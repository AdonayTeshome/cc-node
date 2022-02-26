<?php

namespace CCNode\Tests;
// the testing script is calling this from the main application root.
chdir(__DIR__.'/../AccountStore');

/**
 * Test class for the AccountStore service.
 *
 * @note Query args don't work. Must wait for an answer to https://github.com/Nyholm/psr7/issues/181
 */
class AccountStore extends TestBase  {

  const SLIM_PATH = 'AccountStore/slimapp.php';
  const API_FILE_PATH = 'AccountStore/accountstore.openapi.yml';


  public static function setUpBeforeClass(): void {
    global $config;
    $config = parse_ini_file(__DIR__.'/../node.ini');
    $node_name = $config['node_name'];
  }

  function testFilter() {
    $this->sendRequest('filter', 200);
    $this->assertEquals(1, 1);
  }

  function testFilterFull() {
    $result = $this->sendRequest('filter/full', 200);
    $this->sendRequest(reset($result)->id, 200);
    $this->assertEquals(1, 1);
  }


}
