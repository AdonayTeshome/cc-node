<?php

function replaceIni(array $array, string $filename): int {
  $contents = file_get_contents($filename);
  foreach($array as $key => $val) {
    if(is_array($val)) {
      foreach($val as $skey => $sval) {
        $find = "/$key\[".$skey."\] ?=.*/";
        $replace = "$key".'['. $skey .'] = '.(is_numeric($sval) ? $sval : '"'.$sval.'"');
        $contents = preg_replace($find, $replace, $contents);
      }
    }
    else {
      $contents = preg_replace(
        "/$key ?=.*/",
        "$key = ".(is_numeric($val) ? $val : '"'.$val.'"'),
        $contents
      );
    }
  }
  if ($contents) {
    $filename = str_replace('.example', '', $filename);
    return file_put_contents($filename, $contents);
  }
  throw new \Exception('Problem with preg_replace settings on '.$filename);
}

