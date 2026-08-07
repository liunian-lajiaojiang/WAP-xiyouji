<?php
/**
 * 悬赏命令 (pay) - 三花堂悬赏杀人
 * 用法：pay <amount> gold for <id>
 * @param int $charId 角色ID
 * @param string $arg 命令参数
 */
function cmd_pay(int $charId, string $arg = ''): array {
    require_once __DIR__ . '/../models/SanhuaBounty.php';
    
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 检查是否在三花堂密室
    $currentRoom = $char['current_room'] ?? '';
    if ($currentRoom !== 'city/sanhua-mishi') {
        return ['success' => false, 'message' => '你要悬赏杀人，得去三花堂密室找打手。'];
    }
    
    // 解析参数
    if (empty($arg)) {
        return ['success' => false, 'message' => '用法：pay <数量> gold for <玩家ID>'];
    }
    
    // 匹配格式：<amount> gold for <id>
    if (!preg_match('/^(\d+)\s+gold\s+for\s+(.+)$/i', $arg, $matches)) {
        return ['success' => false, 'message' => '你要悬赏多少黄金去杀谁？用法：pay <数量> gold for <玩家ID>'];
    }
    
    $amount = intval($matches[1]);
    $targetIdStr = trim($matches[2]);
    
    // 检查金额范围
    if ($amount < 1) {
        return ['success' => false, 'message' => '需要至少一两黄金。'];
    }
    if ($amount > 1000) {
        return ['success' => false, 'message' => '一次最多一千两黄金。'];
    }
    
    // 检查余额（balance 单位是文，1两黄金 = 10000文）
    $balance = intval($char['balance'] ?? 0);
    $amountInCoin = $amount * 10000; // 转换为文
    
    if ($balance < $amountInCoin) {
        return ['success' => false, 'message' => '你的帐户里没有这么多钱。'];
    }
    
    // 检查被悬赏的玩家是否存在
    // 先尝试按ID查找
    $targetChar = null;
    if (is_numeric($targetIdStr)) {
        $targetChar = CharacterModel::find(intval($targetIdStr));
    }
    
    // 如果没找到，尝试按 user_id 或 name 查找
    if (!$targetChar) {
        $sql = "SELECT * FROM characters WHERE id = ? OR user_id = ? OR name LIKE ? LIMIT 1";
        $targetChar = Database::queryOne($sql, [$targetIdStr, $targetIdStr, $targetIdStr . '%']);
    }
    
    if (!$targetChar) {
        return ['success' => false, 'message' => '找不到 ' . $targetIdStr . ' 这个人。'];
    }
    
    $targetId = intval($targetChar['id']);
    $targetName = $targetChar['name'] ?? '';
    
    // 不能悬赏自己
    if ($targetId == $charId) {
        return ['success' => false, 'message' => '你不能悬赏自己。'];
    }
    
    // 检查是否是巫师（这里简化处理，假设没有巫师系统）
    // 原始项目中有 wizardp(me) 检查
    
    // 检查过期悬赏
    SanhuaBounty::checkExpiredBounties();
    
    // 检查悬赏数量限制
    $bountyCount = SanhuaBounty::getBountyCount();
    $existingBounty = SanhuaBounty::getBountyByTargetId($targetId);
    
    if (!$existingBounty && $bountyCount >= 2000) {
        return ['success' => false, 'message' => '被悬赏追缉的玩家数太多了。'];
    }
    
    // 扣除余额
    $newBalance = $balance - $amountInCoin;
    $sql = "UPDATE characters SET balance = ? WHERE id = ?";
    Database::execute($sql, [$newBalance, $charId]);
    
    // 添加悬赏
    $sponsorName = $char['name'] ?? '';
    $bounty = SanhuaBounty::addBounty($targetId, $targetName, $amount, $charId, $sponsorName);
    
    // 悬赏 >= 50 两时，给被悬赏者发消息
    if ($amount >= 50) {
        // 这里可以添加消息通知
        // 暂时不实现，因为需要消息系统
    }
    
    $totalAmount = $bounty['amount'];
    
    // 构造消息
    $selfMessage = "你出" . $amount . "两黄金悬赏" . $targetName . "(" . $targetIdStr . ")头颅，目前总赏金" . $totalAmount . "两。\n";
    $broadcastMessage = $char['name'] . "出" . $amount . "两黄金悬赏" . $targetName . "(" . $targetIdStr . ")头颅，目前总赏金" . $totalAmount . "两。\n";
    
    return [
        'success' => true,
        'message' => $selfMessage,
        'broadcast_message' => $broadcastMessage,
        'bounty' => $bounty
    ];
}
