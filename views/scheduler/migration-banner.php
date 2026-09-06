<?php

defined('ABSPATH') || exit;

use parrotposter\AssetModules;
use parrotposter\DBAutopostingTable;
use parrotposter\Migration\TemplateClusterer;
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
			<?php foreach (TemplateClusterer::cluster($templates) as $cluster): ?>
				<?php
				$member_ids = TemplateClusterer::cluster_member_ids($cluster);
				if ($member_ids === []) {
					continue;
				}
				$cluster_name = TemplateClusterer::cluster_name($cluster);
				$networks = TemplateClusterer::cluster_network_labels($cluster);
				$member_names = [];
				foreach ($cluster as $member) {
					if (!is_array($member)) {
						continue;
					}
					$member_name = isset($member['name']) ? trim((string) $member['name']) : '';
					if ($member_name !== '' && !in_array($member_name, $member_names, true)) {
						$member_names[] = $member_name;
					}
				}
				$subtitle_parts = [];
				if ($networks !== []) {
					$subtitle_parts[] = implode(', ', $networks);
				}
				if (count($cluster) > 1) {
					$from = implode(', ', $member_names);
					if ($from !== '') {
						$subtitle_parts[] = sprintf(
							/* translators: %s: original template names */
							__('from %s', 'parrotposter'),
							$from
						);
					}
				}
				?>
				<li>
					<label>
						<input type="checkbox" name="pp_migration_config[]" value="<?php echo esc_attr(implode(',', $member_ids)) ?>" checked>
						<?php echo esc_html($cluster_name) ?>
					</label>
					<?php if ($subtitle_parts !== []): ?>
						<div class="pp-migration-banner__subtitle"><?php echo esc_html(implode(' — ', $subtitle_parts)) ?></div>
					<?php endif ?>
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
