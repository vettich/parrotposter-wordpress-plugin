<?php
defined('ABSPATH') || die;

use parrotposter\PP;
use parrotposter\View;

PP::include_view('header');
PP::include_view('local-queue/setup');
View::embed_front('posts');
