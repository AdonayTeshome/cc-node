<?php

namespace CCNode\Accounts;

use CCNode\Accounts\Remote;
use CreditCommons\RestAPI;

/**
 * Class representing a remote account, which authorises using its latest hash.
 */
interface RemoteAccountInterface {

  /**
   * Get the last hash pertaining to this account.
   *
   * @return array
   */
  function getLastHash() : string;

  /**
   * @return RestAPI
   *   Connection to the remote node
   */
  public function API() : RestAPI;

  /**
   * @return string
   *   'ok' or the class name of the error
   * @throws
   */
  public function handshake();

  /**
   * @return \stdClass
   *   The account Summary, not upcast.
   *
   * @todo this function returns a slightly different format on branchwards and trunkwards accounts.
   */
  function getAccountSummary($rel_path = '') : \stdClass;


  /**
   * @param string $rel_path_to_node
   * @return array
   */
  function getAccountSummaries($rel_path_to_node = '') : array;

  /**
   * @param string $rel_path_to_node
   * @return array
   */
  function getAllLimits($rel_path_to_node = '') : array;

  /**
   * @param string $rel_path
   * @return \stdClass
   */
  function getLimits($rel_path = '') : \stdClass;

  /**
   * @param string $fragment
   * @return string[]
   */
  function autocomplete(string $fragment) : array;

  /**
   * @param int $samples
   * @param string $rel_path
   * @return array
   */
  function getHistory(int $samples = -1, string $rel_path = '') : array;

  /**
   * Get the relative address of the onward account.
   * @return string
   */
  function onwardAccount() : string;

}

