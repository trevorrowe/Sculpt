<?php

require_once(dirname(__FILE__) . '../../simpletest/autorun.php');
require_once(dirname(__FILE__) . '../sculpt.php');
require_once(dirname(__FILE__) . '/lib/user.php');

class TestOfValidations extends UnitTestCase {

  function testValidatesPresenceOf() {
    $user = new User();
    $this->assertFalse($user->is_valid());
  }

}
