<?php

namespace Sculpt;

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
