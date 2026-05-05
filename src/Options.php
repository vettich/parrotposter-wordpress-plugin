<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * Настройки плагина в wp_options.
 * Токен API хранится в зашифрованном виде (AES-256-CBC, ключ из соль-констант WP).
 * Старые plaintext-значения при чтении мигрируют в шифротекст.
 */
class Options
{
	const USER_ID_KEY = 'parrotposter_user_id';
	const TOKEN_KEY = 'parrotposter_token';
	const TOKEN_CIPHER_PREFIX = 'PP1:';

	public static function user_id()
	{
		return get_option(self::USER_ID_KEY);
	}

	public static function token()
	{
		$raw = get_option(self::TOKEN_KEY, '');
		if ($raw === false || $raw === null || $raw === '') {
			return '';
		}
		if (!is_string($raw)) {
			return '';
		}
		if (self::token_stored_is_encrypted($raw)) {
			$plain = self::decrypt_stored_token($raw);
			if ($plain === false) {
				return '';
			}
			return $plain;
		}
		self::migrate_plain_token_to_encrypted($raw);

		return $raw;
	}

	public static function set_user_data($userId, $token)
	{
		update_option(self::USER_ID_KEY, $userId);
		if ($token === null || $token === '') {
			update_option(self::TOKEN_KEY, '');
			return;
		}
		if (!is_string($token)) {
			update_option(self::TOKEN_KEY, '');
			return;
		}
		$blob = self::encrypt_token_for_storage($token);
		update_option(self::TOKEN_KEY, $blob !== false ? $blob : $token);
	}

	public static function delete_data()
	{
		delete_option(self::USER_ID_KEY);
		delete_option(self::TOKEN_KEY);
	}

	private static function token_stored_is_encrypted($raw)
	{
		$prefix = self::TOKEN_CIPHER_PREFIX;

		return is_string($raw) && strncmp($raw, $prefix, strlen($prefix)) === 0;
	}

	/**
	 * 32 байта для AES-256; привязка к соль-константам wp-config.php.
	 */
	private static function get_token_cipher_key()
	{
		$material = 'parrotposter/wp-token/v1';
		if (function_exists('wp_salt')) {
			$material .= wp_salt('auth') . wp_salt('secure_auth');
		}

		return hash('sha256', $material, true);
	}

	/**
	 * @return string|false префикс + base64(iv || ciphertext)
	 */
	private static function encrypt_token_for_storage($plaintext)
	{
		if (!function_exists('openssl_encrypt') || !function_exists('openssl_cipher_iv_length')) {
			return false;
		}
		$method = 'aes-256-cbc';
		if (!in_array($method, openssl_get_cipher_methods(), true)) {
			return false;
		}
		$key = self::get_token_cipher_key();
		$ivlen = openssl_cipher_iv_length($method);
		$iv = openssl_random_pseudo_bytes($ivlen);
		if ($iv === false) {
			return false;
		}
		$cipher = openssl_encrypt($plaintext, $method, $key, OPENSSL_RAW_DATA, $iv);
		if ($cipher === false) {
			return false;
		}

		return self::TOKEN_CIPHER_PREFIX . base64_encode($iv . $cipher);
	}

	/**
	 * @return string|false расшифрованный токен
	 */
	private static function decrypt_stored_token($blob)
	{
		$payload = substr($blob, strlen(self::TOKEN_CIPHER_PREFIX));
		$raw = base64_decode($payload, true);
		if ($raw === false || $raw === '') {
			return false;
		}
		if (!function_exists('openssl_decrypt') || !function_exists('openssl_cipher_iv_length')) {
			return false;
		}
		$method = 'aes-256-cbc';
		$key = self::get_token_cipher_key();
		$ivlen = openssl_cipher_iv_length($method);
		if (strlen($raw) < $ivlen + 1) {
			return false;
		}
		$iv = substr($raw, 0, $ivlen);
		$ciphertext = substr($raw, $ivlen);
		$plain = openssl_decrypt($ciphertext, $method, $key, OPENSSL_RAW_DATA, $iv);
		if ($plain === false) {
			return false;
		}

		return $plain;
	}

	private static function migrate_plain_token_to_encrypted($plaintext)
	{
		$blob = self::encrypt_token_for_storage($plaintext);
		if ($blob !== false) {
			update_option(self::TOKEN_KEY, $blob);
		}
	}
}
