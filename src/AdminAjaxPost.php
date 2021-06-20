<?php

namespace parrotposter;

use ParrotPoster;

class AdminAjaxPost {

	static private $instance = null;

	public static function get_instance()
	{
		if (self::$instance == null) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	public function __construct()
	{
		add_action('admin_post_parrotposter_auth', [$this, 'auth']);
		add_action('wp_ajax_parrotposter_auth', [$this, 'auth']);
		add_action('admin_post_parrotposter_logout', [$this, 'logout']);
	}

	public function auth()
	{
		if (!FormHelpers::check_post_nonce()) {
			FormHelpers::post_error('nonce');
		}
		$username = trim($_POST['parrotposter']['username']);
		$password = trim($_POST['parrotposter']['password']);
		if (empty($username)) {
			FormHelpers::post_error(ParrotPoster::__('Username is empty'));
		}
		if (empty($password)) {
			FormHelpers::post_error(ParrotPoster::__('Password is empty'));
		}
		$res = Api::login($username, $password);
		if (!empty($res['error'])) {
			FormHelpers::post_error($res['error']);
		}
		FormHelpers::post_success('logged');
	}

	public function logout()
	{
		if (!FormHelpers::check_post_nonce()) {
			FormHelpers::post_error('nonce');
		}
		Api::logout();
		FormHelpers::post_success();
	}
}