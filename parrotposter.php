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

define('PARROTPOSTER_PLUGIN_FILE', __FILE__);
define('PARROTPOSTER_PLUGIN_DIR', plugin_dir_path(__FILE__));

// include helpers
require_once PARROTPOSTER_PLUGIN_DIR.'helpers/language.php';

// register autoloaders
require_once PARROTPOSTER_PLUGIN_DIR.'src/autoloader.php';
// require_once PARROTPOSTER_PLUGIN_DIR.'vendor/autoloader.php';

use parrotposter\Menu;
use parrotposter\Options;
use parrotposter\AdminAjaxPost;
use parrotposter\AssetModules;

class ParrotPoster
{
	private static $_instance = null;
	public static function get_instance()
	{
		if (self::$_instance == null) {
			self::$_instance = new self;
		}
		return self::$_instance;
	}

	public function __construct()
	{
	}

	public static function asset($filename)
	{
		return plugins_url("/assets/$filename", PARROTPOSTER_PLUGIN_FILE);
	}

	public static function isset_asset($filename)
	{
		return file_exists(PARROTPOSTER_PLUGIN_DIR."/assets/$filename");
	}

	public static function log($data)
	{
		if (!Options::log_enabled()) {
			return;
		}

		$log = [
			'at' => date(DATE_ATOM),
			'data' => $data,
		];
		$s = print_r($log, true);
		error_log($s, 3, PARROTPOSTER_PLUGIN_DIR.'var.log');
	}

	public function register()
	{
		add_action('init', [$this, 'load_textdomain']);

		add_action('admin_enqueue_scripts', [$this, 'register_scripts']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_translates'], 100);

		add_action('admin_menu', [$this, 'admin_menu']);

		add_action('add_meta_boxes', [$this, 'post_meta_box']);

		AdminAjaxPost::get_instance();
	}

	public static function activation()
	{
	}

	public static function deactivation()
	{
	}

	public function load_textdomain()
	{
		load_plugin_textdomain('parrotposter', false, dirname(plugin_basename(__FILE__)).'/languages');
	}

	public function register_scripts()
	{
		// parrotposter css files
		wp_enqueue_style('parrotposter-admin-menu', self::asset('css/admin-menu.css'));

		// parrotposter js files
		wp_register_script('parrotposter-post-meta-box', self::asset('js/post-meta-box.js'));

		AssetModules::register();
	}

	public function enqueue_admin_translates()
	{
		wp_set_script_translations('parrotposter-main-script', 'parrotposter', PARROTPOSTER_PLUGIN_DIR.'languages');
		wp_set_script_translations('parrotposter-post-meta-box', 'parrotposter', PARROTPOSTER_PLUGIN_DIR.'languages');
	}

	public function admin_menu()
	{
		if (empty(Options::user_id())) {
			add_menu_page(parrotposter__('ParrotPoster settings page'), parrotposter__('ParrotPoster'), 'manage_options', 'parrotposter', [$this, 'admin_page'], self::asset('images/icon.png'), 100);
			add_submenu_page('parrotposter', parrotposter__('Authorization'), parrotposter__('Authorization'), 'manage_options', 'parrotposter', [$this, 'admin_page']);
			return;
		}

		$main_item = Menu::get_main_item();
		if (!$main_item) {
			return;
		}
		add_menu_page($main_item['label'], 'ParrotPoster', 'manage_options', $main_item['id'], [$this, 'admin_page'], self::asset('images/icon.png'), 100);

		foreach (Menu::get_items() as $item) {
			add_submenu_page($main_item['id'], $item['label'], $item['label'], 'manage_options', $item['id'], [$this, 'admin_page']);
		}
	}

	public function admin_page()
	{
		$page = isset($_GET['page']) ? sanitize_text_field($_GET['page']): 'parrotposter';
		$view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'index';
		$prefix = 'parrotposter_';
		if (substr($page, 0, strlen($prefix)) == $prefix) {
			$page = substr($page, strlen($prefix));
		}

		self::include_view("$page/$view");
	}

	public static function include_view($name, $view_args = [])
	{
		wp_enqueue_script('parrotposter-main-script');
		require_once PARROTPOSTER_PLUGIN_DIR."views/$name.php";
	}

	public function post_meta_box()
	{
		$post_id = (isset($_GET['post']) && (int) $_GET['post'] > 0) ? (int) $_GET['post'] : 0;
		if (empty($post_id)) {
			return;
		}
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
	ParrotPoster::get_instance()->register();
	register_activation_hook(__FILE__, [ParrotPoster::get_instance(), 'activation']);
	register_deactivation_hook(__FILE__, [ParrotPoster::get_instance(), 'deactivation']);
}
