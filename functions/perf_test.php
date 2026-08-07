<?php
/**
 * 性能测试脚本 - 测量房间页面加载时间
 */

session_save_path(__DIR__ . '/../sessions');
session_start();

define('IN_GAME', true);
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

// 包装 Database 类来统计查询次数
$GLOBALS['query_count'] = 0;
$GLOBALS['query_times'] = [];

class DatabaseProxy {
    private static $original = null;

    public static function init() {
        // 保存原始查询方法
    }

    public static function queryAll($sql, $params = []) {
        global $query_count, $query_times;
        $query_count++;
        $start = microtime(true);
        $result = Database::queryAll($sql, $params);
        $query_times[] = ['sql' => substr($sql, 0, 80), 'time' => (microtime(true) - $start) * 1000];
        return $result;
    }

    public static function queryOne($sql, $params = []) {
        global $query_count, $query_times;
        $query_count++;
        $start = microtime(true);
        $result = Database::queryOne($sql, $params);
        $query_times[] = ['sql' => substr($sql, 0, 80), 'time' => (microtime(true) - $start) * 1000];
        return $result;
    }

    public static function execute($sql, $params = []) {
        global $query_count, $query_times;
        $query_count++;
        $start = microtime(true);
        $result = Database::execute($sql, $params);
        $query_times[] = ['sql' => substr($sql, 0, 80), 'time' => (microtime(true) - $start) * 1000];
        return $result;
    }
}

$totalStart = microtime(true);

// 模拟 room.php 的加载流程
$charId = get_char_id();

if (!$charId) {
    echo "请先登录游戏！\n";
    exit;
}

// 加载模型
require_once MODEL_PATH . 'Character.php';
require_once MODEL_PATH . 'Room.php';
require_once MODEL_PATH . 'Item.php';
require_once DAEMON_PATH . 'CombatDaemon.php';
require_once DAEMON_PATH . 'NatureDaemon.php';

$char = CharacterModel::find($charId);
echo "角色加载: " . round((microtime(true) - $totalStart) * 1000, 2) . "ms\n";
echo "当前查询数: " . $GLOBALS['query_count'] . "\n\n";

$t1 = microtime(true);
$charFull = CharacterModel::getFullInfo($charId);
echo "角色完整信息: " . round((microtime(true) - $t1) * 1000, 2) . "ms\n";
echo "当前查询数: " . $GLOBALS['query_count'] . "\n\n";

$t2 = microtime(true);
$room = RoomModel::getFullInfo($char['current_area'], $char['current_room']);
echo "房间完整信息: " . round((microtime(true) - $t2) * 1000, 2) . "ms\n";
echo "当前查询数: " . $GLOBALS['query_count'] . "\n\n";

$t3 = microtime(true);
$items = ItemModel::getCharacterItems($charId);
echo "背包物品: " . round((microtime(true) - $t3) * 1000, 2) . "ms\n";
echo "当前查询数: " . $GLOBALS['query_count'] . "\n\n";

$t4 = microtime(true);
$inCombat = CombatDaemon::isInCombat($charId);
echo "战斗状态检查: " . round((microtime(true) - $t4) * 1000, 2) . "ms\n";
echo "当前查询数: " . $GLOBALS['query_count'] . "\n\n";

$totalTime = (microtime(true) - $totalStart) * 1000;
echo "========================\n";
echo "总耗时: " . round($totalTime, 2) . "ms\n";
echo "总查询数: " . $GLOBALS['query_count'] . "\n";
echo "平均查询耗时: " . round($totalTime / $GLOBALS['query_count'], 2) . "ms\n";
echo "\n最慢的 5 个查询:\n";

usort($GLOBALS['query_times'], function($a, $b) {
    return $b['time'] - $a['time'];
});

for ($i = 0; $i < min(5, count($GLOBALS['query_times'])); $i++) {
    $q = $GLOBALS['query_times'][$i];
    echo "  " . ($i + 1) . ". " . round($q['time'], 2) . "ms - " . $q['sql'] . "\n";
}
