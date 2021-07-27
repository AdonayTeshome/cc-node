<?php
namespace CCNode;

use CreditCommons\Exceptions\DoesNotExistViolation;
use CreditCommons\Exceptions\MaxLimitViolation;
use CreditCommons\Exceptions\MinLimitViolation;
use CreditCommons\Exceptions\MiscFailure;
use CreditCommons\TransactionInterface;
use CreditCommons\NewTransaction;
use CreditCommons\Misc;
use CreditCommons\Workflow;
use CCNode\Entry;
use CCNode\Accounts\Account;
use CCNode\BlogicRequester;

/**
 * Transaction class on a credit commons node.
 *
 */
class Transaction extends \CreditCommons\Transaction {

  /**
   * The workflow object for this transaction, determined by the $type.
   * @var Workflow
   */
  public $workflow;

  /**
   * @param string $uuid
   * @param int $version
   * @param string $type
   * @param string $state
   * @param Entry[] $entries
   */
  public function __construct(string $uuid, int $version, string $type, string $state, array $entries, $txID = NULL) {
    parent::__construct($uuid, $version, $type, $state, $entries, $txID);
    $this->workflow = $this->loadWorkflow($type);
  }


  /**
   * @param string $hash
   * @param Transaction $transaction
   * @return \static\
   *
   * @todo At some point we should check that the transaction scope is not larger than the workflow's scope.
   */
  function loadWorkflow(string $id) {
    foreach (getAllWorkflows() as $wfs) {
      foreach ($wfs as $wfhash => $wf) {
        if ($wf->id == $id) {
          return new Workflow($wf, $this);
        }
      }
    }
    throw new DoesNotExistViolation(['type' => 'workflow', 'id' => $workflow_id]);
  }


  static function createEntries(array $rows) : array {
    foreach ($rows as $row) {
      if (empty($row->author)) {
        throw new MiscFailure(['message' => 'Entry is missing author.']);
      }
      $entries[] = Entry::create($row);
    }
    return $entries;
  }


  /**
   * Create a new transaction from a few required fields defined upstream.
   * @param stdClass $input
   *   validated to contain payer, payee, description & quantity
   * @return \static
   */
  public static function createFromUpstreamNode(\stdClass $input) : \CreditCommons\Transaction {
    global $config, $user;
    // basic validation of the input
    if (!isset($input->quant) or (!$config['zero_payments'] and empty($input->quant))) {
      $missing['quant'] = '_REQUIRED_';
    }
    foreach (['payer', 'payee', 'description'] as $field_name) {
      // @todo there is a setting that allows 'quant' to be empty;
      if (!isset($input->{$field_name}) or empty($input->{$field_name})) {
        $missing[$field_name] = '_REQUIRED_';
      }
    }
    if ($missing) {
      throw new MissingRequiredFieldViolation(['fields' => $missing]);
    }
    $input->author = $user->id;
    $entries = static::createEntries([$input]); // why is this false?
    $class = static::determineClass($entries);

    return new $class(
      static::makeUuid(),
      0,
      $input->type,
      TransactionInterface::STATE_INITIATED,
      $entries
    );
  }

  /**
   * This is only needed on a leaf node (one with local users)
   * It mustn't call Node\Account
   *
   * @param NewTransaction $nt
   * @return \static
   */
  public static function createFromClient(NewTransaction $nt) : \CreditCommons\Transaction {
    global $user;
    $entry = new Entry(
      Account::resolveAddress($nt->payee, TRUE),
      Account::resolveAddress($nt->payer, TRUE),
      $nt->quantity,
      $nt->description,
      $user->id,
      (object)[]
    );
    $class = static::determineClass([$entry]);
    $transaction = new $class(
      static::makeUuid(),
      0,
      $nt->type,
      TransactionInterface::STATE_INITIATED,
      [$entry]
    );

    return $transaction;
  }


  /**
   * @param array $entries
   * @return boolean
   *   TRUE if these entries imply a TransversalTransaction
   */
  protected static function determineClass(array $entries) : string {
    foreach ($entries as $entry) {
      if ($entry instanceOf TransversalEntry) {
        return 'CCNode\TransversalTransaction';
      }
    }
    return 'CCNode\Transaction';
  }



  /**
   * @param type $uuid
   * @return \Transaction
   */
  static function loadByUuid($uuid) : Transaction {
    global $orientation;
    $q = "SELECT id, version, type, state FROM transactions "
      . "WHERE uuid = '$uuid' "
      . "ORDER BY version DESC "
      . "LIMIT 0, 1";
    $tx = Db::query($q)->fetch_object();
    if ($tx) {
      $q = "SELECT payee, payer, description, quant, author, metadata FROM entries "
        . "WHERE txid = $tx->id "
        . "ORDER BY id ASC";
      $result = Db::query($q);
      while ($row = $result->fetch_object()) {
        $row->metadata = unserialize($row->metadata);
        $entry_rows[] = $row;
      }
      $entries = static::createEntries($entry_rows);
      $class = static::determineClass($entries);
      $transaction = new $class(
        $uuid,
        $tx->version,
        $tx->type,
        $tx->state,
        $entries,
        $tx->id
      );
    }
    else {
      $transaction = static::getTemp($uuid);
      $orientation->addAccount($transaction->entries[0]->payee);
      $orientation->addAccount($transaction->entries[0]->payer);
    }
    return $transaction;
  }

  /**
   * Write the serialized transaction to the temp table.
   * @return bool
   *   TRUE on success
   */
  function writeValidatedToTemp() {
    //version is 0 until is it written in the transactions table.
    $data = Db::connect()->real_escape_string(serialize($this));
    $q = "INSERT INTO temp (uuid, serialized) VALUES ('$this->uuid', '$data')";
    $result = Db::query($q);
    Misc::message('Written validated transaction '.$this->uuid. ' to temp table');
    return (bool)$result;
  }


  /**
   * Call the business logic and append entries.
   */
  function buildValidate() : void {
    global $loadedAccounts, $user;
    $this->workflow->canTransitionToState($user->id);
    // Add fees, etc by calling on the blogic service
    if ($config['blogic_service_url']) {
      $fees = (new BlogicRequester($config['blogic_service_url']))->appendTo($this);
      // @todo. Validate these since they came from another microservice
      foreach ($fees as $row) {
        $this->entries[] = Entry::create($row)->additional();
      }
    }
    foreach ($this->sum() as $acc_id => $info) {

      $ledgerAccountInfo = (new Wallet(Account::load($acc_id)))->getTradeStats();
      $projected = $ledgerAccountInfo['pending']['balance'] + $info->diff;
      if ($projected > $this->payee->max) {
        throw new MaxLimitViolation(['acc_id' => $acc_id, 'limit' => $this->payee->max, 'projected' => $projected]);
      }
      elseif ($projected < $this->payer->min) {
        throw new MinLimitViolation(['acc_id' => $acc_id, 'limit' => $this->payer->min, 'projected' => $projected]);
      }
    }
    $this->state = TransactionInterface::STATE_VALIDATED;
  }

  /**
   * @param string $target_state
   * @throws \Exception
   */
  function changeState(string $target_state) {
    Misc::message("Changing the state of the transaction to $target_state");
    $this->sign($target_state);
  }

  function sign(string $target_state) {
    global $user;
    $this->workflow->canTransitionToState($user->id, $target_state);
    $this->state = $target_state;
    $this->version++;
    $this->saveTransactionNewVersion();
    return $this;
  }

  /**
   * Write the transaction entries to the database.
   *
   * @note No database errors are anticipated.
   */
  public function saveTransactionNewVersion() {
    global $user;
    // The datestamp is added automatically
    $q = "INSERT INTO transactions (uuid, version, type, state, scribe) "
    . "VALUES ('$this->uuid', $this->version, '".$this->workflow->id."', '$this->state', '$user->id')";
    $new_id = Db::query($q);
    $this->writeEntries($new_id);
  }

  protected function writeEntries($new_txid) {
    if ($this->txID) {// this transaction has already been written in an earlier state
      $q = "UPDATE entries set txid = $new_txid WHERE txid = $this->txID";
      Db::query($q);
    }
    else {// this is the first time the transaction is written properly
      foreach ($this->entries as $entry) {
        $this->insertEntry($new_txid, $entry);
      }
      Db::query("DELETE FROM temp WHERE uuid = '$this->uuid'");
    }
    Misc::message('Written transaction');
  }

  /**
   * Save an entry to the entries table.
   * @param int $txid
   * @param Entry $entry
   * @return int
   *   the new entry id
   * @note No database errors are anticipated.
   */
  private function insertEntry(int $txid, Entry $entry) : int {
    static $primary = 1;
    foreach (['payee', 'payer'] as $role) {
      $$role = $entry->{$role}->id;
      if ($entry->{$role} instanceof RemoteAccount) {
        $entry->metadata->{$$role} = $entry->{$role}->givenPath;
      }
    }
    $metadata = serialize($entry->metadata);
    $desc = Db::connect()->real_escape_string($entry->description);
    $q = "INSERT INTO entries (txid, payee, payer, quant, description, author, metadata, is_primary) "
      . "VALUES ($txid, '$payee', '$payer', '$entry->quant', '$desc', '$entry->author', '$metadata', '$primary')";
    $primary = 0;
    if ($this->id = Db::query($q)) {
      return (bool)$this->id;
    }
  }

  /**
   * Retrieve a transaction from serialized, in the db.
   * @param string $uuid
   * @return \Transaction
   */
  protected static function getTemp($uuid) : Transaction {
    // This seems to work but I suspect there is a cleaner way with PSR4
    require_once __DIR__.'/Entry.php';
    $result = Db::query("SELECT serialized FROM temp WHERE uuid = '$uuid'");
    if ($stored = $result->fetch_object() and $string = $stored->serialized) {
      return unserialize($string);
    }
    throw new DoesNotExistViolation(['type' => 'transaction', 'id' => $uuid]);
  }

  /**
   * Magic method. Look for any unknown properties to the first entry.
   * @param string $name
   * @return type
   */
  function __get($name) {
    $valid = ['payee', 'payer', 'description'];
    if (isset($this->entries[0]->$name)) {
      return $this->entries[0]->$name;
    }

    echo $name;kjhkjh();
    throw new MiscFailure(['message' => 'Requested unknown property of Transaction:'.$name]);
  }

  /**
   * Add up all the transactions and return the differences in balances for
   * every involved user.
   *
   * @param Transaction $transaction
   * @return array
   *   The differences, keyed by account name
   */
  public function sum() : array {
    $accounts = [];
    foreach ($this->entries as $entry) {
      $accounts[$entry->payee->id] = $entry->payee;
      $accounts[$entry->payer->id] = $entry->payer;
      $sums[$entry->payer->id][] = -$entry->quant;
      $sums[$entry->payee->id][] = $entry->quant;
    }
    foreach ($sums as $localName => $diffs) {
      $accounts[$localName]->diff = array_sum($diffs);
    }
    return $accounts;
  }


  /**
   * @param array $params
   *   valid keys: state, payer, payee, involving, type, before, after, description
   * @return uuid[]
   * @note Because neither signatures as such nor the need for them is stored in
   * the db, this method can't filter by them.
   */
  static function filter(array $params) : array {
    extract($params);
    $query = "SELECT t.uuid FROM transactions t "
      . "INNER JOIN versions v ON t.uuid = v.uuid AND t.version = v.ver "
      . "LEFT JOIN entries e ON t.id = e.txid";
    if (isset($is_primary) and $is_primary) {
      // This prevents you from filtering for non-primary transactions only.
      $conditions[]  = 'is_primary = 1';
    }
    if (isset($payer)) {
      if ($col = strpos($payer, '/')) {
        $conditions[] = "metadata LIKE '%$payer%'";
      }
      else {
        // At the moment metadata only stores the real address of remote parties.
        $conditions[]  = "payer = '$payer'";
      }
    }
    if (isset($payee)) {
      if ($col = strpos($payee, '/')) {
        $conditions[] = "metadata LIKE '%$payee%'";
      }
      else {
        // At the moment metadata only stores the real address of remote parties.
        $conditions[]  = "payee = '$payee'";
      }
    }
    if (isset($author)) {
      $conditions[]  = "author = '$author'";
    }
    if (isset($involving)) {
      if ($col = strpos($involving, '/')) {
        $conditions[] = "( metadata LIKE '%$payer%'";
      }
      else {
        // At the moment metadata only stores the real address of remote parties.
        $conditions[]  = "(payee = '$involving' OR payer = '$involving')";
      }
    }
    if (isset($description)) {
      $conditions[]  = "description LIKE '%$description%'";
    }
    if (isset($before)) {
      $date = date("Y-m-d H:i:s", strtotime($before));
      $conditions[]  = "written < '$date'";
    }
    if (isset($after)) {
      $date = date("Y-m-d H:i:s", strtotime($after));
      $conditions[]  = "written > '$date'";
    }
    if (isset($state)) {
      $conditions[]  = "state = '$state'";
    }
    if (isset($type)) {
      $conditions[]  = "type = '$type'";
    }
    if (isset($uuid)) {
      $conditions[]  = "t.uuid = '$uuid'";
    }
    if (isset($conditions)) {
      $query .= ' WHERE '.implode(' AND ', $conditions);
    }
    $result = Db::query($query);
    $uuids = [];
    while ($row = $result->fetch_object()) {
      $uuids[] = $row->uuid;
    }
    return $uuids;
  }

}
