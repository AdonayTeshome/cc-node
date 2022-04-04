<?php
/**
 * Reference implementation of a credit commons node
 *
 * @todo find a way to notify the user if the trunkward node is offline.
 *
 */
namespace CCNode;

use CreditCommons\Exceptions\CCFailure;
use CreditCommons\Exceptions\DoesNotExistViolation;
use CreditCommons\Exceptions\HashMismatchFailure;
use CreditCommons\Exceptions\UnavailableNodeFailure;
use CreditCommons\Exceptions\PermissionViolation;
use CreditCommons\Exceptions\AuthViolation;
use CreditCommons\CreditCommonsInterface;
use CreditCommons\NodeRequester;
use CreditCommons\Account;
use CCNode\Slim3ErrorHandler;
use CCNode\AddressResolver;
use CCNode\Accounts\BoT;
use CCNode\AccountStore;
use CCNode\Accounts\Remote;
use CCNode\Workflows;
use CCNode\Transaction\Transaction;
use CCNode\Transaction\StandaloneEntry;
use CCNode\Transaction\NewTransaction;
use CCNode\Transaction\TransversalTransaction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

// Slim4 (when the League\OpenAPIValidation is ready)
//use Slim\Factory\AppFactory;
//use Psr\Http\Message\ServerRequestInterface;
//$app = AppFactory::create();
//$app->addErrorMiddleware(true, true, true);
//$app->addRoutingMiddleware();
//$errorMiddleware = $app->addErrorMiddleware(true, true, true);
//// See https://www.slimframework.com/docs/v4/middleware/error-handling.html
//// Todo this would be tidier in a class of its own extending Slim\Handlers\ErrorHandler.
////This handler converts the CCError exceptions into Json and returns them.
//$errorMiddleware->setDefaultErrorHandler(function (
//    ServerRequestInterface $request,
//    \Throwable $exception,
//    bool $displayErrorDetails,
//    bool $logErrors,
//    bool $logErrorDetails
//) use ($app) {
//    $response = $app->getResponseFactory()->createResponse();
//    if (!$exception instanceOf CCError) {
//      $exception = new CCFailure($exception->getMessage());
//    }
//    $response->getBody()->write(json_encode($exception, JSON_UNESCAPED_UNICODE));
//    return $response->withStatus($exception->getCode());
//});

$app = new App();
$c = $app->getContainer();
$getErrorHandler = function ($c) {
  return new Slim3ErrorHandler();
};
$c['errorHandler'] = $getErrorHandler;
$c['phpErrorHandler'] = $getErrorHandler;

/**
 * Default HTML page. (Not part of the API)
 */
$app->get('/', function (Request $request, Response $response) {
  $response->getBody()->write('It works!');
  return $response->withHeader('Content-Type', 'text/html');
});

/**
 * Implement the Credit Commons API methods
 */
$app->options('/', function (Request $request, Response $response) {
  // No access control
  check_permission($request, 'permittedEndpoints');
  return json_response($response, permitted_operations());
});
$app->options('/.*', function (Request $request, Response $response) {
  return json_response($response);
});

$app->get('/workflows', function (Request $request, Response $response) {
  check_permission($request, 'workflows');
  // Todo need to instantiate workflows with the BoT requester if there is one.
  return json_response($response, (new Workflows())->loadAll());
});

$app->get('/handshake', function (Request $request, Response $response) {
  check_permission($request, 'handshake');
  return json_response($response, handshake());
});

$app->get('/absolutepath', function (Request $request, Response $response) {
  $node_names[] = getConfig('node_name');
  check_permission($request, 'absolutePath');
  if ($trunkwards = API_calls()) {
    $node_names = array_merge($trunkwards->getAbsolutePath(), $node_names);
  }
  return json_response($response, $node_names, 200);
});

$app->get('/accounts/names[/{acc_path:.*$}]', function (Request $request, Response $response, $args) {
  check_permission($request, 'accountNameFilter');
  $acc_ids = AddressResolver::create()->pathMatch($args['acc_path']??'');
  //if the request is from the trunk prefix all the results. (rare)
  $limit = $request->getQueryParams()['limit'] ??'10';
  return json_response($response, array_slice($acc_ids, 0, $limit));
});

$app->get('/account/summary[/{acc_path:.*$}]', function (Request $request, Response $response, $args) {
  check_permission($request, 'accountSummary');
  $account = AddressResolver::create()
    ->resolveToLocalAccount((string)@$args['acc_path'], TRUE);
  if ($account instanceOf Accounts\User and substr($account->givenPath, -1) == '/') {// All the accounts on a remote node
    $result = $account->getAccountSummaries(trim($account->givenPath, '/'));
  }
  elseif ($account instanceOf Accounts\User) {// An account on this node.
    $result = $account->getAccountSummary($account->relPath());
  }
  elseif ($account == '*') {// All accounts on the current node.
    $result = Transaction::getAccountSummaries(TRUE);
  }
  $response->getBody()->write(json_encode($result));
  return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/account/limits/{acc_path:.*$}', function (Request $request, Response $response, $args) {
  check_permission($request, 'accountLimits');
  $account = AddressResolver::create()
    ->resolveToLocalAccount((string)@$args['acc_path'], TRUE);
  if ($account instanceOf Accounts\User and substr($account->givenPath, -1) == '/') {// All the accounts on a remote node
    $result = $account->getAllLimits(trim($account->givenPath, '/'));
  }
  elseif ($account instanceOf Accounts\User) {// An account on this node.
    $result = $account->getLimits($account->relPath());
  }
  elseif ($account == '*') {// All accounts on the current node.
    $result = accountStore()->allLimits(TRUE);
  }
  return json_response($response, $result);
});

$app->get('/account/history/{acc_path:.*$}', function (Request $request, Response $response, $args) {
  check_permission($request, 'accountHistory');
  $account = AddressResolver::create()
    ->resolveToLocalAccount((string)@$args['acc_path'], TRUE);
  if (!$account instanceOf \CCNode\Accounts\User) {
    throw new \CreditCommons\Exceptions\CCOtherViolation("Unable to get history from NULL account");
  }
  else {
    debug("Requesting history for local or remote account $account->id ".$account->relPath());
  }
  //@todo cope with various other paths.
  $params = $request->getQueryParams() + ['samples' => -1];
  $result = $account->getHistory($params['samples'], $account->relPath());//@todo refactor this.
  $response->getBody()->write(json_encode($result));
  return $response->withHeader('Content-Type', 'application/json');
});

$uuid_regex = '[0-9a-f]{8}\b-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-\b[0-9a-f]{12}';
// Retrieve one transaction
$app->get('/transaction/{format}/{uuid:'.$uuid_regex.'}', function (Request $request, Response $response, $args) {
  global $orientation;
  check_permission($request, 'getTransaction');
  if ($args['format'] == 'entry') {
    $result = array_values(StandaloneEntry::loadByUuid($args['uuid']));
  }
  else {// format = full (default)
    $result = Transaction::loadByUuid($args['uuid']);
    $result->responseMode = TRUE;// there's nowhere tidier to do this.
  }
  return json_response($response, $result, 200);
});

// Filter transactions
$app->get('/transaction/{format}', function (Request $request, Response $response, $args) {
  check_permission($request, 'filterTransactions');
  $params = $request->getQueryParams();
  $results = [];
  if ($uuids = Transaction::filter(...$params)) {// keyed by entries and $args['format'] == 'entry') {
    if ($args['format'] == 'entry') {
      $results = StandaloneEntry::load(array_keys($uuids));
    }
    else {
      $results = [];
      foreach (array_unique($uuids) as $uuid) {
        $results[$uuid] = Transaction::loadByUuid($uuid);
      }
    }
  }
  return json_response($response, array_values($results), 200);
});

// Create a new transaction
$app->post('/transaction', function (Request $request, Response $response) {
  global $user;
  check_permission($request, 'newTransaction');
  $request->getBody()->rewind(); // ValidationMiddleware leaves this at the end.
  $data = json_decode($request->getBody()->getContents());
debug('incoming: '.print_r($data, 1));
  // validate the input and create UUID
  $from_upstream = NewTransaction::prepareClientInput($data);
  $transaction = Transaction::createFromUpstream($from_upstream); // in state 'init'
  // Validate the transaction in its workflow's 'creation' state
  $transaction->buildValidate();
  $status_code = $transaction->insert();
  // Send the whole transaction back via jsonserialize to the user.
  return json_response($response, $transaction, $status_code);
});

// Relay a new transaction
$app->post('/transaction/relay', function (Request $request, Response $response) {
  global $user;
  check_permission($request, 'relayTransaction');
  $request->getBody()->rewind(); // ValidationMiddleware leaves this at the end.
  $data = json_decode($request->getBody()->getContents());
debug('incoming relay: '.print_r($data, 1));
  $transaction = TransversalTransaction::createFromUpstream($data);
  $transaction->buildValidate();
  $status_code = $transaction->insert();
  // Return only the additional entries which are relevant to the upstream node.
  // @todo this could be more elegant.
  $additional_entries = array_filter(
    $transaction->filterFor($user),
    function($e) {return $e->isAdditional();}
  );
  // $additional_entries via jsonSerialize
  return json_response($response, $additional_entries, 201);
});

$app->patch('/transaction/{uuid:'.$uuid_regex.'}/{dest_state}', function (Request $request, Response $response, $args) {
  check_permission($request, 'stateChange');
  Transaction::loadByUuid($args['uuid'])->changeState($args['dest_state']);
  return $response->withStatus(201);
});

global $config;
if ($config and $config['dev_mode']) {
  // this stops execution on ALL warnings and returns CCError objects
  set_error_handler( "\\CCNode\\exception_error_handler" );
}

return $app;

/**
 * Load an account from the accountStore.
 *
 * @staticvar array $fetched
 * @param string $acc_id
 *   The account id or empty string to load a dummy account.
 * @return CreditCommons\Account
 * @throws DoesNotExistViolation
 *
 * @todo make sure this is used whenever possible because it is cached.
 * @todo This doesn't seem like a good place to throw a violation.
 */
function load_account(string $local_acc_id = NULL, string $given_path = NULL) : Account {
  static $fetched = [];
  if (!isset($fetched[$local_acc_id])) {
    if (strpos(needle: '/', haystack: $local_acc_id)) {
      throw new CCFailure("Can't load unresolved account name: $local_acc_id");
    }
    if ($local_acc_id and $acc = accountStore()->has($local_acc_id)) {
      $fetched[$local_acc_id] = accountStore()->fetch($local_acc_id);
    }
    else {
      throw new DoesNotExistViolation(type: 'account', id: $local_acc_id);
    }
  }
  // Sometimes an already loaded account turns out to have a relative path.
  if ($given_path) {
    $fetched[$local_acc_id]->givenPath = $given_path;
  }
  return $fetched[$local_acc_id];
}

/**
 * @global \CCNode\type $user
 * @param Request $request
 * @param string $operationId
 * @return void
 * @throws PermissionViolation
 */
function check_permission(Request $request, string $operationId) : void {
  global $user;
  authenticate($request); // This sets $user
  $permitted = permitted_operations();
  if (!in_array($operationId, array_keys($permitted))) {
    $user_id = $user->id;
    if ($user->id == getConfig('trunkward_acc_id')) {
      $user_id .= '(trunkward)';
    }
    throw new PermissionViolation(operation: $operationId);
  }
}

/**
 * Access control for each API method.
 *
 * Anyone can see what endpoints they can user, any authenticated user can check
 * the workflows and the connectivity of adjacent nodes. But most operations are
 * only accessible to direct members and leafward member, making this node quite
 * private with respect to the rest of the tree.
 * @global type $user
 * @return string[]
 *   A list of the api method names the current user can access.
 */
function permitted_operations() : array {
  global $user;
  $data = CreditCommonsInterface::OPERATIONS;
  $permitted[] = 'permittedEndpoints';
  if ($user->id <> '-anon-') {
    $permitted[] = 'handshake';
    $permitted[] = 'workflows';
    $permitted[] = 'newTransaction';
    $permitted[] = 'absolutePath';
    $permitted[] = 'stateChange';
    $map = [
      'filterTransactions' => 'transactions',
      'getTransaction' => 'transactions',
      'accountHistory' => 'transactions',
      'accountLimits' => 'acc_summaries',
      'accountNameFilter' => 'acc_ids',
      'accountSummary' => 'acc_summaries'
    ];
    foreach ($map as $method => $perm) {
      if (!$user instanceOf BoT or getConfig("priv.$perm")) {
        $permitted[] = $method;
      }
    }
    if ($user instanceof Remote) {
      $permitted[] = 'relayTransaction';
    }
  }
  return array_intersect_key(CreditCommonsInterface::OPERATIONS, array_flip($permitted));
}

/**
 * Taking the user id and auth key from the header and comparing with the database. If the id is of a remote account, compare the extra
 * @global stdClass $user
 * @param Request $request
 * @return void
 * @throws DoesNotExistViolation|HashMismatchFailure|AuthViolation
 */
function authenticate(Request $request) : void {
  global $user;
  $user = accountStore()->anonAccount();
  if ($request->hasHeader('cc-user') and $request->hasHeader('cc-auth')) {
    $acc_id = $request->getHeaderLine('cc-user');
    // Users connect with an API key which can compared directly with the database.
    if ($acc_id) {
      $user = load_account($acc_id);
      $auth = ($request->getHeaderLine('cc-auth') == 'null') ?
        NULL : // Don't know why null is returned as a string.
        $request->getHeaderLine('cc-auth');
      if ($user instanceOf Remote) {
        if (!compare_hashes($acc_id, $auth)) {
//TEMP          throw new HashMismatchFailure(otherNode: $acc_id);
        }
      }
      elseif (!accountStore()->checkCredentials($acc_id, $auth)) {
        //local user with the wrong password
        throw new AuthViolation();
      }
    }
    else {
      // Blank username supplied, fallback to anon
    }
  }
  else {
    // No attempt to authenticate, fallback to anon
  }
}

function compare_hashes(string $acc_id, string $auth) : bool {
   // this is not super secure...
  if (empty($auth)) {
    $query = "SELECT TRUE FROM hash_history "
      . "WHERE acc = '$acc_id'"
      . "LIMIT 0, 1";
    $result = Db::query($query)->fetch_object();
    return $result == FALSE;
  }
  else {
    // Remote nodes connect with a hash of the connected account, which needs to be compared.
    $query = "SELECT TRUE FROM hash_history WHERE acc = '$acc_id' AND hash = '$auth' ORDER BY id DESC LIMIT 0, 1";
    $result = Db::query($query)->fetch_object();
    return (bool)$result;// temp
  }
}

/**
 * Get the object with all the API calls, initialised with a remote account to call.
 *
 * @param Remote $account
 *   if not provided the balance of trade of account will be used
 * @return NodeRequester|NULL
 */
function API_calls(Remote $account = NULL) {
  if (!$account) {
    if ($bot = getConfig('trunkward_acc_id')) {
      $account = load_account($bot);
    }
    else {
      return NULL;
    }
  }
  return new NodeRequester($account->url, getConfig('node_name'), $account->getLastHash());
}

/**
 * Get the library of functions for accessing ledger accounts.
 */
function accountStore() : AccountStore {
  static $accountStore;
  if (!isset($accountStore)) {
    $accountStore = AccountStore::create();
  }
  return $accountStore;
}

/**
 * Populate a json response.
 *
 * @param Response $response
 * @param stdClass|array $body
 * @param int $code
 * @return Response
 */
function json_response(Response $response, $body, int $code = 200) : Response {
  if (is_scalar($body)){
    throw new CCFailure('Illegal value passed to json_response()');
  }
  $contents = json_encode($body, JSON_UNESCAPED_UNICODE);
  $response->getBody()->write($contents);
global $user;
debug("$code outgoing to $user->id: $contents");
  return $response->withStatus($code)
    ->withHeader('Access-Control-Allow-Origin', '*')
    ->withHeader('Access-Control-Allow-Methods', 'GET')
    ->withHeader('Access-Control-Allow-Headers', 'content-type, cc-user, cc-auth')
    ->withHeader('Vary', 'Origin')
    ->withHeader('Content-Type', 'application/json');
}

/**
 * Get names config items, including some items that need to be processed first.
 *
 * @staticvar array $tree
 * @staticvar array $config
 * @param string $var
 * @return mixed
 */
function getConfig(string $var) {
  static $tree, $config;
  if (!isset($tree)) {
    $config = parse_ini_file('node.ini');
    $tree = explode('/', $config['abs_path']);
  }
  if ($var == 'node_name') {
    return end($tree);
  }
  elseif ($var == 'trunkward_acc_id') {
    end($tree);
    return prev($tree);
  }
  if (strpos($var, '.')) {
    list($var, $subvar) = explode('.', $var);
    return $config[$var][$subvar];
  }
  else return $config[$var];
}

function debug($val) {
  static $first = TRUE;
  $file = getConfig('node_name').'.debug';
  if (!is_scalar($val)) {
    $val = print_r($val, 1);
  }
  file_put_contents(
    $file,
    ($first ? "\n": '').date('H:i:s')."  $val\n",
    FILE_APPEND
  ); //temp
  $first = FALSE;
}


function exception_error_handler( $severity, $message, $file, $line ) {
  // all warnings go the debug log AND throw an error
  throw new CCFailure("$message in $file: $line");
}


  /**
   * Check that all the remote nodes are online and the ratchets match
   * @return array
   *   Linked nodes keyed by response_code.
   */
  function handshake() : array {
    global $user;
    $results = [];
    if ($user instanceOf Accounts\User) {
      $remote_accounts = AccountStore()->filterFull(local: FALSE);
      foreach ($remote_accounts as $acc) {
        if ($acc->id == $user->id) {
          continue;
        }
        try {
          $acc->handshake();
          $results[$acc->id] = 'ok';
        }
        catch (UnavailableNodeFailure $e) {
          $results[$acc->id] = 'UnavailableNodeFailure';
        }
        catch (HashMismatchFailure $e) {
          $results[$acc->id] = 'HashMismatchFailure';
        }
        catch(\Exception $e) {
          $results[$acc->id] = get_class($e);
        }
      }
    }
    return $results;
  }
