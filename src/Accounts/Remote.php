<?php

namespace CCNode\Accounts;

use CCNode\Accounts\User;
use CCNode\Transaction\Transaction;
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
    return substr($this->relPath, -1) == '/';
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
    $rows = $this->API()->buildValidateRelayTransaction($transaction);
    $this->convertIncomingEntries($rows);
    return $rows;
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
  public function retrieveTransaction(string $uuid, $full = TRUE) : \CreditCommons\BaseTransaction|array {
    $result = $this->API()->getTransaction($uuid, $this->relPath, $full);
    if (is_array($result)) {
      $this->convertIncomingEntries($result);
      // DO we need responsemode on each of these?
    }
    else {
      $result = TransversalTransaction::createFromDownstream($result);
      $this->convertIncomingEntries($result->entries);
      $result->responseMode = TRUE;
    }
    return $result;
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
   * Convert the quantities if entries are coming from the trunk
   * @param array $entries
   *   array of stdClass or Entries.
   */
  public function convertIncomingEntries(array &$entries) : void {
    // @todo only call this when we know it is incoming from Trunk.
  }


  /**
   * {@inheritdoc}
   */
  private function API() : NodeRequester {
    global $cc_config;
    return new NodeRequester($this->url, $cc_config->nodeName, $this->getLastHash());
  }


  /**
   * Get the address for passing trunkwards or branchwards.
   *
   * @return string
   *
   * @todo Would be great to find a way to put this in cc-php-lib
   */
  function foreignId() : string {
    global $cc_config;
    $parts = [];
    if ($cc_config->trunkwardAcc) {
      $parts[] = $cc_config->nodeName;
    }
    $parts[] = $this->id;
    if ($r = $this->relPath) {
      // Add the leafward part of the path
      $parts[] = $r;
    }
    return implode('/', $parts);
  }

}

