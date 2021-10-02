<?php

use parrotposter\Options;

$post_id = (isset($_GET['post']) && (int) $_GET['post'] > 0) ? (int) $_GET['post'] : 0;

wp_enqueue_script('parrotposter-post-meta-box');
?>

<p class="parrotposter-meta-box-post-items parrotposter-loading-spinner">
</p>

<a class="button button-primary" href="admin.php?page=parrotposter&subpage=publish_post&post_id=<?php echo $post_id ?>">
	<?php parrotposter_e('Publish to socials network') ?>
</a>

<div id="parrotposter-post-details" class="parrotposter-modal">
	<div class="parrotposter-modal-container">
		<div class="parrotposter-modal-header">
			<div class="parrotposter-modal-title"><?php parrotposter_e('Post details') ?></div>
			<div class="parrotposter-modal-close-btn parrotposter-js-close"></div>
		</div>
		<div class="parrotposter-modal-body">
			<p class="parrotposter-loading-spinner"></p>
			<p class="parrotposter-modal-post-text"></p>
			<p class="parrotposter-modal-post-images"></p>
			<p class="parrotposter-modal-post-results"></p>
			<p class="parrotposter-modal-post-actions"></p>
		</div>
		<div class="parrotposter-modal-footer">
			<button class="button button-primary parrotposter-js-close"><?php parrotposter_e('Close') ?></button>
		</div>
	</div>
</div>

<script>
	const parrotposter_post_id = <?php echo json_encode($post_id) ?>;
	const parrotposter_user_id = <?php echo json_encode(Options::user_id()) ?>;
</script>
