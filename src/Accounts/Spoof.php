<?php

namespace CCNode\Accounts;

use CreditCommons\Exceptions\PasswordViolation;
use function CCNode\accountStore;

/**
 * An hybrid local/remote account. Use with care.
 */
class Spoof extends Remote {

  function __construct(
    string $id,
    int $min,
    int $max,
    public string $url
  ) {
    parent::__construct($id, $min, $max, $url);
  }


  static function create(\stdClass $data, string $rel_path = '') : User {
    static::validateFields($data);
    $acc = new static($data->id, $data->min, $data->max, $data->url);
    $acc->relPath = $rel_path;
    return $acc;
  }

  /**
   * {@inheritdoc}
   * A spoof is a node for certain things and not for others.
   */
  public function isNode() : bool {
    $caller = array_slice(debug_backtrace(), 1, 1);
    return substr($this->relPath, -1) == '/';
  }

  /**
   * {@inheritdoc}
   */
  function getSummary($force_local = FALSE) : \stdClass {
    return parent::getSummary(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  function getAllSummaries() : array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  function getLimits($force_local = FALSE) : \stdClass {
    return parent::getLimits(TRUE);
  }

  function getAllLimits() : array {
    // the relPath should always have a slash at the end of it.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  function getHistory(int $samples = -1) : array {
    $temp = $this->relPath;
    $this->relPath =  '';
    $result = (array)parent::getHistory($samples);
    $this->relPath =  $temp;
    return $result;
  }


  function authenticate(string $auth_string) {
    global $cc_config;
    if ($cc_config->spoofs[$this->id] != $_SERVER['REMOTE_ADDR']) {
      //local user with the wrong password
      throw new \CreditCommons\Exceptions\CCFailure($_SERVER['REMOTE_ADDR'] .' <> '.$cc_config->spoofs[$this->id]);
      throw new PasswordViolation();
    }
  }

}

