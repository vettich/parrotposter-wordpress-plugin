<?php

use parrotposter\Api;
use parrotposter\Tools;
use parrotposter\FormHelpers;

if (!defined('ABSPATH')) {
	die;
}

$res = Api::me();
$user = $res['response'] ?: [];
if (isset($res['error'])) {
	$error_msg = $res['error']['msg'];
}

if (!empty($user)) {
	$res = Api::get_tariff($user['tariff']['id'] ?: $user['tariff']['code']);
	$tariff = $res['response'] ?: [];
	if (isset($res['error'])) {
		$error_msg = $res['error']['msg'];
	}
	$lang = Tools::get_current_lang();
	if (isset($tariff['translates'][$lang])) {
		$tariff['name'] = $tariff['translates'][$lang]['name'];
	}
	$accounts_cur_cnt = $user['tariff_limits']['accounts_current_cnt'];
	$accounts_cnt = $user['tariff_limits']['accounts_cnt'];

	$interval = (new \DateTime('now'))->diff(new \DateTime($user['tariff']['expiry_at']));
	if ($interval->invert) {
		$left = parrotposter__('(expired)');
	} elseif ($interval->y > 0) {
		$left = parrotposter_n('(left %s year)', '(left %s years)', $interval->y, $interval->y);
	} elseif ($interval->m > 0) {
		$left = parrotposter_n('(left %s month)', '(left %s months)', $interval->m, $interval->m);
	} elseif ($interval->d > 0) {
		$left = parrotposter_n('(left %s day)', '(left %s days)', $interval->d, $interval->d);
	}
}

?>

<?php ParrotPoster::include_view('header') ?>

<h1><?php parrotposter_e('Profile') ?></h1>

<?php if (!empty($error_msg)): ?>
	<div class="notice notice-error">
		<p><?php echo esc_attr($error_msg) ?></p>
	</div>
<?php endif ?>

<hr class="wp-header-end">

<div class="parrotposter-block mw300">
	<?php if (!empty($user['name'])): ?>
		<p>
			<div class="parrotposter-profile__label"><?php parrotposter_e('Your name') ?></div>
			<div class="parrotposter-profile__value"><?php echo esc_attr($user['name']) ?></div>
		</p>
	<?php endif ?>

	<p>
		<div class="parrotposter-profile__label"><?php parrotposter_e('Email') ?></div>
		<div class="parrotposter-profile__value"><?php echo esc_attr($user['username']) ?></div>
	</p>

	<p>
		<div class="parrotposter-profile__label"><?php parrotposter_e('Tariff') ?></div>
		<div class="parrotposter-profile__value"><?php echo esc_attr($tariff['name']) ?></div>
	</p>

	<p>
		<div class="parrotposter-profile__label"><?php parrotposter_e('Accounts') ?></div>
		<div class="parrotposter-profile__value"><?php parrotposter_e('%s of %s', $accounts_cur_cnt, $accounts_cnt) ?></div>
	</p>

	<p>
		<div class="parrotposter-profile__label"><?php parrotposter_e('Expiry at') ?></div>
		<div class="parrotposter-profile__value">
			<?php echo wp_date(get_option('date_format'), strtotime($user['tariff']['expiry_at'])) ?>
			<?php echo esc_attr($left) ?>
		</div>
	</p>

	<p>
		<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
			<?php FormHelpers::the_nonce() ?>
			<input type="hidden" name="action" value="parrotposter_logout">
			<input type="hidden" name="back_url" value="admin.php?page=parrotposter">
			<input class="button button-secondary" type="submit" name="submit" value="<?php parrotposter_e('Logout') ?>">
		</form>
	</p>
</div>
