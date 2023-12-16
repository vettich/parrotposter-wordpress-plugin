<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class AutopostingHelpers
{
	public static function label_when_publish($item)
	{

		switch ($item['when_publish']) {
			case 'immediately':
				return __('Immediately upon publishing the post', 'parrotposter');
			case 'delay':
				return sprintf('%s: %d min', __('With a delay', 'parrotposter'), $item['publish_delay']);
		}
	}

	public static function label_socials_networks($item)
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
}
