<?php

namespace Sculpt;

/**
 * @package Sculpt
 */
class Exception extends \Exception {}

/**
 * @package Sculpt
 */
class RecordNotFoundException extends Exception {
  public function __construct($scope) {
    parent::__construct('record not found');
  }
}

/**
 * @package Sculpt
 */
class RecordInvalidException extends Exception {
  public function __construct($obj) {
    parent::__construct("Validation failed: $obj->errors");
  }
}

/**
 * @package Sculpt
 */
class UndefinedAttributeException extends Exception {
  public function __construct($class, $attr_name) {
    $msg = "undefined attribute $class::$attr_name accessed";
    parent::__construct($msg);
  }
}
