<?php

namespace CCNode\Transaction;

use CCNode\Accounts\Trunkward;
use CCNode\Transaction\Entry;
use CCNode\Accounts\Remote;
use CreditCommons\Exceptions\DoesNotExistViolation;
use CreditCommons\Exceptions\CCFailure;
use CreditCommons\Exceptions\InvalidFieldsViolation;
use CCNode\Db;
use function CCNode\accountStore;

/**
 * Transaction storage functions
 */

trait StorageTrait {

  static $timeFormat = 'Y-m-d H:i:s';

  /**
   * @param type $uuid
   * @return \Transaction
   */
  static function loadByUuid($uuid) : Transaction {
    global $cc_user, $cc_config;
    // Select the latest version
    $q = "SELECT id as txID, uuid, written, scribe, version, type, state "
      . "FROM transactions "
      . "WHERE uuid = '$uuid' "
      . "ORDER BY version DESC "
      . "LIMIT 0, 1";
    $tx = Db::query($q)->fetch_object();
    if (!$tx) {
      throw new DoesNotExistViolation(type: 'transaction', id: $uuid);
    }
    $multiplier = pow(10, $cc_config->decimalPlaces);
    $q = "SELECT payee, payer, description, quant/$multiplier as quant, trunkward_quant/$multiplier as trunkwardQuant, author, metadata, is_primary FROM entries "
      . "WHERE txid = $tx->txID "
      . "ORDER BY id ASC";
    $result = Db::query($q);
    if ($result->num_rows < 1) {
      throw new CCFailure("Database entries table has no rows for $uuid");
    }
    while ($row = $result->fetch_object()) {
      if ($tx->state == 'validated' and $row->author <> $cc_user->id and !$cc_user->admin and $row->is_primary) {
        // Deny the transaction exists to all but its author and admins
        throw new DoesNotExistViolation(type: 'transaction', id: $uuid);
      }
      $row->metadata = unserialize($row->metadata);
      // replace the full payee and payer path, ready to upcast the row.
      foreach (['payee', 'payer'] as $role) {
        if (isset($row->metadata->{$row->{$role}})) {
          $row->$role = $row->metadata->{$row->{$role}};
        }
      }
      $tx->entries[] = $row;
    }
    $t_class = static::upcastEntries($tx->entries);

    // All these values should be validated, so no need to use static::create
    $transaction = $t_class::create($tx);
    return $transaction;
  }

  /**
   * Write the transaction entries to the database.
   *
   * @return int
   *   The id of the new transaction version.
   *
   * @note No database errors are anticipated.
   */
  public function saveNewVersion() : int {
    global $cc_user;
    if ($this->state == 'validated') {
      // No user would ever need more than one transaction in validated state.
      $this->deleteValidatedByUser($cc_user->id);
    }

    $now = date(self::$timeFormat);
    $this->written = $now;
    $this->version++;

    $query = "INSERT INTO transactions (uuid, version, type, state, scribe, written) "
    . "VALUES ('$this->uuid', $this->version, '$this->type', '$this->state', '$cc_user->id', '$this->written')";
    $success = Db::query($query);

    $new_id = Db::query("SELECT LAST_INSERT_ID() as id")->fetch_object()->id;
    $this->writeEntries($new_id);
    $this->responseMode = TRUE; // Feels awkward but is still the best place for this.
    return $new_id;
  }

  function deleteValidatedByUser(string $acc_id) {
    $result = Db::query("SELECT id FROM transactions where state = 'validated' and scribe = '$acc_id' AND uuid <> '$this->uuid'")->fetch_object();
    if ($result) {
      Db::query("DELETE FROM transactions WHERE id = $result->id");
      Db::query("DELETE FROM entries WHERE txid = $result->id");
    }
  }

  /**
   * suitable for calling by cron.
   */
  static function cleanValidated() {
    global $cc_config;
    $cutoff_moment = date(self::$timeFormat, time() - $cc_config->validatedWindow);

    $result = Db::query("SELECT id FROM transactions where state = 'validated' and written < '$cutoff_moment'");
    foreach ($result->fetch_all() as $row) {
      $ids[] = $row[0];
    }
    $in = '('.implode(',', $ids).')';
    Db::query("DELETE FROM transactions WHERE id IN $in");
    Db::query("DELETE FROM entries WHERE txid = $in");
  }

  /**
   *
   * @param int $id
   */
  protected function writeHashes(int $id) {
    $payee_hash = $payer_hash = '';
    $payee = $this->entries[0]->payee;
    $payer = $this->entries[0]->payer;
    if ($payee instanceOf Remote) {
      $payee_hash = $this->getHash($payee);
    }
    if ($payer instanceOf Remote) {
      $payer_hash = $this->getHash($payer);
    }
    $query = "UPDATE transactions SET payee_hash = '$payee_hash', payer_hash = '$payer_hash' WHERE id = $id";
    Db::query($query);
  }

  /**
   *
   * @param int $new_txid
   * @return void
   */
  protected function writeEntries(int $new_txid) : void {
    if ($this->txID) {// this transaction has already been written in an earlier state
      $q = "UPDATE entries set txid = $new_txid WHERE txid = $this->txID";
      Db::query($q);
    }
    else {// this is the first time the transaction is written properly
      reset($this->entries)->primary = TRUE;
      foreach ($this->entries as $entry) {
        $this->insertEntry($new_txid, $entry);
      }
    }
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
    global $cc_config;
    // Calculate metadata for local storage
    foreach (['payee', 'payer'] as $role) {
      $$role = $entry->{$role}->id;
      if ($entry->{$role} instanceof Remote) {
        $entry->metadata->{$role} = $entry->{$role}->relPath;
      }
    }
    $metadata = serialize($entry->metadata);
    $desc = Db::connect()->real_escape_string($entry->description);
    $primary = $entry->primary??0;
    $trunkward_quant = 0;
    $multiplier = pow(10, $cc_config->decimalPlaces);
    if ($entry->payer instanceOf Trunkward or $entry->payee instanceof Trunkward) {
      $trunkward_quant = $entry->trunkwardQuant;
    }
    $q = "INSERT INTO entries (txid, payee, payer, quant, trunkward_quant, description, author, metadata, is_primary) "
      . "VALUES ($txid, '$payee', '$payer', $entry->quant * $multiplier, $trunkward_quant * $multiplier, '$desc', '$entry->author', '$metadata', '$primary')";
    return $this->id = Db::query($q);
  }

  /**
   * {@inheritdoc}
   */
  function delete() {
    Db::query("DELETE FROM transactions WHERE uuid = '$this->uuid'");
    Db::query("DELETE FROM entries WHERE txid = '$this->txID'");
  }

  /**
   * @return string[]
   *   A list of transaction uuids
   */
  static function filter(array $params) : array {
    $results = [];
    $sort = $params['sort'];
    $dir = $params['dir'];
    $limit = $params['limit'];
    $offset = $params['offset'];
    unset($params['sort'], $params['dir'], $params['limit'], $params['offset']);
    // Get only the latest version of each row in the transactions table.
    $query = "SELECT t.uuid FROM transactions t "
      . "INNER JOIN versions v ON t.uuid = v.uuid AND t.version = v.ver "
      . "RIGHT JOIN entries e ON t.id = e.txid "
      . static::filterConditions(...$params)
      . " GROUP BY t.uuid ";
    $result = Db::query($query);
    $count = mysqli_num_rows($result);
    if ($count) {
      $query .= " ORDER BY $sort ". strtoUpper($dir).", "
      . "  MAX(e.id) ".strtoUpper($dir)
      . " LIMIT $offset, $limit";
      $query_result = Db::query($query);
      while ($row = $query_result->fetch_object()) {
        $results[] = $row->uuid;
      }
    }
    else {
      $count = 0;
    }
    return [$results, $count];
  }

  static function filterEntries(array $params) : array {
    $results = [];
    $sort = $params['sort'];
    $dir = $params['dir'];
    $limit = $params['limit'];
    $offset = $params['offset'];
    unset($params['sort'], $params['dir'], $params['limit'], $params['offset']);
    // Get only the latest version of each row in the transactions table.
    $query = "SELECT e.id, t.uuid FROM transactions t "
      . " INNER JOIN versions v ON t.uuid = v.uuid AND t.version = v.ver "
      . " LEFT JOIN entries e ON t.id = e.txid "
      . static::filterConditions(...$params);

    $count = Db::query(str_replace('e.id, t.uuid', 'COUNT(t.uuid) as count', $query))->fetch_object()->count;
    if ($count) {
      $query .= " ORDER BY $sort ". strtoUpper($dir).", "
        . "  e.id ".strtoUpper($dir).", "
        . "  is_primary DESC "
        . " LIMIT $offset, $limit";
      $query_result = Db::query($query);
      while ($row = $query_result->fetch_object()) {
        $results[$row->id] = $row->uuid;
      }
    }
    return [$results, (int)$count];
  }

  /**
   * @param array $params
   *   Valid fields: payer, payee, involving, type, types, state, states, since, until, author, description
   *   Other params: sort (field name), dir (asc or desc), limit, offset
   *
   * @return string[]
   *   subclauses for the WHERE, to be joined by AND
   *
   * @note It is not possible to filter by signatures needed or signed because
   * they don't exist, as such, in the db.
   * @note you can't filter on metadata here.
   * @todo add a filter on quant
   */
  private static function filterConditions(
    string $payer = NULL,
    string $payee = NULL,
    string $involving = NULL,
    string $scribe = NULL,
    string $description = NULL,
    string $states = NULL,//comma separated
    string $state = NULL,//todo should we pass an array here?
    string $types = NULL,//comma separated
    string $type = NULL,//todo should we pass an array here?
    string $since = NULL,
    string $until = NULL) : string
  {
    global $cc_user;
    // Validation
    foreach (['since', 'until'] as $time) {
      if (isset($$time)) {
        if (preg_match(static::REGEX_DATE, $$time)) {
          continue;
        }
        elseif (preg_match(static::REGEX_DATE, $$time)) {
          $$time .= ' 00:00:00';
          continue;
        }
        throw new InvalidFieldsViolation(type: 'transactionFilter', fields: [$time]);
      }
    }
    if (isset($state) and !isset($states)) {
      $states = [$state];
    }
    if (isset($type) and !isset($types)) {
      $types = [$type];
    }
    $conditions = [];
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
    if (isset($scribe)) {
      $conditions[]  = "scribe = '$scribe'";
    }
    if (isset($involving)) {
      if ($col = strpos($involving, '/')) {
        $conditions[] = "( metadata LIKE '%$payer%'";
      }
      elseif (strpos($involving, ',')) {
        $items = explode(',', $involving);
        $as_string = implode("','", explode(',', $involving));
        // At the moment metadata only stores the real address of remote parties.
        $conditions[]  = "(payee IN ('$as_string') OR payer IN ('$as_string'))";
      }
      else {
        $conditions[]  = "(payee = '$involving' OR payer = '$involving')";
      }
    }
    if (isset($description)) {
      $conditions[]  = "e.description LIKE '%$description%'";
    }
    // NB this uses the date the transaction was last written, not created.
    // to use the created date would require joining the current query to transactions where version =1
    if (isset($until)) {
      $date = date(self::$timeFormat, strtotime($until));
      $conditions[]  = "written < '$date'";
    }
    if (isset($since)) {
      $date = date(self::$timeFormat, strtotime($since));
      $conditions[]  = "written > '$date'";
    }
    if (isset($states)) {
      if (is_string($states)) {
        $states = explode(',', $states);
      }
      if (in_array('validated', $states)) {
        // only the author can see transactions in the validated state.
        $conditions[]  = "(state = 'validated' AND author = '$cc_user->id')";
      }
      else {
        // transactions with version 0 are validated but not confirmed by their creator.
        // They don't really exist and could be deleted, and can only be seen by their creator.
        $conditions[] = 't.version > 0';
      }
      $conditions[] = self::manyCondition('state', $states);
    }
    else {
      $conditions[] = "state <> 'erased'";
      $conditions[] = "(state <> 'validated' OR (state = 'validated' AND author = '$cc_user->id'))";
    }
    $conditions[] ="(t.version > 0 OR e.author = '$cc_user->id')";
    if (isset($types)) {
      $conditions[] = self::manyCondition('type', $types);
    }
    if (isset($uuid)) {
      $conditions[]  = "t.uuid = '$uuid'";
    }
    $query = '';
    if ($conditions) {
     $query .= ' WHERE '.implode(' AND ', $conditions);
    }
    return $query;
  }

  /**
   * Database query builder helper
   * @param string $fieldname
   * @param array $vals
   * @return string
   */
  private static function manyCondition  (string $fieldname, array $vals) : string {
    if ($vals) {
      foreach ($vals as $s) {$strings[] = "'".$s."'";}
      return $fieldname .' IN ('.implode(',', $strings).') ';
    }
    return '';
  }


  /*
   * @todo decide whether to use transaction creation or written dates.
   * Written is easier with the current architecture.
   */
  static function accountHistory($acc_id) : \mysqli_result {
    global $cc_config;
    $multiplier = pow(10, $cc_config->decimalPlaces);
    Db::query("SET @csum := 0");
    $query = "SELECT written, (@csum := @csum + diff / $multiplier) as balance "
      . "FROM transaction_index "
      . "WHERE uid1 = '$acc_id' "
      . "ORDER BY written ASC";
    return Db::query($query);
  }

  static function accountSummary($acc_id) : \mysqli_result {
    global $cc_config;
    $multiplier = pow(10, $cc_config->decimalPlaces);
    $query = "SELECT uid2 as partner, income / $multiplier as income, expenditure / $multiplier as expenditure, diff / $multiplier as diff, volume / $multiplier as volume, state, is_primary as isPrimary "
      . "FROM transaction_index "
      . "WHERE uid1 = '$acc_id' and state in ('completed', 'pending')";
    return Db::query($query);
  }

  /**
   *
   * @param bool $include_virgin_wallets
   * @return array
   */
  static function getAccountSummaries($include_virgin_wallets = FALSE) : array {
    global $cc_config;
    $multiplier = pow(10, $cc_config->decimalPlaces);
    $results = $balances = [];
        $balances = [];
    $result = Db::query("SELECT uid1, uid2, diff / $multiplier as diff, state, is_primary "
      . "FROM transaction_index "
      . "WHERE income > 0");
    while ($row = $result->fetch_object()) {
      foreach ([$row->uid1, $row->uid2] as $uid) {
        if (!isset($balances[$uid])) {
          $balances[$uid] = (object)[
            'completed' => \CCNode\TradeStats::builder(),
            'pending' => \CCNode\TradeStats::builder()
          ];
        }
      }
      $balances[$row->uid1]->pending->logTrade($row->diff, $row->uid2, $row->is_primary);
      $balances[$row->uid2]->pending->logTrade(-$row->diff, $row->uid1, $row->is_primary);
      if ($row->state == 'completed') {
        $balances[$row->uid1]->completed->logTrade($row->diff, $row->uid2, $row->is_primary);
        $balances[$row->uid2]->completed->logTrade(-$row->diff, $row->uid1, $row->is_primary);
      }
    }
    if ($include_virgin_wallets) {
      // Excludes trunkward account.
      $all_account_names = accountStore()->filter(full: false);
      $missing = array_diff($all_account_names, array_keys($balances));
      foreach ($missing as $name) {
        $balances[$name] = (object)[
          'completed' => \CCNode\TradeStats::builder(),
          'pending' => \CCNode\TradeStats::builder()
        ];
      }
    }
    return $balances;
  }

}
