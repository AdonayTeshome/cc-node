<?php

namespace CCNode\Accounts;

use CCNode\TradeStats;
use CCNode\Transaction\Transaction;
use CreditCommons\Exceptions\PasswordViolation;
use CreditCommons\Account;
use function CCNode\accountStore;

/**
 * Class representing a member of the ledger
 */
class User extends Account {

  /**
   * The path from the node this account references, to a leaf account
   * @var string
   */
  public string $relPath;

  /**
   * @param mixed $samples
   *   NULL means just return the raw points. 0 means show a true time record
   *   with a stepped appearance. otherwise return the number of points to smooth to
   *   steps, points, smoothed = .
   * @return array
   *   Balances keyed by timestamp, oldest first. -1 samples will interpolate
   *   the data to give true balance over time, a stepped pattern. 0 samples will
   *   return the raw data, in the form of diagonal lines, any larger number will
   *   sample the data to make the message size smaller and rendering easier.
   *
   * @note Uses the transaction updated time, not the created time
   * @note this isn't part of the core API
   */
  function getHistory(int $samples = -1) : array {
    $points = [];
    $result = Transaction::accountHistory($this->id) ;
    // Database is storing timestamps as 'Y-m-d H:i:s.0'
    // make a first point at zero balance 1 sec before the first balance.
    if ($t = $result->fetch_object()) {
      $start_sec = (new \DateTime($t->written))->modify('-5 seconds');
      // 2022-02-02 23:39:56.000000
      $points[$start_sec->format('Y-m-d H:i:s')] = 0;
      $points[$t->written] = (int)$t->balance;
      while($t = $result->fetch_object()) {
        $points[$t->written] = (int)$t->balance;
      }
      if ($samples === 0) {
        // the raw data would show diagonal lines
      }
      elseif($samples == -1) {
        $times = $values = [];
        // Make two values for each one in the keys and values.
        foreach ($points as $time => $bal) {
          $secs = strtotime($time);
          $times[] = date("Y-m-d H:i:s", $secs);
          $times[] = date("Y-m-d H:i:s", $secs+1);
          $values[] = $bal;
          $values[] = $bal;
        }
        // Now slide the arrays against each other to create steps.
        array_shift($times);
        array_pop($values);
        unset($points);
        $points = array_combine($times, $values);
      }
      else {
        // For large numbers of transactions can be reduced for ease of rendering.
      }
      if (!$samples and $points) {
        // Finish the line from the last transaction until now.
        $points[date("Y-m-d H:i:s")] = end($points); //this date format corresponds to the mysql DATETIME
        // Note that since the first point is ALWAYS the first transaction in this
        // implementation, we don't create a create a point for initial 0 balance.
      }
    }
    else {
      // Make a start and end point.
      // NB the start time of one year is arbitrary and should be determined by config
      $points[date("Y-m-d H:i:s", strtotime('-1 year'))] = 0;// Because the first transaction adds 2 points
      $points[date("Y-m-d H:i:s")] = 0;// Because the first transaction adds 2 points
    }
    return $points;
  }

  /**
   * @return CreditCommons\TradeStats[]
   *   Two groups of stats, with keys 'completed' and 'pending'.
   */
  function getSummary($force_local = FALSE) : \stdClass {
    $completed = TradeStats::builder();
    $pending = TradeStats::builder();
    $results = Transaction::accountSummary($this->id);
    while ($row = $results->fetch_object()) {
      // All transactions contribute to the pending stats.
      $pending->logTrade($row->diff, $row->partner, $row->isPrimary);
      if ($row->state == 'completed') {
        // Make stats summariing only completed transactions
        $completed->logTrade($row->diff, $row->partner, $row->isPrimary);
      }
    }
    return (object)[
      'completed' => $completed,
      'pending' => $pending
    ];
  }

  /**
   * {@inheritdoc}
   */
  function getLimits($force_local = FALSE) : \stdClass {
    return (object)['min' => $this->min, 'max' => $this->max];
  }

  /**
   * {@inheritdoc}
   */
  function authenticate(string $auth_string) {
    global $error_context;
    if (!accountStore()->compareAuthkey($this->id, $auth_string)) {
      //local user with the wrong password
      throw new PasswordViolation($auth_string);
    }
    $error_context->user = $this->id;
  }

  function trunkwardPath() : string {
    global $cc_config;
    return  "$cc_config->nodeName/$this->id";
  }

  function leafwardPath() : string {
    global $cc_config;
    return  "$cc_config->nodeName/$this->id";
  }

}

