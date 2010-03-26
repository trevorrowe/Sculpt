<?php

namespace Sculpt;

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
