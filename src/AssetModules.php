<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class AssetModules
{
	private static $modules = [
		'common',
		'h1',
		'header',
		'modal',
		'block',
		'nav-tab',
		'notice',
		'input',
		'copy',
		'loading',
		'accounts',
		'accounts-connect',
		'tariffs',
		'autoposting_list_table',
		'autoposting_form',
		'posts',
		'post-detail',
		'post-meta-box',
		'publish-post',
		'help',
	];

	private static $libs = [
		'flatpickr' => [
			'css' => 'lib/flatpickr/flatpickr.min.css',
			'js' => 'lib/flatpickr/flatpickr.js',
		],
		'ultraselect' => [
			'css' => 'lib/ultraselect/jquery.ultraselect.min.css',
			'js' => 'lib/ultraselect/jquery.ultraselect.min.js',
		],
		'pqselect' => [
			'css' => [
				'lib/pqselect/pqselect.min.css',
				// 'lib/pqselect/jquery-ui-1.11.4.min.css',
				'lib/pqselect/jquery-ui-1.9.1-smoothness.css',
				// 'lib/pqselect/bootstrap.3.3.5.min.css',
				// 'lib/pqselect/pqselect.bootstrap.min.css',
				'css/lib-pqselect.css',
			],
			// 'js_deps' => ['jquery-ui-core', 'jquery-ui-widget'],
			'js' => 'lib/pqselect/pqselect.min.js',
		],
	];

	private static $registered = [];

	public static function register()
	{
		foreach (self::$modules as $m) {
			self::register_style_module($m);
			self::register_script_module($m);
		}

		foreach (self::$libs as $m => $assets) {
			self::register_style_lib($m, $assets);
			self::register_script_lib($m, $assets);
		}
	}

	public static function is_registered_css($module)
	{
		return isset(self::$registered[$module]) && isset(self::$registered[$module]['css']) && self::$registered[$module]['css'];
	}

	public static function is_registered_js($module)
	{
		return isset(self::$registered[$module]) && isset(self::$registered[$module]['js']) && self::$registered[$module]['js'];
	}

	public static function enqueue($modules = [])
	{
		foreach ($modules as $module) {
			if (self::is_registered_css($module)) {
				wp_enqueue_style("parrotposter-$module");
			}
			if (self::is_registered_js($module)) {
				wp_enqueue_script("parrotposter-$module");
			}
		}
	}

	private static function register_style_module($module)
	{
		$css = "css/$module.css";
		if (PP::isset_asset($css)) {
			wp_register_style("parrotposter-$module", PP::asset($css), [], PARROTPOSTER_VERSION);
			self::$registered[$module]['css'] = true;
		}
	}

	private static function register_style_lib($module, $assets, $key = 'css', $depsKey = 'css_deps')
	{
		if (is_array($assets) && !isset($assets[$key])) {
			return;
		}

		$css = $assets[$key];
		if (is_array($css)) {
			if (empty($css)) {
				return;
			}

			$deps = isset($assets[$depsKey]) ? $assets[$depsKey] : [];
			foreach ($css as $k => $v) {
				if (!PP::isset_asset($v)) {
					continue;
				}

				wp_register_style("parrotposter-$module-$k", PP::asset($v), [], PARROTPOSTER_VERSION);
				$deps[] = "parrotposter-$module-$k";
			}
			wp_register_style("parrotposter-$module", false, $deps, PARROTPOSTER_VERSION);
		} elseif (PP::isset_asset($css)) {
			wp_register_style("parrotposter-$module", PP::asset($css), [], PARROTPOSTER_VERSION);
		}
		self::$registered[$module]['css'] = true;
	}

	private static function register_script_module($module)
	{
		$js = "js/$module.js";
		if (PP::isset_asset($js)) {
			wp_register_script("parrotposter-$module", PP::asset($js), [], PARROTPOSTER_VERSION);
			self::$registered[$module]['js'] = true;
		}
	}

	private static function register_script_lib($module, $assets, $key = 'js', $depsKey = 'js_deps')
	{
		if (is_array($assets) && !isset($assets[$key])) {
			return;
		}

		$js = $assets[$key];
		if (is_array($js)) {
			if (empty($js)) {
				return;
			}

			$deps = isset($assets[$depsKey]) ? $assets[$depsKey] : [];
			foreach ($js as $k => $v) {
				if (!PP::isset_asset($v)) {
					continue;
				}

				wp_register_script("parrotposter-$module-$k", PP::asset($v), [], PARROTPOSTER_VERSION);
				$deps[] = "parrotposter-$module-$k";
			}
			wp_register_script("parrotposter-$module", false, $deps, PARROTPOSTER_VERSION);
		} elseif (PP::isset_asset($js)) {
			wp_register_script("parrotposter-$module", PP::asset($js), [], PARROTPOSTER_VERSION);
		}
		self::$registered[$module]['js'] = true;
	}
}
