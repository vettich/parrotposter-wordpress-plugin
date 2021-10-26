<?php

namespace parrotposter;

class FormHelpers
{
	public static function the_nonce()
	{
		$nonce = wp_create_nonce('parrotposter_nonce'); ?>
		<input type="hidden" name="parrotposter[nonce]" value="<?php echo $nonce ?>">
		<?php
	}

	public static function must_be_right_input_data()
	{
		if (!is_array($_POST['parrotposter'])) {
			self::post_error('wrong input data');
		}
	}

	public static function check_post_nonce()
	{
		if (!isset($_POST['parrotposter']['nonce'])) {
			return false;
		}
		$isCorrect = (int) wp_verify_nonce($_POST['parrotposter']['nonce'], 'parrotposter_nonce') > 0;
		return $isCorrect;
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

		$redirect_url = '';
		if (isset($_POST['success_url'])) {
			$redirect_url = sanitize_url($_POST['success_url']);
		} elseif (isset($_POST['back_url'])) {
			$redirect_url = sanitize_url($_POST['back_url']);
		}
		if (empty($redirect_url)) {
			$redirect_url = 'admin.php?page=parrotposter';
		}
		$args = [];
		if (!empty($data)) {
			$args['parrotposter_success_data'] = $data;
		}
		wp_redirect(esc_url_raw(add_query_arg($args, $redirect_url)));
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
			'parrotposter_error_msg' => is_array($msg) && isset($msg['msg']) ? $msg['msg'] : $msg,
		];
		wp_redirect((add_query_arg($args, $back_url)));
		exit;
	}
}
