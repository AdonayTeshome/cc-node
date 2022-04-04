<?php

namespace CCNode\Transaction;
use CCNode\Transaction\EntryTransversal;
use function \CCNode\getConfig;

/**
 * Entry which is shared with the trunkwards ledger.
 * Converts quantities to/from the trunkwards ledger.
 */
class EntryTrunkwards extends EntryTransversal {

  private float $conversionRate;

  // This is called immediately after the entry is created.
  // Saves overriding the create/__construct funtions again.
  public function setTransaction(TransversalTransaction $transaction) : void {
    parent::setTransaction($transaction);
    $this->conversionRate = getConfig('conversion_rate');
    if ($transaction->isFromTrunkwards() and $this->conversionRate <> 1) {
      $this->quant /= $this->conversionRate;
    }
  }

  public function jsonSerialize() : array {
    // Calculate metadata for relaying trunkwards
    $array = [
      'payee' => $this->payee->foreignId(),
      'payer' => $this->payer->foreignId(),
      'quant' => $this->quant,
      'description' => $this->description,
      'metadata' => (object)[],
      'isPrimary' => $this->isPrimary// this isn't properly in the API at the moment.
    ];

    if ($this->transaction->isGoingTrunkwards()) {
      \CCNode\debug("EntryTrunkwards isGoingTrunkwards");
      if ($this->includeMetaData()) {
        \CCNode\debug('including metadata');
        foreach (['payee', 'payer'] as $role) {
          $name = $this->{$role}->id;
        }
      }
      //the opposite operation is in
      if ($this->conversionRate) {
        $array['quant'] *= $this->conversionRate;
      }
    }
//    \CCNode\debug("Serialized trunkwards Entry");
//    \CCNode\debug($array);
    return $array;
  }

}
