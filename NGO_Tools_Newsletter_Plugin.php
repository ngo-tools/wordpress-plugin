<?php

if (!defined('ABSPATH')) {
    exit;
}

final class NGO_Tools_Newsletter_Plugin {

    private static $instance = null;
    private $encryption;

    private function __construct() {
        $this->encryption = new NGO_Tools_DataEncryption();

        // Load textdomain
        add_action('plugins_loaded', [$this, 'load_textdomain']);

        // Initialize admin and frontend classes
        if (is_admin() && ! wp_doing_ajax()) {
            NGO_Tools_Admin::get_instance($this->encryption);
        } else {
            NGO_Tools_Frontend::get_instance($this->encryption);
        }
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function load_textdomain() {
        load_plugin_textdomain('ngo_tools_newsletter', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }
}
