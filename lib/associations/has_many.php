<?php

namespace Sculpt;

class HasManyAssociation extends Association {

  protected $lk;

  protected $fk;

  public function __construct($owner, $name, $config) {

    parent::__construct($owner, $name, $config, false);

    $this->lk = isset($config['local_key']) ? $config['local_key'] : 'id';

    $this->fk = isset($config['foreign_key']) ?
      $config['foreign_key'] :
      underscore($owner->get_class()) . '_id';

    $assoc_attr = $this->lk;
    $this->where(array($this->fk => $owner->$assoc_attr));

  }

  function build($params = null) {
    $obj = new $this->class($params);
    $obj->set_attribute($this->fk, $this->owner->attribute($this->lk));
    return $obj;
  }

/*
  function create($params = null) {
    $obj = $this->build($params);
    $obj->save();
    return $obj;
  }

  function createx($params = null) {
    $obj = $this->create($params);
    if($obj->is_new_record())
      throw new Exception('');
    return $obj;
  }
*/

}
