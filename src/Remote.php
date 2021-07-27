<?php

namespace CreditCommons;

use CreditCommons\Account;


/**
 * Remote accounts are differentiated by having a url, and are sometimes treated differently.
 */
abstract class Remote extends Account {
  public $url;

  function __construct($obj) {
    parent::__construct($obj);
    $this->url = $obj->url;
  }


  abstract function getLastHash() : string;
  

  public static function validateFields(\stdClass $obj) :array {
    $errs = parent::validateFields($obj);
    $obj->url = strtolower($obj->url);
    // must be a pure domain or subdomain.
    if (!preg_match('/https?:\/\/[a-z1-9.]+/', $obj->url)) {
      $errs['url'] = $obj->url .' is not a valid url';
    }
    return $errs;
  }


  function overridden() : \stdClass {
    $output = parent::overridden();
    $output->url = $this->url;
    return $output;
  }
}
