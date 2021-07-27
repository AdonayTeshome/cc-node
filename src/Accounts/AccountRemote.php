<?php

namespace CCNode;
use CreditCommons\LeafAPI;
use CCNode\Accounts\Account;

/**
 * Class representing a member
 */
class AccountRemote extends Account {

  /**
   * Get the last hash pertaining to this account.
   *
   * @return array
   *
   * @todo could save the result but I don't think it used more than once per request.
   */
  function getLastHash() : string {
    $query = "SELECT hash "
      . "FROM hash_history "
      . "WHERE acc = '$this->id' "
      . "ORDER BY id DESC LIMIT 0, 1";
    if ($row = Db::query($query)->fetch_object()) {
      return (string)$row->hash;
    }
    else { //this account has never traded
      return '';
    }
  }

  public function getHistory($samples = 0) : array {
    // todo get the downstreamAccount from the ledger
    global $orientation;
    if ($orientation->downstreamAccount) {
      $client =  new LeafAPI($orientation->downstreamAccount->url);
      return $client->getHistory($this->transversalPath, $samples);
    }
    return parent::getHistory();
  }


  /**
   * {@inheritDoc}
   */
  function getTradeStats() : array {
    global $orientation;
    if ($orientation->downstreamAccount) {
      $client =  new LeafAPI($orientation->downstreamAccount->url);
      // Branchward nodes may not grant permission
      return $client->getStats($this->givenPath);
    }
    return parent::getTradeStats();
  }


  /**
   * {@inheritDoc}
   */
  static function getAllTradeStats(bool $details = TRUE) : array {
    global $orientation;
    $all_accounts = parent::getAllTradeStats($details);
    // This function is only ever called from index.php where the ledger has
    // been oriented to root.
    $map = $orientation->getDownstreamRequester()->accounts($details, TRUE);
    $downstreamAccountName = $orientation->downstreamAccount->id;

    if ($details) {
      $all_accounts[$downstreamAccountName]->parents = $map;
    }
    else {
      $all_accounts[$downstreamAccountName] = $map;
    }
    return $all_accounts;
  }


}

