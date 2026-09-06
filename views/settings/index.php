<?php

defined('ABSPATH') || die;

use parrotposter\PP;
use parrotposter\FormHelpers;
use parrotposter\AssetModules;
use parrotposter\Profile;
use parrotposter\Settings;
use parrotposter\UserPreferences;

AssetModules::enqueue(['common', 'block', 'settings']);

$profile = Profile::get_info();
$user = !empty($profile['user']) && is_array($profile['user']) ? $profile['user'] : [];
$tariff = !empty($profile['tariff']) && is_array($profile['tariff']) ? $profile['tariff'] : [];
$accounts_cur_cnt = 0;
$accounts_cnt = 0;
if (!empty($user['tariff_limits']) && is_array($user['tariff_limits'])) {
	$accounts_cur_cnt = isset($user['tariff_limits']['accounts_current_cnt']) ? (int) $user['tariff_limits']['accounts_current_cnt'] : 0;
	$accounts_cnt = isset($user['tariff_limits']['accounts_cnt']) ? (int) $user['tariff_limits']['accounts_cnt'] : 0;
}
$accounts_progress = $accounts_cnt > 0 ? min(100, (int) round($accounts_cur_cnt / $accounts_cnt * 100)) : 0;
$is_expired = !empty($profile['expired']);
$expiry_at = !empty($user['tariff']['expiry_at']) ? (string) $user['tariff']['expiry_at'] : '';
$expiry_ts = $expiry_at !== '' ? strtotime($expiry_at) : false;
$expiry_text = $expiry_ts !== false ? wp_date(get_option('date_format'), $expiry_ts) : __('Unknown', 'parrotposter');
if (!empty($profile['left'])) {
	$expiry_text .= ' ' . (string) $profile['left'];
}
$migration_mode = Settings::get_migration_mode();
$is_connected = Settings::is_connected();
$format_connection_activity = static function (?string $utc_value): array {
	if ($utc_value === null || $utc_value === '') {
		return [
			'label' => __('No requests yet', 'parrotposter'),
			'exact' => '',
		];
	}

	$ts = strtotime($utc_value . ' UTC');
	if ($ts === false) {
		return [
			'label' => __('No requests yet', 'parrotposter'),
			'exact' => '',
		];
	}

	return [
		'label' => sprintf(__('%s ago', 'parrotposter'), human_time_diff($ts, time())),
		'exact' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts),
	];
};
$site_to_pp_activity = $format_connection_activity(Settings::last_site_to_pp_call_at());
$pp_to_site_activity = $format_connection_activity(Settings::last_primary_call_at());

$show_pipelines_section = $migration_mode === Settings::MIGRATION_MODE_PIPELINE
	&& Settings::has_enabled_legacy_templates();

if ($show_pipelines_section) {
	AssetModules::enqueue(['pipeline-migration']);
}

$revert_confirm = __(
	'Revert to legacy scheduler? Pipelines will stay on the server but triggers will be disabled.',
	'parrotposter'
);

?>

<?php PP::include_view('header', ['title' => __('Settings', 'parrotposter')]) ?>

<?php PP::include_view('notice') ?>

<?php if (!empty($profile['error'])): ?>
	<div class="notice notice-error">
		<p><?php echo esc_html(is_array($profile['error']) ? $profile['error']['msg'] : $profile['error']) ?></p>
	</div>
<?php endif ?>

<div class="parrotposter-settings">
	<div class="parrotposter-settings__grid">
		<section class="parrotposter-block parrotposter-settings-card parrotposter-settings-card--summary">
			<div class="parrotposter-settings-card__header">
				<div>
					<h2 class="parrotposter-block__heading"><?php _e('Account', 'parrotposter') ?></h2>
				</div>
				<span class="parrotposter-settings-badge <?php echo $is_expired ? 'parrotposter-settings-badge--error' : 'parrotposter-settings-badge--success' ?>">
					<?php echo $is_expired ? esc_html__('Expired', 'parrotposter') : esc_html__('Active', 'parrotposter') ?>
				</span>
			</div>

			<div class="parrotposter-settings-facts">
				<?php if (!empty($user['name'])): ?>
					<div class="parrotposter-settings-fact">
						<div class="parrotposter-block__label"><?php _e('Your name', 'parrotposter') ?></div>
						<div class="parrotposter-block__value"><?php echo esc_html($user['name']) ?></div>
					</div>
				<?php endif ?>

				<?php if (!empty($user['username'])): ?>
					<div class="parrotposter-settings-fact">
						<div class="parrotposter-block__label"><?php _e('Email', 'parrotposter') ?></div>
						<div class="parrotposter-block__value"><?php echo esc_html($user['username']) ?></div>
					</div>
				<?php endif ?>

				<div class="parrotposter-settings-fact">
					<div class="parrotposter-block__label"><?php _e('Tariff', 'parrotposter') ?></div>
					<div class="parrotposter-block__value"><?php echo esc_html(!empty($tariff['name']) ? $tariff['name'] : __('Unknown', 'parrotposter')) ?></div>
				</div>

				<div class="parrotposter-settings-fact">
					<div class="parrotposter-block__label"><?php _e('Expiry at', 'parrotposter') ?></div>
					<div class="parrotposter-block__value">
						<span class="<?php echo $is_expired ? 'parrotposter-block__value--error' : 'parrotposter-block__value--success' ?>">
							<?php echo esc_html($expiry_text) ?>
						</span>
					</div>
				</div>
			</div>

			<div class="parrotposter-settings-usage">
				<div class="parrotposter-settings-usage__row">
					<div class="parrotposter-block__label"><?php _e('Accounts', 'parrotposter') ?></div>
					<div class="parrotposter-settings-usage__value">
						<?php printf(esc_html__('%1$d of %2$d', 'parrotposter'), $accounts_cur_cnt, $accounts_cnt) ?>
					</div>
				</div>
				<div class="parrotposter-settings-progress" aria-hidden="true">
					<span style="width: <?php echo esc_attr($accounts_progress) ?>%"></span>
				</div>
			</div>
		</section>

		<section class="parrotposter-block parrotposter-settings-card parrotposter-settings-card--webapp">
			<div class="parrotposter-settings-card__header">
				<div>
					<h2 class="parrotposter-block__heading"><?php _e('Web application', 'parrotposter') ?></h2>
				</div>
			</div>

			<div class="parrotposter-settings-launch">
				<div class="parrotposter-settings-launch__mark" aria-hidden="true">PP</div>
				<p class="parrotposter-settings-card__description">
					<?php _e('Manage social accounts, templates, and publishing in the ParrotPoster app.', 'parrotposter') ?>
				</p>
				<form
					class="parrotposter-settings-launch__form"
					action="<?php echo esc_url(admin_url('admin-post.php')) ?>"
					method="post"
					target="_blank">
					<?php FormHelpers::the_nonce() ?>
					<input type="hidden" name="action" value="parrotposter_open_webapp">
					<input type="hidden" name="back_url" value="admin.php?page=parrotposter_settings">
					<button
						type="submit"
						class="button button-primary parrotposter-settings-launch__button parrotposter-external-link parrotposter-external-link--white">
						<?php _e('Open web application', 'parrotposter') ?>
					</button>
				</form>
			</div>

			<div class="parrotposter-settings-session">
				<span><?php _e('Signed in on this WordPress site', 'parrotposter') ?></span>
				<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
					<?php FormHelpers::the_nonce() ?>
					<input type="hidden" name="action" value="parrotposter_logout">
					<input type="hidden" name="back_url" value="admin.php?page=parrotposter">
					<input class="button button-secondary button-small" type="submit" name="submit" value="<?php esc_attr_e('Logout', 'parrotposter') ?>">
				</form>
			</div>
		</section>
	</div>

	<?php if ($is_connected): ?>
		<section class="parrotposter-block parrotposter-settings-card">
			<div class="parrotposter-settings-card__header">
				<div>
					<h2 class="parrotposter-block__heading"><?php _e('Site integration', 'parrotposter') ?></h2>
				</div>
				<span class="parrotposter-settings-badge parrotposter-settings-badge--success">
					<?php _e('Connected', 'parrotposter') ?>
				</span>
			</div>

			<p class="parrotposter-settings-card__description">
				<?php _e('This site is linked to your ParrotPoster account and can exchange publishing data with the service.', 'parrotposter') ?>
			</p>

			<div class="parrotposter-settings-activity">
				<div class="parrotposter-settings-activity-card">
					<div class="parrotposter-block__label"><?php _e('WordPress to ParrotPoster', 'parrotposter') ?></div>
					<div class="parrotposter-block__value" title="<?php echo esc_attr($site_to_pp_activity['exact']) ?>">
						<?php echo esc_html($site_to_pp_activity['label']) ?>
					</div>
				</div>
				<div class="parrotposter-settings-activity-card">
					<div class="parrotposter-block__label"><?php _e('ParrotPoster to WordPress', 'parrotposter') ?></div>
					<div class="parrotposter-block__value" title="<?php echo esc_attr($pp_to_site_activity['exact']) ?>">
						<?php echo esc_html($pp_to_site_activity['label']) ?>
					</div>
				</div>
			</div>

			<div class="parrotposter-settings-danger">
				<div>
					<div class="parrotposter-settings-danger__title"><?php _e('Danger zone', 'parrotposter') ?></div>
					<p><?php _e('Disconnect this WordPress site from ParrotPoster automation. Your ParrotPoster account will stay active.', 'parrotposter') ?></p>
				</div>
				<a
					class="button button-secondary parrotposter-button--delete"
					href="<?php echo esc_url(admin_url('admin-post.php?action=parrotposter_connect_disconnect')) ?>"
					onclick="return confirm('<?php echo esc_js(__('Disconnect this site from ParrotPoster automation?', 'parrotposter')) ?>')">
					<?php _e('Disconnect site', 'parrotposter') ?>
				</a>
			</div>
		</section>
	<?php endif ?>

	<?php if ($show_pipelines_section): ?>
		<section class="parrotposter-block parrotposter-settings-card">
			<div class="parrotposter-settings-card__header">
				<div>
					<h2 class="parrotposter-block__heading"><?php _e('Pipelines', 'parrotposter') ?></h2>
				</div>
			</div>

			<p class="parrotposter-settings-card__description">
				<?php _e('Legacy templates are still available. You can keep the reminder visible or revert this site to the old scheduler.', 'parrotposter') ?>
			</p>

			<div class="parrotposter-settings-pipelines">
				<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
					<?php FormHelpers::the_nonce() ?>
					<input type="hidden" name="action" value="parrotposter_save_settings">
					<input type="hidden" name="back_url" value="admin.php?page=parrotposter_settings">
					<label class="parrotposter-settings-checkbox">
						<input
							type="checkbox"
							name="parrotposter_show_migration_banner"
							value="1"
							<?php checked(UserPreferences::show_migration_banner()) ?>>
						<?php _e('Show migration notice on Pipelines page', 'parrotposter') ?>
					</label>
					<button type="submit" class="button button-secondary">
						<?php _e('Save', 'parrotposter') ?>
					</button>
				</form>

				<button
					type="button"
					class="button button-secondary pp-migration-revert"
					data-confirm="<?php echo esc_attr($revert_confirm) ?>">
					<?php _e('Revert to Scheduler', 'parrotposter') ?>
				</button>
			</div>
		</section>
	<?php endif ?>
</div>
