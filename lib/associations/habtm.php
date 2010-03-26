<?php

namespace Sculpt;

use PDO;

class HasAndBelongsToManyAssociation extends Association {

  protected $join_table;

  protected $owner_col;
  protected $owner_key;

  protected $target_col;
  protected $target_key;

  public function __construct($owner, $name, $config) {

    parent::__construct($owner, $name, $config, false);

    # TODO : rename the config options and make them all setable

    if(isset($config['join_table']))
      $join_table = $config['join_table'];
    else
      throw new Exception('oops, need to add logic for guessing join_table');
    $this->join_table = $join_table;

    if(isset($config['owner_col']) )
      $owner_col = $config['owner_col'];
    else
      $owner_col = 'id';
    $this->owner_col = $owner_col;

    if(isset($config['owner_key']))
      $owner_key = $config['owner_key'];
    else
      $owner_key = underscore($owner->get_class()) . '_id';
    $this->owner_key = $owner_key;

    $target_class = $this->class;
    $target_table = $target_class::tablename();

    if(isset($config['target_col']) )
      $target_col = $config['target_col'];
    else
      $target_col = 'id';
    $this->target_col = $target_col;

    if(isset($config['target_key']))
      $target_key = $config['target_key'];
    else
      $target_key = underscore($target_class) . '_id';
    $this->target_key = $target_key;

    $this->joins("INNER JOIN $join_table ON $join_table.$target_key = $target_table.$target_col");
    $this->where("$join_table.$owner_key = ?", $owner->$owner_col);
      
  }

  # TODO : flatten func_get_args so an array can be passed as a single arg
  public function add() {

    $values = array();
    $placeholders = array();
    foreach(flatten_args(func_get_args()) as $arg) {
      $placeholders[] = '(?, ?)';
      $arg_vals = $this->bind_values($arg);
      $values[] = $arg_vals[0];
      $values[] = $arg_vals[1];
    }
    $placeholders = implode(', ', $placeholders);
    
    $sql = "INSERT INTO {$this->join_table} ({$this->owner_key}, {$this->target_key}) VALUES $placeholders";

    $this->connection()->execute($sql, $values);

  }

  public function remove() {

    $args = flatten_args(func_get_args());
    $argc = count($args);

    $values = array($this->owner->attribute($this->owner_key));

    if($argc > 1) {
      $placeholders = ltrim(str_repeat(', ?', $argc), ', ');
      $where = "IN ($placeholders)";
      foreach($args as $arg)
        $values[] = $this->target_key($arg);
    } else {
      $where = '= ?'; 
      $values[] = $this->target_key($args[0]);
    }

    $sql = "DELETE FROM {$this->join_table} WHERE $this->owner_key = ? AND $this->target_key $where";

    $this->connection()->execute($sql, $values);
  }

  public function set($objects) {
    $this->clear();
    foreach($objects as $obj)
      $this->add($obj);
  }

  public function ids() {
    # TODO : implment in base scope class?
    # TODO : provide a shortcut version for basic scopes
    throw new Exception('not implmeneted');
  }

  public function clear() {
    if($this->is_basic()) {
      $sql = "DELETE FROM {$this->join_table} WHERE {$this->owner_key} = ?";
      $value = $this->owner->attribute($this->owner_col);
      $sth = $this->connection()->execute($sql, array($value));
      return $sth->rowCount();
    }
    $scope = clone $this;
    $scope->from($this->join_table);
    return $scope->delete_all();
  }

  public function is_empty() {
    return $this->count() == 0;
  }

  # TODO : think about this methods implmentation, what is currently does...
  # if the scope is basic (no additional where conditions or joins) then
  # a simplified count that requires no joins is used, otherwise the full
  # scope join is used
  public function count($cache = true) {
    if($this->is_basic()) {
      $sql = "SELECT COUNT(*) AS count FROM {$this->join_table} WHERE {$this->owner_key} = ?";
      $value = $this->owner->attribute($this->owner_col);
      $sth = $this->connection()->execute($sql, array($value));
      $row = $sth->fetch(PDO::FETCH_NUM);
      return $row[0];
    }
    return parent::count($cache);
  }

  # TODO : this method should probably act like count where a simplifed
  # query is only used if the conditions and joins are basic
  public function contains($obj_or_key) {
    $sql = "SELECT * FROM {$this->join_table} WHERE {$this->owner_key} = ? AND {$this->target_key} = ? LIMIT 1";
    $values = 
    $sth = $this->connection()->execute($sql, $this->bind_values($obj_or_key));
    return $sth->rowCount() > 0;
  }

  protected function is_basic() {
    $p = $this->sql_parts;
    return (count($p['where']) == 1 && count($p['joins']) == 1);
  }

  protected function target_key($obj_or_key) {
    $target_col = $this->target_col;
    return is_object($obj_or_key) ? 
      $obj_or_key->$target_col :
      $obj_or_key;
  }

  protected function bind_values($obj_or_key) {
    $values = array();
    $values[] = $this->owner->attribute($this->owner_col);
    $values[] = $this->target_key($obj_or_key);
    return $values;
  }

  protected function connection() {
    return Table::get($this->class)->connection;
  }

}
