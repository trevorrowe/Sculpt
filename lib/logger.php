<?php

namespace Sculpt;

/**
 * @package Sculpt
 */
class Logger {

  private static $logger;

  public static function set_logger($logger) {
    self::$logger = $logger;
  }

  public static function log($query, $time, $values) {
    if(self::$logger) {

      static $i = 0;
      static $colors = array("\x1b[36m","\x1b[35m");
      static $weights = array("\x1b[1m", '');

      $reset = "\x1b[0m";
      $bold = "\x1b[1m";
      $color = $colors[$i % 2];
      $weight = $weights[$i % 2];
      if(is_numeric($time))
        $time = self::format_seconds($time);

      $vals = '';
      if(!empty($values))
        $vals = ' [' . implode(', ', $values) . ']';

      $msg = "$color{$bold}[Sculpt] ($time)$reset$weight $query$reset$vals";
      self::$logger->log($msg);
      $i += 1;
    }
  }

  protected static function format_seconds($seconds) {
    $milli = round($seconds * 10000.0) / 10.0;
    switch(true) {
      case $milli < 1000: 
        return sprintf("%.1fms", $milli); 
      case $milli < (1000 * 60): 
        return sprintf("%.2f seconds", $milli / 1000); 
      default:
        $mins = floor(($milli / 1000) / 60);
        $seconds = ($milli - $mins * 1000 * 60) / 1000;
        return sprintf("%d mins %.2f seconds", $mins, $seconds);
    }
  }

}
