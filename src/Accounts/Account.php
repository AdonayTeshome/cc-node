<?php

namespace CCNode\Accounts;

use \CreditCommons\Account as CreditCommonsAccount;
use \CreditCommons\Exceptions\DoesNotExistViolation;

/**
 * Class representing an account on the ledger
 */
abstract class Account extends CreditCommonsAccount {

  /**
   *
   * @param string $given_path
   * @return Account
   * @throws CCViolation
   * @todo could do more type checking.
   */
  static function create(string $given_path, $known_to_exist = FALSE) : Account {
    return self::resolveAddress($given_path, $known_to_exist);
  }


  /**
   * Resolve to an account on the current node.
   * @return Account
   * @param bool $existing
   *   TRUE if the transaction has already been written, and thus we know the
   *   accounts exist. Unknown accounts either resolved to the BoT account or
   *   throw an exception
   */
  public static function resolveAddress(string $given_path, bool $existing) : Account {
    global $orientation, $config;
    // if its one name and it exists on this ledger then good.
    $parts = explode('/', $given_path);
    if (count($parts) == 1) {
      if ($pol = static::load($given_path)) {
        return $pol;
      }
      throw new DoesNotExistViolation(['type' => 'account', 'id' => $given_path]);
    }

    // A branchwards account, including the local node name
    $pos = array_search($config['node_name'], $parts);
    if ($pos !== FALSE and $branch_name = $parts[$pos+1]) {
      try {
        return static::load($branch_name);
      }
      catch (DoesNotExistViolation $e) {}
    }
    // A branchwards or rootwards account, starting with the account name on the local node
    $branch_name = reset($parts);
    try {
      return static::load($branch_name);
    }
    catch (DoesNotExistViolation $e) {}

    // Now the path is either rootwards, or invalid.
    if ($config['bot']['acc_id']) {
      $rootwardsAccount = static::load($config['bot']['acc_id'], TRUE);
      if ($existing) {
        return $rootwardsAccount;
      }
      if ($orientation->isUpstreamBranch()) {
        Misc::message("Attempting to resolve $given_path downstream");
        return $rootwardsAccount;
      }
    }
    throw new DoesNotExistViolation(['type' => 'account', 'id' => $given_path]);
  }

  /**
   * @staticvar array $loadedAccounts
   * @param string $id
   * @return \CCNode\Account
   */
  static function load(string $id = '') : self {

    global $loadedAccounts;
    if (!isset($loadedAccounts[$id])) {
      if ($id and $acc = \CCNode\accountStore()->fetch($id, FALSE)) {
        $loadedAccounts[$id] = $acc;
      }
      else {
        $dummy = (object)['id' => '', 'created' => 0];// these are both required fields
        $loadedAccounts[$id] = new User($dummy);
      }
    }
    return $loadedAccounts[$id];
  }

  /**
   * Determine the class of the Account, considering this node's position in the ledger tree.
   *
   * @param string $acc_id
   * @param string $account_url
   * @param string $upstream_acc_id
   * @param string $BoT_acc_id
   * @return string
   *
   */
  static function determineAccountClass(string $acc_id, string $account_url = '', string $upstream_acc_id = '', string $BoT_acc_id = '') : string {
    if ($account_url) {
      $BoT = $acc_id == $BoT_acc_id;
      $upS = $acc_id == $upstream_acc_id;
      if ($BoT and $upS) {
        $class = 'UpstreamBoT';
      }
      elseif ($BoT and !$upS) {
        $class = 'DownstreamBoT';
      }
      elseif ($upS) {
        $class = 'UpstreamBranch';
      }
      else {
        $class = 'DownstreamBranch';
      }
    }
    else {
      $class = 'Accounts';
    }

    return 'CCNode\User\\'. $class;
  }


  function isAdmin(): bool{
    return property_exists($this, 'admin') and $this->admin;
  }

  function accessOperation($operationId) : bool {
    $permitted = \CCNode\permitted_operations();
    return in_array($operationId, array_keys($permitted));
  }

}
