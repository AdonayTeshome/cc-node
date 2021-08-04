<?php


use League\OpenAPIValidation\PSR15\ValidationMiddlewareBuilder;
use League\OpenAPIValidation\PSR15\SlimAdapter;
use Slim\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

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
    $this->assertEquals(200, $response->getStatusCode());
    $body = json_decode($response->getBody()->getContents());
    $this->assertObjectHasAttribute("permittedEndpoints", $body);
    $this->assertObjectNotHasAttribute("accountSummary", $body);
    $this->assertObjectNotHasAttribute("filterTransactions", $body);
    $response = $this->sendRequest('', 'options');
    $this->checks($response, 200, 'application/json');
    $body = json_decode($response->getBody()->getContents());
    $this->assertObjectHasAttribute("filterTransactions", $body);
    $this->assertObjectHasAttribute("accountSummary", $body);
  }

  function testRootPath() {
    $response = $this->sendRequest('', 'get', TRUE); // default front page, visible to all.
    $this->assertEquals(200, $response->getStatusCode());
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
    $this->assertEquals(200, $response->getStatusCode());
    // The body consists of all the trunkward nodes.
    $nodes = json_decode($response->getBody()->getContents());
    $this->assertisArray($nodes);
  }

  function testAccountNames() {
    global $users;
    $char = substr($users[0]->id, 0, 1);
    $response = $this->sendRequest("accountnames/$char");
    $this->assertEquals(200, $response->getStatusCode());
    $acc_ids = json_decode($response->getBody()->getContents());
    $this->assertIsArray($acc_ids);
    // should be a list of account names including 'a'
    foreach ($acc_ids as $acc_id) {
      $this->assertStringContainsString($char, $acc_id);
    }
  }

  function testWorkflows() {
    // By default this is only accessible for authenticated users.
    $response = $this->sendRequest('workflows');
    $this->assertEquals(200, $response->getStatusCode());
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
    // 3rd party transactions are created already complete.
    $response = $this->sendRequest('transaction/new', 'post', FALSE, json_encode($obj));
    $this->assertEquals(201, $response->getStatusCode());
    $obj = [
      'payee' => $users[0]->id,
      'payer' => $users[1]->id,
      'description' => 'blah',
      'quantity' => 1,
      'type' => 'bill'
    ];
    // 'bill' transactions must be approved, and enter pending state.
    $response = $this->sendRequest('transaction/new', 'post', FALSE, json_encode($obj));
    $this->assertEquals(200, $response->getStatusCode());
    $transaction = json_decode($response->getBody()->getContents());
    $response = $this->sendRequest("transaction/$transaction->uuid/" .reset($transaction->transitions), 'patch');
    $this->assertEquals(201, $response->getStatusCode());
  }

  function testStats() {
    global $users;
    $test_user_id = end($users)->id;
    $response = $this->sendRequest("account/$test_user_id/history");
    $response = $this->sendRequest("account/$test_user_id/summary");

    $response = $this->sendRequest("account/limits/$test_user_id");
    $this->assertEquals(200, $response->getStatusCode());
    $limits = json_decode($response->getBody()->getContents());
    $this->assertIsObject($limits);
    $this->assertObjectHasAttribute('min', $limits);
    $this->assertObjectHasAttribute('max', $limits);
    $this->assertlessThan(0, $limits->min);
    $this->assertGreaterThan(0, $limits->max);
  }


  protected function sendRequest($path, $method = 'get', bool $anon = FALSE, string $request_body = '') {
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
      print_r($response->getBody()->getContents());
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

}
