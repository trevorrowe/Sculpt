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

# TODO : enable custom error messages for validations
# TODO : write tests
# TODO : add string inflections for table names, assoc names, error msgs, etc
# TODO : write the "other" validations
# TODO : modify how static function scopes are called to allow args

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

function collect($array, $callback) {
  $results = array();
  foreach($array as $array_index => $array_element)
    $results[] = $callback($array_element, $array_index);
  return $results;
}

/**
 * @package Sculpt
 */
class Logger {

  private static $logger;

  public static function set_logger($logger) {
    self::$logger = $logger;
  }

  public static function log($query, $time, $values) {
    if(self::$logger) {

      static $i = 0;
      static $colors = array("\x1b[36m","\x1b[35m");
      static $weights = array("\x1b[1m", '');

      $reset = "\x1b[0m";
      $bold = "\x1b[1m";
      $color = $colors[$i % 2];
      $weight = $weights[$i % 2];
      if(is_numeric($time))
        $time = self::format_seconds($time);

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
      $sth->setFetchMode(PDO::FETCH_ASSOC);
      $sth->execute($values);

      Logger::log($sql, microtime(true) - $start, $values);

      return $sth;

    } catch(\Exception $e) {
      Logger::log($sql, 'FAILED', $values);
      throw $e;
    }
  }

  public function last_insert_id($sequence = null) {
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
class UndefinedAttributeException extends Exception {
  public function __construct($class, $attr_name) {
    $msg = "undefined attribute $class::$attr_name accessed";
    parent::__construct($msg);
  }
}

/**
 * @package Sculpt
 */
class Table {

  # caching constructed Table objects, use Table::get() to access these
  private static $cache = array();

  # caching query results
  protected $row_cache = array();

  public $class;

  public $name;

  public $connection;

  public $columns;

  public static function get($class_name) {
    if(!isset(self::$cache[$class_name]))
      self::$cache[$class_name] = new Table($class_name);
    return self::$cache[$class_name];
  }

  protected function __construct($class) {
    $this->class = $class;
    $this->name = isset($class::$table_name) ?
      $class::$table_name :
      tableize($class);
    $this->connection = Connection::get($class::$connection);
    $this->columns = $this->connection->columns($this->name);
  }

  public function insert($obj) {

    $columns = array();
    $bind_params = array(); 

    foreach($obj->changed() as $attr) {
      $columns[] = $attr;
      $bind_params[] = $obj->attribute($attr);
    }

    $timestamp = strftime('%Y-%m-%d %T');
    foreach(array('created_at', 'updated_at') as $timestamp_column) {
      if(isset($this->columns[$timestamp_column])) {
        $columns[] = $timestamp_column;
        $bind_params[] = $timestamp;
      }
    }

    $columns = implode(', ', $columns);
    $placeholders = rtrim(str_repeat('?, ', count($bind_params)), ', ');
    $sql = "INSERT INTO $this->name ($columns) VALUES ($placeholders)";
    $sth = $this->connection->query($sql, $bind_params);
    $obj->id = $this->connection->last_insert_id();
  }

  public function update($obj) {

    if($obj->is_unchanged()) return;

    $set = array();
    $bind_params = array();
    foreach($obj->changed() as $attr) {
      $set[] = "$attr = ?";
      $bind_params[] = $obj->attribute($attr);
    }

    if(isset($this->columns['updated_at'])) {
      $set[] = 'updated_at = ?';
      $bind_params[] = strftime('%Y-%m-%d %T');
    }

    $bind_params[] = $obj->id;
    $set = implode(', ', $set);
    $sql = "UPDATE $this->name SET $set WHERE id = ?";
    $sth = $this->connection->query($sql, $bind_params);
  }

  # TODO : update the destory method
  public function delete($sql_parts = array()) {
    $bind_params = array();
    $sql = 'DELETE';
    $sql .= $this->from_fragment($sql_parts);
    $sql .= $this->joins_fragment($sql_parts);
    $sql .= $this->where_fragment($sql_parts, $bind_params);
    $sth = $this->connection->query($sql, $bind_params);
    return $sth->rowCount();
  }

  public function select($sql_parts, $cache) {

    if($cache && ($row_cache = $this->row_cache_hit($sql_parts)))
      return $row_cache;

    $bind_params = array();

    $sql  = $this->select_fragment($sql_parts);
    $sql .= $this->from_fragment($sql_parts);
    $sql .= $this->joins_fragment($sql_parts);
    $sql .= $this->where_fragment($sql_parts, $bind_params);

    if(!empty($sql_parts['group'])) {
      $sql .= " GROUP BY {$sql_parts['group']}";
      if(isset($sql_parts['having']))
        $sql .= " HAVING {$sql_parts['having']}";
    }

    if(isset($sql_parts['order']))
      $sql .= " ORDER BY {$sql_parts['order']}";

    if(isset($sql_parts['limit']))
      $sql .= " LIMIT {$sql_parts['limit']}";

    if(isset($sql_parts['offset']))
      $sql .= " OFFSET {$sql_parts['offset']}";

    $sth = $this->connection->query($sql, $bind_params);
    $rows = $sth->fetchAll();

    if($cache)
      $this->cache_rows($sql_parts, $rows);

    return $rows;

  }

  private function select_fragment($sql_parts) {
    if(isset($sql_parts['select']))
      $select = $sql_parts['select'];
    else
      $select = '*';
    return "SELECT $select";
  }

  private function from_fragment($parts) {
    $from = isset($parts['from']) ? $parts['from'] : $this->name;
    return " FROM $from";
  }

  private function joins_fragment($parts) {
    return isset($parts['joins']) ? ' ' . implode(', ', $parts['joins'] ) : '';
  }

  private function where_fragment($parts, &$bind_params) {

    if(empty($parts['where']))
      return '';

    $conditions = array();
    foreach($parts['where'] as $i => $where) {
      switch(true) {

        # array('admin' => true)
        case is_assoc($where):
          $condition = array();
          foreach($where as $col_name => $col_value)
            $condition[] = "$col_name = ?";
          $condition = implode(' AND ', $condition);
          $bind_params = array_merge($bind_params, array_values($where));
          break;

        # array('admin = ?', true)
        case is_array($where):
          $condition = $where[0];
          for($i = 1; $i < count($where); ++$i)
            $bind_params[] = $where[$i];
          break;

        # 'admin = 1'
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

  protected function row_cache_key($sql_parts) {
    return serialize($sql_parts);
  }

  protected function row_cache_hit($sql_parts) {
    $cache_key = $this->row_cache_key($sql_parts);
    if(isset($this->row_cache[$cache_key]))
      return $this->row_cache[$cache_key];
    return false;
  }

  protected function cache_rows($sql_parts, $rows) {
    $this->row_cache[$this->row_cache_key($sql_parts)] = $rows;
  }

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

    $scope_terminators = array(

      # singular terminators, operates on at most 1 db record, 
      # excepts or requires an id
      'first',
      'get',
      'delete',
      'destroy',

      # plural terminators, operates on a collection of db records
      'all',
      'delete_all',
      'destroy_all',
      'each',
      'batch',

      # pagination terminators, operates on a collection of db records,
      # accepts special arguments
      'paginate',
      'count',

    );

    if(in_array($scope, $scope_terminators))
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
      array_unshift($args, $this);
      call_user_func_array(array($class, $static_method), $args);
      return $this;
    }

    # sorting (ascend or decend by a single column)
    if(preg_match('/(asc|ascend|desc|descend)_by_(.+)/', $method, $matches)) {
      $dir = $matches[1] == 'asc' || $matches[1] == 'ascend' ? 'ASC' : 'DESC';
      $this->order("{$matches[2]} $dir");
      return $this;
    }

    # auto-magical scopes id_is, age_lte, etc
    $columns = array_keys($this->table->columns);
    $regex = '/^(' . implode('|', $columns) . ')_(.+)$/';
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
        case 'is_exactly':
          $this->where("$col = BINARY ?", $args[0]);
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

  public function first($cache = true) {
    $scope = clone $this;
    $results = $scope->limit(1)->all($cache);
    return empty($results) ? null : $results[0];
  }

  public function get($cache = true) {
    $record = $this->first($cache);
    if(is_null($record))
      throw new RecordNotFoundException($this);
    return $record;
  }

  public function delete() {
    $scope = clone $this;
    return $scope->limit(1)->delete_all();
  }

  public function destroy() {
    $obj = $this->get();
    $obj->destroy();
    return $obj;
  }

  public function all($cache = true) {
    $class = $this->table->class;
    $objects = array();
    foreach($this->table->select($this->sql_parts, $cache) as $row)
      $objects[] = $class::hydrate($row);
    return $objects;
  }

  public function delete_all() {
    return $this->table->delete($this->sql_parts);
  }

  public function destory_all() {
    $this->each(function($obj) {
      $obj->destroy();
    });
  }

  public function each($callback) {
    $this->batch(function($batch) {
      foreach($batch as $obj)
        $callback($obj);
    });
  }

  public function batch($callback, $batch_size = 1000) {
    $scope = clone $this;
    $scope->limit($batch_size);
    $scope->order('id ASC');
    do {
      $batch = $scope->all(false);
      if(empty($batch))
        return; # no matching records
      $callback($batch);
      $scope->where('id > ?', $batch[count($batch) - 1]->id);
    } while(count($batch) == $batch_size);
  }

  public function paginate($page = null, $per_page = null) {

    if(is_null($page))
      $page = 1;

    if(is_null($per_page))
      $per_page = Collection::$default_per_page;

    $limit = $per_page;
    $offset = ($page - 1) * $per_page;

    $scope = clone $this;
    $objects = $scope->limit($limit)->offset($offset)->all(false);

    $count = count($objects);
    if($count == 0)
      $total = $offset == 0 ? 0 : $this->count(false);
    else if($count < $limit)
      $total = count($objects) + $offset;
    else
      $total = $this->count(false);

    return new Collection($objects, $page, $per_page, $total);

  }

  public function count($cache = true) {
    $scope = clone $this;
    $scope->select('COUNT(*) AS count');
    $rows = $this->table->select($scope->sql_parts, $cache);
    return $rows[0]['count'];
  }

}

/**
 * @package Sculpt
 */
class Collection implements \Iterator, \Countable, \ArrayAccess {

  public static $default_per_page = 10;

  protected $paging_info = array();

  private $objects;

  private $position = 0;

  public function __construct($objects, $page, $per_page, $total) {

    $this->objects = $objects;

    $this->paging_info['page']      = $page;
    $this->paging_info['per_page']  = $per_page;
    $this->paging_info['total']     = $total;
    $this->paging_info['pages']     = ceil((float) $total / $per_page);
    $this->paging_info['prev_page'] = $page > 1 ? $page - 1 : null;
    $this->paging_info['next_page'] = $page < $this->pages ? $page + 1 : null;

  }

  public function __get($key) {
    if(array_key_exists($key, $this->paging_info))
      return $this->paging_info[$key];
    throw new Exception("undefined attribute `$key` for " . __CLASS__);
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

  public function is_empty() {
    return $this->total == 0;
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
  private $changes = array();

  public function __construct($attributes = null, $protect_attrs = true) {

    $this->class = get_called_class();
    $this->table = Table::get($this->class);
    $this->errors = new Errors();

    # set default values for this object based on column defaults
    foreach($this->table->columns as $attr_name => $column)
      $this->attr[$attr_name] = $column->default_value();

    if(!is_null($attributes))
      $this->set_attributes($attributes, $protect_attrs);
  }

  public function get_class() {
    return $this->class;
  }

  public static function hydrate($attributes) {
    $class = get_called_class();
    $obj = new $class();
    $obj->_bulk_assign($attributes);
    $obj->_clear_changes();
    return $obj;
  }

  public function is_new_record() {
    return is_null($this->attr['id']);
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

  public function __call($method, $args) {
    $columns = array_keys($this->table->columns);
    $regex = '/(' . implode('|', $columns) . ')_(.+)/';
    if(preg_match($regex, $method, $matches)) {
      $attr = $matches[1];
      switch($matches[2]) {
        case 'was':
        case 'change':
        case 'is_changed':
          $method = "attr_{$matches[2]}";
          return $this->$method($attr);
      }
    }
    throw new Exception("call to undefined method {$this->class}::$method()");
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
    $this->ensure_attr_defined($name);
    $value = isset($this->attr[$name]) ? $this->attr[$name] : null;
    if(isset($this->table->columns[$name]) && $type_cast) {
      $value = $this->table->columns[$name]->cast($value);
    }
    return $value;
  }

  protected function _set($name, $value) { 
    $this->ensure_attr_defined($name);

    # dirty tracking -- track changes on db column attributes
    $track = false;
    if(isset($this->table->columns[$name])) {
      $track = true;
      $was = isset($this->changes[$name]) ? 
        $this->changes[$name][0] : 
        $this->attribute($name);
    }

    $this->attr[$name] = $value;

    if($track) {
      $is = $this->attribute($name);
      if($was !== $is)
        $this->changes[$name] = array($was, $is);    
    }
  }
  
  protected function ensure_attr_defined($name) {
    if(!isset($this->table->columns[$name]) && 
       !in_array($name, static::$attr_accessors)) 
    {
      throw new UndefinedAttributeException($this->class, $name);
    }
  }

  public function attributes() {
    $attributes = array();
    foreach(array_keys($this->attr) as $attr_name)
      $attributes[$attr_name] = $this->attribute($attr_name);
    return $attributes;
  }

  public function set_attributes($attributes, $protect_attrs = true) {

    # nullify bulk assigned empty strings
    foreach($attributes as $attr_name => $attr_value)
      if($attr_value === '')
        $attributes[$attr_name] = null;

    $unprotected = (
      empty(static::$attr_whitelist) && 
      empty(static::$attr_blacklist) ||
      !$protect_attrs
    );

    if($unprotected)
      $this->_bulk_assign($attributes);
    else if(!empty(static::$attr_whitelist))
      $this->_bulk_assign_whitelisted($attributes);
    else
      $this->_bulk_assign_blacklisted($attributes);
  }

  private function _bulk_assign($attributes) {
    foreach($attributes as $name => $value)
      $this->set_attribute($name, $value);
  }

  private function _bulk_assign_whitelisted($attributes) {
    foreach($attributes as $name => $value)
      if(in_array($name, static::$attr_whitelist))
        $this->set_attribute($name, $value);
  }

  private function _bulk_assign_blacklisted($attributes) {
    foreach($attributes as $name => $value)
      if(!in_array($name, static::$attr_blacklist))
        $this->set_attribute($name, $value);
  }

  private function _clear_changes() {
    $this->changes = array();
  }

  public function is_changed() {
    $changed = $this->changed();
    return !empty($changed);
  }

  public function is_unchanged() {
    return !$this->is_changed();
  }

  public function changed() {
    return array_keys($this->changes);
  }

  public function changes() {
    return $this->changes;
  }

  public function attr_change($attr) {
    return isset($this->changes[$attr]) ? $this->changes[$attr] : null;
  }

  public function attr_is_changed($attr) {
    return isset($this->changes[$attr]);
  }

  public function attr_was($attr) {
    return isset($this->changes[$attr]) ?
      $this->changes[$attr][0] : 
      $this->$attr;
  }

  public function update_attributes($attributes, $protect_attrs = true) {
    $this->set_attributes($attributes, $protect_attrs);
    return $this->save();
  }

  public function save() {
    return $this->is_new_record() ? $this->_create() : $this->_update();
  }

  public function savex() {
    if(!$this->save())
      throw new RecordInvalidException($this);
  }

  public function create() {
    if(!$this->is_new_record())
      throw new Exception('create called on an existing record');
    return $this->_create();
  }

  public function delete() {
    throw new Exception('write this');
  }

  public function destroy() {
    if($this->is_new_record())
      throw new Exception('destroy called on a new record');
    $this->before_destroy();
    self::scope()->delete($this->id);
    $this->after_destroy();
  }

  public function to_param() {
    if($this->is_new_record())
      throw new Exception('to_param() called on a new record');
    return (string) $this->_get('id');
  }

  private function _validate($on_create) {
    $this->errors->clear();
    $this->before_validate();
    if($on_create) {
      $this->before_validate_on_create();
      $this->validate();
      $this->after_validate_on_create();
    } else {
      $this->validate();
    }
    $this->after_validate();
    return $this->errors->is_empty();
  }

  private function _update() {
    if($this->_validate(false)) {
      $this->before_save();
      $this->before_update();
      $this->table->update($this);
      $this->after_update();
      $this->after_save();
      $this->_clear_changes();
      return true;
    } else {
      return false;
    }
  }

  private function _create() {
    if($this->_validate(true)) {
      $this->before_save();
      $this->before_create();
      $this->table->insert($this);
      $this->after_create();
      $this->after_save();
      $this->_clear_changes();
      return true;
    } else {
      return false;
    }
  }

  public function validate() {}

  protected function before_validate() {}
  protected function after_validate() {}
  protected function before_validate_on_create() {}
  protected function after_validate_on_create() {}
  protected function before_save() {}
  protected function after_save() {}
  protected function before_update() {}
  protected function after_update() {}
  protected function before_create() {}
  protected function after_create() {}
  protected function before_destroy() {}
  protected function after_destroy() {}

  ## validation methods

  public function is_valid() {
    return $this->_validate($this->is_new_record());  
  }

  protected function validate_acceptance_of() {
    $this->validate_each(func_get_args(), function($obj, $attr, $opts) {
      $val = $this->attribute($attr);
      if($val !== true) {
        $msg = 'must be accepted';
        $obj->errors->add($attr, $msg);
      }
    });
  }

  protected function validate_as_boolean() {
    $this->validate_each(func_get_args(), function($obj, $attr, $opts) {
      $val = $obj->attribute_before_type_cast($attr);
      if($val !== 1 && 
         $val !== 0 && 
         $val !== '1' && 
         $val !== '0' && 
         $val !== true && 
         $val !== false)
      {
        $msg = 'must be a boolean';
        $obj->errors->add($attr, $msg);
      }
    });
  }

  protected function validate_as_date() {
    $this->validate_each(func_get_args(), function($obj, $attr, $opts) {
      throw new Exception('not implmented yet');
    });
  }

  protected function validate_as_datetime() {
    $this->validate_each(func_get_args(), function($obj, $attr, $opts) {
      throw new Exception('not implmented yet');
    });
  }

  protected function validate_as_id() {
    $this->validate_each(func_get_args(), function($obj, $attr, $opts) {
      throw new Exception('not implmented yet');
    });
  }

  protected function validate_as_uuid() {
    $this->validate_each(func_get_args(), function($obj, $attr, $opts) {
      $value = $obj->attribute($attr);
      $hex = '[0-9a-f]';
      $regex = "/^$hex{8}-$hex{4}-$hex{4}-$hex{4}-$hex{12}$/";
      if(!preg_match($regex, $value)) {
        $msg = 'is not a valid UUID';
        $obj->errors->add($attr, $msg);
      }
    });
  }

  protected function validate_confirmation_of() {
    $this->validate_each(func_get_args(), function($obj, $attr, $opts) {
      $other_attr = "{$attr}_confirmation";
      if($obj->attribute($attr) !== $obj->attribute("{$attr}_confirmation")) {
        $msg = 'doesn\'t match confirmation';
        $obj->errors->add($attr, $msg);
      }
    });
  }

  protected function validate_exclusion_of() {
    $this->validate_each(func_get_args(), function($obj, $attr, $opts) {
      throw new Exception('not implmented yet');
    });
  }

  protected function validate_inclusion_of() {
    $this->validate_each(func_get_args(), function($obj, $attr, $opts) {
      throw new Exception('not implmented yet');
    });
  }

  protected function validate_format_of() {
    $this->validate_each(func_get_args(), function($obj, $attr, $opts) {

      if(!isset($opts['regex']))
        throw new Exception("missing regex option for $attr");

      $val = (string) $obj->attribute_before_type_cast($attr);
      if(!preg_match($opts['regex'], $val)) {
        $msg = 'is invalid';
        $obj->errors->add($attr, $msg);
      }

    });
  }

  protected function validate_length_of() {
    $this->validate_each(func_get_args(), function($obj, $attr, $opts) {

      $val = (string) $obj->attribute($attr);
      $length = strlen($val);

      if(isset($opts['is'])) {
        $is = $opts['is'];
        if($length != $is) {
          $msg = "is the wrong length (should be $is characters)";
          $obj->errors->add($attr, $msg);
        }
      }

      if(isset($opts['maximum'])) {
        $max = $opts['maximum'];
        if($length > $max) {
          $msg = "is too long (maximum is $max)";
          $obj->errors->add($attr, $msg);
        }
      }

      if(isset($opts['minimum'])) {
        $min = $opts['minimum'];
        if($length < $min) {
          $msg = "is too short (minimum is $min)";
          $obj->errors->add($attr, $msg);
        }
      }

    });
  }

  protected function validate_numericality_of() {
    $this->validate_each(func_get_args(), function($obj, $attr, $opts) {
      throw new Exception('not implmented yet');
    });
  }

  protected function validate_presence_of() {
    $this->validate_each(func_get_args(), function($obj, $attr, $opts) {
      $val = $obj->attribute_before_type_cast($attr);
      if(is_null($val) || $val === '') {
        $msg = 'may not be blank';
        $obj->errors->add($attr, $msg);
      }
    });
  }

  protected function validate_size_of() {
    $this->validate_each(func_get_args(), function($obj, $attr, $opts) {
      throw new Exception('not implmented yet');
    });
  }

  protected function validate_uniqueness_of() {
    $this->validate_each(func_get_args(), function($obj, $attr, $opts) {

      $class = $obj->get_class();
      $scope = $class::scope();

      if(isset($opts['case_sensitive']) && $opts['case_sensitive'])
        $method = "{$attr}_is_exactly";
      else
        $method = "{$attr}_is";

      $scope->$method($obj->attribute($attr));

      if(!$obj->is_new_record())
        $scope->id_is_not($obj->id);

      if($scope->first())
        $obj->errors->add($attr, 'is already taken');  

    });
  }

  protected function validate_each($attributes, $callback) {

    $opts = is_array($attributes[count($attributes) - 1]) ? 
      array_pop($attributes) : 
      array();
    
    foreach($attributes as $attribute)
      if(!$this->validation_should_be_skipped($attribute, $opts))
        $callback($this, $attribute, $opts);

  }

  private function validation_should_be_skipped($attr, $validation_opts) {

    $opts = &$validation_opts;

    # skip this validation if it allows_null and has a null value
    if(isset($opts['allow_null']) && $opts['allow_null'] &&
      is_null($this->attribute_before_type_cast($attr)))
    {
      return true;
    }

    if(isset($opts['if']) && $this->$opts['if']() == false)
      return true;

    if(isset($opts['unless']) && $this->$opts['unless']() == true)
      return true;

    if(isset($opts['on'])) {
      $on = $opts['on'];
      switch($on) {
        case 'save':
          return false;
        case 'create':
          return !$this->is_new_record();
        case 'update':
          return $this->is_new_record();
        default:
          throw new Exception("invalid on condition for validation: $on");
      }
    }

    return false;
  }

  # defaults:
  #
  # on => (save/create/update)
  # if => null
  # unless => null
  # message => (varies by validation)
  # allow_null => (false/true)

  ## scoped finder methods

  public static function __callStatic($method, $args) {
    $scope = static::scope();
    call_user_func_array(array($scope, $method), $args);
    return $scope;
  }

  public static function scope() {
    return new Scope(Table::get(get_called_class()));
  }

  public static function first($cache = true) {
    return static::scope()->first($cache);
  }

  public static function get($id) {
    return static::scope()->id_is($id)->get();
  }

  public static function all($cache = true) {
    return static::scope()->all($cache);
  }

  public static function delete_all() {
    return static::scope()->delete_all();
  }

  public static function destroy_all() {
    return static::scope()->destroy_all();
  }

  public static function each($callback) {
    return static::scope()->each($callback);
  }

  public static function batch($callback, $batch_size = 1000) {
    return static::scope()->batch($callback, $batch_size);
  }

  public static function paginate($page = null, $per_page = null) {
    return static::scope()->paginate($page, $per_page);
  }

  public static function count($cache = true) {
    return static::scope()->count($cache);
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

  public function has_errors_on($attr) {
    return isset($this->msgs[$attr]);
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
    $this->each_message(function($message) use (&$messages) {
      array_push($messages, $message);
    });
    return $messages;
  }

  public function each($callback) {
    foreach($this->msgs as $on => $messages)
      foreach($messages as $message)
        $callback($on, $message);
  }

  public function each_message($callback) {
    foreach($this->msgs as $on => $messages)
      foreach($messages as $message)
        $callback($this->expand_message($on, $message));
  }

  protected function expand_message($on, $msg) {
    return trim("$on $msg");
  }

  public function clear() {
    $this->msgs = array();
  }

  public function __toString() {
    return implode(', ', $this->full_messages());
  }

}
