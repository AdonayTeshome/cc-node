<?php

use League\OpenAPIValidation\PSR15\ValidationMiddlewareBuilder;
use League\OpenAPIValidation\PSR15\SlimAdapter;
use Slim\Psr7\Response;

/**
 * So far this tests the API functions assuming good results, but doesn't test the error messages.
 * @todo
    DoesNotExistViolation account
    DoesNotExistViolation transaction
    HashMismatchFailure
    IntermediateledgerViolation
    InvalidFieldsViolation
    PermissionViolation
    UnexpectedResultFailure
    UnknownWorkflowViolation
    WorkflowViolation
 *
 *  OfflineFailure Is this testable? Maybe with an invalid url?
 *  try new transaction with existing uuid.
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
    $response = $this->sendRequest('', 'options', 200, 'anon');
    $this->checks($response, 'application/json');
    $body = json_decode($response->getBody()->getContents());
    $this->assertObjectHasAttribute("permittedEndpoints", $body);
    $this->assertObjectNotHasAttribute("accountSummary", $body);
    $this->assertObjectNotHasAttribute("filterTransactions", $body);
    $response = $this->sendRequest('', 'options', 200);
    $this->checks($response, 'application/json');
    $body = json_decode($response->getBody()->getContents());
    $this->assertObjectHasAttribute("filterTransactions", $body);
    $this->assertObjectHasAttribute("accountSummary", $body);

    $response = $this->sendRequest('handshake', 'get', 200);
    // The structure has been checked against the API
    $nodes = json_decode($response->getBody()->getContents());
    foreach ($nodes as $status_code => $urls) {
      $this->assertIsInteger($status_code / 100);
    }
  }

  function testBadPassword() {
    global $norm_acc_id;
    $request = $this->getRequest('trunkwards')
      ->withHeader('cc-user', $norm_acc_id)
      ->withHeader('cc-auth', 'zzz123');
    $response = $this->getApp()->process($request, new Response());
    $this->assertEquals($response->getStatusCode(), 400);
    $this->checkErrorClass($response, 'AuthViolation');
  }

  function testAccountNames() {
    global $users;
    $chars = substr(key($users), 0, 2);
    $response = $this->sendRequest("accounts/$chars", 'get', 400, 'anon'); // AuthViolation
    $response = $this->sendRequest("accounts/$chars", 'get', 200, 'acc');
    $results = json_decode($response->getBody()->getContents());
    // should be a list of account names including 'a'
    foreach ($results as $acc_id) {
      $this->assertStringStartsWith($chars, $acc_id);
    }
  }

  function testWorkflows() {
    // By default this is only accessible for authenticated users.
    $response = $this->sendRequest('workflows', 'get', 200);
    $wfs = (array)json_decode($response->getBody()->getContents());
    $this->assertNotEmpty($wfs);
  }

  function test3rdParty() {
    global $users;
    $this->assertGreaterThan('1', count($users));
    $payee = key($users);
    next($users);
    $payer = key($users);
    $obj = [
      'payee' => 'aaaaaa',
      'payer' => $payer,
      'description' => 'test 3rdparty',
      'quant' => 1,
      'type' => '3rdparty'
    ];
    $response = $this->sendRequest('transaction/new', 'post', 400, 'admin', json_encode($obj));
    $this->checkErrorClass($response, 'DoesNotExistViolation');
    $obj['payee'] = $payee;
    $obj['quant'] = 999999999;
    $response = $this->sendRequest('transaction/new', 'post', 400, 'admin', json_encode($obj));
    // Should show min OR maxLimitViolation
    $this->checkErrorClass($response, 'TransactionLimitViolation');
    $obj['quant'] = 1;
    $obj['type'] = 'zzzzzz';
    $response = $this->sendRequest('transaction/new', 'post', 400, 'admin', json_encode($obj));
    $this->checkErrorClass($response, 'DoesNotExistViolation');
    $obj['type'] = '3rdparty';

    // Now test a valid transaction.
    // This assumes the default workflow is unmodified.
    $obj = [
      'payee' => $payee,
      'payer' => $payer,
      'description' => 'test 3rdparty',
      'quant' => 1,
      'type' => '3rdparty'
    ];
    // 3rdParty transactions are created already complete.
    $response = $this->sendRequest('transaction/new', 'post', 201, 'admin', json_encode($obj));

    // try a zero value transaction
    // use disabled/nonexistent workflow
  }

  function testTransactionLifecycle() {
    global $users, $norm_acc_id, $admin_acc_id;
    $obj = [
      'payer' => $admin_acc_id,
      'payee' => $norm_acc_id,
      'description' => 'test bill',
      'quant' => 1,
      'type' => 'bill'
    ];
    // 'bill' transactions must be approved, and enter pending state.
    $response = $this->sendRequest('transaction/new', 'post', 200, 'acc', json_encode($obj));
    $transaction = json_decode($response->getBody()->getContents());
    $this->assertNotEmpty($transaction->transitions);
    $this->assertContains('pending', $transaction->transitions);
    $this->assertEquals("validated", $transaction->state);
    $this->sendRequest("transaction/$transaction->uuid/pending", 'patch', 201);
    // Erase
    $this->sendRequest("transaction/$transaction->uuid/erased", 'patch', 201);
  }

  /**
   * @todo wait for an answer to https://github.com/Nyholm/psr7/issues/181
   */
  function __testTransactionFilterRetrieve() {
    // Filter description
    $response = $this->sendRequest("transaction?description=test%203rdparty", 'get', 200);
    $uuids = json_decode($response->getBody()->getContents());
    //We have the results, now fetch and test the first result
    $response = $this->sendRequest("transaction/".reset($uuids)."/full", 'get', 200);
    $transaction = json_decode($response->getBody()->getContents());
    $this->assertStringContainsString("test 3rdparty", $transaction->entries[0]->description);
  }

  function testStats() {
    global $users, $test_user_id;
    end($users);
    $test_user_id = key($users);
    $response = $this->sendRequest("account/history/$test_user_id", 'get', 200);
    $response = $this->sendRequest("account/limits/$test_user_id", 'get', 200);
    $limits = json_decode($response->getBody()->getContents());
    $this->assertIsObject($limits);
    $this->assertObjectHasAttribute('min', $limits);
    $this->assertObjectHasAttribute('max', $limits);
    $this->assertlessThan(0, $limits->min);
    $this->assertGreaterThan(0, $limits->max);
    $response = $this->sendRequest("account/summary/$test_user_id", 'get', 200);
  }


  protected function sendRequest($path, $method = 'get', int $expected_code, string $role = 'acc', string $request_body = '') : Response {
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
    if ($response->getStatusCode() <> $expected_code) {
      $response->getBody()->rewind();
      // Blurt out to terminal to ensure all info is captured.
      echo "\n $role got Unexpected code ".$response->getStatusCode()." on $path: ".print_r(json_decode($response->getBody()->getContents()), 1)."\n"; // Seems to be truncated hmph.
      $this->assertEquals($expected_code, $response->getStatusCode());
    }
    $response->getBody()->rewind();
    return $response;
  }

  private function getRequest($path, $method = 'GET') {
    $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();
    return $psr17Factory->createServerRequest(strtoupper($method), '/'.$path);
  }

  protected function checkErrorClass(Response $response, string $err_class) {
    $response->getBody()->rewind();
    $err_obj = json_decode($response->getBody()->getContents());
    $exception = \CreditCommons\RestAPI::reconstructCCException($err_obj);
    $err_class = '\CreditCommons\Exceptions\\'.$err_class;
    $this->assertInstanceOf($err_class, $exception);
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

  private function checks(Response $response, string $mime_type = '') {
    $this->assertEquals($mime_type, $response->getHeaderLine('Content-Type'));
    $body = $response->getbody();
    if ($mime_type) {
      $this->assertGreaterThan(0, $body->getSize());
    }
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
