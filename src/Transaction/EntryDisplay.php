<?php
namespace CCNode\Transaction;

use CCNode\Db;
use CreditCommons\Exceptions\DoesNotExistViolation;
use CreditCommons\Exceptions\CCFailure;

/**
 * Transaction entry in a flat format.
 */
class EntryDisplay extends \CreditCommons\EntryDisplay {

  /**
   * @param string $uuid
   * @return static[]
   */
  static function loadByUuid(string $uuid) : array {
    global $cc_user;
    $ids = [];
    $query = "SELECT distinct (e.id) as id, e.is_primary FROM entries e
      INNER JOIN transactions t ON t.id = e.txid
      INNER JOIN (SELECT MAX(version) as version, uuid FROM transactions group by uuid) t1 ON t1.version = t.version
      WHERE t.uuid = '$uuid'
      ORDER BY e.is_primary DESC, e.id ASC;";
    $result = Db::query($query);
    $entries = [];
    while ($row = $result->fetch_object()){
      $ids[] = $row->id;
    }
    if (empty($ids)) {
      throw new DoesNotExistViolation(type: 'transaction', id: $uuid);
    }
    $entries = static::load($ids);
    // We just check the first (primary) entry
    if (reset($entries)->state == 'validated' and reset($entries)->author <> $cc_user->id and !$cc_user->admin) {
      // deny the transaction exists to all but its author and admins
      throw new DoesNotExistViolation(type: 'transaction', id: $uuid);
    }
    return $entries;
  }

  /**
   * Load a flat entry from the database, returning items in the order given.
   *
   * @param array $entry_ids
   * @return \static[]
   */
  static function load(array $entry_ids) : array {
    if (empty($entry_ids)) {
      throw new CCFailure('No entry ids to load');
    }
    $query = "SELECT e.id as eid, e.*, t.* FROM entries e
      JOIN transactions t ON t.id = e.txid
      WHERE e.id IN (".implode(',', $entry_ids).")";
    $entries = [];
    foreach (Db::query($query)->fetch_all(MYSQLI_ASSOC) as $row) {
      $data = (object)$row;
      // @todo Get the full paths from the metadata
      $data->metadata = unserialize($data->metadata);
      $entries[] = new static(
        $data->uuid,
        $data->payer,
        $data->payee,
        $data->quant,
        $data->type,
        $data->author,
        $data->state,
        $data->written,
        $data->description,
        $data->metadata
      );
    }
    array_multisort($entry_ids, $entries);
    return $entries;
  }

}
