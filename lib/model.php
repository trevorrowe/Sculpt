<?php

namespace Sculpt;

/**
 * @package Sculpt
 */
abstract class Model implements \ArrayAccess {

  private $_class;
  private $_attributes = array();
  private $_changes = array();
  private $_errors;

  # klass configuration

  static $connection = null;
  static $table_name = null;

  static $attr_accessors = array();
  static $attr_whitelist = array();
  static $attr_blacklist = array();

  static $scopes = array();
  static $associations = array();

  public function __construct($attributes = null, $protect_attrs = true) {

    $this->_class = get_called_class();
    $this->_errors = new Errors();

    # set default values for this object based on column defaults
    foreach(static::columns() as $attr_name => $column)
      $this->_attributes[$attr_name] = $column->default_value();

    if(!is_null($attributes))
      $this->set_attributes($attributes, $protect_attrs);

    $this->init();
  }

  public function init() {}

  public function get_class() {
    return $this->_class;
  }

  public static function hydrate($attributes) {
    $class = get_called_class();
    $obj = new $class();
    $obj->_bulk_assign($attributes);
    $obj->_clear_changes();
    return $obj;
  }

  public function is_new_record() {
    return is_null($this->_attributes['id']);
  }

  public function __get($attr_name) {

    if($this->is_an_association($attr_name))
      return $this->association($attr_name);

    if($attr_name == 'errors')
      return $this->_errors;

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
    $columns = array_keys(static::columns());
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

    throw new Exception("call to undefined method {$this->_class}::$method()");

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
    $columns = static::columns();
    $value = isset($this->_attributes[$name]) ? $this->_attributes[$name] : null;
    if(isset($columns[$name]) && $type_cast) {
      $value = $columns[$name]->cast($value);
    }
    return $value;
  }

  protected function _set($name, $value) { 

    $this->ensure_attr_defined($name);

    # dirty tracking
    $trackable_attr = false;
    $columns = static::columns();
    if(isset($columns[$name])) {
      # some attributes setable via _set are not persisted to the db,
      # we don't watch for dirty changes on these
      $trackable_attr = true;
      $was = isset($this->_changes[$name]) ? 
        $this->_changes[$name][0] : 
        $this->_attributes[$name];
    }

    $this->_attributes[$name] = $value;

    if($trackable_attr) {
      $is = $this->_attributes[$name];
      if($was != $is)
        $this->_changes[$name] = array($was, $is);    
    }
  }
  
  protected function ensure_attr_defined($name) {
    $columns = static::columns();
    if(!isset($columns[$name]) && !in_array($name, static::$attr_accessors))
      throw new UndefinedAttributeException($this->_class, $name);
  }

  public function attributes() {
    $attributes = array();
    foreach(array_keys($this->_attributes) as $attr_name)
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
    $this->_changes = array();
  }

  public function is_changed() {
    $changed = $this->changed();
    return !empty($changed);
  }

  public function is_unchanged() {
    return !$this->is_changed();
  }

  public function changed() {
    return array_keys($this->_changes);
  }

  public function changes() {
    return $this->_changes;
  }

  public function attr_change($attr) {
    return isset($this->_changes[$attr]) ? $this->_changes[$attr] : null;
  }

  public function attr_is_changed($attr) {
    return isset($this->_changes[$attr]);
  }

  public function attr_was($attr) {
    return isset($this->_changes[$attr]) ?
      $this->_changes[$attr][0] : 
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

  public static function build($attributes = null, $protect_attrs = true) {
    $class = get_called_class();
    $obj = new $class($attributes, $protect_attrs);
    return $obj;
  }

  public static function create($attributes = null, $protect_attrs = true) {
    $obj = static::build($attributes, $protect_attrs);
    $obj->save();
    return $obj;
  }

  public static function createx($attributes = null, $protect_attrs = true) {
    $obj = static::build($attributes, $protect_attrs);
    $obj->savex();
    return $obj;
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
    $this->_errors->clear();
    $this->before_validate();
    if($on_create) {
      $this->before_validate_on_create();
      $this->validate();
      $this->after_validate_on_create();
    } else {
      $this->validate();
    }
    $this->after_validate();
    return $this->_errors->is_empty();
  }

  private function _update() {
    if($this->_validate(false)) {
      $this->before_save();
      $this->before_update();
      static::table()->update($this);
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
      static::table()->insert($this);
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
    $class = $this->_class;
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
    $class = get_called_class();
    return isset($class::$table_name) ? $class::$table_name : tableize($class);
  }

  public static function columns() {
    $table = static::table();
    return $table->columns;
  }

  public static function table() {
    return Table::get(get_called_class());
  }

}
