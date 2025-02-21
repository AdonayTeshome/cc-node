<?php
use CCNode\Node;
use CCNode\accountStore;
use function CCNode\load_account;

session_start();
require_once '../vendor/autoload.php';
// Creates the required global $error_context.
ErrorContext::Create(
  node: $cc_config->nodeName,
  path: '/ui',
  method: 'get',
  user: '- anon -'
);

global $cc_config, $cc_worflows;
$cc_config = CCNodeConfig::createFromIniArray(parse_ini_file('../node.ini'));
$cc_workflows = new Workflows(json_decode(file_get_contents('../workflows.json')));

$node = new Node();
if ($_POST and !empty($_POST["user_name"]) and !empty($_POST['password'])) {
  include_once('../vendor/credit-commons/cc-node/src/Node.php');
  if (accountStore('../accountstore.json')->checkCredentials($_POST['user_name'], $_POST['password'])) {
    $_SESSION['user'] = $_POST['user_name'];
  }
  else $_SESSION['errorMessage'] = "Invalid Credentials";
}
elseif(isset($_GET['logout'])) {
  unset($_SESSION["user"]);
  session_destroy();
  header("Location: index.php");
}

?><!DOCTYPE html>
<html lang="en">
  <head>
    <title>Credit Commons client</title>
    <link type="text/css" rel="stylesheet" href="style.css" media="all" />
  </head>
  <body>
    <ul class="menu">
      <li><a href="index.php">Summary</a></li>
      <li><a href="transactions.php">Transactions</a></li>
      <li><a href="accounts.php">Accounts</a></li>
      <li><a href="register.php">Register</a></li>
      <?php if(isset($user)): ?><li><a href="index.php?logout">Log out (<?php print $user->id; ?>)</a></li><?php endif; ?>
    </ul><hr />
    <?php
    if (isset($_SESSION['user'])) {
      $cc_user = load_account($_SESSION["user"]);
      return;
    }
    ?>
    <div>
        <form method="post">
            <div class="login-box">
                <div class="form-head">Login</div>
                  <?php if(isset($_SESSION["errorMessage"])):?>
                    <div class="error-message">
                    <?php  echo $_SESSION["errorMessage"];unset($_SESSION["errorMessage"]); ?>
                    </div>
                  <?php endif; ?>
                <div>
                    <div>
                        <label for="username">Username</label><span id="user_info" class="error-info"></span>
                    </div>
                    <div>
                        <input name="user_name" id="user_name" type="text"
                            class="demo-input-box">
                    </div>
                </div>
                <div>
                    <div>
                        <label for="password">Password</label><span id="password_info" class="error-info"></span>
                    </div>
                    <div>
                        <input name="password" id="password" type="password"
                            class="demo-input-box">
                    </div>
                </div>
                  <div>
                      <input type="submit" name="login" value="Login"></span>
                  </div>
            </div>
        </form>
    </div>
</body>
</html>
<?php exit;?>
