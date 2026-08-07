<?php
/**
 * AI 玩家定时触发任务
 * 
 * 用法：
 *   php tasks/AiPlayerTickTask.php
 * 
 * 建议执行频率：每5-10秒
 * 每次执行处理3个 AI 玩家，实现均匀分布
 * 
 * 在宝塔面板设置定时任务：
 *   任务类型：Shell脚本
 *   任务名称：AI玩家行为驱动
 *   执行周期：每5秒
 *   脚本内容：
 *     C:\BtSoft\php\85\php.exe U:\xyj\tasks\AiPlayerTickTask.php
 */

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../helpers/AiPlayerHelper.php';
require_once __DIR__ . '/../daemons/AiPlayerDaemon.php';

// 处理 AI 玩家 tick（每次处理3个）
$result = AiPlayerDaemon::runTick(3);

// 输出结果
$timestamp = date('Y-m-d H:i:s');
echo "[{$timestamp}] AI玩家 Tick 完成\n";
echo "  在线AI玩家: {$result['total']} 个\n";
echo "  本次处理: {$result['processed']} 个\n";

foreach (($result['results'] ?? []) as $r) {
    $status = ($r['success'] ?? false) ? 'OK' : 'FAIL';
    $name = $r['char_name'] ?? ("ID:" . ($r['char_id'] ?? '?'));
    $detail = $r['ai_detail'] ?? $r['message'] ?? '';
    echo "  [{$status}] {$name} -> {$detail}\n";
}
