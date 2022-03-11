<?php

namespace CCNode\Tests;

use CCNode\AccountStore;
use CCNode\AddressResolver;
use CCNode\Accounts\Branch;
use CCNode\Accounts\User;
use CCNode\Accounts\BoT;
use CreditCommons\Exceptions\DoesNotExistViolation;

/**
 * Test for the AddressResolver Class
 */
class AddressResolverTest extends \PHPUnit\Framework\TestCase {

  public static function setUpBeforeClass(): void {
    global $config, $addressResolver, $user, $local_accounts, $branch_accounts, $trunkward_account, $node_name;

    require_once __DIR__.'/../slimapp.php';
    $node_name = \CCNode\getConfig('node_name');
    $accountStore = AccountStore::Create();
    // For now set the user to anon. There are no permissions checks but
    // sometimes the addressresolves depends on whether the user is the BoT
    // account or not.
    $user = $accountStore->anonAccount();
    // Unfortunately testing framework doesn't pass queryParams so we must filter here
    $all_accounts = $accountStore->filter([], TRUE);

    foreach ($all_accounts as $acc) {
      if($acc instanceOf Branch) {
        $branch_accounts[] = $acc->id;
      }
      elseif ($acc instanceof BoT) {
        $trunkward_account = $acc;
      }
      elseif ($acc instanceOf User) {
        $local_accounts[] = $acc->id;
        $user = $acc;
      }
    }
    $addressResolver = new AddressResolver($accountStore, \CCNode\getConfig('abs_path'));
  }

  function testLocalAccounts() {
    global $trunkward_account, $local_accounts, $node_name;
    $acc_name = end($local_accounts);
    $this->oneTest($acc_name, $acc_name);
    $this->oneTest("$node_name/$acc_name", $acc_name);
    if ($trunkward_account) {
      $this->oneTest("$trunkward_account->id/$node_name/$acc_name", $acc_name);
      $this->oneTest("anything/$trunkward_account->id/$node_name/$acc_name", $acc_name);
    }
  }

  function testBranchAccounts() {
    global $user, $local_accounts, $branch_accounts, $trunkward_account, $node_name;
    if ($branch_accounts) {
      $branch_name = reset($branch_accounts);
      $this->oneTest("$branch_name/anything", $branch_name, 'anything');
      $this->oneTest("$branch_name/anything/anything", $branch_name, 'anything/anything');
      $this->oneTest("$node_name/$branch_name", $branch_name);
      if ($trunkward_account) {
        $this->oneTest("$trunkward_account->id/$node_name/$branch_name", $branch_name);
        $this->oneTest("anything/$trunkward_account->id/$node_name/$branch_name/anything", $branch_name, 'anything');
      }
    }
    else {
      print "\nThere were no branchward accounts to test with";
      $this->oneTest("anything", 'DoesNotExistViolation');
    }
  }

  function testTrunkwardAccounts() {
    // need to test as branch, node, and BoT
    global $user, $local_accounts, $branch_accounts, $trunkward_account, $node_name;
    if ($trunkward_account) {
      $trunkw_id = $trunkward_account->id;
      // as anon
      $this->oneTest($trunkw_id, $trunkw_id);
      $this->oneTest("anything", 'DoesNotExistViolation');
      $this->oneTest("anything/$trunkw_id", $trunkw_id, "anything/$trunkw_id");
      $this->oneTest("anything/anything/$trunkw_id", $trunkw_id, "anything/anything/$trunkw_id");
      $this->oneTest("$node_name/anything", 'DoesNotExistViolation');
      $this->oneTest("$node_name/$trunkw_id", $trunkw_id);

      // as trunkwards account
      $user = $trunkward_account;
      $this->oneTest("anything/anything/$trunkw_id", 'DoesNotExistViolation');
      $this->oneTest("anything", 'DoesNotExistViolation');
      $this->oneTest("$trunkw_id/$node_name/zzz", 'DoesNotExistViolation');
    }
    else {
      print "\nThere was no trunkward account to test with";
      $this->oneTest("anything", 'DoesNotExistViolation');
      $this->oneTest("$node_name/anything", 'DoesNotExistViolation');
    }
  }


  function oneTest($given_name, $expected, $expected_path = '') {
    global $addressResolver;
    try {
      list($account, $relative_path) = $addressResolver->resolveToLocalAccount($given_name);
    }
    catch(DoesNotExistViolation $e) {
      $this->assertEquals($expected, 'DoesNotExistViolation', "$given_name should have resolved to $expected");
      return;
    }
    catch(\Exception $e) {
      print_r($e);
      return;
    }
    $this->assertEquals($expected, $account->id, "$given_name should have resolved to $expected");
    $this->assertEquals($expected_path, $relative_path, "$given_name should have a relative path of $expected_path");
  }

}
