<?php
/**
 * accept 命令 - 确认各种请求（入魔道、切磋邀请等）
 */

// 加载任务配置
static $_questCfg = null;
if ($_questCfg === null) {
    $_questCfg = require __DIR__ . '/../config/quest.php';
}

function cmd_accept(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    $param = trim($param);
    
    // 如果参数是 fight，处理切磋邀请
    if ($param === 'fight' || $param === '切磋') {
        return acceptFightRequest($charId);
    }
    
    // 默认处理：天魔茧借宝确认
    return acceptTianmojian($charId);
}

/**
 * 接受切磋邀请
 */
function acceptFightRequest(int $charId): array {
    // 查找最新的待处理切磋请求
    $request = Database::queryOne(
        'SELECT * FROM fight_requests 
         WHERE to_character_id = ? AND status = "pending"
         ORDER BY created_at DESC LIMIT 1',
        [$charId]
    );
    
    if (!$request) {
        return ['success' => false, 'message' => '你没有待处理的切磋邀请。'];
    }
    
    // 检查是否过期
    if (!empty($request['expires_at']) && strtotime($request['expires_at']) < time()) {
        // 过期了，标记为已过期
        Database::execute(
            'UPDATE fight_requests SET status = "expired", resolved_at = NOW() WHERE id = ?',
            [$request['id']]
        );
        return ['success' => false, 'message' => '切磋邀请已经过期了。'];
    }
    
    $fromCharId = intval($request['from_character_id']);
    $fromChar = CharacterModel::find($fromCharId);
    
    if (!$fromChar) {
        return ['success' => false, 'message' => '发起邀请的玩家不存在。'];
    }
    
    // 检查对方是否还在战斗中
    require_once DAEMON_PATH . 'CombatDaemon.php';
    if (CombatDaemon::isInCombat($fromCharId)) {
        // 对方已经在战斗中，取消请求
        Database::execute(
            'UPDATE fight_requests SET status = "cancelled", resolved_at = NOW() WHERE id = ?',
            [$request['id']]
        );
        return ['success' => false, 'message' => '对方已经在战斗中了，无法接受邀请。'];
    }
    
    // 检查自己是否在战斗中
    if (CombatDaemon::isInCombat($charId)) {
        return ['success' => false, 'message' => '你正在战斗中，无法接受邀请。'];
    }
    
    // 更新请求状态为已接受
    Database::execute(
        'UPDATE fight_requests SET status = "accepted", resolved_at = NOW() WHERE id = ?',
        [$request['id']]
    );
    
    // 开始友好的战斗
    $result = CombatDaemon::startFight($charId, $fromCharId, 'player');
    
    // 通知发起者（带自动跳转标记和手动进入按钮）
    require_once DAEMON_PATH . 'MessageDaemon.php';
    $charName = $char['name'] ?? '对方';
    
    // 进入战斗的链接
    $fightUrl = "action.php?action=fight&target=" . $charId;
    
    $acceptMsg = '<span style="color:#00FF00;font-weight:bold">【切磋】</span> ';
    $acceptMsg .= "{$charName}接受了你的切磋邀请！";
    $acceptMsg .= " <a href=\"{$fightUrl}\" style=\"color:#00cc00;font-weight:bold;\">[进入战斗]</a> ";
    $acceptMsg .= "（1秒后自动进入）";
    // 添加自动跳转标记
    $acceptMsg .= '<span data-auto-jump="' . $fightUrl . '" style="display:none"></span>';
    
    MessageDaemon::sendPrivateMessage($fromCharId, $acceptMsg, $charId);
    
    // 重定向到战斗页面
    header('Location: fight.php');
    exit;
}

/**
 * 接受天魔茧借宝确认
 */
function acceptTianmojian(int $charId): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    $charName = $char['name'] ?? '你';
    
    // 检查是否有待确认的借宝请求
    $pendingVar = Database::queryOne(
        "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'tianmojian_pending'",
        [$charId]
    );
    
    if (!$pendingVar || empty($pendingVar['temp_value'])) {
        return ['success' => false, 'message' => '你没有需要确认的事情。'];
    }
    
    $pendingData = json_decode($pendingVar['temp_value'], true);
    if (!$pendingData || !isset($pendingData['expire_time'])) {
        return ['success' => false, 'message' => '你没有需要确认的事情。'];
    }
    
    // 检查是否过期
    if ($pendingData['expire_time'] < time()) {
        // 过期了，清除状态
        Database::execute(
            "DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'tianmojian_pending'",
            [$charId]
        );
        return ['success' => false, 'message' => '确认时间已过，请重新向蒸笼老人申请借宝。'];
    }
    
    // 检查蒸笼老人是否在房间里
    $roomId = $char['current_room'];
    $laorenInRoom = false;
    
    // 检查房间里的NPC
    $npcsInRoom = RoomModel::getNpcsInRoom($char['current_area'], $roomId);
    foreach ($npcsInRoom as $npc) {
        if (($npc['npc_id'] ?? '') === 'zhenglonglaoren') {
            $laorenInRoom = true;
            break;
        }
    }
    
    if (!$laorenInRoom) {
        return ['success' => false, 'message' => '蒸笼老人不在这儿，你无法确认。'];
    }
    
    // 再次检查是否已有天魔茧外借（防止并发）
    $lastJieId = Database::queryOne(
        "SELECT last_jie_id FROM obstacled WHERE id = 1 LIMIT 1"
    );
    
    if ($lastJieId && $lastJieId['last_jie_id'] && intval($lastJieId['last_jie_id']) !== $charId) {
        // 已经被别人借走了
        Database::execute(
            "DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'tianmojian_pending'",
            [$charId]
        );
        return ['success' => false, 'message' => '你来迟一步，天魔茧已经被别人借走了！'];
    }
    
    // 开始事务
    Database::beginTransaction();
    
    try {
        // 1. 设置 no_qujing 标记（以后不能参加取经）
        Database::execute(
            "UPDATE characters SET `obstacle/no_qujing` = 1 WHERE id = ?",
            [$charId]
        );
        
        // 2. 给玩家天魔茧（带完整属性：category、series_no、liquid_remaining）
        require_once __DIR__ . '/../helpers/TianmojianHelper.php';
        TianmojianHelper::giveTianmojian($charId);
        
        // 3. 记录借宝者
        Database::execute(
            "UPDATE obstacled SET last_jie_id = ? WHERE id = 1",
            [$charId]
        );
        
        // 4. 记录借用信息到 tianmojian_borrows 表
        $returnDeadline = date('Y-m-d H:i:s', time() + $_questCfg['expiry']['accept_return_seconds']);
        Database::execute(
            "INSERT INTO tianmojian_borrows (char_id, borrow_time, return_deadline, is_returned) 
             VALUES (?, NOW(), ?, 0)",
            [$charId, $returnDeadline]
        );
        
        // 5. 清除待确认状态
        Database::execute(
            "DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'tianmojian_pending'",
            [$charId]
        );
        
        Database::commit();
        
        // 广播消息
        $npcName = '蒸笼老人';
        $broadcastMsg = "{$npcName}哈哈大笑：「好！好！好！又多了一个魔道中人！」";
        
        require_once DAEMON_PATH . 'MessageDaemon.php';
        MessageDaemon::broadcastToRoom($roomId, $broadcastMsg, $charId);
        
        return [
            'success' => true,
            'message' => "你深吸一口气，大声说道：「我愿意入魔道！」\n\n蒸笼老人哈哈大笑：「好！好！好！又多了一个魔道中人！」\n\n老人递给你一个漆黑的茧：「这天魔茧就借给你了，记住，只有1个时辰的时间，到时候自动收回！」\n\n你接过天魔茧，感觉一股阴冷的气息扑面而来……",
            'data' => ['type' => 'accept_success']
        ];
        
    } catch (\Exception $e) {
        Database::rollBack();
        return ['success' => false, 'message' => '确认失败：' . $e->getMessage()];
    }
}
