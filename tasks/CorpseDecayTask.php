<?php
/**
 * 尸体腐烂定时任务
 * 推进尸体腐烂阶段 + 清理过期尸体（散落物品到房间后删除）
 * 
 * 建议执行频率：每30秒 ~ 1分钟
 * 用法：php tasks/CorpseDecayTask.php
 */

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../models/Corpse.php';

// 第一步：推进腐烂阶段
$changes = Corpse::advanceDecayPhases();
if (!empty($changes)) {
    $count = count($changes);
    echo date('Y-m-d H:i:s') . " - 推进了 {$count} 具尸体的腐烂阶段\n";
    foreach ($changes as $change) {
        $phaseNames = [0 => '新鲜', 1 => '腐烂', 2 => '骸骨'];
        $oldName = $phaseNames[$change['old_phase']] ?? '未知';
        $newName = $phaseNames[$change['new_phase']] ?? '未知';
        echo "  尸体#{$change['corpse_id']}({$change['owner_name']}): {$oldName} -> {$newName}\n";
    }
}

// 第二步：清理过期尸体（物品散落到房间后删除）
$deleted = Corpse::cleanupDecayedCorpses();
if ($deleted > 0) {
    echo date('Y-m-d H:i:s') . " - 清理了 {$deleted} 具过期尸体\n";
}

if (empty($changes) && $deleted === 0) {
    echo date('Y-m-d H:i:s') . " - 无需处理\n";
}
