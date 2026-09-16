<?php

namespace parrotposter\Migration;

defined('ABSPATH') || exit;

/**
 * Map legacy when_publish / publish_delay (minutes) to EventTriggerConfigInput.
 */
class TriggerDelayConverter
{
	/**
	 * @param mixed $when_publish
	 * @param mixed $publish_delay
	 * @return array{debounceSeconds: int, changedFieldsCheck: bool}
	 */
	public static function convert($when_publish, $publish_delay)
	{
		$debounce_seconds = 0;
		if ((string) $when_publish === 'delay') {
			$minutes = is_numeric($publish_delay) ? (int) $publish_delay : 0;
			if ($minutes < 0) {
				$minutes = 0;
			}
			$debounce_seconds = $minutes * 60;
		}

		return [
			'debounceSeconds' => $debounce_seconds,
			'changedFieldsCheck' => true,
		];
	}
}
