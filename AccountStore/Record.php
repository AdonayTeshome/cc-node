<?php

namespace AccountStore;

/**
 * Class for reading and writing policy data from a csv file
 */
abstract class Record {

  /**
   * The unique name of the account
   * @var string
   */
  public $id;

  /**
   * The unixtime the account was created
   * @var string
   */
  public $created;

  /**
   * @var bool|null
   */
  public $status;

  /**
   * @var float|null
   */
  public $min;

  /**
   * @var float|null
   */
  public $max;

  /**
   *
   * @param string $id
   * @param int $created
   * @param type $status
   * @param type $min
   * @param type $max
   * @note we don't typecast here because optional values are NULL when loading in override mode.
   */
  function __construct(string $id, int $created, $status = NULL, $min = NULL, $max = NULL) {
    $this->id = $id;
    $this->created = $created;
    $this->status = $status;
    $this->min = $min;
    $this->max = $max;
  }

  function override(array $new_data) {
    foreach (['status', 'min', 'max'] as $field) {
      if (isset($new_data[$field])) {
        // we can't send null values from form input so empty string means null i.e. revert to default.
        $val = $new_data[$field] == '' ? NULL : $new_data[$field];
        $this->{$field} = $val;
      }
    }
  }

  function overridden() {
    $overridden = new \stdClass();
    foreach ((array)$this as $key=>$val) {
      if (!is_null($val)) {
        $overridden->{$key} = $val;
      }
    }
    return $overridden;
  }

}
