<?php

use League\OpenAPIValidation\PSR15\ValidationMiddlewareBuilder;
use League\OpenAPIValidation\PSR15\SlimAdapter;
use Slim\Psr7\Response;

/**
 * Tests the API functions of a node without touching remote nodes.
 * @todo Test transversal Errors.
 *   - IntermediateledgerViolation
 *   - HashMismatchFailure
 *   - UnavailableNodeFailure Is this testable? Maybe with an invalid url?\
 * @todo Invalid paths currently return 404 which isn't in the spec.
 *
 */
class SingleNodeTest extends \PHPUnit\Framework\TestCase {

  public static function setUpBeforeClass(): void {
    global $config, $users, $admin_acc_ids, $norm_acc_ids;
    // Get some user data directly from the accountStore
    // NB the accountstore should deny requests from outside this server.
    $config = parse_ini_file(__DIR__.'/../node.ini');
    // Just get local user accounts, not remote node accounts.
    $local_accounts = array_filter(
      (array)json_decode(file_get_contents('AccountStore/store.json')),
      function($u) {return $u->status && isset($u->key);}
    );
    foreach ($local_accounts as $a) {
      if ($a->admin) {
        $admin_acc_ids[] = $a->id;
      }
      else {
        $norm_acc_ids[] = $a->id;
      }
      // what if there is no admin? what if no normies.
      $users[$a->id] = $a->key;
    }
    if (empty($norm_acc_ids) || empty($admin_acc_ids)) {
      die("Testing requires both admin and non-admin accounts in store.json");
    }
  }

  function testEndpoints() {
    global $norm_acc_ids;
    $options = $this->sendRequest('', 200, '<anon>', 'options');
    $this->assertObjectHasAttribute("permittedEndpoints", $options);
    $this->assertObjectNotHasAttribute("accountSummary", $options);
    $this->assertObjectNotHasAttribute("filterTransactions", $options);
    $options = $this->sendRequest('', 200, reset($norm_acc_ids), 'options');
    $this->assertObjectHasAttribute("filterTransactions", $options);
    $this->assertObjectHasAttribute("accountSummary", $options);

    $nodes = $this->sendRequest('handshake', 200, reset($norm_acc_ids));
    foreach ($nodes as $status_code => $urls) {
      $this->assertIsInteger($status_code / 100);
    }
  }

  function testBadLogin() {
    global $norm_acc_ids;
    // This is a comparatively long winded because sendRequest() only uses valid passwords.
    $request = $this->getRequest('trunkwards')
      ->withHeader('cc-user', 'zzz123')
      ->withHeader('cc-auth', 'zzz123');
    $response = $this->getApp()->process($request, new Response());
    $this->assertEquals($response->getStatusCode(), 400);
    $response->getBody()->rewind();
    $err_obj = json_decode($response->getBody()->getContents());
    $exception = \CreditCommons\RestAPI::reconstructCCException($err_obj);
    $this->assertInstanceOf('CreditCommons\Exceptions\DoesNotExistViolation', $exception);
    $this->assertEquals('account', $exception->type);

    $request = $this->getRequest('trunkwards')
      ->withHeader('cc-user', reset($norm_acc_ids))
      ->withHeader('cc-auth', 'zzz123');
    $response = $this->getApp()->process($request, new Response());
    $this->assertEquals($response->getStatusCode(), 400);
    $response->getBody()->rewind();
    $err_obj = json_decode($response->getBody()->getContents());
    $exception = \CreditCommons\RestAPI::reconstructCCException($err_obj);
    $this->assertInstanceOf('CreditCommons\Exceptions\AuthViolation', $exception);
  }

  function testAccountNames() {
    global $norm_acc_ids, $admin_acc_ids;
    $chars = substr(reset($admin_acc_ids), 0, 2);
    $this->sendRequest("accounts/filter/$chars", 'PermissionViolation', '<anon>');
    $results = $this->sendRequest("accounts/filter/$chars", 200, reset($norm_acc_ids));
    // should be a list of account names including 'a'
    foreach ($results as $acc_id) {
      $this->assertStringStartsWith($chars, $acc_id);
    }
  }

  function testWorkflows() {
    global $norm_acc_ids;
    // By default this is only accessible for authenticated users.
    $wfs = $this->sendRequest('workflows', 200, reset($norm_acc_ids));
    $this->assertNotEmpty($wfs);
  }

  function testBadTransactions() {
    global $norm_acc_ids, $admin_acc_ids;
    $admin = reset($admin_acc_ids);
    $obj = [
      'payee' => reset($admin_acc_ids),
      'payer' => reset($norm_acc_ids),
      'description' => 'test 3rdparty',
      'quant' => 1,
      'type' => '3rdparty'
    ];
    $obj['payee'] = 'aaaaaaaaaaa';
    $this->sendRequest('transaction', 'DoesNotExistViolation', $admin, 'post', json_encode($obj));
    $obj['payee'] = reset($admin_acc_ids);
    $obj['quant'] = 999999999;
    $this->sendRequest('transaction', 'TransactionLimitViolation', $admin, 'post', json_encode($obj));
    $obj['quant'] = 0;
    $this->sendRequest('transaction', 'CCViolation', $admin, 'post', json_encode($obj));
    $obj['quant'] = 1;
    $obj['type'] = 'zzzzzz';
    $this->sendRequest('transaction', 'DoesNotExistViolation', $admin, 'post', json_encode($obj));
    $obj['type'] = 'disabled';// this is the name of one of the default workflows, which exists for this test
    $this->sendRequest('transaction', 'DoesNotExistViolation', $admin, 'post', json_encode($obj));
    $obj['type'] = '3rdparty';
    $this->sendRequest('transaction', 'PermissionViolation', '<anon>', 'post', json_encode($obj));
  }

  function test3rdParty() {
    global $norm_acc_ids, $admin_acc_ids, $users;
    $this->assertGreaterThan('1', count($users));
    $admin = reset($admin_acc_ids);
    $payee = reset($norm_acc_ids);
    $payer = next($norm_acc_ids);
    // This assumes the default workflow is unmodified.
    $obj = [
      'payee' => $payee,
      'payer' => $payer,
      'description' => 'test 3rdparty',
      'quant' => 1,
      'type' => '3rdparty'
    ];
    $this->sendRequest('transaction', 'PermissionViolation', '<anon>', 'post', json_encode($obj));
    // Default 3rdParty workflow saves transactions immemdiately in completed state.
    $transaction = $this->sendRequest('transaction', 201, $admin, 'post', json_encode($obj));
    sleep(2);// this is so as not to confuse the history chart, which is indexed by seconds.
    // Check the transaction is written
    $this->assertNotNull($transaction->uuid);
    $this->assertEquals($payee, $transaction->entries[0]->payee);
    $this->assertEquals($payer, $transaction->entries[0]->payer);
    $this->assertEquals('test 3rdparty', $transaction->entries[0]->description);
    $this->assertEquals('3rdparty', $transaction->type);
    $this->assertEquals('completed', $transaction->state);
    $this->assertEquals('1', $transaction->version);

    // try to retrieve a transaction that doesn't exist.
    $error = $this->sendRequest('transaction/ada5b4f0-33a8-4807-90c7-3aa56ae1c741/full', 'DoesNotExistViolation', $admin);
    $this->assertEquals('transaction', $error->type);

    $this->sendRequest('transaction/'.$transaction->uuid.'/full', 200, $admin);
  }

  function testTransactionLifecycle() {
    global $norm_acc_ids, $admin_acc_ids;
    $admin = reset($admin_acc_ids);
    $payee = reset($norm_acc_ids);
    $payer = next($norm_acc_ids);
    if (!$payer) {
      print "Skipped testTransactionLifecycle. More than one non-admin user required";
      return;
    }
    // Check the balances first
    $init_summary = $this->sendRequest("account/summary/$payee", 200, $payee);
    $init_points = (array)$this->sendRequest("account/history/$payee", 200, $payee);
    if (count($init_points) == 0) {
      $init_points[0] = 0;// Because the first transaction adds 2 points
    }
    $obj = [
      'payee' => $payee,
      'payer' => $payer,
      'description' => 'test bill',
      'quant' => 1,
      'type' => 'bill'
    ];
    // 'bill' transactions must be approved, and enter pending state.
    $transaction = $this->sendRequest('transaction', 200, $payee, 'post', json_encode($obj));
    $this->assertNotEmpty($transaction->transitions);
    $this->assertContains('pending', $transaction->transitions);
    $this->assertEquals("validated", $transaction->state);
    $this->assertEquals('0', $transaction->version);
    // check that nobody else can see this transaction
    $this->sendRequest("transaction/$transaction->uuid/full", 'DoesNotExistViolation', $payer);
    $this->sendRequest("transaction/$transaction->uuid/full", 200, $admin);

    // write the transaction
    $this->sendRequest("transaction/$transaction->uuid/pending", 'PermissionViolation', '<anon>', 'patch', json_encode($obj));
    $this->sendRequest("transaction/$transaction->uuid/pending", 201, $payee, 'patch');

    $pending_summary = $this->sendRequest("account/summary/$payee", 200, $payee);
    $this->assertEquals($pending_summary->pending->balance-1, $init_summary->pending->balance);
    $this->assertEquals($pending_summary->pending->volume-1, $init_summary->pending->volume);
    $this->assertEquals($pending_summary->pending->gross_in-1, $init_summary->pending->gross_in);
    $this->assertEquals($pending_summary->pending->gross_out, $init_summary->pending->gross_out);
    $this->assertEquals($pending_summary->pending->trades-1, $init_summary->pending->trades);
    // We can't easily test partners unless we clear the db first.
    // Admin confirms the transaction
    $this->sendRequest("transaction/$transaction->uuid/completed", 201, $admin, 'patch');
    $completed_summary = $this->sendRequest("account/summary/$payee", 200, $payee);
    $this->assertEquals($completed_summary->completed->balance-1, $init_summary->completed->balance);
    $this->assertEquals($completed_summary->completed->volume-1, $init_summary->completed->volume);
    $this->assertEquals($completed_summary->completed->gross_in-1, $init_summary->completed->gross_in);
    $this->assertEquals($completed_summary->completed->gross_out, $init_summary->completed->gross_out);
    $this->assertEquals($completed_summary->completed->trades-1, $init_summary->completed->trades);
    sleep(2);// so the last point on account history doesn't override the previous transaction
    $completed_points = (array)$this->sendRequest("account/history/$payee", 200, $payee);
    $expected_points  = count($completed_points) -2 + count($transaction->entries);
    $this->assertEquals($expected_points, count($init_points));
    // Erase
    $this->sendRequest("transaction/$transaction->uuid/erased", 201, $admin, 'patch');
    $erased_summary = $this->sendRequest("account/summary/$payee", 200, $payee);
    $this->assertEquals($erased_summary, $init_summary);
    $erased_points = (array)$this->sendRequest("account/history/$payee", 200, $payee);
    $this->assertEquals(count($erased_points), count($init_points));
  }

  /**
   * This doesn't work because the middleware ignores the query parameters.
   * @todo wait for an answer to https://github.com/Nyholm/psr7/issues/181
   */
  function testTransactionFilterRetrieve() {
    global $norm_acc_ids;
    $this->sendRequest("transaction", 'PermissionViolation', '<anon>');
    $uuids = $this->sendRequest("transaction", 200, reset($norm_acc_ids));
    return;
    // Filter description
    $uuids = $this->sendRequest("transaction?description=test%203rdparty", 200, '<anon>');
    //We have the results, now fetch and test the first result
    $transaction = $this->sendRequest("transaction/".reset($uuids)."/full", 200, '<anon>');
    $this->assertStringContainsString("test 3rdparty", $transaction->entries[0]->description);
  }

  function testAccountSummaries() {
    global $norm_acc_ids;
    $user1 = reset($norm_acc_ids);
    $this->sendRequest("account/history/$user1", 'PermissionViolation', '<anon>');
    //  Currently there is no per-user access control around limits visibility.
    $limits = $this->sendRequest("account/limits/$user1", 200, $user1);
    $this->assertlessThan(0, $limits->min);
    $this->assertGreaterThan(0, $limits->max);
    // account/summary/{acc_id} is already tested
    $this->sendRequest("accounts/summary", 'PermissionViolation', '<anon>');
    $this->sendRequest("accounts/summary", 200, $user1);
  }


  protected function sendRequest($path, int|string $expected_response, string $acc_id, string $method = 'get', string $request_body = '') : stdClass|NULL|array {
    global $users, $admin_acc_ids, $norm_acc_ids;
    $request = $this->getRequest($path, $method);
    if ($acc_id <> '<anon>') {
      $request = $request->withHeader('cc-user', $acc_id)->withHeader('cc-auth', $users[$acc_id]);
    }
    if ($request_body) {
      $request = $request->withHeader('Content-Type', 'application/json');
      $request->getBody()->write($request_body);
    }

    $response = $this->getApp()->process($request, new Response());
    $response->getBody()->rewind();
    $contents = json_decode($response->getBody()->getContents());
    $status_code = $response->getStatusCode();
    if (is_int($expected_response)) {
      if ($status_code <> $expected_response) {
        // Blurt out to terminal to ensure all info is captured.
        echo "\n $acc_id got unexpected code ".$status_code." on $path: ".print_r($contents, 1); // Seems to be truncated hmph.
        $this->assertEquals($expected_response, $status_code);
      }
    }
    else {
      //print_r($contents);
      $e = CreditCommons\RestAPI::reconstructCCException($contents);
      $this->assertInstanceOf("CreditCommons\Exceptions\\$expected_response", $e);
    }
    return $contents;
  }

  private function getRequest($path, $method = 'GET') {
    $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();
    return $psr17Factory->createServerRequest(strtoupper($method), '/'.$path);
  }

  /**
   * @return \Slim\App
   */
  protected function getApp(): \Slim\App {
    static $app;
    if (!$app) {
      $app = require_once __DIR__.'/../slimapp.php';
      $spec = __DIR__.'/../vendor/credit-commons-software-stack/cc-php-lib/docs/credit-commons-openapi-3.0.yml';
      $psr15Middleware = (new ValidationMiddlewareBuilder)->fromYaml(file_get_contents($spec))->getValidationMiddleware();
      $middleware = new SlimAdapter($psr15Middleware);
      $app->add($middleware);
    }
    return $app;
  }

  private function checkTransactions(array $all_transactions, array $filtered_uuids, array $conditions) {
    foreach ($all_transactions as $t) {
      $pass = FALSE;
      foreach ($conditions as $key => $value) {
        $pass = $t->{$key} == $value;
        if (!$pass) break;
      }
      if ($pass) {
        $uuids[] = $t->uuid;
      }
    }
    $this->assertEquals($uuids, $filtered_uuids);
  }

}
