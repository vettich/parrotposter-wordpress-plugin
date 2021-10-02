<?php

use parrotposter\FormHelpers;
use parrotposter\Options;
use parrotposter\Api;
use parrotposter\Tools;

if (!current_user_can('manage_options')) {
	return;
}

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'user';
if (!in_array($tab, ['user', 'tariffs'])) {
	wp_redirect(esc_url_raw('admin.php?page=parrotposter'));
	exit;
}

$active_tab = function ($tab, $cond) {
	echo $tab == $cond ? 'nav-tab-active' : '';
};

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
}

if ($tab == 'tariffs') {
	$res = Api::list_tariffs();
	$tariffs = $res['response']['tariffs'];
	$currentTariff = null;
	$otherTariffs = [];
	$balans = 0;
	$lang = Tools::get_current_lang();
	foreach ($tariffs as $t) {
		if (isset($t['translates'][$lang])) {
			$t['name'] = $t['translates'][$lang]['name'];
		}
		if ($user['tariff']['id'] == $t['id']) {
			$currentTariff = $t;
			$expiry_at = strtotime($user['tariff']['expiry_at']);
			$leftTime = $expiry_at - strtotime('now');
			$expired = $leftTime < 0;
			if ($expired) {
				continue;
			}
			$diff = strtotime('now +1 month') - strtotime('now');
			$pricePerTime = $t['price'] / $diff;
			$balans = $pricePerTime * $leftTime;
		} else {
			$otherTariffs[] = $t;
		}
	}
}

function parrotposter_calc_amount($period, $price)
{
	$percent = ($period - 1) * 2;
	if ($percent > 30) {
		$percent = 30;
	}
	$full = $period * $price / 100;
	$amount = [];
	$amount['value'] = $full - ($full * $percent / 100);
	$amount['saving'] = $full - $amount['value'];
	return $amount;
}
?>

<div class="wrap">
	<h1><?php parrotposter_e('ParrotPoster') ?></h1>

	<?php if (!empty($error_msg)): ?>
		<div class="notice notice-error">
			<p><?php echo $error_msg ?></p>
		</div>
	<?php endif ?>

	<nav class="nav-tab-wrapper">
		<a href="?page=parrotposter" class="nav-tab <?php $active_tab($tab, 'user')?>"><?php parrotposter_e('Profile') ?></a>
		<a href="?page=parrotposter&tab=tariffs" class="nav-tab <?php $active_tab($tab, 'tariffs')?>"><?php parrotposter_e('Tariffs') ?></a>
	</nav>

	<div class="tab-content">
		<br>

		<?php if ($tab == 'user'): ?>
			<?php if (!empty($user['name'])): ?>
				<p><?php parrotposter_e('Name: %s', $user['name']) ?></p>
			<?php endif ?>
			<p><?php parrotposter_e('Email: %s', $user['username']) ?></p>
			<p><?php parrotposter_e('Current tariff: %s (%s/%s accounts)', $tariff['name'], $user['tariff_limits']['accounts_current_cnt'], $user['tariff_limits']['accounts_cnt']) ?></p>
			<p><?php parrotposter_e('Expiry at %s', wp_date(get_option('date_format'), strtotime($user['tariff']['expiry_at']))) ?></p>

			<p>
				<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
					<?php FormHelpers::the_nonce() ?>
					<input type="hidden" name="action" value="parrotposter_logout">
					<input type="hidden" name="back_url" value="admin.php?page=parrotposter">
					<input class="button button-primary" type="submit" name="submit" value="<?php parrotposter_e('Logout') ?>">
				</form>
			</p>
		<?php endif ?>

		<?php if ($tab == 'tariffs'): ?>

			<p>
				<?php parrotposter_e('You can change the tariff and pay through the web-application by following the link') ?>:
				<a href="https://parrotposter.com/app/#/tariffs">parrotposter.com/app/#/tariffs</a>
			</p>

			<?php /*
			<?php if (!empty($currentTariff)): ?>
			<h2><?php parrotposter_e('Prolong tariff') ?></h2>
			<div class="parrotposter_tariffs_list">
				<div class="parrotposter_tariffs_item">
					<h3 class="parrotposter_tariff_title">
						<?php echo $currentTariff['name'] ?>
						(<?php parrotposter_e('Expiry at %s', wp_date(get_option('date_format'), strtotime($user['tariff']['expiry_at']))) ?>)
					</h3>

					<p>
						<b><?php parrotposter_e('Posts count:') ?></b>
						<?php parrotposter_e('unlimited') ?>
					</p>
					<p>
						<b><?php parrotposter_e('Accounts count:') ?></b>
						<?php echo $currentTariff['limits']['accounts_cnt'] ?>
					</p>

					<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
						<?php FormHelpers::the_nonce() ?>
						<input type="hidden" name="parrotposter[tariff_id]" value="<?php echo $currentTariff['id']?>" />
						<input type="hidden" name="action" value="parrotposter_create_transaction" />
						<input type="hidden" name="back_url" value="admin.php?page=parrotposter&tab=tariffs">
						<?php foreach ([1, 3, 6, 12] as $period): ?>
							<p>
								<label>
									<?php $amount = parrotposter_calc_amount($period, $currentTariff['price']) ?>
									<?php $checked = $period == 1 ? 'checked="checked"' : '' ?>
									<input name="period" value="<?php echo $period?>" type="radio" <?php echo $checked ?> />
									<?php parrotposter_e('%d month(s)', $period) ?>:
									<?php if ($period == 1): parrotposter_e('%d roubles', $amount['value']) ?>
									<?php else: parrotposter_e('%d roubles (saving %d roubles)', $amount['value'], $amount['saving']) ?>
									<?php endif ?>
								</label>
							</p>
						<?php endforeach ?>
						<button class="adm-btn parrotposter_tariff_btn" type="submit"><?php parrotposter_e('Submit') ?></button>
					</form>
				</div>
				<br/>
				<br/>
				<h2><?php parrotposter_e('Switch tariffs') ?></h2>
			<?php endif ?>

			<div class="parrotposter_tariffs_list">
				<?php foreach ($otherTariffs as $tariff): ?>
				<div class="parrotposter_tariffs_item">
					<h3 class="parrotposter_tariff_title">
						<?php echo $tariff['name']?>
					</h3>

					<p>
						<b><?php parrotposter_e('Posts count:') ?></b>
						<?php parrotposter_e('unlimited') ?>
					</p>
					<p>
						<b><?php parrotposter_e('Accounts count:') ?></b>
						<?php echo $tariff['limits']['accounts_cnt'] ?>
					</p>
					<p>
						<b><?php parrotposter_e('ID:') ?></b>
						<?php echo $tariff['id'] ?>
					</p>

					<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
						<?php FormHelpers::the_nonce() ?>
						<input type="hidden" name="back_url" value="admin.php?page=parrotposter&tab=tariffs">
						<input type="hidden" name="parrotposter[tariff_id]" value="<?php echo $tariff['id'] ?>" />

						<?php if ($balans > 0): ?>
							<?php $diff = strtotime('now +1 month') - strtotime('now') ?>
							<?php $pricePerTime = $tariff['price'] / $diff ?>
							<?php $leftTime = (int) ($balans / $pricePerTime) ?>
							<input type="hidden" name="action" value="parrotposter_set_tariff" />
							<?php parrotposter_e('Free up to %s', wp_date(get_option('date_format'), strtotime('now') + $leftTime)) ?>
							<button class="adm-btn" type="submit"><?php parrotposter_e('Select tariff') ?></button>
						<?php else: ?>
							<input type="hidden" name="action" value="parrotposter_create_transaction" />
						<?php endif ?>

						<?php foreach ([1, 3, 6, 12] as $period): ?>
							<p>
								<label>
									<?php $amount = parrotposter_calc_amount($period, $tariff['price']) ?>
									<?php $checked = $period == 1 ? 'checked="checked"' : '' ?>
									<input name="period" value="<?php echo $period?>" type="radio" <?php echo $checked ?> />
									<?php parrotposter_e('%d month(s)', $period) ?>:
									<?php if ($period == 1): parrotposter_e('%d roubles', $amount['value']) ?>
									<?php else: parrotposter_e('%d roubles (saving %d roubles)', $amount['value'], $amount['saving']) ?>
									<?php endif ?>
								</label>
							</p>
						<?php endforeach ?>

						<?php if ($balans > 0): ?>
							<input type="submit" disabled="disabled" value="<?php parrotposter_e('Submit')?>" />
						<?php else: ?>
							<input type="submit" value="<?php parrotposter_e('Submit')?>" />
						<?php endif ?>
					</form>
				</div>
				<?php endforeach ?>
			</div>

			<style>
				.parrotposter_tariffs_list {
					width: 100%;
				}
				.parrotposter_tariffs_item {
					display: inline-block;
					width: 30%;
					padding-left: 2em;
				}
				.parrotposter_tariffs_item:not(:first-child){
					border-left:1px solid #9d9d9d;
				}
				.parrotposter_tariff_btn {
					display: block;
					margin: 0 auto;
				}
			</style>
		*/ ?>

		<?php endif ?>

</div>
