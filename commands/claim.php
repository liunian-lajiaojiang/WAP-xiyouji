<?php
/**
 * 领取赏金命令 (claim) - 三花堂领取悬赏赏金
 * 用法：claim <玩家ID> 或 claim
 * @param int $charId 角色ID
 * @param string $arg 命令参数
 */
function cmd_claim(int $charId, string $arg = ''): array {
    require_once __DIR__ . '/../models/SanhuaBounty.php';
    require_once __DIR__ . '/../models/Corpse.php';
    
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 检查是否在三花堂密室
    $currentRoom = $char['current_room'] ?? '';
    if ($currentRoom !== 'city/sanhua-mishi') {
        return ['success' => false, 'message' => '你要领取赏金，得去三花堂密室找打手。'];
    }
    
    // 检查过期悬赏
    SanhuaBounty::checkExpiredBounties();
    
    $arg = trim($arg);
    
    if (empty($arg)) {
        // 没有指定玩家，检查房间里的尸体和携带的尸体
        return claim_from_all_corpses($charId, $char);
    } else {
        // 指定了玩家ID，检查该玩家是否被悬赏，并且当前玩家是击杀者
        return claim_by_target_id($charId, $char, $arg);
    }
}

/**
 * 从所有尸体（房间中的 + 携带的）领取赏金
 */
function claim_from_all_corpses(int $charId, array $char): array {
    $currentArea = $char['current_area'] ?? '';
    $currentRoom = $char['current_room'] ?? '';
    
    $totalReward = 0;
    $claimedTargets = [];
    
    // 1. 检查房间里的尸体
    $roomCorpses = Corpse::getCorpsesInRoom($currentArea, $currentRoom);
    
    foreach ($roomCorpses as $corpse) {
        // 只处理玩家尸体
        if ($corpse['owner_type'] !== 'player') {
            continue;
        }
        
        $targetId = intval($corpse['owner_id']);
        $targetName = $corpse['owner_name'];
        $killerId = intval($corpse['killer_id'] ?? 0);
        
        // 检查是否是击杀者
        if ($killerId !== $charId) {
            continue;
        }
        
        // 检查是否有悬赏
        $bounty = SanhuaBounty::getBountyByTargetId($targetId);
        if (!$bounty) {
            continue;
        }
        
        $amount = $bounty['amount'];
        
        // 领取赏金
        $claimed = SanhuaBounty::claimBounty($targetId, $charId, $char['name'] ?? '');
        if ($claimed) {
            // 存入玩家帐户（1两黄金 = 10000文）
            $amountInCoin = $amount * 10000;
            $balance = intval($char['balance'] ?? 0);
            $newBalance = $balance + $amountInCoin;
            $sql = "UPDATE characters SET balance = ? WHERE id = ?";
            Database::execute($sql, [$newBalance, $charId]);
            
            // 销毁尸体
            $sql = "DELETE FROM corpses WHERE id = ?";
            Database::execute($sql, [$corpse['id']]);
            
            $totalReward += $amount;
            $claimedTargets[] = $targetName . "(" . $amount . "两)";
        }
    }
    
    // 2. 检查携带的尸体
    $carriedCorpses = Corpse::getCarriedCorpses($charId);
    
    foreach ($carriedCorpses as $corpse) {
        // 只处理玩家尸体
        if ($corpse['owner_type'] !== 'player') {
            continue;
        }
        
        $targetId = intval($corpse['owner_id']);
        $targetName = $corpse['owner_name'];
        $killerId = intval($corpse['killer_id'] ?? 0);
        
        // 检查是否是击杀者
        if ($killerId !== $charId) {
            continue;
        }
        
        // 检查是否有悬赏
        $bounty = SanhuaBounty::getBountyByTargetId($targetId);
        if (!$bounty) {
            continue;
        }
        
        $amount = $bounty['amount'];
        
        // 领取赏金
        $claimed = SanhuaBounty::claimBounty($targetId, $charId, $char['name'] ?? '');
        if ($claimed) {
            // 存入玩家帐户（1两黄金 = 10000文）
            $amountInCoin = $amount * 10000;
            $balance = intval($char['balance'] ?? 0);
            $newBalance = $balance + $amountInCoin;
            $sql = "UPDATE characters SET balance = ? WHERE id = ?";
            Database::execute($sql, [$newBalance, $charId]);
            
            // 销毁尸体
            Corpse::buryCorpse($corpse['id']);
            
            $totalReward += $amount;
            $claimedTargets[] = $targetName . "(" . $amount . "两)";
        }
    }
    
    if ($totalReward > 0) {
        $message = "你从三花堂领取了" . $totalReward . "两黄金的赏金！\n";
        $message .= "被你领取赏金的目标：" . implode('、', $claimedTargets) . "\n";
        $message .= "赏金已存入你的帐户。\n";
        
        $broadcastMessage = $char['name'] . "从三花堂领取了赏金。\n";
        
        return [
            'success' => true,
            'message' => $message,
            'broadcast_message' => $broadcastMessage
        ];
    } else {
        return ['success' => false, 'message' => '这里没有你可以领取赏金的尸体。'];
    }
}

/**
 * 根据目标玩家ID领取赏金
 */
function claim_by_target_id(int $charId, array $char, string $targetIdStr): array {
    // 查找目标玩家
    $targetChar = null;
    if (is_numeric($targetIdStr)) {
        $targetChar = CharacterModel::find(intval($targetIdStr));
    }
    
    if (!$targetChar) {
        $sql = "SELECT * FROM characters WHERE id = ? OR user_id = ? OR name LIKE ? LIMIT 1";
        $targetChar = Database::queryOne($sql, [$targetIdStr, $targetIdStr, $targetIdStr . '%']);
    }
    
    if (!$targetChar) {
        return ['success' => false, 'message' => '找不到 ' . $targetIdStr . ' 这个人。'];
    }
    
    $targetId = intval($targetChar['id']);
    $targetName = $targetChar['name'] ?? '';
    
    // 检查是否有悬赏
    $bounty = SanhuaBounty::getBountyByTargetId($targetId);
    if (!$bounty) {
        return ['success' => false, 'message' => $targetName . '目前没有被悬赏。'];
    }
    
    // 检查是否是击杀者（检查最近的尸体记录）
    $currentArea = $char['current_area'] ?? '';
    $currentRoom = $char['current_room'] ?? '';
    
    $sql = "SELECT * FROM corpses WHERE owner_type = 'player' AND owner_id = ? AND killer_id = ? AND room_area = ? AND room_id = ? AND decay_time > NOW() ORDER BY created_at DESC LIMIT 1";
    $corpse = Database::queryOne($sql, [$targetId, $charId, $currentArea, $currentRoom]);
    
    if (!$corpse) {
        return ['success' => false, 'message' => '你没有击杀 ' . $targetName . '，或者尸体不在这里。'];
    }
    
    $amount = $bounty['amount'];
    
    // 领取赏金
    $claimed = SanhuaBounty::claimBounty($targetId, $charId, $char['name'] ?? '');
    if ($claimed) {
        // 存入玩家帐户（1两黄金 = 10000文）
        $amountInCoin = $amount * 10000;
        $balance = intval($char['balance'] ?? 0);
        $newBalance = $balance + $amountInCoin;
        $sql = "UPDATE characters SET balance = ? WHERE id = ?";
        Database::execute($sql, [$newBalance, $charId]);
        
        // 销毁尸体
        $sql = "DELETE FROM corpses WHERE id = ?";
        Database::execute($sql, [$corpse['id']]);
        
        $message = "你从三花堂领取了" . $amount . "两黄金的赏金！\n";
        $message .= "目标：" . $targetName . "\n";
        $message .= "赏金已存入你的帐户。\n";
        
        $broadcastMessage = $char['name'] . "从三花堂领取了" . $targetName . "的赏金。\n";
        
        return [
            'success' => true,
            'message' => $message,
            'broadcast_message' => $broadcastMessage
        ];
    } else {
        return ['success' => false, 'message' => '领取赏金失败。'];
    }
}
