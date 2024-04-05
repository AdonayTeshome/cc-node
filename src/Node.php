<?php

namespace CCNode;

use CCNode\Accounts\Branch;
use CCNode\AddressResolver;
use CCNode\Accounts\Remote;
use CCNode\Accounts\RemoteAccountInterface;
use CCNode\Accounts\Trunkward;
use CCNode\Transaction\Transaction;
use CreditCommons\TransactionInterface;
use CreditCommons\CreditCommonsInterface;
use CreditCommons\Exceptions\HashMismatchFailure;
use CreditCommons\Exceptions\UnavailableNodeFailure;

/**
 * In order to implement the same CreditCommonsInterface for internal and
 * external purposes, we avoid injecting variables by allowing a few globals:
 * $cc_user, $cc_workflows, $cc_config
 */
class Node implements CreditCommonsInterface {

  /**
   * Create the global variables $cc_workflows, $cc_config.
   */
  function __construct() {
    global $cc_workflows, $cc_config;
    if (!isset($cc_workflows) or !$cc_workflows instanceOf Workflows) {
      throw new \Exception('Cannot initiate \CCNode\Node before creating global $cc_workflows');
    }
    if (!isset($cc_config) or !$cc_config instanceOf \CCNode\ConfigInterface) {
      throw new \Exception('Cannot initiate \CCNode\Node before creating global $cc_config');
    }
  }

  /**
   * {@inheritDoc}
   */
  public function accountNameFilter(string $rel_path = '', $limit = 10): array {
    global $cc_user, $cc_config;
    $node_name = $cc_config->nodeName;
    $trunkward_acc_id = $cc_config->trunkwardAcc;
    // Find out if the request is for this node or whether to forward it.
    try {
      // Autocomplete on remote nodes is not supported for clearing central
      $remote_node = AddressResolver::create()->nodeAndFragment($rel_path);
    }
    catch(DoesNotExistViolation $e) {
      return [];
    }
    if (isset($remote_node)) {// Match names on a specific node.
      if ($remote_node instanceOf Branch and !$trunkward_acc_id) {
        $acc_ids = $remote_node->autocomplete();
        foreach ($acc_ids as &$acc_id) {
          $acc_id = $remote_node->id .'/'.$acc_id;
        }
      }
    }
    else {// Match names on each node from here to the trunk.
      $trunkward_names = [];
      if ($trunkward_acc_id and $cc_user->id <> $trunkward_acc_id) {
        $acc = load_account($trunkward_acc_id, $rel_path);
        $trunkward_names = $acc->autocomplete();
      }
      // Local names.
      $filtered = accountStore()->filter(fragment: trim($rel_path, '/'), full: TRUE);
      $local = [];
      foreach ($filtered as $acc) {
        $name = $acc->id;
        // Exclude the logged in account.
        if ($cc_user instanceOf RemoteAccountInterface and $name == $cc_user->id) continue;
        // Exclude the trunkwards account.
        if ($name == $cc_config->trunkwardAcc) continue;
        // Add a slash to the leafward accounts to indicate they are nodes not accounts.
        if ($acc instanceOf RemoteAccountInterface) $name .= '/';
        // Prefix the trunk name unless this is the trunk.
        if ($cc_config->trunkwardAcc and $cc_user instanceOf RemoteAccountInterface) {
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
  public function filterTransactions(array $params = []): array {
    global $orientation;
    $transactions = $transitions = [];
    [$uuids, $count] = Transaction::filter($params);
    if ($uuids) {
      foreach ($uuids as $uuid) {
        $t = Transaction::loadByUuid($uuid);
        $transactions[] = $t;
        $transitions[$uuid] = $t->transitions();
      }
      $orientation->responseMode();
    }
    // Transitions are returned seperately, because the leafTransaction doesn't knowo the workflow, and can't run actionlinks.
    return [$count, $transactions, $transitions];
  }

  /**
   * {@inheritDoc}
   */
  public function filterTransactionEntries(array $params = []): array {
    global $orientation;
    $results = [];
    [$uuids, $count] = Transaction::filterEntries($params);
    if ($uuids) {
      $results = Transaction::loadEntries(array_keys($uuids));
    }
    // Actually this hasnt been initiated
    //$orientation->responseMode();
    // All entries are returned
    return [$count, $results];
  }

  /**
   * {@inheritDoc}
   */
  public function getTransaction(string $uuid): array {
    global $orientation;
    $transaction = Transaction::loadByUuid($uuid);
    $orientation->responseMode();
    return [$transaction, $transaction->transitions()];
  }

  /**
   * {@inheritDoc}
   */
  public function getTransactionEntries(string $uuid): array {
    global $orientation, $cc_config;
    $entries = Transaction::loadEntriesByUuid($uuid);
    //$orientation->responseMode();
    return $entries;
  }

  /**
   * {@inheritDoc}
   */
  public function getAbsolutePath(): array {
    global $cc_config;
    $node_names[] = $cc_config->nodeName;
    if ($trunkward_node = \CCNode\API_calls()) {
      $node_names = array_merge($trunkward_node->getAbsolutePath(), $node_names);
    }
    return $node_names;
  }

  /**
   * {@inheritDoc}
   */
  public function getAccountHistory(string $acc_id, $samples = 0): array {
    $account = AddressResolver::create()->localOrRemoteAcc($acc_id);
    return $account->getHistory($samples);
  }

  /**
   * {@inheritDoc}
   */
  public function getAccountLimits(string $acc_id): array {
    if ($account = AddressResolver::create()->getLocalAccount($acc_id)) {
      if ($account instanceof Remote) {
        if (!$account->isNode()) {// All the accounts on a remote node
          $results = [$account->id => $account->getLimits()];
        }
        else {
          $results = $account->getAllLimits();
        }
      }
      elseif ($account) {
        $results = [$account->id => $account->getLimits()];
      }
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
    if ($account = AddressResolver::create()->getLocalAccount($acc_id)) {
      if ($account instanceOf Remote and $account->isNode()) {
        $results = $account->getAllSummaries();
      }
      elseif ($account) {
        $results = [$account->id => $account->getSummary()];
      }
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
   */
  public function handshake(bool $extended = FALSE): array {
    global $cc_user, $cc_config;
    $results = [];
    // This ensures the handshakes only go one level deep.
    if ($extended and $cc_user instanceOf Accounts\User) {
      // filter excludes the trunkwards account
      $remote_accounts = AccountStore()->filter(local: FALSE);
      if($trunkw = $cc_config->trunkwardAcc) {
        $remote_accounts[$trunkw] = AccountStore()->fetch($trunkw);
      }
      foreach ($remote_accounts as $acc) {
        if ($acc->id == $cc_user->id) {
          continue;
        }
        try {
          $results[$acc->id] = $acc->handshake();
        }
        catch (UnavailableNodeFailure $e) {
          $results[$acc->id] = 'UnavailableNodeFailure';
        }
        catch (HashMismatchFailure $e) {
          $results[$acc->id] = 'HashMismatchFailure';
        }
        catch(\Error $e) {
          $results[$acc->id] = get_class($e);
        }
      }
    }
    return $results;
  }

  /**
   * {@inheritDoc}
   */
  public function submitNewTransaction(NewTransaction $new_transaction) : array {
    $request = $this
      ->setMethod('post')
      ->setBody($new_transaction);
    $result = $request->request('transaction');
    return [$result->data, $result->meta->transitions];
  }

  /**
   * {@inheritDoc}
   */
  public function buildValidateRelayTransaction(TransactionInterface $transaction) : array {
    $new_rows = $transaction->buildValidate();
    $saved = $transaction->insert();
    return $new_rows;
  }

  /**
   * {@inheritDoc}
   */
  public function transactionChangeState(string $uuid, string $target_state) : void {
    $transaction = Transaction::loadByUuid($uuid);
    $transaction->changeState($target_state);
  }

  /**
   * {@inheritDoc}
   * The requesting node is always valued as 1
   */
  public function about($node_path) : \stdClass {
    global $cc_config, $cc_user;
    if ($account = AddressResolver::create()->getLocalAccount($node_path)) {
      if ($account instanceof RemoteAccountInterface) {
        $obj = $account->getConversionRate();
        if ($account instanceof Trunkward) {
          $obj->rate *= $cc_config->conversionRate;
        }
        else {
          $obj->rate /= $cc_config->conversionRate;
        }
      }
      // else should never happen.
    }
    else {
      $obj = (object)['format' => $cc_config->displayFormat];
      if ($cc_user instanceof Trunkward) {
        $obj->rate = 1/$cc_config->conversionRate;
      }
      else {
        $obj->rate = 1;
      }
    }
    return $obj;
  }

}
