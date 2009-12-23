<?php

/**
 * Sculpt
 *
 * A PHP ORM.
 *
 * Copyright (c) 2009 Trevor Rowe
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to 
 * deal in the Software without restriction, including without limitation the 
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or 
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING 
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER 
 * DEALINGS IN THE SOFTWARE.
 *
 * @author Trevor Rowe
 * @package Sculpt
 * @version 0.1
 *
 */

namespace Sculpt;

use PDO;
use PDOException;
use DateTime;

/**
 * Returns true if the argument is an associative array (hash).
 *
 * @param array $array the array to test
 * @return boolean 
 */
function is_assoc($array) {
  return is_array($array) && array_diff_key($array, array_keys(array_keys($array)));
}

/**
 * @package Sculpt
 */
class Logger {

  private static $logger;

  public static function set_logger($logger) {
    self::$logger = $logger;
  }

  public static function log($query, $seconds, $values) {
    if(self::$logger) {

      static $i = 0;
      static $colors = array("\x1b[36m","\x1b[35m");
      static $weights = array("\x1b[1m", '');

      $reset = "\x1b[0m";
      $bold = "\x1b[1m";
      $color = $colors[$i % 2];
      $weight = $weights[$i % 2];
      $time = self::format_seconds($seconds);

      $vals = '';
      if(!empty($values))
        $vals = ' [' . implode(', ', $values) . ']';

      $msg = "$color{$bold}[Sculpt] ($time)$reset$weight $query$reset$vals";
      self::$logger->log($msg);
      $i += 1;
    }
  }

  protected static function format_seconds($seconds) {
    $milli = round($seconds * 10000.0) / 10.0;
    switch(true) {
      case $milli < 1000: 
        return sprintf("%.1fms", $milli); 
      case $milli < (1000 * 60): 
        return sprintf("%.2f seconds", $milli / 1000); 
      default:
        $mins = floor(($milli / 1000) / 60);
        $seconds = ($milli - $mins * 1000 * 60) / 1000;
        return sprintf("%d mins %.2f seconds", $mins, $seconds);
    }
  }

}

/**
 * @package Sculpt
 */
abstract class Connection {

  private static $default = null;

  private static $details = array();

  private static $pool = array();

  protected $c;

  public static function add($name, $details) {
    if(isset(self::$details[$name]))
      throw new Exception("connection already defined: $name");
    if(!isset($details['adapter']))
      throw new Exception("missing required connection option adapter: $name");
    self::$details[$name] = $details;
  }

  public static function load_ini_file($ini_path) {
    $ini = parse_ini_file($ini_path, true);
    foreach($ini as $name => $details)
      self::add($name, $details);
  }

  public static function set_default($name) {
    if(!isset(self::$details[$name]))
      throw new Exception("could not find a connection named: $name");
    self::$default = $name;
  }

  public static function get($name = null) {

    if(is_null($name)) {
      if(is_null(self::$default))
        throw new Exception('unable to connect: no default connection name');
      $name = self::$default;
    }

    if(!isset(self::$pool[$name])) {
      if(!isset(self::$details[$name]))
        throw new Exception("unable to connect, no connection named: $name");
      self::$pool[$name] = Connection::build(self::$details[$name]);
    }

    return self::$pool[$name];
      
  }

  public static function build($details) {
    switch($details['adapter']) {
      case 'mysql':
        return new MySQLConnection($details);
      default:
        throw new Exception("unknown connection adapter {$details['adapter']}");
    }
  }

  protected function __construct($details) {
    $dsn = $details['dsn'];
    $username = isset($details['username']) ? $details['username'] : null;
    $password = isset($details['password']) ? $details['password'] : null;
    $opts = array(
      PDO::ATTR_CASE => PDO::CASE_LOWER,
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
      PDO::ATTR_STRINGIFY_FETCHES  => false,
      PDO::ATTR_PERSISTENT => false,
    );
    $this->c = new PDO($dsn, $username, $password, $opts);
  } 

  public function query($sql, &$values = array()) {
    $start = microtime(true);
    try {
      $sth = $this->c->prepare($sql);
    } catch(PDOException $e) {
      throw new Exception("PREPARE FAILED: $sql");
    }
    $sth->setFetchMode(PDO::FETCH_ASSOC);
    try {
      $sth->execute($values);
    } catch(PDOException $e) {
      throw new Exception("EXECUTE FAILED: $sql");
    }

    Logger::log($sql, microtime(true) - $start, $values);
    return $sth;
  }

  public function insert_id($sequence = null) {
    return $this->c->lastInsertId($sequence);
  }
  
  abstract public function tables();

  abstract public function columns($table_name);

}

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
        $value = date_create($value);
        $errors = \DateTime::getLastErrors();
        if ($errors['warning_count'] > 0 || $errors['error_count'] > 0)
          return null;
        return $value;
    }
  }

}

/**
 * @package Sculpt
 */
class MySQLConnection extends Connection {

  public function quote_name($name) {
    return "`$name`";
  }

  public function tables() {
    $tables = array();
    $sth = $this->query('SHOW TABLES');
    while($row = $sth->fetch(PDO::FETCH_NUM))
      $tables[] = $row[0];
    return $tables;
  }

  public function columns($table) {
    $columns = array();
    $sth = $this->query("SHOW COLUMNS FROM $table");
    while($row = $sth->fetch()) {
      $column = new MySQLColumn($row);
      $columns[$column->name()] = $column;
    }
    return $columns;
  }

}

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
class NonExistantAttributeException extends Exception {
  public function __construct($class, $attr_name) {
    $msg = "$class class: undefined attribute setter called: $attr_name";
    parent::__construct($msg);
  }
}

/**
 * @package Sculpt
 */
class NonWhitelistedAttributeBulkAssigned extends Exception {
  public function __construct($class, $attr_name) {
    $msg = "$class class: non-whitelisted attribute `$attr_name` bulk assigned";
    parent::__construct($msg);
  }
}

/**
 * @package Sculpt
 */
class BlacklistedAttributeBulkAssigned extends Exception {
  public function __construct($class, $attr_name) {
    $msg = "$class class: blacklisted attribute `$attr_name` bulk assigned";
    parent::__construct($msg);
  }
}

/**
 * @package Sculpt
 */
class Table {

  private static $cache = array();

  public $name;
  public $class;
  public $columns;
  public $connection;

  public static function get($class_name) {
    if(!isset(self::$cache[$class_name]))
      self::$cache[$class_name] = new Table($class_name);
    return self::$cache[$class_name];
  }

  protected function __construct($class) {
    
    $this->class = $class;

    $this->connection = Connection::get($class::$connection);

    $this->name = isset($class::$table_name) ?
      $class::$table_name :
      strtolower($class) . 's';
      # TODO : use a string inflector to create a table name

    $this->columns = $this->connection->columns($this->name);

  }

  # TODO : move to the abstract connection classs?
  public function select($opts = array()) {

    $bind_params = array();

    $sql = $this->select_fragment($opts);
    $sql .= $this->from_fragment($opts);
    $sql .= $this->where_fragment($opts, $bind_params);

    #if(isset($parts['joins']))
    #  $sql .= " WHERE {$parts['joins']}";

    if(!empty($opts['group'])) {
      $sql .= " GROUP BY {$opts['group']}";
      if(isset($opts['having']))
        $sql .= " HAVING {$opts['having']}";
    }

    if(isset($opts['order']))
      $sql .= " ORDER BY {$opts['order']}";

    if(isset($opts['limit']))
      $sql .= " LIMIT {$opts['limit']}";

    if(isset($opts['offset']))
      $sql .= " OFFSET {$opts['offset']}";

    $objects = array();
    $sth = $this->connection->query($sql, $bind_params);
    $class = $this->class;
    while($row = $sth->fetch()) {
      $objects[] = $class::hydrate($row);
    }
    return $objects;
  }

  private function select_fragment($opts) {
    if(isset($opts['select']))
      $select = $opts['select'];
    else
      $select = '*';
    return "SELECT $select";
  }

  private function from_fragment($opts) {
    $from = isset($parts['from']) ? $parts['from'] : $this->name;
    return " FROM $from";
  }

  private function where_fragment($opts, &$bind_params) {

    if(empty($opts['where']))
      return '';

    $conditions = array();
    foreach($opts['where'] as $where) {
      switch(true) {

        # 'admin' => array('admin' => true)
        case is_assoc($where):
          $condition = array();
          foreach($where as $col_name => $col_value)
            $condition[] = "$col_name = ?";
          $condition = implode(' AND ', $condition);
          $bind_params = array_merge($bind_params, array_values($where));
          break;

        # 'admin' => array('admin = ?', true)
        case is_array($where):
          $condition = array_shift($where);
          $bind_params = array_merge($bind_params, $where);
          break;

        # admin => 'admin = 1'
        case is_string($where):
          $condition = $where;
          break;

        default:
          throw new Exception("invalid where condition");

      }
      $conditions[] = "($condition)";
    }
    return " WHERE " . implode(' AND ', $conditions);
  }

  # TODO : public function insert() {}

  # TODO : public function update() {}

  # TODO : public function delete() {}

}

/**
 * @package Sculpt
 */
class Scope {

  private $table;

  private $sql_parts = array(
    'select' => null,
    'from'   => null,
    'where'  => array(),
    'joins'  => array(),
    'group'  => null,
    'having' => null,
    'order'  => null,
    'limit'  => null,
    'offset' => null,
  );

  public function __construct($table) {
    $this->table = $table;
  }

  public function from($table_name) {
    $this->sql_parts['from'] = $table_name;
    return $this;
  }

  public function select($select_sql_fragment) {
    $this->sql_parts['select'] = $select_sql_fragment;
    return $this;
  }

  public function where($where) {
    $args = func_get_args();
    if(count($args) == 1)
      $this->sql_parts['where'][] = $where;
    else
      $this->sql_parts['where'][] = $args;
    return $this;
  }

  public function joins($joins) {
    if(is_array($joins))
      $this->sql_parts['joins'] = array_merge($this->sql_parts['joins'], $joins);
    else
      $this->sql_parts['joins'][] = $joins;
    return $this;
  }

  public function group_by($group_by_sql_fragment) {
    $this->sql_parts['group'] = $group_by_sql_fragment;
    return $this;
  }

  public function having($having_sql_fragment) {
    $this->sql_parts['having'] = $having_sql_fragment;
    return $this;
  }

  public function order($order_sql_fragment) {
    $this->sql_parts['order'] = $order_sql_fragment;
    return $this;
  }

  public function limit($limit) {
    $this->sql_parts['limit'] = $limit;
    return $this;
  }

  public function offset($offset) {
    $this->sql_parts['offset'] = $offset;
    return $this;
  }

  public function find($opts = array()) {
    $this->apply_static_scope($opts);
    return $this;
  }

  public function __get($scope) {
    if(in_array($scope, array('all', 'first', 'get')))
      return $this->$scope();
    $this->$scope();
    return $this;
  }

  public function __call($method, $args) {

    $class = $this->table->class;

    # static class scope (e.g. User::$scopes['admin'])
    if(isset($class::$scopes[$method])) {
      $this->apply_static_scope($class::$scopes[$method]);
      return $this;
    }

    # dynamic class scope (e.g. User::admin_scope($scope))
    $static_method = "{$method}_scope";
    if(method_exists($class, $static_method)) {
      $class::$static_method($this);
      return $this;
    }

    # sorting (ascend or decend by a single column)
    # TODO : raise an exception if not a valid column
    if(preg_match('/(asc|ascend|desc|descend)_by_(.+)/', $method, $matches)) {
      $dir = $matches[1] == 'asc' || $matches[1] == 'ascend' ? 'ASC' : 'DESC';
      $this->order("{$matches[2]} $dir");
      return $this;
    }

    # auto-magical scopes id_is, age_lte, etc
    $columns = array_keys($this->table->columns);
    $regex = '/(' . implode('|', $columns) . ')_(.+)/';
    if(preg_match($regex, $method, $matches)) {
      $col = $matches[1];
      switch($matches[2]) {
        case 'is':
        case 'equals':
          $this->where("$col = ?", $args[0]);
          return $this;
        case 'is_not':
        case 'not_equal':
        case 'not_equal_to':
        case 'does_not_equal':
          $this->where("$col != ?", $args[0]);
          return $this;
        case 'begins_with':
          $this->where("$col LIKE ?", "{$args[0]}%");
          return $this;
        case 'not_begin_with':
          $this->where("$col NOT LIKE ?", "{$args[0]}%");
          return $this;
        case 'ends_with':
          $this->where("$col LIKE ?", "%{$args[0]}");
          return $this;
        case 'not_end_with':
        case 'not_ends_with':
        case 'not_ending_with':
          $this->where("$col NOT LIKE ?", "%{$args[0]}");
          return $this;
        case 'like':
          $this->where("$col LIKE ?", "%{$args[0]}%");
          return $this;
        case 'not_like':
          $this->where("$col NOT LIKE ?", "%{$args[0]}%");
          return $this;
        case 'gt':
        case 'greater':
        case 'greater_than':
          $this->where("$col > ?", $args[0]);
          return $this;
        case 'gte':
        case 'greater_than_or_equal':
        case 'greater_than_or_equal_to':
          $this->where("$col >= ?", $args[0]);
          return $this;
        case 'lt':
        case 'less':
        case 'less_than':
          $this->where("$col < ?", $args[0]);
          return $this;
        case 'lte':
        case 'less_than_or_equal':
        case 'less_than_or_equal_to':
          $this->where("$col <= ?", $args[0]);
          return $this;
        case 'null':
        case 'is_null':
          $this->where("$col IS NULL");
          return $this;
        case 'not_null':
        case 'is_not_null':
          $this->where("$col IS NOT NULL");
          return $this;
        case 'blank':
        case 'is_blank':
          $this->where("$col IS NULL OR $col = ''");
          return $this;
        case 'not_blank':
        case 'is_not_blank':
          $this->where("$col IS NOT NULL AND $col != ''");
          return $this;
      }
    }

    throw new Exception("undefine scope $class::$method");

    return $this;
  }

  protected function apply_static_scope($static_scope) {
    foreach($static_scope as $key => $value)
      if(is_numeric($key)) # some_other_scope
        $this->$value;
      else # where
        $this->$key($value);
  }

  public function get($id = null) {

    $scope = clone $this;

    if(!is_null($id))
      $scope->id_is($id);

    $record = $scope->first();  
    if(is_null($record))
      throw new RecordNotFoundException($scope);

    return $record;
  }

  public function first() {
    $scope = clone $this;
    $results = $scope->limit(1)->all();
    return empty($results) ? null : $results[0];
  }

  public function all() {
    return $this->table->select($this->sql_parts);
  }

  public function paginate($page = null, $per_page = null) {

    if(is_null($page))
      $page = 1;

    if(is_null($per_page))
      $per_page = Collection::$default_per_page;

    $total = $this->count();

    $scope = clone $this;
    $scope->limit($per_page)->offset(($page - 1) * $per_page);
    $objects = $scope->all();

    return new Collection($page, $per_page, $total, $objects);
  }

  public function count() {
    $scope = clone $this;
    $scope->select('COUNT(*) AS count');
    return $scope->first()->count;
  }

}

/**
 * @package Sculpt
 */
class Collection implements \Iterator, \Countable, \ArrayAccess {

  public static $default_per_page = 10;

  public $page;
  public $per_page;
  public $total;

  private $objects;
  private $position = 0;

  public function __construct($page, $per_page, $total, $objects) {
    $this->objects = $objects;
    $this->page = $page;
    $this->per_page = $per_page;
    $this->total = $total;
  }

  public function rewind() {
    $this->position = 0;
  }

  public function current() {
    return $this->objects[$this->position];
  }

  public function key() {
    return $this->position;
  }

  public function next() {
    $this->position += 1;
  }

  public function valid() {
    return isset($this->objects[$this->position]);
  }

  public function offsetExists($offset) {
    return isset($this->objects[$offset]);
  }

  public function offsetSet($offset, $value) {
    throw new Exception(__CLASS__ . ' is inmutable');
  }

  public function offsetGet($offset) {
    return $this->objects[$offset];
  }

  public function offsetUnset($offset) {
    throw new Exception(__CLASS__ . ' is inmutable');
  }

  public function count() {
    return $this->total;
  }

  public function total() {
    return $this->total;
  }

}

/**
 * @package Sculpt
 */
abstract class Model implements \ArrayAccess {

  static $connection = null;

  static $attr_accessors = array();
  static $attr_whitelist = array();
  static $attr_blacklist = array();

  static $scopes = array();

  public $errors;

  protected $class;
  protected $table;

  private $attr = array();

  public function __construct($attributes = null) {

    $this->class = get_called_class();
    $this->table = Table::get($this->class);
    $this->errors = new Errors();

    # set default values for this object based on column defaults
    foreach($this->table->columns as $attr_name => $column)
      $this->attr[$attr_name] = $column->default_value();

    if(!is_null($attributes))
      $this->set_attributes($attributes);
  }

  public static function hydrate($attributes) {
    $class = get_called_class();
    $obj = new $class();
    foreach($attributes as $attr_name => $attr_value)
      $obj->_set($attr_name, $attr_value);
    return $obj;
  }

  public function is_new_record() {
    return !is_null($this->attr['id']);
  }

  public function __get($attr_name) {
    return $this->attribute($attr_name);
  }

  public function __set($attr_name, $attr_value) {
    $this->set_attribute($attr_name, $attr_value);
  }

  public function __isset($attr_name) {
    return !is_null($this->attribute($attr_name));
  }

  public function offsetExists($attr_name) {
    return isset($this->$attr_name);
  }

  public function offsetSet($attr_name, $attr_value) {
    $this->set_attribute($attr_name, $attr_value);
  }

  public function offsetGet($attr_name) {
    return $this->attribute($attr_name);
  }

  public function offsetUnset($attr_name) {
    $this->set_attribute($attr_name, null);
  }

  public function attribute_before_type_cast($name) {
    return $this->_get($name, false);
  }

  public function attribute($name) {
    $getter = "_$name";
    if(method_exists($this, $getter))
      return $this->$getter();
    else
      return $this->_get($name);
  }

  public function set_attribute($name, $value) {
    $setter = "_set_$name";
    if(method_exists($this, $setter))
      $this->$setter($value);
    else
      $this->_set($name, $value);
  }

  protected function _get($name, $type_cast = true) {
    #$this->ensure_attr_defined($name);
    $value = isset($this->attr[$name]) ? $this->attr[$name] : null;
    if(isset($this->table->columns[$name]) && $type_cast) {
      $value = $this->table->columns[$name]->cast($value);
    }
    return $value;
  }

  protected function _set($name, $value) {
    #$this->ensure_attr_defined($name);
    if(isset($this->table->columns[$name])) {
      # TODO : track changes for dirty tracking
    }
    $this->attr[$name] = $value;
  }
  
  #protected function ensure_attr_defined($name) {
  #  if(!isset($this->table->columns[$name]) && 
  #     !in_array($name, static::$attr_accessors)) 
  #  {
  #    throw new NonExistantAttributeException($this->class, $name);
  #  }
  #}

  protected function bulk_assign($attributes) {
    if(empty(static::$attr_whitelist) && empty(static::$attr_blacklist)) {
      # no whitelist or blacklist
      foreach($attributes as $name => $value)
        $this->set_attribute($name, $value);
    } else if(!empty(static::$attr_whitelist)) {
      # whitelisting
      foreach($attributes as $name => $value) {
        if(in_array($name, static::$attr_whitelist))
          $this->set_attribute($name, $value);
        else
          throw new NonWhitelistedAttributeBulkAssigned($this->class, $name);
      }
    } else {
      # blacklisting
      foreach($attributes as $name => $value) {
        if(in_array($name, static::$attr_blacklist))
          throw new BlacklistedAttributeBulkAssigned($this->class, $name);
        else
          $this->set_attribute($name, $value);
      }
    }
  }

  public function attributes() {
    $attributes = array();
    foreach(array_keys($this->attr) as $attr_name)
      $attributes[$attr_name] = $this->attribute($attr_name);
    return $attributes;
  }

  public function set_attributes($attributes) {
    $this->bulk_assign($attributes);
  }

  public function update_attributes($attributes) {
    $this->set_attributes($attributes);
    return $this->save();
  }

  public function create() {
    if($this->validate()) {
      $this->table->insert($this);
      return true;
    } else {
      return true;
    }
  }

  public function save() {
    if($this->is_new_record()) {
      return $this->create();
    } else {
      if($this->validate()) {
        $this->table->update($this);
        return true;
      } else {
        return false;
      }
    }
  }

  public function force_save() {
    if(!$this->save())
      throw new RecordInvalidException($this);
  }

  public function validate() {
    $this->errors->clear();
    # TODO : run validations
    return $this->errors->is_empty();
  }

  public function to_param() {
    return $this->_get('id');
  }

  public static function __callStatic($method, $args) {
    $scope = static::scope();
    call_user_func_array(array($scope, $method), $args);
    return $scope;
  }

  public static function scope() {
    return new Scope(Table::get(get_called_class()));
  }

  public static function get($id = null) {
    return static::scope()->get($id);
  }

  public static function first() {
    return static::scope()->first();
  }

  public static function all() {
    return static::scope()->all();
  }

  public static function paginate($page, $per_page = null) {
    return static::scope()->paginate($page, $per_page);
  }

  public static function count() {
    return static::scope()->count();
  }

  public static function find($opts = array()) {
    return static::scope()->find($opts);
  }

  public static function table_name() {
    return Table::get(get_called_class())->name;
  }

  public static function columns() {
    return Table::get(get_called_class())->columns;
  }

}

/**
 * @package Sculpt
 */
class Errors {

  protected $msgs = array();

  public function add_to_base($msg) {
    $this->add(null, $msg);
  }

  public function add($to, $msg) {
    if(isset($this->msgs[$to]))
      array_push($this->msgs[$to], $msg);
    else
      $this->msgs[$to] = array($msg);
  }

  public function on($attr) {
    return isset($this->msgs[$attr]) ? $this->msgs[$attr] : null;
  }

  public function on_base() {
    return $this->on(null);
  }

  public function count() {
    return count($this->msgs);
  }

  public function is_empty() {
    return empty($this->msgs);
  }

  public function is_invalid($attr) {
    return isset($this->msgs[$attr]);
  }

  public function full_messages() {

    if(empty($this->msgs))
      return array();

    $messages = array();
    $this->each_full_message(function($message) use (&$messages) {
      array_push($messages, $message);
    });
    return $messages;
  }

  public function each_message($callback) {
    foreach($this->msgs as $on => $messages)
      foreach($messages as $message)
        $callback($on, $message);
  }

  public function each_full_message($callback) {
    foreach($this->msgs as $on => $messages)
      foreach($messages as $message)
        $callback($this->expand_message($on, $message));
  }

  protected function expand_message($on, $msg) {
    # TODO : $on needs to be titleized
    return trim("$on $msg");
  }

  public function clear() {
    $this->msgs = array();
  }

  public function __toString() {
    return implode(', ', $this->full_messages());
  }

  # TODO : public function to_xml() {}
  # TODO : public function to_json() {}
  # TODO : implement iterator interface, should iterate through full_messages
}
