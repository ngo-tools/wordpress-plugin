<?php
/*
Plugin Name: NGO Tools newsletter subscription form
Description: Newsletter subscription form sending data as JSON via cURL POST with Bearer Token authentication, AJAX support, localization, and admin settings including API endpoint URL and segment selection.
Version: 1.0
Author: David Recinos
Text Domain: ngo_tools_newsletter
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit;
}
require_once('NGO_Tools_DataEncryption.php');
require_once('NGO_Tools_Admin.php');
require_once('NGO_Tools_Frontend.php');
require_once('NGO_Tools_Newsletter_Plugin.php');

// Initialize plugin
NGO_Tools_Newsletter_Plugin::get_instance();