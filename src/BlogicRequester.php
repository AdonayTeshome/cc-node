<?php

namespace CCNode;
use CreditCommons\Requester;
use CCNode\Transaction\Transaction;
use CCNode\Transaction\Entry;
use CCNode\CCBlogicInterface;

/**
 * Calls to the business logic service.
 */
class BlogicRequester extends Requester implements CCBlogicInterface {

  function __construct() {
    global $config;
    parent::__construct($config->blogicMod);
  }

  /**
   * Add a new rule.
   *
   * @param Transaction $transaction
   * @return \stdClass[]
   *   Simplified entries with names only for payee, payer, author.
   */
  public function addRows(string $type, \stdClass $main_entry) : array {
    $rows = $this
      ->setBody($main_entry)
      ->setMethod('post')
      ->request(200, $type);

    // The external Blogic class does not know about remote account relative paths,
    // replace them from the original entry to preserve the given path of any remote accounts.
    foreach ($rows as &$row) {
      // Try to reuse the already loaded accounts to upcast the new rows.
      if ($row->payee == $main_entry->payee->id) {
        $row->payee = $main_entry->payee;
      }
      elseif ($row->payee == $main_entry->payer->id) {
        $row->payee = $main_entry->payer;
      }
      else {
        $row->payee = load_account($row->payee);
      }

      if ($row->payer == $main_entry->payee->id) {
        $row->payer = $main_entry->payee;
      }
      elseif ($row->payer == $main_entry->payer->id) {
        $row->payer = $main_entry->payer;
      }
      else {
        $row->payer = load_account($row->payer);
      }
    }
    return $rows;
  }

  /**
   * This is instead of using the Serialize callback which is used for transversal messaging.
   * @param Entry $entry
   * @return \stdClass
   */
  function convert(Entry $entry) : \stdClass {
    // we only send the local account names because the blogic doesn't know anything about specific foreign accounts
    $item = [
      'payee' => $entry->payee->id,
      'payer' => $entry->payer->id,
      'quant' => $entry->quant,
      'author' => $entry->author
    ];
    return (object)$item;
  }

}
