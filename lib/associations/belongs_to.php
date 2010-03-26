<?php

namespace Sculpt;

class BelongsToAssociation extends Association {

  public function __construct($owner, $name, $config) {

    parent::__construct($owner, $name, $config, true);

    $local_key = isset($config['local_key']) ? $config['local_key'] : 'id';

    $foreign_key = isset($config['foreign_key']) ?
      $config['foreign_key'] :
      "{$name}_id";

    $this->where(array($foreign_key => $owner->$local_key));

  }

}
