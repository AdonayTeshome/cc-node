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
    global $local_accounts, $remote_accounts;
    $local_user = reset($this->normalAccIds);
    // Find all the accounts we can in what is presumably a limited testing tree and group them by node
    // We don't need to know the specific relationship of each node to the current node.
    // Local accounts
    $trunkward = $this->sendRequest("accounts/names", 200, $local_user);
    $local_accounts = array_filter($trunkward, function($a) {return strpos($a, '/') === FALSE;});
    $nodes = array_filter($trunkward, function($a) {return substr($a, -1) == '/';});
    $remote_accounts = array_diff($trunkward, $local_accounts, $nodes);

    print_R($local_accounts);
    print_R($nodes);
    print_R($remote_accounts);die();
    $this->getBranchwardAccounts($nodes, $all_accounts);

    foreach (array_reverse($this->nodePath) as $trunkward) {
      $this->getNodeAccounts('', $all_remote_accounts, $leafward);
      $leafward = $trunkward;
    }
    die();
  }

  private function getNodeAccounts($path_to_node, &$all_accounts, $exclude_leafward = NULL) {
    //print_R(func_get_args());
    $local_user = reset($this->normalAccIds);
    $all_accounts[$path_to_node] = $this->sendRequest("accounts/names/$path_to_node?local=true", 200, $local_user);
    $branches = $this->sendRequest("accounts/names/$path_to_node?local=false", 200, $local_user);

    print_R($all_accounts);
    foreach ($branches as $branch) {
      if ($branch == $exclude_leafward) continue;
      $this->getNodeAccounts($branch, $all_accounts);
    print_R($all_accounts);
    }
  }

  function getRelatives($path, $parent, ) {

  }

  function testWorkflows() {
    parent::testWorkflows();
    // should find a way of testing that the inherited workflows combine properly.
  }

  function testBadTransactions() {
    global $guncle_accs, $gparent;
    parent::testBadTransactions();
    $admin = reset($this->adminAccIds);
    $obj = [
      'payee' => reset($this->adminAccIds),
      'payer' => reset($this->normalAccIds),
      'description' => 'test 3rdparty',
      'quant' => 10,
      'type' => '3rdparty',
      'metadata' => ['foo' => 'bar']
    ];
    if ($this->branchAccIds) {
      // Try to trade with a local remote account.
      $obj['payer'] = reset($this->branchAccIds);
      $this->sendRequest('transaction', 'WrongAccountViolation', $admin, 'post', json_encode($obj));
      // Try to trade with two remote accounts
      $obj['payee'] = $gparent .'/'.reset($guncle_accs);
      $obj['payer'] = $gparent .'/'.end($guncle_accs);
      echo json_encode($obj);
      $this->sendRequest('transaction', 'WrongAccountViolation', $admin, 'post', json_encode($obj));
    }
  }

  function test3rdParty() {
    parent::test3rdParty();
  }

  function testTransactionLifecycle() {
    parent::testTransactionLifecycle();
  }

  function testAccountSummaries() {
    global $guncle_accs, $gparent;
    parent::testAccountSummaries();
    if ($uncle_id = end($guncle_accs)) {
      $user1 = reset($this->normalAccIds);
      $this->sendRequest("account/summary/$this->trunkwardsId/", 200, $user1);
      if ($gparent) {
        $this->sendRequest("account/summary/$gparent/$uncle_id", 200, $user1);
        $this->sendRequest("account/limits/$gparent/", 200, $user1);
        $this->sendRequest("account/limits/$gparent/$uncle_id", 200, $user1);
        $this->sendRequest("account/history/$gparent/$uncle_id", 200, $user1);
      }
    }
    // I don't think its necessary to test other relations.

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
