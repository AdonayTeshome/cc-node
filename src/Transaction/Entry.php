<?php
namespace CCNode\Transaction;

use CCNode\AddressResolver;
use CCNode\Accounts\Remote;
use CreditCommons\BaseEntry;
use CreditCommons\Account;

/**
 * Determine the account types for entries.
 */
class Entry extends BaseEntry implements \JsonSerializable {

  function __construct(
    public Account $payee,
    public Account $payer,
    public int $quant,
    public string $author, // The author is always local and we don't need to cast it into account
    public \stdClass $metadata, // Does not recognise field type: \stdClass
    protected bool $isAdditional,
    /**
     * TRUE if this entry was authored by blogicService or downstream.
     * @var bool
     */
    protected bool $isPrimary,
    /**
     * TRUE if this entry was authored locally or downstream.
     * @var bool
     */
    public string $description = '',
  ) {
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
  static function create(\stdClass $data, $transaction) : BaseEntry {
    if (!isset($data->metadata)) {
      $data->metadata = new \stdClass();
    }
    static::validateFields($data);
    $entry = new static (
      $data->payee,
      $data->payer,
      $data->quant,
      $data->author,
      $data->metadata,
      $data->isAdditional,
      $data->isPrimary,
      substr($data->description, 0, 255) // To comply with mysql tinytext field.
    );
    if ($entry instanceOf EntryTransversal) {
      $entry->setTransaction($transaction);
    }
    return $entry;
  }

  function isAdditional() : bool {
    return $this->isAdditional;
  }

  /**
   * Entries return to the client with account names collapsed to a relative name
   * @return array
   */
  public function jsonSerialize() : array {
    $flat = [
      'payee' => $this->payee->foreignId(),
      'payer' => $this->payer->foreignId(),
      'quant' => $this->quant,
      'description' => $this->description,
      'metadata' => $this->metadata
    ];
    // Calculate metadata for client
    foreach (['payee', 'payer'] as $role) {
      $name = $this->{$role}->id;
      $flat[$role] = $this->metadata->{$name} ?? $name;
    }
    return $flat;
  }

  /**
   * Prepare a version of the entry to send to the Blogic module.
   */
  public function toBlogic($type) : array {
    // Foreign ids are used so they can be upcast later.
    return [
      'payee' => $this->payee->foreignId(),
      'payer' => $this->payer->foreignId(),
      'quant' => $this->quant,
      'description' => $this->description,
      'metadata' => $this->metadata,
      'type' => $type
    ];
  }

  /**
   * @param array $rows
   * @return string
   *   The transaction classname. Transversal if any account in any entry is remote.
   *
   * @todo this might be tidier if it just does one Entry at a time.
   */
  static function upcastAccounts(array &$rows) : string {
    $addressResolver = AddressResolver::create();
    foreach ($rows as &$row) {
      $payee_addr = $row->metadata->{$row->payee}??$row->payee;
      $row->payee = $addressResolver->localOrRemoteAcc($payee_addr);
      $payer_addr = $row->metadata->{$row->payer}??$row->payer;
      $row->payer = $addressResolver->localOrRemoteAcc($payer_addr);
      // @todo refactor the address resolver so that we can be sure by now that
      // the payee/payer is one local or remote account and not a remote node - i.e. with a slash at the end.
      if ($row->payee instanceOf Remote or $row->payer instanceOf Remote) {
        $class = 'TransversalTransaction';
      }
      else {
        $class = 'Transaction';
      }
    }
    return '\\CCNode\\Transaction\\'.$class;
  }

}
