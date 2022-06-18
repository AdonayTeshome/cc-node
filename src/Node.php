<?php

namespace CCNode;

use CCNode\Workflows;
use CCNode\Accounts\Branch;
use CCNode\AddressResolver;
use CCNode\Accounts\Remote;
use CCNode\Accounts\User;
use CCNode\Accounts\Trunkward;
use CCNode\Accounts\RemoteAccountInterface;
use CCNode\Transaction\Transaction;
use CCNode\Transaction\StandaloneEntry;
use CreditCommons\AccountStoreInterface;
use CreditCommons\CreditCommonsInterface;
use CreditCommons\Exceptions\CCFailure;
use CreditCommons\Exceptions\HashMismatchFailure;
use CreditCommons\Exceptions\UnavailableNodeFailure;
use CreditCommons\Exceptions\DoesNotExistViolation;
use CreditCommons\NodeRequester;
use CreditCommons\BaseTransaction;
use CreditCommons\NewTransaction;
use CreditCommons\Account;

class Node implements CreditCommonsInterface {

  function __construct(private \CCNode\ConfigInterface $config) {}

  /**
   * {@inheritDoc}
   */
  public function accountNameFilter(string $rel_path = '', $limit = 10): array {
    global $user;
    $node_name = $this->config->nodeName;
    $trunkward_acc_id = $this->config->trunkwardAcc;
    $remote_node = AddressResolver::create()->nodeAndFragment($rel_path);
    if ($remote_node) {// Match names on a specific node.
      $acc_ids = $remote_node->autocomplete();
      if ($remote_node instanceOf Branch and !$trunkward_acc_id) {
        foreach ($acc_ids as &$acc_id) {
          $acc_id = $node_name .'/'.$acc_id;
        }
      }
    }
    else {// Match names from here to the trunk.
      $trunkward_names = [];
      if ($trunkward_acc_id and $user->id <> $trunkward_acc_id) {
        $trunkward_names = load_account($trunkward_acc_id, $rel_path)->autocomplete();
      }
      // Local names.
      $filtered = accountStore()->filter(fragment: trim($rel_path, '/'));
      $local = [];
      foreach ($filtered as $acc) {
        $name = $acc->id;
        // Exclude the logged in account
        if ($user instanceOf RemoteAccountInterface and $name == $user->id) continue;
        if ($acc instanceOf RemoteAccountInterface) $name .= '/';
        if ($user instanceOf RemoteAccountInterface) {
          $local[] = $node_name."/$name";
        }
        else {
          $local[] = $name;
        }
      }
      $acc_ids = array_merge($local, $trunkward_names);
    }
    //if the request is from the trunk prefix all the results. (rare)
    return array_slice($acc_ids, 0, $limit);
  }

  /**
   * {@inheritDoc}
   */
  public function buildValidateRelayTransaction(\CreditCommons\TransactionInterface $transaction): array {
    global $user;
    $transaction->buildValidate();
    $saved = $transaction->insert();
    // Return only the additional entries which are relevant to the upstream node.
    // @todo this could be more elegant.
    return array_filter(
      $transaction->filterFor($user),
      function($e) {return $e->isAdditional();}
    );
  }

  /**
   * {@inheritDoc}
   */
  public function filterTransactions(array $params): array {
    if (isset($params['entries']) and $params['entries'] === 'true') {
      $entries = TRUE;
    }
    else {
      $entries = FALSE;
    }
    unset($params['entries']);
    $uuids = Transaction::filter(...$params);
    $results = [];

    if ($entries) {
      $results = StandaloneEntry::load(array_keys($uuids));
    }
    else {
      foreach (array_unique($uuids) as $uuid) {
        $results[$uuid] = Transaction::loadByUuid($uuid);
      }
    }
    // All entries are returned
    return array_values($results);
  }

  /**
   * {@inheritDoc}
   */
  public function getAbsolutePath(): array {
    $node_names[] = $this->config->nodeName;
    if ($trunkward = \CCNode\API_calls()) {
      $node_names = array_merge($trunkward->getAbsolutePath(), $node_names);
    }
    return $node_names;
  }

  /**
   * {@inheritDoc}
   */
  public function getAccountHistory(string $acc_id, $samples = 0): array {
    $account = AddressResolver::create()->localOrRemoteAcc($acc_id);
    return $account->getHistory($samples);//@todo refactor this.
  }

  /**
   * {@inheritDoc}
   */
  public function getAccountLimits(string $acc_id): array {
    $account = AddressResolver::create()->nearestNode($acc_id);
    if ($account instanceof Remote and !$account->isAccount()) {// All the accounts on a remote node
      $results = $account->getAllLimits();
    }
    elseif ($account instanceOf User) {
      $results = [$account->getLimits()];
    }
    else {// All accounts on the current node.
      $results = accountStore()->allLimits(TRUE);
    }
    return $results;
  }

  /**
   * {@inheritDoc}
   */
  public function getAccountSummary(string $acc_id = ''): array {
    $account = AddressResolver::create()->nearestNode($acc_id);
    if ($account instanceOf Remote and !$account->isAccount()) {
      $results = $account->getAllSummaries();
    }
    elseif ($account instanceOf User) {
      $results = [$account->getSummary()];
    }
    else {// All accounts on the current node.
      $results = Transaction::getAccountSummaries(TRUE);
    }
    return $results;
  }

  /**
   * {@inheritDoc}
   */
  public function getOptions(): array {
    return permitted_operations();
  }


  /**
   * {@inheritDoc}
   * get local or remote transaction
   */
  public function getTransaction(string $uuid, bool $full = TRUE): BaseTransaction | array {
    if (!$full) {
      $result = array_values(StandaloneEntry::loadByUuid($uuid));
    }
    else {
      $result = Transaction::loadByUuid($uuid);
      $result->responseMode = TRUE;// there's nowhere tidier to do this.
    }
    return $result;
  }

  /**
   * {@inheritDoc}
   */
  public function getWorkflows(): array {
    // Todo need to instantiate workflows with the Trunkward requester if there is one.
    return (new Workflows())->loadAll();
  }

  /**
   * {@inheritDoc}
   */
  public function handshake(): array {
    global $user;
    $results = [];
    // This ensures the handshakes only go one level deep.
    if ($user instanceOf Accounts\User) {
      // filter excludes the trunkwards account
      $remote_accounts = AccountStore()->filter(local: FALSE);
      if($trunkw = $this->config->trunkwardAcc) {
        $remote_accounts[] = AccountStore()->fetch($trunkw);
      }
      foreach ($remote_accounts as $acc) {
        if ($acc->id == $user->id) {
          continue;
        }
        try {
          $acc->handshake();
          $results[$acc->id] = 'ok';
        }
        catch (UnavailableNodeFailure $e) {
          $results[$acc->id] = 'UnavailableNodeFailure';
        }
        catch (HashMismatchFailure $e) {
          $results[$acc->id] = 'HashMismatchFailure';
        }
        catch(\Exception $e) {
          $results[$acc->id] = get_class($e);
        }
      }
    }
    return $results;
  }

  /**
   * {@inheritDoc}
   */
  public function submitNewTransaction(NewTransaction $new_transaction): BaseTransaction {
    $transaction = Transaction::createFromLeaf($new_transaction); // in state 'init'
    // Validate the transaction in its workflow's 'creation' state
    $transaction->buildValidate();
    // It is written according to the workflow's creation->confirm property
    $written = $transaction->insert();
    return $transaction;
  }

  /**
   * {@inheritDoc}
   */
  public function transactionChangeState(string $uuid, string $target_state): bool {
    return Transaction::loadByUuid($uuid)->changeState($target_state);
  }

}

/**
 * @todo put these functions in an always included file so they needn't be called with the namespace.
 */

/**
 * Access control for each API method.
 *
 * Anyone can see what endpoints they can user, any authenticated user can check
 * the workflows and the connectivity of adjacent nodes. But most operations are
 * only accessible to direct members and leafward member, making this node quite
 * private with respect to the rest of the tree.
 * @param Accounts\User $user
 * @return string[]
 *   A list of the api method names the current user can access.
 * @todo make this more configurable.
 */
function permitted_operations() : array {
  global $user;
  $permitted[] = 'permittedEndpoints';
  if ($user->id <> '-anon-') {
    $permitted[] = 'handshake';
    $permitted[] = 'workflows';
    $permitted[] = 'newTransaction';
    $permitted[] = 'absolutePath';
    $permitted[] = 'stateChange';
    $map = [
      'filterTransactions' => 'transactions',
      'getTransaction' => 'transactions',
      'accountHistory' => 'transactions',
      'accountLimits' => 'acc_summaries',
      'accountNameFilter' => 'acc_ids',
      'accountSummary' => 'acc_summaries'
    ];
    foreach ($map as $method => $perm) {
      if (!$user instanceOf Trunkward or $this->config->privacy[$perm]) {
        $permitted[] = $method;
      }
    }
    if ($user instanceof Remote) {
      $permitted[] = 'relayTransaction';
    }
  }
  return array_intersect_key(CreditCommonsInterface::OPERATIONS, array_flip($permitted));
}


/**
 * Load an account from the accountStore.
 *
 * @staticvar array $fetched
 * @param string $acc_id
 *   The account id or empty string to load a dummy account.
 * @return CreditCommons\Account
 * @throws DoesNotExistViolation
 *
 * @todo make sure this is used whenever possible because it is cached.
 * @todo This doesn't seem like a good place to throw a violation.
 */
function load_account(string $local_acc_id = NULL, string $given_path = NULL) : Account {
  global $config;
  static $fetched = [];
  if ($config->devMode) {
    $fetched = [];
  }
  if (!isset($fetched[$local_acc_id])) {
    if (strpos(needle: '/', haystack: $local_acc_id)) {
      throw new CCFailure("Can't load unresolved account name: $local_acc_id");
    }
    if ($local_acc_id and accountStore()->has($local_acc_id)) {
      $fetched[$local_acc_id] = accountStore()->fetch($local_acc_id);
    }
    else {
      throw new DoesNotExistViolation(type: 'account', id: $local_acc_id);
    }
  }
  // Sometimes an already loaded account turns out to have a relative path.
  if ($given_path) {
    $fetched[$local_acc_id]->givenPath = $given_path;
  }
  return $fetched[$local_acc_id];
}

/**
 * Get the object with all the API calls, initialised with a remote account to call.
 *
 * @param Remote $account
 *   if not provided the balance of trade of account will be used
 * @return NodeRequester|NULL
 */
function API_calls(Remote $account = NULL) {
  global $config;
  if (!$account) {
    if ($bot = $config->trunkwardAcc) {
      $account = load_account($bot);
    }
    else {
      return NULL;
    }
  }
  return new NodeRequester($account->url, $config->nodeName, $account->getLastHash());
}

/**
 * Get the library of functions for accessing ledger accounts.
 */
function accountStore() : AccountStoreInterface {
  global $config;
  static $account_store;
  if (!isset($account_store)) {
    $class = class_exists($config->accountStore) ? $config->accountStore : '\CCNode\AccountStoreREST';
    $account_store = new $class();
  }
  return $account_store;
}

/**
 * Write a message to a debug file.
 */
function debug($val) {
  global $config;
  $file = $config->nodeName.'.debug';
  if (!is_scalar($val)) {
    $val = print_r($val, TRUE);
  }
  $written = file_put_contents(
    $file,
    date('H:i:s')."  $val\n",
    FILE_APPEND
  );
}
