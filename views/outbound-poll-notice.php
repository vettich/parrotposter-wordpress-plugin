<?php

defined('ABSPATH') || exit;

use parrotposter\OutboundPollScheduler;

if (!current_user_can('manage_options')) {
	return;
}

if (!OutboundPollScheduler::should_show_poll_banner()) {
	return;
}

$wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
$last_error = OutboundPollScheduler::last_lease_error();

?>
<div class="notice notice-warning parrotposter-outbound-poll-notice">
	<p>
		<?php
		echo esc_html(
			$wp_cron_disabled
				? __('ParrotPoster outbound polling has not leased tasks recently. DISABLE_WP_CRON is on — WordPress will not run plugin cron by itself. Add a system cron that hits wp-cron.php at least every 5 minutes, for example:', 'parrotposter')
				: __('ParrotPoster outbound polling has not leased tasks recently. Scheduled publishing via the plugin poll will stall until a lease succeeds.', 'parrotposter')
		);
		?>
	</p>
	<?php if ($wp_cron_disabled): ?>
		<p><code>*/5 * * * * wget -q -O - <?php echo esc_html(site_url('wp-cron.php?doing_wp_cron')); ?> >/dev/null 2>&1</code></p>
	<?php endif; ?>
	<?php if ($last_error): ?>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: last lease error */
					__('Last lease error: %s', 'parrotposter'),
					$last_error
				)
			);
			?>
		</p>
	<?php endif; ?>
</div>
