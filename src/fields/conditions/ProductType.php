<?php

namespace parrotposter\fields\conditions;

defined('ABSPATH') || exit;

class ProductType
{
	public static function get_fields($post_type)
	{
		if ($post_type != 'product') {
			return [];
		}

		$fields = [
			[
				'key' => 'product_regular_price',
				'label' => _x('Product regular price', 'wp_post_field', 'parrotposter'),
				'ops' => [
					'equal' => _x('Equal', 'wp_post_condition', 'parrotposter'),
					'not_equal' => _x('Not equal', 'wp_post_condition', 'parrotposter'),
					'less' => _x('Less', 'wp_post_condition', 'parrotposter'),
					'less_or_equal' => _x('Less or equal', 'wp_post_condition', 'parrotposter'),
					'greater' => _x('Greater', 'wp_post_condition', 'parrotposter'),
					'greater_or_equal' => _x('Greater or equal', 'wp_post_condition', 'parrotposter'),
				],
				'input' => 'number',
			],
			[
				'key' => 'product_regular_price_min',
				'label' => _x('Product min regular price', 'wp_post_field', 'parrotposter'),
				'ops' => [
					'equal' => _x('Equal', 'wp_post_condition', 'parrotposter'),
					'not_equal' => _x('Not equal', 'wp_post_condition', 'parrotposter'),
					'less' => _x('Less', 'wp_post_condition', 'parrotposter'),
					'less_or_equal' => _x('Less or equal', 'wp_post_condition', 'parrotposter'),
					'greater' => _x('Greater', 'wp_post_condition', 'parrotposter'),
					'greater_or_equal' => _x('Greater or equal', 'wp_post_condition', 'parrotposter'),
				],
				'input' => 'number',
			],
			[
				'key' => 'product_regular_price_max',
				'label' => _x('Product max regular price', 'wp_post_field', 'parrotposter'),
				'ops' => [
					'equal' => _x('Equal', 'wp_post_condition', 'parrotposter'),
					'not_equal' => _x('Not equal', 'wp_post_condition', 'parrotposter'),
					'less' => _x('Less', 'wp_post_condition', 'parrotposter'),
					'less_or_equal' => _x('Less or equal', 'wp_post_condition', 'parrotposter'),
					'greater' => _x('Greater', 'wp_post_condition', 'parrotposter'),
					'greater_or_equal' => _x('Greater or equal', 'wp_post_condition', 'parrotposter'),
				],
				'input' => 'number',
			],
			[
				'key' => 'product_sale_price',
				'label' => _x('Product sale price', 'wp_post_field', 'parrotposter'),
				'ops' => [
					'equal' => _x('Equal', 'wp_post_condition', 'parrotposter'),
					'not_equal' => _x('Not equal', 'wp_post_condition', 'parrotposter'),
					'less' => _x('Less', 'wp_post_condition', 'parrotposter'),
					'less_or_equal' => _x('Less or equal', 'wp_post_condition', 'parrotposter'),
					'greater' => _x('Greater', 'wp_post_condition', 'parrotposter'),
					'greater_or_equal' => _x('Greater or equal', 'wp_post_condition', 'parrotposter'),
				],
				'input' => 'number',
			],
			[
				'key' => 'product_sale_price_min',
				'label' => _x('Product min sale price', 'wp_post_field', 'parrotposter'),
				'ops' => [
					'equal' => _x('Equal', 'wp_post_condition', 'parrotposter'),
					'not_equal' => _x('Not equal', 'wp_post_condition', 'parrotposter'),
					'less' => _x('Less', 'wp_post_condition', 'parrotposter'),
					'less_or_equal' => _x('Less or equal', 'wp_post_condition', 'parrotposter'),
					'greater' => _x('Greater', 'wp_post_condition', 'parrotposter'),
					'greater_or_equal' => _x('Greater or equal', 'wp_post_condition', 'parrotposter'),
				],
				'input' => 'number',
			],
			[
				'key' => 'product_sale_price_max',
				'label' => _x('Product max sale price', 'wp_post_field', 'parrotposter'),
				'ops' => [
					'equal' => _x('Equal', 'wp_post_condition', 'parrotposter'),
					'not_equal' => _x('Not equal', 'wp_post_condition', 'parrotposter'),
					'less' => _x('Less', 'wp_post_condition', 'parrotposter'),
					'less_or_equal' => _x('Less or equal', 'wp_post_condition', 'parrotposter'),
					'greater' => _x('Greater', 'wp_post_condition', 'parrotposter'),
					'greater_or_equal' => _x('Greater or equal', 'wp_post_condition', 'parrotposter'),
				],
				'input' => 'number',
			],
			[
				'key' => 'product_type',
				'label' => _x('Product type', 'wp_post_field', 'parrotposter'),
				'ops' => [
					'equal' => _x('Equals one of', 'wp_post_condition', 'parrotposter'),
					'not_equal' => _x('Not equal to one of', 'wp_post_condition', 'parrotposter'),
				],
				'input' => 'select',
				'values' => self::get_product_types(),
				'multi' => true,
			],
		];
		return $fields;
	}

	public static function check($cond, $post)
	{
		$fn = "check_{$cond['key']}";
		if (!method_exists(get_class(), $fn)) {
			return null;
		}

		if (!function_exists('wc_get_product')) {
			return null;
		}

		$product = wc_get_product($post);
		return self::$fn($cond, $product);
	}

	private static function check_product_regular_price($cond, $product)
	{
		return Operations::apply($cond['op'], $product->get_regular_price(), $cond['value']);
	}

	private static function check_product_regular_price_min($cond, $product)
	{
		if ($product instanceof \WC_Product_Variable) {
			$min = $product->get_variation_regular_price('min');
			return Operations::apply($cond['op'], $min, $cond['value']);
		}

		return Operations::apply($cond['op'], $product->get_regular_price(), $cond['value']);
	}

	private static function check_product_regular_price_max($cond, $product)
	{
		if ($product instanceof \WC_Product_Variable) {
			$max = $product->get_variation_regular_price('max');
			return Operations::apply($cond['op'], $max, $cond['value']);
		}

		return Operations::apply($cond['op'], $product->get_regular_price(), $cond['value']);
	}

	private static function check_product_sale_price($cond, $product)
	{
		return Operations::apply($cond['op'], $product->get_sale_price(), $cond['value']);
	}

	private static function check_product_sale_price_min($cond, $product)
	{
		if ($product instanceof \WC_Product_Variable) {
			$min = $product->get_variation_sale_price('min');
			return Operations::apply($cond['op'], $min, $cond['value']);
		}

		return Operations::apply($cond['op'], $product->get_sale_price(), $cond['value']);
	}

	private static function check_product_sale_price_max($cond, $product)
	{
		if ($product instanceof \WC_Product_Variable) {
			$max = $product->get_variation_sale_price('max');
			return Operations::apply($cond['op'], $max, $cond['value']);
		}

		return Operations::apply($cond['op'], $product->get_sale_price(), $cond['value']);
	}

	private static function check_product_type($cond, $product)
	{
		return Operations::apply($cond['op'], $product->get_type(), $cond['value']);
	}

	private static function get_product_types()
	{
		if (!function_exists('wc_get_product_types')) {
			return [];
		}

		$list = [];
		$types = wc_get_product_types();
		foreach (wc_get_product_types() as $key => $label) {
			$list[] = [
				'key' => $key,
				'label' => $label,
			];
		}
		return $list;
	}
}
