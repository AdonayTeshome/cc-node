<?php
/**
 * Reference implementation of a credit commons node
 */

use CreditCommons\Exceptions\HashMismatchFailure;
use CreditCommons\Exceptions\MiscFailure;
use CreditCommons\Exceptions\PermissionViolation;
use CreditCommons\CreditCommonsInterface;
use CreditCommons\Workflows;
use CreditCommons\Misc;
use CreditCommons\NewTransaction;
use CreditCommons\TransactionInterface;
use CreditCommons\Accounts\Remote;
use CCNode\Db;
use CCNode\BlogicRequester;
use CCNode\Transaction;
use CCNode\AccountStore;
use CCNode\Orientation;
use CCNode\Wallet;
use CCNode\Accounts\Account;

require_once './vendor/autoload.php';

ini_set('display_errors', 1);

$config = parse_ini_file('ledger.ini');
$headers = getLCHeaders();
// This is part of the API compliance
if ($headers['accept'] != 'application/json') : ?>
<!DOCTYPE html>
<html>
  <head>
    <title>Credit Commons client</title>
  </head>
  <body><?php if (!empty($config['node_name'])) : ?>
    <p>This is a Credit Commons node called '<?php print $config['node_name']; ?>'.</p>
    <?php endif; ?>
    <p>It cannot be accessed through a web browser at present and only responds to API calls from other credit commons clients.</p>
    <p>This url could be used as a public front page for the node, showing stats etc.
    <p>For more information please see <a href="http://creditcommons.net">creditcommons.net</a>.</p>
  </body>
</html>
<?php exit; endif;

/**
 * Credit Commons ledger.
 * This is what implements the Credit Commons API.
 */
set_exception_handler('ccnode_exception_handler');
authenticateUser(@$headers['cc-user'], @$headers['cc-auth']);

// READ FUNCTIONS
if ($method == 'OPTIONS') {
  // No need to check permission
  Misc::response(200, get_permitted_methods());
}
elseif ($method == 'GET' and $endpoint == '') {
  // No need to check permission
  cc_ledger_response(200, $orientation->absoluteAddress());
}
elseif ($method == 'GET' and $endpoint == 'handshake') {
  // No need to check permission
  $hs = [];
  if ($_SESSION['user']) {
    $hs = $orientation->handshake();
  }
  cc_ledger_response(200, $hs);
}
if ($endpoint == 'workflows') {
  // No need to check permission
  $wfs = getAllWorkflows();
  Misc::response(200, $wfs);
}
// this happens before authorisation. NOT part of the public API @todo move this to admin.php
//elseif ($endpoint == 'join' and $method == 'POST') {
//  \check_permission('join');
//  $url = Misc::jsonInput()->url;
//  $new_acc_name = arg(2);
//  $account = AccountStore()->join($new_acc_name);
//  if ($url) {
//    // Todo - check its ok to reuse the service or better to create new instance.
//    AccountStore()->override($new_acc_name, ['url' => $url]);
//  }
//  Misc::response(200);
//}


if ($method == 'GET') {
  parse_str($_SERVER['QUERY_STRING'], $params);
  if ($endpoint == 'accounts') {
    \check_permission('accountNames');
    $remote_names = [];
    if (!empty($params['tree']) and $orientation->orientToRoot()) {
      //@todo pass this to the parent ledger
      throw new MiscFailure(['message' => 'accounts/{fragment} not implemented for ledger tree.']);
      $remote_names = (new CreditCommons\LeafAPI())->accounts(arg(2), TRUE);
    }
    $local_names = (new AccountStore())->filter(['chars' => arg(2)], 'nameonly');
    Misc::response(200, array_slice(array_merge($local_names, $remote_names), 0, 10));
  }
  elseif ($endpoint == 'account') {
    if (arg(2) == 'summary') {
      \check_permission('accountSummary');
      $stats = [];
      if ($acc_address = arg(-2)) {
        // This account could be remote.
        $account = Account::create($acc_address, FALSE);
        $stats = (new Wallet($account))->getTradeStats();
      }
      else {
        foreach ((new AccountStore())->filter([], 'nameonly') as $acc_id) {
          $account = Account::create($acc_id, TRUE);
          $stats[$acc_id] = (new Wallet($account))->getTradeStats();
        }
      }
      Misc::response(200, $stats);
    }
    elseif (arg(2) == 'history') {
      \check_permission('accountHistory');
      $acc_address = arg(-2);
      // determine if this account is local or remote.
      $account = Account::create($acc_address, FALSE);
      Misc::response(200, (new Wallet($account))->getHistory($params['samples']??0, $account->created));
    }
    elseif (arg(2) == 'limits') {
      \check_permission('accountLimits');
      $name = arg(3);
      // the name might have been deleted because of GDPR
      if ($account = (new AccountStore())->fetch($name, FALSE)) {
        $response = (object)['min' => $account->min, 'max' => $account->max];
        Misc::response(200, $response);
      }
    }
  }
  elseif ($endpoint == 'transaction') {
    if (arg(2) == 'filter') {
      check_permission('filterTransactions');
      $uuids = Transaction::filter($params);

      if ($uuids and !empty($params['full'])) {
        foreach ($uuids as $key => $uuid) {
          $transactions[$uuid] = Transaction::loadByUuid($uuid);
        }
        // Question = Should we make an internal class to pass transactions between nodes?
        cc_ledger_response(200, $transactions);
      }
      else {
        cc_ledger_response(200, $uuids);
      }
    }
  }
}
// Create a new transaction
elseif ($method == 'POST') {
  $post = Misc::jsonInput();
  if ($endpoint == 'transaction') {
    if (arg(2) == 'new') {
      \check_permission('newTransaction');
      //$post is an stdClass derived from NewTransaction
      // this will throw an exception if the incoming data is bad.
      $newTransaction = new NewTransaction($post->payee, $post->payer, $post->quantity, $post->description, $post->type);
      $transaction = Transaction::createFromClient($newTransaction);
    }
    elseif (arg(2) == 'relay') {
      \check_permission('relayTransaction');
      // There is always an upstream node.
      $transaction = TransversalTransaction::createFromUpstreamNode($post);
    }
    $transaction->buildValidate();
    $transaction->writeValidatedToTemp();
    cc_ledger_response(201, $transaction);
  }
}
// Change the state of an existing transaction
elseif ($target_state = arg(3) and $method == 'PATCH') {
  \check_permission('stateChange');
  // validate input
  $valid_states = [
    TransactionInterface::STATE_PENDING,
    TransactionInterface::STATE_COMPLETED,
    TransactionInterface::STATE_ERASED
  ];
  $uuid = arg(2);
  if (!in_array($target_state, $valid_states)) {
    Misc::response(400, "Invalid workflow type.");
  }
  $transaction = Transaction::loadByUuid($uuid);
  // Ensure that transversal transactions are being manipulated only from their
  // end points, not an intermediate ledger
  if (!$orientation->upstreamAccount and !empty($transaction->payer->url) and !empty($transaction->payee->url)) {
    throw new Exceptions\IntermediateLedgerViolation();
  }

  $transaction->changeState($target_state, $_SESSION['user']);
  cc_ledger_response(201);
}

// This could also be 405
cc_ledger_response(404, "Could not match $method $endpoint to route", "Could not match $method input to route");


function ledger_journal($message, $type = 'request') {
  global $method;
  $message = Db::connect()->real_escape_string($message);
  $path = $_SERVER['REQUEST_URI'];
  Db::query("INSERT into log (type, http_method, path, message) VALUES ('request', '$method', '$path', '$message')");
}

/**
 * Switch the ledger mode before converting the response to json.
 *
 * @param int $code
 * @param Mixed $body
 */
function cc_ledger_response(int $code, $body = NULL) {
  global $orientation, $config;
  //header('Account-name: '.$config['node_name']); // Can't remember why this is necessary
  $orientation->responseMode = TRUE;
  Misc::response($code, $body);
}

function authorise() {
  global $roles;
  return TRUE;
}

function authenticateUser($acc_id, $auth) {
  global $orientation, $config, $loadedAccounts;
  $upstreamAccount = NULL;
  // Find out where the request is coming from, check the hash if it is another
  // node, and set up the session, which should persist accross the microservices.
  if ($acc_id and $acc_id <> 'null') {
    $account = Account::load($acc_id);
    if ($account instanceOf Remote) {
      $query = "SELECT TRUE FROM hash_history WHERE acc = '$account->id' AND hash = '$hash' ORDER BY id DESC LIMIT 0, 1";
      $result = Db::query($query)->fetch_row();
      if ($hash && !$result or !$hash && $result) {
        throw new HashMismatchFailure(['downStreamNode' => $config['node_name']]);
      }
      $upstreamAccount = $account;
    }
    // Failed logins can still access some endpoints
    elseif (!$account->checkAuth($auth)) {
    //elseif (!AccountStore()->auth($acc_id, $auth)) {
      $acc_id = '';
    }
  }
  $_SESSION['user'] = $acc_id;

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
    if ($acc = (new AccountStore())->fetch($id, FALSE)) {
      $fetched[$id] = $acc;
    }
  }
  return $fetched[$id];
}

function get_permitted_methods() {
  global $orientation;
  $data = CreditCommonsInterface::OPERATIONS;
  $permitted[] = 'workflows';
  $permitted[] = 'permittedEndpoints';
  $permitted[] = 'handshake';
  $permitted[] = 'accountNames';
  $permitted[] = 'absoluteAddress';

  if ($_SESSION['user']) {
    $permitted[] = 'accountHistory';
    $permitted[] = 'accountLimits';
    $permitted[] = 'accountSummary';
    $permitted[] = 'newTransaction';
    $permitted[] = 'stateChange';
    $permitted[] = 'filterTransactions';
  }
  if ($orientation->upstreamAccount) {
    $permitted[] = 'relayTransaction';
  }
  // @todo this is more about the role
  if ($_SESSION['user'] == 'admin') {

  }
  return array_intersect_key(CreditCommonsInterface::OPERATIONS, array_flip($permitted));
}

function check_permission(string $operationId) {
  if (!in_array($operationId, array_keys(get_permitted_methods()))) {
    throw new PermissionViolation([
      'account' => $_SESSION['user'],
      'method' => $_SERVER['REQUEST_METHOD'],
      'url' => $_SERVER['REDIRECT_URL']??$_SERVER['REQUEST_URI']
    ]);
  }
}

function getLCHeaders() : array {
  $headers = [];
  foreach (getallHeaders() as $key => $val) {
    $headers[strtolower($key)] = $val;
  }
  return $headers;
}

// Log all errors before throwing them back upstream.
function ccnode_exception_handler(\Throwable $exception) {
  ledger_journal($exception, 'clientError');
  Misc::exceptionHandler($exception);
}

function AccountStore() {
  return new AccountStore();
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
