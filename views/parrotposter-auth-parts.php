<?php

use parrotposter\FormHelpers;
use parrotposter\Options;

if (!current_user_can('manage_options')) {
	return;
}

if (!empty(Options::user_id())) {
	wp_redirect(esc_url_raw('admin.php?page=parrotposter'));
	exit;
}

$tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'auth';
$active_tab = function ($tab, $cond) {
	echo $tab == $cond ? 'nav-tab-active' : '';
};

if (isset($_GET['parrotposter_error_msg'])) {
	$error_msg = sanitize_text_field($_GET['parrotposter_error_msg']);
}

if (isset($_GET['parrotposter_success_data'])) {
	$success_msg = sanitize_text_field($_GET['parrotposter_success_data']);
}

?>

<div class="wrap">
	<?php if ($tab == 'auth'): ?>
		<h1><?php parrotposter_e('Authorization') ?></h1>
	<?php elseif ($tab == 'signup'): ?>
		<h1><?php parrotposter_e('Registration') ?></h1>
	<?php elseif ($tab == 'forgot_password'): ?>
		<h1><?php parrotposter_e('Reset password') ?></h1>
	<?php endif ?>

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

	<nav class="nav-tab-wrapper">
		<a href="?page=parrotposter" class="nav-tab <?php $active_tab($tab, 'auth')?>"><?php parrotposter_e('Authorization') ?></a>
		<a href="?page=parrotposter&tab=signup" class="nav-tab <?php $active_tab($tab, 'signup')?>"><?php parrotposter_e('Registration') ?></a>
		<a href="?page=parrotposter&tab=forgot_password" class="nav-tab <?php $active_tab($tab, 'forgot_password')?>"><?php parrotposter_e('Forgot password?') ?></a>
	</nav>

	<div class="tab-content">
		<br>

		<?php if ($tab == 'auth'): ?>
			<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
				<?php FormHelpers::the_nonce() ?>
				<input type="hidden" name="action" value="parrotposter_auth">
				<input type="hidden" name="back_url" value="admin.php?page=parrotposter">

				<p>
					<label for="parrotposter_username"><?php parrotposter_e('Email') ?></label>
					<br>
					<input id="parrotposter_username" type="text" name="parrotposter[username]">
				</p>

				<p>
					<label for="parrotposter_password"><?php parrotposter_e('Password') ?></label>
					<br>
					<input id="parrotposter_password" type="password" name="parrotposter[password]">
				</p>

				<p>
					<input class="button button-primary" type="submit" name="submit" value="<?php parrotposter_e('Login') ?>">
				</p>
			</form>
		<?php endif ?>

		<?php if ($tab == 'signup'): ?>
			<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
				<?php FormHelpers::the_nonce() ?>
				<input type="hidden" name="action" value="parrotposter_signup">
				<input type="hidden" name="back_url" value="admin.php?page=parrotposter&tab=signup">

				<p>
					<label for="parrotposter_name"><?php parrotposter_e('Your name') ?></label>
					<br>
					<input id="parrotposter_name" type="text" name="parrotposter[name]">
				</p>

				<p>
					<label for="parrotposter_username"><?php parrotposter_e('Email') ?></label>
					<br>
					<input id="parrotposter_username" type="text" name="parrotposter[username]">
				</p>

				<p>
					<label for="parrotposter_password"><?php parrotposter_e('Password') ?></label>
					<br>
					<input id="parrotposter_password" type="password" name="parrotposter[password]">
				</p>

				<p>
					<label for="parrotposter_confirm_password"><?php parrotposter_e('Confirm password') ?></label>
					<br>
					<input id="parrotposter_confirm_password" type="password" name="parrotposter[confirm_password]">
				</p>

				<p>
					<input class="button button-primary" type="submit" name="submit" value="<?php parrotposter_e('Sign up') ?>">
				</p>
			</form>
		<?php endif ?>

		<?php if ($tab == 'forgot_password'): ?>
			<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
				<?php FormHelpers::the_nonce() ?>
				<input type="hidden" name="action" value="parrotposter_forgot_password">
				<input type="hidden" name="back_url" value="admin.php?page=parrotposter&tab=forgot_password">

				<p>
					<label for="parrotposter_username"><?php parrotposter_e('Email') ?></label>
					<br>
					<input id="parrotposter_username" type="text" name="parrotposter[username]">
				</p>

				<p>
					<input class="button button-primary" type="submit" name="submit" value="<?php parrotposter_e('Send') ?>">
				</p>
			</form>
		<?php endif ?>
	</div>
</div>
