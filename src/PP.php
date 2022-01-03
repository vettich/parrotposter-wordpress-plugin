<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class PP
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
		// if (!Options::log_enabled()) {
		// 	return;
		// }

		$log = [
			'at' => date(DATE_ATOM),
			'data' => $data,
		];
		$s = print_r($log, true);
		error_log($s, 3, PARROTPOSTER_PLUGIN_DIR.'var.log');
	}

	public function register()
	{
		add_action('plugins_loaded', [$this, 'load_textdomain']);

		add_action('admin_enqueue_scripts', [$this, 'register_scripts']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_translates'], 100);

		add_action('admin_menu', [$this, 'admin_menu']);

		add_action('add_meta_boxes', [$this, 'post_meta_box']);

		AdminAjaxPost::init(false);
		Install::init();
		Scheduler::init();

		register_activation_hook(PARROTPOSTER_PLUGIN_FILE, [$this, 'activation']);
		register_deactivation_hook(PARROTPOSTER_PLUGIN_FILE, [$this, 'deactivation']);
	}

	public static function activation()
	{
		Install::install();
	}

	public static function deactivation()
	{
		Install::uninstall();
		Options::delete_data();
	}

	public function load_textdomain()
	{
		load_plugin_textdomain('parrotposter', false, dirname(plugin_basename(PARROTPOSTER_PLUGIN_FILE)).'/languages');
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
		AssetModules::enqueue_script_translates();
	}

	public function admin_menu()
	{
		if (empty(Options::user_id())) {
			add_menu_page(__('ParrotPoster settings page', 'parrotposter'), __('ParrotPoster', 'parrotposter'), 'manage_options', 'parrotposter', [$this, 'admin_page'], self::asset('images/icon.png'), 100);
			add_submenu_page('parrotposter', __('Authorization', 'parrotposter'), __('Authorization', 'parrotposter'), 'manage_options', 'parrotposter', [$this, 'admin_page']);
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
		$post_types = WpPostHelpers::get_post_types();
		add_meta_box('parrotposter-post-meta-box', 'ParrotPoster', [$this, 'post_meta_box_view'], $post_types, 'side', 'high');
	}

	public function post_meta_box_view()
	{
		self::include_view('post-meta-box');
	}
}
