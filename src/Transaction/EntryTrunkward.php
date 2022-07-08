<?php

namespace CCNode\Transaction;
use CCNode\Transaction\EntryTransversal;
use CreditCommons\BaseEntry;

/**
 * Entry which is shared with the trunkward ledger and has a special 'quant'
 * value converted for that ledger.
 */
class EntryTrunkward extends EntryTransversal {

  public int $trunkward_quant;

  static function create(\stdClass $data, $transaction) : BaseEntry {
    global $config;
    $e = parent::create($data, $transaction);
    if (!isset($e->trunkward_quant)) {
      $e->trunkward_quant = $data->trunkward_quant ?? ceil($e->quant * $config->conversionRate);
    }
    return $e;
  }

  public function jsonSerialize() : array {
    // Calculate metadata for relaying trunkward
    $array = [
      'payee' => $this->payee->foreignId(),
      'payer' => $this->payer->foreignId(),
      'quant' => $this->quant,
      'description' => $this->description,
      'metadata' => (object)[],
    ];

    if ($this->transaction->trunkwardResponse()) {
      if ($this->includeMetaData()) {
        foreach (['payee', 'payer'] as $role) {
          $name = $this->{$role}->id;
        }
      }
    }
    $array['quant'] = $this->transaction->trunkwardResponse() ? $this->trunkward_quant : $this->quant;
    return $array;
  }

}
