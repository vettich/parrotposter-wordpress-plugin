<?php

namespace parrotposter;

use ParrotPoster;

class Api {
	const API_URL = 'https://parrotposter.com/api/v1/';
	const FROM = 'wordpress';
	const USER_AGENT = 'ParrotPoster WP Plugin';

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
		return $res;
	}

	public static function get_tariff($id)
	{
		if (empty($id)) {
			return [];
		}

		$res = self::get("tariffs/$id");
		return $res;
	}

	public static function list_tariffs()
	{
		$res = self::get("tariffs");
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
			'period' => $period,
			'success_url' => $success_url,
			'fail_url' => $fail_url,
		];
		$res = self::post('transactions', $data);
		return $res;
	}

	private static function call($endpoint = '', $params = []) {
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

		$ret = json_decode(wp_remote_retrieve_body(wp_remote_request($url, $args)), true);
		ParrotPoster::log([$endpoint, $args, $ret, $url]);
		return $ret;
	}

	private static function get($endpoint = '', $query = [], $params = []) {
		if (!empty($query)) {
			$params['body'] = [
				'query' => urlencode(json_encode($query)),
			];
		}
		return self::call($endpoint, $params);
	}

	private static function post($endpoint = '', $data = [], $params = []) {
		$params['method'] = 'POST';
		$params['headers']['Content-Type'] = 'application/json';
		$params['body'] = json_encode($data);
		return self::call($endpoint, $params);
	}

	private static function delete($endpoint = '', $query = [], $params = []) {
		$params['method'] = 'DELETE';
		if (!empty($query)) {
			$params['body'] = [
				'query' => urlencode(json_encode($query)),
			];
		}
		return self::call($endpoint, $params);
	}

	private static function get_locale() {
		$locale = 'en';
		$locale_splitted = explode("_", get_locale());
		if (count($locale_splitted) > 0 &&
			!empty($locale_splitted[0]) &&
			strlen($locale_splitted[0]) == 2
		) {
			$locale = $locale_splitted[0];
		}
		return $locale;
	}
}
