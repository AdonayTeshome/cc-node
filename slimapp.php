<?php
/**
 * Reference implementation of a credit commons node
 *
 *
 * @todo find a way to notify the user if the trunkward node is offline.
 *
 */
namespace CCNode;

use CreditCommons\NewTransaction;
use CreditCommons\Exceptions\CCFailure;
use CreditCommons\Exceptions\CCError;
use CreditCommons\Exceptions\HashMismatchFailure;
use CreditCommons\Exceptions\PermissionViolation;
use CreditCommons\CreditCommonsInterface;
use CreditCommons\Workflows;
use CreditCommons\Workflow;
use CreditCommons\AccountRemote;
use CreditCommons\RestAPI;
use CCnode\Accounts\BoT;

use CCNode\Accounts\Account;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

$config = parse_ini_file('./node.ini');
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
$c['errorHandler'] = function ($c) {
  return new Slim3ErrorHandler();
};
$c['phpErrorHandler'] = function ($c) {
  return new Slim3ErrorHandler();
};

/**
 * Middleware to add the name of the current node to every response header.
 */
$app->add(function ($request, $response, $next) {
  global $config;
  $response = $next($request, $response);
	return $response->withHeader('Node-name', $config['node_name']);
});


/**
 * Implement the Credit Commons API methods
 */
$app->get('/', function (Request $request, Response $response) {
  $response->getBody()->write('It works!');
  return $response
    ->withHeader('Content-Type', 'text/html');
});

$app->options('/', function (Request $request, Response $response) {
  // No access control
  check_permission($request, 'permittedEndpoints');
  return json_response($response, permitted_operations(), 200);
});

$app->get('/address', function (Request $request, Response $response) {
  check_permission($request, 'absoluteAddress');
  return json_response(RestAPI::absoluteNodePath(API_calls()), permitted_operations(), 200);
});

$app->get('/workflows', function (Request $request, Response $response) {
  check_permission($request, 'workflows');
  return json_response($response, get_all_workflows(), 200);
});

$app->get('/handshake', function (Request $request, Response $response) {
  global $orientation, $config;
  check_permission($request, 'handshake');
  return json_response($response, $orientation->handshake(), 200);
});

$app->get('/accountnames[/{fragment}]', function (Request $request, Response $response, $args) {
  check_permission($request, 'accountNameAutocomplete');
  $params = $request->getQueryParams();
  $remote_names = [];
  if (!empty($config['bot']['acc_id'])) {// $orientation might be cleaner
    //@todo pass this to the parent ledger
    throw new CCFailure(['message' => 'accounts/{fragment} not implemented for ledger tree.']);
    $remote_names = API_calls()->accounts(@$args['fragment'], TRUE);
    // @todo Also we may want to query child ledgers.
  }
  $local_names = accountStore()->filter(['chars' => @$args['fragment'], 'status' => 1, 'local' => 1, 'nameonly' => 1], TRUE);
  $result = array_slice(array_merge($local_names, $remote_names), 0, $params['limit']??10);
  $response->getBody()->write(json_encode($result));
  return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/account/summary[/{acc_path}]', function (Request $request, Response $response, $args) {
  global $orientation;
  check_permission($request, 'accountSummary');
  $params = $request->getQueryParams();
  if (isset($args['acc_path'])) {
    $account = Account::create($args['acc_path'], FALSE);
    $result = (new Wallet($account))->getTradeStats();
  }
  // The openAPI format doesn't allow acc_path to be empty, so this is undocumented and won't work here
  else {
    foreach (accountStore()->filter(['nameonly' => 1]) as $acc_id) {
      $account = Account::create($acc_id, TRUE);
      $result[$acc_id] = (new Wallet($account))->getTradeStats();
    }
  }
  $orientation->responseMode = TRUE;
  $response->getBody()->write(json_encode($result));
  return $response->withHeader('Content-Type', 'application/json');
});


$app->get('/account/history/{acc_path}', function (Request $request, Response $response, $args) {
  global $orientation;
  check_permission($request, 'accountHistory');
  $params = $request->getQueryParams();
  $account = Account::create($args['acc_path'], FALSE);
  $result = (new Wallet($account))->getHistory($params['samples']??0, $account->created);

  $orientation->responseMode = TRUE;
  $response->getBody()->write(json_encode($result));
  return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/account/limits/{acc_path}', function (Request $request, Response $response, $args) {
  global $orientation;
  check_permission($request, 'accountHistory');
  $account = accountStore()->fetch($args['acc_path'], FALSE);
  $result = (object)['min' => $account->min, 'max' => $account->max];
  $orientation->responseMode = TRUE;
  $response->getBody()->write(json_encode($result));
  return $response->withHeader('Content-Type', 'application/json');
});


$app->get('/transaction/filter', function (Request $request, Response $response) {
  global $orientation;
  check_permission($request, 'filterTransactions');
  $params = $request->getQueryParams();
  $uuids = Transaction::filter($params);

  if ($uuids and !empty($params['full'])) {
    foreach ($uuids as $key => $uuid) {
      $result[$uuid] = Transaction::loadByUuid($uuid);
    }
  }
  else {
    $result = $uuids;
  }
  $orientation->responseMode = TRUE;
  $response->getBody()->write(json_encode($result));
  return $response->withHeader('Content-Type', 'application/json');
});


// Create a new transaction
$app->post('/transaction/new', function (Request $request, Response $response) {
  global $orientation;
  check_permission($request, 'newTransaction');
  $data = json_decode($request->getBody()->getContents());
  $newTransaction = new NewTransaction($data->payee, $data->payer, $data->quantity, $data->description, $data->type);
  $transaction = Transaction::createFromClient($newTransaction);
  $transaction->buildValidate();
  $transaction->writeValidatedToTemp();
  $orientation->responseMode = TRUE;
  $response->getBody()->write(json_encode($transaction));
  return $response
    ->withStatus(201)
    ->withHeader('Content-Type', 'application/json');
});

$app->post('/transaction/relay', function (Request $request, Response $response) {
  global $orientation;
  check_permission($request, 'relayTransaction');
  $data = json_decode($request->getBody()->read());
  $transaction = TransversalTransaction::createFromUpstreamNode($post);
  $transaction->buildValidate();
  $transaction->writeValidatedToTemp();
  $orientation->responseMode = TRUE;
  return $response
    ->withStatus(201, json_encode($transaction))
    ->withHeader('Content-Type', 'application/json');
});

$app->patch('/transaction/{uuid:[0-9a-f]{8}\b-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-\b[0-9a-f]{12}}/{dest_state}', function (Request $request, Response $response, $args) {
  check_permission($request, 'stateChange');
  global $orientation;
  $dest_state = $args['dest_state'];
  $uuid = $args['uuid'];
  $transaction = Transaction::loadByUuid($uuid);
  // Ensure that transversal transactions are being manipulated only from their
  // end points, not an intermediate ledger
  if (!$orientation->upstreamAccount and !empty($transaction->payer->url) and !empty($transaction->payee->url)) {
    throw new Exceptions\IntermediateLedgerViolation();
  }
  $transaction->changeState($dest_state);
  return $response->withStatus(201);
});

return $app;

/**
 *
 * @staticvar array $fetched
 * @param string $name
 * @param bool $stop_on_error
 * @return CCNode\Account
 * @throws DoesNotExistViolation
 */
function load_account(string $id) : Account {
  static $fetched = [];
  if (!isset($fetched[$id])) {
    if ($acc = accountStore()->fetch($id, FALSE)) {
      $fetched[$id] = $acc;
    }
  }
  return $fetched[$id];
}

function check_permission(Request $request, string $operationId) {
  authenticate($request); // This sets $user
  global $user, $orientation;
  $orientation = new Orientation();

  $permitted = \CCNode\permitted_operations();
  if (!in_array($operationId, array_keys($permitted))) {
    throw new PermissionViolation([
      'account' => $user->id?:'<anon>',
      'method' => $request->getMethod(),
      'url' => $request->getRequestTarget()
    ]);
  }
}


function permitted_operations() {
  global $user;
  $data = CreditCommonsInterface::OPERATIONS;
  $permitted[] = 'permittedEndpoints';

  if ($user->id) {
    $permitted[] = 'handshake';
    $permitted[] = 'workflows';
    if (!$user instanceof BoT) {
      // All users can do all operations.
      $permitted[] = 'absoluteAddress';
      $permitted[] = 'accountHistory';
      $permitted[] = 'accountLimits';
      $permitted[] = 'accountNameAutocomplete';
      $permitted[] = 'accountSummary';
      $permitted[] = 'newTransaction';
      $permitted[] = 'stateChange';
      $permitted[] = 'filterTransactions';
    }
    if ($user instanceof Remote) {
      $permitted[] = 'relayTransaction';
    }
  }
  return array_intersect_key(CreditCommonsInterface::OPERATIONS, array_flip($permitted));
}


function authenticate(Request $request) : void {
  global $config, $user;
  $acc_id = '';
  $user = Account::load(); // Anon
  if ($request->hasHeader('cc-user') and $request->hasHeader('cc-auth')) {
    $acc_id = $request->getHeader('cc-user')[0];
    $auth = $request->getHeader('cc-auth')[0];
    // Find out where the request is coming from, check the hash if it is another
    // node, and set up the session, which should persist accross the microservices.
    if ($acc_id and $acc_id <> 'null') {
      $user = Account::load($acc_id);
      // Check the ratchet
      if ($user instanceOf Remote) {
        $query = "SELECT TRUE FROM hash_history WHERE acc = '$account->id' AND hash = '$hash' ORDER BY id DESC LIMIT 0, 1";
        $result = Db::query($query)->fetch_row();
        if ($hash && !$result or !$hash && $result) {
          throw new HashMismatchFailure(['downStreamNode' => $config['node_name']]);
        }
      }
    }
  }
}


/**
 * @todo: cache this and check once a day.
 */
function get_all_workflows() {
  return Workflows::getAll(load_local_workflows(), API_calls());
}

function load_local_workflows() : array {
  $wfs = [];
  if (file_exists('workflows.json')) {
    $content = file_get_contents('workflows.json');
    if ($data = json_decode($content)) {
      foreach ($data as $wf) {
        $wfs[] = new Workflow($wf);
      }
    }
  }
  return $wfs;
}

/**
 * Get the object with all the API calls, initialised with a remote account to call.
 *
 * @param Remote $account
 *   if not provided the balance of trade of account will be used
 * @return RestAPI|NULL
 */
function API_calls(AccountRemote $account = NULL) {
  global $config;
  if (!$account) {
    if ($bot = $config['bot']['acc_id']) {
      $account = Account::load($bot);
    }
    else {
      return NULL;
    }
  }
  return new RestAPI($account->url, $config['node_name'], $account->getLastHash);
}

/**
 * Get the library of functions for accessing ledger accounts.
 */
function accountStore() : AccountStore {
  global $config;
  return new AccountStore($config['account_store_url']);
}

/**
 * Convert all errors into the CC Error format, which includes a field showing
 * which node caused the error
 */
class Slim3ErrorHandler {
  /**
   * Probably all errors and warnings should include an emergency message to the admin.
   * CC nodes should be seamless.
   */
  public function __invoke($request, $response, $exception) {
    global $config;
    if (!$exception instanceOf CCError) {
      // isolate only the first exception because the protocol allows only one.
      $e = new CCFailure([
        'message' => $exception->getMessage()
      ]);
      while ($exception = $exception->getPrevious()) {
        $e = new CCFailure([
          'message' => $exception->getMessage()
        ]);
      }
      $exception = $e;
    }
    $exception->node = $config['node_name'];
    $response->getBody()->write(json_encode($exception, JSON_UNESCAPED_UNICODE));
    return $response->withStatus($exception->getCode());
   }
}

function json_response($response, $body, $code = 200) : Response {
  $response->getBody()->write(json_encode($body));
  return $response->withHeader('content-type', 'application/json');
}