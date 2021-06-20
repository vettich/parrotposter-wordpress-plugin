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

$tab   = isset($_GET['tab']) ? $_GET['tab'] : 'auth';
$active_tab = function ($tab, $cond) {
	return $tab == $cond ? 'nav-tab-active' : '';
};

$data = $_GET['parrotposter_data'];
?>

<div class="wrap">
	<h1><?php ParrotPoster::_e('Authorization') ?></h1>

	<?php if ($data && $data['error']): ?>
		<div class="notice notice-error">
			<p><?php echo $data['error']['msg'] ?></p>
		</div>
	<?php endif ?>

	<nav class="nav-tab-wrapper">
		<a href="?page=parrotposter" class="nav-tab <?=$active_tab($tab, 'auth')?>"><?php ParrotPoster::_e('Authorization') ?></a>
		<a href="?page=parrotposter&tab=signup" class="nav-tab <?=$active_tab($tab, 'signup')?>"><?php ParrotPoster::_e('Sign up') ?></a>
		<a href="?page=parrotposter&tab=forgot" class="nav-tab <?=$active_tab($tab, 'forgot')?>"><?php ParrotPoster::_e('Forgot password?') ?></a>
	</nav>

	<div class="tab-content">
		<br>

		<?php if ($tab == 'auth'): ?>
			<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
				<?php FormHelpers::the_nonce() ?>
				<input type="hidden" name="action" value="parrotposter_auth">
				<input type="hidden" name="back_url" value="admin.php?page=parrotposter">

				<p>
					<label for="parrotposter_username"><?php ParrotPoster::_e('Email') ?></label>
					<br>
					<input id="parrotposter_username" type="text" name="parrotposter[username]">
				</p>

				<p>
					<label for="parrotposter_password"><?php ParrotPoster::_e('Password') ?></label>
					<br>
					<input id="parrotposter_password" type="password" name="parrotposter[password]">
				</p>

				<p>
					<input class="button button-primary" type="submit" name="submit" value="<?php ParrotPoster::_e('Login') ?>">
				</p>
			</form>
		<?php endif ?>

		<?php if ($tab == 'signup'): ?>
			<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
				<?php FormHelpers::the_nonce() ?>
				<input type="hidden" name="action" value="parrotposter_signup">
				<input type="hidden" name="back_url" value="admin.php?page=parrotposter&tab=signup">

				<p>
					<label for="parrotposter_name"><?php ParrotPoster::_e('Your name') ?></label>
					<br>
					<input id="parrotposter_name" type="text" name="parrotposter[name]">
				</p>

				<p>
					<label for="parrotposter_username"><?php ParrotPoster::_e('Email') ?></label>
					<br>
					<input id="parrotposter_username" type="text" name="parrotposter[username]">
				</p>

				<p>
					<label for="parrotposter_password"><?php ParrotPoster::_e('Password') ?></label>
					<br>
					<input id="parrotposter_password" type="password" name="parrotposter[password]">
				</p>

				<p>
					<label for="parrotposter_confirm_password"><?php ParrotPoster::_e('Confirm password') ?></label>
					<br>
					<input id="parrotposter_confirm_password" type="password" name="parrotposter[confirm_password]">
				</p>

				<p>
					<input class="button button-primary" type="submit" name="submit" value="<?php ParrotPoster::_e('Sign up') ?>">
				</p>
			</form>
		<?php endif ?>

		<?php if ($tab == 'forgot'): ?>
			<p>Забыли пароль?</p>
		<?php endif ?>
	</div>
</div>
