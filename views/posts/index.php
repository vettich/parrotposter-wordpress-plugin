<?php

defined('ABSPATH') || die;

use parrotposter\PP;
use parrotposter\Api;
use parrotposter\ApiHelpers;
use parrotposter\AssetModules;
use parrotposter\PostsListTable;

AssetModules::enqueue(['block', 'nav-tab', 'common', 'modal', 'posts']);

if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
	Api::delete_post(sanitize_text_field($_GET['id']));
	$url = 'admin.php?page=parrotposter_posts';
	if (isset($_GET['paged'])) {
		$url .= '&paged=' . $_GET['paged'];
	}
	wp_redirect($url);
}

$table = new PostsListTable();
$table->prepare_items();

?>

<?php PP::include_view('header', [
	'title' => __('Posts', 'parrotposter'),
	// 'button_link' => empty($table->items) ? false : [
	// 	'text' => __('+ Add a post', 'parrotposter'),
	// 	'href' => '?page=parrotposter_posts&view=add',
	// ],
]) ?>
<?php PP::include_view('notice') ?>

<?php if (empty($table->items)): ?>

	<div class="parrotposter-empty-block">
		<div class="parrotposter-empty-block__title">
			<?php _e('You don\'t have posts yet', 'parrotposter') ?>
		</div>

		<div class="parrotposter-empty-block__note">
			<?php _e('Set up auto-posting in the Scheduler or post directly from an article, product, etc', 'parrotposter') ?>
		</div>
	</div>

<?php else: ?>

	<div class="parrotposter-table__wrap">
		<?php $table->display() ?>
	</div>

	<?php PP::include_view('posts/detail')?>

	<div id="parrotposter-post-delete-confirm" class="parrotposter-modal">
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

<?php endif ?>

