<?php

defined('ABSPATH') || die;

use parrotposter\AssetModules;
use parrotposter\AutopostingHelpers;
use parrotposter\DBAutopostingTable;
use parrotposter\FormHelpers;

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
