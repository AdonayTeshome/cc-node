<?php
namespace CCNode;

use CCNode\Db;

/**
 * Transaction entry in a flat format.
 */
class FlatEntry extends \CreditCommons\FlatEntry {


  /**
   * @param string $uuid
   * @return static[]
   */
  static function loadByUuid(string $uuid) : array {
    $query = "SELECT e.id FROM transactions t "
      . "INNER JOIN versions v ON t.uuid = v.uuid AND t.version = v.ver "
      . "LEFT JOIN entries e ON t.id = e.txid "
      . "WHERE t.uuid = '$uuid'";
    $result = Db::query($query);
    $entries = [];
    while ($row = $result->fetch_object()){
      $ids[] = $row->id;
    }
    return static::load($ids);
  }

  /**
   * Load a flat entry from the database.
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
      $data->payee = accountStore()->fetch($data->payee)->getRelPath();
      $data->payer = accountStore()->fetch($data->payer)->getRelPath();
      // @todo Get the full paths from the metadata
      $data->metadata = unserialize($data->metadata);
      $data->version = (int)$data->version;
      $entries[] = new static($data->payer, $data->payee, $data->description, $data->quant, $data->type, $data->uuid, $data->author, $data->state, $data->version);
    }
    return $entries;
  }

}
