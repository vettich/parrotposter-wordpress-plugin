<?php

use parrotposter\PP;
use parrotposter\Api;
use parrotposter\Tools;
use parrotposter\FormHelpers;
use parrotposter\AssetModules;

if (!defined('ABSPATH')) {
	die;
}

AssetModules::enqueue(['block', 'input', 'nav-tab']);

?>

<?php PP::include_view('header') ?>
<?php PP::include_view('notice') ?>

<nav class="parrotposter-nav-tab__wrapper">
	<a href="?page=parrotposter" class="parrotposter-nav-tab__item"><?php _e('Authorization', 'parrotposter') ?></a>
	<a href="?page=parrotposter&view=signup" class="parrotposter-nav-tab__item active"><?php _e('Registration', 'parrotposter') ?></a>
	<a href="?page=parrotposter&view=forgot_password" class="parrotposter-nav-tab__item"><?php _e('Forgot password?', 'parrotposter') ?></a>
</nav>

<div class="parrotposter-block parrotposter-block--min">
	<h2><?php _e('Registration', 'parrotposter') ?></h2>

	<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
		<?php FormHelpers::the_nonce() ?>
		<input type="hidden" name="action" value="parrotposter_signup">
		<input type="hidden" name="back_url" value="admin.php?page=parrotposter&view=signup">
		<input type="hidden" name="success_url" value="admin.php?page=parrotposter_accounts">

		<div class="parrotposter-input__group">
			<label class="parrotposter-input">
				<span><?php _e('Your name', 'parrotposter') ?></span>
				<input type="text" name="parrotposter[name]">
			</label>

			<label class="parrotposter-input">
				<span><?php _e('Email', 'parrotposter') ?></span>
				<input type="email" name="parrotposter[username]">
			</label>

			<label class="parrotposter-input">
				<span><?php _e('Password', 'parrotposter') ?></span>
				<input type="password" name="parrotposter[password]">
			</label>

			<label class="parrotposter-input">
				<span><?php _e('Confirm password', 'parrotposter') ?></span>
				<input type="password" name="parrotposter[confirm_password]">
			</label>

			<div class="parrotposter-input parrotposter-input--footer">
				<input class="button button-primary" type="submit" name="submit" value="<?php _e('Sign up', 'parrotposter') ?>">
			</div>
		</div>
	</form>
</div>
