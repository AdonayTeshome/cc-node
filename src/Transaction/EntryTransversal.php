<?php

namespace CCNode\Transaction;

use CCNode\Transaction\Transaction;
use CCNode\Transaction\Entry;
use CreditCommons\BaseEntry;

/**
 * Transversal entries have different classes (and hence methods) according to
 * which ledger it is shared with.
 * @todo make a new interface for this and put some elements into cc-php-lib
 */
class EntryTransversal extends Entry {

  /**
   * @var CCNode\Transaction
   */
  public $transaction;

  static function create(\stdClass $data) : BaseEntry {
    global $cc_config;
    $entry = parent::create($data);
    if (isset($data->trunkwardQuant)) {
      // If this is coming from trunkwards, $data->trunkwardQuant is already set
      $entry->trunkwardQuant = $data->trunkwardQuant;
    }
    else {
      // If this entry might be sent trunkwards, calculate the value now.
      $entry->trunkwardQuant = ceil($entry->quant * $cc_config->conversionRate);
    }
    return $entry;
  }

  /**
   * Convert the entry for relaying to another node.
   * @note To convert the addresses we need to work out whether it is being sent
   * trunkward or leafward. This is the main or the only reason why $transaction is a property.
   */
  public function jsonSerialize() : mixed {
    global $cc_user, $cc_config;
    $metadata = $this->metadata;
    unset($metadata->{$this->payer->id});
    unset($metadata->{$this->payee->id});
    // Handle according to whether the transaction is going trunkwards or leafwards
    if ($this->transaction->trunkwards()) {
      $array['quant'] = $this->trunkwardQuant; // convert
      $array['payee'] = $this->payee->trunkwardPath();
      $array['payer'] = $this->payer->trunkwardPath();
      if ($cc_config->privacy['metadata']) {
        $array['metadata'] = $this->metadata;
      }
    }
    else {
      $array['quant'] = $this->quant; // convert
      $array['payee'] = $this->payee->leafwardPath();
      $array['payer'] = $this->payer->leafwardPath();
    }
    $array['description'] = $this->description;
    $array['metadata'] = $this->metadata;
    return $array;
  }

}
