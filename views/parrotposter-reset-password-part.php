<?php

use parrotposter\FormHelpers;
use parrotposter\Options;

if (!current_user_can('manage_options')) {
	return;
}

if (empty($_GET['parrotposter_token'])) {
	wp_redirect(esc_url_raw('admin.php?page=parrotposter'));
	exit;
}

if (isset($_GET['parrotposter_error_msg'])) {
	$error_msg = $_GET['parrotposter_error_msg'];
}

if (isset($_GET['parrotposter_success_data'])) {
	wp_redirect(esc_url_raw('admin.php?page=parrotposter'));
	exit;
}

$back_url = add_query_arg([
	'page' => 'parrotposter',
	'subpage' => 'reset_password',
	'parrotposter_token' => $_GET['parrotposter_token'],
], 'admin.php');
?>

<div class="wrap">
	<h1><?php parrotposter_e('Reset password') ?></h1>

	<?php if (!empty($error_msg)): ?>
		<div class="notice notice-error">
			<p><?php echo $error_msg ?></p>
		</div>
	<?php endif ?>

	<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
		<?php FormHelpers::the_nonce() ?>
		<input type="hidden" name="action" value="parrotposter_reset_password">
		<input type="hidden" name="back_url" value="<?php echo $back_url ?>">
		<input type="hidden" name="parrotposter[token]" value="<?php echo $_GET['parrotposter_token'] ?>">

		<p>
			<label for="parrotposter_password"><?php parrotposter_e('New password') ?></label>
			<br>
			<input id="parrotposter_password" type="password" name="parrotposter[password]">
		</p>

		<p>
			<label for="parrotposter_confirm_password"><?php parrotposter_e('Confirm password') ?></label>
			<br>
			<input id="parrotposter_confirm_password" type="password" name="parrotposter[confirm_password]">
		</p>

		<p>
			<input class="button button-primary" type="submit" name="submit" value="<?php parrotposter_e('Submit') ?>">
		</p>
	</form>

</div>
