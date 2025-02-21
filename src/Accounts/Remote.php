<?php

namespace CCNode\Accounts;

use CCNode\Accounts\User;
use CCNode\Transaction\Transaction;
use CCNode\Db;
use function CCNode\API_calls;
use CreditCommons\Exceptions\HashMismatchFailure;


/**
 * An account on another node, represented by an account on the current node.
 */
abstract class Remote extends User implements RemoteAccountInterface {

  /**
   * Saves recalculating.
   * @var string
   */
  private string $lastHash;

  function __construct(
    string $id,
    int $min,
    int $max,
    /**
     * The url of the remote node
     * @var string
     */
    protected string $url
   ) {
    parent::__construct($id, $min, $max);
  }

  static function create(\stdClass $data, string $rel_path = '') : static {
    static::validateFields($data);
    $acc = new static($data->id, $data->min, $data->max, $data->url);
    $acc->relPath = $rel_path;
    return $acc;
  }

  /**
   * {@inheritDoc}
   */
  public function isNode() : bool {
    return empty($this->relPath) or substr($this->relPath, -1) == '/';
  }

  /**
   * {@inheritDoc}
   */
  function getLastHash() : string {
    if (!isset($this->lastHash)) {
      $this->lastHash = '';
      $query = "SELECT hash FROM hash_history WHERE acc_id = '$this->id' ORDER BY txid DESC LIMIT 0, 1";
      /** @var \mysqli_result $result */
      $result = Db::query($query);
      if ($result->num_rows) {
        $this->lastHash = $result->fetch_object()->hash;
      }
    }
    return $this->lastHash;
  }

  /**
   * {@inheritDoc}
   * @param Transaction $transaction
   *   We don't have a way of requiring it be a transversal transaction because
   *   the object inheritance isn't flexible enough
   */
  function storeHash(\CreditCommons\Transaction $transaction) {
    $hash = $transaction->makeHash($this);
    Db::query("INSERT INTO hash_history (txid, acc_id, hash) VALUES ($transaction->txID, '$this->id', '$hash')");
  }

  /**
   * {@inheritDoc}
   */
  public function relayTransaction(Transaction $transaction) : array {
    return API_calls($this)->buildValidateRelayTransaction($transaction);
  }

  /**
   * {@inheritDoc}
   */
  public function handshake() : string {
    API_calls($this)->handshake();
    return 'ok'; // @todo shouldn't this return nothing or fail?
  }

  /**
   * {@inheritDoc}
   */
  function autocomplete() : array {
    return API_calls($this)->accountNameFilter($this->relPath);
  }

  /**
   * {@inheritDoc}
   */
  function getSummary($force_local = FALSE) : \stdClass {
    if ($force_local) {
      $result = parent::getSummary();
    }
    else {
      // An account on another (branchward) node
      $result = API_calls($this)->getAccountSummary($this->relPath);
      $result = reset($result);
    }
    return $result;
  }

  /**
   * {@inheritDoc}
   */
  function getAllSummaries() : array {
    // the relPath should have a slash at the end of it.
    return API_calls($this)->getAccountSummary($this->relPath);
  }

  /**
   * {@inheritDoc}
   */
  function getLimits($force_local = FALSE) : \stdClass {
    if ($this->relPath) {
      $result = API_calls($this)->getAccountLimits($this->relPath);
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
    return API_calls($this)->getAccountLimits($this->relPath);
  }

  /**
   * {@inheritDoc}
   */
  function getHistory(int $samples = -1) : array {
    if ($path = $this->relPath) {
      $result = (array)API_calls($this)->getAccountHistory($path, $samples);
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
   * {@inheritDoc}
   */
  function authenticate(string $remote_hash) {
    $local_hash = $this->getLastHash();
    if ($remote_hash == $local_hash) {
      return;
    }
    throw new HashMismatchFailure($this->id, $local_hash, $remote_hash);
  }

  function __toString() {
    return $this->id . '/'.$this->relPath;
  }

  /**
   * {@inheritDoc}
   */
  function getConversionRate() : \stdClass {
    return API_calls($this)->about($this->relPath);
  }

  function getUrl() : string {
    return $this->url;
  }

}
