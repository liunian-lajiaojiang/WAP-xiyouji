<?php
/**
 * 木筏状态查询接口（短轮询 JSON）
 * 
 * 木筏生命周期：at_shore → sailing_away → at_dest → sailing_back → at_shore（65秒循环）
 * 返回当前状态及 trigger_time，前端可用 JS 自行计算进度，避免长连接占用 PHP 进程。
 * 
 * 用法: GET api/mufa_state.php?room=changan/eastseashore
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// 参数验证
$room = $_GET['room'] ?? '';
$allowedRooms = ['changan/eastseashore', 'changan/mufa', 'changan/aolaiws'];

if (!in_array($room, $allowedRooms)) {
    echo json_encode(['error' => 'invalid room'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 状态时间线
$timeline = [
    'at_shore'      => ['next' => 'sailing_away', 'trigger' => 0,  'duration' => 15],
    'sailing_away'  => ['next' => 'at_dest',      'trigger' => 15, 'duration' => 20],
    'at_dest'       => ['next' => 'sailing_back', 'trigger' => 35, 'duration' => 20],
    'sailing_back'  => ['next' => 'at_shore',     'trigger' => 55, 'duration' => 10],
];

// 房间对应的转换消息
$roomMessages = [
    'changan/eastseashore' => [
        'at_shore→sailing_away'  => '一阵浪头打来，木筏缓缓漂去...',
        'sailing_back→at_shore'  => '一只木筏缓缓漂回岸边。',
    ],
    'changan/mufa' => [
        'at_shore→sailing_away'  => '周围是白茫茫一片大海，你已经远离任何陆地的视线...',
        'sailing_away→at_dest'   => '木筏一沉，搁浅了。忽然竟是登陆之处，赶紧上去罢。',
        'at_dest→sailing_back'   => '一阵浪头打来，木筏缓缓漂去...',
    ],
    'changan/aolaiws' => [
        'sailing_away→at_dest'   => '木筏已经靠岸，可以上船了。',
        'at_dest→sailing_back'   => '木筏缓缓离开岸边，向大海深处漂去...',
    ],
];

$stateFile = __DIR__ . '/../data/mufa_state.json';

// 计算当前状态
function calcTrueState(string $stateFile, array $timeline): array {
    if (!file_exists($stateFile)) {
        return [
            'status' => 'at_shore',
            'elapsed' => 0,
            'remaining' => 15,
            'progress_pct' => 0,
            'trigger_time' => time(),
            'server_time' => time(),
        ];
    }

    $raw = json_decode(file_get_contents($stateFile), true);
    if (!$raw || !isset($raw['trigger_time'])) {
        return [
            'status' => 'at_shore',
            'elapsed' => 0,
            'remaining' => 15,
            'progress_pct' => 0,
            'trigger_time' => time(),
            'server_time' => time(),
        ];
    }

    $now = time();
    $elapsed = $now - $raw['trigger_time'];

    // 循环：65秒一圈
    if ($elapsed >= 65) {
        $elapsed = $elapsed % 65;
    }

    // 找当前状态
    $status = 'at_shore';
    foreach ($timeline as $key => $info) {
        if ($elapsed >= $info['trigger']) {
            $status = $key;
        } else {
            break;
        }
    }

    // 计算进度
    $info = $timeline[$status];
    $phaseElapsed = $elapsed - $info['trigger'];
    $remaining = max(0, $info['duration'] - $phaseElapsed);
    $progressPct = $info['duration'] > 0 ? min(100, ($phaseElapsed / $info['duration']) * 100) : 100;

    return [
        'status'       => $status,
        'elapsed'      => $elapsed,
        'remaining'    => $remaining,
        'progress_pct' => round($progressPct, 1),
        'trigger_time' => intval($raw['trigger_time']),
        'server_time'  => $now,
    ];
}

$result = calcTrueState($stateFile, $timeline);
$result['room'] = $room;
$result['messages'] = $roomMessages[$room] ?? new stdClass();
$result['timeline'] = $timeline;

echo json_encode($result, JSON_UNESCAPED_UNICODE);
