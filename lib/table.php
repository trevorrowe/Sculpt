<?php

namespace Sculpt;

/**
 * @package Sculpt
 */
class Table {

  # columns
  # associations
  # validations
  # 

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
    $this->name = $class::table_name();
    $this->connection = Connection::get($class::$connection);
    $this->columns = $this->connection->columns($this->name);
  }

  public function insert($obj) {

    $columns = array();
    $bind_params = array(); 

    foreach($obj->changed() as $attr) {
      $columns[] = $attr;
      $value = $obj->attribute($attr);
      if(is_object($value) && get_class($value) == 'DateTime')
        $value = strftime('%Y-%m-%d %T', $value->getTimestamp());
      $bind_params[] = $value;
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
    $sth = $this->connection->execute($sql, $bind_params);
    $obj->id = $this->connection->last_insert_id();
  }

  public function update($obj) {

    if($obj->is_unchanged()) return;

    $set = array();
    $bind_params = array();
    foreach($obj->changed() as $attr) {
      $set[] = "$attr = ?";
      $value = $obj->attribute($attr);
      if(is_object($value) && get_class($value) == 'DateTime')
        $value = strftime('%Y-%m-%d %T', $value->getTimestamp());
      $bind_params[] = $value;
    }

    if(isset($this->columns['updated_at'])) {
      $set[] = 'updated_at = ?';
      $bind_params[] = strftime('%Y-%m-%d %T');
    }

    $bind_params[] = $obj->id;
    $set = implode(', ', $set);
    $sql = "UPDATE $this->name SET $set WHERE id = ?";
    $sth = $this->connection->execute($sql, $bind_params);
  }

  # TODO : update the destory method
  public function delete($sql_parts = array()) {
    $bind_params = array();
    $sql = $this->delete_fragment($sql_parts);
    $sql .= $this->from_fragment($sql_parts);
    $sql .= $this->joins_fragment($sql_parts);
    $sql .= $this->where_fragment($sql_parts, $bind_params);
    $sth = $this->connection->execute($sql, $bind_params);
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

    $sth = $this->connection->execute($sql, $bind_params);
    $rows = $sth->fetchAll();

    if($cache)
      $this->cache_rows($sql_parts, $rows);

    return $rows;

  }

  private function delete_fragment($parts) {
    if(empty($parts['joins']))
      return 'DELETE';
    $table = isset($parts['from']) ? $parts['from'] : $this->name;
    return "DELETE $table.*";
  }

  private function select_fragment($sql_parts) {
    if(isset($sql_parts['select']))
      $select = $sql_parts['select'];
    else
      $select = "{$this->name}.*";
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
            $condition[] = "`{$this->name}`.$col_name = ?";
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
