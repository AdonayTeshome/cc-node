<?php
namespace CCNode\Transaction;

use CCNode\AddressResolver;
use CCNode\Accounts\Remote;
use CreditCommons\BaseEntry;
use CreditCommons\Account;
use CreditCommons\Exceptions\CCOtherViolation;

/**
 * Determine the account types for entries.
 */
class Entry extends BaseEntry implements \JsonSerializable {

  function __construct(
    public Account $payee,
    public Account $payer,
    public float $quant,
    public string $author, // The author is always local and we don't need to cast it into account
    public \stdClass $metadata, // Does not recognise field type: \stdClass
    protected bool $isAdditional,
    /**
     * TRUE if this entry was authored by blogicService or downstream.
     * @var bool
     */
    public bool $isPrimary,
    /**
     * TRUE if this entry was authored locally or downstream.
     * @var bool
     */
    public string $description = '',
  ) {
    global $cc_config;
    // Could this be done earlier?
    if (empty($quant) and !$cc_config->zeroPayments) {
      throw new CCOtherViolation("Zero transactions not allowed on this node.");
    }
    parent::__construct($payee, $payer, $quant, $author, $metadata, $description);
  }

  /**
   * Convert the account names to Account objects, and instantiate the right sub-class.
   *
   * @param \stdClass $data
   *   From upstream, downstream NewTransaction or Blogic service. The payer and
   *   payee are already converted to Accounts
   * @return \BaseEntry
   */
  static function create(\stdClass $data, Transaction $transaction = NULL) : BaseEntry {
    if (!isset($data->metadata)) {
      $data->metadata = new \stdClass();
    }
    if (!isset($data->isPrimary)) {
      $data->isPrimary = FALSE;
    }
    static::validateFields($data);
    $entry = new static (
      $data->payee,
      $data->payer,
      $data->quant,
      $data->author,
      $data->metadata,
      $data->isAdditional,
      $data->isPrimary = 0,
      substr($data->description, 0, 255) // To comply with mysql tinytext field.
    );
    return $entry;
  }

  function isAdditional() : bool {
    return $this->isAdditional;
  }

  /**
   * For sending the transaction back to the client.
   */
  public function jsonSerialize() : mixed {
    // Handle according to whether the transaction is going trunkwards or leafwards
    $array = [
      'payee' => $this->payee->id,
      'payer' => $this->payer->id,
      'quant' => $this->quant,
      'description' => $this->description,
      'metadata' => $this->metadata
    ];
    unset(
      $array['metadata']->{$this->payee->id},
      $array['metadata']->{$this->payer->id}
    );
    return $array;
  }

}
