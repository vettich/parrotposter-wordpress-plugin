<?php
/**
 * Plugin Name: ParrotPoster - Автопубликации в соц. сети
 * Plugin URI: https://parrotposter.com
 * Description: Плагин сервиса автопубликаций в соц. сети ParrotPoster. Специально для WordPress!
 * Author: Selen
 * Version: 1.0.0
 * Author URI: http://selen.digital
 * Text Domain: parrotposter
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
	function parrotposter__($msg, ...$values)
	{
		return sprintf(esc_html__($msg, 'parrotposter'), ...$values);
	}
}

if (!function_exists('parrotposter_e')) {
	function parrotposter_e($msg, ...$values)
	{
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
		load_plugin_textdomain('parrotposter', false, dirname(plugin_basename(__FILE__)).'/languages');

		add_action('admin_enqueue_scripts', [$this, 'register_scripts']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_translates'], 100);

		add_action('admin_menu', [$this, 'admin_menu']);

		add_action('add_meta_boxes', [$this, 'post_meta_box']);

		parrotposter\AdminAjaxPost::get_instance();
	}

	public static function activation()
	{
	}

	public static function deactivation()
	{
	}

	public function register_scripts()
	{
		// parrotposter css files
		wp_enqueue_style('parrotposter-main-css', self::asset('css/style.css'));

		// parrotposter js files
		wp_register_script('parrotposter-main-script', self::asset('js/script.js'));
		wp_register_script('parrotposter-post-meta-box', self::asset('js/post-meta-box.js'));

		// jquery-ui
		wp_register_style('parrotposter-jquery-ui-css', self::asset('lib/jquery-ui/jquery-ui.min.css'));
		wp_register_script('parrotposter-jquery-ui-js', self::asset('lib/jquery-ui/jquery-ui.min.js'));
	}

	public function enqueue_admin_translates()
	{
		wp_set_script_translations('parrotposter-main-script', 'parrotposter', PARROTPOSTER_PLUGIN_DIR.'languages');
		wp_set_script_translations('parrotposter-post-meta-box', 'parrotposter', PARROTPOSTER_PLUGIN_DIR.'languages');
	}

	public function admin_menu()
	{
		add_menu_page(parrotposter__('ParrotPoster settings page'), parrotposter__('ParrotPoster'), 'manage_options', 'parrotposter', [$this, 'admin_page'], self::asset('images/icon.png'), 100);
		if (empty(parrotposter\Options::user_id())) {
			add_submenu_page('parrotposter', parrotposter__('Authorization'), parrotposter__('Authorization'), 'manage_options', 'parrotposter', [$this, 'admin_page']);
		} else {
			add_submenu_page('parrotposter', parrotposter__('User'), parrotposter__('User'), 'manage_options', 'parrotposter', [$this, 'admin_page']);
			add_submenu_page('parrotposter', parrotposter__('Social media accounts'), parrotposter__('Social media accounts'), 'manage_options', 'parrotposter_accounts', [$this, 'admin_page']);
			add_submenu_page('parrotposter', parrotposter__('Posts'), parrotposter__('Posts'), 'manage_options', 'parrotposter_posts', [$this, 'admin_page']);
		}
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
		wp_enqueue_script('parrotposter-main-script');
		require_once PARROTPOSTER_PLUGIN_DIR."views/$name.php";
	}

	public function post_meta_box()
	{
		$post_types = get_post_types(['public' => true]);
		$post_types = array_diff($post_types, ['attachment', 'nav_menu_item']);
		add_meta_box('parrotposter-post-meta-box', 'ParrotPoster', [$this, 'post_meta_box_view'], $post_types, 'side', 'high');
	}

	public function post_meta_box_view()
	{
		self::include_view('post-meta-box');
	}
}

if (class_exists('ParrotPoster')) {
	$parrotPoster = new ParrotPoster();
	$parrotPoster->register();
}

register_activation_hook(__FILE__, [$parrotPoster, 'activation']);
register_deactivation_hook(__FILE__, [$parrotPoster, 'deactivation']);
