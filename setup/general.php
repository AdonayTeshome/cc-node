<?php
//namespace CCnode;
ini_set('display_errors', 1);
use CCNode\Db;
const NODE_SETTINGS_FILE = '../node.ini';
require_once '../vendor/autoload.php';
$node_config = parse_ini_file(NODE_SETTINGS_FILE);

if ($_POST) {
  unset($_POST['submit']);
  $errs = [];

  // since unchecked checkboxes don't appear in the post, put a zero
  if (!isset($_POST['transmit_metadata'])) {
    $_POST['transmit_metadata'] = 0;
  }
  if (!isset($_POST['zero_payments'])) {
    $_POST['zero_payments'] = 0;
  }

  if (!$errs) {
    require './writeini.php';
    replaceIni($_POST, NODE_SETTINGS_FILE);
    if (empty($node_config['db']['name'])) {
      $connection = new mysqli('localhost', $_POST['db']['user'], $_POST['db']['pass']);
      $connection->query("DROP DATABASE ".$_POST['db']['name']);
      $connection->query("CREATE DATABASE ".$_POST['db']['name']);
      Db::connect($_POST['db']['name'], $_POST['db']['user'], $_POST['db']['pass']);
      foreach (explode(';', file_get_contents('install.sql')) as $q) {
        if ($query = trim($q)) {
          Db::query($query);
        }
      }
      // TODO need to be sure this process actually worked.
    }
print "Check the db is created and then set up <a href=\"accounts.php\">the accounts</a>.";exit;
  }

}
$node_config = parse_ini_file(NODE_SETTINGS_FILE);

if (!is_writable(NODE_SETTINGS_FILE)) {
  $errs[] = NODE_SETTINGS_FILE . " is not writable";
}
?><!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Frameset//EN" "http://www.w3.org/TR/html4/frameset.dtd">
  <head>
    <title>Credit Commons setup</title>
  </head>
  <body>
    <h1>Credit Commons: General settings</h1>
    <p>Hover for help. To edit settings after this setup, see the ini files;
      <br />Or go to <a href = "index.php?accounts">account settings</a>.
    <?php if (!empty($errs)) print "<p><font color=red>".implode('<br />', $errs).'</font>'; ?>
    <form method="post">
      <h2>Transactions</h2>
      <p><span title="This information could only be used by the end client or for formatting info to send to the client. It is not currently used">Name of unit <input name = "currency_name" value = "<?php print $node_config['currency_name']; ?>" disabled></span>
      <br /><span title="This information could only be used by the end client or for formatting info to send to the client. It is not currently used">Decimal places displayed <input name = "decimal_places" type = "number" min = "0" max = "3" size = "1" value = "<?php print $node_config['decimal_places']; ?>" disabled>
      <br /><span title="Some social currencies like to register transactions for zero amount">Allow zero payments <input name = "zero_payments" type = "checkbox" value = "<?php print $node_config['zero_payments']; ?>"></span>
      <br /><span title="At the moment the only metadata is the account names of leafward traders">Transmit transaction metadata <input name = "transmit_metadata" type = "checkbox" value = "<?php print $node_config['transmit_metadata']; ?>"></span>

      <h2>Performance</h2>
      <p>Timeout in seconds<input name = "timeout" type = "number" min = "0" max = "60" size = "1" value = "<?php print $node_config['timeout']; ?>">
      <br />(Needs to be longer for nodes far away from the trunk)
      </p>


      <p><input type ="submit" value ="save"></p>
    </form>

  </body>
</html><?php

function replaceIni(array $array, string $filename): int {
  $inifile = file_get_contents($filename);
  foreach($array as $key => $val) {
    if(is_array($val)) {
      foreach($val as $skey => $sval) {
        $line_id = "$key[$skey]";
        preg_replace("/^$key ?=.*$/", "$key = ".(is_numeric($val) ? $val : '"'.$val.'"'), $inifile);
      }
    }
    else {
      preg_replace("/^$key ?=.*$/", "$key = ".(is_numeric($val) ? $val : '"'.$val.'"'), $inifile);
    }
  }
  return file_put_contents($filename, $inifile);
}
