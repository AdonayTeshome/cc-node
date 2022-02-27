<?php

namespace CCNode\Tests;

use League\OpenAPIValidation\PSR15\ValidationMiddlewareBuilder;
use League\OpenAPIValidation\PSR15\SlimAdapter;
use Slim\Psr7\Response;
use PHPUnit\Framework\TestCase;

class TestBase extends TestCase {

  protected $users = [];//todo

  protected function sendRequest($path, int|string $expected_response, string $acc_id = '', string $method = 'get', string $request_body = '') : \stdClass|NULL|array {
    global $users;
    $request = $this->getRequest($path, $method);
    if ($acc_id) {
      $request = $request->withHeader('cc-user', $acc_id)
        ->withHeader('cc-auth', $users[$acc_id]);
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
      $e = \CreditCommons\RestAPI::reconstructCCException($contents);
      $this->assertInstanceOf("CreditCommons\Exceptions\\$expected_response", $e);
    }
    return $contents;
  }

  protected function getRequest($path, $method = 'GET') {
    $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();
    return $psr17Factory->createServerRequest(strtoupper($method), '/'.$path);
  }

  /**
   *
   * @staticvar string $app
   *   address of api file relative to the application root
   * @param type $api_file
   * @return \Slim\App
   */
  protected function getApp(): \Slim\App {
    static $app;
    if (!$app) {
      $app = require_once __DIR__.'/../'.static::SLIM_PATH;
      if (static::API_FILE_PATH) {
        $spec = file_get_contents(__DIR__.'/../'.static::API_FILE_PATH);
        $psr15Middleware = (new ValidationMiddlewareBuilder)
          ->fromYaml($spec)
          ->getValidationMiddleware();
        $middleware = new SlimAdapter($psr15Middleware);
        $app->add($middleware);
      }
    }
    return $app;
  }


}
