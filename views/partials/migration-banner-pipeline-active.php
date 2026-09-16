<?php

defined('ABSPATH') || exit;

use parrotposter\AssetModules;
use parrotposter\Settings;
use parrotposter\UserPreferences;

if (
	Settings::get_migration_mode() !== Settings::MIGRATION_MODE_PIPELINE
	|| !Settings::has_enabled_legacy_templates()
	|| !UserPreferences::show_migration_banner()
) {
	return;
}

AssetModules::enqueue(['pipeline-migration']);

$info_title = __(
	'You can revert to the legacy scheduler if the new pipelines do not work for you.',
	'parrotposter'
);
$revert_confirm = __(
	'Revert to legacy scheduler? Pipelines will stay on the server but triggers will be disabled.',
	'parrotposter'
);
$dismiss_confirm = __(
	'You can restore this notice in Settings → Pipelines.',
	'parrotposter'
);
?>

<div class="pp-migration-banner pp-migration-banner--compact">
	<div class="pp-migration-banner__row">
		<span class="pp-migration-banner__title">
			<?php _e('Pipeline mode is active', 'parrotposter') ?>
		</span>
		<span
			class="pp-migration-banner__info dashicons dashicons-info"
			title="<?php echo esc_attr($info_title) ?>"
			aria-label="<?php echo esc_attr($info_title) ?>"></span>
		<div class="pp-migration-banner__actions">
			<button
				type="button"
				class="button button-secondary pp-migration-revert"
				data-confirm="<?php echo esc_attr($revert_confirm) ?>">
				<?php _e('Revert to Scheduler', 'parrotposter') ?>
			</button>
			<button
				type="button"
				class="button button-link pp-migration-dismiss"
				data-confirm="<?php echo esc_attr($dismiss_confirm) ?>">
				<?php _e('Hide', 'parrotposter') ?>
			</button>
		</div>
	</div>
</div>
