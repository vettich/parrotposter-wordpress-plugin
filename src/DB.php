<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class DB
{
	public static function autoposting_to_db($data)
	{
		if (isset($data['conditions'])) {
			$data['conditions'] = empty($data['conditions']) ? '[]' : json_encode($data['conditions']);
		}

		if (isset($data['account_ids'])) {
			$data['account_ids'] = empty($data['account_ids']) ? '[]' : json_encode($data['account_ids']);
		}

		if (isset($data['post_images'])) {
			$data['post_images'] = empty($data['post_images']) ? '[]' : json_encode($data['post_images']);
		}

		return $data;
	}

	public static function autoposting_from_db($data)
	{
		// decode json arrays
		foreach (['conditions', 'account_ids', 'post_images'] as $key) {
			if (empty($data[$key])) {
				$data[$key] = [];
				continue;
			}

			if (!is_string($data[$key])) {
				continue;
			}

			$data[$key] = json_decode($data[$key], true);
		}

		return $data;
	}
}
