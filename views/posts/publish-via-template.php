<?php

defined('ABSPATH') || die;

use parrotposter\AssetModules;
use parrotposter\AutopostingHelpers;
use parrotposter\DBAutopostingTable;
use parrotposter\FormHelpers;
use parrotposter\Settings;

$is_pipeline = Settings::get_migration_mode() === Settings::MIGRATION_MODE_PIPELINE;

if ($is_pipeline) {
	AssetModules::enqueue(['modal', 'common', 'loading', 'publish-via-pipeline']);
	$automation_url = admin_url('admin.php?page=parrotposter_pipelines');
	?>
	<div id="parrotposter-publish-via-pipeline" class="parrotposter-modal parrotposter-modal--post parrotposter-modal--pipeline"
		data-title-choose="<?php echo esc_attr(__('Choose an automation', 'parrotposter')) ?>"
		data-title-again="<?php echo esc_attr(__('Publish again', 'parrotposter')) ?>"
		data-load-error="<?php echo esc_attr(__('Could not load publication data.', 'parrotposter')) ?>">
		<div class="parrotposter-modal__container">
			<div class="parrotposter-modal__close"></div>
			<div class="parrotposter-modal__title"><?php _e('Publish post', 'parrotposter') ?></div>

			<?php FormHelpers::the_nonce() ?>

			<div id="parrotposter-pipeline-loading" class="parrotposter-pipeline-modal__loading" hidden>
				<span class="parrotposter-loading parrotposter-loading-block"></span>
			</div>

			<div id="parrotposter-pipeline-error" class="parrotposter-pipeline-modal__empty" hidden>
				<p></p>
			</div>

			<nav class="parrotposter-pipeline-modal__tabs" id="parrotposter-pipeline-tabs" hidden role="tablist">
				<button type="button" class="parrotposter-pipeline-modal__tab" data-tab="results" role="tab" aria-selected="false" aria-controls="parrotposter-pipeline-existing">
					<?php _e('On social networks', 'parrotposter') ?>
				</button>
				<button type="button" class="parrotposter-pipeline-modal__tab" data-tab="publish" role="tab" aria-selected="false" aria-controls="parrotposter-pipeline-publish-panel">
					<?php _e('Publish again', 'parrotposter') ?>
				</button>
			</nav>

			<div class="parrotposter-pipeline-modal__panel" data-panel="results" id="parrotposter-pipeline-existing" hidden role="tabpanel">
				<div class="parrotposter-pipeline-modal__section-title"><?php _e('On social networks', 'parrotposter') ?></div>
				<div class="parrotposter-pipeline-modal__existing-list" id="parrotposter-pipeline-existing-list"></div>
			</div>

			<div class="parrotposter-pipeline-modal__panel" data-panel="publish" id="parrotposter-pipeline-publish-panel" hidden role="tabpanel">
				<div id="parrotposter-pipeline-empty" class="parrotposter-pipeline-modal__empty" hidden>
					<p><?php _e('There are no automations available for this post.', 'parrotposter') ?></p>
					<p>
						<a href="<?php echo esc_url($automation_url) ?>"><?php _e('Open Automation', 'parrotposter') ?></a>
					</p>
				</div>

				<div id="parrotposter-pipeline-list-wrap" class="parrotposter-pipeline-modal__publish" hidden>
					<div class="parrotposter-pipeline-modal__section-title" id="parrotposter-pipeline-list-title"></div>
					<div class="parrotposter-pipeline-modal__list" id="parrotposter-pipeline-list"></div>
					<p class="parrotposter-pipeline-modal__hint" id="parrotposter-pipeline-hint" hidden>
						<?php _e('This will create another publication. If this automation already published the post, a duplicate may be skipped.', 'parrotposter') ?>
					</p>
				</div>
			</div>

			<div class="parrotposter-pipeline-modal__footer">
				<button type="button" class="button parrotposter-js-close"><?php _e('Cancel', 'parrotposter') ?></button>
				<button type="button" id="parrotposter-publish-via-pipeline-btn" class="button button-primary" disabled><?php _e('Publish', 'parrotposter') ?></button>
			</div>
		</div>
	</div>
	<?php
} else {
	AssetModules::enqueue(['modal', 'common', 'loading', 'publish-via-template']);

	global $post_type_object;

	$templates = DBAutopostingTable::get_all();
	$templates = array_filter($templates, function ($v) {
		global $post_type_object;
		return $v['wp_post_type'] == $post_type_object->name;
	});
	?>
	<div id="parrotposter-publish-via-template" class="parrotposter-modal parrotposter-modal--post">
		<div class="parrotposter-modal__container">
			<div class="parrotposter-modal__close"></div>
			<div class="parrotposter-modal__title"><?php _e('Publish post', 'parrotposter') ?></div>

			<div class="parrotposter-modal__template-wrap">
				<?php if (empty($templates)) : ?>
					<div>
						<?php _e('There are no auto-publishing templates available. You can create one on the Scheduler page') ?>
					</div>
				<?php else : ?>
					<div>
						<?php _e('You can publish via the auto-publish templates you have already created', 'parrotposter') ?>
					</div>

					<?php FormHelpers::the_nonce() ?>
					<div class="parrotposter-modal__template-list">
						<?php foreach ($templates as $templ) : ?>
							<label for="pp-template-<?php echo $templ['id'] ?>" class="parrotposter-modal__template-item">
								<div class="parrotposter-modal__template-name">
									<input id="pp-template-<?php echo $templ['id'] ?>" name="parrotposter_template_id" value="<?php echo $templ['id'] ?>" type="radio">
									<?php echo $templ['name'] ?>
								</div>
								<div class="parrotposter-modal__template-socials"><?php echo AutopostingHelpers::label_socials_networks($templ) ?></div>
								<div class="parrotposter-modal__template-when"><?php echo AutopostingHelpers::label_when_publish($templ) ?></div>
								<div class="parrotposter-modal__template-post-already-exist">
									<?php _e('Warning', 'parrotposter') ?>:
									<?php _e('The post has already been previously published via this template at :time:', 'parrotposter') ?>
								</div>
							</label>
						<?php endforeach ?>
					</div>

					<div>
						<button id="parrotposter-publish-via-template-btn" class="button" disabled><?php _e('Publish via selected templates', 'parrotposter') ?></button>
						<span id="parrotposter-wait-loading" class="parrotposter-loading"></span>
					</div>
				<?php endif ?>
			</div>


			<div class="parrotposter-modal__template-manually-footer">
				<?php if (!empty($templates)) : ?>
					<?php _e('Or you can publish manually', 'parrotposter') ?>
				<?php endif ?>
				<div>
					<a id="parrotposter-publish-manually-link" href="" class="button"><?php _e('Publish manually', 'parrotposter') ?></a>
				</div>
			</div>
		</div>
	</div>
	<?php
}
?>

<div id="parrotposter-publish-via-template-success" class="parrotposter-modal parrotposter-modal--post">
	<div class="parrotposter-modal__container">
		<div class="parrotposter-modal__close"></div>
		<div class="parrotposter-modal__title"><?php _e('The post was created in ParrotPoster', 'parrotposter') ?></div>
		<span><?php _e('You can check the post publication status in social networks in the ParrotPoster - Posts section.', 'parrotposter') ?></span>
	</div>
</div>

<div id="parrotposter-publish-via-template-fail" class="parrotposter-modal parrotposter-modal--post">
	<div class="parrotposter-modal__container">
		<div class="parrotposter-modal__close"></div>
		<div class="parrotposter-modal__title"><?php _e('Something went wrong', 'parrotposter') ?></div>
	</div>
</div>
