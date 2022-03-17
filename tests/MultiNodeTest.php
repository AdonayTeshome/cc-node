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
    global $uncle_accs, $aunt_accs, $cousin_accs, $gfather_accs, $guncle_accs, $gaunt_accs, $gparent;
    parent::testAccountNames();
    $uncle_accs = $aunt_accs = $cousin_accs = $gfather_accs = $guncle_accs = $gaunt_accs = [];
    $node_name = end($this->nodePath);
    $parent = prev($this->nodePath);
    $gparent = prev($this->nodePath);

    // Now we build a map of remote nodes
    // Get a list of accounts on the parent.
    $uncle_accs = $this->sendRequest("accounts/filter/$this->trunkwardsId?local=true", 200, reset($this->normalAccIds));
    if ($aunt_accs = $this->sendRequest("accounts/filter/$this->trunkwardsId?local=false", 200, reset($this->normalAccIds))) {
      if ($aunt_accs = array_diff($aunt_accs, [$node_name, $gparent])) {
        $cousin_accs = $this->sendRequest("accounts/filter/$this->trunkwardsId/".reset($aunt_accs).'?local=true', 200, reset($this->normalAccIds));
      }
    }
    $guncle_accs = $this->sendRequest("accounts/filter/$gparent?local=true", 200, reset($this->normalAccIds));
    if ($gaunt_accs =  $this->sendRequest("accounts/filter/$gparent?local=false", 200, reset($this->normalAccIds))) {
      unset($gaunt_accs[array_search($this->trunkwardsId, $gaunt_accs)]);
      if ($gaunt_accs) {
        $cousin1_accs = $this->sendRequest("accounts/filter/".reset($gaunt_accs).'?local=true', 200, reset($this->normalAccIds));
      }
    }
    \CCNode\debug("Uncles: ".implode(',', $uncle_accs));
    \CCNode\debug("Aunties: ".implode(',', $aunt_accs));
    \CCNode\debug("Cousins: ".implode(',', $cousin_accs));
    \CCNode\debug("Great-Uncles: ".implode(',', $guncle_accs));
    \CCNode\debug("Great-Aunties: ".implode(',', $gaunt_accs));
    \CCNode\debug("2nd Cousins: ".implode(',', $cousin1_accs));

  }

  function getRelatives($path, $parent, ) {

  }

  function testWorkflows() {
    parent::testWorkflows();
    // should find a way of testing that the inherited workflows combine properly.
  }

  function testBadTransactions() {
    parent::testBadTransactions();
    $admin = reset($this->adminAccIds);
    $obj = [
      'payee' => reset($this->adminAccIds),
      'payer' => reset($this->normalAccIds),
      'description' => 'test 3rdparty',
      'quant' => 1,
      'type' => '3rdparty',
      'metadata' => ['foo' => 'bar']
    ];
    // try to trade with a remote account
    if ($this->branchAccIds) {
      $obj['payer'] = reset($this->branchAccIds);
      $this->sendRequest('transaction', 'IntermediateledgerViolation', $admin, 'post', json_encode($obj));
      // Test that at least one acccount must be local...
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
    $uncle_id = end($guncle_accs);
    parent::testAccountSummaries();
    $user1 = reset($this->normalAccIds);
    $this->sendRequest("account/summary/$this->trunkwardsId/", 200, $user1);
    $this->sendRequest("account/summary/$gparent/$uncle_id", 200, $user1);
    $this->sendRequest("account/limits/$gparent/", 200, $user1);
    $this->sendRequest("account/limits/$gparent/$uncle_id", 200, $user1);
    $this->sendRequest("account/history/$gparent/$uncle_id", 200, $user1);
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
