<?php
use CCNode\AccountStore;
use AccountStore\AccountManager;
$store = '../AccountStore/'.AccountManager::FILESTORE;
touch($store);
ini_set('display_errors', 1);

$errs = [];
if (!is_writable($store)) {
  $errs[] = $store . " is not writable";
}
if ($_POST) {
  unset($_POST['submit']);

  if (!$errs) {
    require './writeini.php';
    if ($_POST['user']['new']['id']) {
      if (empty($_POST['user']['new']['key'])) $errs[] = 'New users must have an api key';
      else add_account('user', $_POST['user']['new']);
    }
    unset($_POST['user']['new']);
    if (@$_POST['node']['new']['id']) {
      if (empty($_POST['node']['new']['url'])) $errs[] = 'New nodes must have a url';
      add_account('node', $_POST['node']['new']);
    }
    unset($_POST['node']['new']);
    if (isset($_POST['user'])) {
      foreach ($_POST['user'] as $id => $fields) {
        mod_account('user', $id, $fields);
      }
    }
    if (isset($_POST['node'])) {
      foreach ($_POST['node'] as $id => $fields) {
        mod_account('node', $id, $fields);
      }
    }
    $node_conf = parse_ini_file(SETTINGS_INI_FILE);
    if ($node_conf['bot']['acc_id'] or !empty($_POST['bot']['acc_id'])) {
      $accs = load_accounts();
      if (isset($_POST['bot']['acc_id'])) {
        add_account('node', $_POST['bot']);
      }
      elseif (isset($accs[$node_conf['bot']['acc_id']])) {
        mod_account('node', $node_conf['bot']['acc_id'], $_POST['bot']);
      }
      else{
        $errs[] = "Balance of trade account does not exist: ". $node_conf['bot']['acc_id'];
      }
      // populate unchecked boxes
      $bot_settings = $_POST['bot'] + ['priv_accounts' => 0, 'priv_transactions' => 0, 'priv_stats' => '0', 'metadata' => 0];
      replaceIni(['bot' => $bot_settings], SETTINGS_INI_FILE);
    }
  }
}
$node_conf = parse_ini_file(SETTINGS_INI_FILE);
$accs = load_accounts();

?><!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Frameset//EN" "http://www.w3.org/TR/html4/frameset.dtd">
  <head>
    <title>Credit Commons setup</title>
    <style>th{background-color: #eee;}</style>
  </head>
  <body>
    <h1>Credit Commons: Account settings</h1>
    <p>Hover for help. Account information is stored in accounts.json
    <br />Or go to <a href = "index.php?general">general settings</a>.
    <?php if ($errs)
      print "<p><font color=red>".implode('<br />', $errs).'</font>';
    ?><form method="post">
      <h2>User accounts</h2>
      <p>Resubmit the form to create at least two accounts.
      <table cellpadding="2">
        <thead>
          <tr>
            <th title = "Wallet id, must be unique on this node">Name</th>
            <th title = "Password-like string">API Key</th>
            <th title = "Account is active or blocked (override)">Status</th>
            <th title = "Minimum/Maximium balance (override default @todo)">Min/Max</th>
            <th title = "Checked if this account has admin privileges">Admin</th>
          </tr>
        </thead>
        <tbody>
        <?php
      $users = array_filter($accs, function($a){return !empty($a->key);});
      foreach ($users as $id => $acc) : ?>
    <tr>
            <th title = "Wallet id, must be unique on this node"><?php print $id;?><!--<input type="hidden" name="user[<?php print $id;?>][id]" value = "<?php print $id;?>">--></th>
            <td title = "Password-like string"><input name="user[<?php print $id;?>][key]" value="<?php print $acc->key;?>" size = "6"></td>
            <?php print status_cell('td','user['.$id.']', $acc); ?>
            <?php print minmax_cell('td','user['.$id.']', $acc); ?>
            <td title = "Checked if this account has admin privileges"><input name="user[<?php print $id;?>][admin]" type="checkbox" value = "1" <?php print !empty($acc->admin)?'checked':'';?>></td>
          </tr>
      <?php endforeach; ?>
      <tr>
          <td title = "Wallet id, must be unique on this node"><input name="user[new][id]" size = "8" placeholder = "new_acc_id"></td>
          <td title = "Password-like string"><input name="user[new][key]" size = "8"></td>
          <?php print status_cell('td', 'user[new]'); ?>
          <?php print minmax_cell('td', 'user[new]'); ?>
          <td title = "Checked if this account has admin privileges"><input name="user[new][admin]" type="checkbox" value = "1" ></td>
        </tr>
      </table>

      <h2>Leafward nodes</h2>
      <p>Special accounts which are controlled by other, credit commons nodes.
      <table>
      <thead>
        <tr>
          <th title = "Wallet id, must be unique on this node">name</th>
          <th title = "Url of the node">url</th>
          <th title = "Account is active or blocked (override)">status</th>
          <th title = "Minimum/Maximum balance (override default @todo)">Min/Max</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $nodes = array_filter(
        $accs,
        function($a, $id) use ($node_conf){return !empty($a->url) and $id <> $node_conf['bot']['acc_id'];},
        ARRAY_FILTER_USE_BOTH
      );
      foreach ($nodes as $id => $acc) : ?>
      <tr>
        <th><?php print $id;?></th>
        <td title = "Url of the node">
          <input name="node[<?php print $id;?>][url]" value="<?php print $acc->url;?>" size = "8">
        </td>
        <?php print status_cell('td', 'node['.$id.']', $acc); ?>
        <?php print minmax_cell('td', 'node['.$id.']', $acc); ?>
      </tr>
      <?php endforeach; ?>
      <tr>
        <td title = "Wallet id, must be unique on this node"><input name="node[new][id]" size = "8" placeholder = "new_account_id" value="<?php $bot_name;?>"></td>
        <td title = "Root url of the remote node"><input name="node[new][url]" size = "8"  value="<?php $bot_url;?>"></td>
        <?php print status_cell('td', 'node[new]'); ?>
        <?php print minmax_cell('td', 'node[new]'); ?>
      </tr>
      </tbody>
      </table>

      <h2>Balance of Trade account</h2>
      <p>Special account used to connect to the wider Credit Commons tree.
        <?php $bot = $accs[$node_conf['bot']['acc_id']]??NULL; ?><p>
        ID <input name="bot[acc_id]" size = "8" placeholder = "new_account_id" value="<?php print $node_conf['bot']['acc_id']; ?>" <?php if ($node_conf['bot']['acc_id'])print ' disabled';?>> (cannot be changed)
        <br />Url <input name="bot[url]" size = "8" value="<?php print $bot?$bot->url:''; ?>" <?php if ($bot and $bot->url)print ' disabled';?>>
        <p>Min/Max <?php print minmax_cell('span', 'bot', $bot); ?>
      <p><span title="The ratio of the local unit to the Branchward ledger's unit @todo clarify which way around this is">Exchange rate <input name = "bot[rate]" type = "number" min = "0.001" max = "1000" step = "0.001" size = "2" value = "<?php print $node_conf['bot']['rate']; ?>"></span>
      <p title = "Privacy settings: which aspects of the ledger are visible to the public?">
        Expose account Ids <input name = "bot[priv_accounts]" type = "checkbox" value = "1" <?php print $node_conf['bot']['priv_accounts'] ? 'checked ': ''; ?>>
      <br />Expose account transactions <input name = "bot[priv_transactions]" type = "checkbox" value = "1" <?php print $node_conf['bot']['priv_transactions'] ? 'checked ': ''; ?>>
      <br />Expose anonymised stats<input name = "bot[priv_stats]" type = "checkbox" value = "1" <?php print $node_conf['bot']['priv_stats'] ? 'checked ': ''; ?>>
      <br />Transaction metadata <input name = "bot[metadata]" type = "checkbox" value = "1" <?php print $node_conf['bot']['metadata'] ? 'checked' : ''; ?>></span>
      </p>
      <input type="submit">
    </form>

  </body>
</html><?php


function add_account($type, $fields): void {
  $id = $fields['id'];
  unset($fields['id']);
  $fields = array_filter($fields, 'strlen');
  $fields['admin'] = (int)!empty($fields['admin']);
  $config = parse_ini_file('../node.ini');
  $accountStore = new AccountStore($config['account_store_url']);
  $accountStore->join($type, $id, $fields);
}

function mod_account($type, $id, $fields) :void {
  $fields['admin'] = (int)!empty($fields['admin']);
  $config = parse_ini_file('../node.ini');
  $accountStore = new AccountStore($config['account_store_url']);
  $accountStore->override($id, $fields);
}

/**
 *
 * @return stdClass[]
 */
function load_accounts() : array {
  global $config;
  $accs = [];
  $config = parse_ini_file('../node.ini');
  $accountStore = new AccountStore($config['account_store_url']);
  foreach ($accountStore->filter() as $acc) {
    $accs[$acc->id] = $accountStore->getOverride($acc->id);
  }
  return $accs;
}

function status_cell($tag, $type, $acc = NULL) {
  $status = $acc && isset($acc->status) ? $acc->status : NULL; ?>
<<?php print $tag; ?> title = "Account is active or blocked (override)">
              <select name="<?php print $type; ?>[status]">
                <option value = ""<?php print is_null($status) ? ' selected' : ''; ?>>Default</option>
                <option value = "1"<?php print $status === '1' ? ' selected' : ''; ?>>Active</option>
                <option value = "0"<?php print $status === '0' ? ' selected' : ''; ?>>Blocked</option>
              </select>
            </<?php print $tag; ?>>
<?php }

function minmax_cell($tag, $type, $acc = NULL) {?>
<<?php print $tag; ?> title = "Min/max balance (override default @todo)">
    <input name="<?php print $type; ?>[min]" type="number" min="-999999" max="0" size="4" value="<?php print $acc?$acc->min:'';?>" />
    <input name="<?php print $type; ?>[max]" type="number" max="999999" min="0" size="4"  value="<?php print $acc?$acc->max:'';?>" />
  </<?php print $tag; ?>>
<?php }
