<?php

namespace Sculpt;

class Association extends Scope {

  protected $owner;

  protected $name;

  protected $config;

  protected $singular;

  protected $class;

  public function __construct($owner, $name, $config, $singular) {

    $this->owner = $owner;
    $this->name = $name;
    $this->config = $config;
    $this->singular = $singular;

    $this->class = isset($config['class']) ? 
      $config['class'] : 
      classify($name);

    $table = Table::get($this->class);
    parent::__construct($table);

  }

  public function is_singular() {
    return $this->singular;
  }

}
