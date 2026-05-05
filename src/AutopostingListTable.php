<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class AutopostingListTable extends WPListTable
{
	public function __construct()
	{
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
		return '<input type="checkbox" name="parrotposter[items][]" value="' . $item['id'] . '" />';
	}

	/**
	 * Slug admin.php?page=… только из белого списка (защита от reflected XSS в query).
	 */
	private function get_request_admin_page_slug()
	{
		$page = isset($_REQUEST['page']) ? sanitize_text_field(wp_unslash($_REQUEST['page'])) : '';
		$allowed = [
			'parrotposter',
			'parrotposter_posts',
			'parrotposter_accounts',
			'parrotposter_scheduler',
			'parrotposter_tariffs',
			'parrotposter_profile',
			'parrotposter_help',
		];
		if (in_array($page, $allowed, true)) {
			return $page;
		}

		return 'parrotposter_scheduler';
	}

	public function column_name($item)
	{
		$page = $this->get_request_admin_page_slug();
		$id = isset($item['id']) ? (int) $item['id'] : 0;

		$edit_url = add_query_arg(
			[
				'page' => $page,
				'view' => 'autoposting_edit',
				'id' => $id,
			],
			admin_url('admin.php')
		);

		$delete_url = add_query_arg(
			[
				'page' => $page,
				'action' => 'delete',
				'id' => $id,
			],
			admin_url('admin.php')
		);

		$onclick = sprintf('parrotposter_autoposting_delete(event, %d)', $id);

		$actions = [
			'edit' => sprintf(
				'<a href="%s">%s</a>',
				esc_url($edit_url),
				esc_html(__('Edit', 'parrotposter'))
			),
			'delete' => sprintf(
				'<a href="%s" onclick="%s">%s</a>',
				esc_url($delete_url),
				esc_attr($onclick),
				esc_html(__('Delete', 'parrotposter'))
			),
		];

		$name = sprintf(
			'<a href="%s">%s</a>',
			esc_url($edit_url),
			esc_html(isset($item['name']) ? $item['name'] : '')
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
		return AutopostingHelpers::label_when_publish($item);
	}

	public function column_social_networks($item)
	{
		return AutopostingHelpers::label_socials_networks($item);
	}

	public function prepare_items()
	{
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
				<div class="parrotposter-modal__title" data-title="<?php _e('Are you sure you want to delete {autoposting_name}?', 'parrotposter') ?>">
				</div>
				<div class="parrotposter-modal__footer">
					<button class="button button-primary parrotposter-button--delete" onclick="parrotposter_autoposting_delete_confirm(event)">
						<?php _e('Delete', 'parrotposter') ?>
					</button>
					<button class="button parrotposter-js-close"><?php _e('Cancel', 'parrotposter') ?></button>
				</div>
			</div>
		</div>
<?php
	}
}
