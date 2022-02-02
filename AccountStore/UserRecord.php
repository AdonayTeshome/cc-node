<?php

namespace AccountStore;

/**
 * Class for reading and writing policy data from a csv file
 */
class UserRecord extends Record {

  /**
   * A password or API key (user accounts only)
   * @var string
   */
  public $key;

  public $admin;

  function __construct(\stdClass $data) {
    parent::__construct($data->id, $data->created, $data->status, $data->min??NULL, $data->max??NULL);
    $this->key = $data->key;
    $this->admin = $data->admin;
  }


  function set(\stdClass $new_data) {
    if (isset($new_data->admin)) {
      $this->admin = $new_data->admin;
    }
    parent::set($new_data);
  }


  function view($mode) {
    $result = parent::view($mode);
    if ($mode <> 'own') {
      unset($result->key);
    }
    return $result;
  }
}
