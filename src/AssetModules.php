<?php

namespace parrotposter;

use ParrotPoster;

class AssetModules
{
	private static $modules = [
		'common',
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
	];
	private static $libs = [
		'flatpickr' => [
			'css' => 'lib/flatpickr/flatpickr.min.css',
			'js' => 'lib/flatpickr/flatpickr.js',
		],
	];

	private static $registered = [];

	public static function register()
	{
		foreach (self::$modules as $m) {
			if (ParrotPoster::isset_asset("css/$m.css")) {
				wp_register_style("parrotposter-$m", ParrotPoster::asset("css/$m.css"));
				self::$registered[$m]['css'] = true;
			}
			if (ParrotPoster::isset_asset("js/$m.js")) {
				wp_register_script("parrotposter-$m", ParrotPoster::asset("js/$m.js"));
				self::$registered[$m]['js'] = true;
			}
		}
		foreach (self::$libs as $m => $assets) {
			if (ParrotPoster::isset_asset($assets['css'])) {
				wp_register_style("parrotposter-$m", ParrotPoster::asset($assets['css']));
				self::$registered[$m]['css'] = true;
			}
			if (ParrotPoster::isset_asset($assets['js'])) {
				wp_register_script("parrotposter-$m", ParrotPoster::asset($assets['js']));
				self::$registered[$m]['js'] = true;
			}
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
}
