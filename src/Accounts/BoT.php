<?php
namespace CCNode\Accounts;

/**
 * Class representing an account corresponding to an account on another ledger
 */
class BoT extends Remote {

  function foreignId() {
    return "$this->id/". $this->relPath();
  }
}

