<?php

defined('ABSPATH') || exit;

use parrotposter\LocalQueue;

if (!current_user_can('manage_options')) {
	return;
}

$lq_pending_count = LocalQueue::get_pending_count();
if ($lq_pending_count <= 0) {
	return;
}

?>
<div class="notice notice-warning parrotposter-local-queue-notice">
	<p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: number of pending local queue items */
				_n(
					'Local queue: %d item waiting to sync with ParrotPoster.',
					'Local queue: %d items waiting to sync with ParrotPoster.',
					$lq_pending_count,
					'parrotposter'
				),
				$lq_pending_count
			)
		);
		?>
		<button type="button" class="button button-secondary parrotposter-local-queue-view-btn" style="margin-left: 8px;">
			<?php esc_html_e('View', 'parrotposter'); ?>
		</button>
	</p>
	<p class="description">
		<?php esc_html_e('Temporary tasks on your site: changes to WordPress posts that the plugin is still sending to ParrotPoster. The page stays fast; sync runs in the background.', 'parrotposter'); ?>
	</p>
</div>
