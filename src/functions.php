<?php

namespace CCNode;

use CCNode\Accounts\Remote;
use CreditCommons\AccountStoreInterface;
use CreditCommons\Exceptions\CCFailure;
use CreditCommons\Exceptions\DoesNotExistViolation;
use CreditCommons\NodeRequester;
use CreditCommons\Account;


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
  global $config;
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
  global $config;
  if (!$account) {
    if ($bot = $config->trunkwardAcc) {
      $account = load_account($bot);
    }
    else {
      return NULL;
    }
  }
  return new NodeRequester($account->url, $config->nodeName, $account->getLastHash());
}

/**
 * Get the library of functions for accessing ledger accounts.
 * Careful if trying to cache the accountstore
 */
function accountStore() : AccountStoreInterface {
  global $config;
  $class = class_exists($config->accountStore) ? $config->accountStore : '\CCNode\AccountStoreREST';
  return new $class($config->trunkwardAcc);
}

/**
 * Write a message to a debug file.
 */
function debug($val) {
  global $config;
  $file = $config->nodeName.'.debug';
  if (!is_scalar($val)) {
    $val = print_r($val, TRUE);
  }
  $written = file_put_contents(
    $file,
    date('H:i:s')."  $val\n",
    FILE_APPEND
  );
}
