<?php

namespace AccountStore;

/**
 * Class for reading and writing policy data from a csv file
 */
class UserRecord extends Record {

  /**
   * A password or api key (user accounts only)
   * @var string
   */
  public $key;

  public $admin;

  function __construct(string $id, $key, int $created, $status = NULL, $min = NULL, $max = NULL, $admin = NULL) {
    parent::__construct($id, $created, $status, $min, $max, $admin);
    $this->key = $key;
    $this->admin = $admin;
  }


  function override($new_data) {
    if (isset($new_data->admin)) {
      $this->admin = $new_data->admin;
    }
    parent::override($new_data);
  }

}
