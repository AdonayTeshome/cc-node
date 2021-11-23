<?php
namespace CCnode;

use CreditCommons\Entry as CreditCommonsEntry;
use CreditCommons\Account;


/**
 * Determine the account types for entries.
 *
 */
class Entry extends CreditCommonsEntry {

  /**
   * TRUE if this entry was authored locally or downstream.
   * @var bool
   */
  private $additional;


  /**
   * Convert the account names to Account objects, and instantiate the right sub-class.
   *
   * @param object $row
   *   Could be from client or a flattened Entry
   * @return \CreditCommonsEntry
   */
  static function create(\stdClass $row) : CreditCommonsEntry {
    $missing = [];
    // Basic validation needs to be done all in one place.
    foreach (['payer', 'payee', 'quant'] as $field_name) {
      if (empty($row->{$field_name})) {
        $missing["entries-$key:$field_name"] = '_REQUIRED_';
      }
    }
    if ($missing) {
      throw new InvalidFieldsViolation(['fields' => $missing]);
    }
    $payee = accountStore()->ResolveAddress($row->metadata->{$row->payee} ?? $row->payee, FALSE);
    $payer = accountStore()->ResolveAddress($row->metadata->{$row->payer} ?? $row->payer, FALSE);

    foreach (['payee', 'payer'] as $role) {
      if ($$role instanceOf AccountRemote) {
        $row->metadata->{$$role->id} = $$role->givenPath;
      }
    }
    // Unknown accounts will show up as the balance of trade account.

    $class = static::determineClass($payee, $payer);

    return new $class(
      $payee,
      $payer,
      $row->quant,
      $row->description,
      $row->author,
      $row->metadata
    );
  }

  /**
   *
   * @param Account $acc1
   * @param Account $acc2
   * @return string
   */
  static function determineClass(\CreditCommons\Account $acc1, \CreditCommons\Account $acc2) : string {
    $class_name = 'CCNode\Entry';
    // Now, depending on the classes of the payer and payee
    if ($acc1 instanceOf AccountBranch and $acc2 instanceOf AccountBranch) {
      // both accounts are leafwards, the current node is at the apex of the route.
      $class_name = 'CCNode\TransversalEntry';
    }
    elseif ($acc1 instanceOf AccountBoT or $acc2 instanceOf AccountBoT) {
      // One of the accounts is trunkwards
      $class_name = 'CCNode\TrunkwardsEntry';
    }
    elseif ($acc1 instanceOf AccountBranch or $acc2 instanceOf AccountBranch) {
      // One account is local, one account is further leafwards.
      $class_name = 'CCNode\TransversalEntry';
    }
    return $class_name;
  }

  function additional() {
    $this->additional = TRUE;
    return $this;
  }

  function isAdditional() : bool {
    return $this->additional;
  }

}
