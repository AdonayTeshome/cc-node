<?php

namespace AccountStore;

/**
 * Class for reading and writing account data from a csv file.
 * Performance is not an issue as groups should never exceed more than a few hundred members.
 */
class AccountManager implements \Iterator, \ArrayAccess, \Countable {

  const FILESTORE = 'store.json';
  private $defaults; // if this is true it can't be saved.

  /**
   *
   * @var array
   */
  public $accounts = [];

  private $pos = 0;

  function __construct($with_defaults = TRUE) {
    global $config;
    $accs = (array)json_decode(file_get_contents(self::FILESTORE));
    foreach ($accs as $data) {
      if ($with_defaults) {
        $this->defaults = $with_defaults;
        if (!isset($data->min)) {
          $data->min = $config['default_min'];
        }
        if (!isset($data->max)) {
          $data->max = $config['default_max'];
        }
        if (!isset($data->status)) {
          $data->status = $config['default_status'];
        }
        if (!isset($data->admin)) {
          $data->admin = 0;
        }
      }
      $class  = !empty($data->url) ? '\AccountStore\RemoteRecord' : '\AccountStore\UserRecord';
      $this->accounts[$data->id] = new $class(
        $data->id,
        $data->url??$data->key,
        $data->created,
        $data->status??NULL,
        $data->min??NULL,
        $data->max??NULL,
        $data->admin??NULL
      );

    }
  }


  function save() {
    if ($this->defaults) {
      die("Can't save accounts loaded with defaults");
    }
    file_put_contents(self::FILESTORE, json_encode($this->accounts, JSON_PRETTY_PRINT));
  }

  function addAccount(Record $record) {
    $this->accounts[$record->id] = $record;
    $this->save();
  }

  /**
   *
   * @param string $string
   */
  function filterByName(string $string = '') {
    if ($string) {
      $this->accounts = array_filter($this->accounts, function ($a) use ($string) {
        return is_int(stripos($a->id, $string));
      });
    }
  }

  /**
   *
   * @param bool $status
   *   True for active, FALSE for Blocked
   */
  function filterByStatus(bool $status) {
    global $config;
    $this->accounts = array_filter($this->accounts, function ($a) use ($status) {
      return $status == $a->status;
    });
  }
  /**
   *
   * @param bool $local
   *   TRUE for local accounts, FALSE for remote accounts
   */
  function filterByLocal(bool $local) {
    $class = $local ? 'AccountStore\UserRecord' : 'AccountStore\RemoteRecord';
    $this->accounts = array_filter($this->accounts, function ($a) use ($class) {
      return $a instanceof $class;
    });
  }

  /**
   * @param string $view_mode
   * @return stdClass[]
   */
  function view(string $view_mode) : array {
    return array_map(
      function ($a) use ($view_mode) {return $a->view($view_mode);},
      $this->accounts
    );
  }


  /**
   * @param string $id
   * @return bool
   */
  function availableName($id) {
    return !isset($this[$id]) ;
  }

  function validName($id) {
    return preg_match('/^[a-z0-9@.]{1,32}$/', $id) and strlen($id) < 32;
  }

  static function validateFields(array $fields) : array {
    $errs = Record::validateFields($fields);
    return $errs;
  }

  function key() {
    return $this->pos;
  }

  function valid() {
     return isset($this->accounts[$this->pos]);
  }

  function current() {
    return $this->accounts[$this->pos];
  }

  function rewind() {
    $this->pos = 0;
  }

  function next() {
    ++$this->pos;
  }

  public function offsetExists($offset) : bool {
    return array_key_exists($offset, $this->accounts);
  }

  public function offsetGet($offset) {
    return $this->accounts[$offset];
  }
  public function offsetSet($offset, $value) : void {
    $this->accounts[$offset] = $value;
  }
  public function offsetUnset($offset) : void {
    trigger_error('Cannot delete accounts', E_USER_WARNING);
  }
  public function count() : int {
    return count($this->accounts);
  }
}

