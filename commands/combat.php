<?php
/**
 * 查看战斗状态命令 (combat)
 * 显示当前战斗信息
 */
require_once DAEMON_PATH . 'CombatDaemon.php';

function cmd_combat(int $charId, string $param = ''): array {
    $me = CharacterModel::find($charId);
    
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 检查是否在战斗中
    $combat = CombatDaemon::getCombatStatus($charId);
    if (!$combat) {
        return ['success' => false, 'message' => '你目前没有在进行战斗。'];
    }
    
    // 获取玩家当前状态
    $hpPercent = $me['max_kee'] > 0 ? intval(($me['kee'] / $me['max_kee']) * 100) : 0;
    
    // 构建状态消息
    $message = HIR . '【战斗状态】' . NOR . "\n";
    $message .= "━━━━━━━━━━━━━━━━━━━━━━━\n";
    $message .= '目标：' . HICYN . $combat['target_name'] . NOR . "\n";
    $message .= '类型：' . ($combat['target_type'] === 'npc' ? 'NPC' : '玩家') . "\n";
    $message .= '你的气血：' . HIYEL . $me['kee'] . '/' . $me['max_kee'] . NOR . " ({$hpPercent}%)\n";
    $message .= '战斗回合：' . ($combat['round'] ?? 0) . "\n";
    $message .= '战斗时长：' . (time() - $combat['start_time']) . " 秒\n";
    $message .= "━━━━━━━━━━━━━━━━━━━━━━━\n";
    $message .= HIGRN . '提示：使用 hit 继续攻击，或使用 flee 逃跑' . NOR;
    
    return [
        'success' => true,
        'type' => 'combat_status',
        'output' => $message,
        'in_combat' => true,
        'target_name' => $combat['target_name'],
        'player_hp' => $me['kee'],
        'player_max_hp' => $me['max_kee'],
        'round' => $combat['round'] ?? 0
    ];
}

// 别名：sc (show combat)
if (!function_exists('cmd_sc')) {
    function cmd_sc(int $charId, string $param = ''): array {
        return cmd_combat($charId, $param);
    }
}

