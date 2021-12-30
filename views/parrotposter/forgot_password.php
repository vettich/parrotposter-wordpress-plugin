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
	<a href="?page=parrotposter" class="parrotposter-nav-tab__item"><?php parrotposter_e('Authorization') ?></a>
	<a href="?page=parrotposter&view=signup" class="parrotposter-nav-tab__item"><?php parrotposter_e('Registration') ?></a>
	<a href="?page=parrotposter&view=forgot_password" class="parrotposter-nav-tab__item active"><?php parrotposter_e('Forgot password?') ?></a>
</nav>

<div class="parrotposter-block parrotposter-block--min">
	<h2><?php parrotposter_e('Forgot password?') ?></h2>

	<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
		<?php FormHelpers::the_nonce() ?>
		<input type="hidden" name="action" value="parrotposter_forgot_password">
		<input type="hidden" name="back_url" value="admin.php?page=parrotposter&view=forgot_password">

		<div class="parrotposter-input__group">
			<label class="parrotposter-input">
				<span><?php parrotposter_e('Email') ?></span>
				<input type="email" name="parrotposter[username]">
			</label>

			<div class="parrotposter-input parrotposter-input--footer">
				<input class="button button-primary" type="submit" name="submit" value="<?php parrotposter_e('Send') ?>">
			</div>
		</div>
	</form>
</div>