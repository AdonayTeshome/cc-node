<?php
namespace CCNode\Transaction;

use CCNode\Transaction\Entry;
use CCNode\Transaction\Transaction;
use CCNode\Accounts\Remote;
use CCNode\Accounts\RemoteAccountInterface;
use CreditCommons\TransversalTransactionTrait;
use function \CCNode\API_calls;

/**
 * Handle the sending of transactions between ledgers and hashing.
 * @todo make a new interface for this.
 */
class TransversalTransaction extends Transaction {
  use TransversalTransactionTrait;

  public function __construct(
    public string $uuid,
    public string $type,
    public string $state,
    /** @var Entry[] $entries */
    public array $entries,
    public string $written,
    public int $version
  ) {
    global $cc_user, $cc_config;
    $entries[0]->isPrimary = TRUE;
  }

  /**
   * {@inheritDoc}
   */
  function buildValidate() : array {
    global $cc_user, $orientation;
    $new_local_rows = parent::buildvalidate();
    if ($orientation->downstreamAccount) {
      $new_remote_rows = $orientation->downstreamAccount->relayTransaction($this);

      static::upcastEntries($new_remote_rows, TRUE);
      $this->entries = array_merge($this->entries, $new_remote_rows);
    }
    $orientation->responseMode();
    // Entries have been added to the transaction, but now return the local and
    // remote additional entries which concern the upstream node.
    // @todo this should go somewhere a bit closer to the response generation.
    if ($cc_user instanceof Remote) {
      $new_local_rows = array_filter(
        $this->filterFor($cc_user->id),
        function($e) {return !$e->isPrimary;}
      );
    }
    return array_values($new_local_rows);
  }

  /**
   * {@inheritDoc}
   */
  public function saveNewVersion() : int {
    $id = parent::saveNewVersion();
    if ($this->version > 0) {
      if ($this->entries[0]->payee instanceOf RemoteAccountInterface) {
        $this->entries[0]->payee->storeHash($this);
      }
      if ($this->entries[0]->payer instanceOf RemoteAccountInterface) {
        $this->entries[0]->payer->storeHash($this);
      }
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
    global $orientation;
    $orig_entries = $this->entries;
    if ($adjacentNode = $orientation->targetNode()) {
      /** @var CreditCommons\Account $adjacentNode */
      $this->entries = $this->filterFor($adjacentNode->id);
    }
    $array = parent::jsonSerialize();
    $this->entries = $orig_entries;
    unset($array['status'], $array['workflow'], $array['created'], $array['version'], $array['state']);
    return $array;
  }

  /**
   * Load this transaction's workflow from the local json storage, then restrict
   * the workflow as for a remote transaction.
   */
  public function getWorkflow() : \CreditCommons\Workflow {
    global $orientation;
    $workflow = parent::getWorkflow();
    if ($orientation->upstreamAccount instanceOf Remote) {
      // Normally a payer or payee might not be allows to do a transition, but
      // admin might be allowed.
      // A transaction triggered by an upstream admin appears on a remote node as
      // being triggered by a payer or payee.
      // So we have to assume the transaction was triggered by an authorised user
      // in that exchange.
      // The local workflow then, must permit any existing state changes if the transition is coming from upstrea
      // This seems to be the best place to put this logic.
      foreach ($workflow->states as &$state) {
        foreach ($state as $target_state => $info) {
          if (empty($info->actors)) {
            $info->actors = ['payer', 'payee'];
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
    global $orientation;
    if ($orientation->downstreamAccount) {
      API_calls($orientation->downstreamAccount)->transactionChangeState($this->uuid, $target_state);
    }
    $saved = parent::changeState($target_state);
    $orientation->responseMode();
    return $saved;
  }

}
