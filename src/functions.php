<?php

namespace CCNode;

use CCNode\Accounts\Remote;
use CCNode\AddressResolver;
use CCNode\Transaction\Transaction;
use CreditCommons\Account;
use CreditCommons\AccountRemoteInterface;
use CreditCommons\AccountStoreInterface;
use CreditCommons\TransactionInterface;
use CreditCommons\NewTransaction;
use CreditCommons\NodeRequester;
use CreditCommons\Exceptions\CCFailure;
use CreditCommons\Exceptions\DoesNotExistViolation;
use CreditCommons\CreditCommonsInterface;

/**
 * Load an account from the accountStore.
 *
 * @staticvar array $fetched
 * @param string $acc_id
 *   The account id or empty string to load a dummy account.
 * @return CreditCommons\Account
 * @throws DoesNotExistViolation
 *
 * @todo This doesn't seem like a good place to throw a violation.
 */
function load_account(string $local_acc_id = NULL, string $rel_path = '') : Account {
  if (strpos($local_acc_id, '/')) {
    throw new CCFailure("Can't load unresolved account name: $local_acc_id");
  }
  if ($local_acc_id and accountStore()->has($local_acc_id)) {
    return accountStore()->fetch($local_acc_id, $rel_path);
  }
  throw new DoesNotExistViolation(type: 'account', id: $local_acc_id);
}

/**
 * Get the object with all the API calls, initialised with a remote account to call.
 *
 * @param Remote $account
 *   if not provided the balance of trade of account will be used
 * @return NodeRequester|NULL
 */
function API_calls(Remote $account = NULL) {
  global $cc_config, $cc_user;
  if (!$account) {
    if ($bot = $cc_config->trunkwardAcc) {
      $account = load_account($bot);
    }
    else {
      return NULL;
    }
  }
  $requester = new NodeRequester($account->getUrl(), $cc_config->nodeName, $account->getLastHash());
  if (!($cc_user instanceOf AccountRemoteInterface)){
    $requester->catch = TRUE;
  }
  return $requester;
}

/**
 * Get the object for accessing ledger accounts.
 */
function accountStore() : AccountStoreInterface {
  global $cc_config;
if (!isset($cc_config)){print_r(debug_backtrace());exit;}
  $class = $cc_config->accountStore;
  if (filter_var($class, FILTER_VALIDATE_URL)) {
    $store = new \CCNode\AccountStoreREST(trunkwardAccName: $cc_config->trunkwardAcc);
  }
  elseif (class_exists($class)) {
    // filepath is in the web root, but where are we?
    $store = new $class(trunkwardAccName: $cc_config->trunkwardAcc);
  }
  else {
    throw new CCFailure('Invalid accountStore setting: '.$cc_config->accountStore);
  }
  return $store;
}

/**
 * Write a message to a debug file.
 */
function debug($val, $whatis = '') {
  global $cc_config;
  $file = $cc_config->nodeName.'.debug';
  if (!is_scalar($val)) {
    $val = print_r($val, TRUE);
  }
  file_put_contents(
    $file,
    date('H:i:s')." $whatis:  $val\n",
    FILE_APPEND
  );
}

/**
 * @todo put these functions in an always included file so they needn't be called with the namespace.
 */

/**
 * Access control for each API method.
 *
 * Anyone can see what endpoints they can user, any authenticated user can check
 * the workflows and the connectivity of adjacent nodes. But most operations are
 * only accessible to direct members and leafward member, making this node quite
 * private with respect to the rest of the tree.
 *
 * @return string[]
 *   A list of the api method names the current user can access.
 *
 * @todo make this more configurable.
 */
function permitted_operations() : array {
  global $cc_user;
  $permitted[] = 'permittedEndpoints';
  $permitted[] = 'about';
  if ($cc_user->id <> '-anon-') {
    $permitted[] = 'handshake';
    $permitted[] = 'workflows';
    $permitted[] = 'newTransaction';
    $permitted[] = 'absolutePath';
    $permitted[] = 'stateChange';
    $map = [
      'filterTransactions' => 'transactions',
      'filterTransactionEntries' => 'transactions',
      'getTransaction' => 'transactions',
      'getEntries' => 'transactions',
      'accountHistory' => 'transactions',
      'accountLimits' => 'acc_summaries',
      'accountNameFilter' => 'acc_ids',
      'accountSummary' => 'acc_summaries'
    ];
    foreach ($map as $method => $perm) {
      if (!$cc_user instanceOf Trunkward or $this->config->privacy[$perm]) {
        $permitted[] = $method;
      }
    }
    if ($cc_user instanceof Remote) {
      $permitted[] = 'relayTransaction';
    }
  }
  return array_intersect_key(CreditCommonsInterface::OPERATIONS, array_flip($permitted));
}


/**
 * Generate links first paged listings.
 *
 * @param string $endpoint
 * @param array $params
 * @param int $total_items
 * @return array
 */
function pager(string $endpoint, array $params, int $total_items) : array {
  $params = $params +=['offset' => 0, 'limit' => 25];
  $curr_page = floor($params['offset'] / $params['limit']);
  $pages = ceil($total_items/$params['limit']);
  $links = [];
  if ($pages > 1) {
    if($curr_page > 0) {
      $links['first'] = $endpoint .'?'.http_build_query(['offset' => 0] + $params);
      if($curr_page > 1) {
        $links['prev'] = $endpoint .'?'.http_build_query(['offset' => ($curr_page -1) * $params['limit']] + $params);
      }
    }
    if ($curr_page < $pages) {
      $links['next'] = $endpoint .'?'.http_build_query(['offset' => ($curr_page +1) * $params['limit']] + $params);
      if ($curr_page < ($pages -1)) {
        $links['last'] = $endpoint .'?'.http_build_query(['offset' => ($pages -1) * $params['limit']] + $params);
      }
    }
  }
  return $links;
}

  /**
   * @param \stdClass &$row
   * @return bool
   *   TRUE if payer or payee are remote accounts
   */
function upcastAccounts(\stdClass $row) : bool {
  $addressResolver = AddressResolver::create();
  $remote = FALSE;
  // metadata contains the non-local parts of the address
  foreach (['payee', 'payer'] as $role) {
    $acc_path = $row->{$role};
    if (empty($acc_path)) {
      // @todo need to validate
    }
    $row->{$role} = $addressResolver->localOrRemoteAcc($acc_path);
    if ($row->{$role} instanceOf Remote) {
      $remote = TRUE;
    }
  }
  return $remote;
}


/**
 * @param type $float
 * @return string
 * @kudos https://stackoverflow.com/questions/1954018/php-convert-decimal-into-fraction-and-back#9143510
 * @kudos https://www.alt-codes.net/fraction-symbols
 */
function decToFraction($float) {
  // 1/2, 1/4, 1/8, 1/16, 1/3 ,2/3, 3/4, 3/8, 5/8, 7/8, 3/16, 5/16, 7/16,
  // 9/16, 11/16, 13/16, 15/16
  $whole = floor ( $float );
  $decimal = $float - $whole;
  $leastCommonDenom = 48; // 16 * 3;
  $denominators = array (2, 3, 4, 5);
  $roundedDecimal = round ( $decimal * $leastCommonDenom ) / $leastCommonDenom;
  if ($roundedDecimal == 0)
    return $whole;
  if ($roundedDecimal == 1)
    return $whole + 1;
  foreach ($denominators as $d ) {
    if ($roundedDecimal * $d == floor($roundedDecimal * $d )) {
      $denom = $d;
      break;
    }
  }
  $numerator = intval($roundedDecimal*$denom);
  if (isset($denom)) {
    switch ($denom) {
      case 2: //
        $frac = "&#189;"; #1/2
        break;
      case 3:
        if ($numerator == 1)$frac = '&#8531'; # 1/3
        else $frac = '&#8532'; # 2/3
        break;
      case 4:
        if ($numerator == 1)$frac = '&#188;'; # 1/4
        else $frac = '&#190;'; # 3/4
        break;
      case 5:
        if ($numerator == 1)$frac = '&#8533;';
        elseif ($numerator == 2)$frac = '&#8534;';
        elseif ($numerator == 3)$frac = '&#8535;';
        elseif ($numerator == 4)$frac = '&#8536;';
        break;
      default:
        //$frac = ($whole == 0 ? '' : $whole) . " " . ($roundedDecimal * $denom) . "/" . $denom;
    }
    return $frac;
  }

}

/**
 * Create a new transaction from a few required fields defined upstream.
 * @param NewTransaction $new
 * @return TransactionInterface
 */
function convertNewTransaction(NewTransaction $new) : Transaction {
  $data = new \stdClass;
  $data->uuid = $new->uuid;
  $data->type = $new->type;
  $data->state = TransactionInterface::STATE_INITIATED;
  $data->version = -1; // Corresponds to state init, pre-validated.
  $data->entries = [(object)[
    'payee' => $new->payee,
    'payer' => $new->payer,
    'description' => $new->description,
    'metadata' => $new->metadata,
    'quant' => $new->quant,
    'isPrimary' => TRUE
  ]];
  return Transaction::create($data);
}
