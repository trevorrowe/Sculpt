<?php

namespace Sculpt;

/**
 * @package Sculpt
 */
abstract class Model implements \ArrayAccess {

  static $connection = null;

  static $attr_accessors = array();
  static $attr_whitelist = array();
  static $attr_blacklist = array();

  static $associations = array();

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

  public static function tablename() {
    $class = get_called_class();
    return isset($class::$table_name) ? $class::$table_name : tableize($class);
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
    if($this->is_an_association($attr_name))
      return $this->association($attr_name);
    return $this->attribute($attr_name);
  }

  public function __set($attr_name, $attr_value) {
    $this->set_attribute($attr_name, $attr_value);
  }

  public function __isset($attr_name) {
    return !is_null($this->attribute($attr_name));
  }

  public function __call($method, $args) {

    # check against the defined associations
    if($this->is_an_association($method))
      return $this->association($method);

    # check against the magic method patterns for dirty tracking
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

  ## 
  ## association methods 
  ## 

  public function is_an_association($name) {
    $class = $this->class;
    return isset($class::$associations[$name]);
  }

  public function association($name) {
    $associations = $this->associations();
    $config = $associations[$name];
    $assoc_class = "\\Sculpt\\" . classify($config['type'] . 'Association');
    $assoc = new $assoc_class($this, $name, $config);
    if($assoc->is_singular())
      return $assoc->first;
    else
      return $assoc;
  }

  public static function associations() {
    $class = get_called_class();
    if(isset($class::$associations))
      return $class::$associations;
    return array();
  }

  public static function table_name() {
    return Table::get(get_called_class())->name;
  }

  public static function columns() {
    return Table::get(get_called_class())->columns;
  }

}
