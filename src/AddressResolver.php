<?php
namespace CCNode;

use CreditCommons\AccountStoreInterface;
use CreditCommons\Exceptions\DoesNotExistViolation;
use CCNode\Accounts\Remote;

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

  function __construct(AccountStoreInterface $accountStore, $absolute_path) {
    global $user;
    $this->accountStore = $accountStore;
    $parts = explode('/', $absolute_path);
    $this->nodeName = array_pop($parts);
    $this->trunkwardName = array_pop($parts);
    $this->userId = $user->id;
  }

  static function create($absolute_path) {
    return new static(AccountStore::create(), $absolute_path);
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
   *     the trunkward path is returned.
   *   Otherwise the result is invalid path
   */
  public function resolveTolocalAccount(string $given_acc_path) : array {
    $parts = explode('/', $given_acc_path);
    $acc_id = array_pop($parts);
    // identify which node
    if ($proxy_account_id = $this->relativeToThisNode($parts)) {
      // it's not this node.
      if ($this->userId <> $this->trunkwardName) {
        return [$this->accountStore->fetch($proxy_account_id), implode('/', $parts).'/'.$acc_id];
      }
      else {
        throw new DoesNotExistViolation(type: 'account', id: $given_acc_path);
      }
    }
    elseif ($acc_id and ($this->accountStore->has($acc_id) or $acc_id == $this->trunkwardName)) {// its an account on this node.
      return [$this->accountStore->fetch($acc_id), implode('/', $parts)];
    }
    elseif ($acc_id) {
      // No matches
      return [NULL, NULL];
    }
    else {// all accounts on this node.
      return [NULL, '*'];
    }
  }

  /**
   * Return the account name that points to another node, and alter the $path_parts to be relative to that account.
   */
  public function relativeToThisNode(array &$path_parts) : string {
    // handle all local and branchward nodes.
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
      else {// Rare
        // the node name appears under a different trunk.
        // Freaky coincidence, but pass trunkward.
        return $this->trunkwardName;
      }
    }
    if (empty(array_filter($path_parts))) {
      // the current node.
      return '';
    }
    elseif ($this->accountStore->has(reset($path_parts), 'Remote')) {
      return array_shift($path_parts);
    }
    elseif ($this->accountStore->has(reset($path_parts))) {
      return '';
    }
    elseif($this->trunkwardName) {
      return $this->trunkwardName;
    }
    throw new DoesNotExistViolation(type: 'node', id: implode($path_parts));
  }

  /**
   * Find all the accounts in the tree that match the given fragment
   * @param type $fragment
   * @return type
   */
  function pathMatch($fragment) {
    global $user;
    // Make a list with all the matching trunkward node names and all the matching accounts.
    $tree = explode('/', getConfig('abs_path'));
    $trunkward_names = [];
    $trunkward_acc_id = getConfig('trunkward_acc_id');
    if ($trunkward_acc_id and $this->userId <> $trunkward_acc_id) {
      $trunkward_names = load_account($trunkward_acc_id)->autocomplete($fragment);
    }
    // Local names.
    $filtered = accountStore()->filterFull(fragment: $fragment);
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
    return array_merge($trunkward_names, $local);
  }

}
