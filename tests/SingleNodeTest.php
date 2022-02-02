<?php

use League\OpenAPIValidation\PSR15\ValidationMiddlewareBuilder;
use League\OpenAPIValidation\PSR15\SlimAdapter;
use Slim\Psr7\Response;

/**
 * So far this tests the API functions assuming good results, but doesn't test the error messages.
 * @todo
 *  AccountResolutionViolation
    AuthViolation
    BadCharactersViolation
    DoesNotExistViolation
    HashMismatchFailure
    IntermediateledgerViolation
    InvalidFieldsViolation
    PermissionViolation
    UnexpectedResultFailure
    UnknownWorkflowViolation
    WorkflowViolation
 *
 *  OfflineFailure CANT
 */
class SingleNodeTest extends \PHPUnit\Framework\TestCase {

  public static function setUpBeforeClass(): void {
    global $config, $users;
    // Get some user data directly from the accountStore
    // NB the accountstore should deny requests from outside this server.
    $config = parse_ini_file(__DIR__.'/../node.ini');
    $requester = new \CCNode\AccountStore($config['account_store_url']);
    $users = $requester->filter(['status' => 1]);
  }

  function testEndpoints() {
    $response = $this->sendRequest('', 'options', 200, TRUE);
    $this->checks($response, 'application/json');
    $body = json_decode($response->getBody()->getContents());
    $this->assertObjectHasAttribute("permittedEndpoints", $body);
    $this->assertObjectNotHasAttribute("accountSummary", $body);
    $this->assertObjectNotHasAttribute("filterTransactions", $body);
    $response = $this->sendRequest('', 'options', 200);
    /** @var \Slim\Psr7\Response $response */
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

  function testAccountNames() {
    global $users;
    $char = substr($users[0]->id, 0, 2);
    $response = $this->sendRequest("accounts/$char", 'get', 200);
    $acc_ids = json_decode($response->getBody()->getContents());
    // should be a list of account names including 'a'
    foreach ($acc_ids as $acc_id) {
      $this->assertStringStartsWith($char, $acc_id);
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
    $obj = [
      'payee' => $users[0]->id,
      'payer' => $users[1]->id,
      'description' => 'test 3rdparty',
      'quantity' => 1000000000,
      'type' => '3rdparty'
    ];
    // This SHOULD generate an error.
    $response = $this->sendRequest('transaction/new', 'post', 400, FALSE, json_encode($obj));
    $err_obj = json_decode($response->getBody()->getContents());
    $exception = \CreditCommons\RestAPI::reconstructCCException($err_obj);
    // Should violate min OR max
    $this->assertInstanceOf('\CreditCommons\Exceptions\TransactionLimitViolation', $exception);

    // Now test a valid transaction.
    // This assumes the default workflow is unmodified.
    $obj = [
      'payee' => $users[0]->id,
      'payer' => $users[1]->id,
      'description' => 'test 3rdparty',
      'quantity' => 1,
      'type' => '3rdparty'
    ];
    // 3rdParty transactions are created already complete.
    $response = $this->sendRequest('transaction/new', 'post', 201, FALSE, json_encode($obj));
  }

  function testTransactionLifecycle() {
    global $users;
    $obj = [
      'payee' => $users[0]->id,
      'payer' => $users[1]->id,
      'description' => 'test bill',
      'quantity' => 1,
      'type' => 'bill'
    ];
    // 'bill' transactions must be approved, and enter pending state.
    $response = $this->sendRequest('transaction/new', 'post', 200, FALSE, json_encode($obj));
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
    global $users;
    $test_user_id = end($users)->id;
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


  protected function sendRequest($path, $method = 'get', int $expected_code, bool $anon = FALSE, string $request_body = '') : Response {
    global $users;
    $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();
    $request = $psr17Factory->createServerRequest(strtoupper($method), '/'.$path);
    if (!$anon) {
      $firstuser = reset($users);
      $request = $request->withHeader('cc-user', $firstuser->id);
      $request = $request->withHeader('cc-auth', $firstuser->key);
    }
    if ($request_body) {
      $request = $request->withHeader('Content-type', 'application/json');
      $request->getBody()->write($request_body);
    }

    $response = $this->getApp()->process($request, new Response());
    if ($response->getStatusCode() <> $expected_code) {
      $response->getBody()->rewind();
      // Blurt out to terminal to ensure all info is captured.
      echo "\n Unexpected code ".$response->getStatusCode()." on $path: ".print_r($response->getBody()->getContents(), 1)."\n"; // Seems to be truncated hmph.
      $this->assertEquals($expected_code, $response->getStatusCode());
    }
    $response->getBody()->rewind();
    return $response;
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
