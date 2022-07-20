<?php

namespace CCNode;

use CCNode\Accounts\Remote;
use CreditCommons\AccountStoreInterface;
use CreditCommons\Exceptions\CCFailure;
use CreditCommons\Exceptions\DoesNotExistViolation;
use CreditCommons\NodeRequester;
use CreditCommons\Account;
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
  if (strpos(needle: '/', haystack: $local_acc_id)) {
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
  global $cc_config;
  if (!$account) {
    if ($bot = $cc_config->trunkwardAcc) {
      $account = load_account($bot);
    }
    else {
      return NULL;
    }
  }
  return new NodeRequester($account->url, $cc_config->nodeName, $account->getLastHash());
}

/**
 * Get the object for accessing ledger accounts.
 */
function accountStore() : AccountStoreInterface {
  global $cc_config;
  if (filter_var($cc_config->accountStore, FILTER_VALIDATE_URL)) {
    $class = '\CCNode\AccountStoreREST';
  }
  elseif (class_exists($cc_config->accountStore)) {
    $class = $cc_config->accountStore;
  }
  else {
    throw new CCFailure('Invalid accountStore setting: '.$cc_config->accountStore);
  }
  return new $class;
}

/**
 * Write a message to a debug file.
 */
function debug($val) {
  global $cc_config;
  $file = $cc_config->nodeName.'.debug';
  if (!is_scalar($val)) {
    $val = print_r($val, TRUE);
  }
  $written = file_put_contents(
    $file,
    date('H:i:s')."  $val\n",
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
  if ($cc_user->id <> '-anon-') {
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
