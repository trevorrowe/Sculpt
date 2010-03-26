<?php

namespace Sculpt;

use PDO;

/**
 * @package Sculpt
 */
class MySQLConnection extends Connection {

  public function quote_name($name) {
    return "`$name`";
  }

  public function tables() {
    $tables = array();
    $sth = $this->execute('SHOW TABLES');
    while($row = $sth->fetch(PDO::FETCH_NUM))
      $tables[] = $row[0];
    return $tables;
  }

  public function columns($table) {
    $columns = array();
    $sth = $this->execute("SHOW COLUMNS FROM $table");
    while($row = $sth->fetch()) {
      $column = new MySQLColumn($row);
      $columns[$column->name()] = $column;
    }
    return $columns;
  }

}
