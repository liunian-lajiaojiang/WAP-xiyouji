<?php
/**
 * NPC独立攻击命令 (npc_attack)
 * 还原LPC heart_beat机制：NPC有自己的心跳，独立于玩家发起攻击
 * 由前端定时器触发，不执行玩家攻击逻辑
 */
require_once DAEMON_PATH . 'CombatDaemon.php';

function cmd_npc_attack(int $charId, string $param = ''): array {
    // 检查是否在战斗中
    if (!CombatDaemon::isInCombat($charId)) {
        return ['success' => false, 'message' => '', 'debug' => 'not_in_combat'];
    }
    
    // 执行NPC独立攻击回合
    $result = CombatDaemon::doNpcTurn($charId);
    
    if (!$result['success']) {
        $result['debug'] = 'doNpcTurn_failed';
        return $result;
    }
    
    return [
        'success' => true,
        'type' => 'npc_attack',
        'output' => $result['message'] ?? '',
        'message' => $result['message'] ?? '',  // 兼容前端
        'damage' => $result['damage'] ?? 0,
        'player_damage' => $result['player_damage'] ?? 0,
        'killed' => $result['killed'] ?? false,
        'player_hp' => $result['player_hp'] ?? null,
        'debug' => 'ok'
    ];
}
