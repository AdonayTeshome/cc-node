<?php

namespace AccountStore;

/**
 * Class for reading and writing policy data from a csv file
 */
class RemoteRecord extends Record {

  /**
   * The url of the remote node (remote accounts only)
   * @var string
   */
  public $url;


  function __construct(string $id, string $url, int $created, $status = NULL, $min = NULL, $max = NULL) {
    parent::__construct($id, $created, $status, $min, $max);
    $this->url = $url;
  }


}
