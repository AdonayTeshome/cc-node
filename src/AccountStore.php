<?php

namespace CCNode;

use CreditCommons\Exceptions\InvalidFieldsViolation;
use CreditCommons\Exceptions\CCFailure;
use CreditCommons\Exceptions\DoesNotExistViolation;
use CreditCommons\Requester;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Client;
use CreditCommons\Account;

/**
 * Handle requests & responses from the ledger to the accountStore.
 */
class AccountStore extends Requester {

  function __construct($base_url) {
    parent::__construct($base_url);
    $this->options[RequestOptions::HEADERS]['Accept'] = 'application/json';
  }

  function __invoke() {
    return new static();
  }


  /**
   * Filter on the account names
   *
   * @param array $filters
   *   possible keys are nameonly, status, local, chars
   * @return array
   *   CreditCommons\Account[] or string[]
   */
  function filter(array $filters = []) : array {
    $valid = ['nameonly', 'status', 'local', 'chars'];
    $this->options[RequestOptions::QUERY] = array_intersect_key($filters, array_flip($valid));
    $results = $this->localRequest('filter');
    if (!empty($filters['nameonly'])) {
      $return = $results;
    }
    elseif ($results) {
      foreach ($results as $res) {
        $return[] = $this->upcast($res);
      }
    }
    else {
      $return = [];
    }
    return $return;
  }

  /**
   * Get an account
   *
   * Use this if you know the account exists.
   *
   * @param string $name
   * @param string $nameonly
   * @return stdClass|string
   *   The account object
   */
  function fetch(string $name) : Account {
    try{
      $result = $this->localRequest('fetch/'.urlencode($name));
      if (!$result){echo 'no result from fetch/'.urlencode($name);die();}
    }
    catch (\Exception $e) {
      if ($e->getCode() == 404) {
        // N.B. the name might have been deleted because of GDPR
        throw new DoesNotExistViolation(['type' => 'account', 'id' => $name]);
      }
      else {
        print_r($e->getMessage());
        die($e->getCode() .' Unknown response from AccountStore fetch/'.urlencode($name));
      }
    }
    $result = $this->upcast($result);
    return $result;
  }

  /**
   * Determine what Account class has been fetched and instantiate it.
   *
   * @global type $orientation
   * @global type $config
   * @param \stdClass $data
   * @return Account
   */
  private function upcast(\stdClass $data) : Account {
    global $orientation, $config;
    $class = self::determineAccountClass(
      $data->id,
      $data->url??'',
      isset($orientation->upstreamAccount) ? $orientation->upstreamAccount->id : '',
      $config['bot']['acc_id']
    );
    return new $class($data);
  }


  /**
   *
   * @param string $type
   * @param string $acc_id
   * @param array $fields
   *   The fields overriding the defaults, including url for node and key for users
   * @return Account
   * @throws \Exception
   *
   * @note this is not part of the CreditCommons API
   */
  function join(string $type, string $acc_id, array $fields) {
    if ($type == 'node') {
      $this->addField('url', $fields['url']);
      unset($fields['url']);
    }
    elseif ($type == 'user') {
      $this->addField('key', $fields['key']);
      unset($fields['key']);
    }
    else {
      throw new Exception('Wrong account type');
    }
    foreach ($fields as $key => $val) {
      $this->addField($key, $val);
    }
    try {
      $this->setMethod('post')
        ->addField('id', urlencode($acc_id))
        ->localRequest('join/'.$type);
    }
    catch (\Exception $e) {
      switch ($e->getCode()) {
        case 400:
          throw new BadCharactersViolation($acc_id);
        case 404:
          throw new DoesNotExistViolation(['id' => $acc_id, 'type' => 'account']);
        default:
          throw new CCFailure(['message' => 'Unexpected '.$e->getCode()." result from $this->baseUrl/join: ".$e->getMessage()]);
      }
    }
  }

  /**
   * Get the account including the overridden values.
   * @param string $acc_id
   * @return Account
   * @throws DoesNotExistViolation
   * @throws CCFailure
   *
   * @todo refactor this into fetch()
   */
  function getOverriden(string $acc_id) : Account {
    $this->options[RequestOptions::QUERY] = ['view_mode' => 'override'];
    try {
      //return $this->localRequest("override/$acc_id");
      $result =  $this->localRequest('fetch/'.urlencode($acc_id));
      return $this->upcast($result);
    }
    catch (\Exception $e) {
      switch ($e->getCode()) {
        case 404:
          throw new DoesNotExistViolation(['id' => $acc_id, 'type' => 'account']);
       default:
          throw new CCFailure(['message' => 'Unexpected '.$e->getCode()." result from $this->baseUrl/override/$acc_id: ".$e->getMessage()]);
      }
    }
  }

  /**
   * Override account defaults.
   *
   * @param string $acc_id
   * @param array $vals
   */
 function override(string $acc_id, array $vals) : void {
    $this->fields = $vals;
    try {
      $this->setMethod('patch')
        ->localRequest('override/'.$acc_id);
    }
    catch (\Exception $e) {
      switch($e->getCode()) {
        case 400:
          throw new InvalidFieldsViolation(['fields' => $result]);
        case 404:
          throw new DoesNotExistViolation(['type' => 'account', 'id' => $acc_id]);
        default:
          throw new \Exception('Unexpected '.$e->getCode()." result from $this->baseUrl/override/$acc_id");
      }
    }
  }

  /**
   * Add a field to the request body.
   * @param string $key
   * @param type $value
   * @return $this
   */
  protected function addField(string $key, $value) {
    $this->fields[$key] = $value;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  protected function localRequest(string $endpoint = '') {
    $client = new Client(['base_uri' => $this->baseUrl, 'timeout' => 1]);
    if (!empty($this->fields) and !isset($this->options[RequestOptions::BODY])) {
      $this->options[RequestOptions::BODY] = http_build_query($this->fields);
    }
    try{
//echo strtoupper($this->method) ." $this->baseUrl/$endpoint".print_r($this->options, 1);
      $response = $client->{$this->method}($endpoint, $this->options);
    }
    catch (RequestException $e) {
      if ($e->getStatusCode() == 500) {
        throw new CCFailure(['message' => $e->getMessage()]);
      }
      throw $e;
    }
    $contents = $response->getBody()->getContents();
    return json_decode($contents);
  }

  /**
   * @staticvar array $loadedAccounts
   * @param string $id
   * @return \Creditcommons\Account
   */
  function load(string $id = '') : \CreditCommons\Account {
    global $loadedAccounts;
    if (!isset($loadedAccounts[$id])) {
      if ($id and $acc = $this->fetch($id, FALSE)) {
        $loadedAccounts[$id] = $acc;
      }
      else {
        $dummy = (object)['id' => '', 'created' => 0];// these are both required fields
        $loadedAccounts[$id] = new Accounts\User($dummy);
      }
    }
    return $loadedAccounts[$id];
  }


  /**
   * Resolve to an account on the current node.
   * @return Account
   * @param bool $existing
   *   TRUE if the transaction has already been written, and thus we know the
   *   accounts exist. Unknown accounts either resolved to the BoT account or
   *   throw an exception
   */
  public function resolveAddress(string $given_path, bool $existing) : Account {
    global $orientation, $config;
    // if its one name and it exists on this ledger then good.
    $parts = explode('/', $given_path);
    if (count($parts) == 1) {
      if ($pol = $this->load($given_path)) {
        return $pol;
      }
      throw new DoesNotExistViolation(['type' => 'account', 'id' => $given_path]);
    }

    // A branchwards account, including the local node name
    $pos = array_search($config['node_name'], $parts);
    if ($pos !== FALSE and $branch_name = $parts[$pos+1]) {
      try {
        return $this->load($branch_name);
      }
      catch (DoesNotExistViolation $e) {}
    }
    // A branchwards or trunkwards account, starting with the account name on the local node
    $branch_name = reset($parts);
    try {
      return $this->load($branch_name);
    }
    catch (DoesNotExistViolation $e) {}

    // Now the path is either trunkwards, or invalid.
    if ($config['bot']['acc_id']) {
      $trunkwardsAccount = $this->load($config['bot']['acc_id'], TRUE);
      if ($existing) {
        return $trunkwardsAccount;
      }
      if ($orientation->isUpstreamBranch()) {
        return $trunkwardsAccount;
      }
    }
    throw new DoesNotExistViolation(['type' => 'account', 'id' => $given_path]);
  }


  /**
   * Determine the class of the given Account, considering this node's position
   * in the ledger tree.
   *
   * @param string $acc_id
   * @param string $account_url
   * @param string $upstream_acc_id
   * @param string $BoT_acc_id
   * @return string
   */
  static function determineAccountClass(string $acc_id, string $account_url = '', string $upstream_acc_id = '', string $BoT_acc_id = '') : string {
    if ($account_url) {
      $BoT = $acc_id == $BoT_acc_id;
      $upS = $acc_id == $upstream_acc_id;
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
      $class = 'User';
    }
    return 'CCNode\Accounts\\'. $class;

  }



}

