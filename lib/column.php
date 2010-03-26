<?php

namespace Sculpt;

use DateTime;

/**
 * @package Sculpt
 */
abstract class Column {

  const STRING   = 1;
  const INTEGER  = 2;
  const DECIMAL  = 3;
  const DATETIME = 4;
  const DATE     = 5;
  const BOOLEAN  = 6;

  abstract public function name();

  abstract public function type();

  abstract public function default_value();

  public function cast($value) {

    $type = $this->type();

    if($value === null)
      return null;

    switch($type) {
      case self::STRING:   return (string) $value;
      case self::INTEGER:  return (int) $value;
      case self::BOOLEAN:  return (boolean) $value;
      case self::DECIMAL:  return (double) $value;
      case self::DATETIME:
      case self::DATE:
        if($value instanceof DateTime)
          return $value;
        if(is_numeric($value))
          $value = strftime('%F %T', $value);
        $value = date_create($value);
        $errors = \DateTime::getLastErrors();
        if($errors['warning_count'] > 0 || $errors['error_count'] > 0)
          return null;
        return $value;
    }
  }

}
