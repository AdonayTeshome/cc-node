<?php

use League\OpenAPIValidation\PSR15\ValidationMiddlewareBuilder;
use League\OpenAPIValidation\PSR15\SlimAdapter;
use Slim\Psr7\Response;

class APITest extends \PHPUnit\Framework\TestCase {

  public static function setUpBeforeClass(): void {
    global $config, $users;
    // Get some user data directly from the accountStore
    // NB the accountstore should deny requests from outside this server.
    $config = parse_ini_file(__DIR__.'/../node.ini');
    $requester = new \CCNode\AccountStore($config['account_store_url']);
    $users = $requester->filter(['status' => 1]);
  }

  function testEndpoints() {
    $response = $this->sendRequest('', 'options', TRUE);
    $this->checks($response, 200, 'application/json');
    $body = json_decode($response->getBody()->getContents());
    $this->assertObjectHasAttribute("permittedEndpoints", $body);
    $this->assertObjectNotHasAttribute("accountSummary", $body);
    $this->assertObjectNotHasAttribute("filterTransactions", $body);
    $response = $this->sendRequest('', 'options');
    /** @var \Slim\Psr7\Response $response */
    $this->checks($response, 200, 'application/json');
    $body = json_decode($response->getBody()->getContents());
    $this->assertObjectHasAttribute("filterTransactions", $body);
    $this->assertObjectHasAttribute("accountSummary", $body);
  }

  function testRootPath() {
    $response = $this->sendRequest('', 'get', TRUE); // default front page, visible to all.
    $this->checks($response, 200, 'text/html');
    $message = $response->getBody()->getContents();
    $this->assertisString($message);
    $response = $this->sendRequest('');
    $this->checks($response, 200, 'text/html');
    $message = $response->getBody()->getContents();
    $this->assertisString($message);
  }

  function testHandshake() {
    // By default this is only accessible for authenticated users.
    $response = $this->sendRequest('handshake');
    $this->checks($response, 200, 'application/json');
    // The structure has been checked against the API
    $nodes = json_decode($response->getBody()->getContents());
    foreach ($nodes as $status_code => $urls) {
      $this->assertIsInteger($status_code / 100);
    }
  }

  function testAccountNames() {
    global $users;
    $char = substr($users[0]->id, 0, 1);
    $response = $this->sendRequest("accountnames/$char");
    $this->checks($response, 200, 'application/json');
    $acc_ids = json_decode($response->getBody()->getContents());
    // should be a list of account names including 'a'
    foreach ($acc_ids as $acc_id) {
      $this->assertStringContainsString($char, $acc_id);
    }
  }

  function testWorkflows() {
    // By default this is only accessible for authenticated users.
    $response = $this->sendRequest('workflows');
    $this->checks($response, 200, 'application/json');
    $wfs = (array)json_decode($response->getBody()->getContents());
    $this->assertNotEmpty($wfs);
  }

  function testNewTransaction() {
    global $users;
    $this->assertGreaterThan('1', count($users));
    $obj = [
      'payee' => $users[0]->id,
      'payer' => $users[1]->id,
      'description' => 'blah',
      'quantity' => 1,
      'type' => '3rdparty'
    ];
    // 3rdParty transactions are created already complete.
    $response = $this->sendRequest('transaction/new', 'post', FALSE, json_encode($obj));
    $this->checks($response, 201, 'application/json');
    $obj = [
      'payee' => $users[0]->id,
      'payer' => $users[1]->id,
      'description' => 'blah',
      'quantity' => 1,
      'type' => 'bill'
    ];
    // 'bill' transactions must be approved, and enter pending state.
    $response = $this->sendRequest('transaction/new', 'post', FALSE, json_encode($obj));
    $this->checks($response, 200, 'application/json');
    $transaction = json_decode($response->getBody()->getContents());
    $response = $this->sendRequest("transaction/$transaction->uuid/" .reset($transaction->transitions), 'patch');
    $this->checks($response, 201);
  }


  /**
   * Todo think about how the transactions are filtered by their main properties,
   * their entry properties, and how the results are returned.
   */
  function testTransactionFilter() {
    $response = $this->sendRequest("transaction?".$querystring, 'get');
    $this->checks($response, 200, 'application/json');
    $all_transactions = json_decode($response->getBody()->getContents());
    // Check filter by state
    $response = $this->sendRequest("transaction?state=pending&full=false", 'get');
    $this->checks($response, 200, 'application/json');
    $pending_transaction_uuids = json_decode($response->getBody()->getContents());
    $this->checkTransactions($all_transactions, $pending_transaction_uuids, ['state' => 'pending']);

  }

  function testTransactionStateChange() {

  }

  function testStats() {
    global $users;
    $test_user_id = end($users)->id;
    $response = $this->sendRequest("account/history/$test_user_id");
    $response = $this->sendRequest("account/summary/$test_user_id");
    $response = $this->sendRequest("account/limits/$test_user_id");
    $this->checks($response, 200, 'application/json');
    $limits = json_decode($response->getBody()->getContents());
    $this->assertIsObject($limits);
    $this->assertObjectHasAttribute('min', $limits);
    $this->assertObjectHasAttribute('max', $limits);
    $this->assertlessThan(0, $limits->min);
    $this->assertGreaterThan(0, $limits->max);
  }


  protected function sendRequest($path, $method = 'get', bool $anon = FALSE, string $request_body = '') : Response {
    global $users;
    $app = $this->getApp();
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
      $request->getBody()->rewind();
    }
    $response = $this->getApp()->process($request, new Response());
    $response->getBody()->rewind();
    if ($response->getStatusCode() > 399) {
      // Blurt out to terminal to ensure all info is captured.
      print_r($response->getBody()->getContents()); // Seems to be truncated hmph.
      $request->getBody()->rewind();
    }
    $this->assertTrue($response->hasHeader('Node-path'));
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

  private function checks(Response $response, int $status_code, string $mime_type = '') {
    $this->assertEquals($status_code, $response->getStatusCode());
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
