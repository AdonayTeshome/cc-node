<?php
namespace CCNode\Transaction;
use CCNode\Transaction\Entry;
use CCNode\Transaction\Transaction;
use CCNode\Accounts\Trunkward;
use CCNode\Accounts\Remote;
use CreditCommons\TransactionInterface;
use CreditCommons\TransversalTransactionTrait;
use function \CCNode\API_calls;

/**
 * Handle the sending of transactions between ledgers and hashing.
 * @todo make a new interface for this.
 */
class TransversalTransaction extends Transaction {

  use TransversalTransactionTrait;

  public $downstreamAccount;
  public $upstreamAccount;
  // Only load the trunkward account if needed
  public $trunkwardAccount = NULL;

  public function __construct(
    public string $uuid,
    public string $written,
    public string $type,
    public string $state,
    array $entries,
    public int $version,
    public string $scribe
  ) {
    global $cc_user, $cc_config;
    $this->upstreamAccount = $cc_user instanceof Remote ? $cc_user : NULL;
    $payer = $entries[0]->payer;
    $payee = $entries[0]->payee;
    // Find the downstream account
    // if there's an upstream account, then the other one, if remote is downstream
    if ($this->upstreamAccount) {
      if ($this->upstreamAccount->id == $payee->id and $payer instanceOf Remote) {
        $this->downstreamAccount = $payer; // going towards a payer branch
      }
      elseif ($this->upstreamAccount->id == $payer->id and $payee instanceOf Remote) {
        $this->downstreamAccount = $payee;// going towards a payee branch
      }
    }// with no upstream account, then any remote account is downstream
    else {
      if ($payee instanceOf Remote) {
        $this->downstreamAccount = $payee;
      }
      elseif ($payer instanceOf Remote) {
        $this->downstreamAccount = $payer;
      }
    }
    /// Set the trunkward account only if it is used.
    if ($this->upstreamAccount and $this->upstreamAccount->id == $cc_config->trunkwardAcc) {
      $this->trunkwardAccount = $this->upstreamAccount;
    }
    elseif ($this->downstreamAccount and $this->downstreamAccount->id == $cc_config->trunkwardAcc) {
      $this->trunkwardAccount = $this->downstreamAccount;
    }
    $this->upcastEntries($entries);
  }

  public static function createFromUpstream(\stdClass $data) : TransactionInterface {
    global $cc_user, $cc_config;
    if ($cc_user->id == $cc_config->trunkwardAcc){
      Trunkward::convertIncomingEntries($data->entries, $cc_user->id, $cc_config->conversionRate);
    }
    $data->scribe = $cc_user->id;
    $data->state = TransactionInterface::STATE_INITIATED;
    $transaction_class = Entry::upcastAccounts($data->entries);
    return $transaction_class::create($data);
    // N.B. This isn't saved yet.
  }

  /**
   * {@inheritDoc}
   */
  function buildValidate() : array {
    global $cc_user;
    $new_rows = parent::buildvalidate();
    if ($this->downstreamAccount) {
      $additional_remote_rows = $this->downstreamAccount->buildValidateRelayTransaction($this);
      Entry::upcastAccounts($additional_remote_rows);
      $this->upcastEntries($additional_remote_rows, TRUE);
      $new_rows = array_merge($new_rows, $additional_remote_rows);
    }
    $this->responseMode = TRUE;
    // Return only the additional entries which are also relevant to the upstream node.
    // @todo Could this be more elegant?
    if ($cc_user instanceof Remote) {
      $new_rows = array_filter(
        $this->filterFor($cc_user),
        function($e) {return $e->isAdditional();}
      );
    }
    return array_values($new_rows);
  }

  /**
   * {@inheritDoc}
   */
  public function saveNewVersion() : int {
    $id = parent::saveNewVersion();
    if ($this->version > 0) {
      $this->writeHashes($id);
    }
    return $id;
  }

  /**
   * {@inheritDoc}
   */
  function delete() {
    if ($this->state <> static::STATE_VALIDATED) {
      throw new CCFailure('Cannot delete transversal transactions.');
    }
    parent::delete();
  }

  /**
   * Send the whole transaction downstream for building.
   * Or send the entries back upstream
   *
   * To send transactions to another node
   * - filter the entries
   * - remove workflow
   *
   * @return stdClass
   */
  public function jsonSerialize() : mixed {
    $array = parent::jsonSerialize();
    if ($adjacentNode = $this->responseMode ? $this->upstreamAccount : $this->downstreamAccount) {
      $array['entries'] = $this->filterFor($adjacentNode);
    }
    // Relaying downstream
    if ($this->downstreamAccount && !$this->responseMode) {
      // Forward the whole transaction minus a few properties.
      unset($array['status'], $array['workflow'], $array['payeeHash'], $array['payerHash']);
    }
    return $array;
  }

  /**
   * Load this transaction's workflow from the local json storage, then restrict
   * the workflow as for a remote transaction.
   */
  public function getWorkflow() : \CreditCommons\Workflow {
    $workflow = parent::getWorkflow();
    if ($this->upstreamAccount instanceOf Remote) {
      // Remote accounts can ONLY be created by their authors, and only signed
      // by participants, not admins. However this contradicts
      // Workflow::getTransitions which allows admin to do anything.
      $workflow->creation->by = ['author'];
      foreach ($workflow->states as &$state) {
        foreach ($state as $target_state => $info) {
          if (empty($info->signatories)) {
            $info->signatories = ['payer', 'payee'];
          }
        }
      }
    }
    return $workflow;
  }

  /**
   * {@inheritDoc}
   */
  function changeState(string $target_state) : bool {
    if ($this->downstreamAccount) {
      API_calls($this->downstreamAccount)->transactionChangeState($this->uuid, $target_state);
    }
    $saved = parent::changeState($target_state);
    $this->responseMode = TRUE;
    return $saved;
  }

  /**
   * Return TRUE if the response is directed towards the trunk.
   *
   * @return bool
   *
   * @todo put in an interface
   */
  public function trunkwardResponse() : bool {
    if ($this->trunkwardAccount) {
      if ($this->trunkwardAccount == $this->upstreamAccount and $this->responseMode == TRUE) {
        return TRUE;
      }
      elseif ($this->trunkwardAccount == $this->downstreamAccount and $this->responseMode == FALSE) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritDoc}
   */
  public function transitions() : array {
    global $cc_user;
    // Admin permission over a transaction can only be granted when payer or payee is local
    if ($this->entries[0]->payer instanceof Remote and $this->entries[0]->payee instanceof Remote) {
      $admin = FALSE;
    }
    else {
      $admin = (bool)$cc_user->admin;
    }
    return $this->getWorkflow()->getTransitions($cc_user->id, $this, $admin);
  }
}
