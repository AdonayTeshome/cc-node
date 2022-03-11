<?php

namespace CCNode\Accounts;

use CCNode\Accounts\User;
use CreditCommons\RestAPI;
use CCNode\Db;
use CreditCommons\Exceptions\CCFailure;

/**
 * Class representing a remote account, which authorises using its latest hash.
 */
class Remote extends User {

  function __construct(
    public string $id,
    public bool $status,
    public int $min,
    public int $max,
    public string $url,
   ) {
    parent::__construct($id, $status, $min, $max, $url);
  }

  static function create(\stdClass $data) : User {
    static::validateFields($data);
    return new static($data->id, $data->status, $data->min, $data->max, $data->url);
  }

  /**
   * Get the last hash pertaining to this account.
   *
   * @return array
   */
  function getLastHash() : string {
    $query = "SELECT hash "
      . "FROM hash_history "
      . "WHERE acc = '$this->id' "
      . "ORDER BY id DESC LIMIT 0, 1";
    if ($row = Db::query($query)->fetch_object()) {
      return (string)$row->hash;
    }
    else { //No hash because this account has never traded to. Security problem?
      return '';
    }
  }

  public function API() : RestAPI {
    return new RestAPI($this->url, \CCNode\getConfig('node_name'), $this->getLastHash());
  }

  public function handshake() {
    try {
      $this->API()->handshake();
      return 'ok';
    }
    catch (CCFailure $e) {// fails to catch.
      return get_class($e);
    }
  }

  /**
   * The below functions might work better somewhere else.
   */

  public function getHistory($samples = 0) : array {
    // N.B. Branchward nodes may refuse permission
    return $this->API()->getHistory($this->transversalPath, $samples);
  }

  /**
   * {@inheritDoc}
   * @todo this functions returns a slightly different format on branchwards and trunkwards accounts.
   */
  function getAccountSummary($rel_path = '') : \stdClass {
    if ($rel_path) {
      $result = $this->API()->getAccountSummary($rel_path);
    }
    else {
      $result = parent::getAccountSummary();
    }
    return $result;
  }

  function accountNameAutocomplete($rel_path) {
    $result = $this->API()->accountNameAutocomplete($rel_path);
    return $result;
  }

  function getRelPath() : string {
    die('Need to calculate the relative path of remote account');
  }

}

