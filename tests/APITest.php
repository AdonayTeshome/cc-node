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
//
//  function testEndpoints() {
//    $response = $this->sendRequest('', 'options', TRUE);
//    $this->checks($response, 200, 'application/json');
//    $body = json_decode($response->getBody()->getContents());
//    $this->assertObjectHasAttribute("permittedEndpoints", $body);
//    $this->assertObjectNotHasAttribute("accountSummary", $body);
//    $this->assertObjectNotHasAttribute("filterTransactions", $body);
//    $response = $this->sendRequest('', 'options');
//    $this->checks($response, 200, 'application/json');
//    $body = json_decode($response->getBody()->getContents());
//    $this->assertObjectHasAttribute("filterTransactions", $body);
//    $this->assertObjectHasAttribute("accountSummary", $body);
//  }
//
//  function testRootPath() {
//    $response = $this->sendRequest('', 'get', TRUE); // default front page, visible to all.
//    $this->checks($response, 200, 'text/html');
//    $message = json_decode($response->getBody()->getContents());
//    $this->assertisString($message);
//    $response = $this->sendRequest('');
//    $this->checks($response, 200, 'text/html');
//    $message = json_decode($response->getBody()->getContents());
//    $this->assertisString($message);
//  }

    /**
     * @runInSeparateProcess
     */
  function testHandshake() {
    $response = $this->sendRequest('handshake');
    $this->checks($response);
    $nodes = json_decode($response->getBody()->getContents());
    $this->assertisArray($nodes);
  }
//
//  function testAccountNames() {
//    global $users;
//    $char = substr($users[0]->id, 0, 1);
//    $response = $this->sendRequest("accountnames/$char");
//    $this->checks($response);
//    $acc_ids = json_decode($response->getBody()->getContents());
//    $this->assertIsArray($acc_ids);
//    // should be a list of account names including 'a'
//    foreach ($acc_ids as $acc_id) {
//      $this->assertStringContainsString($char, $acc_id);
//    }
//  }



  protected function sendRequest($path, $method = 'get', bool $anon = FALSE, $request_body = NULL) {
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
      $request = $request->withBody($request_body);
    }
    $response = $this->getApp()->process($request, new Response());

    $response->getBody()->rewind();
    return $response;
  }

  function checks(ResponseInterface $response, $code = 200, $content_type = '') {
    $this->assertTrue($response->hasHeader('Node-name'));
    $this->assertEquals($code, $response->getStatusCode());

    if ($content_type) {
      $this->assertTrue($response->hasHeader('content-type'));
      $this->assertEquals($content_type, $response->getHeaderLine('content-type'));
    }


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
