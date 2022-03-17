<?php

namespace CCNode;

use CCNode\Accounts\User;
use CCNode\Accounts\BoT;
use CreditCommons\Exceptions\CCFailure;
use CreditCommons\Exceptions\DoesNotExistViolation;
use CreditCommons\Requester;
use CreditCommons\Account;
use CreditCommons\AccountStoreInterface;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

/**
 * Handle requests & responses from the ledger to the accountStore.
 */
class AccountStore extends Requester implements AccountStoreInterface {

  /**
   * Accounts already retrieved.
   * @var Account[]
   */
  private $cached = [];

  function __construct($base_url) {
    parent::__construct($base_url);
    $this->options[RequestOptions::HEADERS]['Accept'] = 'application/json';
  }

  public static function create() : AccountStoreInterface {
    return new static(getConfig('account_store_url'));
  }

  /**
   * @inheritdoc
   */
  function checkCredentials(string $name, string $pass) : bool {
    try {
      $this->localRequest("creds/$name/$pass");
    }
    catch(ClientException $e) {
      // this would be a 400 error
      return FALSE;
    }
    return TRUE;
  }

  /**
   * @inheritdoc
   */
  function filter(
    int $offset = 0,
    int $limit = 10,
    bool $full = FALSE,
    bool $local= NULL,
    string $fragment = NULL
  ) : array {
    // Pointless to try to make use of the $this->cached here.
    $path = 'filter';
    if ($full) {
      $path .= '/full';
    }
    if (isset($local)) {
      // covert to a path boolean... tho shouldn't guzzle do that?
      $this->options[RequestOptions::QUERY]['local'] = $local ? 'true' : 'false';
    }
    if (isset($full)) {
      // covert to a path boolean... tho shouldn't guzzle do that?
      $this->options[RequestOptions::QUERY]['full'] = $full ? 'true' : 'false';
    }
    foreach(['fragment', 'offset', 'limit'] as $param) {
      if (isset($$param)) {
        $this->options[RequestOptions::QUERY][$param] = $$param;
      }
    }
    $results = (array)$this->localRequest($path);
    // remove the trunkward account from all filter because know about it in other
    // ways and it gets in the way of most use cases for filtering.
    $pos = array_search(getConfig('trunkward_acc_id'), $results);
    if ($pos !== FALSE) {
      unset($results[$pos]);
    }
    if ($full) {
      $results = array_map([$this, 'upcast'], $results);
    }
    return $results;
  }

  /**
   * @inheritdoc
   */
  function fetch(string $name, string $remote_path = '') : Account {
    if (!isset($this->cached[$name])) {
      $path = urlencode($name);
      try{
        $result = $this->localRequest($path);
      }
      catch (\Exception $e) {
        if ($e->getCode() == 404) {
          // N.B. the name might have been deleted because of GDPR
          throw new DoesNotExistViolation(type: 'account', id: $name);
        }
        else {
          throw new CCFailure("AccountStore returned an invalid error code looking for $name: ".$e->getCode());
        }
      }
      $result->remotePath = $remote_path;
      $this->cached[$name] = $this->upcast($result);
    }
    return $this->cached[$name];
  }

  /**
   * Get the transaction limits for all accounts.
   * @return array
   */
  function allLimits() : array {
    foreach ($this->filter(full: TRUE) as $info) {
      $limits[$info->id] = (object)['min' => $info->min, 'max' => $info->max];
    }
    return $limits;
  }

  /**
   * @inheritdoc
   */
  public function has(string $name, string $node_class = '') : bool {
    try {
      $acc = $this->fetch($name);
    }
    catch (DoesNotExistViolation $e) {
      return FALSE;
    }
    if ($acc instanceOf BoT) {
      return FALSE;
    }
    if ($node_class) {
      $class = '\CCNode\Accounts\\'.$node_class;
      return $acc instanceOf $class;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function localRequest(string $endpoint = '') {
    $client = new Client(['base_uri' => $this->baseUrl, 'timeout' => 1]);
    if (!empty($this->fields) and !isset($this->options[RequestOptions::BODY])) {
      $this->options[RequestOptions::BODY] = http_build_query($this->fields);
    }
    try{
      $response = $client->{$this->method}($endpoint, $this->options);
    }
    catch (RequestException $e) {
      if ($e->getStatusCode() == 500) {
        throw new CCFailure($e->getMessage());
      }
      throw $e;
    }
    $contents = $response->getBody()->getContents();
    $result = json_decode($contents);
    if ($contents and is_null($result)) {
      throw new CCFailure('Non-json result from account service: '.$contents);
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public static function anonAccount() : Account {
    $obj = ['id' => '<anon>', 'max' => 0, 'min' => 0, 'status' => 1];
    return User::create((object)$obj);
  }

  /**
   * Determine what Account class has been fetched and instantiate it.
   *
   * @param \stdClass $data
   * @return Account
   */
  private function upcast(\stdclass $data) : Account {
    $class = self::determineAccountClass($data, getConfig('trunkward_acc_id'));
    $this->cached[$data->id] = $class::create($data);
    return $this->cached[$data->id];
  }

  /**
   * Determine the class of the given Account, considering this node's position
   * in the ledger tree.
   *
   * @param \stdClass $data
   * @param string $BoT_acc_id
   * @return string
   */
  private static function determineAccountClass(\stdClass $data, string $BoT_acc_id = '') : string {
    global $user;
    if (!empty($data->url)) {
      $upS = $data->id == $user->id;
      $BoT = $data->id == $BoT_acc_id;
      if ($BoT and $upS) {
        $class = 'UpstreamBoT';
      }
      elseif ($BoT and !$upS) {
        $class = 'DownstreamBoT';
      }
      elseif ($upS) {
        $class = 'UpstreamBranch';
      }
      else {
        $class = 'DownstreamBranch';
      }
    }
    else {
      if ($data->admin) {
        $class = 'Admin';
      }
      else {
        $class = 'User';
      }
    }
    return 'CCNode\Accounts\\'. $class;
  }


}
