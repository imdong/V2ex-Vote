<?php
// ini_set("display_errors", "On");
// error_reporting(E_ALL | E_STRICT);
date_default_timezone_set('Asia/Shanghai');

/**
 * 引入投票对象
 */
require './Vote.php';

/**
 * 获取配置信息
 */
$config = require './config.php';

$referrer = $_SERVER['HTTP_REFERER'] ?? null;
$title    = $_GET['title'];
$items    = array_map("urldecode", explode('|', $_GET['items']));

// 构建对象
$vote = new V2ex\Vote();

// 获取可配置项
$allow_config = ['width'];
$config = array_merge(array_intersect_key($_GET, array_flip($allow_config)), $config);
if (!empty($config)) {
    $vote->setConfig($config);
}

// 设置
$vote->setTitle($title);
$vote->setItems($items);
if ($referrer && preg_match('#/t/(?<tid>[0-9]+)#', $referrer, $tid_match)) {
    $vote->setTid($tid_match['tid']);
}

// 获取投票数量
$vote->getVote();

// 输出图像
$vote->toSvg();
