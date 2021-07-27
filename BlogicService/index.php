<?php

namespace BlogicService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
/**
 * Business logic service
 */

$config = parse_ini_file('blogic.ini');
require_once '../vendor/autoload.php';
$app = AppFactory::create();


$app->post('/append', function (Request $request, Response $response, $args) {
  $entry  = json_decode($request->getBody()->read());
  global $config;
  if ($config['payee_fee']) {
    $additional[] = payee_fee($entry, $config['payer_fee']);
  }
  if ($config['payer_fee']) {
    $additional[] = payer_fee($entry, $config['payer_fee']);
  }
  $response->getBody()->write(json_encode($additional));
  return $response;
});

$app->run();exit;

/**
 * Charge the payee.
 */
function payee_fee(stdClass $entry, $fee) : stdClass {
  global $config;
  // Might want to author with the authenticaed account rather than $fees account
  $fee = calc($entry->quant, $fee);
  return (object)[
    'payer' => $entry->payee,
    'payee' => $config['fees_account'],
    'author' => $config['fees_account'],
    'quant' => $fee,
    'description' => "payee fee of $fee to ".$config['fees_account']
  ];
}

/**
 * Charge the payer.
 */
function payer_fee(stdClass $entry, $fee) : stdClass {
  global $config;
  $fee = calc($entry->quant, $fee);
  // Might want to author with the authenticated account rather than $fees account
  return (object)[
    'payer' => $entry->payer,
    'payee' => $config['fees_account'],
    'author' => $config['fees_account'],
    'quant' => $fee,
    'description' => "payer fee of $fee to ".$config['fees_account']
  ];
}

/**
 *
 * @param float $quant
 * @param float $fee
 * @return float
 */
function calc(float $quant, float $fee) {
  preg_match('/([0-9.])(%?)/', $fee, $matches);
  $num = $matches[1];
  $percent = $matches[2];
  if ($percent) {
    $val = (float)$quant * $num/100;
  }
  else {
    $val =  (float)$num;
  }
  return (float)$val;
}
