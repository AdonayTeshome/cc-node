<?php

namespace CCNode\Transaction;

use CCNode\Transaction\Entry;
use CCNode\Accounts\RemoteAccountInterface;
use CreditCommons\BaseEntry;

/**
 * Transversal entries have different classes (and hence methods) according to
 * which ledger it is shared with.
 * @todo make a new interface for this.
 */
class EntryTransversal extends Entry {

  public float $trunkwardQuant;

  static function create(\stdClass $data, $transaction) : BaseEntry {
    global $cc_config;
    $e = parent::create($data, $transaction);
    if (isset($data->trunkwardQuant)) {
      // If this is coming from trunkwards, $data->trunkwardQuant is already set
      $e->trunkwardQuant = $data->trunkwardQuant;
    }
    elseif ($cc_config->conversionRate <> 1) {
      // If this entry might be sent trunkwards, calculate the value now.
      $e->trunkwardQuant = ceil($e->quant * $cc_config->conversionRate);
    }
    return $e;
  }

  /**
   * @var CCNode\Transaction
   */
  protected $transaction;

  public function setTransaction(TransversalTransaction $transaction) : void {
    $this->transaction = $transaction;
  }

  /**
   * Convert the entry for sending to another node.
   * Because we can't pass args to this function, we lack context.
   * That's one reason the global $cc_user was needed.
   */
  public function jsonSerialize() : mixed {
    global $cc_user;
    // Handle according to whether the transaction is going trunkwards or leafwards
    if ($this->transaction->trunkwardResponse()) {// Going trunkward.
      $array = [
        'payee' => $this->payee->trunkwardId(),
        'payer' => $this->payer->trunkwardId(),
        'quant' => $this->trunkwardQuant,
        'description' => $this->description
      ];
      if ($this->includeMetaData()) {
        $array['metadata'] = $this->metadata;
      }
    }
    elseif ($cc_user instanceOf RemoteAccountInterface) {// Going leafward.
      $array = [
        'payee' => $this->payee->leafwardId(),
        'payer' => $this->payer->leafwardId(),
        'quant' => $this->quant,
        'description' => $this->description,
        'metadata' => $this->metadata
      ];
    }
    else { // going back to the client.
      $array = [
        'payee' => $this->payee->id . ($this->payee instanceOf RemoteAccountInterface ? '/'.$this->payee->relPath : ''),
        'payer' => $this->payer->id . ($this->payer instanceOf RemoteAccountInterface ? '/'.$this->payer->relPath : ''),
        'quant' => $this->quant,
        'description' => $this->description,
        'metadata' => $this->metadata
      ];
    }
    unset(
      $array['metadata']->{$this->payee->id},
      $array['metadata']->{$this->payer->id}
    );
    return $array;
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
