<?php

namespace CCNode\Accounts;

use CreditCommons\Exceptions\PasswordViolation;
use function CCNode\accountStore;
use CreditCommons\NodeRequester;

/**
 * An hybrid local/remote account. Use with care.
 */
class Spoof extends Branch {

  function __construct(
    string $id,
    int $min,
    int $max,
    public string $url,
    string $key
  ) {
    $this->key = $key; // Why isn't this automatic in php8?
    parent::__construct($id, $min, $max, $url);
  }


  static function create(\stdClass $data, string $rel_path = '') : User {
    static::validateFields($data);
    $acc = new static($data->id, $data->min, $data->max, $data->url, $data->key);
    $acc->relPath = $rel_path;
    return $acc;
  }

  /**
   * {@inheritdoc}
   */
  protected function API() : NodeRequester {
    global $cc_config;
    return new NodeRequester($this->url, $cc_config->nodeName, $this->key);
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
    if (!accountStore()->compareAuthkey($this->id, $auth_string)) {
      //local user with the wrong password
      throw new PasswordViolation(key: $auth_string);
    }
  }

}

