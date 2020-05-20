<?php
namespace V2ex;

use Redis;

class Vote
{
    /**
     * 主站地址 (代理地址)
     *
     * @var string
     */
    private $host = 'https://www.v2ex.com';

    /**
     * 可配置项
     *
     * @var array
     */
    private $config = [
        'redis_host'   => '127.0.0.1',
        'redis_port'   => 6379,
        'redis_auth'   => null,
        'show_tpl'     => 'tpl.svg',
        'error_tpl'    => 'error_tpl.svg',
        'refresh_time' => 60, // 每隔1分钟更新一次
        'save_time'    => 60 * 60 * 24, // 保存一天
        'match_rule'   => '/#(?<item>[^#]+)#/'
    ];

    /**
     * 帖子ID
     *
     * @var string
     */
    private $tid = null;

    /**
     * 标题
     *
     * @var string
     */
    private $title = '';

    /**
     * 储存 hash 的 Key
     *
     * @var string
     */
    private $hash_key = '';

    /**
     * 选项
     *
     * @var array
     */
    private $items = [];

    /**
     * 已投票用户
     *
     * @var array
     */
    private $vote_users = [];

    /**
     * 错误代码
     *
     * @var int
     */
    private $errno = null;

    /**
     * 错误描述
     *
     * @var string
     */
    private $error_message = '';

    /**
     * 生成选项
     *
     * @var array
     */
    private $options = [
        'canvas_width'    => 500,
        'update_time'     => 0,
        'reply_last_time' => '',
        'reply_last_page' => 1,
        'vote_count'      => 0,
        'max_conut'       => 0
    ];

    /**
     * cUrl 对象
     *
     * @var cUrl
     */
    private $ch = null;

    /**
     * Redis
     *
     * @var Redis
     */
    private $redis = null;

    /**
     * @param array $config  配置选项
     */
    public function __construct(array $config = null)
    {
        $config && $this->setConfig($config);
    }

    /**
     * 设置配置项
     *
     * @param array $config 配置项
     * @return void
     */
    public function setConfig(array $config)
    {
        foreach ($config as $key => $value) {
            if (substr($key, 0, 2) == 'do') {
                $key = strtolower(substr($key, 2));
                if (property_exists($this, $key)) {
                    $this->{$key} = $value;
                }
            } elseif (isset($this->config[$key])) {
                $this->config[$key] = $value;
            }
        }
    }

    /**
     * 设置错误信息
     *
     * @param int $code 错误码
     * @param string $message 消息描述
     * @return void
     */
    private function setError(int $code, string $message): void
    {
        if (!$this->errno) {
            $this->errno = $code;
            $this->error_message = $message;
        }
    }

    /**
     * 获取错误信息
     *
     * @return array|null
     */
    public function getError()
    {
        if (!$this->errno) {
            return null;
        }

        return [
            'code' => $this->errno,
            'message' => $this->error_message
        ];
    }

    /**
     * 设置 帖子ID
     *
     * @param string $tid 帖子 ID
     * @return bool
     */
    public function setTid(string $tid): bool
    {
        if (!preg_match('#^[0-9]+$#', $tid)) {
            $this->setError(1001, '帖子 ID 不正确');
            return false;
        }

        $this->tid = $tid;

        return true;
    }

    /**
     * 设置标题
     *
     * @param string $title 标题
     * @return bool
     */
    public function setTitle(string $title): bool
    {
        if (mb_strlen($title) > 30 || mb_strlen($title) < 5) {
            $this->setError(1002, '标题太"长"或太"短"(5-20个字符)');
            return false;
        }

        $this->title = htmlspecialchars($title);
        return true;
    }

    /**
     * 设置选择项
     *
     * @param array $items 选择项列表
     * @return bool
     */
    public function setItems(array $items): bool
    {
        if (count($items) < 2) {
            $this->setError(1003, '可选择选项太少(最少两个)');
            return false;
        }

        // 检查每一个选项
        foreach ($items as $item) {
            if (mb_strlen($item) > 20 || mb_strlen($item) < 1) {
                $this->setError(1004, '某个选项太"长"或太"短"(5-20个字符)');
                return false;
            }

            // 安全转义
            $item = htmlspecialchars($item);

            if (isset($this->items[$item])) {
                $this->setError(1005, '选项有重复');
                return false;
            }

            $this->items[$item] = [
                'item_title' => $item,
                'count'      => 0,
                'color'      => $this->getColor($item)
            ];
        }

        return true;
    }

    /**
     * 获取 Key
     *
     * @param string $tid   帖子 ID
     * @param string $title 投票标题
     * @return string
     */
    private function getHashKey(string $tid = null, string $title = null): string
    {
        if (!$tid && !$title && $this->hash_key) {
            return $this->hash_key;
        }

        return $this->hash_key = sprintf(
            'v2Vote::%s:%s',
            $tid ?? $this->tid,
            substr(md5($title ?? $this->title), 6, 6)
        );
    }

    /**
     * 获取更新缓存的锁 Key
     *
     * @param string $tid
     * @return string
     */
    private function getLockKey(string $tid = null): string
    {
        return sprintf('v2Vote::Lock:%s', $tid ?? $this->tid);
    }

    /**
     * 更新锁
     *
     * @param bool $isLock 是否锁定
     * @return bool
     */
    private function refreshLock(bool $isLock = true): bool
    {
        $key = $this->getLockKey();
        $redis = $this->getRedis();

        return $isLock ? $redis->setnx($key, 'lock') : $redis->del($key) == 1;
    }

    /**
     * 获取投票数量
     *
     * @return void
     */
    public function getVote(): bool
    {
        // 读缓存成功就没事了
        if ($this->getFromCache() || !$this->refreshLock(true)) {
            return true;
        }

        // 测试 随机生成
        if (!$this->tid) {
            foreach ($this->items as $key => $item) {
                $this->items[$key]['count'] = mt_rand(0, 50);
            }
        } else {
            $html     = $this->getPage($this->tid);
            $now_page = $this->getPageInfo($html);

            // 检查回复更新时间
            if ($this->options['reply_last_time'] != $now_page['last_time']) {
                // 获取前面未更新的页面的投票
                for ($i = $this->options['reply_last_page']; $i < $now_page['max_page']; $i++) {
                    $this_page = $this->getPageInfo($this->getPage($this->tid, $i));
                    $this->updateVote($this_page['reply_list']);
                }

                // 获取最后新一页的投票
                $this->updateVote($now_page['reply_list']);

                // 保存到全局
                $this->options['reply_last_time'] = $now_page['last_time'];
                $this->options['reply_last_page'] = $now_page['this_page'];
            }
        }

        // 更新总票数统计
        $vote_count = 0;
        $max = 0;
        foreach ($this->items as $key => $item) {
            $vote_count += $item['count'];
            $max = $item['count'] > $max ? $item['count'] : $max;
        }
        $this->options['max_conut'] = $max;
        $this->options['vote_count'] = $vote_count;
        $this->options['update_time'] = time();

        // 保存到缓存
        $this->saveToCache();

        // 解锁
        $this->refreshLock(false);

        return true;
    }

    /**
     * 更新投票记录
     *
     * @param array $reply_list
     * @param array $vote_users
     * @return void
     */
    private function updateVote(array $reply_list)
    {
        foreach ($reply_list as $reply_info) {
            $items = $this->extractReply2Vote($reply_info['content']);
            if ($items !== false) {
                $this->updateItemCount($items, $reply_info['user']);
            }
        }
    }

    /**
     * 更新单项目统计票数
     *
     * @param array $item
     * @param string $user
     * @return void
     */
    private function updateItemCount(array $items, string $user): void
    {
        // 已投票 或 错误选项
        if (isset($this->vote_users[$user])) {
            return;
        }

        // 选项要去重
        $items = array_flip($items);

        // 遍历每个选项
        foreach ($items as $item => $v) {
            if (isset($this->items[$item])) {
                $this->items[$item]['count']++;
                $this->vote_users[$user] = [$item];
                $this->options['vote_count']++;
                break; // 暂时只能投一个选项一票
            }
        }
    }

    /**
     * 提取回复中的投票信息
     *
     * @param string $reply
     * @return array|bool
     */
    private function extractReply2Vote(string $reply_content)
    {
        if (!preg_match_all($this->config['match_rule'], $reply_content, $votes)) {
            return false;
        }

        return $votes['item'];
    }

    /**
     * 提取页面详情
     *
     * @param string $html
     * @return array
     */
    private function getPageInfo(string $html): array
    {
        // 页面详情
        $page_info = [
            'last_time'  => '', // 最后回复时间
            'this_page'  => 1, // 当前页
            'max_page'   => 1, // 最大页
            'reply_list' => [] // 回复列表
        ];

        // 获取最后回复时间
        $regex = '#<span class="gray">[^\/]+[^>]+>\s*&nbsp;(?<time>[^<]+)<\/span>#';
        if (!preg_match($regex, $html, $_info)) {
            return $page_info;
        }
        $page_info['last_time']  = $_info['time'] ?? '';

        // 获取页码信息
        $regex = '#<input[^>]+class="page_input"[^>]+value="(?<this>[0-9]+)"[^>]+max="(?<max>[0-9]+)"#';
        if (preg_match($regex, $html, $_info)) {
            $page_info['this_page'] = intval($_info['this']);
            $page_info['max_page']  = intval($_info['max']);
        }

        // 获取回复列表
        $regex = '#<div id="r_(?<rid>[0-9]+)"[^>]+>(?<body>.*?)<\/table>\s*<\/div>#s';
        if (!preg_match_all($regex, $html, $replys)) {
            return $page_info;
        }

        // 处理每个回复
        $regexs = [
            'no'      => '#<span class="no">(?<no>[0-9]+)<\/span>#',
            'user'    => '#class="dark">(?<user>[^<]+)<\/a>#',
            'content' => '#<div class="reply_content">(?<content>.+?)<\/div>\s*<\/td>#s'
        ];
        foreach ($replys['body'] as $id => $reply_body) {
            $reply_info = [
                'id'      => $replys['rid'][$id],
                'no'      => '',
                'user'    => '',
                'content' => '',
            ];
            foreach ($regexs as $key => $regex) {
                preg_match($regex, $reply_body, $match_info);
                $reply_info[$key] = $match_info[$key];
            }

            $page_info['reply_list'][] = $reply_info;
        }

        return $page_info;
    }

    /**
     * 渲染模板
     *
     * @param string $tpl_file
     * @param array $params
     * @return string
     */
    private function render(string $tpl_file, array $params): string
    {
        $tpl_str = file_exists($tpl_file) ? file_get_contents($tpl_file) : '<svg><text y="15">(-1) 模板缺失</text></svg>';
        $replace_map = [
            'search' => [],
            'replace' => []
        ];

        $params = array_merge($params, $this->options);
        foreach ($params as $search => $replace) {
            $replace_map['search'][] = sprintf('${%s}', $search);
            $replace_map['replace'][] = $replace;
        }

        return str_replace($replace_map['search'], $replace_map['replace'], $tpl_str);
    }

    /**
     * 渲染结果
     *
     * @param string $tid
     * @param string $title
     * @param array $items
     * @param array $options
     * @return void
     */
    public function toSvg(array $options = [])
    {
        if ($this->errno || !file_exists($this->config['show_tpl'])) {
            header('Content-Type: image/svg+xml');
            echo $this->render($this->config['error_tpl'], $this->getError());
            exit;
        }

        extract($this->options);

        header('Content-Type: image/svg+xml');
        // 考虑到 CDN 缓存无法区分帖子
        // header('Cache-Control: max-age=' . $this->config['refresh_time']);
        include $this->config['show_tpl'];
    }

    /**
     * 创建 cUrl
     *
     * @return cUrl
     */
    private function getCh()
    {
        if (!$this->ch) {
            $this->ch = curl_init();
            curl_setopt_array($this->ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false
            ]);
        }

        return $this->ch;
    }

    /**
     * 获取帖子内容
     *
     * @param string $tid    帖子 ID
     * @param int $page      页码
     * @param array $options 附加选项
     * @return string
     */
    private function getPage(string $tid, int $page = null, array $options = []): string
    {
        // // 临时加入缓存(调试用)
        // echo "getPage: {$tid}/{$page}\n";
        // $cache_file = sprintf('./cache/%s_%s.html', $tid, $page);
        // if (file_exists($cache_file)) {
        //     return file_get_contents($cache_file);
        // }

        $url = sprintf('%s/t/%s', $this->host, $tid);
        $page && $url .= '?p=' . $page;

        $options[CURLOPT_URL] = $url;

        $ch = $this->getCh();
        curl_setopt_array($ch, $options);

        $html = curl_exec($ch);
        // file_put_contents($cache_file, $html); // 保存到缓存 (调试用)
        return $html;
    }

    /**
     * 获取 Redis 链接
     *
     * @return Redis
     */
    private function getRedis(): Redis
    {
        if (!$this->redis) {
            $config = $this->config;
            $redis  = new Redis();
            if (!$redis->connect($config['redis_host'], $config['redis_port'])) {
                $this->setError(1010, "链接到 Redis 失败");
                return false;
            }

            // 如果需要验证
            if (!empty($config['redis_auth'])) {
                $redis->auth($config['redis_auth']);
            }

            $this->redis = $redis;
        }

        return $this->redis;
    }

    /**
     * 缓存起来
     *
     * @return void
     */
    private function saveToCache(): void
    {
        $key = $this->getHashKey();
        $data = [
            'refresh_time' => time() + $this->config['refresh_time'],
            'tid'          => $this->tid,
            'title'        => $this->title,
            'items'        => $this->items,
            'vote_users'   => $this->vote_users,
            'options'      => $this->options
        ];

        $redis = $this->getRedis();
        $redis->setEx($key, $this->config['save_time'], json_encode($data));
    }

    /**
     * 从缓存获取数据
     *
     * @return void
     */
    private function getFromCache(): bool
    {
        $key = $this->getHashKey();

        $redis = $this->getRedis();
        $json  = $redis->get($key);
        if (!$json) {
            return false;
        }

        $data = json_decode($json, true);

        // 还原到对象
        $this->tid        = $data['tid'];
        $this->title      = $data['title'];
        $this->items      = $data['items'];
        $this->vote_users = $data['vote_users'];
        $this->options    = $data['options'];

        // 检查是否需要更新
        if ($data['refresh_time'] <= time()) {
            return false;
        }

        return true;
    }

    /**
     * 获取一个颜色代码
     *
     * @param string $string
     * @return string
     */
    private function getColor(string $string): string
    {
        $hash = md5($string);
        $h = (base_convert(substr($hash, 0, 2), 16, 10) % 128) + 128;
        $s = base_convert(substr($hash, 2, 2), 16, 10);
        $v = base_convert(substr($hash, 4, 2), 16, 10);
        return $this->convertYuv2Rgb($h, $s, $v);
    }

    /**
     * YUV 颜色转 RGB
     *
     * @param int $y
     * @param int $u
     * @param int $v
     * @return string
     */
    private function convertYuv2Rgb(int $y, int $u, int $v): string
    {
        $rgb = [
            'R' => intval($y + 1.402 * ($u - 128)),
            'G' => intval($y - 0.34414 * ($v - 128) - 0.71414 * ($u - 128)),
            'B' => intval($y + 1.722 * ($v - 128))
        ];
        foreach ($rgb as $key => $value) {
            $rgb[$key] = $value < 0 ? 0 : ($value > 255 ? 255 : $value);
        }

        return sprintf('rgb(%d,%d,%d)', $rgb['R'], $rgb['G'], $rgb['B']);
    }
}
