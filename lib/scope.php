<?php

namespace Sculpt;

/**
 * @package Sculpt
 */
class Scope {

  protected $table;

  protected $sql_parts = array(
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

  public function __construct($table, $scope_opts = null) {
    $this->table = $table;
    if($scope_opts)
      $this->apply_scope_opts($scope_opts);
  }

  public function select($select_sql_fragment) {
    $this->sql_parts['select'] = $select_sql_fragment;
    return $this;
  }

  public function from($table_name) {
    $this->sql_parts['from'] = $table_name;
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
    $this->apply_scope_opts($opts);
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
      $this->apply_scope_opts($class::$scopes[$method]);
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
      $col = "`{$this->table->name}`.{$matches[1]}";
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

  protected function apply_scope_opts($opts) {
    foreach($opts as $key => $value)
      if(is_numeric($key))    # if the key is numeric we will assume is another
        $this->$value;        # (named) scope to apply
      else
        $this->$key($value);  # otherwise its a standard scope
  }

/*
  # gets the first record and returns it id
  public function id() {
    $scope = clone $this;
    $scope->select('id');
    return $scope->get()->id;;
  }
*/

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

  public function delete($id = null) {
    $scope = clone $this;
    if($id)
      $scope->id_is($id)->limit(1);
    return $this->table->delete($scope->sql_parts);
  }

  public function destroy($id = null) {

    # destroy a single record by id
    if($id) {
      $obj = $this->id_is($id)->get();
      $obj->destroy();
      return $obj;
    }

    # or call destroy on an entire collection
    $this->each(function($obj) {
      $obj->destroy();
    });

  }

/*
  public function ids() {
    $scope = clone $this;
    $scope->select('id');
    $ids = array();
    $scope->each(function($record) use (&$ids) {
      $ids[] = $record->id;
    });
    return $ids;
  }
*/

  public function all($cache = true) {
    $class = $this->table->class;
    $objects = array();
    foreach($this->table->select($this->sql_parts, $cache) as $row)
      $objects[] = $class::hydrate($row);
    return $objects;
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
