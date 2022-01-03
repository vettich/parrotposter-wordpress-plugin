<?php

use parrotposter\PP;
use parrotposter\FormHelpers;
use parrotposter\AssetModules;
use parrotposter\Profile;

AssetModules::enqueue(['block']);

if (!defined('ABSPATH')) {
	die;
}

$profile = Profile::get_info();
if (!empty($profile['user'])) {
	$accounts_cur_cnt = $profile['user']['tariff_limits']['accounts_current_cnt'];
	$accounts_cnt = $profile['user']['tariff_limits']['accounts_cnt'];
}

?>

<?php PP::include_view('header', ['title' => __('Profile', 'parrotposter')]) ?>

<?php if (!empty($profile['error'])): ?>
	<div class="notice notice-error">
		<p><?php echo esc_attr(is_array($profile['error']) ? $profile['error']['msg'] : $profile['error']) ?></p>
	</div>
<?php endif ?>

<div class="parrotposter-block parrotposter-block--min">
	<?php if (!empty($profile['user']['name'])): ?>
		<div class="parrotposter-block__group">
			<div class="parrotposter-block__label"><?php _e('Your name', 'parrotposter') ?></div>
			<div class="parrotposter-block__value"><?php echo esc_attr($profile['user']['name']) ?></div>
		</div>
	<?php endif ?>

	<div class="parrotposter-block__group">
		<div class="parrotposter-block__label"><?php _e('Email', 'parrotposter') ?></div>
		<div class="parrotposter-block__value"><?php echo esc_attr($profile['user']['username']) ?></div>
	</div>

	<div class="parrotposter-block__group">
		<div class="parrotposter-block__label"><?php _e('Tariff', 'parrotposter') ?></div>
		<div class="parrotposter-block__value"><?php echo esc_attr($profile['tariff']['name']) ?></div>
	</div>

	<div class="parrotposter-block__group">
		<div class="parrotposter-block__label"><?php _e('Accounts', 'parrotposter') ?></div>
		<div class="parrotposter-block__value"><?php printf(__('%1$d of %2$d', 'parrotposter'), $accounts_cur_cnt, $accounts_cnt) ?></div>
	</div>

	<div class="parrotposter-block__group">
		<div class="parrotposter-block__label"><?php _e('Expiry at', 'parrotposter') ?></div>
		<div class="parrotposter-block__value">
			<?php echo wp_date(get_option('date_format'), strtotime($profile['user']['tariff']['expiry_at'])) ?>
			<?php echo esc_attr($profile['left']) ?>
		</div>
	</div>

	<div class="parrotposter-block__group">
		<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
			<?php FormHelpers::the_nonce() ?>
			<input type="hidden" name="action" value="parrotposter_logout">
			<input type="hidden" name="back_url" value="admin.php?page=parrotposter">
			<input class="button button-secondary" type="submit" name="submit" value="<?php _e('Logout', 'parrotposter') ?>">
		</form>
	</div>
</div>
