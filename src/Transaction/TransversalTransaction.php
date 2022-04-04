<?php
namespace CCNode\Transaction;
use CCNode\Transaction\Entry;
use CCNode\Transaction\Transaction;
use CCNode\Accounts\Remote;
use function \CCNode\getConfig;
use function \CCNode\API_calls;

/**
 * Handle the sending of transactions between ledgers and hashing.
 */
class TransversalTransaction extends Transaction {

  public $downstreamAccount;
  public $upstreamAccount;
  // Only load the trunkwards account if we need it.
  public $trunkwardAccount = NULL;


  /**
   * FALSE for request, TRUE for response mode
   * @var Bool
   */
  public bool $responseMode = FALSE;

  /**
   * Create a NEW transaction on this ledger to correspond with one relayed from upstream or downstream
   *
   * @param stdClass $data
   *   $entries should be already prepared Entry[]
   *
   * @return \Transaction
   */
  public static function create(\stdClass $data) : static {
    global $user;
\CCnode\debug($data);
    $tx = parent::create($data);
\CCnode\debug('TransversalTransaction::Create()');

    $tx->upstreamAccount = $user instanceof Remote ? $user : NULL;
    $payer = $tx->entries[0]->payer;
    $payee = $tx->entries[0]->payee;
    // Find the downstream account
    $trunkward_name = getConfig('trunkward_acc_id');
    // if there's an upstream account, then the other one, if remote is downstream
    if ($tx->upstreamAccount) {
      if ($tx->upstreamAccount->id == $payee->id and $payer instanceOf Remote) {
        $tx->downstreamAccount = $payer; // going towards a payer branch
      }
      elseif ($tx->upstreamAccount->id == $payer->id and $payee instanceOf Remote) {
        $tx->downstreamAccount = $payee;// going towards a payee branch
      }
    }// with no upstream account, then any remote account is downstream
    else {
      if ($payee instanceOf Remote) {
        $tx->downstreamAccount = $payee;
      }
      elseif ($payer instanceOf Remote) {
        $tx->downstreamAccount = $payee;
      }
    }
    /// Set the trunkwards account only if it is used.
    if ($tx->upstreamAccount and $tx->upstreamAccount->id == $trunkward_name) {
      $tx->trunkwardAccount = $tx->upstreamAccount;
    }
    elseif ($tx->downstreamAccount and $tx->downstreamAccount->id == $trunkward_name) {
      $tx->trunkwardAccount = $tx->downstreamAccount;
    }
    return $tx;
  }

  /**
   * {@inheritDoc}
   */
  public function saveNewVersion() : int {
    global $user;
    $id = parent::saveNewVersion();
    $this->writeHashes($id);
    return $id;
  }


  /**
   * Filter the entries for those that pertain to a certain node.
   * Make a clone of the transaction with only the entries shared with an
   * adjacent ledger.
   *
   * @param Remote $account
   */
  public function filterFor(Remote $account) : array {
    // Filter entries for the appropriate adjacent ledger
    // If this works we can delete all the TransversalEntry Classes.
    $remote_name = $account->id;
    foreach ($this->entries as $e) {
      if ($e->payee->id == $remote_name or $e->payer->id == $remote_name) {
        $entries[] = $e;
        //\CCNode\debug("Selected $remote_name row with users ".$e->payee->id. ' and '.$e->payer->id);
      }
     // else \CCNode\debug("Not selected $remote_name row with users ".$e->payee->id. ' and '.$e->payer->id);
    }
    return $entries;
  }

  /**
   * Produce a hash of all the entries and transaction data in an easily repeatable way.
   * @param Remote $account
   * @param Entry[] $entries
   * @return string
   */
  protected function getHash(Remote $account, array $entries) : string {
    foreach ($entries as $entry) {
      //we also need the trunkward payer and payee acocunt names
      $rows[] = abs($entry->quant).'|'.$entry->description;
    }
    $string = join('|', [
      $account->getLastHash(),
      $this->uuid,
      $this->version,
      join('|', $rows)
    ]);
    \CCNode\debug($string);
    \CCNode\debug(md5($string));
    return md5($string);
  }

  /**
   * Send the whole transaction downstream for building.
   * Or send the entries back upstream
   *
   * To send transactions to another node
   * - filter the entries
   * - remove workflow
   * - remove actions
   *
   * @return stdClass
   */
  public function jsonSerialize() : array {
    global $user;
    $array = parent::jsonSerialize();
    if ($adjacentNode = $this->responseMode ? $this->upstreamAccount : $this->downstreamAccount) {
      $array['entries'] = $this->filterFor($adjacentNode);
    }

    // relaying downstream
    if ($this->downstreamAccount && !$this->responseMode) {
      // Forward the whole transaction minus a few properties.
      unset($array['status'], $array['workflow'], $array['payeeHash'], $array['payerHash'], $array['transitions']);
    }
    return $array;
  }

  /**
   * {@inheritDoc}
   */
  function buildValidate() : void {
    parent::buildvalidate();
    if ($this->downstreamAccount) {
      $rows = API_calls($this->downstreamAccount)->buildValidateRelayTransaction($this);
      Entry::upcastAccounts($rows);
      \CCNode\debug('Rows added by '.$this->downstreamAccount->id);
      \CCNode\debug($rows);
      $this->upcastEntries($rows, $this->downstreamAccount, TRUE);
    }
  }

  /**
   * Load this transaction's workflow from the local json storage.
   * @todo Sort out
   */
  protected function getWorkflow() : \CreditCommons\Workflow {
    $workflow = parent::getWorkflow();
    if ($this->upstreamAccount instanceOf Remote) {
      // Would have nice to cache this
      $workflow->creation->by = ['author'];// This is tautological, exactly what we need actually.
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
  function changeState(string $target_state) {
    if ($this->downstreamAccount) {
      API_calls($this->downstreamAccount)->transactionChangeState($this->uuid, $target_state);
    }
    parent::changeState($target_state);
    $this->responseMode = TRUE;
  }


  /**
   * Functions to inform jsonSerializing
   * @return bool
   */
  public function isGoingTrunkwards() : bool {
    return ($this->upstreamAccount and $this->responseMode == TRUE)
      or
    ($this->trunkwardAccount and $this->responseMode == FALSE);
  }
  public function isFromTrunkwards() : bool {
    return ($this->upstreamAccount and $this->responseMode == FALSE)
      or
    ($this->trunkwardAccount and $this->responseMode == TRUE);
  }

}
