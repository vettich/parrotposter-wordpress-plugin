<?php

namespace parrotposter;

class FormHelpers
{
	public static function the_nonce()
	{
		?><input type="hidden" name="parrotposter_nonce" value="<?php echo wp_create_nonce('parrotposter_nonce') ?>"><?php
	}

	public static function must_be_right_input_data()
	{
		if (!is_array($_POST['parrotposter'])) {
			self::post_error('wrong input data');
		}
	}

	public static function check_post_nonce()
	{
		$issetNonce = isset($_POST['parrotposter_nonce']);
		$correctNonce = $issetNonce && (int) wp_verify_nonce($_POST['parrotposter_nonce'], 'parrotposter_nonce') > 0;
		return $correctNonce;
	}

	public static function must_be_post_nonce()
	{
		self::must_be_right_input_data();
		if (!self::check_post_nonce()) {
			self::post_error('nonce');
		}
	}

	public static function post_success($data = '')
	{
		if (isset($_POST['ajaxrequest']) && $_POST['ajaxrequest'] === 'true') {
			echo json_encode([
				'data' => $data,
			]);
			exit;
		}

		$back_url = sanitize_url($_POST['back_url']);
		if (empty($back_url)) {
			$back_url = 'admin.php?page=parrotposter';
		}
		$args = [];
		if (!empty($data)) {
			$args['parrotposter_success_data'] = $data;
		}
		wp_redirect(esc_url_raw(add_query_arg($args, $back_url)));
		exit;
	}

	public static function post_error($msg, $status = 400)
	{
		if (isset($_POST['ajaxrequest']) && $_POST['ajaxrequest'] === 'true') {
			echo json_encode([
				'error' => $msg,
			]);
			exit;
		}

		$back_url = sanitize_url($_POST['back_url']);
		if (empty($back_url)) {
			$back_url = 'admin.php?page=parrotposter';
		}
		$args = [
			'parrotposter_error_msg' => $msg,
		];
		wp_redirect(esc_url_raw(add_query_arg($args, $back_url)));
		exit;
	}
}
