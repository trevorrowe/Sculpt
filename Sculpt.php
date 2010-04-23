<?php

/**
 * Sculpt
 *
 * A PHP Object Relationship Mapper (ORM).
 *
 * Copyright (c) 2009 Trevor Rowe
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to 
 * deal in the Software without restriction, including without limitation the 
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or 
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING 
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER 
 * DEALINGS IN THE SOFTWARE.
 *
 * @author Trevor Rowe
 * @package Sculpt
 * @version 0.1
 *
 */

namespace Sculpt;

$lib_dir = realpath(dirname(__FILE__)) . '/lib';

require "$lib_dir/exceptions.php";
require "$lib_dir/connection.php";
require "$lib_dir/column.php";
require "$lib_dir/adapters/mysql/connection.php";
require "$lib_dir/adapters/mysql/column.php";
require "$lib_dir/logger.php";
require "$lib_dir/table.php";
require "$lib_dir/model.php";
require "$lib_dir/errors.php";
require "$lib_dir/collection.php";
require "$lib_dir/scope.php";
require "$lib_dir/association.php";
require "$lib_dir/associations/belongs_to.php";
require "$lib_dir/associations/has_one.php";
require "$lib_dir/associations/has_many.php";
require "$lib_dir/associations/habtm.php";

/**
 * Returns true if passed argument is an associative array (e.g. a hash).
 *
 * @param array $var the varaiable to test
 * @return boolean 
 */
function is_assoc($var) {
  return is_array($var) && array_diff_key($var, array_keys(array_keys($var)));
}

/**
 * Returns arguments as an array.  To use this method call it like:
 * 
 *   function some_function() {
 *     $args = flatten_args(func_get_args());
 *   }
 *
 * A few examples outputs:
 *
 *   some_func(1, 2, 3, 4)
 *   #=> array(1, 2, 3, 4)
 *
 *   some_func(array(1, 2, 3, 4))
 *   #=> array(1, 2, 3, 4)
 *
 *   some_func(array(1, 2), 3, 4)
 *   #=> array(array(1, 2), 3, 4)
 *
 *  some_func()
 *  #=> array()
 *
 * @param array $func_get_args the result of a call to func_get_args()
 * @return array
 *
 */
function flatten_args($func_get_args) {
  if(count($func_get_args) == 1 && is_array($func_get_args[0]))
    return $func_get_args[0];
  else
    return $func_get_args;
}

function collect($array, $callback) {
  $results = array();
  foreach($array as $array_index => $array_element)
    $results[] = $callback($array_element, $array_index);
  return $results;
}
