<?php
/**
 * Reference implementation of a credit commons node
 */
namespace CCNode;

use CreditCommons\NewTransaction;
use CreditCommons\Exceptions\MiscFailure;
use CreditCommons\Exceptions\CCError;
use CreditCommons\Exceptions\HashMismatchFailure;
use CreditCommons\Exceptions\PermissionViolation;
use CreditCommons\CreditCommonsInterface;
use CreditCommons\Workflows;
use CreditCommons\Accounts\Remote;
use CCnode\Accounts\BoT;

use CCNode\Accounts\Account;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

$config = parse_ini_file('./node.ini');
// Slim4
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
//      $exception = new \CreditCommons\Exceptions\MiscFailure([
//        'message' => $exception->getMessage()
//      ]);
//    }
//    $response->getBody()->write(json_encode($exception, JSON_UNESCAPED_UNICODE));
//    return $response->withStatus($exception->getCode());
//});

$app = new App;

$c = $app->getContainer();
$c['errorHandler'] = function ($c) {
  return new Slim3ErrorHandler();
};

/**
 * Implement the Credit Commons API methods
 */
$app->options('/', function (Request $request, Response $response) {
  // No access control
  check_permission($request, 'permittedEndpoints');
  $result = permitted_operations();
  $response->getBody()->write(json_encode($result));
  return $response->withHeader('Content-type', 'application/json');;
});

$app->get('/', function (Request $request, Response $response) {
  global $orientation;
  check_permission($request, 'absoluteAddress');
  $result = $orientation->absoluteAddress();
  $response->getBody()->write(json_encode($result));
  return $response->withHeader('Content-type', 'application/json');;
});

$app->get('/workflows', function (Request $request, Response $response) {
  // No access control
  $result = getAllWorkflows();
  $response->getBody()->write(json_encode($result));
  return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/handshake', function (Request $request, Response $response) {
  global $orientation;
  check_permission($request, 'handshake');
  $result = $orientation->handshake() ?: [];
  $response->getBody()->write(json_encode($result));
  return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/accounts/[{fragment}]', function (Request $request, Response $response, $args) {
  check_permission($request, 'accountNames');
  $tree = !empty($request->getQueryParams()['tree']);
  $remote_names = [];
  if ($tree and !empty($config['bot']['account'])) {// $orientation might be cleaner
    //@todo pass this to the parent ledger
    throw new MiscFailure(['message' => 'accounts/{fragment} not implemented for ledger tree.']);
    $remote_names = (new CreditCommons\LeafAPI())->accounts(@$args['fragment'], TRUE);
  }
  $local_names = accountStore()->filter(['chars' => @$args['fragment'], 'status' => 1, 'local' => 1, 'nameonly' => 1], TRUE);
  $result = array_slice(array_merge($local_names, $remote_names), 0, 10);
  $response->getBody()->write(json_encode($result));
  return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/account/summary[/{acc_path}]', function (Request $request, Response $response, $args) {
  global $orientation;
  check_permission($request, 'accountSummary');
  $params = $request->getQueryParams();
  if ($acc_path = $args['acc_path']) {
    $account = Account::create($acc_path, FALSE);
    $result = (new Wallet($account))->getTradeStats();
  }
  // The openAPI format doesn't allow acc_path to be empty, so this is undocumented and won't work here
  else {
    foreach (accountStore()->filter([], 'nameonly') as $acc_id) {
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
  $data = json_decode($request->getBody()->read());
  $newTransaction = new NewTransaction($data->payee, $data->payer, $data->quantity, $data->description, $data->type);
  $transaction = Transaction::createFromClient($newTransaction);
  $transaction->buildValidate();
  $transaction->writeValidatedToTemp();
  $orientation->responseMode = TRUE;
  return $response
    ->withStatus(201, json_encode($transaction))
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

function setUser(Request $request) {
  global $orientation, $config, $user;
  $acc_id = '';
  $upstreamAccount = NULL;
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
        $upstreamAccount = $user;
      }
    }
  }

  if (!$user) {
    // Load an anonymous account object
    $user = Account::load();
  }
  $orientation = new Orientation(@$upstreamAccount);
}


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


function permitted_operations() {
  global $user;
  $data = CreditCommonsInterface::OPERATIONS;
  $permitted[] = 'workflows';
  $permitted[] = 'permittedEndpoints';

  if ($user->id) {
    $permitted[] = 'handshake';
    if (!$user instanceof BoT) {
      // All users can do all operations.
      $permitted[] = 'absoluteAddress';
      $permitted[] = 'accountHistory';
      $permitted[] = 'accountLimits';
      $permitted[] = 'accountNames';
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

function check_permission(Request $request, string $operationId) {
  global $user;
  setUser($request);
  if (!$user->accessOperation($operationId)) {
    throw new PermissionViolation([
      'account' => $user->id?:'<anon>',
      'method' => $request->getMethod(),
      'url' => $request->getRequestTarget()
    ]);
  }
}


/**
 * Combine local and trunkwards workflows.
 *
 * @return array
 *   Translated workflows, keyed by the rootwards node name they originated from
 *
 * @todo This should be cached if this system has any significant usage.
 */
function getAllWorkflows() : array {
  global $config;
  $local = $tree = [];

  // get rootwards workflows.
  if ($bot_acc_name = $config['bot']['account']) {
    $account = Account::create($bot_acc_name);
    $tree = Workflows::trunkwardsWorkflows($account->url);
  }

  // get the local workflows
  if (file_exists('workflows.json')) {
    $json = file_get_contents('workflows.json');
    foreach (json_decode($json) as $id => $wf) {
      if ($wf->active) {
        $hash = Workflows::wfHash((array)$wf->states);
        $local[$hash] = $wf;
      }
    }
    // Now compare the hashes, and where similar, replace the rootwards one with the local translation.
    foreach ($tree as $nName => $wfs) {
      foreach ($wfs as $hash => $wf) {
        if (isset($local[$hash])) {
          $tree[$nName][$hash] = $local[$hash];
          unset($local[$hash]);
        }
      }
    }
    // Todo, ensure that no local wf types clash with inherited ones.
    // Better still, all workflow names contain their absolute path to prevent clashes
    $tree[absolute_node_path()] = $local;
  }
  foreach ($tree as $nPath => &$wfs) {
    foreach ($wfs as $hash => $wf) {
      $all[$nPath][] = $wf;
    }
  }
  return $all;
}

function absolute_node_path() {
  global $config;
  $ancestors = [];
  if ($bot_name = $config['bot']['account']) {
    $bot_account = Account::load($bot_name);
    $ancestors = (new ClientAPI($bot_account->url))->getTrunkwardNodeNames();
  }
  array_unshift($ancestors, $config['node_name']);
  return '/'.implode('/', array_reverse($ancestors));
}

/**
 * Get the library of functions for accessing ledger accounts
 */
function accountStore() : AccountStore {
  global $config;
  return new AccountStore($config['account_store_url']);
}

class Slim3ErrorHandler {
  public function __invoke($request, $response, $exception) {
    if (!$exception instanceOf CCError) {
      $exception = new \CreditCommons\Exceptions\MiscFailure([
        'message' => $exception->getMessage()
      ]);
    }
    $response->getBody()->write(json_encode($exception, JSON_UNESCAPED_UNICODE));
    return $response->withStatus($exception->getCode());
   }
}


