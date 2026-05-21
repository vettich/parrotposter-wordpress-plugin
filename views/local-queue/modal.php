<?php

defined('ABSPATH') || exit;

if (!current_user_can('manage_options')) {
	return;
}

?>
<div id="parrotposter-local-queue-modal" class="parrotposter-modal parrotposter-modal--local-queue">
	<div class="parrotposter-modal__container">
		<div class="parrotposter-modal__close"></div>
		<div class="parrotposter-modal__title">
			<?php esc_html_e('Local sync queue', 'parrotposter'); ?>
		</div>
		<p class="parrotposter-local-queue-modal__intro description">
			<?php esc_html_e('Tasks waiting to be sent to ParrotPoster (create, update, or delete). Usually the list shrinks quickly once sync succeeds.', 'parrotposter'); ?>
		</p>
		<div class="parrotposter-local-queue-modal__loading parrotposter-loading" style="display: none;"></div>
		<div class="parrotposter-local-queue-modal__empty" style="display: none;">
			<p><?php esc_html_e('No items in the queue.', 'parrotposter'); ?></p>
		</div>
		<div class="parrotposter-local-queue-modal__table-wrap" style="display: none;">
			<table class="widefat striped parrotposter-local-queue-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e('ID', 'parrotposter'); ?></th>
						<th scope="col"><?php esc_html_e('WP post', 'parrotposter'); ?></th>
						<th scope="col"><?php esc_html_e('Operation', 'parrotposter'); ?></th>
						<th scope="col"><?php esc_html_e('Status', 'parrotposter'); ?></th>
						<th scope="col"><?php esc_html_e('Attempts', 'parrotposter'); ?></th>
						<th scope="col"><?php esc_html_e('Next attempt', 'parrotposter'); ?></th>
						<th scope="col"><?php esc_html_e('Created', 'parrotposter'); ?></th>
						<th scope="col"><?php esc_html_e('Details', 'parrotposter'); ?></th>
						<th scope="col" class="parrotposter-local-queue-table__actions-col"><?php esc_html_e('Actions', 'parrotposter'); ?></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>
		<p class="parrotposter-local-queue-modal__feedback description" style="display: none;" role="status"></p>
		<p class="parrotposter-local-queue-modal__wake-hint description" style="display: none;"></p>
		<p class="parrotposter-local-queue-modal__support description">
			<?php
			printf(
				/* translators: %s: support email link */
				wp_kses(
					__('If the count stays high for a long time, check site connectivity to ParrotPoster or contact %s.', 'parrotposter'),
					['a' => ['href' => []]]
				),
				'<a href="mailto:support@parrotposter.com">support@parrotposter.com</a>'
			);
			?>
		</p>
		<div class="parrotposter-modal__footer">
			<button type="button" class="button button-primary parrotposter-local-queue-process-all-btn">
				<?php esc_html_e('Process now', 'parrotposter'); ?>
			</button>
			<button type="button" class="button parrotposter-js-close"><?php esc_html_e('Close', 'parrotposter'); ?></button>
		</div>
	</div>
</div>
