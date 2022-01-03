<?php

namespace parrotposter\fields;

defined('ABSPATH') || exit;

class ProductType
{
	public static function get_fields($post_type, $field_types)
	{
		if ($post_type !== 'product') {
			return [];
		}

		$fields = [];
		foreach ($field_types as $type) {
			$fields = array_merge($fields, self::get_fields_by_type($type));
		}
		return $fields;
	}

	private static function get_fields_by_type($type)
	{
		$fn = "get_{$type}_fields";
		return method_exists(get_class(), $fn) ? self::$fn() : [];
	}

	private static function get_text_fields()
	{
		$fields = [
			[
				'key' => '{product_title}',
				'label' => _x('Product title', 'wp_post_field', 'parrotposter'),
			],
			[
				'key' => '{product_short_description}',
				'label' => _x('Product short description', 'wp_post_field', 'parrotposter'),
			],
			[
				'key' => '{product_description}',
				'label' => _x('Product description', 'wp_post_field', 'parrotposter'),
			],
			// [
			// 	'key' => '{product_price}',
			// 	'label' => _x('Price', 'wp_post_field', 'parrotposter'),
			// ],
			[
				'key' => '{product_regular_price}',
				'label' => _x('Regular price', 'wp_post_field', 'parrotposter'),
			],
			[
				'key' => '{product_sale_price}',
				'label' => _x('Sale price', 'wp_post_field', 'parrotposter'),
			],
			[
				'key' => '{product_currency}',
				'label' => _x('Product currency', 'wp_post_field', 'parrotposter'),
			],
			[
				'key' => '{product_height}',
				'label' => _x('Height', 'wp_post_field', 'parrotposter'),
			],
			[
				'key' => '{product_length}',
				'label' => _x('Length', 'wp_post_field', 'parrotposter'),
			],
			[
				'key' => '{product_width}',
				'label' => _x('Width', 'wp_post_field', 'parrotposter'),
			],
			[
				'key' => '{product_weight}',
				'label' => _x('Weight', 'wp_post_field', 'parrotposter'),
			],
		];
		return $fields;
	}

	private static function get_link_fields()
	{
		$fields = [
			[
				'key' => '{product_link}',
				'label' => _x('Product link', 'wp_post_field', 'parrotposter'),
			],
		];
		return $fields;
	}

	private static function get_date_fields()
	{
		$fields = [
		];
		return $fields;
	}

	private static function get_image_fields()
	{
		$fields = [
			[
				'key' => '{product_image}',
				'label' => _x('Product image', 'wp_post_field', 'parrotposter'),
			],
			[
				'key' => '{product_gallery}',
				'label' => _x('Product gallery', 'wp_post_field', 'parrotposter'),
			],
		];
		return $fields;
	}

	public static function get_field_value($field, $post)
	{
		$fn = "get_field_value_{$field}";
		if (!method_exists(get_class(), $fn)) {
			return null;
		}

		if (!function_exists('wc_get_product')) {
			return null;
		}

		$product = wc_get_product($post);
		return self::$fn($product);
	}

	private static function get_field_value_product_title($product)
	{
		return $product->get_title();
	}

	private static function get_field_value_product_short_description($product)
	{
		return $product->get_short_description();
	}

	private static function get_field_value_product_description($product)
	{
		return $product->get_description();
	}

	private static function get_field_value_product_price($product)
	{
		if ($product instanceof \WC_Product_Variable) {
			$min = $product->get_variation_price('min');
			$max = $product->get_variation_price('max');
			return sprintf("%s - %s", $min, $max);
		}
		return $product->get_price();
	}

	private static function get_field_value_product_regular_price($product)
	{
		if ($product instanceof \WC_Product_Variable) {
			$min = $product->get_variation_regular_price('min');
			$max = $product->get_variation_regular_price('max');
			return sprintf("%s - %s", $min, $max);
		}
		return $product->get_regular_price();
	}

	private static function get_field_value_product_sale_price($product)
	{
		if ($product instanceof \WC_Product_Variable) {
			$min = $product->get_variation_sale_price('min');
			$max = $product->get_variation_sale_price('max');
			return sprintf("%s - %s", $min, $max);
		}
		return $product->get_sale_price();
	}

	private static function get_field_value_product_currency($product)
	{
		if (function_exists('get_woocommerce_currency')) {
			return get_woocommerce_currency();
		}
		return '';
	}

	private static function get_field_value_product_height($product)
	{
		$dimension_unit = __(get_option('woocommerce_dimension_unit'), 'woocommerce');
		return ($product->get_height() ?: 0).$dimension_unit;
	}

	private static function get_field_value_product_length($product)
	{
		$dimension_unit = __(get_option('woocommerce_dimension_unit'), 'woocommerce');
		return ($product->get_length() ?: 0).$dimension_unit;
	}

	private static function get_field_value_product_width($product)
	{
		$dimension_unit = __(get_option('woocommerce_dimension_unit'), 'woocommerce');
		return ($product->get_width() ?: 0).$dimension_unit;
	}

	private static function get_field_value_product_weight($product)
	{
		$weight_unit = __(get_option('woocommerce_weight_unit'), 'woocommerce');
		return ($product->get_weight() ?: 0).$weight_unit;
	}

	private static function get_field_value_product_link($product)
	{
		return urldecode($product->get_permalink());
	}

	private static function get_field_value_product_image($product)
	{
		$id = $product->get_image_id();
		return empty($id) ? [] : [$id];
	}

	private static function get_field_value_product_gallery($product)
	{
		$ids = $product->get_gallery_image_ids();
		return empty($ids) ? [] : $ids;
	}
}
