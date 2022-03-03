<?php

namespace CCNode\Tests;
// the testing script is calling this from the main application root.
chdir(__DIR__.'/../AccountStore');

/**
 * Test class for the AccountStore service.
 *
 * @note Query args don't work. Must wait for an answer to https://github.com/Nyholm/psr7/issues/181
 */
class AccountStoreTest extends TestBase  {

  const SLIM_PATH = 'AccountStore/slimapp.php';
  const API_FILE_PATH = 'AccountStore/accountstore.openapi.yml';

  function __construct() {
    parent::__construct();
    $this->loadAccounts('');
  }

  public static function setUpBeforeClass(): void {
    global $config;
    $config = parse_ini_file(__DIR__.'/../node.ini');
    $node_name = $config['node_name'];
  }

  function testLogin() {
    $name = reset($this->normalAccIds);
    $pass = $this->passwords[$name];
    $this->sendRequest("creds/$name/z!<", 400);
    $this->sendRequest("creds/$name/$pass", 200);
  }

  function testFilterName() {
    $this->filterTest('', array_merge($this->normalAccIds, $this->branchAccIds, $this->adminAccIds));
    $this->filterTest('local=true', array_merge($this->normalAccIds, $this->adminAccIds));
    $this->filterTest('status=false', $this->blockedAccIds);
    $char = substr(reset($this->normalAccIds), 0, 1);
    $expected = array_filter(
      array_keys($this->rawAccounts),
      function ($acc) use ($char) {return stripos($acc, $char) !== FALSE;}
    );
    $this->filterTest("chars=$char", $expected);
  }

  function testGetAccount() {
    $name = key($this->rawAccounts);
    $this->sendRequest("$name", 200);
  }


  function filterTest($queryString, $expected) {
    $names = $this->sendRequest("filter?$queryString", 200);
    $objs = $this->sendRequest("filter/full?$queryString", 200);
    $names_full = array_map(function ($a){return $a->id; }, $objs);

    sort ($names);
    sort ($names_full);
    sort ($expected);
    $this->assertEquals($expected, $names);
    $this->assertEquals($expected, $names_full);
  }

}
