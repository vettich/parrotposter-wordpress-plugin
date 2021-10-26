<?php

if (!defined('ABSPATH')) {
	die;
}

if (isset($_GET['parrotposter_error_msg'])) {
	$error_msg = sanitize_text_field($_GET['parrotposter_error_msg']);
}

if (isset($_GET['parrotposter_success_data'])) {
	$success_msg = sanitize_text_field($_GET['parrotposter_success_data']);
}

?>

<?php if (!empty($error_msg)): ?>
	<div class="notice notice-error">
		<p>
			<?php echo esc_attr(is_array($error_msg) ? $error_msg['msg'] : $error_msg) ?>
		</p>
	</div>
<?php endif ?>

<?php if (!empty($success_msg)): ?>
	<div class="notice notice-success">
		<p><?php echo esc_attr($success_msg) ?></p>
	</div>
<?php endif ?>

