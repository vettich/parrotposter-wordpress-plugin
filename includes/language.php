<?php

if (!defined('ABSPATH')) {
	die;
}

if (!function_exists('parrotposter__')) {
	function parrotposter__($msg, ...$values)
	{
		foreach ($values as $k => $v) {
			$values[$k] = esc_html($v);
		}
		return sprintf(__($msg, 'parrotposter'), ...$values);
	}
}

if (!function_exists('parrotposter_e')) {
	function parrotposter_e($msg, ...$values)
	{
		foreach ($values as $k => $v) {
			$values[$k] = esc_html($v);
		}
		echo sprintf(__($msg, 'parrotposter'), ...$values);
	}
}

if (!function_exists('parrotposter_n')) {
	function parrotposter_n($single, $plural, $cnt, ...$values)
	{
		foreach ($values as $k => $v) {
			$values[$k] = esc_html($v);
		}
		return sprintf(_n($single, $plural, $cnt, 'parrotposter'), ...$values);
	}
}

if (!function_exists('parrotposter_x')) {
	function parrotposter_x($msg, $context, ...$values)
	{
		foreach ($values as $k => $v) {
			$values[$k] = esc_html($v);
		}
		return sprintf(_x($msg, $context, 'parrotposter'), ...$values);
	}
}

if (!function_exists('parrotposter_xe')) {
	function parrotposter_xe($msg, $context, ...$values)
	{
		foreach ($values as $k => $v) {
			$values[$k] = esc_html($v);
		}
		echo sprintf(_x($msg, $context, 'parrotposter'), ...$values);
	}
}
