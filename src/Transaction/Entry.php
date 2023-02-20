<?php
namespace CCNode\Transaction;

use CreditCommons\Account;
use CreditCommons\Exceptions\CCOtherViolation;
use CreditCommons\Exceptions\SameAccountViolation;

/**
 * Determine the account types for entries.
 */
class Entry extends \CreditCommons\Entry implements \JsonSerializable {

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
    if ($payee->id == $payer->id) {
      throw new SameAccountViolation($payee->id);
      // @note If this happens with the trunkwards account then it means the
      // remote address does not exist, but this library has no way of knowing
      // what is the trunkwards account.
    }
    // Could this be done earlier?
    if (empty($quant) and $this->isPrimary and !$cc_config->zeroPayments) {
      throw new CCOtherViolation("Zero transactions not allowed on this node");
    }
    parent::__construct($payee, $payer, $quant, $metadata, $description);
  }

  /**
   * Convert the account names to Account objects, and instantiate the right sub-class.
   *
   * @param \stdClass $data
   *   From upstream, downstream NewTransaction or Blogic service. The payer and
   *   payee are already converted to Accounts
   * @return \CreditCommons\Entry
   */
  static function create(\stdClass $data) : static {
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
      $data->isPrimary??FALSE,
      substr($data->description, 0, 255) // To comply with mysql tinytext field.
    );
    return $entry;
  }

  function isAdditional() : bool {
    return $this->isAdditional;
  }

  /**
   * Serialise to send to client or blogic service.
   * @return mixed
   */
  public function jsonSerialize() : mixed {
    // Handle according to whether the transaction is going trunkwards or leafwards
    $array = [
      // Trunkward path is best if we don't have context.
      'payee' => (string)$this->payee,
      'payer' => (string)$this->payer,
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
