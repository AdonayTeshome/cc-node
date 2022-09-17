<?php

namespace CCNode;

use CCNode\Accounts\Branch;
use CCNode\AddressResolver;
use CCNode\Accounts\Remote;
use CCNode\Accounts\RemoteAccountInterface;
use CCNode\Transaction\Transaction;
use CCNode\Transaction\StandaloneEntry;
use CreditCommons\TransactionInterface;
use CreditCommons\CreditCommonsInterface;
use CreditCommons\Exceptions\HashMismatchFailure;
use CreditCommons\Exceptions\UnavailableNodeFailure;
use CreditCommons\BaseTransaction;
use CreditCommons\NewTransaction;
use CreditCommons\Workflow;

/**
 * In order to implement the same CreditCommonsInterface for internal and
 * external purposes, we avoid injecting variables by allowing a few globals:
 * $cc_user, $cc_workflows, $cc_config
 */
class Node implements CreditCommonsInterface {

  function __construct(array $ini_array) {
    global $cc_workflows, $cc_config;
    $cc_config = new ConfigFromIni($ini_array);
    $wfs = json_decode(file_get_contents($cc_config->workflowsFile));
    if (empty($wfs)) {
      throw new \CreditCommons\Exceptions\CCFailure('Bad json workflows file: '.$cc_config->workflowsFile);
    }
    // @todo This loads only from the local file, but we need to load everything
    // from cached trunkward workflows as well.
    foreach ($wfs as $wf) {
      $cc_workflows[$wf->id] = new Workflow($wf);
    }
  }

  /**
   * {@inheritDoc}
   */
  public function accountNameFilter(string $rel_path = '', $limit = 10): array {
    global $cc_user, $cc_config;
    $node_name = $cc_config->nodeName;
    $trunkward_acc_id = $cc_config->trunkwardAcc;
    $remote_node = AddressResolver::create()->nodeAndFragment($rel_path);
    if ($remote_node) {// Match names on a specific node.
      $acc_ids = $remote_node->autocomplete();
      if ($remote_node instanceOf Branch and !$trunkward_acc_id) {
        foreach ($acc_ids as &$acc_id) {
          $acc_id = $node_name .'/'.$acc_id;
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
        // Exclude the logged in account
        if ($cc_user instanceOf RemoteAccountInterface and $name == $cc_user->id) continue;
        // Exclude the trunkwards account'
        if ($name == $cc_config->trunkwardAcc) continue;
        // Add a slash to the leafward accounts to indicate they are nodes not accounts
        if ($acc instanceOf RemoteAccountInterface) $name .= '/';
        if ($cc_user instanceOf RemoteAccountInterface) {
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
    $entries =  isset($params['entries']) and $params['entries'] === 'true';
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
    global $cc_config;
    $node_names[] = $cc_config->nodeName;
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
    $account = AddressResolver::create()->getLocalAccount($acc_id);
    if ($account instanceof Remote) {
      if (!$account->isNode()) {// All the accounts on a remote node
        $results = [$account->getLimits()];
      }
      else {
        $results = $account->getAllLimits();
      }
    }
    elseif ($account) {
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
    $account = AddressResolver::create()->getLocalAccount($acc_id);
    if ($account instanceOf Remote and $account->isNode()) {
      $results = $account->getAllSummaries();
    }
    elseif ($account) {
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
   */
  public function getTransaction(string $uuid): BaseTransaction {
    $result = Transaction::loadByUuid($uuid);
    $result->responseMode = TRUE;// there's nowhere tidier to do this.
    return $result;
  }

  /**
   * {@inheritDoc}
   */
  public function getTransactionEntries(string $uuid): array {
    return array_values(StandaloneEntry::loadByUuid($uuid));
  }

  /**
   * {@inheritDoc}
   */
  public function getWorkflows(): array {
    global $cc_workflows, $cc_config;
    return $cc_workflows;
    // bit confused about this right now...
    return [$cc_config->nodeName => $cc_workflows];
  }

  /**
   * {@inheritDoc}
   */
  public function handshake(): array {
    global $cc_user, $cc_config;
    $results = [];
    // This ensures the handshakes only go one level deep.
    if ($cc_user instanceOf Accounts\User) {
      // filter excludes the trunkwards account
      $remote_accounts = AccountStore()->filter(local: FALSE);
      if($trunkw = $cc_config->trunkwardAcc) {
        $remote_accounts[] = AccountStore()->fetch($trunkw);
      }
      foreach ($remote_accounts as $acc) {
        if ($acc->id == $cc_user->id) {
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
  public function __submitNewTransaction(NewTransaction $new_transaction) : TransactionInterface {
    $transaction = Transaction::createFromNew($new_transaction); // in state 'init'
    // Validate the transaction in its workflow's 'creation' state
    $transaction->buildValidate();
    // It is written according to the workflow's creation->confirm property
    /** var bool $written */
    $written = $transaction->insert();
    return $transaction;
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
  public function transactionChangeState(string $uuid, string $target_state): bool {
    return Transaction::loadByUuid($uuid)->changeState($target_state);
  }


}
