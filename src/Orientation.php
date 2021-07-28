<?php

namespace CCNode;

use CreditCommons\RestAPI;
use CCNode\Account;


/**
 * Handles everything pertaining to the position of the ledger in the tree.
 */
class Orientation {

  public $downstreamAccount;
  public $upstreamAccount;
  public $localRequest;
  private $trunkwardsAccountName;
  private $trunkwardsAccount;

  /**
   * FALSE for request, TRUE for response mode
   * @var Bool
   */
  public $esponseMode;

  function __construct(AccountRemote $account = NULL) {
    global $config;
    $this->esponseMode = 0;
    $this->upstreamAccount = $account;
    $this->trunkwardsAccountName = $config['bot']['acc_id'];
    if ($this->trunkwardsAccountName and $this->upstreamAccount) {
      if ($this->trunkwardsAccountName == $this->upstreamAccount->id) {
        $this->trunkwardsAccount = $this->upstreamAccount;
      }
    }
  }


  /**
   * Any remote account which isn't the upstreamAccount is marked as the downstream account
   */
  function addAccount(Account $acc) : void {
    if ($acc instanceOf AccountRemote) {
      if (!$this->upstreamAccount or $acc->id != $this->upstreamAccount->id) {
        $this->downstreamAccount = $acc;
        if ($this->trunkwardsAccountName and $this->trunkwardsAccountName == $acc->id) {
          $this->trunkwardsAccount = $this->upstreamAccount;
        }
      }
    }
  }

  function getDownstreamRequester() {
    if ($this->downstreamAccount) {
      return new RestAPI($this->downstreamAccount->url);
    }
  }


  function getTrunkwardsAccount() {
    global $config;
    if (!$this->trunkwardsAccount and $this->trunkwardsAccountName) {
      include_transversal_classes();
      // Load this account ensuring it is the AccountRemote class by naming it
      $id = $this->trunkwardsAccountName . '/'.$config['node_name'];
      $account = load_account($this->trunkwardsAccountName);
      $this->trunkwardsAccount = new AccountBoT($account, $id);
    }
    return $this->trunkwardsAccount;
  }

  function orientToRoot() : bool {
    if ($this->trunkwardsAccountName) {
      $this->trunkwardsAccount = $this->getTrunkwardsAccount();
      $this->downstreamAccount = $this->trunkwardsAccount;
    }
    return isset($this->trunkwardsAccount);
  }

  function isUpstreamBranch() {
    if ($this->trunkwardsAccountName) {
      if ($ups = $this->upstreamAccount) {
        if ($ups->id <> $this->trunkwardsAccountName)
          return TRUE;
      }
      else {
        return TRUE;
      }
    }
  }

  /**
   * Ledger orientation functions. Used for converting transactions to send.
   */
  // return TRUE or FALSE
  function goingDownstream() : bool {
    return $this->downstreamAccount && !$this->responseMode && !$this->localRequest;
  }
  function goingUpstream() : bool {
    return $this->upstreamAccount && $this->responseMode && !$this->localRequest;
  }

  // return TRUE, FALSE
  function goingRootwards() {
    return $this->trunkwardsAccount and (
      $this->downstreamAccount == $this->trunkwardsAccount && !$this->responseMode
      or
      $this->upstreamAccount == $this->trunkwardsAccount && $this->responseMode
    ) && !$this->localRequest;
  }

  function upstreamIsRootwards() : bool {
    return $this->trunkwardsAccountName == $this->upstreamAccount->id;
  }


  function adjacentAccount() {
    if (!$this->responseMode) {
      return $this->downstreamAccount;
    }
    else{
      return $this->upstreamAccount ?? 'client';
    }
  }

  /**
   * Check that all the remote nodes are online and the ratchets match
   * @return array
   *   Linked nodes keyed by response_code.
   */
  function handshake() : array {
    global $config;
    $results = [];
    if ($this->upstreamAccount) {
      //return the name of this node back upstream
      header('Node-name: '.$config['node_name']);
    }
    else {
      $active_policies = AccountStore()->filter(['status' => 1]);
      foreach ($active_policies as $account) {
        if (!empty($account->url)) {
          //Make sure we load the remote version by giving a path longer than 1 part.
          $ledgerAccount = Account::create($config['node_name']."/$account->id");
          list($code) = $this->getDownstreamRequester()->handshake();
          $results[$code][] = $account->id;
        }
      }
    }
    return $results;
  }

}
