<?php

namespace CCNode\Accounts;
use CreditCommons\Account;

/**
 * Class representing a member of the ledger
 */
class User extends Account {
  public $admin;
  public $key;

  function __construct(\stdClass $obj) {
    parent::__construct($obj);
    $this->admin = $obj->admin??FALSE;
    $this->key = $obj->key??'';
  }

  function checkAuth(string $given) : bool {
    return $given == $this->key;
  }

}

