<?php

namespace parrotposter;

class Tools {

	// https://www.php.net/manual/ru/function.array-merge-recursive.php#118727
	public static function array_merge_recursive_distinct ( array &$array1, array &$array2 )
	{
		static $level=0;
		$merged = [];
		if (!empty($array2["mergeWithParent"]) || $level == 0) {
			$merged = $array1;
		}

		foreach ( $array2 as $key => &$value )
		{
			if (is_numeric($key)) {
				$merged [] = $value;
			} else {
				$merged[$key] = $value;
			}

			if ( is_array ( $value ) && isset ( $array1 [$key] ) && is_array ( $array1 [$key] )) {
				$level++;
				$merged [$key] = self::array_merge_recursive_distinct($array1 [$key], $value);
				$level--;
			}
		}
		unset($merged["mergeWithParent"]);
		return $merged;
	}
}
