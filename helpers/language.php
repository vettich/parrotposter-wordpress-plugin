<?php

if (!defined('ABSPATH')) {
	die;
}

if (!function_exists('parrotposter__')) {
	function parrotposter__($msg, ...$values)
	{
		return esc_html(sprintf(__($msg, 'parrotposter'), ...$values));
	}
}

if (!function_exists('parrotposter_e')) {
	function parrotposter_e($msg, ...$values)
	{
		echo esc_html(sprintf(__($msg, 'parrotposter'), ...$values));
	}
}

if (!function_exists('parrotposter_n')) {
	function parrotposter_n($single, $plural, $cnt, ...$values)
	{
		return esc_html(sprintf(_n($single, $plural, $cnt, 'parrotposter'), ...$values));
	}
}
