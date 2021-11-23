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
   * @var bool
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
   * @var array
   */
  private $overridableFields = ['min', 'max'];

  /**
   *
   * @param string $id
   * @param int $created
   * @param bool $status
   * @param type $min
   * @param type $max
   */
  function __construct(string $id, int $created, bool $status, $min = NULL, $max = NULL) {
    $this->id = $id;
    $this->created = $created;
    $this->status = $status;
    $this->min = $min;
    $this->max = $max;
  }

  /**
   * Set the record to the new values.
   * Don't forget to do accountManager->save() afterwards.
   * @param \stdClass $new_data
   */
  function set(\stdClass $new_data) {
    if (isset($new_data->status)) {
      $this->status = (bool)$new_data->status;
    }
    foreach ($this->overridableFields as $fname) {
      if (isset($new_data->{$fname})) {
        // We can't send null values from form input so empty string means null i.e. revert to default.
        $val = $new_data->{$fname} == '' ? NULL : $new_data->{$fname};
        $this->{$fname} = $val;
      }
    }
  }

  function overridden() {
    $overridden = new \stdClass();
    foreach ($this->overridableFields as $fname) {
      if (!is_null($this->{$fname})) {
        $overridden->{$fname} = $this->{$fname};
      }
    }
    return $overridden;
  }

  function view($mode) {
    global $config;
    if ($mode == 'own') {
      $ret = $this;
    }
    if ($mode == 'name') {
      $ret = $this->id;
    }
    if ($mode == 'full') {
      $full = clone($this);
      if (is_null($this->max)) {
        $full->max = $config['default_max'];
      }
      if (is_null($this->min)) {
        $full->min = $config['default_min'];
      }
      $ret = $full;
    }

    return $ret;
  }

}
