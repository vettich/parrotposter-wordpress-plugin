<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class ArrayWrap
{
	private $data = [];

	public function __construct($array)
	{
		if (!empty($array) && is_array($array)) {
			$this->data = $array;
		}
	}

	public function __get($property)
	{
		if (isset($this->data[$property])) {
			return $this->data[$property];
		}
	}

	public function isset($property)
	{
		return isset($this->data[$property]);
	}
}
