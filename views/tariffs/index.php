<?php
if (!defined('ABSPATH')) {
	die;
}

use parrotposter\PP;
use parrotposter\Api;
use parrotposter\ApiHelpers;
use parrotposter\AssetModules;
use parrotposter\Profile;
use parrotposter\Tariffs;

AssetModules::enqueue(['tariffs', 'block', 'loading']);

$profile = Profile::get_info();
if (!empty($profile['user'])) {
	$accounts_cur_cnt = $profile['user']['tariff_limits']['accounts_current_cnt'];
	$accounts_cnt = $profile['user']['tariff_limits']['accounts_cnt'];
}

$res = Api::list_tariffs();
$tariffs = ApiHelpers::retrieve_response($res, 'tariffs');
Tariffs::apply_translates($tariffs);
foreach ($tariffs as $tariffs_k => $tariff) {
	$tariffs[$tariffs_k]['is_current'] = $profile['tariff']['id'] == $tariff['id'];
	foreach ($tariff['periods'] as $period_k => $item) {
		$usdPrice = Tariffs::rub_to_dollar($item['price'] / 100);
		$tariffs[$tariffs_k]['periods'][$period_k]['price_usd'] = $usdPrice;
	}
}

?>

<?php PP::include_view('header') ?>

<h1><?php _e('Tariffs', 'parrotposter') ?></h1>

<div class="parrotposter-block parrotposter-block--horizontal parrotposter-block--column-mobile">
	<div class="parrotposter-block__group">
		<div class="parrotposter-block__label"><?php _e('Your tariff', 'parrotposter') ?></div>
		<div class="parrotposter-block__value"><?php echo esc_html($profile['tariff']['name']) ?></div>
	</div>
	<div class="parrotposter-block__group">
		<div class="parrotposter-block__label"><?php _e('Added accounts', 'parrotposter') ?></div>
		<div class="parrotposter-block__value"><?php printf(__('%1$d of %2$d', 'parrotposter'), $accounts_cur_cnt, $accounts_cnt) ?></div>
	</div>
	<div class="parrotposter-block__group">
		<div class="parrotposter-block__label"><?php _e('Tariff expired at', 'parrotposter') ?></div>
		<div class="parrotposter-block__value">
			<?php echo wp_date(get_option('date_format'), strtotime($profile['user']['tariff']['expiry_at'])) ?>
			<?php echo esc_attr($profile['left']) ?>
		</div>
	</div>
</div>

<div class="parrotposter-tariffs-period__wrap">
	<?php foreach (Tariffs::get_periods() as $item): ?>
	<label>
		<?php if ($item['period'] == 1): ?>
		<input type="radio" name="parrotposter[period]" value="<?php echo esc_attr($item['period']) ?>" checked="checked">
		<?php else: ?>
		<input type="radio" name="parrotposter[period]" value="<?php echo esc_attr($item['period']) ?>">
		<?php endif ?>
		<div class="parrotposter-tariffs-period__label">
			<span class="parrotposter-tariffs-period__name"><?php echo esc_attr($item['label']) ?></span>
			<?php if ($item['discount'] > 0): ?>
			<span class="parrotposter-tariffs-period__badge">-<?php echo esc_attr($item['discount']) ?>%</span>
			<?php endif ?>
		</div>
	</label>
	<?php endforeach ?>
</div>

<br>

<div class="parrotposter-tariffs__wrap">
	<?php foreach ($tariffs as $tariff): ?>
	<div class="parrotposter-tariffs__item <?php echo $tariff['is_current'] ? 'parrotposter-tariffs__item--current' : '' ?>" data-id="<?php echo esc_attr($tariff['id']) ?>">
		<div class="parrotposter-tariffs__name">
			<?php echo esc_attr($tariff['name']) ?>
			<div class="parrotposter-tariffs__current"><?php _e('Current', 'parrotposter') ?></div>
		</div>
		<div class="parrotposter-tariffs__price">
			<span class="parrotposter-tariffs__price-value"><?php echo esc_attr(Tariffs::get_period_price($tariff, 1)) ?></span> ₽
			<div class="parrotposter-tariffs__period">
				<?php _e('per month', 'parrotposter') ?>
			</div>
		</div>
		<div class="parrotposter-tariffs__price-usd">
			~$<span class="parrotposter-tariffs__price-usd-value"><?php echo esc_attr(Tariffs::rub_to_dollar(Tariffs::get_period_price($tariff, 1))) ?></span>
		</div>
		<div class="parrotposter-tariffs__limit">
			<?php _e('Unlimited posts', 'parrotposter') ?>
		</div>
		<div class="parrotposter-tariffs__limit">
			<?php printf(_n('%d account of social network', '%d accounts of social networks', $tariff['limits']['accounts_cnt'], 'parrotposter'), $tariff['limits']['accounts_cnt']) ?>
		</div>
		<div>
			<br>
			<?php if (!Tariffs::is_active($profile['tariff']['code'], $profile['user']['tariff']['expiry_at'])): ?>
			<button class="button button-primary">
				<?php _e('Select and pay', 'parrotposter') ?>
			</button>
			<?php elseif ($tariff['is_current']): ?>
			<button class="button button-primary">
				<?php _e('Prolong', 'parrotposter') ?>
			</button>
			<?php else: ?>
			<button class="button">
				<?php _e('Select and pay', 'parrotposter') ?>**
			</button>
			<?php endif ?>
		</div>
	</div>
	<?php endforeach ?>
</div>

<br>

<div class="parrotposter-tariffs__ps">
	* <?php _e('The price in dollars is calculated on the basis of the Bank of Russia exchange rate', 'parrotposter') ?>
</div>

<?php if (Tariffs::is_active($profile['tariff']['code'], $profile['user']['tariff']['expiry_at'])): ?>
<div class="parrotposter-tariffs__ps">
	** <?php _e('If you select a new tariff, the remaining days at the current tariff will be recalculated and transferred to the new tariff', 'parrotposter') ?>
</div>
<?php endif ?>

<script>
	const parrotposter_tariffs = <?php echo json_encode($tariffs) ?>;
</script>
