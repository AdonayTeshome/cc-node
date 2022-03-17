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
use CreditCommons\Exceptions\PermissionViolation;
use CreditCommons\Exceptions\AuthViolation;
use CreditCommons\CreditCommonsInterface;
use CreditCommons\RestAPI;
use CreditCommons\Account;
use CCNode\Slim3ErrorHandler;
use CCNode\AddressResolver;
use CCNode\Accounts\BoT;
use CCNode\Accounts\Remote;
use CCNode\Workflows;
use CCNode\StandaloneEntry;
use CCNode\NewTransaction;
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
//      $exception = new CCFailure([
//        'message' => $exception->getMessage()
//      ]);
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

$app->get('/workflows', function (Request $request, Response $response) {
  check_permission($request, 'workflows');
  // Todo need to instantiate workflows with the BoT requester if there is one.
  return json_response($response, (new Workflows())->loadAll());
});

$app->get('/handshake', function (Request $request, Response $response) {
  global $orientation;
  check_permission($request, 'handshake');
  return json_response($response, $orientation->handshake());
});

$app->get('/absolutepath', function (Request $request, Response $response) {
  $node_names[] = getConfig('node_name');
  check_permission($request, 'absolutePath');
  if ($trunkwards = API_calls()) {
    $node_names = array_merge($trunkwards->getAbsolutePath(), $node_names);
  }
  return json_response($response, $node_names, 200);
});

$app->get('/accounts/filter[/{acc_path:.*$}]', function (Request $request, Response $response, $args) {
  check_permission($request, 'accountNameFilter');
  // TEMP
  $path_parts = explode('/', @$args['acc_path'])??'';
  $remote_acc_id = AddressResolver::create(getConfig('abs_path'))->relativeToThisNode($path_parts);
  if ($remote_acc_id) {
    // All the accounts on a remote node
    $names = accountStore()->fetch($remote_acc_id)->accountNameFilter(implode('/', $path_parts), $request->getQueryParams());
  }
  else {
    // All accounts on the current node.
    $params = $request->getQueryParams();
    // I'd be surprised if there wasn't a better way to turn boolean queryparams into php bools
    if (isset($params['full']))$params['full'] = filter_var(@$params['full'], FILTER_VALIDATE_BOOLEAN);
    if (isset($params['local']))$params['local'] = filter_var(@$params['local'], FILTER_VALIDATE_BOOLEAN);
    $names = accountStore()->filter(...$params);
  }
  return json_response($response, $names);
});

$app->get('/account/summary[/{acc_path:.*$}]', function (Request $request, Response $response, $args) {
  check_permission($request, 'accountSummary');
  list($account, $rel_path) = AddressResolver::create(getConfig('abs_path'))
    ->resolveToLocalAccount((string)@$args['acc_path'], TRUE);
  if ($account and substr($rel_path, -1) == '/') {// All the accounts on a remote node
    $result = $account->getAccountSummaries(trim($rel_path, '/'));
  }
  elseif ($account) {// An account on this node.
    $result = $account->getAccountSummary($rel_path);
  }
  else {// All accounts on the current node.
    $result = Transaction::getAccountSummaries(TRUE);
  }
  $response->getBody()->write(json_encode($result));
  return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/account/limits/{acc_path:.*$}', function (Request $request, Response $response, $args) {
  check_permission($request, 'accountLimits');
  list($account, $rel_path) = AddressResolver::create(getConfig('abs_path'))
    ->resolveToLocalAccount((string)@$args['acc_path'], TRUE);
  if ($account and substr($rel_path, -1) == '/') {// All the accounts on a remote node
    debug("getting all limits on remote node $rel_path");
    $result = $account->getAllLimits(trim($rel_path, '/'));
  }
  elseif ($account) {// An account on this node.
    debug("getting limits for one account $rel_path");
    $result = $account->getLimits($rel_path);
  }
  else {// All accounts on the current node.
    debug('getting all limits');
    $result = accountStore()->allLimits(TRUE);
  }

  return json_response($response, $result);
});

$app->get('/account/history/{acc_path:.*$}', function (Request $request, Response $response, $args) {
  check_permission($request, 'accountHistory');
  list($account, $rel_path) = AddressResolver::create(getConfig('abs_path'))
    ->resolveToLocalAccount((string)@$args['acc_path'], TRUE);
  $params = $request->getQueryParams() + [
    'samples' => -1
  ];

  $result = $account->getHistory($params['samples'], $rel_path);

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
  }
  $orientation->responseMode = TRUE;
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
  global $orientation, $user;
  check_permission($request, 'newTransaction');
  $request->getBody()->rewind(); // ValidationMiddleware leaves this at the end.
  $data = json_decode($request->getBody()->getContents());
  // validate the input and create UUID
  NewTransaction::prepareClientInput($data);
  $transaction = Transaction::createFromUpstream($data); // in state 'init'
  // Validate the transaction in its workflow's 'creation' state
  $transaction->buildValidate();
  $orientation->responseMode = TRUE;// todo put this in buildvalidate
  $workflow = (new Workflows())->get($transaction->type);
  if ($workflow->creation->confirm) {
    // Send the transaction back to the user to confirm.
    $transaction->saveNewVersion();
    $status_code = 200;
  }
  else {
    // Write the transaction immediately to its 'creation' state
    $transaction->state = $workflow->creation->state;
    $transaction->version = 0;
    $transaction->saveNewVersion();
    $status_code = 201;
  }
  return json_response($response, $transaction, $status_code);
});

$app->post('/transaction/relay', function (Request $request, Response $response) {
  global $orientation;
  check_permission($request, 'relayTransaction');
  $request->getBody()->rewind(); // ValidationMiddleware leaves this at the end.
  $data = json_decode($request->getBody()->read());
  $transaction = TransversalTransaction::createFromUpstream($data);
  $transaction->buildValidate();
  $orientation->responseMode = TRUE;
  $transaction->saveNewVersion();
  return json_response($response, $transaction, 201);
});

$app->patch('/transaction/{uuid:'.$uuid_regex.'}/{dest_state}', function (Request $request, Response $response, $args) {
  check_permission($request, 'stateChange');
  global $orientation;
  $uuid = $args['uuid'];
  $transaction = Transaction::loadByUuid($uuid);
  // Ensure that transversal transactions are being manipulated only from their
  // end points, not an intermediate ledger
  if (!$orientation->upstreamAccount and !empty($transaction->payer->url) and !empty($transaction->payee->url)) {
    throw new IntermediateLedgerViolation();
  }
  $transaction->changeState($args['dest_state']);
  return $response->withStatus(201);
});

return $app;

/**
 * Load an account from the accountStore.
 *
 * @staticvar array $fetched
 * @param string $id
 *   The account id or empty string to load a dummy account.
 * @return CreditCommons\Account
 * @throws DoesNotExistViolation
 *
 * @todo make sure this is used whenever possible because it is cached.
 * @todo This doesn't seem like a good place to throw a violation.
 */
function load_account(string $acc_id) : Account {
  static $fetched = [];
  if (!isset($fetched[$acc_id])) {
    if (strpos(needle: '/', haystack: $acc_id)) {
      throw new CCFailure("Can't load unresolved account name: $acc_id");
    }
    if ($acc_id == '<anon>') {
      $fetched[$acc_id] = accountStore()->anonAccount();
    }
    elseif ($acc_id and $acc = accountStore()->fetch($acc_id)) {
      $fetched[$acc_id] = $acc;
    }
    else {
      throw new \DoesNotExistViolation(type: 'account', id: $acc_id);
    }
  }
  return $fetched[$acc_id];
}

function check_permission(Request $request, string $operationId) {
  global $user;
  authenticate($request); // This sets $user
  $permitted = permitted_operations();
  if (!in_array($operationId, array_keys($permitted))) {
    if ($user->id == getConfig('trunkward_acc_id')) {
      $user_id = '<trunk>';
    }
    elseif ($user->id) {
      $user_id = $user->id;
    }
    else $user_id = '<anon>';
    throw new PermissionViolation(
      acc_id: $user_id
    );
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
  if ($user->id <> '<anon>') {
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
  global $user, $orientation;
  $user = load_account('<anon>'); // Anon
  if ($request->hasHeader('cc-user') and $request->hasHeader('cc-auth')) {
    $acc_id = $request->getHeaderLine('cc-user');
    // Users connect with an API key which can compared directly with the database.
    if ($acc_id) {
      $user = load_account($acc_id);
      $auth = $request->getHeaderLine('cc-auth');
      if ($auth == 'null')$auth = '';// Don't know why null is returned as a string.
      if ($user instanceOf Remote) {
        if (!compare_hashes($acc_id, $auth)) {
          throw new HashMismatchFailure(otherNode: $acc_id);
        }
      }
      elseif (!accountStore()->checkCredentials($acc_id, $auth)) {
        //local user with the wrong password
        throw new AuthViolation(acc_id: $acc_id);
      }
      // login successful.
      // Todo orientation is only needed during transaction processing.
      $orientation = new Orientation();
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
    print_r($result);
    die($auth);
    return (bool)$result;// temp
  }
}

/**
 * Get the object with all the API calls, initialised with a remote account to call.
 *
 * @param Remote $account
 *   if not provided the balance of trade of account will be used
 * @return RestAPI|NULL
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
  return new RestAPI($account->url, getConfig('node_name'), $account->getLastHash());
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
  $response->getBody()->write(json_encode($body, JSON_UNESCAPED_UNICODE));
  return $response->withStatus($code)
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