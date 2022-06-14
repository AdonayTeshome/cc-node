<?php

namespace CCNode;

class ConfigFromIni implements ConfigInterface {
#class ConfigFromIni {

  function __construct(array $ini_file) {
    $this->accountStoreUrl = $ini_file['account_store_url'];
    $this->blogicServiceUrl = $ini_file['blogic_service_url'];
    $this->dbCreds = $ini_file['db']; // Array
    $this->absPath = $ini_file['abs_path'];
    $this->displayFormat = $ini_file['display_format'];
    $this->zeroPayments = $ini_file['zero_payments'];
    $this->validatePending = $ini_file['validate_pending'];
    $this->devMode = $ini_file['dev_mode'];
    $tree = explode('/', $this->absPath);
    $this->nodeName = end($tree);
    $this->trunkwardAcc = '';
    if (count($tree) > 1) {
      $this->conversionRate = $ini_file['conversion_rate'];
      $this->privacy = $ini_file['priv']; // Array
      $this->timeOut = $ini_file['timeout'];
      $this->validatedWindow = $ini_file['validated_window'];
      $this->trunkwardAcc = prev($tree);
    }
  }
}
