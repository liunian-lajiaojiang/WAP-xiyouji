<?php
/**
 * 显示西游时间
 * 点击后发送一条时间消息，同时出现在 room 和 chat 消息中
 */

// 开启输出缓冲，避免 header 已经发送的问题
ob_start();

session_save_path(__DIR__ . '/../sessions');
session_start();

header('Content-Type: application/json; charset=utf-8');

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

require_once DAEMON_PATH . 'NatureDaemon.php';

// 检查登录
if (!isset($_SESSION['char_id'])) {
    echo json_encode(['success' => false, 'error' => '未登录']);
    ob_end_flush();
    exit;
}

$charId = intval($_SESSION['char_id']);

try {
    // 防止短时间内重复发送（1秒内最多发送一次）
    $recentMsg = Database::queryOne(
        "SELECT id FROM message_queue 
         WHERE char_id = ? AND type = 'room' AND message LIKE '现在是西游时间%'
         AND created_at > DATE_SUB(NOW(), INTERVAL 1 SECOND)
         ORDER BY id DESC LIMIT 1",
        [$charId]
    );
    
    if ($recentMsg) {
        echo json_encode([
            'success' => true,
            'message' => '请勿频繁点击'
        ]);
        ob_end_flush();
        exit;
    }
    
    // 获取游戏时间信息
    $gameHour = NatureDaemon::getGameHour();
    $gameSeconds = time() % 1440;
    $gameMinute = $gameSeconds % 60;
    $phase = NatureDaemon::getCurrentPhase();
    $season = NatureDaemon::getCurrentSeason();
    
    // 时辰对应（游戏小时0对应子时前半）
    $shichenMap = [
        0 => '子时', 1 => '丑时', 2 => '寅时', 3 => '卯时',
        4 => '辰时', 5 => '巳时', 6 => '午时', 7 => '未时',
        8 => '申时', 9 => '酉时', 10 => '戌时', 11 => '亥时',
        12 => '子时', 13 => '丑时', 14 => '寅时', 15 => '卯时',
        16 => '辰时', 17 => '巳时', 18 => '午时', 19 => '未时',
        20 => '申时', 21 => '酉时', 22 => '戌时', 23 => '亥时'
    ];
    
    $shichen = $shichenMap[$gameHour] ?? '未知';
    
    // 构建时间消息
    $timeMsg = "现在是西游时间：{$season['name']}，{$phase['period_name']}，{$shichen}（{$gameHour}时{$gameMinute}分）";
    
    // 发送 room 消息（room 和 chat 页面都会显示）
    $sql = "INSERT INTO message_queue (char_id, message, type, is_html, from_char_id, created_at) 
            VALUES (?, ?, 'room', 0, 0, NOW())";
    Database::execute($sql, [$charId, $timeMsg]);
    
    echo json_encode([
        'success' => true,
        'message' => $timeMsg
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

ob_end_flush();
