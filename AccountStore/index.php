<?php

namespace AccountStore;
use AccountStore\AccountManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;
use CreditCommons\Exceptions\DoesNotExistViolation;
use Slim\App;

/**
 * AccountStore service
 *
 * Service providing information about accountholders. This implementation is
 * very primitive:
 * - It stores data in a single json file
 * - Allows default values and overriding of defaults.
 * - Allows filtering of users on status, name, and whetther the accounts are local or pointers to trunkward or leafward ledgers.
 * Normally this service would be replaced by a wrapper around an existing system of accounts.
 */

ini_set('display_errors', 1);
$config = parse_ini_file('accountstore.ini');
require_once '../vendor/autoload.php';
$app = new App();
$app->get('/filter', function (Request $request, Response $response) {
  $accounts = new AccountManager(TRUE);
  $params = $request->getQueryParams();
  if (!empty($params['chars'])) {
    $accounts->filterByName($params['chars']);
  }
  if (!empty($params['status'])) {
    $accounts->filterByStatus((bool)$params['status']);
  }
  if (!empty($params['local'])) {
    $accounts->filterByLocal((bool)$params['local']);
  }
  $result = !empty($params['nameonly']) ? array_keys($accounts->accounts) : array_values($accounts->accounts);
  $response->getBody()->write(json_encode($result));
  return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/fetch[/{acc_id}]', function (Request $request, Response $response, $args) {
  $accounts = new AccountManager(TRUE); // defaults, not overrides
  $params = $request->getQueryParams() + ['status' => TRUE, 'view_mode' => 'name'];
  if (isset($args['acc_id'])) {
    if (isset($accounts[$args['acc_id']])) {
      $response->getBody()->write(json_encode($accounts[$args['acc_id']]));
    }
    else{
      throw new DoesNotExistViolation(['type' => 'account', 'id' => $args['acc_id']]);
      throw new HttpNotFoundException();
    }
  }
  else {
    $result = !empty($params['nameonly']) ? array_keys($accounts->accounts) : $accounts->view($params['view_mode']);
    $response->getBody()->write($result);
  }
  return $response->withHeader('Content-Type', 'application/json');
});


$app->get('/overriden/{acc_id}', function (Request $request, Response $response, $args) {
  $accounts = new AccountManager(FALSE);
  $response->getBody()->write(json_encode($accounts[$args['acc_id']]->overridden()));
  return $response;
});

$app->post('/join/{type}', function (Request $request, Response $response, $args) {
  // check permission?
  $accounts = new AccountManager(FALSE);
  //this is NOT a json object
  parse_str($request->getBody()->getContents(), $params);
  $data = (object)$params;
  $acc_id = strtolower($data->id);
  if (!$accounts->validName($acc_id)) {
    throw new HttpBadRequestException();
  }
  elseif (!$accounts->availableName($acc_id)) {
    throw new HttpBadRequestException(); // this should actually be the credit commons duplicate exception
  }
  elseif (!$auth = $data->url??$data->key) {
    throw new HttpBadRequestException();
  }
  elseif ($args['type'] == 'node' and isset($data->url)) {
    $record = new RemoteRecord($acc_id, $data->url, time());
  }
  elseif ($args['type'] == 'user' and isset($data->key)) {
    $record = new UserRecord($acc_id, $data->key, time());
  }
  else {
    throw new HttpBadRequestException();
  }
  if (isset($record)) {
    $record->override((array)$data);
    $accounts->addAccount($record);
    $accounts->save();
    // Todo clarify what this should return.
    $response->getBody()->write(json_encode($record));
    $response = $response->withStatus(201);
  }
  return $response;
});

$app->patch('/override/{id}', function (Request $request, Response $response, $args) {
  $accounts = new AccountManager(FALSE);
  //field input is NOT a json object
  parse_str($request->getBody()->getContents(), $params);
  $accounts[$args['id']]->override($params);
  $accounts->save();
  return $response->withStatus(201);
});

$app->run();
