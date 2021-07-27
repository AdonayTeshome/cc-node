<?php
use CCNode\AccountStore;
const ACCCOUNTS_FILE  = '../AccountStore/store.json';

$errs = [];
if ($_POST) {
  unset($_POST['submit']);
  $errs = [];

  if (!$errs) {
    require './writeini.php';
    if ($_POST['user']['new']['id']) {
      add_account('user', $_POST['user']['new']);
    }
    unset($_POST['user']['new']);
    if (@$_POST['node']['new']['id']) {
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
    if (@$_POST['bot']['id']) {
      $accs = file_exists(ACCCOUNTS_FILE) ? (array)json_decode(file_get_contents(ACCCOUNTS_FILE)) : [];
      if (isset($accs[$_POST['bot']['id']])) {
        mod_account('node', $_POST['bot']['id'], $_POST['bot']);
      }
      else {
        add_account('node', $_POST['bot']);
      }
      // populate unchecked boxes
      $bot_settings = $_POST['bot'] + ['priv_accounts' => 0, 'priv_transactions' => 0, 'priv_stats' => '0'];
      replaceIni(['bot' => $bot_settings], SETTINGS_INI_FILE);
      $node_conf = parse_ini_file(SETTINGS_INI_FILE);
    }
  }
}
$accs = file_exists(ACCCOUNTS_FILE) ? (array)json_decode(file_get_contents(ACCCOUNTS_FILE)) : [];
if (!is_writable(ACCCOUNTS_FILE)) {
  $errs[] = ACCCOUNTS_FILE . " is not writable";
}
?><!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Frameset//EN" "http://www.w3.org/TR/html4/frameset.dtd">
  <head>
    <title>Credit Commons setup</title>
    <style>th{background-color: #eee;}</style>
  </head>
  <body>
    <h1>Credit Commons: Account settings</h1>
    <p>Hover for help. Account information is stored in accounts.json
    <br />Or go to <a href = "index.php?node">general settings</a>.
    <?php if ($errs)
      print "<p><font color=red>".implode('<br />', $errs).'</font>';
    ?><form method="post">
      <h2>User accounts</h2>
      <p>Normal accounts
      <table cellpadding="2">
        <thead>
          <tr>
            <th title = "Wallet id, must be unique on this node">name (created)</th>
            <th title = "Password-like string">key</th>
            <th title = "Account is active or blocked (override)">status</th>
            <th title = "Minimum balance (override default @todo)">min</th>
            <th title = "Maximum balance (override default @todo)">max</th>
            <th title = "Checked if this account has admin privileges">admin</th>
          </tr>
        </thead>
        <tbody>
        <?php
      $users = array_filter($accs, function($a){return !empty($a->key);});
      foreach ($users as $acc) : ?>
    <tr>
            <th title = "Wallet id, must be unique on this node"><?php print $acc->id;?> (<?php print date('d-M-Y', $acc->created); ?>)<!--<input type="hidden" name="user[<?php print $acc->id;?>][id]" value = "<?php print $acc->id;?>">--></th>
            <td title = "Password-like string"><input name="user[<?php print $acc->id;?>][key]" value="<?php print $acc->key;?>" size = "6"></td>
            <?php print status_cell($acc); ?>
            <td title = "Minimum balance (override default @todo)"><input name="user[<?php print $acc->id;?>][min]" type="number" min="-999999" max="0" size="4" value="<?php print $acc->min;?>"></td>
            <td title = "Maximum balance (override default @todo)"><input name="user[<?php print $acc->id;?>][max]" type="number" max="999999" min="0" size="4" value="<?php print $acc->max;?>"></td>
            <td title = "Checked if this account has admin privileges"><input name="user[<?php print $acc->id;?>][admin]" type="checkbox" value = "1" <?php print $acc->admin?'checked':'';?>></td>
          </tr>
      <?php endforeach;
      for ($i=0; $i < (count($users) == 0?2:1); $i++) : ?>
      <tr>
          <td title = "Wallet id, must be unique on this node"><input name="user[new][id]" size = "8" placeholder = "node_account_id"></td>
          <td title = "Password-like string"><input name="user[new][key]" size = "8"></td>
          <?php print status_cell(); ?>
          <td title = "Minimum balance (override default @todo)"><input name="user[new][min]" type="number" min="-999999" max="0" size="4" ></td>
          <td title = "Maximum balance (override default @todo)"><input name="user[new][max]" type="number" max="999999" min="0" size="4" ></td>
          <td title = "Checked if this account has admin privileges"><input name="user[new][admin]" type="checkbox" value = "1" ></td>
        </tr>
      </table>
      <?php endfor; ?>

      <h2>Leafward nodes</h2>
      <p>One special accounts which are controlled by other, credit commons nodes.
      <table>
      <thead>
        <tr>
          <th title = "Wallet id, must be unique on this node">name</th>
          <th title = "Url of the node">url</th>
          <th title = "Account is active or blocked (override)">status</th>
          <th title = "Minimum balance (override default @todo)">min</th>
          <th title = "Maximum balance (override default @todo)">max</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $nodes = array_filter($accs, function($a) use ($node_conf){return !empty($a->url) and $a->id <> $node_conf['bot']['acc_id'];});
      foreach ($nodes as $acc) : ?>
      <tr>
        <th><?php print $acc->id;?></th>
        <td title = "Url of the node">
          <input name="node[<?php print $acc->id;?>][url]" value="<?php print $acc->url;?>" size = "8">
        </td>
        <?php print status_cell($acc); ?>
        <td title = "Minimum balance (override default @todo)">
          <input name="node[<?php print $acc->id;?>][min]" type="number" min="-999999" max="0" size="4" value="<?php print $acc->min;?>">
        </td>
        <td title = "Maximum balance (override default @todo)">
          <input name="node[<?php print $acc->id;?>][max]" type="number" max="999999" min="0" size="4" value="<?php print $acc->max;?>">
        </td>
      </tr>
      <?php endforeach; ?>
      <tr>
        <td title = "Wallet id, must be unique on this node"><input name="node[new][id]" size = "8" placeholder = "new_account_id" value="<?php $bot_name;?>"></td>
        <td><input name="node[new][url]" size = "8"  value="<?php $bot_url;?>"></td>
        <?php print status_cell(); ?>
        <td title = "Minimum balance (override default @todo)">
          <input name="node[new][min]" type="number" min="-999999" max="0" size="4" >
        </td>
        <td title = "Maximum balance (override default @todo)">
          <input name="node[new][max]" type="number" max="999999" min="0" size="4" >
        </td>
      </tr>
      </tbody>
      </table>


      <h2>Balance of Trade account</h2>
      <p>Special account used to connect to the wider Credit Commons tree.
        <?php $bot = $accs[$node_conf['bot']['acc_id']]??NULL; ?><p>
        ID <?php if (!$bot) : ?>
      <input name="bot[id]" size = "8" placeholder = "new_account_id" value="<?php print $bot?$bot->id:''; ?>"> (cannot be changed)
      <br />Url <input name="bot[url]" size = "8" value="<?php print $bot?$bot->url:''; ?>">
      <?php else : ?>
        <?php print $bot->id;  ?>
        <br />Url <?php print $bot->url;  ?>
        <?php endif;  ?>

      <p title = "This name is your node's identification on the Credit Commons tree. It is not needed for standalone nodes.">
        Node name <input name ="node_name" value ="<?php print $node_conf['node_name']; ?>">
      </p>
      <p><span title = "Minimum balance (override default @todo)">Min <input name="bot[min]" type="number" min="-999999" max="0" size="4" value="<?php print $bot?$bot->max:''; ?>"></span>
        <br /><span title = "Maximum balance (override default @todo)">Max <input name="bot[max]" type="number" max="999999" min="0" size="4" value="<?php print $bot?$bot->min:''; ?>"></span></p>
      <p><span title="The ratio of the local unit to the Branchward ledger's unit @todo clarify which way around this is">Exchange rate <input name = "bot[rate]" type = "number" min = "0.001" max = "1000" step = "0.001" size = "2" value = "<?php print $node_conf['bot']['rate']; ?>"></span>
      <p title = "Privacy settings: which aspects of the ledger are visible to the public?">
        Expose account Ids <input name = "bot[priv_accounts]" type = "checkbox" value = "1" <?php print $node_conf['bot']['priv_accounts'] ? 'checked ': ''; ?>">
      <br />Expose account transactions <input name = "bot[priv_transactions]" type = "checkbox" value = "1" <?php print $node_conf['bot']['priv_transactions'] ? 'checked ': ''; ?>">
      <br />Expose anonymised stats<input name = "bot[priv_stats]" type = "checkbox" value = "1" <?php print $node_conf['bot']['priv_stats'] ? 'checked ': ''; ?>">
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


function status_cell($acc = NULL) { ?>
<td title = "Account is active or blocked (override)">
              <select name="user[<?php print $acc->id??'new'; ?>][status]">
                <option value = ""<?php print $acc && $acc->status === ''? 'selected ':''; ?>>Default</option>
                <option value = "1"<?php print $acc && $acc->status === '1'? 'selected ':''; ?>>Active</option>
                <option value = "0"<?php print $acc && $acc->status === '0'? 'selected ':''; ?>>Blocked</option>
              </select>
            </td>
<?php }