<?php

namespace Sculpt;

use PDO;

/**
 * @package Sculpt
 */
abstract class Connection {

  private static $default = null;

  private static $details = array();

  private static $pool = array();

  protected $c;

  public static function add($name, $details) {
    if(isset(self::$details[$name]))
      throw new Exception("connection already defined: $name");
    if(!isset($details['adapter']))
      throw new Exception("missing required connection option adapter: $name");
    self::$details[$name] = $details;
  }

  public static function load_ini_file($ini_path) {
    $ini = parse_ini_file($ini_path, true);
    foreach($ini as $name => $details)
      self::add($name, $details);
  }

  public static function set_default($name) {
    if(!isset(self::$details[$name]))
      throw new Exception("could not find a connection named: $name");
    self::$default = $name;
  }

  public static function get($name = null) {

    if(is_null($name)) {
      if(is_null(self::$default))
        throw new Exception('unable to connect: no default connection name');
      $name = self::$default;
    }

    if(!isset(self::$pool[$name])) {
      if(!isset(self::$details[$name]))
        throw new Exception("unable to connect, no connection named: $name");
      self::$pool[$name] = Connection::build(self::$details[$name]);
    }

    return self::$pool[$name];
      
  }

  public static function build($details) {
    switch($details['adapter']) {
      case 'mysql':
        return new MySQLConnection($details);
      default:
        throw new Exception("unknown connection adapter {$details['adapter']}");
    }
  }

  protected function __construct($details) {
    $dsn = $details['dsn'];
    $username = isset($details['username']) ? $details['username'] : null;
    $password = isset($details['password']) ? $details['password'] : null;
    $opts = array(
      PDO::ATTR_CASE => PDO::CASE_LOWER,
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
      PDO::ATTR_STRINGIFY_FETCHES  => false,
      PDO::ATTR_PERSISTENT => false,
    );
    $this->c = new PDO($dsn, $username, $password, $opts);
  } 

  public function execute($sql, $values = array()) {
    $start = microtime(true);
    try {

      $sth = $this->c->prepare($sql);
      $sth->setFetchMode(PDO::FETCH_ASSOC);
      $sth->execute($values);

      Logger::log($sql, microtime(true) - $start, $values);

      return $sth;

    } catch(\Exception $e) {
      Logger::log($sql, 'FAILED', $values);
      throw $e;
    }
  }

  public function last_insert_id($sequence = null) {
    return $this->c->lastInsertId($sequence);
  }
  
  abstract public function tables();

  abstract public function columns($table_name);

}
