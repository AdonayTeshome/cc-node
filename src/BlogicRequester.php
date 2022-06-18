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
  public function addRows(string $type, \stdClass $entry) : array {
    $rows = $this
      ->setBody($entry)
      ->setMethod('post')
      ->request(200, $type);
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
