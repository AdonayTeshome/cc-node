<?php

namespace CCNode;

use CCNode\Transaction\Entry;

interface CCBlogicInterface {

  /**
   * Given an entry and a transaction type, return new entries which might constitute transaction fees.
   *
   * @param string $type
   * @param \stdClass $entry
   * @return \stdClass[]
   */
  public function addRows(string $type, Entry $entry) : array;
}
