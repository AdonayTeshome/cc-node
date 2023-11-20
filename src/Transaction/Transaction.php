<?php

namespace CCNode\Transaction;

use CCNode\Transaction\EntryTransversal;
use CCNode\Orientation;
use CCNode\BlogicRequester;
use CCNode\Accounts\Remote;
use CCNode\Accounts\Trunkward;
use CreditCommons\Workflow;
use CreditCommons\TransactionInterface;
use CreditCommons\Exceptions\MaxLimitViolation;
use CreditCommons\Exceptions\MinLimitViolation;
use CreditCommons\Exceptions\WorkflowViolation;
use CreditCommons\Exceptions\DoesNotExistViolation;


class Transaction extends \CreditCommons\Transaction implements \JsonSerializable {

  const DECIMAL_PLACES = 6;
  const REGEX_DATE = '/[0-9]{4}-[0|1]?[0-9]-[0-3][0-9]/';
  const REGEX_TIME = '/[0-2][0-9]:[0-5][0-9]:[0-5][0-9]/';
  use \CCNode\Transaction\StorageTrait;

  /**
   * The database ID of the transaction
   * @var int
   */
  public int $txID = 0;

  /**
   * Name of the user who wrote the latest version
   * @var string
   */
  public string $scribe;

  public static function createFromUpstream(\stdClass $data) : TransactionInterface {
    global $cc_user, $cc_config;
    if ($cc_user->id == $cc_config->trunkwardAcc){
      Trunkward::convertIncomingEntries($data->entries, $cc_user->id, $cc_config->conversionRate);
    }
    $data->state = TransactionInterface::STATE_INITIATED;
    return static::create($data);
  }


  static function create(\stdClass $data) : static {
    global $cc_user, $orientation;
    if (isset($orientation))unset($orientation);
    static::upcastEntries($data->entries);
    $data->version = $data->version??-1;
    $data->written = $data->written??'';
    static::validateFields($data);
    $transaction_class = '\CCNode\Transaction\Transaction';
    foreach ($data->entries as $e) {
      if ($e instanceOf EntryTransversal) {
        $transaction_class = '\CCNode\Transaction\TransversalTransaction';
        if (!isset($orientation)) {
          $orientation = Orientation::CreateTransversal($data->entries[0]->payee, $data->entries[0]->payer);
        }
        break;
      }
    }
    if (!isset($orientation)) {
      $orientation = Orientation::CreateLocal($data->entries[0]->payee, $data->entries[0]->payer);
    }
    $transaction = new $transaction_class($data->uuid, $data->type, $data->state, $data->entries, $data->written, $data->version);
    if (isset($data->txID)) {
      $transaction->txID = $data->txID;
    }
    $transaction->scribe = $data->scribe??$cc_user->id;
    return $transaction;
  }

  /**
   * Call the business logic, append entries.
   * Validate the transaction in its workflow's 'creation' state
   *
   * @return Entry[]
   *   Any new rows added by the business logic.
   */
  function buildValidate() : array {
    global $cc_config, $cc_user;

    if (!$this->workflow->active) {
      // not allowed to make new transactions with non-active workflows
      throw new DoesNotExistViolation(type: 'workflow', id: $this->type);
    }
    $desired_state = $this->workflow->creation->state;
    // Check the user has the right role to create this transaction.
    $direction = $this->workflow->direction;
    if ($direction <> '3rdparty') {
      $bill_payee = ($direction == 'bill' && $cc_user->id == $this->entries[0]->payee->id);
      $credit_payer = ($direction == 'credit' && $cc_user->id == $this->entries[0]->payer->id);
      if (!$bill_payee and !$credit_payer) {
        throw new WorkflowViolation(type: $this->type, from: $this->state, to: $desired_state);
      }
    }
    // Blogic both adds the new rows and returns them.
    $rows = $cc_config->blogicMod ? $this->callBlogic($cc_config->blogicMod) : [];
    $this->checkLimits();
    $this->state = TransactionInterface::STATE_VALIDATED;
    return $rows;
  }

  /**
   * Check the transaction doesn't transgress balance limits
   *
   * @throws MaxLimitViolation
   * @throws MinLimitViolation
   *
   * @note if the request was from a trunkward node, the $diff in the violation needs to be multiplied up.
   */
  protected function checkLimits() {
    global $cc_config, $orientation;
    $payee = $this->entries[0]->payee;
    if ($payee->max) {
      $acc_summary = $payee->getSummary(TRUE);
      $stats = $cc_config->validatePending ? $acc_summary->pending : $acc_summary->completed;
      $payee_diff = $this->sum($payee->id);
      $projected = $stats->balance + $payee_diff;
      if ($payee_diff > 0 and $projected > $payee->max) {
        throw new MaxLimitViolation(
          diff: $payee_diff *= ($orientation->upstreamAccount instanceOf Trunkward ? $cc_config->conversionRate : 1),
          acc: $payee->id == $cc_config->trunkwardAcc ? '*' : $payee->id
        );
      }
    }
    $payer = $this->entries[0]->payer;
    if ($payer->min) {
      $acc_summary = $payer->getSummary(TRUE);
      $stats = $cc_config->validatePending ? $acc_summary->pending : $acc_summary->completed;
      $payer_diff = $this->sum($payer->id);
      $projected = $stats->balance + $payer_diff;
      if ($payer_diff < 0 and $projected < $payer->min) {
        throw new MinLimitViolation(
          diff: $payer_diff *= ($orientation->upstreamAccount instanceOf Trunkward ? $cc_config->conversionRate : 1),
          acc: $payer->id == $cc_config->trunkwardAcc ? '*' : $payer->id
        );
      }
    }
  }

  /**
   * Call whatever Blogic class and upcast and append any resulting rows.
   */
  protected function callBlogic(string $bLogicMod) : array {
    // this is rather cumbersome because blogic wants the first entry with
    // flattened payee and payee and the transaction->type.
    $first = $this->entries[0];
    $blogic_entry = [
      'payee' => (string)$first->payee,
      'payer' => (string)$first->payer,
      'quant' => $first->quant,
      'description' => $first->description,
      'metadata' => $first->metadata,
      'type' => $this->type
    ];
    if (class_exists($bLogicMod)) {
      $blogic_service = new $bLogicMod();
      $rows = $blogic_service->addRows(...$blogic_entry);
    }
    else {// It must be a url
      // The Blogic service will know itself which account to put fees in.
      $rows = (new BlogicRequester($bLogicMod))->addRows(...$blogic_entry);
    }
    // The Blogic returns entries with upcast account objects
    static::upcastEntries($rows, TRUE);

    $this->entries = array_merge($this->entries, $rows);
    return $rows;
  }

  /**
   * Write the transaction metadata with a version number of 0.
   *
   * @return bool
   *   FALSE if the transaction is in a temporary state
   */
  function insert() : bool {
    // The transaction is in 'validated' state.
    if ($this->workflow->creation->confirm) {
      $this->version = -1;
      $status_code = 200;
    }
    else {
      // Write the transaction immediately to its 'creation' state
      $this->state = $this->workflow->creation->state;
      $this->version = 0;
      $status_code = 201;
    }
    // this adds +1 to the version.
    $this->saveNewVersion();
    return $status_code;
  }

  /**
   * @param string $target_state
   * @return bool
   *   TRUE if a new version of the transaction was saved. FALSE if the transaction was deleted (transactions in validated state only)
   * @throws WorkflowViolation
   *
   * @note
   */
  function changeState(string $target_state) : bool {
    global $cc_user;
    // If the logged in account is local, then at least one of the local accounts must be local.
    // No leaf account can manipulate transactions which only bridge this ledger.
    if ($this->entries[0]->payer instanceOf Remote and $this->entries[0]->payee instanceOf Remote and !$cc_user instanceOf Remote) {
      throw new WorkflowViolation(type: $this->type, from: $this->state, to: $target_state);
    }
    if ($target_state == 'null' and $this->state == 'validated') {
      $this->delete();
      return FALSE;
    }
    // Remote users who are involved in the transaction are treated as admins.
    $skip_check = $cc_user->admin or $cc_user instanceOf Remote && Workflow::getAccRole($this, $cc_user->id);

    if ($this->workflow->canTransitionToState($this, $cc_user->id, $target_state, $skip_check)) {
      $this->state = $target_state;
      $this->saveNewVersion();
      return TRUE;
    }
    throw new WorkflowViolation(
      type: $this->type,
      from: $this->state,
      to: $target_state,
    );
  }

  /**
   * Calculate the total difference this transaction makes to an account balance.
   *
   * @param string $acc_id
   *   The account whose diff is needed.
   *
   * @return int
   *   The total difference caused by this transaction to the account balance
   */
  private function sum(string $acc_id) {
    $diff = 0;
    foreach ($this->entries as $entry) {
      if ($acc_id == $entry->payee->id) {
        $diff += $entry->quant;
      }
      elseif ($acc_id == $entry->payer->id) {
        $diff -= $entry->quant;
      }
    }
    return $diff;
  }

  /**
   * {@inheritDoc}
   */
  public function transitions() : array {
    global $cc_user;
    return $this->workflow->getTransitions($cc_user->id, $this, (bool)$cc_user->admin);
  }

  /**
   * Retrieve this transaction's workflow from the global scope.
   * To access the workflow normally use $this->workflow.
   * @see __get()
   */
  public function getWorkflow() : Workflow {
    global $cc_workflows;
    foreach ($cc_workflows->tree as $node_name => $wfs) {
      foreach ($wfs as $wf) {
        if ($wf->id == $this->type) {
          return $wf;
        }
      }
    }
    throw new DoesNotExistViolation(type: 'workflow', id: $this->type);
  }

  /**
   *
   * @param type $value
   * @return type
   */
  public function __get($value) {
    // Optional way to access the workflow as a property (not documented)
    if ($value == 'workflow') {
      return $this->getWorkflow();
    }
  }


  /**
   * Make entry objects from json entries with users already upcast.
   * - upcast the accounts
   * - add 'author' and isPrimary fields
   * - determine which class each entry is.
   *
   * @param stdClass[] $rows
   *   Entry objects received as json.
   * @param bool $is_additional
   *   TRUE if these transactions were created (as fees etc.) by the current
   *   node or downstream, and hence should be passed back upstream
   * @return bool
   *   TRUE if any of the Entries is transversal.
   */
  protected static function upcastEntries(array &$rows, bool $is_additional = FALSE) : bool {
    global $cc_user;
    $transversal_transaction = FALSE;
    foreach ($rows as &$row) {
      $transversal_row = \CCNode\upcastAccounts($row);
      // sometimes this is called with entries from the db including isPrimary
      // other times it is called with additional entries from json
      if (!isset($row->isPrimary)) {
        $row->isPrimary = !$is_additional;
      }
      if (empty($row->author)) {
        $row->author = $cc_user->id;
      }
      $entry_class = 'CCNode\Transaction\Entry';
      if ($transversal_row) {
        $entry_class .= 'Transversal';
        $transversal_transaction = TRUE;
      }
      // Transversal entries require the transaction as a parameter.
      $row = [$entry_class, 'create']($row);
    }
    return $transversal_transaction;
  }

  public function jsonSerialize(): mixed {
    return $this->jsonDisplayable();
  }

}
