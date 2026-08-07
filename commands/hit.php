<?php
/**
 * 继续攻击命令 (hit)
 * 在战斗中对当前目标继续攻击
 */
require_once DAEMON_PATH . 'CombatDaemon.php';
require_once DAEMON_PATH . 'DntgQuestHandler.php';

function cmd_hit(int $charId, string $param = ''): array {
    $me = CharacterModel::find($charId);
    
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 检查是否昏迷 (参考原始LPC: living() 函数)
    if (isset($_SESSION["unconscious_{$charId}"])) {
        $unconscious = $_SESSION["unconscious_{$charId}"];
        $elapsed = time() - $unconscious['timestamp'];
        $duration = $unconscious['duration'] ?? 30;
        
        if ($elapsed < $duration) {
            return [
                'success' => false,
                'message' => HTML_HIRED . '你昏迷中，无法攻击！' . HTML_NOR,
                'skip_queue' => true,
            ];
        } else {
            unset($_SESSION["unconscious_{$charId}"]);
        }
    }
    
    // ★ 火焰山石门特殊处理：用石块砸门
    if ($me['current_room'] === 'qujing/firemount/shimen' && 
        (strpos($param, 'shimen') !== false || strpos($param, 'door') !== false || strpos($param, '石门') !== false)) {
        require_once DAEMON_PATH . 'FiremountHandler.php';
        return FiremountHandler::hitShimen($charId);
    }
    
    // 检查是否在战斗中
    if (!CombatDaemon::isInCombat($charId)) {
        return ['success' => false, 'message' => '你没有在战斗中！先用 kill <目标> 发起攻击。'];
    }
    
    // 执行攻击
    $result = CombatDaemon::doAttack($charId);
    
    if (!$result['success']) {
        return $result;
    }
    
    // 如果击杀了NPC，检查大闹天宫任务进度
    if (!empty($result['killed'])) {
        $npcName = $result['target_name'] ?? '';
        $targetType = $result['target_type'] ?? '';
        $roomId = $me['current_room'] ?? '';
        log_game('DNTG_DEBUG', "killed=true, npcName='{$npcName}', targetType='{$targetType}', roomId='{$roomId}'");
        if ($targetType === 'npc' && !empty($npcName) && !empty($roomId)) {
            $dntgResult = DntgQuestHandler::onNpcKilled($charId, $npcName, $roomId);
            log_game('DNTG_DEBUG', "onNpcKilled result: " . json_encode($dntgResult, JSON_UNESCAPED_UNICODE));
            if ($dntgResult && !empty($dntgResult['message'])) {
                // 将大闹天宫任务完成消息追加到战斗消息中
                $result['message'] .= "\n" . $dntgResult['message'];
            }
        } else {
            log_game('DNTG_DEBUG', "条件不满足: targetType!='npc'=" . ($targetType !== 'npc' ? 'Y' : 'N') . " empty(npcName)=" . (empty($npcName) ? 'Y' : 'N') . " empty(roomId)=" . (empty($roomId) ? 'Y' : 'N'));
        }
    }
    
    return [
        'success' => true,
        'type' => 'combat_attack',
        'output' => $result['message'],
        'flee_output' => $result['flee_message'] ?? null,
        'damage' => $result['damage'] ?? 0,
        'player_damage' => $result['player_damage'] ?? 0,
        'killed' => $result['killed'] ?? false,
        'friendly' => $result['friendly'] ?? false,
        'npc_fled' => $result['npc_fled'] ?? false,
        'exp_gain' => $result['exp_gain'] ?? 0,
        'player_hp' => $result['player_hp'] ?? null
    ];
}

// 别名：k 也可以用来继续攻击
if (!function_exists('cmd_k')) {
    function cmd_k(int $charId, string $param = ''): array {
        return cmd_hit($charId, $param);
    }
}

