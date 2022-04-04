<?php
namespace CCNode;

use CreditCommons\AccountStoreInterface;
use CreditCommons\Exceptions\DoesNotExistViolation;
use CreditCommons\Exceptions\CCOtherViolation;
use CCNode\Accounts\Remote;
use CCNode\Accounts\User;

/**
 * Convert all errors into an stdClass, which includes a field showing
 * which node caused the error.
 *
 * Consider branch called a/b/c/d/e where a is the trunk and e is an end account.
 * From anywhere in the tree
 * resolveToLocalAccount(b/c/d) returns the d account on node c
 * resolveToNode(b/c/d) returns a requester for d node from any where in the tree.
 * These work from anywhere because they include the top level branch name.
 * It is never necessary to name the trunk node.
 * Meanwhile on node c to reach node d account e, you could pass anything from a/b/c/d/e to just d/e
 *
 */
class AddressResolver {

  private $accountStore;
  private $nodeName;
  private $trunkwardName;
  private $userId;

  function __construct(AccountStoreInterface $accountStore, string $absolute_path) {
    global $user;
    $this->accountStore = $accountStore;
    $parts = explode('/', $absolute_path);
    $this->nodeName = array_pop($parts);
    $this->trunkwardName = array_pop($parts);
    $this->userId = $user->id;
  }

  static function create() {
    return new static(accountStore(), getConfig('abs_path'));
  }

  /**
   *
   * @global type $user
   * @param string $given_acc_path
   *   could be an account name, or could end with a / to indicate all the accounts on the node.
   * @return array
   *   The local account, and the desired path relative to it.
   *
   * On c:
   *   c returns 'c'
   *   b/c returns 'c'
   *   a/b/c returns 'c'
   *   d returns d
   *   d/e returns d
   *   c/d/e returns d
   *   b/c/d/e returns d
   *   Anything else returns b unless the request came from b
   * So
   *   A child of the current node c is assumed if:
   *     The first item on the path is c or d OR
   *     The path includes b/c and another item
   *   Otherwise if the request didn't come from the trunk,
   *     The trunkward path is returned.
   *   Otherwise the result is invalid path
   */
  public function resolveTolocalAccount(string $given_acc_path) : User|string {
    $parts = explode('/', $given_acc_path);
    $acc_id = array_pop($parts);
    // Identify which node
    if ($proxy_account_id = $this->relativeToThisNode($parts)) {
      // Forward to another node.
      return load_account($proxy_account_id, $given_acc_path);// parts is the same as given
    }
    // its an account on this node.
    elseif ($acc_id and ($this->accountStore->has($acc_id) or $acc_id == $this->trunkwardName)) {
      return load_account($acc_id, $given_acc_path); // $parts is missing last part, which is the account name.
    }
    elseif ($acc_id) {
      throw new DoesNotExistViolation(type: 'account', id: $given_acc_path);
    }
    else {// all accounts on this node.
      //\CCNode\debug($given_acc_path);// cctrunk\/ccbranch1\/bertha\/
      return '*';
    }
  }

  /**
   * Return the account name that points to another node, and alter the $path_parts to be relative to that account.
   */
  public function relativeToThisNode(array &$path_parts) : string {
    // handle all local and leafward nodes.
    // if the node name is in the path, cut every thing off before it.
    $pos = array_search($this->nodeName, $path_parts);
    if ($pos !== FALSE) {
      if ($pos == 0) {
        array_shift($path_parts);
      }
      elseif ($pos > 0 and $path_parts[$pos-1] == $this->trunkwardName) {
        // This is a branch so we can cut off the start of the path.
        $path_parts = array_slice($path_parts, $pos+1);
      }
      elseif ($this->trunkwardName) {// Rare
        // the node name appears under a different trunk.
        // Freaky coincidence, but pass trunkward.
        return $this->trunkwardName;
      }
      else {
        throw new CCOtherViolation("Invalid node path: ".implode('/', $path_parts));
      }
    }
    if (empty(array_filter($path_parts))) {
      // the current node.
      return '';
    }
    else{
      if ($this->accountStore->has(reset($path_parts))) {
        $acc = load_account(reset($path_parts));
        if ($acc instanceof Remote) {
          return array_shift($path_parts);
        }
        else {
          return '';
        }
      }
      elseif($this->trunkwardName) {
        return $this->trunkwardName;
      }
    }
    throw new DoesNotExistViolation(type: 'node', id: implode($path_parts));
  }

  /**
   * Find all the accounts in the tree that match the given fragment, excluding the current user.
   * @param string $fragment
   * @return string[]
   */
  function pathMatch(string $fragment) : array {
    global $user;
    $trunkward_acc_id = getConfig('trunkward_acc_id');
    try {
      $remote_acc = $this->resolveTolocalAccount($fragment);
    }
    catch (DoesNotExistViolation $e) {
      //die("No such account $fragment");
      $remote_acc = '';
    }
    // Look locally and trunkwards if no remote account is taken from the fragment
    if (!$remote_acc instanceOf Accounts\Remote or substr($fragment, -1) <> '/') {
      // Make a list with all the matching trunkward node names and all the matching accounts.
      $trunkward_names = [];
      if ($trunkward_acc_id and $this->userId <> $trunkward_acc_id) {
        $trunkward_names = load_account($trunkward_acc_id)->autocomplete($fragment);
      }
      // Local names.
      $filtered = $this->accountStore->filterFull(fragment: trim($fragment, '/'));
      $local = [];

      foreach ($filtered as $acc) {
        $name = $acc->id;
        // Exclude the logged in account
        if ($name == $this->userId) continue;
        if ($acc instanceOf Remote) $name .= '/';
        if ($user instanceOf Remote) {
          $local[] = getConfig('node_name')."/$name";
        }
        else {
          $local[] = $name;
        }
      }

      $names = array_merge($trunkward_names, $local);
    }
    elseif ($remote_acc) {
      // Just give the names on the given branch
      $names = $remote_acc->autocomplete($remote_acc->relPath());
      if ($this->userId == getConfig('trunkward_acc_id')) {
        foreach ($names as &$name) {
          $name = getConfig('node_name') . '/'.$name;
        }
      }
    }
    else {
      // Not yet sure how to handle this or when it would happen
      throw new CCOtherViolation("Invalid autocomplete path '$fragment'");
    }
    return $names;
  }

}
