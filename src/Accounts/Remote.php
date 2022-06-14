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
  public string $givenPath = '';
  private float $trunkwardConversionRate = 1;

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
    //parent::__construct($id, $min, $max, FALSE);
  }

  static function create(\stdClass $data) : User {
    static::validateFields($data);
    return new static($data->id, $data->min, $data->max, $data->url);
  }

  /**
   * {@inheritdoc}
   */
  function relPath() : string {
    $parts = explode('/', $this->givenPath);
    // remove everything including the node name.
    $pos = array_search($this->id, $parts);
    if (FALSE !== $pos) {
      $parts = array_slice($parts, $pos+1);
    }

    debug("RelPath of Trunkward is ". implode('/', $parts).' - ' . print_R($this, 1));
    return implode('/', $parts);
  }

  /**
   * {@inheritdoc}
   */
  public function isAccount() : bool {
    return substr($this->givenPath, -1) <> '/';
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
    return $this->api()->accountNameFilter($this->relPath());
  }

  /**
   * {@inheritdoc}
   */
  public function retrieveTransaction(string $uuid, $full = TRUE) : \CreditCommons\BaseTransaction|array {
    $result = $this->API()->getTransaction($uuid, $this->relPath(), $full);
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
      $result = $this->API()->getAccountSummary($this->relPath());
      $result = reset($result);
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  function getAllSummaries() : array {
    // the relPath should have a slash at the end of it.
    return $this->API()->getAccountSummary($this->relPath());
  }

  /**
   * {@inheritdoc}
   */
  function getLimits($force_local = FALSE) : \stdClass {
    if ($this->isAccount()) {
      $result = $this->API()->getAccountLimits($this->relPath());
    }
    else {
      $result = parent::getLimits();
    }
    return $result;
  }

  function getAllLimits() : array {
    // the relPath should have a slash at the end of it.
    return $this->API()->getAccountLimits($this->relPath());
  }

  /**
   * {@inheritdoc}
   */
  function getHistory(int $samples = -1) : array {
    if ($path = $this->relPath()) {
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
    global $config;
    return new NodeRequester($this->url, $config->nodeName, $this->getLastHash());
  }

}

