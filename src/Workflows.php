<?php
namespace CCNode;

use CreditCommons\Workflow;
use CCNode\API_calls;

/**
 * incorporates locally stored workflows
 *
 * @todo Write the aggregated workflows somewhere, somehow it doesn't
 *   need to be built every request. But the app so far doesn't have write
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
  function __construct(array $raw_workflows) {
    global $cc_config;
    foreach ($raw_workflows as $id => $wf) {
      $wf->home = $cc_config->nodeName;
      $this->local[$id] = $wf;
    }
    parent::__construct(API_calls());
  }

  /**
   * Incorporate the local workflows, overriding any similar workflows declared
   * by more trunkward nodes.
   */
  function loadAll() : array {
    if ($this->local) {
      foreach ($this->local as $wf_data) {
        $wf = new Workflow($wf_data);
        $local[$wf->gethash()] = $wf;
      }
      // Get the trunkward workflows and merge them with the local ones.
      $all_wfs = parent::loadAll();
      // Replace trunkward workflows with local (translated) versions.
      foreach ($all_wfs as $hash => $wf) {
        if (isset($local[$hash])) {
          $all_wfs[$hash] = $local[$hash];
          $all_wfs[$hash]->home = $wf->home;
          unset($local[$hash]);
        }
      }
      // Only remaining local workflows are in fact local.
      $all_wfs += $local;
    }
    return $all_wfs;
  }

}
