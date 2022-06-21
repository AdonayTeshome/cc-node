<?php

namespace CCNode;

use CCNode\Transaction\Entry;

class BlogicDemo extends \CreditCommons\Example\BlogicDemo {

  /**
   * the name of the current node
   * @var string
   */
  private $nodeName;

  function __construct() {
    global $config;
    // Pay the first admin account
    $admins = accountStore()->filter(admin: TRUE);
    parent::__construct(reset($admins)->id);
    $this->nodeName = $config->nodeName;
  }

  /**
   * Add a fee of 1 to both payer and payee
   */
  public function addRows(string $type, string $payee, string $payer, int $quant, \stdClass $metadata = NULL, string $description = '') : array {
    $additional_rows = [
      $this->chargePayeeRate($payee, $quant, '1%'),
      $this->chargePayerRate($payer, $quant, '1%')
    ];
    Entry::upcastAccounts($additional_rows);
    // remove any entries with too small amounts or payer & payee identical.
    return array_filter(
      $additional_rows,
      function($e) {return ($e->payee->id <> $e->payer->id) or $e->quant < 1;}
    );
  }

  /**
   * Charge the payee
   *
   * @param string $payee
   * @param int $quant
   * @param string $fee_rate
   * @return \stdClass
   */
  private function chargePayeeRate(string $payee, int $quant, string $fee_rate) : \stdClass {
    $entry = parent::chargePayee($payee, $this->calc($quant, $fee_rate));
    $entry->description = "$this->nodeName payee fee of $entry->quant to $this->feeAcc";
    return $entry;

  }

  /**
   * Charge the payer.
   *
   * @param string $payer
   * @param int $quant
   * @param string $fee_rate
   * @return \stdClass
   */
  private function chargePayerRate(string $payer, int $quant, string $fee_rate) : \stdClass {
    $entry = parent::chargePayer($payer, $this->calc($quant, $fee_rate));
    $entry->description = "$this->nodeName payee fee of $entry->quant to $this->feeAcc";
    return $entry;
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
