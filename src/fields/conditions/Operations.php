<?php

namespace parrotposter\fields\conditions;

defined('ABSPATH') || exit;

use parrotposter\Tools;

class Operations
{
	public static function apply($op, $left, $right)
	{
		$fn = "apply_{$op}";
		if (!method_exists(get_class(), $fn)) {
			return null;
		}

		return self::$fn($left, $right);
	}

	private static function apply_equal($left, $right)
	{
		$left_is_array = is_array($left);
		$right_is_array = is_array($right);

		$res = false;
		if ($left_is_array && $right_is_array) {
			foreach ($left as $l) {
				if (Tools::in_array($l, $right)) {
					$res = true;
					break;
				}
			}
		} elseif (!$left_is_array && $right_is_array) {
			$res = Tools::in_array($left, $right);
		} elseif ($left_is_array && !$right_is_array) {
			$res = Tools::in_array($right, $left);
		} else {
			$res = $left == $right;
		}

		return $res;
	}

	private static function apply_not_equal($left, $right)
	{
		$res = self::apply_equal($left, $right);
		return !$res;
	}

	private static function apply_less($left, $right)
	{
		return $left < $right;
	}

	private static function apply_greater($left, $right)
	{
		return $left > $right;
	}

	private static function apply_less_or_equal($left, $right)
	{
		$res = self::apply_greater($left, $right);
		return !$res;
	}

	private static function apply_greater_or_equal($left, $right)
	{
		$res = self::apply_less($left, $right);
		return !$res;
	}

	private static function apply_include($left, $right)
	{
		$left_is_array = is_array($left);
		$right_is_array = is_array($right);

		$res = false;
		if ($left_is_array && $right_is_array) {
			// @TODO
		} elseif (!$left_is_array && $right_is_array) {
			// @TODO
		} elseif ($left_is_array && !$right_is_array) {
			// @TODO
		} else {
			$res = stripos($left, $right) !== false;
		}

		return $res;
	}

	private static function apply_not_include($left, $right)
	{
		$res = self::apply_include($left, $right);
		return !$res;
	}
}
