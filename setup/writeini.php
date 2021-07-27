<?php

function replaceIni(array $array, string $filename): int {
  $inifile = file_get_contents($filename);
  foreach($array as $key => $val) {
    if(is_array($val)) {
      foreach($val as $skey => $sval) {
        $inifile = preg_replace(
          "/$key\[".$skey."\] ?=.*/",
          "$key".'['. $skey .'] = '.(is_numeric($sval) ? $sval : '"'.$sval.'"'),
          $inifile
        );
      }
    }
    else {
      $inifile = preg_replace(
        "/$key ?=.*/",
        "$key = ".(is_numeric($val) ? $val : '"'.$val.'"'),
        $inifile
      );
    }
  }
  return file_put_contents($filename, $inifile);
}

