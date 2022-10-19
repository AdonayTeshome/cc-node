<?php

namespace CCNode\Accounts;

/**
 * Class representing an account linked to leafwards node
 */
abstract class Branch extends Remote {

  /**
   * {@inheritDoc}
   */
  function trunkwardId() : string {
    global $cc_config;
    return "$cc_config->nodeName/$this->id/$this->relPath";
  }

  /**
   * {@inheritDoc}
   */
  function leafwardId() : string {
    return $this->relPath;
  }



}
