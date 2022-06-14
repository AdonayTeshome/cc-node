<?php
/**
 * Reference implementation of a credit commons node
 *
 * @todo Update to slim 4 using https://github.com/thephpleague/openapi-psr7-validator/issues/136
 *
 */

use CreditCommons\Exceptions\CCFailure;
use CCNode\Slim3ErrorHandler;
use CCNode\PermissionMiddleware;
use CreditCommons\NewTransaction;
use CCNode\Transaction\TransversalTransaction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

// Slim4 (when the League\OpenAPIValidation is ready)
//use Slim\Factory\AppFactory;
//use Psr\Http\Message\ServerRequestInterface;
//$app = AppFactory::create();
//$app->addErrorMiddleware(true, true, true);
//$app->addRoutingMiddleware();
//$errorMiddleware = $app->addErrorMiddleware(true, true, true);
//// See https://www.slimframework.com/docs/v4/middleware/error-handling.html
//// Todo this would be tidier in a class of its own extending Slim\Handlers\ErrorHandler.
//// Note that v4 has $app->addBodyParsingMiddleware();
////This handler converts the CCError exceptions into Json and returns them.
//$errorMiddleware->setDefaultErrorHandler(function (
//    ServerRequestInterface $request,
//    \Throwable $exception,
//    bool $displayErrorDetails,
//    bool $logErrors,
//    bool $logErrorDetails
//) use ($app) {
//    $response = $app->getResponseFactory()->createResponse();
//    if (!$exception instanceOf CCError) {
//      $exception = new CCFailure($exception->getMessage());
//    }
//    $response->getBody()->write(json_encode($exception, JSON_UNESCAPED_UNICODE));
//    return $response->withStatus($exception->getCode());
//});

$app = new App();
$app;
$c = $app->getContainer();
$getErrorHandler = function ($c) {
  return new Slim3ErrorHandler();
};
$c['errorHandler'] = $getErrorHandler;
$c['phpErrorHandler'] = $getErrorHandler;

/**
 * Default HTML page. (Not part of the API)
 */
$app->get('/', function (Request $request, Response $response) {
  header('Location: index.html');
  exit;
});

global $node, $config;
$config = new \CCNode\ConfigFromIni(parse_ini_file('node.ini'));
require 'Node.php';
$node = new Node($config);
/**
 * Implement the Credit Commons API methods
 */
$app->options('/{id:.*}', function (Request $request, Response $response, $args) {
  global $node;
  $options = empty($args['id']) ? $node->getOptions() : [];
  return json_response($response, $options);
}
)->setName('permittedEndpoints')->add(PermissionMiddleware::class);

$app->get('/workflows', function (Request $request, Response $response) {
  global $node;
  return json_response($response, $node->getWorkflows());
}
)->setName('workflows')->add(PermissionMiddleware::class);

$app->get('/handshake', function (Request $request, Response $response) {
  global $node;
  return json_response($response, $node->handshake());
}
)->setName('handshake')->add(PermissionMiddleware::class);

$app->get('/absolutepath', function (Request $request, Response $response) {
  global $node;
  return json_response($response, $node->getAbsolutePath());
}
)->setName('absolutePath')->add(PermissionMiddleware::class);

// Conceivably acc_paths could be up to 10 items deep.
$acc_path = '/{acc_path1}[/[{acc_path2}[/[{acc_path3}[/]]]]]';
$app->get("/accounts/names[$acc_path]", function (Request $request, Response $response, $args) {
  global $node;
  $acc_path = extractAccPathParams($args, $request);
  // Assuming the limit is 10.
  return json_response($response, $node->accountNameFilter($acc_path, $request->getQueryParams()['limit'] ??'10'));
}
)->setName('accountNameFilter')->add(PermissionMiddleware::class);

$app->get("/account/summary[$acc_path]", function (Request $request, Response $response, $args) {
  global $node;
  $acc_path = extractAccPathParams($args, $request);
  print_r($node->getAccountSummary($acc_path));
  return json_response($response, $node->getAccountSummary($acc_path));
}
)->setName('accountSummary')->add(PermissionMiddleware::class);

$app->get("/account/limits[$acc_path]", function (Request $request, Response $response, $args) {
  global $node;
  $acc_path = extractAccPathParams($args, $request);
  return json_response($response, $node->getAccountLimits($acc_path));
}
)->setName('accountLimits')->add(PermissionMiddleware::class);

$app->get("/account/history[$acc_path]", function (Request $request, Response $response, $args) {
  global $node;
  $acc_path = extractAccPathParams($args, $request);
  $params = $request->getQueryParams() + ['samples' => -1];
  $result = $node->getAccountHistory($acc_path, $params['samples']);
  $response->getBody()->write(json_encode($result));
  return $response;
}
)->setName('accountHistory')->add(PermissionMiddleware::class);

$uuid_regex = '[0-9a-f]{8}\b-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-\b[0-9a-f]{12}';
// Retrieve one transaction
$app->get('/transaction/{uuid:'.$uuid_regex.'}', function (Request $request, Response $response, $args) {
  global $node;
  $uuid = array_shift($args);
  $params = $request->getQueryParams();
  $transaction = $node->getTransaction($uuid, $params['entries'] ?? 'false');
  return json_response($response, $transaction);
}
)->setName('getTransaction')->add(PermissionMiddleware::class);

// Create a new transaction
$app->post('/transaction', function (Request $request, Response $response) {
  global $user, $node;
// TODO try $request->getparsedBody()
  $request->getBody()->rewind(); // Testing Framework Middleware leaves this at the end.
  $data = json_decode($request->getBody()->getContents());
  // validate the input and create UUID
  $new_transaction = NewTransaction::createFromLeaf($data);
  // Send the whole transaction back via jsonserialize to the user.
  // the workflow ultimately determines whether the transaction is temp or written
  $transaction = $node->submitNewTransaction($new_transaction);
  $status_code = $transaction->version < 1 ? 200 : 201;
  return json_response($response, $transaction, $status_code);
}
)->setName('newTransaction')->add(PermissionMiddleware::class);

// Relay a new transaction
$app->post('/transaction/relay', function (Request $request, Response $response) {
  global $user, $node;
  $request->getBody()->rewind(); // ValidationMiddleware leaves this at the end.
  $data = json_decode($request->getBody()->getContents());
  // Convert the incoming amounts as soon as possible.
  $user->convertIncomingEntries($data->entries);
  $transaction = TransversalTransaction::createFromUpstream($data);
  $additional_entries = $node->buildValidateRelayTransaction($transaction);
  // $additional_entries via jsonSerialize
  return json_response($response, $additional_entries, 201);
}
)->setName('relayTransaction')->add(PermissionMiddleware::class);

$app->patch('/transaction/{uuid:'.$uuid_regex.'}/{dest_state}', function (Request $request, Response $response, $args) {
  global $node;
  $written = $node->transactionChangeState($args['uuid'], $args['dest_state']);
  return $response->withStatus($written ? 201 : 200);
}
)->setName('stateChange')->add(PermissionMiddleware::class);

if ($config->devMode) {
  // this stops execution on ALL warnings and returns CCError objects
  set_error_handler( '\exception_error_handler' );
}

// Filter transactions
$app->get("/transactions", function (Request $request, Response $response, $args) {
  global $node;
  $params = $request->getQueryParams();
  return json_response($response, $node->filterTransactions($params));
}
)->setName('filterTransactions')->add(PermissionMiddleware::class);


return $app;

/**
 * Populate a json response.
 *
 * @param Response $response
 * @param stdClass|array $body
 * @param int $code
 * @return Response
 *
 * @todo this should be moved to middleware
 */
function json_response(Response $response, $body = NULL, int $code = 200) : Response {
  if (is_scalar($body)){
    throw new CCFailure('Illegal value passed to json_response()');
  }
  $contents = json_encode($body, JSON_UNESCAPED_UNICODE);
  $response->getBody()->write($contents);
  return $response->withStatus($code)
    ->withHeader('Access-Control-Allow-Origin', '*')
    ->withHeader('Access-Control-Allow-Methods', 'GET')
    ->withHeader('Access-Control-Allow-Headers', 'content-type, cc-user, cc-auth')
    ->withHeader('Vary', 'Origin')
    ->withHeader('Content-Type', 'application/json');
}

/**
 * Custom error handling.
 * Everything, even warnings are logged locally AND thrown back to the client.
 */
function exception_error_handler( $severity, $message, $file, $line ) {
  throw new CCFailure("$message in $file: $line");
}

/**
 * Currently the testing framework doesn't allow optional args, so paths accept 3 and only 3 args which are populated by null
 * @param array $args
 */
function extractAccPathParams(array &$args, Request $request) : string {
  while (end($args) == 'null') {
    array_pop($args);
  }
  $path = implode('/', $args);
  // It is important to preserve the trailing slash.
  if (substr($request->getUri(), -1) == '/') {
    $path .= '/';
  }
  return $path;
}