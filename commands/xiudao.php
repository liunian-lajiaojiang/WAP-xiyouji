<?php
/**
 * 修道命令 (xiudao)
 * 参考 xyj2000/cmds/std/xiudao.c 还原
 * 
 * 功能：静坐修炼以提高道行
 * 用法：xiudao
 */

require_once __DIR__ . '/../helpers/SkillManager.php';

function cmd_xiudao(int $charId, string $param = ''): array {
    $me = CharacterModel::find($charId);
    
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    // 检查是否正在修道
    if (!empty($_SESSION['pending_xiudao'])) {
        return executeXiudaoRound($charId);
    }

    // 环境检查：安全区禁止
    $room = get_room_info($charId);
    $roomFlags = $room['flags'] ?? [];
    if (in_array('no_fight', $roomFlags) || in_array('no_magic', $roomFlags)) {
        return ['success' => false, 'message' => '安全区内禁止练功。'];
    }

    // 前置要求：法术 >= 20
    $spellsLevel = SkillManager::querySkill($charId, 'spells', true);
    if ($spellsLevel < 20) {
        return ['success' => false, 'message' => '你法术修为太低，不能行功修炼！'];
    }

    // 忙碌检查
    if (is_player_busy($charId) || !empty($_SESSION['pending_exercising']) || !empty($_SESSION['pending_meditating'])) {
        return ['success' => false, 'message' => '你现在正忙着呢。'];
    }

    // 战斗检查
    require_once DAEMON_PATH . 'CombatDaemon.php';
    if (CombatDaemon::isInCombat($charId)) {
        return ['success' => false, 'message' => '战斗中修道，找死啊？！'];
    }

    // 精神检查：sen >= 50%
    $sen = intval($me['sen'] ?? 0);
    $maxSen = intval($me['max_sen'] ?? 1);
    if ($maxSen > 0 && $sen * 100 / $maxSen < 50) {
        return ['success' => false, 'message' => '你现在神智不清，再炼恐怕要走火入魔！'];
    }

    // 气血检查：kee >= 50%
    $kee = intval($me['kee'] ?? 0);
    $maxKee = intval($me['max_kee'] ?? 1);
    if ($maxKee > 0 && $kee * 100 / $maxKee < 50) {
        return ['success' => false, 'message' => '你现在体力不够，再练就要累死了！'];
    }

    // 计算 pot_gain（参考原始项目）
    $potGain = intval($spellsLevel / 20) + mt_rand(0, 2);
    
    // busy_time 计算（参考原始：random(7200)/pot_gain/100+2，再乘pot_gain）
    // 原始项目：busy_time 非常长（可能几百到几千秒），PHP项目中压缩
    $busyTime = intval(mt_rand(60, 180) / max(1, $potGain) + 2);
    $potGain = 1 + mt_rand(0, $potGain * 2);
    $busyTime *= $potGain;
    
    // 饥饿/口渴惩罚：food + water < 20 时 busy_time 翻倍
    $food = intval($me['food'] ?? 0);
    $water = intval($me['water'] ?? 0);
    if ($food + $water < 20) {
        $busyTime *= 2;
    }

    // 限制最大 busy_time 为300秒（5分钟）
    $busyTime = min($busyTime, 300);

    // 保存修道状态到 SESSION
    $_SESSION['pending_xiudao'] = [
        'char_id' => $charId,
        'start_time' => time(),
        'busy_time' => $busyTime,
        'pot_gain' => $potGain,
        'room_id' => $me['current_room'] ?? '',
    ];

    set_player_busy($charId, $busyTime + 2);
    
    // 写入数据库标记（防止SESSION并发问题）
    Database::execute(
        "UPDATE characters SET training_state = 'xiudao', training_end_time = ? WHERE id = ?",
        [time() + $busyTime + 2, $charId]
    );

    // 广播开始消息
    require_once DAEMON_PATH . 'MessageDaemon.php';
    $startMsg = HTML_HIYEL . "你闭上眼睛，盘膝坐下，嘴里默默念念不知在说些什么。" . HTML_NOR;
    $roomMsg = "{$me['name']}闭上眼睛，盘膝坐下，开始修炼。";

    MessageDaemon::broadcastToRoom($me['current_room'], $roomMsg, $charId);

    return [
        'success' => true,
        'message' => $startMsg,
        'redirect' => 'skills_practice.php',
        'skip_queue' => true,
    ];
}

/**
 * 修道结算轮次
 * 当玩家再次输入 xiudao 或进入房间轮询时检查是否完成
 */
function executeXiudaoRound(int $charId): array {
    $state = $_SESSION['pending_xiudao'] ?? null;
    if (!$state) {
        return ['success' => false, 'message' => '你没有在修道。'];
    }

    $elapsed = time() - intval($state['start_time']);
    $busyTime = intval($state['busy_time']);
    $potGain = intval($state['pot_gain']);

    // 检查是否完成
    if ($elapsed < $busyTime) {
        $remaining = $busyTime - $elapsed;
        return [
            'success' => true,
            'message' => "你正在闭目修道中...（还需要约" . ceil($remaining) . "秒）",
            'skip_queue' => true,
        ];
    }

    // 修道完成，执行结算
    unset($_SESSION['pending_xiudao']);
    set_player_busy($charId, 1); // 短暂忙碌
    clearTrainingState($charId);

    $me = CharacterModel::find($charId);
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在。'];
    }

    // 检查是否被黄风捕获
    require_once DAEMON_PATH . 'Miscd.php';
    $captured = Miscd::randomCapture($charId, 500);
    if ($captured) {
        $captureMsg = Miscd::getCaptureMessage($charId);
        return [
            'success' => true,
            'message' => $captureMsg ?? '你被一阵黄风卷走了！',
            'redirect' => 'room.php?area=qujing&room=baihuling/jail',
            'skip_queue' => true,
        ];
    }

    // 增加道行
    Database::execute(
        "UPDATE characters SET daoxing = daoxing + ? WHERE id = ?",
        [$potGain, $charId]
    );

    // 消耗 food 和 water
    $food = intval($me['food'] ?? 0);
    $water = intval($me['water'] ?? 0);
    if ($food >= $potGain) {
        Database::execute("UPDATE characters SET food = food - ? WHERE id = ?", [$potGain, $charId]);
    } else {
        Database::execute("UPDATE characters SET food = 0 WHERE id = ?", [$charId]);
    }
    if ($water >= $potGain) {
        Database::execute("UPDATE characters SET water = water - ? WHERE id = ?", [$potGain, $charId]);
    } else {
        Database::execute("UPDATE characters SET water = 0 WHERE id = ?", [$charId]);
    }

    // 扣除 int 值的 sen 和 kee
    $charInt = intval($me['int'] ?? 10);
    Database::execute(
        "UPDATE characters SET sen = GREATEST(sen - ?, 1), kee = GREATEST(kee - ?, 1) WHERE id = ?",
        [$charInt, $charInt, $charId]
    );

    // 提升法术技能
    $spellsGain = mt_rand(0, max(1, $potGain));
    Database::execute(
        "UPDATE character_skills SET level = level + ? WHERE char_id = ? AND skill_id = 'spells'",
        [$spellsGain, $charId]
    );

    // 构建完成消息
    $msg = HTML_HIYEL . "你缓缓睁开眼睛，长舒一口气站了起来。" . HTML_NOR . "\n";
    $msg .= HTML_HICYN . "你的道行增加了" . ($potGain * 3) . "时辰！" . HTML_NOR;

    if ($spellsGain > 0) {
        $msg .= "\n你的法术也有了一定的进步。";
    }

    // 广播到房间
    require_once DAEMON_PATH . 'MessageDaemon.php';
    $roomMsg = "{$me['name']}缓缓睁开眼睛，长舒一口气站了起来。";
    MessageDaemon::broadcastToRoom($me['current_room'], $roomMsg, $charId);

    return [
        'success' => true,
        'message' => $msg,
        'skip_queue' => true,
    ];
}
