<?php

/**
 * Удаление плагина из админки WordPress: таблицы и опции.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

define('PARROTPOSTER_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once PARROTPOSTER_PLUGIN_DIR . 'src/autoloader.php';

parrotposter\Install::uninstall();
