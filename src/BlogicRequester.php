<?php

namespace CCNode;
use CreditCommons\Requester;
use CCNode\Transaction\Transaction;
use CCNode\Transaction\Entry;

/**
 * Calls to the business logic service.
 */
class BlogicRequester extends Requester {

  /**
   * Add a new rule.
   *
   * @param Transaction $transaction
   * @return \stdClass[]
   *   Simplified entries with names only for payee, payer, author.
   */
  function appendTo(Transaction $transaction) {
    $first_entry = $this->convert($transaction->entries[0]);
    $rows = $this
      ->setBody($first_entry)
      ->setMethod('post')
      ->request(200, $transaction->type);
    foreach ($rows as &$row) {
      $row->payee = load_account($row->payee);
      $row->payer = load_account($row->payer);
    }
    // All Blogic transactions have the same author, a local account.
    $transaction->upcastEntries($rows, load_account($rows[0]->author), TRUE);
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
