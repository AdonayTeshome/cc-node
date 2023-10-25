<?php

namespace CCNode;

use CCNode\Accounts\Trunkward;
use CCNode\Accounts\Branch;
use CCNode\Accounts\Remote;
use CreditCommons\Account;

/**
 * Class to track where we are in the request/response cycles, and especially
 * to determine whether to translate data for or from the trunkward node.
 */
class Orientation {

  const REQUEST = TRUE;
  const RESPONSE = FALSE;
  const TRUNKWARD = 1;
  const LEAFWARD = -1;
  const CLIENT = 0;

  /**
   * @var Account
   */
  public $downstreamAccount;
  public $upstreamAccount;
  public $trunkwardAccount = NULL;

  function __construct(
   /**
    * trunkward, leafward, client.
    * @var bool
    */
    public int|NULL $target = NULL,
    /**
     * request / response
     * @var bool
     */
    public bool $requestPhase = self::REQUEST
  ) {
  }

  /**
   * Instantiate a new Orientation object.
   *
   * @param Account $payee
   * @param Account $payer
   * @return static
   */
  static function createTransversal(Account $payee, Account $payer) : static {
    global $cc_user, $orientation, $cc_config;
    // Find the up and downstream accounts
    $upstreamAccount = ($cc_user instanceof Remote) ? $cc_user : self::CLIENT;
    if ($upstreamAccount) {
      if ($upstreamAccount->id == $payee->id and $payer instanceOf Remote) {
        $downstreamAccount = $payer; // going towards a payer branch
      }
      elseif ($upstreamAccount->id == $payer->id and $payee instanceOf Remote) {
        $downstreamAccount = $payee;// going towards a payee branch
      }
    }// with no upstream account, then any remote account is downstream
    elseif ($payee instanceOf Remote) {
      $downstreamAccount = $payee;
    }
    elseif ($payer instanceOf Remote) {
      $downstreamAccount = $payer;
    }
    else {
      $downstreamAccount = NULL;
    }
    // The initial target is the downstream account
    $target = NULL;
    if ($downstreamAccount instanceOf Trunkward) {
      $target = SELF::TRUNKWARD;
    }
    elseif ($downstreamAccount instanceOf Branch) {
      $target = SELF::LEAFWARD;
    }
    $orientation = new static($target, self::REQUEST);
    $orientation->upstreamAccount = $upstreamAccount;
    $orientation->downstreamAccount = $downstreamAccount;
    // Set the trunkward account, if used
    if ($upstreamAccount and $upstreamAccount->id == $cc_config->trunkwardAcc) {
      $orientation->trunkwardAccount = $upstreamAccount;
    }
    elseif ($orientation->downstreamAccount and $orientation->downstreamAccount->id == $cc_config->trunkwardAcc) {
      $orientation->trunkwardAccount = $downstreamAccount;
    }
    return $orientation;
  }

  static function createLocal() {
    global $orientation;
    $orientation = new static(self::CLIENT, self::RESPONSE);
    return $orientation;
  }

  /**
   * Flip the orientation from request to response mode.
   *
   * @return void
   * @throws CCFailure
   */
  function responseMode() : void {
    $this->requestPhase = self::RESPONSE;
    if (!is_object($this->upstreamAccount) and $this->upstreamAccount == self::CLIENT) {
      $this->target = self::CLIENT;
    }
    elseif ($this->upstreamAccount instanceOf Trunkward) {
      $this->target = self::TRUNKWARD;
    }
    elseif ($this->upstreamAccount instanceOf Branch) {
      $this->target = self::LEAFWARD;
    }
    else throw new CCFailure('Failed to switch to Orientation::responseMode(): '.print_r($this->upstreamAccount, 1));
  }

  /**
   * Get the account where we are likely sending to, either as request or response.
   *
   * @return Account|NULL
   */
  function targetNode() : Account|NULL {
    if ($this->requestPhase) return $this->downstreamAccount;
    else return $this->upstreamAccount;
  }
}