<?php

namespace CCNode;

class CCNodeConfig implements ConfigInterface {

  function __construct(
    public array $dbCreds,
    public string $accountStore,
    public string $blogicMod,
    public bool $zeroPayments,
    public bool $validatePending,
    public bool $devMode,
    public string $workflowsFile,
    public string $absPath,
    public string $nodeName,
    public float $conversionRate,
    public int $timeOut,
    public int $validatedWindow,
    public string $trunkwardAcc,
    public string $displayFormat,
    public array $privacy
) {}

  static function createFromIniArray(array $settings) {
    $tree = explode('/', $settings['abs_path']);
    return new static(
      dbCreds: $settings['db'], // Array
      accountStore: $settings['account_store']??'\Examples\AccountStore',
      blogicMod: $settings['blogic_mod'], // optional
      zeroPayments: $settings['zero_payments']??false,
      validatePending: $settings['validate_pending']??true,
      devMode: $settings['dev_mode']??false,
      workflowsFile: $settings['workflows_filepath'],
      // The rest are only used when there are remote accounts.
      absPath: @$settings['abs_path']??'mynode',
      nodeName: end($tree),
      conversionRate: count($tree) > 1 ? $settings['conversion_rate'] : 1,
      privacy: count($tree) > 1 ? $settings['priv'] : [],
      timeOut: count($tree) > 1 ? $settings['timeout'] : 0,
      validatedWindow: count($tree) > 1 ? $settings['validated_window'] : 0,
      trunkwardAcc: count($tree) > 1 ? prev($tree) : '',
      displayFormat: $settings['display_format'] ?? ''
    );

  }

}
