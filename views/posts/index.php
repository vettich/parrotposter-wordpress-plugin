<?php
defined('ABSPATH') || die;

use parrotposter\PP;
use parrotposter\View;

PP::include_view('header');
View::embed_front('posts');
