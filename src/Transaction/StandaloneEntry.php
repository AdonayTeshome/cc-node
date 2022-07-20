<?php
namespace CCNode\Transaction;

use CCNode\Db;
use CreditCommons\Exceptions\DoesNotExistViolation;
use CreditCommons\Exceptions\PermissionViolation;

/**
 * Transaction entry in a flat format.
 */
class StandaloneEntry extends \CreditCommons\StandaloneEntry {

  /**
   * @param string $uuid
   * @return static[]
   */
  static function loadByUuid(string $uuid) : array {
    global $cc_user;
    $ids = [];
    $query = "SELECT e.id FROM transactions t "
      . "INNER JOIN versions v ON t.uuid = v.uuid AND t.version = v.ver "
      . "LEFT JOIN entries e ON t.id = e.txid "
      . "WHERE t.uuid = '$uuid'";
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
      throw new PermissionViolation();
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
    $query = "SELECT * FROM transactions t "
      . "INNER JOIN versions v ON t.uuid = v.uuid AND t.version = v.ver "
      . "LEFT JOIN entries e ON t.id = e.txid "
      . "WHERE e.id IN (".implode(',', $entry_ids).")";
    $entries = [];
    foreach (Db::query($query)->fetch_all(MYSQLI_ASSOC) as $row) {
      $data = (object)$row;
      // @todo Get the full paths from the metadata
      $data->metadata = unserialize($data->metadata);
      $entries[$data->id] = new static(
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
    foreach($entry_ids as $id) {
      $sorted[$id] = $entries[$id];
    }
    return $sorted;
  }

}
