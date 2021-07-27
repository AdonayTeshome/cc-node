<?php

namespace CCNode;

use CreditCommons\Exceptions\InvalidFieldsViolation;
use CreditCommons\Exceptions\DoesNotExistViolation;
use CreditCommons\Requester;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Client;
use CCNode\Accounts\Account;

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
  function fetch(string $name, bool $nameonly = FALSE) : Account {
    if ($nameonly) {
      $this->options[RequestOptions::QUERY] = ['nameonly' => 1];
    }
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
    if (!$nameonly) {
      $result = $this->upcast($result);
    }
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
    $class = Account::determineAccountClass(
      $data->id,
      $data->url??'',
      isset($orientation->upstreamAccount) ? $orientation->upstreamAccount->id : '',
      $config['bot']['account']
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
    else throw new Exception('Wrong account type');
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
          throw new \Exception('Bad characters in '.$acc_id);
        case 409:
          throw new \Exception('Duplicate account name: '.$acc_id);
        default:
          throw new \Exception('Unexpected '.$e->getCode()." result from $this->baseUrl/join: ".$e->getMessage());
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
      $response = $client->{$this->method}($endpoint, $this->options);
    }
    catch (RequestException $e) {
      if ($e->getStatusCode() == 500) {
        throw new MiscFailure(['message' => '500 Problem with AccountStore.']);
      }
      throw $e;
    }
    $contents = $response->getBody()->getContents();
    return json_decode($contents);
  }


}
