<?php

namespace CCNode\Tests;
use Slim\Psr7\Response;

/**
 * Tests the API functions of a node without touching remote nodes.
 * @todo Test transversal Errors.
 *   - IntermediateledgerViolation
 *   - HashMismatchFailure
 *   - UnavailableNodeFailure Is this testable? Maybe with an invalid url?
 *   - Try to trade directly with a Remote account.
 *
 * @todo Invalid paths currently return 404 which isn't in the spec.
 *
 */
class MultiNodeTest extends SingleNodeTest {

  function __construct() {
    parent::__construct();
    $this->nodePath = explode('/', \CCNode\getConfig('abs_path'));
    $this->setupAccountTree();
  }

  // Depends a lot on testAccountNames working
  function setupAccountTree() {
    global $local_accounts, $foreign_accounts, $remote_accounts;
    $local_user = reset($this->normalAccIds);
    // Find all the accounts we can in what is presumably a limited testing tree and group them by node
    $local_and_trunkward = $this->sendRequest("accounts/names", 200, $local_user);
    foreach ($local_and_trunkward as $path_name) {
      if (substr($path_name, -1) <> '/') {
        $all_accounts[] = $path_name;//local
      }
      else {//remote
        $this->getLeafwardAccounts($path_name, $all_accounts);
        $remote_accounts[] = $path_name;
      }
    }
    // Group the accounts
    foreach ($all_accounts as $path) {
      if ($pos = intval(strrpos($path, '/'))) {
        $node_path = substr($path, 0, $pos);
        $foreign_accounts[$node_path][] = $path;
        $foreign_accounts['all'][] = $path;
      }
      else {
        $local_accounts[] = $path;
      }
    }
    shuffle($foreign_accounts['all']);
  }

  private function getLeafwardAccounts($path_to_node, &$all_accounts) {
    $local_user = reset($this->normalAccIds);
    $results = $this->sendRequest("accounts/names/$path_to_node", 200, $local_user);

    foreach ($results as $result) {
      if (substr($result, -1) <> '/') {
        $all_accounts[] = $result;
      }
      else {
        $this->getLeafwardAccounts ($result, $all_accounts);
      }
    }
  }

  function testWorkflows() {
    parent::testWorkflows();
    // should find a way of testing that the inherited workflows combine properly.
  }

  function testBadTransactions() {
    //parent::testBadTransactions();
    global $local_accounts, $foreign_accounts, $remote_accounts;
    $admin = reset($this->adminAccIds);
    $obj = [
      'description' => 'test 3rdparty',
      'quant' => 10,
      'type' => '3rdparty',
      'metadata' => ['foo' => 'bar']
    ];
    $foreign_node = reset($foreign_accounts);
    // Try to trade with two foreign accounts
    $obj['payer'] = reset($foreign_node);
    $obj['payee'] = end($foreign_node);
    //$this->sendRequest('transaction', 'WrongAccountViolation', $admin, 'post', json_encode($obj));

    // Try to trade with a mirror account.
    $obj['payee'] = reset($local_accounts);
    $obj['payer'] = reset($remote_accounts);
echo json_encode($obj, JSON_PRETTY_PRINT);
    $this->sendRequest('transaction', 'WrongAccountViolation', $admin, 'post', json_encode($obj));
  }

  function test3rdParty() {
    parent::test3rdParty();
    global $local_accounts, $foreign_accounts, $remote_accounts;
    $admin = reset($this->adminAccIds);
    $foreign_node = reset($foreign_accounts);

    $obj = (object)[
      'payee' => end($foreign_node),
      'payer' => reset($foreign_node),
      'description' => 'test 3rdparty',
      'quant' => 10,
      'type' => '3rdparty',
      'metadata' => ['foo' => 'bar']
    ];
    // test that admin can't even do a transaction between two foreign accounts
    $this->sendRequest('transaction', 'WrongAccountViolation', $admin, 'post', json_encode($obj));
    $obj->payee = reset($foreign_node);
    $obj->payer = reset($local_accounts);
    $this->sendRequest('transaction', 201, $admin, 'post', json_encode($obj));

    // test again for good measure.
    $foreign_node = end($foreign_accounts);
    $obj->payee = end($foreign_node);
    $obj->payer = end($local_accounts);
    $this->sendRequest('transaction', 201, $admin, 'post', json_encode($obj));
  }

  // Ensure that transactions passing accross this ledger but not involving leaf
  // accounts can't be manipulated.
  // @todo Find a way to filter for these purely transversal transactions.
  function _testImmutableTransversal() {
    global $remote_accounts;
    $admin = reset($this->adminAccIds);
    foreach ($remote_accounts as $acc_id) {
      $transversal = $this->sendRequest("transaction/full?", '200', $admin);
    }
  }

  function testTransactionLifecycle() {
    //parent::testTransactionLifecycle();
    global $local_accounts, $foreign_accounts, $remote_accounts;
    $admin = reset($this->adminAccIds);
    $obj = (object)[
      'payer' => end($local_accounts),
      'payee' => $foreign_accounts['all'][array_rand($foreign_accounts['all'])],
      'description' => 'test bill',
      'quant' => 10,
      'type' => 'credit',
      'metadata' => ['foo' => 'bar']
    ];
    $tx = $this->sendRequest('transaction', 200, $obj->payer, 'post', json_encode($obj));
    $this->sendRequest("transaction/$tx->uuid/pending", 201, $obj->payer, 'patch');
  }

  function testAccountSummaries() {
    global $local_accounts, $foreign_accounts, $remote_accounts;
    parent::testAccountSummaries();
    $user1 = reset($this->normalAccIds);
    if ($this->trunkwardsId) {
      $this->sendRequest("account/summary/$this->trunkwardsId/", 200, $user1);
    }
    // test 2 random addresses (with not more than one slash in)
    $i=0;
    while ($i < 2) {
      $rel_path = next($foreign_accounts['all']);
      if (count(explode('/', $rel_path)) > 2)continue;
      $this->sendRequest("account/summary/$rel_path", 200, $user1);
      $this->sendRequest("account/limits/$rel_path", 200, $user1);
      $this->sendRequest("account/history/$rel_path", 200, $user1);
      $i++;
    }
    // get the info about all the accounts
    $node_path = key($foreign_accounts);
    next($foreign_accounts);
    $node_path = key($foreign_accounts);
    $this->sendRequest("account/limits/$node_path", 200, $user1);
    $this->sendRequest("account/summary/$node_path", 200, $user1);
  }

  function testTrunkwards() {
    if (empty($this->trunkwardsId)) {
      $this->assertEquals(1, 1);
      return;
    }
    $this->sendRequest("absolutepath", 'PermissionViolation', '');
    $nodes = $this->sendRequest("absolutepath", 200, reset($this->normalAccIds));
    $this->assertGreaterThan(1, count($nodes), 'Absolute path did not return more than one node: '.reset($nodes));
    $this->assertEquals(\CCNode\getConfig('node_name'), end($nodes), 'Absolute path does not end with the current node.');
  }

}
