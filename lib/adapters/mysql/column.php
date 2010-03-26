<?php

namespace Sculpt;

/**
 * @package Sculpt
 */
class MySQLColumn extends Column {

  protected $name;

  protected $type;

  protected $default_value;

  protected static $type_mappings = array(
    'tinyint(1)' => self::BOOLEAN,
    'datetime'   => self::DATETIME,
    'timestamp'  => self::DATETIME,
    'date'       => self::DATE,
    'int'        => self::INTEGER,
    'tinyint'    => self::INTEGER,
    'smallint'   => self::INTEGER,
    'mediumint'  => self::INTEGER,
    'bigint'     => self::INTEGER,
    'float'      => self::DECIMAL,
    'double'     => self::DECIMAL,
    'numeric'    => self::DECIMAL,
    'decimal'    => self::DECIMAL,
    'dec'        => self::DECIMAL,
  );

  public function __construct($column_details) {

    $this->name = $column_details['field'];

    $raw_type = $column_details['type'];
    if(isset(self::$type_mappings[$raw_type]))
      $this->type = self::$type_mappings[$raw_type];
    else {
      preg_match('/^(.*?)\(([0-9]+(,[0-9]+)?)\)/', $raw_type, $matches);
      if(sizeof($matches) > 0 && isset(self::$type_mappings[$matches[1]]))
        $this->type = self::$type_mappings[$matches[1]];
      else
        $this->type = self::STRING;
    }

    $default = $column_details['default'];
    $this->default_value = $default === '' ? null : $this->cast($default);

  }

  public function name() {
    return $this->name;
  }

  public function type() {
    return $this->type;
  }

  public function default_value() {
    return $this->default_value;
  }

}
