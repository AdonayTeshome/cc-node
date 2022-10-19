<?php
namespace CCNode\Accounts;
use CCNode\Transaction\Transaction;

/**
 * Class representing an account corresponding to an account on another ledger
 */
class Trunkward extends Remote {

  private float $trunkwardConversionRate = 1;

  function __construct(
    string $id,
    int $min,
    int $max,
    /**
     * The url of the remote node
     * @var string
     */
    public string $url
   ) {
    parent::__construct($id, $min, $max, $url);
    global $cc_config;
    if ($cc_config->conversionRate <> 1) {
      $this->trunkwardConversionRate = $cc_config->conversionRate;
    }
  }

  /**
   * {@inheritDoc}
   */
  function TrunkwardId() : string {
    return $this->relPath;
  }

  /**
   * {@inheritDoc}
   */
  function leafwardId() : string {
    return "$this->id/$this->relPath";
  }

  /**
   * {@inheritdoc}
   */
  public function buildValidateRelayTransaction(Transaction $transaction) : array {
    $rows = parent::buildValidateRelayTransaction($transaction);
    $this->convertIncomingEntries($rows, $this->id, $this->trunkwardConversionRate);
    return $rows;
  }


  /**
   * Convert the quantities if entries are coming from the trunk
   * @param array $entries
   *   array of stdClass or Entries.
   */
  public static function convertIncomingEntries(array &$entries, string $author, float $rate) : void {
    global $cc_config;
    foreach ($entries as &$e) {
      $e->trunkwardQuant = $e->quant;
      if ($rate <> 1) {
        $e->quant = round($e->quant / $rate, $cc_config->decimalPlaces);
      }
      $e->author = $author;
    }
  }

  /**
   * {@inheritdoc}
   */
  function getSummary($force_local = FALSE) : \stdClass {
    $summary = parent::getSummary($force_local);
    $this->convertSummary($summary);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  function getAllSummaries() : array {
    $summaries = parent::getAllSummaries();
    foreach ($summaries as &$summary) {
      $this->convertSummary($summary);
    }
    return $summaries;
  }

  /**
   * Convert remote summary objects, if coming from trunkwards.
   *
   * @param \stdClass $summary
   * @return void
   */
  private function convertSummary(\stdClass $summary) : void {
    if ($rate = $this->trunkwardConversionRate) {
      $summary->pending->receiveTrunkward($rate);
      $summary->completed->receiveTrunkward($rate);
    }
    // @todo convert when Sending trunkward. This is not a priority because
    // sending internal info trunkward is strictly optional.
  }

  /**
   * {@inheritdoc}
   */
  function getLimits($force_local = FALSE) : \stdClass {
    $limits = parent::getLimits($force_local);
    if (!$this->isNode()) {
      $this->convertLimits($limits);// convert values from trunkward nodes.
    }
    return $limits;
  }

  /**
   * {@inheritdoc}
   */
  function getAllLimits() : array {
    $all_limits = parent::getAllLimits();
    foreach ($all_limits as &$limits) {
      $this->convertLimits($limits);
    }
    return $all_limits;
  }

  /**
   * Convert remote limits objects (if coming from trunkwards)
   *
   * @param \stdClass $summary
   * @return void
   */
  private function convertLimits(\stdClass &$limits) : void {
    if ($rate = $this->trunkwardConversionRate) {
      $limits->min = ceil($limits->min / $rate);
      $limits->max = ceil($limits->max / $rate);
    }
    // @todo convert when Sending trunkward. This is not a priority because
    // sending internal info trunkward is strictly optional.
  }

}

