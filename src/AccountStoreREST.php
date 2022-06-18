<?php

namespace CCNode;

use CCNode\Accounts\User;
use CreditCommons\Exceptions\CCFailure;
use CreditCommons\Exceptions\DoesNotExistViolation;
use CreditCommons\Requester;
use CreditCommons\Account;
use CreditCommons\AccountStoreInterface;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;

/**
 * Handle requests & responses from the ledger to the accountStore.
 */
class AccountStoreRest extends Requester implements AccountStoreInterface {

  private $exists = [];

  /**
   * Accounts already retrieved.
   * @var Account[]
   */
  private $cached = [];
  private $trunkwardAcc;

  function __construct() {
    global $config;
    parent::__construct($config->accountStore);
    $this->options[RequestOptions::HEADERS]['Accept'] = 'application/json';
    $this->trunkwardAcc = $config->trunkwardAcc;
  }

  /**
   * {@inheritDoc}
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
   * {@inheritDoc}
   */
  function filter(
    string $fragment = NULL,
    bool $local = NULL,
    bool $admin = NULL,
    int $limit = 10,
    int $offset = 0,
    bool $full = TRUE
  ) : array {
    global $config;
    if ($config->devMode) {
      // Because phpunit mode makes lots of requests with the same object.
      $this->options[RequestOptions::QUERY] = [];//
    }
    if (!is_null($fragment)) {
      $this->options[RequestOptions::QUERY]['fragment'] = $fragment;
    }
    if (!is_null($local)) {
      // covert to a path boolean... tho shouldn't guzzle do that?
      $this->options[RequestOptions::QUERY]['local'] = $local ? 'true' : 'false';
    }
    if (!is_null($admin)) {
      // covert to a path boolean... tho shouldn't guzzle do that?
      $this->options[RequestOptions::QUERY]['admin'] = $admin ? 'true' : 'false';
    }
    if ($limit) {
      $this->options[RequestOptions::QUERY]['limit'] = $limit;
    }
    if ($offset) {
      $this->options[RequestOptions::QUERY]['offset'] = $offset;
    }
    $results = (array)$this->localRequest('filter');
    if ($full) {
      $results = (array)$this->localRequest('filter/full');
      $results = array_map([$this, 'upcast'], $results);
    }
    else {
      $results = (array)$this->localRequest('filter');
    }
    return $results;
  }

  /**
   * {@inheritDoc}
   */
  function fetch(string $name) : Account {
    $path = urlencode($name);
    try{
      $result = $this->localRequest($path);
    }
    catch (ClientException $e) {
      if ($e->getCode() == 404) {
        // N.B. the name might have been deleted because of GDPR
        throw new DoesNotExistViolation(type: 'account', id: $name);
      }
    }
    catch (\Exception $e) {
      throw new CCFailure("AccountStore returned a ".$e->getCode() ." from $name: ".$e->getMessage());
    }
    return $this->upcast($result);
  }

  /**
   * Get the transaction limits for all accounts (except trunkward)
   * @return array
   */
  function allLimits() : array {
    $limits = [];
    foreach ($this->filter() as $info) {
      $limits[$info->id] = (object)['min' => $info->min, 'max' => $info->max];
    }
    return $limits;
  }

  /**
   * {@inheritDoc}
   */
  public function has(string $name) : bool {
    if ((!in_array($name, $this->exists))) {
      try {
        $this->method = 'HEAD';
        $this->localRequest($name);
        $this->method = 'GET';
      }
      catch (RequestException $e) {
        $this->method = 'GET';
        return FALSE;
      }
      $this->exists[] = $name;
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
      if ($e->getCode() == 500) {
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
    $obj = ['id' => '-anon-', 'max' => 0, 'min' => 0, 'status' => 1];
    return User::create((object)$obj);
  }

  /**
   * Determine what Account class has been fetched and instantiate it.
   *
   * @param \stdClass $data
   * @return Account
   */
  private function upcast(\stdclass $data) : Account {
    $class = self::determineAccountClass($data);
    $this->cached[$data->id] = $class::create($data);
    return $this->cached[$data->id];
  }

  /**
   * Determine the class of the given Account, considering this node's position
   * in the ledger tree.
   *
   * @param \stdClass $data
   * @return string
   */
  private static function determineAccountClass(\stdClass $data) : string {
    global $user, $config;
    if (!empty($data->url)) {
      $upS = $user ? ($data->id == $user->id) : TRUE;
      $trunkward = $data->id == $config->trunkwardAcc;
      if ($trunkward and $upS) {
        $class = 'UpstreamTrunkward';
      }
      elseif ($trunkward and !$upS) {
        $class = 'DownstreamTrunkward';
      }
      elseif ($upS) {
        $class = 'UpstreamBranch';
      }
      else {
        $class = 'DownstreamBranch';
      }
    }
    else {
      $class = $data->admin ? 'Admin' : 'User';
    }
    return 'CCNode\Accounts\\'. $class;
  }

}
