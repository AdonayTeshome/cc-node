<?php

namespace CCNode\Accounts;

use CCNode\Db;

/**
 * Provides all interaction between an account and the ledger.
 *
 * TODO NOT USED move the below functions elsewhere.
 */
trait AccountSummaryTrait {




  /**
   * Get stats for all members
   * @return array
   *   Stats keyed by acc_id
   */
  private static function getAllLocalTradeStats() : array {

    return $balances;
  }

}
