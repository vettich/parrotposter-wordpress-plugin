<?php
/**
 * Plugin Name: ParrotPoster - Автопубликации в соц. сети
 * Plugin URI: https://parrotposter.com
 * Description: Плагин сервиса автопубликаций в соц. сети ParrotPoster. Специально для WordPress!
 * Author: Selen
 * Version: 1.0.0
 * Author URI: http://selen.digital
 * Text Domain: selen-parrot-poster
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
	die;
}

define('PARROTPOSTER_PLUGIN_FILE', __FILE__);
define('PARROTPOSTER_PLUGIN_DIR', plugin_dir_path(__FILE__));

// register autoloader
require_once PARROTPOSTER_PLUGIN_DIR.'src/autoloader.php';

if (!function_exists('parrotposter__')) {
	function parrotposter__($msg, ...$values) {
		return sprintf(esc_html__($msg, 'parrotposter'), ...$values);
	}
}

if (!function_exists('parrotposter_e')) {
	function parrotposter_e($msg, ...$values) {
		printf(esc_html__($msg, 'parrotposter'), ...$values);
	}
}

class ParrotPoster
{
	public function __construct()
	{
	}

	public static function asset($filename)
	{
		return plugins_url("/assets/$filename", PARROTPOSTER_PLUGIN_FILE);
	}

	public static function log($data)
	{
		if (!parrotposter\Options::log_enabled()) {
			return;
		}

		$dtz = date_default_timezone_get();
		date_default_timezone_set('Asia/Yekaterinburg');
		$now = date(DATE_ATOM);
		date_default_timezone_set($dtz);

		$log = [
			'datetime' => $now,
			'data' => $data,
		];
		$s = print_r($log, true);
		error_log($s, 3, PARROTPOSTER_PLUGIN_DIR.'var.log');
	}

	public function register()
	{
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);

		add_action('admin_menu', [$this, 'admin_menu']);

		parrotposter\AdminAjaxPost::get_instance();
	}

	public static function activation()
	{
	}

	public static function deactivation()
	{
	}

	public function enqueue_admin()
	{
		wp_enqueue_style('parrotPosterStyle', self::asset('css/style.css'));
		wp_enqueue_script('parrotPosterScript', self::asset('js/script.js'));
	}

	public function admin_menu()
	{
		add_menu_page(parrotposter__('ParrotPoster settings page'), parrotposter__('ParrotPoster'), 'manage_options', 'parrotposter', [$this, 'admin_page'], self::asset('images/icon.png'), 100);
		// add_submenu_page('parrotposter', parrotposter__('Authorization'), parrotposter__('Authorization'), 'manage_options', 'parrotposter_auth', [$this, 'admin_page']);
	}

	public function admin_page()
	{
		$page = $_GET['page'] ?: 'parrotposter';
		$prefix = 'parrotposter_';
		if (substr($page, 0, strlen($prefix)) == $prefix) {
			$page = substr($page, strlen($prefix));
		}
		self::include_view($page);
	}

	public static function include_view($name)
	{
		require_once PARROTPOSTER_PLUGIN_DIR."views/$name.php";
	}
}

if (class_exists('ParrotPoster')) {
	$parrotPoster = new ParrotPoster();
	$parrotPoster->register();
}

register_activation_hook(__FILE__, [$parrotPoster, 'activation']);
register_deactivation_hook(__FILE__, [$parrotPoster, 'deactivation']);
