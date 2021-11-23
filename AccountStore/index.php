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
$app->get('/filter[/{chars}]', function (Request $request, Response $response, $args) {
  $accounts = new AccountManager();
  $params = $request->getQueryParams();
  // For now support chars via path or query
  $chars = $args['chars']??$params['chars']??'';
  if (!empty($chars)) {
    $accounts->filterByName($chars);
  }
  if (!empty($params['status'])) {
    $accounts->filterByStatus((bool)$params['status']);
  }
  if (!empty($params['local'])) {
    $accounts->filterByLocal((bool)$params['local']);
  }
  $result = $accounts->view($params['view_mode']??'full'); // array
  $response->getBody()->write(json_encode($result));
  return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/{acc_id}[/{view_mode}]', function (Request $request, Response $response, $args) {
  // View_mode can be either name, full, own (with null for default values)
  $accounts = new AccountManager();
  // To retrieve all accounts you would have to send status = NULL
  $args += ['view_mode' => 'full'];
  if (isset($args['acc_id'])) {
    if ($accounts->has($args['acc_id'])) {
      $account = $accounts[$args['acc_id']]->view($args['view_mode']);
      $response->getBody()->write(json_encode($account));
    }
    else{
      throw new DoesNotExistViolation(['type' => 'account', 'id' => $args['acc_id']]);
    }
  }
  else {
    throw new HttpNotFoundException();
  }
  return $response->withHeader('Content-Type', 'application/json');
});


$app->post('/{type}', function (Request $request, Response $response, $args) {
  // check permission?
  $accounts = new AccountManager();
  //this is NOT a json object
  parse_str($request->getBody()->getContents(), $params);
  $data = (object)$params;
  $data->created = time();
  $data->id = strtolower($data->id);
  if (!$accounts->validName($data->id)) {
    throw new HttpBadRequestException();
  }
  elseif (!$accounts->availableName($data->id)) {
    throw new HttpBadRequestException(); // this should actually be the credit commons duplicate exception
  }
  elseif (!$auth = $data->url??$data->key) {
    throw new HttpBadRequestException();
  }
  elseif ($args['type'] == 'node' and isset($data->url)) {
    $record = new RemoteRecord($data);
  }
  elseif ($args['type'] == 'user' and isset($data->key)) {
    $record = new UserRecord($data);
  }
  else {
    throw new HttpBadRequestException();
  }
  if (isset($record)) {
    $record->set($data);
    $accounts->addAccount($record);
    $accounts->save();
    // Todo clarify what this should return.
    $response->getBody()->write(json_encode($record));
    $response = $response->withStatus(201);
  }
  return $response;
});

$app->patch('/{acc_id}', function (Request $request, Response $response, $args) {
  $accounts = new AccountManager();
  if (!$accounts->has($args['acc_id'])) {
    return $response->withStatus(404);
  }
  $contents = $request->getBody()->getContents();
  $values = json_decode($contents);
  $accounts[$args['acc_id']]->set($values);
  $accounts->save();
  return $response->withStatus(200);
});

$app->run();
