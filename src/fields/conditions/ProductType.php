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
				'label' => parrotposter__('Product regular price'),
				'ops' => [
					'equal' => parrotposter_x('Equal', 'wp_post_condition'),
					'not_equal' => parrotposter_x('Not equal', 'wp_post_condition'),
					'less' => parrotposter_x('Less', 'wp_post_condition'),
					'less_or_equal' => parrotposter_x('Less or equal', 'wp_post_condition'),
					'greater' => parrotposter_x('Greater', 'wp_post_condition'),
					'greater_or_equal' => parrotposter_x('Greater or equal', 'wp_post_condition'),
				],
				'input' => 'number',
			],
			[
				'key' => 'product_regular_price_min',
				'label' => parrotposter__('Product min regular price'),
				'ops' => [
					'equal' => parrotposter_x('Equal', 'wp_post_condition'),
					'not_equal' => parrotposter_x('Not equal', 'wp_post_condition'),
					'less' => parrotposter_x('Less', 'wp_post_condition'),
					'less_or_equal' => parrotposter_x('Less or equal', 'wp_post_condition'),
					'greater' => parrotposter_x('Greater', 'wp_post_condition'),
					'greater_or_equal' => parrotposter_x('Greater or equal', 'wp_post_condition'),
				],
				'input' => 'number',
			],
			[
				'key' => 'product_regular_price_max',
				'label' => parrotposter__('Product max regular price'),
				'ops' => [
					'equal' => parrotposter_x('Equal', 'wp_post_condition'),
					'not_equal' => parrotposter_x('Not equal', 'wp_post_condition'),
					'less' => parrotposter_x('Less', 'wp_post_condition'),
					'less_or_equal' => parrotposter_x('Less or equal', 'wp_post_condition'),
					'greater' => parrotposter_x('Greater', 'wp_post_condition'),
					'greater_or_equal' => parrotposter_x('Greater or equal', 'wp_post_condition'),
				],
				'input' => 'number',
			],
			[
				'key' => 'product_sale_price',
				'label' => parrotposter__('Product sale price'),
				'ops' => [
					'equal' => parrotposter_x('Equal', 'wp_post_condition'),
					'not_equal' => parrotposter_x('Not equal', 'wp_post_condition'),
					'less' => parrotposter_x('Less', 'wp_post_condition'),
					'less_or_equal' => parrotposter_x('Less or equal', 'wp_post_condition'),
					'greater' => parrotposter_x('Greater', 'wp_post_condition'),
					'greater_or_equal' => parrotposter_x('Greater or equal', 'wp_post_condition'),
				],
				'input' => 'number',
			],
			[
				'key' => 'product_sale_price_min',
				'label' => parrotposter__('Product min sale price'),
				'ops' => [
					'equal' => parrotposter_x('Equal', 'wp_post_condition'),
					'not_equal' => parrotposter_x('Not equal', 'wp_post_condition'),
					'less' => parrotposter_x('Less', 'wp_post_condition'),
					'less_or_equal' => parrotposter_x('Less or equal', 'wp_post_condition'),
					'greater' => parrotposter_x('Greater', 'wp_post_condition'),
					'greater_or_equal' => parrotposter_x('Greater or equal', 'wp_post_condition'),
				],
				'input' => 'number',
			],
			[
				'key' => 'product_sale_price_max',
				'label' => parrotposter__('Product max sale price'),
				'ops' => [
					'equal' => parrotposter_x('Equal', 'wp_post_condition'),
					'not_equal' => parrotposter_x('Not equal', 'wp_post_condition'),
					'less' => parrotposter_x('Less', 'wp_post_condition'),
					'less_or_equal' => parrotposter_x('Less or equal', 'wp_post_condition'),
					'greater' => parrotposter_x('Greater', 'wp_post_condition'),
					'greater_or_equal' => parrotposter_x('Greater or equal', 'wp_post_condition'),
				],
				'input' => 'number',
			],
			[
				'key' => 'product_type',
				'label' => parrotposter__('Product type'),
				'ops' => [
					'equal' => parrotposter_x('Equals one of', 'wp_post_condition'),
					'not_equal' => parrotposter_x('Not equal to one of', 'wp_post_condition'),
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
