<?php

namespace CCNode\Accounts;
use CreditCommons\RestAPI;
use CCNode\Accounts\Account;

/**
 * Class representing a member
 */
class Remote extends Account {

  public $url;

  function __construct(\stdClass $obj) {
    parent::__construct($obj);
    $this->url = $obj->url;
  }

  function getRequester() {
    return new RestAPI($this->url);
  }

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
    else { //this account has never traded to there is no hash. Security problem?
      return '';
    }
  }

  public function getHistory($samples = 0) : array {
    // N.B. Branchward nodes may refuse permission
    return $this->getRequester()->getHistory($this->transversalPath, $samples);

    return parent::getHistory();
  }


  /**
   * {@inheritDoc}
   */
  function getTradeStats() : array {
    // N.B. Branchward nodes may refuse permission
    return $this->getRequester()->getStats($this->givenPath);

    return parent::getTradeStats();
  }


  /**
   * {@inheritDoc}
   */
  static function getAllTradeStats(bool $details = TRUE) : array {
    $all_accounts = parent::getAllTradeStats($details);
    $map = $this->getRequester()->accounts($details, TRUE);
    if ($details) {
      $all_accounts[$this->id]->parents = $map;
    }
    else {
      $all_accounts[$this->id] = $map;
    }

    return $all_accounts;
  }


}

