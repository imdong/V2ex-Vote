<?php

return [
    'redis_host'   => 'redis',
    'redis_port'   => 6379,
    'redis_auth'   => null,
    'show_tpl'     => 'tpl.svg',
    'error_tpl'    => 'error_tpl.svg',
    'refresh_time' => 60, // 每隔1分钟更新一次
    'save_time'    => 60 * 60 * 24, // 保存一天
    'match_rule'   => '/#(?<item>[^#]+)#/'
];
