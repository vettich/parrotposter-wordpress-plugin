<?php

defined('ABSPATH') || die;

use parrotposter\PP;
use parrotposter\AssetModules;
use parrotposter\DBAutopostingTable;

AssetModules::enqueue(['block', 'input', 'nav-tab']);

$id = intval(sanitize_text_field($_GET['id']));

$data = get_transient('parrotposter_autoposting_edit_data');
if ($data !== false) {
	delete_transient('parrotposter_autoposting_edit_data');
	if (!isset($data['id'])) {
		$data['id'] = $id;
	}
} else {
	$data = DBAutopostingTable::get_by_id($id);
}

?>

<?php PP::include_view('header', [
	'title' => __('Edit autoposting', 'parrotposter'),
	'back_url' => 'admin.php?page=parrotposter_scheduler'
]) ?>
<?php PP::include_view('notice') ?>

<?php PP::include_view('scheduler/autoposting_form', [
	'action' => 'parrotposter_autoposting_edit',
	'back_url' => 'admin.php?page=parrotposter_scheduler&view=autoposting_edit&id='.$id,
	'success_url' => 'admin.php?page=parrotposter_scheduler',
	'data' => $data,
]) ?>
