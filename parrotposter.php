<?php
/**
 * Plugin Name: ParrotPoster
 * Plugin URI: https://parrotposter.com
 * Description: Plugin of service posting in the social networks ParrotPoster.
 * Author: Selen
 * Version: 1.0.0
 * Author URI: http://selen.digital
 * Text Domain: parrotposter
 * Domain Path: /languages
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

if (!defined('ABSPATH')) {
	die;
}

define('PARROTPOSTER_VERSION', '1.0.0');
define('PARROTPOSTER_DB_VERSION', '1.0.7');
define('PARROTPOSTER_PLUGIN_FILE', __FILE__);
define('PARROTPOSTER_PLUGIN_DIR', plugin_dir_path(__FILE__));

// include helpers
require_once PARROTPOSTER_PLUGIN_DIR.'helpers/language.php';

// register autoloaders
require_once PARROTPOSTER_PLUGIN_DIR.'src/autoloader.php';
//require_once PARROTPOSTER_PLUGIN_DIR.'vendor/autoloader.php';

use parrotposter\PP;

if (class_exists('parrotposter\\PP')) {
	PP::get_instance()->register();
}
