<?php

defined('ABSPATH') || exit;

use parrotposter\AssetModules;
use parrotposter\DBAutopostingTable;
use parrotposter\PP;
use parrotposter\Settings;

if (Settings::get_migration_mode() !== Settings::MIGRATION_MODE_LEGACY) {
	PP::include_view('partials/migration-banner-pipeline-active');
	return;
}

if (!Settings::has_enabled_legacy_templates()) {
	return;
}

AssetModules::enqueue(['pipeline-migration']);
$templates = DBAutopostingTable::get_all(true);
?>

<div class="pp-migration-banner">
	<div class="pp-migration-banner__title">
		<?php _e('Upgrade to Pipelines', 'parrotposter') ?>
	</div>
	<p><?php _e('ParrotPoster now uses Pipelines for automation. Migrate your active autoposting templates or start fresh.', 'parrotposter') ?></p>

	<?php if (!empty($templates)): ?>
		<ul class="pp-migration-banner__list">
			<?php foreach ($templates as $tpl): ?>
				<?php if (!is_array($tpl)) {
	continue;
} ?>
				<li>
					<label>
						<input type="checkbox" name="pp_migration_config[]" value="<?php echo esc_attr((string) ($tpl['id'] ?? '')) ?>" checked>
						<?php echo esc_html((string) ($tpl['name'] ?? __('Template', 'parrotposter'))) ?>
					</label>
				</li>
			<?php endforeach ?>
		</ul>
	<?php endif ?>

	<div class="pp-migration-banner__actions">
		<button type="button" class="button button-primary pp-migration-start" data-mode="import">
			<?php _e('Migrate selected templates', 'parrotposter') ?>
		</button>
		<button type="button" class="button button-secondary pp-migration-start" data-mode="fresh">
			<?php _e('Start from scratch', 'parrotposter') ?>
		</button>
	</div>
</div>
