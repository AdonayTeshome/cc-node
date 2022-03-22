<?php

namespace CCNode\Accounts;

use CCNode\Accounts\User;
use CreditCommons\RestAPI;
use CCNode\Db;
use CreditCommons\Exceptions\CCFailure;

/**
 * An account on another node, represented by an account on the current node.
 */
class Remote extends User implements RemoteAccountInterface {

  function __construct(
    string $id,
    int $min,
    int $max,
    /**
     * The url of the remote node
     * @var string
     */
    public string $url,
    /**
     * The path from the node this account references, to a leaf account
     * @var string
     */
    public string $relPath = ''
   ) {
    parent::__construct($id, $min, $max, FALSE);
  }

  static function create(\stdClass $data) : User {
    $data->relPath??'';// have to set the field or this validation will fail.
    static::validateFields($data);
    return new static($data->id, $data->min, $data->max, $data->url, $data->relPath);
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

  public function handshake() : string {
    try {
      $this->API()->handshake();
      return 'ok';
    }
    catch (CCFailure $e) {// fails to catch.
      return get_class($e);
    }
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

  function getAccountSummaries($rel_path_to_node = '') : array {
    return $this->API()->getAccountSummaries($rel_path_to_node);
  }

  function getAllLimits($rel_path_to_node = '') : array {
    return (array)$this->API()->getAllAccountLimits($rel_path_to_node);
  }

  function getLimits($rel_path = '') : \stdClass {
    if ($rel_path) {
      $result = $this->API()->getAccountLimits($rel_path);
    }
    else {
      $result = parent::getLimits();
    }
    return $result;
  }

  function autocomplete($fragment = '') : array {
    return $this->api()->accountNameFilter($fragment);
  }

  function getHistory(int $samples = -1, $rel_path = '') : array {
    if ($rel_path) {
      $result = $this->api()->getAccountHistory($rel_path, $samples);
    }
    else {
      $result = parent::getHistory($samples);
    }
    return $result;
  }

  function onwardAccount() : string {
    return $this->relPath;
  }

}

