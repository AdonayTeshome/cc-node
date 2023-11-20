<?php
namespace CCNode;

use CreditCommons\Workflow;
use CCNode\API_calls;

/**
 * incorporates locally stored workflows into the json tree.
 *
 * @todo Write the aggregated workflows somewhere, somehow so the tree doesn't
 *   need to be build every request. But the app so far doesn't have write
 *   permission for files, and there's no suitable database table.
 */
class Workflows extends \CreditCommons\Workflows {

  /**
   * Local workflow data from file.
   * @var array
   */
  protected $local = [];

  /**
   *
   * @param string $file_path
   * @throws \CreditCommons\Exceptions\CCFailure
   */
  function __construct(string $file_path) {
    if (!file_exists($file_path)) {
      throw new \CreditCommons\Exceptions\CCFailure('Missing $file_path file at '.getcwd());
    }
    $this->local = json_decode(file_get_contents($file_path));
    if (empty($this->local)) {
      throw new \CreditCommons\Exceptions\CCFailure('Bad json workflows file');
    }
    parent::__construct(API_calls());
  }

  /**
   * Incorporate the local workflows into the tree, overriding any similar w
   * workflows declared by more trunkward nodes.
   */
  function loadAll() : array {
    global $cc_config;
    if ($this->local) {
      foreach ($this->local as $wf_data) {
        $wf = new Workflow($wf_data);
        $local[$wf->gethash()] = $wf;
      }
      $tree = parent::loadAll();
      // Now compare the hashes, and where similar, replace the trunkward one with the local translation.
      foreach ($tree as $node_name => $wfs) {
        foreach ($wfs as $hash => $wf) {
          if (isset($local[$hash])) {
            $tree[$node_name][$hash] = $local[$hash];
            unset($local[$hash]);
          }
        }
      }
      // any remaining local workflows are listed under this node.
      if ($local) {
        $tree[$cc_config->nodeName] = $local;
      }
    }
    return $tree;
  }

}
