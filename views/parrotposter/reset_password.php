<?php

use parrotposter\PP;
use parrotposter\Api;
use parrotposter\Tools;
use parrotposter\FormHelpers;
use parrotposter\AssetModules;

if (!defined('ABSPATH')) {
	die;
}

AssetModules::enqueue(['block', 'input']);

if (empty($_GET['parrotposter_token'])) {
	wp_redirect(esc_url_raw('admin.php?page=parrotposter'));
	exit;
}

if (isset($_GET['parrotposter_success_data'])) {
	wp_redirect(esc_url_raw('admin.php?page=parrotposter'));
	exit;
}

$token = sanitize_text_field($_GET['parrotposter_token']);
$back_url = add_query_arg([
	'page' => 'parrotposter',
	'view' => 'reset_password',
	'parrotposter_token' => $token,
], 'admin.php');
?>

<?php PP::include_view('header') ?>
<?php PP::include_view('notice') ?>

<div class="parrotposter-block parrotposter-block--min">
	<h2><?php parrotposter_e('Reset password') ?></h2>

	<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
		<?php FormHelpers::the_nonce() ?>
		<input type="hidden" name="action" value="parrotposter_reset_password">
		<input type="hidden" name="back_url" value="<?php echo esc_url($back_url) ?>">
		<input type="hidden" name="parrotposter[token]" value="<?php echo esc_attr($token) ?>">

		<div class="parrotposter-input__group">
			<label class="parrotposter-input">
				<span><?php parrotposter_e('Password') ?></span>
				<input type="password" name="parrotposter[password]">
			</label>

			<label class="parrotposter-input">
				<span><?php parrotposter_e('Confirm password') ?></span>
				<input type="password" name="parrotposter[confirm_password]">
			</label>

			<div class="parrotposter-input parrotposter-input--footer">
				<input class="button button-primary" type="submit" name="submit" value="<?php parrotposter_e('Save') ?>">
			</div>
		</div>
	</form>
</div>