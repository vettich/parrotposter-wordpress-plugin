<?php
if (!defined('ABSPATH')) {
	die;
}

use parrotposter\PP;
use parrotposter\Api;
use parrotposter\Tools;
use parrotposter\AssetModules;

AssetModules::enqueue(['accounts', 'block', 'loading']);

list($user, $error) = Api::me();
if (!empty($error)) {
	$error_msg = $error['msg'];
}

$accounts_cur_cnt = $user['tariff_limits']['accounts_current_cnt'];
$accounts_cnt = $user['tariff_limits']['accounts_cnt'];

$connect_disabled = false;
if ($accounts_cur_cnt >= $accounts_cnt) {
	$connect_disabled = true;
}

list($accounts) = Api::list_accounts();
$accounts = parrotposter\ApiHelpers::fix_accounts_photos($accounts);

$connect_btn_args = ['connect_disabled' => $connect_disabled];
?>

<?php PP::include_view('header') ?>

<div class="parrotposter-accounts__header">
	<h1><?php _e('Social networks accounts', 'parrotposter') ?></h1>

	<div class="parrotposter-accounts__badge <?php echo $connect_disabled ? 'over' : '' ?>">
		<span class="parrotposter-accounts__badge-txt">
			<?php printf(__('Added %1$d of %2$d.', 'parrotposter'), $accounts_cur_cnt, $accounts_cnt) ?>
		</span>
		<a href="admin.php?page=parrotposter_tariffs"><?php _e('Change tariff', 'parrotposter') ?></a>
	</div>
</div>

<?php if (empty($accounts)): ?>

	<div class="parrotposter-empty-block">
		<div class="parrotposter-empty-block__title">
			<?php _e('You haven\'t connected your social networks accounts yet', 'parrotposter') ?>
		</div>

		<div class="parrotposter-empty-block__note">
			<?php _e('To connect, you need to be the administrator of the group/page/channel and provide all accesses that ParrotPoster asks for, so that everything works correctly', 'parrotposter') ?>
		</div>

		<div class="parrotposter-empty-block__connect">
			<?php PP::include_view('accounts/connect', $connect_btn_args) ?>
		</div>
	</div>

<?php else: ?>

	<p>
		<?php PP::include_view('accounts/connect', $connect_btn_args) ?>
	</p>

	<div class="parrotposter-accounts__list">
		<?php foreach ($accounts as $item): ?>
			<div class="parrotposter-accounts__item" data-id="<?php echo esc_attr($item['id']) ?>">
				<div class="parrotposter-accounts__delete"></div>
				<div class="parrotposter-accounts__photo">
					<img src="<?php echo esc_url($item['photo']) ?>" alt="photo">
					<div class="parrotposter-accounts__type <?php echo esc_html($item['type']) ?>"></div>
				</div>
				<div class="parrotposter-accounts__name" title="<?php echo esc_html($item['name']) ?>">
					<?php echo esc_html($item['name']) ?>
				</div>
				<a class="parrotposter-accounts__link" href="<?php echo esc_url($item['link']) ?>" target="_blank">
					<?php echo esc_html(Tools::clear_account_link($item['link'])) ?>
				</a>
			</div>
		<?php endforeach ?>
	</div>

	<div id="parrotposter-confirm" class="parrotposter-modal">
		<div class="parrotposter-modal__container">
			<div class="parrotposter-modal__close"></div>
			<div class="parrotposter-modal__title">
				<?php _e('Are you sure you want to delete "#account_name#"', 'parrotposter') ?>
			</div>
			<div class="parrotposter-modal__footer">
				<button class="button button-primary"><?php _e('Delete', 'parrotposter') ?></button>
				<button class="button parrotposter-js-close"><?php _e('Cancel', 'parrotposter') ?></button>
			</div>
		</div>
	</div>

<?php endif ?>
