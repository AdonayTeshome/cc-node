<?php

namespace CCNode\Accounts;

use CCNode\Transaction\Transaction;

/**
 * Class representing a remote account (or remote node) and managing queries to it.
 */
interface RemoteAccountInterface {

  /**
   * Get the last hash pertaining to this account.
   *
   * @return array
   */
  function getLastHash() : string;

  /**
   * The path to the remote account relative to this account on the local ledger.
   * @return string
   */
  function relPath() : string;

  /**
   * Check if this Account points to a remote account, rather than a remote node.
   * @return bool
   *   TRUE if this object references a remote account, not a whole node
   *
   * @todo refactor Address resolver so this isn't necessary in Entry::upcastAccounts
   */
  public function isAccount() : bool;

  /**
   * @return string
   *   'ok' or the class name of the error
   */
  function handshake() : string;

  /**
   * Pass a new transaction to the downstream node for building and validation.
   *
   * @param Transaction $transaction
   * @return Any entries added by downstream nodes, with converted quants, but not upcast.
   */
  function buildValidateRelayTransaction(Transaction $transaction) : array;

  /**
   * Convert incoming entry values (if upstream is trunkwards);
   * @param array $entries
   * @return void
   */
  public function convertIncomingEntries(array &$entries) : void;

  /**
   * Autocomplete fragment using account names on a remote node;
   *
   * @param string $fragment
   * @return string[]
   */
  function autocomplete() : array;

  /**
   * Retrieve a remote transaction (with responseMode - TRUE)
   *
   * @param string $uuid
   * @param bool $full
   * @return \stdClass
   */
  function retrieveTransaction(string $uuid, bool $full = TRUE) : \CreditCommons\BaseTransaction|array;

  /**
   * Get all the account summaries on a remote node.
   *
   * @return array
   */
  function getAllSummaries() : array;

  /**
   * @return []
   */
  function getAllLimits() : array;

}

