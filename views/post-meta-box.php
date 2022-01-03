<?php

defined('ABSPATH') || exit;

use parrotposter\PP;
use parrotposter\Options;
use parrotposter\AssetModules;

$post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
if (empty($post_id)) {
	return;
}

if (!Options::user_id()) {
	return;
}

AssetModules::enqueue(['loading', 'post-meta-box']);
?>

<p>
	<a class="button button-primary" href="admin.php?page=parrotposter_posts&view=publish-post&post_id=<?php echo esc_attr($post_id) ?>">
		<?php _e('Publish to socials network', 'parrotposter') ?>
	</a>
</p>

<p>
	<a class="parrotposter-meta-box__show-posts-btn" href="#">
		<span class="parrotposter-meta-box__text-show"><?php _e('Show published posts on social networks', 'parrotposter') ?></span>
		<span class="parrotposter-meta-box__text-hide"><?php _e('Hide', 'parrotposter') ?></span>
	</a>
</p>

<p class="parrotposter-meta-box-post-items">
</p>

<?php PP::include_view('posts/detail') ?>


<script>
	const parrotposter_post_id = <?php echo json_encode(esc_attr($post_id)) ?>;
	const parrotposter_user_id = <?php echo json_encode(Options::user_id()) ?>;
</script>
