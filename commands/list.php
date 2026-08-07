<?php
/**
 * 悬赏列表命令 (list) - 查看三花堂悬赏列表
 * 用法：list 或 list <玩家ID>
 * @param int $charId 角色ID
 * @param string $arg 命令参数
 */
function cmd_list(int $charId, string $arg = ''): array {
    require_once __DIR__ . '/../models/SanhuaBounty.php';
    
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 检查是否在三花堂密室
    $currentRoom = $char['current_room'] ?? '';
    if ($currentRoom !== 'city/sanhua-mishi') {
        return ['success' => false, 'message' => '这里没有悬赏名单。'];
    }
    
    // 检查过期悬赏
    SanhuaBounty::checkExpiredBounties();
    
    $arg = trim($arg);
    
    if (!empty($arg)) {
        // 查看特定玩家的悬赏
        return cmd_list_single($charId, $arg);
    } else {
        // 查看所有悬赏列表
        return cmd_list_all($charId);
    }
}

/**
 * 查看单个玩家的悬赏
 */
function cmd_list_single(int $charId, string $targetIdStr): array {
    // 查找被悬赏的玩家
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
    $bounty = SanhuaBounty::getBountyByTargetId($targetId);
    
    if (!$bounty) {
        return ['success' => true, 'message' => $targetChar['name'] . "目前没有被悬赏。\n"];
    }
    
    $amount = $bounty['amount'];
    $lastAddTime = $bounty['last_add_time'];
    $timeDiff = time() - $lastAddTime;
    
    $days = intval($timeDiff / 86400);
    $hours = intval(($timeDiff % 86400) / 3600);
    
    $timeStr = '';
    if ($days > 0) {
        $timeStr .= $days . '天';
    }
    if ($hours > 0 || $days > 0) {
        $timeStr .= $hours . '小时';
    }
    if ($timeStr === '') {
        $timeStr = '不到一小时';
    }
    
    $message = $targetChar['name'] . "(" . $targetIdStr . ") 被悬赏" . $amount . "两黄金。\n";
    $message .= "距上次增加赏金：" . $timeStr . "前\n";
    
    return ['success' => true, 'message' => $message];
}

/**
 * 查看所有悬赏列表
 */
function cmd_list_all(int $charId): array {
    $bounties = SanhuaBounty::getAllBounties(50, 0);
    
    if (empty($bounties)) {
        return ['success' => true, 'message' => "目前没有悬赏。\n"];
    }
    
    $message = "──────────────────────────────\n";
    $message .= "        三花堂悬赏名单\n";
    $message .= "──────────────────────────────\n";
    $message .= sprintf("%-20s %-10s %s\n", "名字", "赏金", "距上次增加");
    $message .= "──────────────────────────────\n";
    
    foreach ($bounties as $bounty) {
        $targetName = $bounty['target_name'];
        $amount = $bounty['amount'];
        $lastAddTime = $bounty['last_add_time'];
        $timeDiff = time() - $lastAddTime;
        
        $days = intval($timeDiff / 86400);
        $hours = intval(($timeDiff % 86400) / 3600);
        
        $timeStr = '';
        if ($days > 0) {
            $timeStr .= $days . '天';
        }
        if ($hours > 0 || $days > 0) {
            $timeStr .= $hours . '小时';
        }
        if ($timeStr === '') {
            $timeStr = '不到一小时';
        }
        
        $message .= sprintf("%-20s %-10s %s\n", $targetName, $amount . '两', $timeStr . '前');
    }
    
    $message .= "──────────────────────────────\n";
    $message .= "共 " . count($bounties) . " 个悬赏\n";
    
    return ['success' => true, 'message' => $message];
}
