<?php

defined('ABSPATH') || exit;

use parrotposter\PP;
use parrotposter\AssetModules;
use parrotposter\AutopostingListTable;
use parrotposter\FormHelpers;
use parrotposter\DBAutopostingTable;

AssetModules::enqueue(['block', 'input', 'nav-tab', 'common']);

if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
	DBAutopostingTable::delete($_GET['id']);
	wp_redirect('admin.php?page=parrotposter_scheduler');
}

$table = new AutopostingListTable();
$table->prepare_items();
?>

<?php PP::include_view('scheduler/header', [
	'tab' => 'autoposting',
	'show_button' => !empty($table->items),
]) ?>

<?php if (empty($table->items)): ?>

	<div class="parrotposter-empty-block">
		<div class="parrotposter-empty-block__title">
			<?php _e('You don\'t have autopublications yet', 'parrotposter') ?>
		</div>

		<div class="parrotposter-empty-block__connect">
			<a href="admin.php?page=parrotposter_scheduler&view=autoposting_add" class="button button-secondary">
				<?php _e('+ Add autoposting', 'parrotposter') ?>
			</a>
		</div>
	</div>

<?php else: ?>

	<br>

	<form method="post" class="parrotposter-table__wrap">
		<input type="hidden" name="page" value="parrotposter_scheduler">
		<?php FormHelpers::the_nonce() ?>
		<?php $table->display() ?>
	</form>

<?php endif ?>
