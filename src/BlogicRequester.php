<?php

namespace CCNode;
use CCNode\Transaction\Transaction;
use CCNode\Transaction\Entry;
use CreditCommons\BlogicInterface;
use CreditCommons\Requester;

/**
 * Calls to the business logic service.
 */
class BlogicRequester extends Requester implements BlogicInterface {

  function __construct() {
    global $cc_config;
    parent::__construct($config->blogicMod);
  }

  /**
   * Add a new rule.
   *
   * @param Transaction $transaction
   * @return \stdClass[]
   *   Simplified entries with names only for payee, payer, author.
   */
  public function addRows(string $type, string $payee, string $payer, int $quant, \stdClass $metadata = NULL, string $description = '') : array {
    $query = [
      'payee' => $payee->id,
      'payer' => $payer->id,
      'type' => $type,
      'quant' => $quant,
      'description' => $description,
      'metadata' => $metadata
    ];
    $rows = $this
      ->setBody($main_entry)
      ->setMethod('post')
      ->addField
      ->request(200, '?'. http_build_query($query)); // use func_get_args?
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
