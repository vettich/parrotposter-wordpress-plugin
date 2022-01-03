<?php

defined('ABSPATH') || die;

use parrotposter\AssetModules;

AssetModules::enqueue(['modal', 'common', 'loading', 'post-detail', 'accounts']);

?>

<div id="parrotposter-view-post" class="parrotposter-modal parrotposter-modal--post">
	<div class="parrotposter-modal__container">
		<div class="parrotposter-modal__close"></div>
		<div class="parrotposter-modal__title"><?php _e('View post', 'parrotposter') ?></div>

		<div class="parrotposter-modal__post-images"></div>

		<div class="parrotposter-modal__post-text"></div>

		<div class="parrotposter-modal__post-info">
			<div class="parrotposter-modal__post-info-item parrotposter--tags">
				<div class="parrotposter-modal__post-info-label">
					<?php _e('Tags', 'parrotposter') ?>
				</div>
				<div class="parrotposter-modal__post-info-value"></div>
			</div>
		</div>

		<div class="parrotposter-modal__post-info">
			<div class="parrotposter-modal__post-info-item parrotposter--link">
				<div class="parrotposter-modal__post-info-label">
					<?php _e('Link', 'parrotposter') ?>
				</div>
				<div class="parrotposter-modal__post-info-value"></div>
			</div>
		</div>

		<div class="parrotposter-modal__post-info">
			<div class="parrotposter-modal__post-info-item parrotposter--publish_at">
				<div class="parrotposter-modal__post-info-label">
					<?php _e('Publish at', 'parrotposter') ?>
				</div>
				<div class="parrotposter-modal__post-info-value"></div>
			</div>
		</div>

		<div class="parrotposter-accounts__list parrotposter-accounts__list--results">
		</div>

		<div>
			<button class="button parrotposter-button--delete"><?php _e('Delete post', 'parrotposter') ?></button>
		</div>
	</div>
</div>

<div id="parrotposter-view-post-delete-confirm" class="parrotposter-modal">
	<div class="parrotposter-modal__container">
		<div class="parrotposter-modal__close"></div>
		<div class="parrotposter-modal__title">
			<?php _e('Are you sure you want to delete post from ParrotPoster?', 'parrotposter') ?>
		</div>
		<div class="parrotposter-modal__footer">
			<button class="button button-primary parrotposter-button--delete"><?php _e('Delete', 'parrotposter') ?></button>
			<button class="button parrotposter-js-close"><?php _e('Cancel', 'parrotposter') ?></button>
		</div>
	</div>
</div>

