<?php

defined('ABSPATH') || die;

use parrotposter\AssetModules;

AssetModules::enqueue(['modal', 'common', 'loading', 'post-detail', 'accounts']);

?>

<div id="parrotposter-view-post" class="parrotposter-modal parrotposter-modal--post">
	<div class="parrotposter-modal__container">
		<div class="parrotposter-modal__close"></div>
		<div class="parrotposter-modal__title"><?php parrotposter_e('View post') ?></div>

		<div class="parrotposter-modal__post-images"></div>

		<div class="parrotposter-modal__post-text"></div>

		<div class="parrotposter-modal__post-info">
			<div class="parrotposter-modal__post-info-item parrotposter--tags">
				<div class="parrotposter-modal__post-info-label">
					<?php parrotposter_e('Tags') ?>
				</div>
				<div class="parrotposter-modal__post-info-value"></div>
			</div>
		</div>

		<div class="parrotposter-modal__post-info">
			<div class="parrotposter-modal__post-info-item parrotposter--link">
				<div class="parrotposter-modal__post-info-label">
					<?php parrotposter_e('Link') ?>
				</div>
				<div class="parrotposter-modal__post-info-value"></div>
			</div>
		</div>

		<div class="parrotposter-modal__post-info">
			<div class="parrotposter-modal__post-info-item parrotposter--publish_at">
				<div class="parrotposter-modal__post-info-label">
					<?php parrotposter_e('Publish at') ?>
				</div>
				<div class="parrotposter-modal__post-info-value"></div>
			</div>
		</div>

		<div class="parrotposter-accounts__list parrotposter-accounts__list--results">
		</div>

		<div>
			<button class="button parrotposter-button--delete"><?php parrotposter_e('Delete post') ?></button>
		</div>
	</div>
</div>

<div id="parrotposter-view-post-delete-confirm" class="parrotposter-modal">
	<div class="parrotposter-modal__container">
		<div class="parrotposter-modal__close"></div>
		<div class="parrotposter-modal__title">
			<?php parrotposter_e('Are you sure you want to delete post from ParrotPoster?') ?>
		</div>
		<div class="parrotposter-modal__footer">
			<button class="button button-primary parrotposter-button--delete"><?php parrotposter_e('Delete') ?></button>
			<button class="button parrotposter-js-close"><?php parrotposter_e('Cancel') ?></button>
		</div>
	</div>
</div>

