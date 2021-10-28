<?php
if (!defined('ABSPATH')) {
	die;
}

use parrotposter\Api;
use parrotposter\Tools;
use parrotposter\AssetModules;

AssetModules::enqueue(['accounts']);

$res = Api::me();
$user = $res['response'] ?: [];
if (isset($res['error'])) {
	$error_msg = $res['error']['msg'];
}

$accounts_cur_cnt = $user['tariff_limits']['accounts_current_cnt'];
$accounts_cnt = $user['tariff_limits']['accounts_cnt'];

$connect_disabled = false;
if ($accounts_cur_cnt >= $accounts_cnt) {
	$connect_disabled = true;
}

$accounts_res = Api::list_accounts();
$accounts = parrotposter\ApiHelpers::retrieve_response($accounts_res, 'accounts');
$accounts = $accounts ?: [];
$accounts = parrotposter\ApiHelpers::fix_accounts_photos($accounts);

$connect_btn_args = ['connect_disabled' => $connect_disabled];
?>

<?php ParrotPoster::include_view('header') ?>

<div class="parrotposter-accounts__header">
	<h1><?php parrotposter_e('Social networks accounts') ?></h1>

	<div class="parrotposter-accounts__badge <?php echo $connect_disabled ? 'over' : '' ?>">
		<span class="parrotposter-accounts__badge-txt">
			<?php parrotposter_e('Added %s of %s.', $accounts_cur_cnt, $accounts_cnt) ?>
		</span>
		<a href="admin.php?page=parrotposter_tariffs"><?php parrotposter_e('Change tariff') ?></a>
	</div>
</div>

<hr class="wp-header-end">

<?php if (empty($accounts)): ?>

	<div class="parrotposter-empty-block">
		<div class="parrotposter-empty-block__label">
			<?php parrotposter_e('You haven\'t connected your social networks accounts yet') ?>
		</div>

		<?php ParrotPoster::include_view('accounts/connect', $connect_btn_args) ?>
	</div>

<?php else: ?>

	<p>
		<?php ParrotPoster::include_view('accounts/connect', $connect_btn_args) ?>
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

<?php endif ?>
