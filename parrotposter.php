<?php
/**
 * Plugin Name: ParrotPoster - Auto Post to Social Media
 * Plugin URI: https://parrotposter.com
 * Description: Auto post or selective post of news and products from the site to social networks (media) Facebook, Instagram, Telegram, VK, OK (autoposting, autopost).
 * Author: Selen
 * Author URI: http://selen.digital
 * Version: 1.0.3
 * Text Domain: parrotposter
 * Domain Path: /languages
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

if (!defined('ABSPATH')) {
	die;
}

define('PARROTPOSTER_VERSION', '1.0.3');
define('PARROTPOSTER_DB_VERSION', '1.0.7');
define('PARROTPOSTER_PLUGIN_FILE', __FILE__);
define('PARROTPOSTER_PLUGIN_DIR', plugin_dir_path(__FILE__));

// register autoloaders
require_once PARROTPOSTER_PLUGIN_DIR.'src/autoloader.php';

// include includes
require_once PARROTPOSTER_PLUGIN_DIR.'includes/posts_custom_column.php';

use parrotposter\PP;

if (class_exists('parrotposter\\PP')) {
	PP::get_instance()->register();
}
