<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class Api
{
	const API_URL = 'https://parrotposter.com/api/v1/';
	// const API_URL = 'http://188.225.45.58:8010/api/v1/';
	const FROM = 'wordpress';
	const USER_AGENT = 'ParrotPoster WP Plugin';

	private static $_api_log_enabled = true;

	public static function ping()
	{
		return self::get('ping');
	}

	public static function login($username, $password)
	{
		$data = [
			'username' => $username,
			'password' => $password,
			'from' => self::FROM,
		];
		$res = self::post('tokens', $data);
		if (!empty($res['error'])) {
			return $res;
		}

		Options::set_user_data($res['response']['user_id'], $res['response']['token']);
		return [];
	}

	public static function signup($name, $username, $password)
	{
		$data = [
			'name' => $name,
			'username' => $username,
			'password' => $password,
			'from' => self::FROM,
		];
		$res = self::post('users', $data);
		if (!empty($res['error'])) {
			return $res;
		}

		Options::set_user_data($res['response']['user_id'], $res['response']['token']);
		return [];
	}

	public static function forgot_password($username, $callback_url)
	{
		$data = [
			'username' => $username,
			'callback_url' => $callback_url,
			'from' => self::FROM,
		];
		$res = self::post('passwords/forgot', $data);
		return $res;
	}

	public static function reset_password($token, $password)
	{
		$data = [
			'token' => $token,
			'password' => $password,
		];
		$res = self::post('passwords/new', $data);
		return $res;
	}

	public static function logout()
	{
		Options::set_user_data('', '');
	}

	public static function validate_token()
	{
		$token = Options::token();
		if (empty($token)) {
			return ['error' => ['msg' => 'token is empty']];
		}
		$res = self::get("tokens/$token/valid");
		return $res;
	}

	public static function me()
	{
		$res = self::get('me');
		return ApiHelpers::prepare_api_response($res, '', []);
	}

	public static function get_tariff($id)
	{
		if (empty($id)) {
			return [null, 'id is empty'];
		}

		$res = self::get("tariffs/$id");
		return ApiHelpers::prepare_api_response($res);
	}

	public static function list_tariffs()
	{
		$res = self::get('tariffs');
		return $res;
	}

	public static function set_user_tariff($tariff_id)
	{
		$data = ['tariff_id' => $tariff_id];
		$res = self::post('me/set-tariff', $data);
		return $res;
	}

	public static function create_transaction($tariff_id, $period, $success_url, $fail_url)
	{
		$data = [
			'tariff_id' => $tariff_id,
			'period' => intval($period),
			'success_url' => $success_url,
			'fail_url' => $fail_url,
		];
		$res = self::post('transactions', $data);
		return $res;
	}

	public static function list_accounts()
	{
		$res = self::get('accounts');
		return ApiHelpers::prepare_api_response($res, 'accounts', []);
	}

	public static function get_connect_url($account_type, $callback_url)
	{
		$data = [
			'type' => $account_type,
			'callback' => $callback_url,
		];
		$res = self::get('connect_url', $data);
		return $res;
	}

	public static function connect($account_type, $fields)
	{
		$data = [
			'type' => $account_type,
			'fields' => $fields,
		];
		$res = self::post('connect', $data);
		return $res;
	}

	public static function delete_account($account_id)
	{
		$res = self::delete("accounts/$account_id");
		return $res;
	}

	public static function list_posts($filter = [], $sort = [], $paging = [], $needCounts = false)
	{
		$filter['from'] = self::FROM;
		$data = [
			'filter' => $filter,
		];
		if (!empty($sort)) {
			$data['sort'] = $sort;
		}
		if (!empty($paging)) {
			$data['paging'] = $paging;
		}
		if ($needCounts) {
			$data['need_counts'] = true;
		}
		return self::get('posts', $data);
	}

	public static function get_post($post_id = '')
	{
		$res = self::get("posts/$post_id");
		return ApiHelpers::prepare_api_response($res, '', []);
	}

	public static function delete_post($post_id = '')
	{
		$res = self::delete("posts/$post_id");
		return $res;
	}

	public static function create_post($post = [])
	{
		$res = self::post('posts', $post);
		return $res;
	}

	public static function get_exchange_rate_usd()
	{
		$res = self::get("exchange-rate/usd");
		return $res;
	}

	public static function upload_file($filepath)
	{
		$filename = basename($filepath);
		$content_type = mime_content_type($filepath);
		$boundary = wp_generate_password(24, false);

		$payload = "--$boundary\r\n";
		$payload .= "Content-Disposition: form-data; name=\"file\"; filename=\"$filename\"\r\n";
		$payload .= "Content-Type: $content_type\r\n";
		$payload .= "\r\n";
		$payload .= file_get_contents($filepath);
		$payload .= "\r\n--${boundary}--\r\n";

		$params = [
			'method' => 'POST',
			'headers' => [
				'Content-Type' => "multipart/form-data; boundary=$boundary",
			],
			'body' => $payload,
		];
		self::$_api_log_enabled = false;
		$res = self::call('files', $params);
		self::$_api_log_enabled = true;
		if (!empty($res['error'])) {
			return $res;
		}
		$file_id = $res['response']['file_id'];

		$res = self::get("files/$file_id/status");
		if (!empty($res['response']) && $res['response']['status'] != 'uploaded') {
			return ['error' => ['msg' => 'failed file upload']];
		}

		return ['response' => ['file_id' => $file_id]];
	}

	private static function call($endpoint = '', $params = [])
	{
		if (empty($endpoint)) {
			return false;
		}
		$url = self::API_URL.$endpoint.'?lang='.self::get_locale();

		$defaults = [
			'method' => 'GET',
			'timeout' => 30,
			'redirection' => '5',
			'user-agent' => self::USER_AGENT,
		];
		if (!empty(Options::token())) {
			$defaults['headers']['Token'] = Options::token();
		}
		$args = Tools::array_merge_recursive_distinct($defaults, $params);

		$time_start = microtime(true);
		$ret = json_decode(wp_remote_retrieve_body(wp_remote_request($url, $args)), true);
		$time_secs = microtime(true) - $time_start;

		if (self::$_api_log_enabled) {
			PP::log([$endpoint, $time_secs, $args, $ret, $url]);
		}

		return $ret;
	}

	private static function get($endpoint = '', $query = [], $params = [])
	{
		if (!empty($query)) {
			$params['body'] = [
				'query' => urlencode(json_encode($query)),
			];
		}
		return self::call($endpoint, $params);
	}

	private static function post($endpoint = '', $data = [], $params = [])
	{
		$params['method'] = 'POST';
		$params['headers']['Content-Type'] = 'application/json';
		$data = mb_convert_encoding($data, 'UTF-8', 'UTF-8');
		$params['body'] = json_encode($data);
		return self::call($endpoint, $params);
	}

	private static function delete($endpoint = '', $query = [], $params = [])
	{
		$params['method'] = 'DELETE';
		if (!empty($query)) {
			$params['body'] = [
				'query' => urlencode(json_encode($query)),
			];
		}
		return self::call($endpoint, $params);
	}

	private static function get_locale()
	{
		$locale = 'en';
		$locale_splitted = explode('_', get_locale());
		if (count($locale_splitted) > 0 &&
			!empty($locale_splitted[0]) &&
			strlen($locale_splitted[0]) == 2
		) {
			$locale = $locale_splitted[0];
		}
		return $locale;
	}
}
