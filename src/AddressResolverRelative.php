<?php
namespace CCNode;

use CCNode\AccountStore;
use CreditCommons\Exceptions\DoesNotExistViolation;

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
 * NB Superceded
 */
class AddressResolver {

  private $accountStore;

  function __construct(AccountStore $accountStore) {
    $this->accountStore = $accountStore;
  }

  static function create() {
    return new static(AccountStore::create());
  }
  /**
   *
   * @global type $user
   * @param string $given_acc_path
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
   *     the trunkwards path is returned.
   *   Otherwise the result is invalid path
   */
  public function resolveTolocalAccount(string $given_acc_path) : array {
    global $user;

    $parts = explode('/', $given_acc_path);
    if ($acc_id = $this->relativeToThisNode($parts)) {
      // $parts has been reduced to the branchward path.
      if ($this->accountStore->has($acc_id)) {
        return [$this->accountStore->fetch($acc_id), implode('/', $parts)];
      }
    }
    elseif ($user->id <> getConfig('trunkwards_name')) {
      return [$this->accountStore->fetch(getConfig('trunkwards_name')), $given_acc_path];
    }
    throw new DoesNotExistViolation(type: 'account', id: $given_acc_path);
  }

  /**
   * Return the name of the node we are concerned with, altering the $path_parts
   * so they describe something relative to that node.
   *
   * @param array $path_parts
   * @return string
   */
  public function relativeToThisNode(array &$path_parts) : string {
    $node_name = getConfig('node_name');
    if (count($path_parts) == 1) {
      return array_pop($path_parts);
    }
    if (reset($path_parts) == $node_name) {
      array_shift($path_parts);
      return array_shift($path_parts);
    }
    if ($this->accountStore->has(reset($path_parts))) {
      return array_shift($path_parts);
    }

    $pos = array_search(getConfig('trunkwards_name'), $path_parts);
    if ($pos !== NULL) {
      if (isset($path_parts[$pos+1]) and $path_parts[$pos+1] == $node_name) {
        $path_parts = array_slice($path_parts, $pos+2);
        return (string)array_shift($path_parts);
      }
    }
    return FALSE;
  }

}
