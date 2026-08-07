<?php
/**
 * 逃脱法宝命令 - out
 * 被困者用来尝试逃出法宝
 * 
 * 流程:
 * 1. 检查角色是否被困（FabaoHelper::isTrapped）
 * 2. 已到期则直接释放
 * 3. 未到期则基于kar值尝试挣脱
 */

require_once __DIR__ . '/../helpers/FabaoHelper.php';

// 加载技能配置
static $_skillCosts = null;
if ($_skillCosts === null) {
    $_skillCosts = require __DIR__ . '/../config/skill_costs.php';
}
require_once __DIR__ . '/../daemons/MessageDaemon.php';

function cmd_out(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 检查是否被困
    $trapState = FabaoHelper::isTrapped($charId);
    if (!$trapState) {
        return ['success' => false, 'message' => '你并没有被困在什么地方。'];
    }
    
    $trapType = $trapState['trap_type'] ?? 'trap';
    $charName = $char['name'] ?? '某人';
    
    // 检查是否已到期
    $releaseTime = strtotime($trapState['release_at']);
    $now = time();
    
    if ($now >= $releaseTime) {
        // 时间已到，直接释放
        $result = FabaoHelper::releaseFromFabao($charId);
        
        if ($trapType === 'bind') {
            $selfMsg = HTML_HIGRN . '你挣脱了束缚，恢复了行动自由！' . HTML_NOR;
            $roomMsg = HTML_HICYN . $charName . '挣脱了束缚，恢复了行动自由！' . HTML_NOR;
        } else {
            $selfMsg = HTML_HIGRN . '你从法宝中脱身而出！浑身精气有所损耗。' . HTML_NOR;
            $roomMsg = HTML_HICYN . $charName . '从法宝中脱身而出！浑身精气有所损耗。' . HTML_NOR;
        }
        
        // 广播给同房间玩家
        $roomId = $char['current_room'] ?? '';
        if (!empty($roomId)) {
            MessageDaemon::broadcastToRoom($roomId, $roomMsg, $charId);
        }
        
        return [
            'success' => true,
            'message' => $selfMsg,
            'released' => true,
            'trap_type' => $trapType
        ];
    }
    
    // 未到期：尝试挣脱（基于kar值判定）
    // 挣脱概率 = kar / 5（最低1%，最高20%）
    $escapeChance = min(20, max(1, intval(($char['kar'] ?? 100) / 5)));
    
    if (mt_rand(1, 100) <= $escapeChance) {
        // 挣脱成功
        $result = FabaoHelper::releaseFromFabao($charId);
        
        if ($trapType === 'bind') {
            $selfMsg = HTML_HIGRN . '你挣脱了束缚，恢复了行动自由！' . HTML_NOR;
            $roomMsg = HTML_HICYN . $charName . '挣脱了束缚，恢复了行动自由！' . HTML_NOR;
        } else {
            $selfMsg = HTML_HIGRN . '你从法宝中脱身而出！浑身精气有所损耗。' . HTML_NOR;
            $roomMsg = HTML_HICYN . $charName . '从法宝中脱身而出！浑身精气有所损耗。' . HTML_NOR;
        }
        
        // 广播给同房间玩家
        $roomId = $char['current_room'] ?? '';
        if (!empty($roomId)) {
            MessageDaemon::broadcastToRoom($roomId, $roomMsg, $charId);
        }
        
        return [
            'success' => true,
            'message' => $selfMsg,
            'released' => true,
            'escape_attempt' => true,
            'trap_type' => $trapType
        ];
    }
    
    // 挣脱失败
    $remaining = $releaseTime - $now;
    
    if ($trapType === 'bind') {
        $selfMsg = HTML_HIYEL . '你挣扎了一下，但绳索缠得更紧了。' . HTML_NOR 
                 . HTML_HIRED . '剩余约' . $remaining . '秒。' . HTML_NOR;
    } else {
        $selfMsg = HTML_HIYEL . '你尝试冲出法宝，但四周的力量牢牢锁住了你。' . HTML_NOR 
                 . HTML_HIRED . '剩余约' . $remaining . '秒。' . HTML_NOR;
    }
    
    return [
        'success' => false,
        'message' => $selfMsg,
        'released' => false,
        'escape_attempt' => true,
        'remaining_seconds' => $remaining,
        'trap_type' => $trapType
    ];
}
