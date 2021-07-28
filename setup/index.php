<?php

require_once '../vendor/autoload.php';

ini_set('display_errors', 1);

const SETTINGS_INI_FILE = '../node.ini';
const ACC_STORAGE_INI_FILE  = '../AccountStore/accountstore.ini';
$node_conf = parse_ini_file(SETTINGS_INI_FILE);
$errs = [];
if (empty($_SERVER['QUERY_STRING'])){
  if ($node_conf['account_store_url']) {
    include 'general.php';
    exit;
  }
}
else {
  include $_SERVER['QUERY_STRING'].'.php';
  exit;
}
if ($_POST) {
  if (!filter_var($_POST['account_store_url'], FILTER_VALIDATE_DOMAIN)) {
    $errs[] = "invalid Account store url";
  }
  if(!empty($_POST['account_store_url']) and !filter_var($_POST['account_store_url'], FILTER_VALIDATE_DOMAIN)) {
    $errs[] = "invalid Account store url";
  }
  if (empty($_POST['db']['name'])) {
    $errs[] = "Database name required";
  }
  if (empty($_POST['db']['user'])) {
    $errs[] = "Database user required";
  }
  if (empty($_POST['node_name'])) {
    $errs[] = 'Node name is required';
  }
  if (empty($_POST['acc']['default_max'])) {
    $_POST['acc']['default_max'] = 0;
  }
  if (empty($_POST['acc']['default_min'])) {
    $_POST['acc']['default_min'] = 0;
  }
  $_POST['acc']['default_status'] = isset($_POST['conf']['default_status']) ? 1 : 0;
  if (!$errs) {
    require './writeini.php';
    $acc = $_POST['acc'];
    unset($_POST['acc']);
    replaceIni($_POST, SETTINGS_INI_FILE);
    replaceIni($acc, ACC_STORAGE_INI_FILE);
    header('Location: index.php?accounts');
    exit;
  }
  else $values = $_POST;
}
else $values = $node_conf + parse_ini_file(ACC_STORAGE_INI_FILE);

// the following form is used once in set up
?><!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Frameset//EN" "http://www.w3.org/TR/html4/frameset.dtd">
  <head>
    <title>Credit Commons setup</title>
    <style>th{background-color: #eee;}</style>
  </head>
  <body>
    <?php if (!empty($errs)) {
      print "<p><font color=red>".implode('<br />', $errs).'</font>';
    }?>
    <form method="post">

      <p title = "This name is your node's identification on the Credit Commons tree. It is not needed for standalone nodes.">
        This node name <input name ="node_name" value ="<?php print $values['node_name']; ?>">
      </p>


      <h2>Microservices</h2>
      <p title="The reference implementation uses these two microservices (with as yet undocumented apis)">
        Account store <input name = "account_store_url" value = "<?php print $values['account_store_url']; ?>" placeholder = "https://accounts.mydomain.com">
      <br />Business logic <input name = "blogic_service_url" value = "<?php print $values['blogic_service_url']; ?>" placeholder = "https://blogic.mydomain.com">  (optional)
      </p>

      <h2>Database settings</h2>
      <p>Db name <input name = "db[name]" value = "<?php print $values['db']['name']; ?>">
        <br />Db user <input name = "db[user]" value = "<?php print $values['db']['user']; ?>">
        <br /><span title="Password is not required for the moment">Db pass <input name = "db[pass]" value = "<?php print $values['db']['pass']; ?>"></span>
      </p>


      <h2>Default values for new accounts</h2>
      <p>Max account limit: <input name="acc[default_max]" type="number" min="1" max="1000000" size="3" value="<?php print $values['default_max']; ?>" />
      <br />Min account limit: <input name="acc[default_min]" type="number" max="0" min="-1000000" size="3" value="<?php print $values['default_min']; ?>" />
      <br />Active <input name="acc[default_status]" type="checkbox" value = "1"<?php if ($values['default_status']) print ' checked'; ?> />

      <input type="submit">
    </form>
  </body>
</html>