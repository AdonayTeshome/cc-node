<?php

namespace CCNode\Accounts;

use CCNode\Accounts\User;
use CCNode\Transaction\Transaction;
use CCNode\Db;
use CreditCommons\NodeRequester;
use CreditCommons\Exceptions\CCFailure;
use CreditCommons\Exceptions\HashMismatchFailure;


/**
 * An account on another node, represented by an account on the current node.
 */
abstract class Remote extends User implements RemoteAccountInterface {

  /**
   * The path from the node this account references, to a leaf account
   * @var string
   */
  public string $relPath = '';

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

  static function create(\stdClass $data, string $rel_path = '') : User {
    static::validateFields($data);
    $acc = new static($data->id, $data->min, $data->max, $data->url);
    $acc->relPath = $rel_path;
    return $acc;
  }

  /**
   * {@inheritdoc}
   */
  public function isNode() : bool {
    return empty($this->relPath) or substr($this->relPath, -1) == '/';
  }

  /**
   * {@inheritdoc}
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

  /**
   * {@inheritdoc}
   */
  public function buildValidateRelayTransaction(Transaction $transaction) : array {
    return $this->API()->buildValidateRelayTransaction($transaction);
  }

  /**
   * {@inheritdoc}
   */
  public function handshake() : string {
    try {
      $this->API()->handshake();
      return 'ok'; // @todo shouldn't this return nothing or fail?
    }
    catch (CCFailure $e) {// fails to catch.
      return get_class($e);
    }
  }

  /**
   * {@inheritdoc}
   */
  function autocomplete() : array {
    return $this->api()->accountNameFilter($this->relPath);
  }


  /**
   * {@inheritdoc}
   */
  function getSummary($force_local = FALSE) : \stdClass {
    if ($force_local) {
      $result = parent::getSummary();
    }
    else {
      // An account on another (branchward) node
      $result = $this->API()->getAccountSummary($this->relPath);
      $result = reset($result);
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  function getAllSummaries() : array {
    // the relPath should have a slash at the end of it.
    return $this->API()->getAccountSummary($this->relPath);
  }

  /**
   * {@inheritdoc}
   */
  function getLimits($force_local = FALSE) : \stdClass {
    if ($this->relPath) {
      $result = $this->API()->getAccountLimits($this->relPath);
      // Always returns an array
      $result = reset($result);
    }
    else {
      $result = parent::getLimits();
    }
    return $result;
  }

  function getAllLimits() : array {
    // the relPath should always have a slash at the end of it.
    return $this->API()->getAccountLimits($this->relPath);
  }

  /**
   * {@inheritdoc}
   */
  function getHistory(int $samples = -1) : array {
    if ($path = $this->relPath) {
      $result = (array)$this->api()->getAccountHistory($path, $samples);
      if ($rate = $this->trunkwardConversionRate) {
        $result = array_map(function ($v) use ($rate) {return ceil($v/$rate);}, $result);
      }
    }
    else {
      $result = (array)parent::getHistory($samples);
    }
    return $result;
  }


  /**
   * {@inheritdoc}
   */
  protected function API() : NodeRequester {
    global $cc_config;
    return new NodeRequester($this->url, $cc_config->nodeName, $this->getLastHash());
  }

  /**
   * {@inheritdoc}
   */
  function authenticate(string $hash) {
    if (empty($hash)) {// If there is no history...
      // this might not be super secure...
      $query = "SELECT TRUE FROM hash_history "
        . "WHERE acc = '$this->id'"
        . "LIMIT 0, 1";
      $result = Db::query($query)->fetch_object();
      if ($result == FALSE) return;
    }
    else {
      // Remote nodes connect with a hash of the connected account, which needs to be compared.
      $query = "SELECT TRUE FROM hash_history WHERE acc = '$this->id' AND hash = '$hash' ORDER BY id DESC LIMIT 0, 1";
      $result = Db::query($query)->fetch_object();
      if ($result) return;
    }
    throw new HashMismatchFailure($this->id, $hash);
  }

  function __toString() {
    return $this->id . '/'.$this->relPath;
  }


  /**
   * {@inheritdoc}
   */
  function getConversationRate() : \stdClass {
    return $this->api()->convertPrice($this->relPath);
  }

}
