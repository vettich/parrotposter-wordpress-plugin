<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class AutopostingListTable extends WPListTable
{
	public function __construct()
	{
		global $status, $page;

		parent::__construct([
			'singular' => __('Autoposting', 'parrotposter'),
			'plural' => __('Autopostings', 'parrotposter'),
			'ajax' => false,
		]);
	}

	public function no_items()
	{
		_e('No autoposting found', 'parrotposter');
	}

	public function get_columns()
	{
		return [
			// 'cb' => '<input type="checkbox">',
			'name' => __('Name', 'parrotposter'),
			'wp_post_type' => __('WP Post type', 'parrotposter'),
			'when_publish' => __('When publish', 'parrotposter'),
			'social_networks' => __('Social networks', 'parrotposter'),
			'enable' => __('Enable', 'parrotposter'),
		];
	}

	public function column_cb($item)
	{
		return '<input type="checkbox" name="parrotposter[items][]" value="'.$item['id'].'" />';
	}

	public function column_name($item)
	{
		$actions = [
			'edit' => sprintf(
				'<a href="?page=%s&view=%s&id=%s">%s</a>',
				// link
				$_REQUEST['page'],
				'autoposting_edit',
				$item['id'],
				// label
				__('Edit', 'parrotposter')
			),
			'delete' => sprintf(
				'<a href="?page=%s&action=%s&id=%s" onclick="%s(event, %s)">%s</a>',
				// link
				$_REQUEST['page'],
				'delete',
				$item['id'],
				// js callback
				'parrotposter_autoposting_delete',
				$item['id'],
				// label
				__('Delete', 'parrotposter')
			),
		];

		$name = sprintf(
			'<a href="?page=%s&view=%s&id=%s">%s</a>',
			$_REQUEST['page'],
			'autoposting_edit',
			$item['id'],
			$item['name']
		);

		return sprintf(
			'%s %s',
			$name,
			$this->row_actions($actions)
		);
	}

	public function column_enable($item)
	{
		$js_onclick = "parrotposter_autoposting_enable(event, '{$item['id']}')";
		ob_start(); ?>
			<label class="parrotposter-input parrotposter-input--toggle">
				<?php FormHelpers::render_checkbox('', $item['enable'], $js_onclick) ?>
			</label>
		<?php
		$content = ob_get_contents();
		ob_end_clean();
		return $content;
	}

	public function column_wp_post_type($item)
	{
		$wp_pt = get_post_type_object($item['wp_post_type']);
		if (empty($wp_pt)) {
			return '-';
		}
		return $wp_pt->label;
	}

	public function column_when_publish($item)
	{
		switch ($item['when_publish']) {
		case 'immediately':
			return __('Immediately upon publishing the post', 'parrotposter');
		case 'delay':
			return sprintf('%s: %d min', __('With a delay', 'parrotposter'), $item['publish_delay']);
		}
	}

	public function column_social_networks($item)
	{
		if (!isset($item['account_ids'])) {
			return;
		}
		$text = ApiHelpers::list_social_network_names($item['account_ids']);
		if (empty($text)) {
			$text = esc_html(__('<Not selected>', 'parrotposter'));
			$text = "<span style=\"color: #d63638\">$text</span>";
		}
		return $text;
	}

	public function prepare_items()
	{
		global $wpdb;

		$columns = $this->get_columns();
		$this->_column_headers = [$columns, [], []];

		$per_page = 20;
		$current_page = $this->get_pagenum();

		$this->items = DBAutopostingTable::get_list($per_page, ($current_page - 1) * $per_page);

		$this->set_pagination_args([
			'total_items' => DBAutopostingTable::get_total_count(),
			'per_page' => $per_page,
		]);
	}

	public function display()
	{
		AssetModules::enqueue(['modal', 'loading', 'autoposting_list_table']);
		parent::display(); ?>
			<div id="parrotposter-autoposting-delete-confirm" class="parrotposter-modal">
				<div class="parrotposter-modal__container">
					<div class="parrotposter-modal__close"></div>
					<div class="parrotposter-modal__title"
						data-title="<?php _e('Are you sure you want to delete {autoposting_name}?', 'parrotposter') ?>">
					</div>
					<div class="parrotposter-modal__footer">
						<button class="button button-primary parrotposter-button--delete"
							onclick="parrotposter_autoposting_delete_confirm(event)">
							<?php _e('Delete', 'parrotposter') ?>
						</button>
						<button class="button parrotposter-js-close"><?php _e('Cancel', 'parrotposter') ?></button>
					</div>
				</div>
			</div>
		<?php
	}
}
