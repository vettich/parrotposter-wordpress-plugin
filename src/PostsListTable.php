<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class PostsListTable extends WPListTable
{
	public function __construct()
	{
		global $status, $page;

		parent::__construct([
			'singular' => __('Post', 'parrotposter'),
			'plural' => __('Posts', 'parrotposter'),
			'ajax' => false,
		]);
	}

	public function no_items()
	{
		_e('No posts found', 'parrotposter');
	}

	public function column_default($item, $column_name)
	{
		if (isset($item[$column_name])) {
			return $item[$column_name];
		}
	}

	public function get_columns()
	{
		return [
			// 'cb' => '<input type="checkbox">',
			'text' => __('Text', 'parrotposter'),
			'publish_at' => __('Publish at', 'parrotposter'),
			'social_networks' => __('Social networks', 'parrotposter'),
			'status' => __('Status', 'parrotposter'),
		];
	}

	public function column_cb($item)
	{
		return '<input type="checkbox" name="parrotposter[items][]" value="'.$item['id'].'" />';
	}

	public function column_text($item)
	{
		if (!isset($item['fields']['text'])) {
			return;
		}

		$actions = [
			'view' => sprintf(
				'<a class="parrotposter-post-view" href="#" data-id="%s">%s</a>',
				$item['id'],
				__('View', 'parrotposter')
			),
			'delete' => sprintf(
				'<a class="parrotposter-post-delete" href="#" data-id="%s">%s</a>',
				$item['id'],
				__('Delete', 'parrotposter')
			),
		];

		return sprintf(
			'%s %s',
			wp_trim_words($item['fields']['text'], 12, '...'),
			$this->row_actions($actions)
		);
	}

	public function column_social_networks($item)
	{
		if (!isset($item['networks']['accounts'])) {
			return;
		}
		return ApiHelpers::list_social_network_names($item['networks']['accounts']);
	}

	public function column_status($item)
	{
		if (!isset($item['status'])) {
			return;
		}
		return ApiHelpers::get_post_status_text($item['status']);
	}

	public function column_publish_at($item)
	{
		if (!isset($item['publish_at'])) {
			return;
		}
		$format = get_option('date_format').' '.get_option('time_format');
		return wp_date($format, ApiHelpers::getTimestamp($item['publish_at']));
	}

	public function prepare_items()
	{
		global $wpdb;

		$columns = $this->get_columns();
		$this->_column_headers = [$columns, [], []];

		list($posts, $paging, $postsCounts) = $this->get_posts();
		$this->items = $posts;

		$this->set_pagination_args([
			'total_items' => $paging['total'],
			'per_page' => $paging['size'],
		]);
	}

	private function get_posts()
	{
		$type = 'all';
		if (isset($_GET['type'])) {
			if ($_GET['type'] == 'published') {
				$type = 'published';
			} elseif ($_GET['type'] == 'scheduled') {
				$type = 'scheduled';
			}
		}

		$filter = [];
		if ($type != 'all') {
			$filter['status'] = $type;
		}

		$pagingArgs = [
			'page' => $this->get_pagenum(),
			'size' => 25,
		];

		$res = Api::list_posts($filter, [], $pagingArgs, true);
		$posts = ApiHelpers::retrieve_response($res, 'posts');
		$paging = ApiHelpers::retrieve_response($res, 'paging');
		$postsCounts = ApiHelpers::retrieve_response($res, 'counts');

		return [
			$posts,
			$paging,
			$postsCounts,
		];
	}
}
