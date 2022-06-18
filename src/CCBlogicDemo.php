<?php

namespace CCNode;

use CCNode\Transaction\Entry;

final class CCBlogicDemo implements CCBlogicInterface {

  /**
   * The name of the account into which fees are paid.
   * @var CCNode\Accounts\user
   */
  private $reservoirAcc;

  /**
   * the name of the current node
   * @var type
   */
  private $nodeName;

  function __construct() {
    global $config;
    // the fees will be paid into the first admin account.
    $account_names = \CCNode\accountStore()->filter(admin: true);
    $this->reservoirAcc = reset($account_names);
    $this->nodeName = $config->nodeName;
    print_r($this);
  }

  /**
   * Add a fee of 1 to both payer and payee
   */
  public function addRows(string $type, Entry $entry) : array {
    $additional = [
      $this->chargePayee($entry, '1%'),
      $this->chargePayer($entry, '1%')
    ];
    return array_filter(
      $additional,
      function($e) {return ($e->payer->id <> $e->payee->id) or $e->quant < 1;}
    );
  }

  /**
   * Charge the payee
   *
   * @param \stdClass $entry
   * @param string $fee_rate
   * @return \stdClass
   */
  private function chargePayee(Entry $entry, string $fee_rate) : \stdClass {
    $fee = $this->calc($entry->quant, $fee_rate);
    return (object)[
      'payer' => $entry->payee,
      'payee' => $this->reservoirAcc,
      'author' => $this->reservoirAcc->id,
      'quant' => $fee,
      'description' => $this->nodeName. " payee fee of $fee to ".$this->reservoirAcc->id
    ];
  }

  /**
   * Charge the payer.
   *
   * @param \stdClass $entry
   * @param string $fee_rate
   * @return \stdClass
   */
  private function chargePayer(Entry $entry, string $fee_rate) : \stdClass {
    $fee = $this->calc($entry->quant, $fee_rate);
    return (object)[
      'payer' => $entry->payer,
      'payee' => $this->reservoirAcc,
      'author' => $this->reservoirAcc->id,
      'quant' => $fee,
      'description' => $this->nodeName." payer fee of $fee to ".$this->reservoirAcc->id
    ];
  }

  /**
   * Calculates the fee as either a fixed value or a percent.
   * @param int $quant
   * @param float $fee_rate
   * @return int
   */
  private function calc(int $quant, string $fee_rate) : float {
    // the setting is a fix num of units or a percent.
    preg_match('/([0-9.])(%?)/', $fee_rate, $matches);
    $num = $matches[1];
    $percent = $matches[2];
    if ($percent) {
      $val = $quant * $num/100;
    }
    else {
      $val =  $num;
    }
    return ceil($val);
  }
}