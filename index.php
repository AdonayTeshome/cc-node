<?php
/**
 * Reference implementation of a credit commons node
 */

declare(strict_types=1);

ini_set('display_errors', '1');

$config = parse_ini_file('./node.ini');
if (empty($config['node_name'])) {
  header('Location: setup/index.php');
}

require_once './vendor/autoload.php';

// It helps the testing framework to have the main app in a seperate file.
require './slimapp.php';

$app->run();
