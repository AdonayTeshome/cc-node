<?php

namespace CCNode\Accounts;
use CreditCommons\Account;

/**
 * Class representing a member of the ledger
 */
class User extends Account {

  function __construct(
    $id,
    $status,
    $min,
    $max,
    public bool $admin
  ) {
    parent::__construct($id, $status, $min, $max);
  }

  static function create(\stdClass $data) : Account {
    static::validateFields($data);
    return new static($data->id, $data->status, $data->min, $data->max, $data->admin??FALSE);
  }


  static function AnonAccount() {
    $obj = ['id' => '<anon>', 'max' => 0, 'min' => 0, 'status' => 1];
    return static::create((object)$obj);
  }

  function isAdmin() : bool {
    return $this->admin;
  }


}

