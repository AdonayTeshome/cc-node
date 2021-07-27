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
    $body = $this->send('', 200, 'options', TRUE);
    $this->assertArrayHasKey("permittedEndpoints", $body);
    $this->assertArrayHasKey("workflows", $body);
  }

  function testAbsoluteAddress() {
    $body = $this->send('', 400, 'get', TRUE);
    $body = $this->send('', 200, 'get');
    $this->assertisArray($body);
  }

  function testHandshake() {
    $body = $this->send('handshake', 200);
    // $body is a list of all the connected nodes, keyed by status code.
  }

  function testAccountNames() {
    global $users;
    $char = substr($users[0]->id, 0, 1);
    $body = $this->send("accounts/$char", 200);
    // should be a list of account names including 'a'
    foreach ($body as $acc_id) {
      $this->assertStringContainsString($char, $acc_id);
    }
  }



  protected function send($path, $expected_code, $method = 'get', bool $anon = FALSE, $body = NULL ): array {
    global $users;
    $app = $this->getApp();
    $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();
    $request = $psr17Factory->createServerRequest(strtoupper($method), '/'.$path);
    if (!$anon) {
      $firstuser = reset($users);
      $request = $request->withHeader('cc-user', $firstuser->id);
      $request = $request->withHeader('cc-auth', $firstuser->key);
    }
    if ($body) {

    }
    $response = $this->getApp()->process($request, new Response());
    $body = json_decode((string)$response->getBody(), true);

    $this->assertEquals($expected_code, $response->getStatusCode());
    if ($response->getStatusCode() <> $expected_code) {
      print_r($body);
    }
    return $body;
  }


    /**
     * @return \Slim\App
     */
    protected function getApp(): \Slim\App {
      static $app;
      if (!$app) {
        $app = require_once __DIR__.'/../slimapp.php';
        $spec = __DIR__.'/../vendor/matslats/cc-php-lib/docs/credit-commons-openapi-3.0.yml';
        $psr15Middleware = (new ValidationMiddlewareBuilder)->fromYaml(file_get_contents($spec))->getValidationMiddleware();
        $middleware = new SlimAdapter($psr15Middleware);
        $app->add($middleware);
      }
      return $app;
    }

}
