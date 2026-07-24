<?php

defined('ABSPATH') || die;

use parrotposter\PP;
use parrotposter\FormHelpers;
use parrotposter\AssetModules;
use parrotposter\Profile;
use parrotposter\Settings;
use parrotposter\UserPreferences;
use parrotposter\Api;

AssetModules::enqueue(['block']);

$profile = Profile::get_info();
$accounts_cur_cnt = 0;
$accounts_cnt = 0;
if (!empty($profile['user'])) {
	$accounts_cur_cnt = $profile['user']['tariff_limits']['accounts_current_cnt'];
	$accounts_cnt = $profile['user']['tariff_limits']['accounts_cnt'];
}

$sso = Api::build_sso_url('/app');
$sso_url = empty($sso['error']) && !empty($sso['url']) ? (string) $sso['url'] : '';

$show_pipelines_section = Settings::get_migration_mode() === Settings::MIGRATION_MODE_PIPELINE
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
		<p><?php echo esc_attr(is_array($profile['error']) ? $profile['error']['msg'] : $profile['error']) ?></p>
	</div>
<?php endif ?>

<div class="parrotposter-block parrotposter-block--min">
	<h2 class="parrotposter-block__heading"><?php _e('Account', 'parrotposter') ?></h2>

	<?php if (!empty($profile['user']['name'])): ?>
		<div class="parrotposter-block__group">
			<div class="parrotposter-block__label"><?php _e('Your name', 'parrotposter') ?></div>
			<div class="parrotposter-block__value"><?php echo esc_attr($profile['user']['name']) ?></div>
		</div>
	<?php endif ?>

	<?php if (!empty($profile['user']['username'])): ?>
		<div class="parrotposter-block__group">
			<div class="parrotposter-block__label"><?php _e('Email', 'parrotposter') ?></div>
			<div class="parrotposter-block__value"><?php echo esc_attr($profile['user']['username']) ?></div>
		</div>
	<?php endif ?>

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
			<span class="<?php echo $profile['expired'] ? 'parrotposter-block__value--error' : 'parrotposter-block__value--success' ?>">
				<?php echo wp_date(get_option('date_format'), strtotime($profile['user']['tariff']['expiry_at'])) ?>
				<?php echo esc_attr($profile['left']) ?>
			</span>
		</div>
	</div>

	<?php if ($sso_url !== ''): ?>
		<div class="parrotposter-block__group">
			<div class="parrotposter-block__label"><?php _e('Web application', 'parrotposter') ?></div>
			<div class="parrotposter-block__value">
				<a
					class="button button-primary"
					href="<?php echo esc_url($sso_url) ?>"
					target="_blank"
					rel="noopener noreferrer">
					<?php _e('Open web application', 'parrotposter') ?>
				</a>
			</div>
		</div>
	<?php endif ?>

	<div class="parrotposter-block__group">
		<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
			<?php FormHelpers::the_nonce() ?>
			<input type="hidden" name="action" value="parrotposter_logout">
			<input type="hidden" name="back_url" value="admin.php?page=parrotposter">
			<input class="button button-secondary" type="submit" name="submit" value="<?php _e('Logout', 'parrotposter') ?>">
		</form>
	</div>
</div>

<?php if (Settings::is_connected()): ?>
	<div class="parrotposter-block parrotposter-block--min">
		<h2 class="parrotposter-block__heading"><?php _e('Site integration', 'parrotposter') ?></h2>
		<div class="parrotposter-block__group">
			<div class="parrotposter-block__value">
				<p><?php _e('This site is linked to your ParrotPoster account.', 'parrotposter') ?></p>
				<a
					class="button button-secondary"
					href="<?php echo esc_url(admin_url('admin-post.php?action=parrotposter_connect_disconnect')) ?>"
					onclick="return confirm('<?php echo esc_js(__('Disconnect this site from ParrotPoster automation?', 'parrotposter')) ?>')">
					<?php _e('Disconnect site', 'parrotposter') ?>
				</a>
			</div>
		</div>
	</div>
<?php endif ?>

<?php if ($show_pipelines_section): ?>
	<div class="parrotposter-block parrotposter-block--min">
		<h2 class="parrotposter-block__heading"><?php _e('Pipelines', 'parrotposter') ?></h2>
		<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
			<?php FormHelpers::the_nonce() ?>
			<input type="hidden" name="action" value="parrotposter_save_settings">
			<input type="hidden" name="back_url" value="admin.php?page=parrotposter_settings">
			<div class="parrotposter-block__group">
				<label>
					<input
						type="checkbox"
						name="parrotposter_show_migration_banner"
						value="1"
						<?php checked(UserPreferences::show_migration_banner()) ?>>
					<?php _e('Show migration notice on Pipelines page', 'parrotposter') ?>
				</label>
			</div>
			<div class="parrotposter-block__group">
				<button type="submit" class="button button-secondary">
					<?php _e('Save', 'parrotposter') ?>
				</button>
			</div>
		</form>
		<div class="parrotposter-block__group">
			<button
				type="button"
				class="button button-secondary pp-migration-revert"
				data-confirm="<?php echo esc_attr($revert_confirm) ?>">
				<?php _e('Revert to Scheduler', 'parrotposter') ?>
			</button>
		</div>
	</div>
<?php endif ?>
