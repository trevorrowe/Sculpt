<?php

class User extends \Sculpt\Model {

  static $secret = '9c3ea1e36407b1f3fab4c43e5b4277ff87f62e6a35b2a72';

  static $attr_accessors = array('password', 'password_confirmation');

  static $attr_whitelist = array('email', 'password', 'password_confirmation');

  ##
  ## validations
  ##

  static $validates_presence_of = array(
    array('username'),
    array('email'),
    array('admin'),
    array('uuid'),
    array('password_salt'),
    array('password_hash'),
  );

  static $validates_format_of = array(
    array('username', 
      'regex' => '/^[a-zA-Z0-9]{2,30}$/',
      'message' => 'may only contain letters and numbers',
    ),
  );

  static $validates_uniqueness_of = array(
    array('username', 'if' => 'is_new_record'),
  );

  static $validates_length_of = array(
    array('username', 'within' => array(2,32)),
    array('password_salt', 'is' => 32),
    array('password_hash', 'is' => 64),
  );

  static $validates_as_boolean = array(
    array('admin'),
  );

  static $validates_confirmation_of = array(
    array('password'),
  );

  static $validate = array(
    'validate_email',
  );

  ##
  ## associations
  ##

  static $has_one = array(
    array('profile'),
  );

  static $has_many = array(
    array('photos'),
    array('vidoes'),
    array('albums'),
    array('photo_comments'),
    array('video_comments'),
    array('album_comments'),
    array('logins'),
    array('login_cookies'),
  );

  static $has_and_belongs_to_many = array(
    array('favorite_photos'),
    array('favorite_videos'),
    array('favorite_albums'),
    array('favorite_users'),
  );

  ##
  ## scopes
  ##

  static $scopes = array(
    'admin' => array('admin' => true),
    'activated' => array('where' => 'activated_at IS NOT NULL'),
  );

  public static function other_scope($scope) {
    $scope->admin->activated->order('username ASC');
  }

  ##
  ## setters & getters
  ##

  protected function _set_password($password) {
    $salt = '';
    for($i = 0; $i < 24; ++$i)
      $salt .= chr(rand(33,126));
    $this->password_salt = $salt;
    $this->password_hash = hash('sha256', $password . $salt . self::$secret);
    $this->_set('password', $password);
  }

  protected function _get_password() {
    return null;
  }

  protected function _get_password_confirmation() {
    return null;
  }

}
