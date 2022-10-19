<?php

namespace CCNode\Transaction;

use CCNode\BlogicRequester;
use CCNode\Accounts\Remote;
use CCNode\Accounts\Branch;
use CCNode\Transaction\Entry;
use CreditCommons\Workflow;
use CreditCommons\NewTransaction;
use CreditCommons\BaseTransaction;
use CreditCommons\TransactionInterface;
use CreditCommons\Exceptions\MaxLimitViolation;
use CreditCommons\Exceptions\MinLimitViolation;
use CreditCommons\Exceptions\WorkflowViolation;
use CreditCommons\Exceptions\DoesNotExistViolation;

class Transaction extends BaseTransaction implements \JsonSerializable {
  use \CCNode\Transaction\StorageTrait;

  const REGEX_DATE = '/[0-9]{4}-[0|1]?[0-9]-[0-3][0-9]/';
  const REGEX_TIME = '/[0-2][0-9]:[0-5][0-9]:[0-5][0-9]/';

  /**
   * The full workflow object.
   * @var Workflow
   */
  protected Workflow $workflow;

  /**
   * The database ID of the transaction, (for linking to the entries table)
   * @var int
   */
  protected int $txID = 0;

  /**
   * FALSE for request, TRUE for response mode
   * @var Bool
   */
  public bool $responseMode = FALSE;

  /**
   * FALSE for request, TRUE for response mode
   * @var Bool
   */
  public string $scribe;

  /**
   * Create a new transaction from a few required fields defined upstream.
   * @param NewTransaction $new
   * @return TransactionInterface
   */
  public static function createFromNew(NewTransaction $new) : TransactionInterface {
    global $cc_user;
    $data = new \stdClass;
    $data->uuid = $new->uuid;
    $data->type = $new->type;
    $data->state = TransactionInterface::STATE_INITIATED;
    $data->version = -1;
    $data->scribe = $cc_user->id;
    $data->entries = [(object)[
      'payee' => $new->payee,
      'payer' => $new->payer,
      'description' => $new->description,
      'metadata' => $new->metadata,
      'quant' => $new->quant
    ]];
    $transaction_class = Entry::upcastAccounts($data->entries);
    return $transaction_class::create($data);
    // N.B. This isn't saved yet.
  }

  static function create(\stdClass $data) : static {
    $data->version = $data->version??-1;
    $data->written = $data->written??'';
    static::validateFields($data);
    $t = new static($data->uuid, $data->written, $data->type, $data->state, $data->entries, $data->version, $data->scribe);
    if (isset($data->txID)) {
      $t->txID = $data->txID;
    }
    return $t;
  }

  /**
   * Call the business logic, append entries.
   * Validate the transaction in its workflow's 'creation' state
   *
   * @return Entry[]
   *   Any new rows added by the business logic
   */
  function buildValidate() : array {
    global $cc_config, $cc_user;

    $workflow = $this->getWorkflow();
    if (!$workflow->active) {
      // not allowed to make new transactions with non-active workflows
      throw new DoesNotExistViolation(type: 'workflow', id: $this->type);
    }
    $desired_state = $workflow->creation->state;
    if (!$cc_user->admin and !$workflow->canTransitionToState($cc_user->id, $this, $desired_state)) {
      throw new WorkflowViolation(type: $this->type, from: $this->state, to: $desired_state);
    }
    // Add fees, etc by calling on the blogic module, either internally or via REST API
    // @todo make the same function name for both.
    if ($cc_config->blogicMod) {
      $rows = $this->callBlogic();
    }
    $this->validate();
    return $rows;
  }

  /**
   * Check the transaction doesn't transgress balance limits
   *
   * @throws MaxLimitViolation
   * @throws MinLimitViolation
   */
  function validate() {
    global $cc_config;
    $payee = $this->entries[0]->payee;
    if ($payee->max or $payee->min) {
      $acc_summary = $payee->getSummary(TRUE);
      $stats = $cc_config->validatePending ? $acc_summary->pending : $acc_summary->completed;
      $payee_diff = $this->sum($payee->id);
      $projected = $stats->balance + $payee_diff;
      if ($payee_diff > 0 and $projected > $payee->max) {
        throw new MaxLimitViolation(limit: $payee->max, projected: $projected);
      }
    }
    $payer = $this->entries[0]->payer;
    if ($payer->max or $payer->min) {
      $acc_summary = $payer->getSummary(TRUE);
      $stats = $cc_config->validatePending ? $acc_summary->pending : $acc_summary->completed;
      $payer_diff = $this->sum($payer->id);
      $projected = $stats->balance + $payer_diff;
      if ($payer_diff < 0 and $projected < $payer->min) {
        throw new MaxLimitViolation(limit: $payer->max, projected: $projected);
      }
    }
    $this->state = TransactionInterface::STATE_VALIDATED;
  }

  /**
   * Call whatever Blogic class and upcast and append any resulting rows.
   */
  private function callBlogic() : array {
    global $cc_config;
    // This feels a bit inelegant.
    $blogic_entry = $this->entries[0]->toBlogic($this->type);
    if (class_exists($cc_config->blogicMod)) {
      $class = $cc_config->blogicMod;
      $blogic_mod = new $class();
      $rows = $blogic_mod->addRows(...$blogic_entry);
    }
    elseif($cc_config->blogicMod) {// Should really test that it is a url.
      // The Blogic service will know itself which account to put fees in.
      $rows = (new BlogicRequester())->addRows(...$blogic_entry);
    }
    // The Blogic returns entries with upcast account objects
    $this->upcastEntries($rows, TRUE);
    return $rows;
  }

  /**
   * Insert the transaction for the first time
   * @return bool
   *   FALSE if the transaction is in a temporary state
   */
  function insert() : bool {
    // for first time transactions...
    $workflow = $this->getWorkflow();
    // The transaction is in 'validated' state.
    if ($workflow->creation->confirm) {
      $this->version = -1;
      $status_code = 200;
    }
    else {
      // Write the transaction immediately to its 'creation' state
      $this->state = $workflow->creation->state;
      $this->version = 0;
      $status_code = 201;
    }
    // this adds +1 to the version.
    $this->saveNewVersion();

    return $status_code;
  }

  /**
   *  Add an additional entry to the transaction.
   */
  function addEntry(Entry $entry) : void {
    $entry->additional = TRUE;
    $this->entries[] = $entry;
  }

  /**
   * @param string $target_state
   * @return bool
   *   TRUE if a new version of the transaction was saved. FALSE if the transaction was deleted (transactions in validated state only)
   * @throws WorkflowViolation
   */
  function changeState(string $target_state) : bool {
    global $cc_user;
    // If the logged in account is local, then at least one of the local accounts must be local.
    // No leaf account can manipulate transactions which only bridge this ledger.
    if ($this->entries[0]->payer instanceOf Remote and $this->entries[0]->payee instanceOf Remote and !$cc_user instanceOf Remote) {
      throw new WorkflowViolation(
        type: $this->type,
        from: $this->state,
        to: $args['dest_state'],
      );
    }
    if ($target_state == 'null' and $this->state == 'validated') {
      $this->delete();
      return FALSE;
    }

    if ($cc_user->admin or $this->getWorkflow()->canTransitionToState($cc_user->id, $this, $target_state)) {
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
  private function sum(string $acc_id) : int {
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
   * Export the transaction to json for transport.
   * - get the actions
   * - remove some properties.
   *
   * @return array
   */
  public function jsonSerialize() : mixed {
    return [
      'uuid' => $this->uuid,
      'written' => $this->written,
      'state' => $this->state,
      'type' => $this->type,
      'version' => $this->version,
      'entries' => $this->entries
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function transitions() : array {
    global $cc_user;
    return $this->getWorkflow()->getTransitions($cc_user->id, $this, $cc_user->admin);
  }

  /**
   * Load this transaction's workflow from the local json storage.
   * @todo Sort out
   */
  public function getWorkflow() : Workflow {
    global $cc_workflows;
    if (isset($cc_workflows[$this->type])) {
      return $cc_workflows[$this->type];
    }
    throw new DoesNotExistViolation(type: 'workflow', id: $this->type);
  }


  /**
   * Make entry objects from json entries with users already upcast by Entry::upcastAccounts
   * @param stdClass[] $rows
   *   Which are Entry objects flattened by json for transport.
   * @return Entry[]
   *   The created entries
   */
  public function upcastEntries(array $rows, bool $additional = FALSE) : void {
    global $cc_user;
    foreach ($rows as $row) {
      $row->isAdditional = $additional;
      $row->isPrimary = empty($this->entries);
      if (empty($row->author)) {
        $row->author = $cc_user->id;
      }
      if($row->payee instanceOf Branch and $row->payer instanceOf Branch) {
        // both accounts are leafwards, the current node is at the apex of the route.
        $create_method = ['EntryTransversal', 'create'];
      }
      elseif ($row->payee instanceOf Remote or $row->payer instanceOf Remote) {
        $create_method = ['EntryTransversal', 'create'];
      }
      else {
        $create_method = ['Entry', 'create'];
      }
      $create_method[0] = '\CCNode\Transaction\\'.$create_method[0];
      $row->isPrimary = empty($this->entries);
      $this->entries[] = $create_method($row, $this);
    }
  }

}
