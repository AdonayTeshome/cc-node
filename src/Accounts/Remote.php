<?php

namespace CCNode\Accounts;

use CCNode\Accounts\User;
use CreditCommons\NodeRequester;
use CCNode\Db;
use CreditCommons\Exceptions\CCFailure;

/**
 * An account on another node, represented by an account on the current node.
 */
class Remote extends User implements RemoteAccountInterface {

  /**
   * The path from the node this account references, to a leaf account
   * @var string
   */
  public string $givenPath = '';

  function __construct(
    string $id,
    int $min,
    int $max,
    /**
     * The url of the remote node
     * @var string
     */
    public string $url
   ) {
    parent::__construct($id, $min, $max, FALSE);
  }

  static function create(\stdClass $data) : User {
    static::validateFields($data);
    return new static($data->id, $data->min, $data->max, $data->url);
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

  public function API() : NodeRequester {
    return new NodeRequester($this->url, \CCNode\getConfig('node_name'), $this->getLastHash());
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

  // the path to the remote account relative to this account on the local ledger
  function relPath() {
    $parts = explode('/', $this->givenPath);
    // remove everything including the node name.
    $pos = array_search($this->id, $parts);
    if (FALSE !== $pos) {
      $parts = array_slice($parts, $pos+1);
    }
    return implode('/', $parts);
  }

  function isAccount() {
    return substr($this->givenPath, -1) <> '/';
  }
  function isNode() {
    return substr($this->givenPath, -1) == '/';
  }

  /**
   * These two methods belong to remote Nodes rather than remote accounts.
   * Refactor them a bit so as to reduce ambiguity with the
   */
  function getAccountSummaries($rel_path_to_node = '') : array {
    // why isn't this using the given path?
    return $this->API()->getAccountSummaries($rel_path_to_node);
  }

  function getAllLimits($rel_path_to_node = '') : array {
    // why isn't this using the given path?
    return (array)$this->API()->getAllAccountLimits($rel_path_to_node);
  }


}

