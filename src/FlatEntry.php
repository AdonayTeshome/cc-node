<?php
namespace CCNode;

use CCNode\Db;

/**
 * Transaction entry in a flat format.
 */
class FlatEntry extends \CreditCommons\FlatEntry {

  /**
   * Load a flat entry from the database.
   *
   * @param int $entry_id
   * @return \static
   */
  static function load(int $entry_id) {
    $query = "SELECT * FROM transactions t "
      . "INNER JOIN versions v ON t.uuid = v.uuid AND t.version = v.ver "
      . "LEFT JOIN entries e ON t.id = e.txid "
      . "WHERE e.id = $entry_id";
    $data = Db::query($query)->fetch_object();

    $data->payee = accountStore()->fetch($data->payee);
    $data->payer = accountStore()->fetch($data->payer);
    $data->metadata = unserialize($data->metadata);
    $data->version = (int)$data->version;
    return new static($data);
  }

  static function loadByUuid(string $uuid) {
    $query = "SELECT e.id FROM transactions t "
      . "INNER JOIN versions v ON t.uuid = v.uuid AND t.version = v.ver "
      . "LEFT JOIN entries e ON t.id = e.txid "
      . "WHERE t.uuid = '$uuid'";
    $result = Db::query($query);
    $entries = [];
    while ($row = $result->fetch_object()){
      $entries[] = static::load($row->id);
    }
    return $entries;
  }

}
