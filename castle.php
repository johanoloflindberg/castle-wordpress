<?php
/*
Plugin Name: Castle Security
Description: Secure your Wordpress site with intelligent account takeover protection.
Version: 0.1.0
Author: Castle
Author URI: https://castle.io
License: GPL2

    Copyright 2013-2015  Castle.io

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

if(!class_exists('WP_Plugin_Castle')) {
  class WP_Plugin_Castle {
    /// Constants

    //// Plugin Version
    const VERSION = '0.1.0';

    //// Keys
    const SETTINGS_NAME = '_wp_plugin_castle_settings';

    //// Slugs
    const SETTINGS_PAGE_SLUG = 'wp-plugin-castle-settings';

    //// Defaults
    private static $default_settings = null;

    public static function init() {
      $secret = self::_get_settings('apiSecret');
      if (!empty($secret)) {
        Castle::setApiKey($secret);
      }
      self::add_actions();
      self::add_filters();
      self::register_shortcodes();

      register_activation_hook(__FILE__, array(__CLASS__, 'do_activation_actions'));
      register_deactivation_hook(__FILE__, array(__CLASS__, 'do_deactivation_actions'));
    }

    public static function activate($apiSecret) {
      Castle::setApiKey($apiSecret);
      try {
        $account = new Castle_Account();
        $account->get();
      } catch (Castle_UnauthorizedError $e) {
         return false;
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
      if (self::activated()) {
        add_action('wp_login', array(__CLASS__, 'user_login'), 100, 2);
        add_action('wp_logout', array(__CLASS__, 'user_logout'), 1);
        add_action('delete_user', array(__CLASS__, 'user_delete'), 1);
        add_action('user_register', array(__CLASS__, 'user_register'), 1);
        add_action('profile_update', array(__CLASS__, 'user_update'), 1);
        add_action('password_reset', array(__CLASS__, 'user_reset_password'), 1);
        add_action('wp_footer', array(__CLASS__, 'script_tag'), 100);
        add_action('admin_footer', array(__CLASS__, 'script_tag'), 100);
        add_action('login_footer', array(__CLASS__, 'script_tag' ));
      }
    }

    private static function add_filters() {

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
    }

    //// Settings related

    public static function add_settings_page() {
      $settings_page_hook_suffix = add_options_page(__('Castle - Settings'), __('Castle'), 'manage_options', self::SETTINGS_PAGE_SLUG, array(__CLASS__, 'display_settings_page'));

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

      $valid = self::activate($settings['apiSecret']);
      $settings['activated'] = $valid;

      return shortcode_atts($defaults, $settings);
    }

    public static function script_tag() {
      $settings = self::_get_settings();
      $current_user = wp_get_current_user();
      if($settings['appId']) {
        echo '<script type="text/javascript">';
        echo '(function(e,t,n,r){function i(e,n){e=t.createElement("script");e.async=1;e.src=r;n=t.getElementsByTagName("script")[0];n.parentNode.insertBefore(e,n)}e[n]=e[n]||function(){(e[n].q=e[n].q||[]).push(arguments)};e.attachEvent?e.attachEvent("onload",i):e.addEventListener("load",i,false)})(window,document,"_castle","//d2t77mnxyo7adj.cloudfront.net/v1/c.js");';
        echo "_castle('setAccount', '$settings[appId]');";
        if ($current_user->id != '0') {
          echo "_castle('setUser', {id: '$current_user->id'});";
        }
        echo '_castle(\'trackPageview\');';
        echo '</script>';
      }
    }

    public static function user_delete($user_id) {
      // TODO: delete user
    }

    public static function user_login( $user_login, $user ) {
      Castle::track(Array(
        'name' => '$login.succeeded',
        'user_id' => $user->id,
        'details' => Array(
          'username' => $user->user_login,
          'email'    => $user->user_email
        )
      ));
      return $user;
    }

    public static function user_logout() {
      if (is_user_logged_in()) {
        $current_user = wp_get_current_user();
        Castle::track(Array(
          'name' => '$logout.succeeded',
          'user_id' => $current_user->id
        ));
      }
    }

    public static function user_register($user_id) {
      Castle::track(Array(
        'name' => '$registration.succeeded',
        'user_id' => $user_id
      ));
    }

    public static function user_update($user_id, $old_user_data) {
      // TODO: update user params
    }

    public static function user_reset_password($user, $new_pass = '') {
      Castle::track(Array(
        'name' => '$password_reset.succeeded',
        'user_id' => $user->id
      ));
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
          'appId' => '',
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

  require_once('lib/lib/Castle.php');
  WP_Plugin_Castle::init();
}
?>