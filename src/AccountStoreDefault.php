<?php

namespace CCNode;

use CreditCommons\Exceptions\DoesNotExistViolation;
use CreditCommons\Account;
use CreditCommons\AccountStoreInterface;
use AccountStore\AccountManager;
use AccountStore\Record;
use AccountStore\UserRecord;

/**
 * Handle requests & responses from the ledger to the DefaultAccountStore.
 */
class AccountStoreDefault implements AccountStoreInterface {

  private $trunkwardAcc;
  private $accountManager;

  function __construct() {
    global $config;
    $this->trunkwardAcc = $config->trunkwardAcc;
    $this->accountManager = new AccountManager('./accounts.json');
  }

  /**
   * @inheritdoc
   */
  function checkCredentials(string $name, string $auth) : bool {
    return $this->accountManager[$name]->key == $auth;
  }

  /**
   * @inheritdoc
   */
  function filter(
    string $fragment = NULL,
    bool $local = NULL,
    bool $admin = NULL,
    int $limit = 10,
    int $offset = 0,
    bool $full = TRUE,
  ) : array {
    $all = $this->accountManager->accounts;

    if ($this->trunkwardAcc) {
      unset($this->accountManager->accounts[$this->trunkwardAcc]);
    }
    if (!empty($fragment)) {
      $this->accountManager->filterByName($fragment);
    }
    if (!is_null($local)) {
      $this->accountManager->filterByLocal($local);
    }
    if (!is_null($admin)) {
      $this->accountManager->filterByAdmin($admin);
    }
    $results = array_slice($this->accountManager->accounts, $offset, $limit);
    $this->accountManager->accounts = $all;
    if ($full) {
      // Upcast to CCNode accounts
      return array_map([$this, 'upcast'], $results);
    }
    else {
      return array_keys($results);
    }
  }

  /**
   * @inheritdoc
   */
  function fetch(string $name) : Account {
    if ($this->accountManager->has($name)) {
      $acc = $this->accountManager[$name];
      return $this->upcast($acc);
    }
    throw new DoesNotExistViolation(type: 'account', id: $name);
  }


  /**
   * Get the transaction limits for all accounts.
   * @return array
   */
  function allLimits() : array {
    $limits = [];
    foreach ($this->filter() as $info) {
      $limits[$info->id] = (object)['min' => $info->min, 'max' => $info->max];
    }
    return $limits;
  }

  /**
   * @inheritdoc
   */
  public function has(string $name) : bool {
    return isset($this->accountManager->accounts[$name]);
  }

  /**
   * {@inheritdoc}
   */
  public static function anonAccount() : Account {
    $obj = (object)['id' => '-anon-', 'max' => 0, 'min' => 0, 'key' => ''];
    $anon = new UserRecord($obj);
    return SELF::upcast($anon);
  }

  /**
   * Convert the AccountStore accounts into CCnode accounts
   *
   * @param Record $record
   * @return Account
   */
  private static function upcast(Record $record) : Account {
    global $user, $config;
    if (!empty($record->url)) {
      $upS = $user ? ($record->id == $user->id) : TRUE;
      $trunkward = $record->id == $config->trunkwardAcc;
      if ($trunkward and $upS) {
        $class = 'UpstreamTrunkward';
      }
      elseif ($trunkward and !$upS) {
        $class = 'DownstreamTrunkward';
      }
      elseif ($upS) {
        $class = 'UpstreamBranch';
      }
      else {
        $class = 'DownstreamBranch';
      }
    }
    else {
      $class = $record->admin ? 'Admin' : 'User';
    }
    $class = 'CCNode\Accounts\\'. $class;
    return $class::create($record->asObj());
  }
}
