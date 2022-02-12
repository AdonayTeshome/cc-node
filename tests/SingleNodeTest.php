<?php

use League\OpenAPIValidation\PSR15\ValidationMiddlewareBuilder;
use League\OpenAPIValidation\PSR15\SlimAdapter;
use Slim\Psr7\Response;

/**
 * So far this tests the API functions assuming good results, but doesn't test the error messages.
 * @todo Test transversal Errors.
 *   - IntermediateledgerViolation
 *   - HashMismatchFailure
 *   - OfflineFailure Is this testable? Maybe with an invalid url?\
 * @todo Invalid paths currently return 404 which isn't in the spec.
 */
class SingleNodeTest extends \PHPUnit\Framework\TestCase {

  public static function setUpBeforeClass(): void {
    global $config, $users, $admin_acc_id, $norm_acc_id;
    // Get some user data directly from the accountStore
    // NB the accountstore should deny requests from outside this server.
    $config = parse_ini_file(__DIR__.'/../node.ini');
    // Augment the user objects with the key.
    $accounts = array_filter(
      (array)json_decode(file_get_contents('AccountStore/store.json')),
      function($u) {return $u->status && $u->key;}
    );
    foreach ($accounts as $a) {
      if ($a->admin) {
        $admin_acc_id = $a->id;
      }
      else {
        $norm_acc_id = $a->id;
      }
      // what if there is no admin? what if no normies.
      $users[$a->id] = $a->key;
    }
    if (empty($norm_acc_id) || empty($admin_acc_id)) {
      die("Testing requires both admin and non-admin accounts in store.json");
    }
  }

  function testAnonEndpoints() {
    $body = $this->sendRequest('', 200, 'options', 'anon');
    $this->assertObjectHasAttribute("permittedEndpoints", $body);
    $this->assertObjectNotHasAttribute("accountSummary", $body);
    $this->assertObjectNotHasAttribute("filterTransactions", $body);
    $body = $this->sendRequest('', 200, 'options');
    $this->assertObjectHasAttribute("filterTransactions", $body);
    $this->assertObjectHasAttribute("accountSummary", $body);

    $nodes = $this->sendRequest('handshake', 200);
    foreach ($nodes as $status_code => $urls) {
      $this->assertIsInteger($status_code / 100);
    }
  }

  function testBadLogin() {
    global $norm_acc_id;
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
      ->withHeader('cc-user', $norm_acc_id)
      ->withHeader('cc-auth', 'zzz123');
    $response = $this->getApp()->process($request, new Response());
    $this->assertEquals($response->getStatusCode(), 400);
    $response->getBody()->rewind();
    $err_obj = json_decode($response->getBody()->getContents());
    $exception = \CreditCommons\RestAPI::reconstructCCException($err_obj);
    $this->assertInstanceOf('CreditCommons\Exceptions\AuthViolation', $exception);

  }

  function testAccountNames() {
    global $users;
    $chars = substr(key($users), 0, 2);
    $this->sendRequest("accounts/$chars", 'PermissionViolation', 'get', 'anon');
    $results = $this->sendRequest("accounts/$chars", 200, 'get', 'acc');
    // should be a list of account names including 'a'
    foreach ($results as $acc_id) {
      $this->assertStringStartsWith($chars, $acc_id);
    }
  }

  function testWorkflows() {
    // By default this is only accessible for authenticated users.
    $wfs = $this->sendRequest('workflows', 200);
    $this->assertNotEmpty($wfs);
  }

  function testBadTransactions() {
    global $norm_acc_id, $admin_acc_id;
    $obj = [
      'payee' => $admin_acc_id,
      'payer' => $norm_acc_id,
      'description' => 'test 3rdparty',
      'quant' => 1,
      'type' => '3rdparty'
    ];
    $obj['payee'] = 'aaaaaaaaaaa';
    $this->sendRequest('transaction/new', 'DoesNotExistViolation', 'post', 'admin', json_encode($obj));
    $obj['payee'] = $payee;
    $obj['quant'] = 999999999;
    $this->sendRequest('transaction/new', 'TransactionLimitViolation', 'post', 'admin', json_encode($obj));
    $obj['quant'] = 0;
    $this->sendRequest('transaction/new', 'CCViolation', 'post', 'admin', json_encode($obj));
    $obj['quant'] = 1;
    $obj['type'] = 'zzzzzz';
    $this->sendRequest('transaction/new', 'DoesNotExistViolation', 'post', 'admin', json_encode($obj));
    $obj['type'] = 'disabled';// this is the name of one of the default workflows, which exists for this test
    $this->sendRequest('transaction/new', 'DoesNotExistViolation', 'post', 'admin', json_encode($obj));
    $obj['type'] = '3rdparty';
    $this->sendRequest('transaction/new', 'PermissionViolation', 'post', 'anon', json_encode($obj));
  }



  function test3rdParty() {
    global $users;
    $this->assertGreaterThan('1', count($users));
    $payee = key($users);
    next($users);
    $payer = key($users);
    // This assumes the default workflow is unmodified.
    $obj = [
      'payee' => $payee,
      'payer' => $payer,
      'description' => 'test 3rdparty',
      'quant' => 1,
      'type' => '3rdparty'
    ];

    $this->sendRequest('transaction/new', 'PermissionViolation', 'post', 'anon', json_encode($obj));
    // 3rdParty transactions are created already complete.
    $transaction = $this->sendRequest('transaction/new', 201, 'post', 'admin', json_encode($obj));
    // Check the transaction is written
    $this->assertNotNull($transaction->uuid);
    $this->assertEquals($payee, $transaction->entries[0]->payee);
    $this->assertEquals($payer, $transaction->entries[0]->payer);
    $this->assertEquals('test 3rdparty', $transaction->entries[0]->description);
    $this->assertEquals('3rdparty', $transaction->type);
    $this->assertEquals('completed', $transaction->state);

    // try to retrieve a transaction that doesn't exist.
    $error = $this->sendRequest('transaction/ada5b4f0-33a8-4807-90c7-3aa56ae1c741/full', 'DoesNotExistViolation', 'get', 'admin');
    $this->assertEquals('transaction', $error->type);

    $this->sendRequest('transaction/'.$transaction->uuid.'/full', 200, 'get', 'admin');
  }

  function testTransactionLifecycle() {
    global $norm_acc_id, $admin_acc_id;
    // Check the balances first
    $init_summary = $this->sendRequest("account/summary/$norm_acc_id", 200);
    $init_points = $this->sendRequest("account/history/$norm_acc_id", 200);
    $obj = [
      'payer' => $admin_acc_id,
      'payee' => $norm_acc_id,
      'description' => 'test bill',
      'quant' => 1,
      'type' => 'bill'
    ];
    // 'bill' transactions must be approved, and enter pending state.
    $transaction = $this->sendRequest('transaction/new', 200, 'post', 'acc', json_encode($obj));
    $this->assertNotEmpty($transaction->transitions);
    $this->assertContains('pending', $transaction->transitions);
    $this->assertEquals("validated", $transaction->state);
    // write the transaction
    $this->sendRequest("transaction/$transaction->uuid/pending", 'PermissionViolation', 'patch', 'anon', json_encode($obj));
    $this->sendRequest("transaction/$transaction->uuid/pending", 201, 'patch');

    $pending_summary = $this->sendRequest("account/summary/$norm_acc_id", 200);
    $this->assertEquals($pending_summary->pending->balance-1, $init_summary->pending->balance);
    $this->assertEquals($pending_summary->pending->volume-1, $init_summary->pending->volume);
    $this->assertEquals($pending_summary->pending->gross_in-1, $init_summary->pending->gross_in);
    $this->assertEquals($pending_summary->pending->gross_out, $init_summary->pending->gross_out);
    $this->assertEquals($pending_summary->pending->trades-1, $init_summary->pending->trades);
    // We can't easily test partners unless we clear the db first.
    // Admin confirms the transaction
    $this->sendRequest("transaction/$transaction->uuid/completed", 201, 'patch', 'admin');
    $completed_summary = $this->sendRequest("account/summary/$norm_acc_id", 200);
    $completed_points = $this->sendRequest("account/history/$norm_acc_id", 200);
    $this->assertEquals($completed_summary->completed->balance-1, $init_summary->completed->balance);
    $this->assertEquals($completed_summary->completed->volume-1, $init_summary->completed->volume);
    $this->assertEquals($completed_summary->completed->gross_in-1, $init_summary->completed->gross_in);
    $this->assertEquals($completed_summary->completed->gross_out, $init_summary->completed->gross_out);
    $this->assertEquals($completed_summary->completed->trades-1, $init_summary->completed->trades);
    $this->assertEquals(count((array)$completed_points) -2, count((array)$init_points));
    // Erase
    $this->sendRequest("transaction/$transaction->uuid/erased", 201, 'patch', 'admin');
    $erased_summary = $this->sendRequest("account/summary/$norm_acc_id", 200);
    $this->assertEquals($erased_summary, $init_summary);
  }

  /**
   * This doesn't work because the middleware ignores the query parameters.
   * @todo wait for an answer to https://github.com/Nyholm/psr7/issues/181
   */
  function testTransactionFilterRetrieve() {
    $this->sendRequest("transaction", 'PermissionViolation', 'get', 'anon');
    $uuids = $this->sendRequest("transaction", 200);
    return;
    // Filter description
    $uuids = $this->sendRequest("transaction?description=test%203rdparty", 200);
    //We have the results, now fetch and test the first result
    $transaction = $this->sendRequest("transaction/".reset($uuids)."/full", 200);
    $this->assertStringContainsString("test 3rdparty", $transaction->entries[0]->description);
  }

  function testStats() {
    global $users, $test_user_id;
    end($users);
    $test_user_id = key($users);
    $this->sendRequest("account/history/$test_user_id", 'PermissionViolation', 'get', 'anon');
    //  Currently there is no per-user access control around limits visibility.
    $limits = $this->sendRequest("account/limits/$test_user_id", 200);
    $this->assertlessThan(0, $limits->min);
    $this->assertGreaterThan(0, $limits->max);
    // account/summary/{acc_id} is already tested
  }


  protected function sendRequest($path, int|string $expected_response, $method = 'get', string $role = 'acc', string $request_body = '') : stdClass|NULL|array {
    global $users, $admin_acc_id, $norm_acc_id;
    if ($role == 'admin') {
      $acc_id = $admin_acc_id;
    }
    elseif ($role == 'acc') {
      $acc_id = $norm_acc_id; // TODO
    }
    $request = $this->getRequest($path, $method);
    if (isset($acc_id)) {
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
        echo "\n $role got unexpected code ".$status_code." on $path: ".print_r($contents, 1); // Seems to be truncated hmph.
        $this->assertEquals($expected_response, $status_code);
      }
    }
    else {
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
