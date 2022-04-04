<?php
namespace CCNode\Transaction;

use CCNode\AddressResolver;
use CCNode\Accounts\BoT;
use CCNode\Accounts\Remote;
use CCNode\Accounts\Branch;
use CreditCommons\BaseEntry;
use CreditCommons\Account;
use CreditCommons\Exceptions\WrongAccountViolation;

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
    foreach ([$payee, $payer] as $acc) {
      // Ideally this check would be a little more 'hard-wired' but that would require big architecture changes
      if ($acc instanceOf Remote and !$acc->relPath()) {
        throw new WrongAccountViolation($acc->id);
      }
    }
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
    $class = static::determineEntryClass($data->payee, $data->payer);
    $entry = new $class (
      $data->payee,
      $data->payer,
      $data->quant,
      $data->author,
      $data->metadata,
      $data->isAdditional,
      $data->isPrimary,
      $data->description
    );
    if ($entry instanceOf EntryTransversal) {
      $entry->setTransaction($transaction);
    }
    return $entry;
  }

  /**
   *
   * @param Account $acc1
   * @param Account $acc2
   * @return string
   */
  static function determineEntryClass(Account $acc1, Account $acc2) : string {
    $class_name = 'Entry';
    // Now, depending on the classes of the payer and payee
    if ($acc1 instanceOf Branch and $acc2 instanceOf Branch) {
      // both accounts are leafwards, the current node is at the apex of the route.
      $class_name = 'EntryTransversal';
    }
    elseif ($acc1 instanceOf BoT or $acc2 instanceOf BoT) {
      // One of the accounts is trunkwards, so this class does conversion of amounts.
      $class_name = 'EntryTrunkwards';
    }
    elseif ($acc1 instanceOf Branch or $acc2 instanceOf Branch) {
      // One account is local, one account is further leafwards.
      $class_name = 'EntryTransversal';
    }
    return '\CCNode\Transaction\\'.$class_name;
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
      'author' => $this->author,
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
   * @param array $rows
   * @return string
   *   The transaction classname. Transversal if any account is remote.
   *
   * @todo this might be tidier if it just does one row at a time.
   */
  static function upcastAccounts(array &$rows) : string {
    $class = 'Transaction';
    $addressResolver = AddressResolver::create();
    foreach ($rows as &$row) {
      $row->payee = $addressResolver->resolveTolocalAccount($row->metadata->{$row->payee}??$row->payee);
      $row->payer = $addressResolver->resolveTolocalAccount($row->metadata->{$row->payer}??$row->payer);
      // @todo make an address resolver function which only returns remote accounts
      if ($row->payee instanceOf Remote) {
        if (!$row->payee->isAccount()) {
          throw new WrongAccountViolation($row->payee->givenPath);
        }
        $class = 'TransversalTransaction';
      }
      elseif ($row->payer instanceOf Remote) {
        if (!$row->payer->isAccount()) {
          throw new WrongAccountViolation($row->payer->givenPath);
        }
        $class = 'TransversalTransaction';
      }
    }
    return '\\CCNode\\Transaction\\'.$class;
  }

}
