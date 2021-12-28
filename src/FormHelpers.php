<?php

namespace parrotposter;

defined('ABSPATH') || exit;

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

	public static function post_success($data = '', $success_url = '')
	{
		if (isset($_POST['ajaxrequest']) && $_POST['ajaxrequest'] === 'true') {
			echo json_encode([
				'data' => $data,
			]);
			exit;
		}

		$redirect_url = '';
		if (!empty($success_url)) {
			$redirect_url = $success_url;
		} elseif (isset($_POST['success_url'])) {
			$redirect_url = esc_url_raw($_POST['success_url']);
		} elseif (isset($_POST['back_url'])) {
			$redirect_url = esc_url_raw($_POST['back_url']);
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

		$back_url = esc_url_raw($_POST['back_url']);
		if (empty($back_url)) {
			$back_url = 'admin.php?page=parrotposter';
		}
		$args = [
			'parrotposter_error_msg' => is_array($msg) && isset($msg['msg']) ? $msg['msg'] : $msg,
		];
		wp_redirect((add_query_arg($args, $back_url)));
		exit;
	}

	public static function prepare_data_values($data_keys, $values)
	{
		$data = [];
		$format = [];

		foreach ($data_keys as $key => $format_v) {
			$value_type = 'text';
			$value_options = [];
			if (strpos($key, ':') !== false) {
				$s = explode(':', $key, 3);
				$key = $s[0];
				$value_type = $s[1];
				if (isset($s[2])) {
					$s = explode(':', $s[2]);
					foreach ($s as $t) {
						$tt = explode('=', $t);
						if (count($tt) == 1) {
							$value_options[$tt[0]] = true;
						} elseif (count($tt) == 2) {
							$value_options[$tt[0]] = $tt[1];
						}
					}
				}
			}

			if (!isset($values[$key]) && !$value_options['required']) {
				continue;
			}

			switch ($value_type) {
			case 'text':
			case 'textarea':
				if ($value_type == 'text') {
					$data[$key] = trim(sanitize_text_field($values[$key]));
				} else {
					$data[$key] = trim(sanitize_textarea_field($values[$key]));
				}
				if (isset($value_options['limit'])) {
					$data[$key] = substr($data[$key], 0, $value_options['limit']);
				}
				break;

			case 'number':
				$data[$key] = intval($values[$key]);
				if (isset($value_options['min']) && $data[$key] < intval($value_options['min'])) {
					$data[$key] = intval($value_options['min']);
				}
				if (isset($value_options['max']) && $data[$key] > intval($value_options['max'])) {
					$data[$key] = intval($value_options['max']);
				}
				break;

			case 'url':
				$data[$key] = trim(esc_url_raw($values[$key]));
				break;

			case 'text_array':
				$data[$key] = [];
				foreach ($values[$key] as $v) {
					$v = sanitize_text_field($v);
					if (isset($value_options['remove_empty']) && empty($v)) {
						continue;
					}
					$data[$key][] = $v;
				}
				break;

			case 'raw':
				$data[$key] = $values[$key];
				break;
			}

			$format[] = $format_v;
		}

		return [$data, $format];
	}

	public static function render_checkbox($name, $value, $js_onclick = '')
	{
		?>
			<input type="hidden" name="<?php echo $name ?>" value="0">
			<input type="checkbox" name="<?php echo $name ?>" value="1"
				<?php echo($value ? 'checked="checked"' : '') ?>
				onclick="<?php echo $js_onclick ?>">
		<?php
	}
}
