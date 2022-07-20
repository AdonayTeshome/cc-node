<?php

namespace CCNode\Transaction;

use CCNode\Transaction\Entry;

/**
 * Transversal entries have different classes (and hence methods) according to
 * which ledger it is shared with.
 * @todo make a new interface for this.
 */
class EntryTransversal extends Entry {

  /**
   * @var CCNode\Transaction
   */
  protected $transaction;

  public function setTransaction(TransversalTransaction $transaction) : void {
    $this->transaction = $transaction;
  }

  /**
   * Convert the entry for sending to another node.
   */
  public function jsonSerialize() : mixed {
    $flat = [
      'quant' => $this->quant,
      'description' => $this->description,
      'metadata' => (object)[]
    ];

    if ($this->includeMetaData()) {
      // Calculate metadata for relaying branchwards
      foreach (['payee', 'payer'] as $role) {
        $name = $this->{$role}->id;
        $flat[$role] = $this->{$role}->foreignId();
      }
    }
    return $flat;
  }

  /**
   * Include the metadata unless we are responding to a request from trunkward and privacy settings forbid.
   *
   * @return bool
   */
  protected function includeMetaData() : bool {
    global $cc_user, $cc_config;
    if ($cc_user == $this->transaction->trunkwardAccount and $this->transaction->responseMode == TRUE) {
      return $cc_config->privacy['metadata'];
    }
    else return TRUE;
  }

}
