<?php
/**
 * 投降命令 (surrender)
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 用法: surrender
 * 可在切磋模式或击杀模式下使用，用于认输结束战斗
 * 
 * 投降成功率：
 * - 切磋模式：10%拒绝率，成功则无损失
 * - 击杀模式：90%拒绝率，成功则损失1%潜能
 */
// 加载技能配置
static $_skillCosts = null;
if ($_skillCosts === null) {
    $_skillCosts = require __DIR__ . '/../config/skill_costs.php';
}

function cmd_surrender(int $charId, string $param = ''): array {
    
    // 必须在Session中有战斗状态才算真正在战斗中
    // 防止数据库残留记录导致误判（比如点击投降反倒进入战斗）
    if (!isset($_SESSION["combat_{$charId}"])) {
        // 检查是否有数据库残留记录，如果有就清理掉
        $dbRecord = Database::queryOne("SELECT id FROM active_combats WHERE char_id = ? LIMIT 1", [$charId]);
        if ($dbRecord) {
            Database::execute("DELETE FROM active_combats WHERE char_id = ?", [$charId]);
        }
        return ['success' => false, 'message' => '你还没有在战斗中，投降什么？'];
    }
    
    // 检查是否在战斗中
    if (!CombatDaemon::isInCombat($charId)) {
        return ['success' => false, 'message' => '你还没有在战斗中，投降什么？'];
    }
    
    $combatStatus = CombatDaemon::getCombatStatus($charId);
    
    // 获取角色信息
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    $charName = $char['name'] ?? '你';
    $targetName = $combatStatus['target_name'] ?? '对方';
    $targetType = $combatStatus['target_type'] ?? 'npc';
    $targetId = intval($combatStatus['target_id'] ?? 0);
    $isFriendly = isset($combatStatus['friendly']) && $combatStatus['friendly'];
    
    // 计算投降成功率（从配置读取）
    $baseReject = $_skillCosts['surrender']['base_reject_chance'];
    $rejectChance = $isFriendly ? $baseReject : (100 - $baseReject);  // 切磋模式低拒绝率，击杀模式高拒绝率
    $isRejected = mt_rand(1, 100) <= $rejectChance;
    
    if ($isRejected) {
        // 投降被拒绝
        $rejectMessages = [
            "{$targetName}冷哼一声：「既然敢打，就别想求饶！」",
            "{$targetName}不为所动，继续猛攻！",
            "{$targetName}嘲笑道：「现在求饶太晚了！」",
            "{$targetName}根本不理会你的求饶，攻势更猛！"
        ];
        $message = HTML_HICYN . "你向" . $targetName . "跪地求饶：「英雄饶命！」" . HTML_NOR . "\n";
        $message .= HTML_HIRED . $rejectMessages[array_rand($rejectMessages)] . HTML_NOR;
        
        return [
            'success' => true,
            'message' => $message,
            'surrender' => false,
            'combat_end' => false,
            'skip_queue' => true
        ];
    }
    
    // 投降成功，结束战斗
    CombatDaemon::endCombat($charId);
    
    // 清理战斗日志
    unset($_SESSION["combat_log_{$charId}"]);
    
    // 清理切磋NPC血量
    if ($targetType === 'npc') {
        unset($_SESSION["npc_hp_friendly_{$targetId}"]);
    }
    
    require_once DAEMON_PATH . 'MessageDaemon.php';
    $roomId = $char['current_area'] . '/' . $char['current_room'];
    
    // 生成投降消息
    $messages = [];
    $opponentMsg = '';  // 发给对手的消息
    
    if ($isFriendly) {
        // 切磋模式投降 - 无损失
        $messages[] = HTML_HIWHT . "【切磋】" . HTML_NOR;
        $messages[] = HTML_HICYN . "你向" . $targetName . "抱拳道：「在下认输，承让了！」" . HTML_NOR;
        $messages[] = HTML_HIMAG . $targetName . "微微一笑，抱拳还礼。" . HTML_NOR;
        
        // 如果是NPC对手，设置拒绝再次切磋（30秒冷却）
        if ($targetType === 'npc' && $targetId > 0) {
            $rejectKey = "npc_reject_fight_{$targetId}";
            $_SESSION[$rejectKey] = time() + 30;
        }
        
        $broadcastMsg = HTML_HICYN . $charName . "向" . $targetName . "抱拳道：「在下认输，承让了！」" . HTML_NOR;
        $opponentMsg = HTML_HIWHT . "【切磋】 " . $charName . "向" . $targetName . "抱拳道：「在下认输，承让了！」" . HTML_NOR;
    } else {
        // 击杀模式投降 - 损失1%潜能
        $messages[] = HTML_HIWHT . "【投降】" . HTML_NOR;
        $messages[] = HTML_HICYN . "你向" . $targetName . "跪地求饶：「英雄饶命！」" . HTML_NOR;
        
        // 计算惩罚：损失1%潜能
        $potentialLoss = 0;
        if (isset($char['potential']) && $char['potential'] > 0) {
            $potentialLoss = intval($char['potential'] * 0.01);
            $char['potential'] -= $potentialLoss;
            CharacterModel::update($charId, ['potential' => $char['potential']]);
            $messages[] = HTML_HIRED . "你损失了 " . $potentialLoss . " 点潜能作为投降的代价。" . HTML_NOR;
        }
        
        $messages[] = HTML_HIMAG . $targetName . "冷哼一声，放过了你。" . HTML_NOR;
        
        $broadcastMsg = HTML_HICYN . $charName . "向" . $targetName . "跪地求饶：「英雄饶命！」" . HTML_NOR;
        $opponentMsg = HTML_HIWHT . "【投降】 " . HTML_HICYN . $charName . "向你跪地求饶：「英雄饶命！」" . HTML_NOR . "\n" . HTML_HIMAG . "你冷哼一声，放过了" . $charName . "。" . HTML_NOR;
    }
    
    // 1. 广播消息到房间（旁观者看）
    MessageDaemon::broadcastToRoom($roomId, $broadcastMsg, $charId);
    
    // 2. 如果对手是玩家：通过消息队列通知对手
    // 注意：不能在此处调用 endCombat($targetId)，因为当前是投降者的 session，
    // 无法修改对手的 $_SESSION。对手的 fight.php 会自行检测目标已消失并结束战斗。
    if ($targetType === 'player' && $targetId > 0) {
        MessageDaemon::sendToPlayer($targetId, $opponentMsg, 'combat');
    }
    
    // 返回结果
    return [
        'success' => true,
        'message' => implode("\n", $messages),
        'surrender' => true,
        'combat_end' => true,
        'skip_queue' => true  // 防止 action.php 重复保存消息
    ];
}
