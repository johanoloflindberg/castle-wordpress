<?php
/*
Plugin Name: Userbin Authentication
Description: Add a secure and manageble authentication stack to your Wordpress stack
Version: 0.0.1
Author: Userbin
Author URI: https://userbin.com
License: GPL2

    Copyright 2013  Userbin.com

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License version 2,
    as published by the Free Software Foundation.

    You may NOT assume that you can use any other version of the GPL.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    The license for this software can likely be found here:
    http://www.gnu.org/licenses/gpl-2.0.html

*/

//function wp_new_user_notification($user_id, $plaintext_pass = '') {}

if(!class_exists('WP_Plugin_Userbin')) {
  class WP_Plugin_Userbin {
    /// Constants

    //// Plugin Version
    const VERSION = '0.1.0';

    //// Keys
    const SETTINGS_NAME = '_wp_plugin_userbin_settings';

    //// Slugs
    const SETTINGS_PAGE_SLUG = 'wp-plugin-userbin-settings';

    //// Defaults
    private static $default_settings = null;

    public static function init() {
      $appId = self::_get_settings('appId');
      $secret = self::_get_settings('apiSecret');
      if (!empty($appId) && !empty($secret)) {
        Userbin::set_app_id($appId);
        Userbin::set_api_secret($secret);
      }
      Userbin::authenticate();
      self::add_actions();
      self::add_filters();
      self::register_shortcodes();

      register_activation_hook(__FILE__, array(__CLASS__, 'do_activation_actions'));
      register_deactivation_hook(__FILE__, array(__CLASS__, 'do_deactivation_actions'));
    }

    public static function init_user() {
      if (Userbin::authenticated()) {
        self::log_in_from_userbin();
      } else if(self::activated()){
        wp_clear_auth_cookie();
      }
    }

    private static function log_in_from_userbin() {
      $profile = Userbin::current_profile();
      $user = null;
      if (Userbin::authenticated()) {
        $user = new WP_User($profile['local_id']);
      }
      if ($user instanceof WP_User) {
        $secure_cookie = is_ssl();
        $secure_cookie = apply_filters('secure_signon_cookie', $secure_cookie, $credentials);
        wp_set_auth_cookie($user->ID, true, $secure_cookie);
      }
    }

    public static function activate($appId, $apiSecret) {
      Userbin::set_app_id($appId);
      Userbin::set_api_secret($apiSecret);
      // Upload site URL
      $response = Userbin_Settings::update(array(
        'confirmation_required' => false,
        'site_url' => get_site_url(),
        'app_name' => bloginfo('name')
      ));
      if (!$response->successful()) {
        return false;
      }
      // Sync users
      UserbinIdentity::destroy_all();
      $blogusers = get_users();
      foreach ($blogusers as $user) {
        $response = UserbinIdentity::import(array(
          'local_id'      => $user->id,
          'username' => $user->user_login,
          'email'    => $user->user_email,
          'password' => 'phpass:'.$user->user_pass
        ));
        if ($response->successful()) {
          $identity = $response->as_json();
          update_user_meta($user->id, 'userbin_id', $identity['id']);
        }
      }
      return true;
    }

    public static function activated() {
      return self::_get_settings('activated');
    }

    private static function add_actions() {
      // Common actions
      add_action('init', array(__CLASS__, 'register_resources'), 0);

      if(is_admin()) {
        // Administrative only actions
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_menu', array(__CLASS__, 'add_settings_page'));
      } else {
        // Frontend only actions
      }
      add_action('wp_footer', array(__CLASS__, 'script_tag'), 100);
      add_action('login_footer', array(__CLASS__, 'script_tag' ));
      add_action('wp_logout', array(__CLASS__, 'logout'), 1);
      add_action('delete_user', array(__CLASS__, 'user_delete'), 1);
      add_action('user_register', array(__CLASS__, 'user_register'), 1);
      add_action('profile_update', array(__CLASS__, 'user_update'), 1);
      add_action('password_reset', array(__CLASS__, 'user_reset_password'), 1);
    }

    private static function add_filters() {
      add_filter('authenticate', array(__CLASS__, 'authenticate'), 1);
      add_filter('registration_errors', array(__CLASS__, 'registration_errors'));
    }

    private static function register_shortcodes() {

    }

    /// Callbacks

    //// Activation, deactivation, and uninstall

    public static function do_activation_actions() {

    }

    public static function do_deactivation_actions() {

    }

    //// Generic operation

    public static function register_resources() {
      self::init_user();
    }

    //// Settings related

    public static function add_settings_page() {
      $settings_page_hook_suffix = add_options_page(__('Userbin - Settings'), __('Userbin'), 'manage_options', self::SETTINGS_PAGE_SLUG, array(__CLASS__, 'display_settings_page'));

      if($settings_page_hook_suffix) {
        add_action("load-{$settings_page_hook_suffix}", array(__CLASS__, 'load_settings_page'));
      }
    }

    public static function display_settings_page() {
      $settings = self::_get_settings();

      include('views/settings.php');
    }

    public static function load_settings_page() {

    }

    public static function register_settings() {
      register_setting(self::SETTINGS_NAME, self::SETTINGS_NAME, array(__CLASS__, 'sanitize_settings'));
    }

    public static function sanitize_settings($settings) {
      $defaults = self::_get_settings_default();

      if(isset($settings['appId'])) {
        $settings['appId'] = trim(strip_tags($settings['appId']));
      }

      if(isset($settings['apiSecret'])) {
        $settings['apiSecret'] = trim(strip_tags($settings['apiSecret']));
      }

      $valid = self::activate($settings['appId'], $settings['apiSecret']);
      $settings['activated'] = $valid;

      return shortcode_atts($defaults, $settings);
    }

    // FILTERS

    public static function registration_errors() {
      $error = new WP_Error();
      $token = $_POST['userbin_token'];
      if (empty($token)) {
        $error->add( 'denied', __("<strong>ERROR</strong>: Invalid parameters") );
      }
      return $error;
    }

    public static function script_tag() {
      $appId = self::_get_settings('appId');
      $secret = self::_get_settings('apiSecret');
      if (!empty($appId) && !empty($secret)){
        echo Userbin::javascript_include_tag();
        echo '<script>ubin({loginForm: "#loginform", signupForm: "#registerform", loginFields: {';
        echo 'email: "#user_login", password: "#user_pass"},';
        echo 'signupFields: { username: "#user_login", email: "#user_email"';
        echo '}});</script>';
      }
    }

    public static function authenticate( $user, $username = '', $password = '' ){
        $profile = Userbin::current_profile();
        if (Userbin::authenticated()) {
          $user = new WP_User($profile['local_id']);
        } else {
          $user = new WP_Error( 'denied', __("<strong>ERROR</strong>: User/pass bad") );
        }

         //remove_action('authenticate', 'wp_authenticate_username_password', 20);
         return $user;
    }

    public static function logout() {
      Userbin::deauthenticate();
    }

    public static function user_delete($user_id) {
      $id = get_user_meta($user_id, 'userbin_id', true);
      if (empty($id)) { return true; }
      $identity = new UserbinIdentity($id);
      $identity->destroy();
      return true;
    }

    public static function user_register($user_id) {
      $token = $_REQUEST['userbin_token'];
      if (!empty($token)) {
        $user = new WP_User($user_id);
        $identity = Userbin::identify($token);
        $identity->activate($user_id, array(
          'password_hash' => 'phpass:'.$user->user_pass
        ));
        // Set userbin id in meta
        update_user_meta($user_id, 'userbin_id', $identity->id);

        //Userbin::login($token);
      }
    }

    public static function user_reset_password($user, $new_pass = '') {
      $id = get_user_meta($user->ID, 'userbin_id', true);
      if (empty($id)) { return true; }
      $identity = new UserbinIdentity($id);
      $identity->update(array(
        'password_hash' => 'phpass:'.$user->user_pass
      ));
    }

    public static function user_update($user_id, $old_user_data) {
      $id = get_user_meta($user_id, 'userbin_id', true);
      if (empty($id)) { return true; }
      $user = new WP_User($user_id);
      $identity = new UserbinIdentity($id);
      $response = $identity->update(array(
        'username'      => $user->user_login,
        'email'         => $user->user_email,
        'password_hash' => 'phpass:'.$user->user_pass
      ));
      // FIXME: This doesn't work. There doesn't seem to be a way to abort
      // password updates of a user.
      if (!$response || !$response->successful()) {
        return new WP_Error($response->as_json());
      }
    }

    private static function _get_settings($settings_key = null) {
      $defaults = self::_get_settings_default();

      $settings = get_option(self::SETTINGS_NAME, $defaults);
      $settings = shortcode_atts($defaults, $settings);

      return is_null($settings_key) ? $settings : (isset($settings[$settings_key]) ? $settings[$settings_key] : false);
    }

    private static function _get_settings_default() {
      if(is_null(self::$default_settings)) {
        self::$default_settings = array(
          'appId'     => '',
          'apiSecret' => '',
          'activated' => false
        );
      }

      return self::$default_settings;
    }

    private static function _settings_id($key, $echo = true) {
      $settings_name = self::SETTINGS_NAME;

      $id = "{$settings_name}-{$key}";
      if($echo) {
        echo $id;
      }

      return $id;
    }

    private static function _settings_name($key, $echo = true) {
      $settings_name = self::SETTINGS_NAME;

      $name = "{$settings_name}[{$key}]";
      if($echo) {
        echo $name;
      }

      return $name;
    }

    /// Template tags

    public static function get_template_tag() {
      return '';
    }
  }

  require_once('lib/userbin.php');
  WP_Plugin_Userbin::init();
}
?>