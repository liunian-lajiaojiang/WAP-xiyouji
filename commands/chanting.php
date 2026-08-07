<?php
/**
 * 诵经命令 (chanting)
 * 参考 xyj2000-php/cmds/std/Chanting.php 重构
 * xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 功能：诵经修炼以提高道行（仅限出家人）
 * 用法：chanting
 * 
 * 黄风捕获概率：3 * 3600 / (time + 1)，即约 1/3600 级别
 */

require_once __DIR__ . '/../helpers/SkillManager.php';

function cmd_chanting(int $charId, string $param = ''): array {
    $me = CharacterModel::find($charId);
    
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 检查是否昏迷
    if (isset($_SESSION["unconscious_{$charId}"])) {
        $unconscious = $_SESSION["unconscious_{$charId}"];
        $elapsed = time() - $unconscious['timestamp'];
        $duration = $unconscious['duration'] ?? 30;
        if ($elapsed < $duration) {
            return [
                'success' => false,
                'message' => HTML_HIRED . '你昏迷中，无法诵经！' . HTML_NOR,
                'skip_queue' => true,
            ];
        }
        unset($_SESSION["unconscious_{$charId}"]);
        Database::execute('UPDATE characters SET unconscious_state = 0, unconscious_end_time = 0 WHERE id = ?', [$charId]);
    }
    
    // 检查是否在禁止修炼的房间
    $room = RoomModel::load($me['current_area'], $me['current_room']);
    if ($room && (isset($room['no_fight']) && $room['no_fight'] || isset($room['no_magic']) && $room['no_magic'])) {
        return ['success' => false, 'message' => '安全区内禁止练功。'];
    }
    
    // 检查是否在静坐房间（jingzuo_room）
    if (empty($room) || empty($room['jingzuo_room'])) {
        return ['success' => false, 'message' => '此处不宜静坐修练！'];
    }
    
    // 检查是否已出家（class == "bonze"）
    $charClass = $me['class'] ?? '';
    if ($charClass !== 'bonze') {
        return ['success' => false, 'message' => '你又没有出家，诵什么经哪！'];
    }
    
    // 检查大乘佛法等级 >= 20
    $buddhismLevel = SkillManager::getSkillLevel($charId, 'buddhism');
    if ($buddhismLevel < 20) {
        return ['success' => false, 'message' => '你的大乘佛法修为太低，不能行功修炼！'];
    }
    
    // 检查忙碌状态或正在修炼
    if (is_player_busy($charId) || !empty($_SESSION['pending_chanting'])) {
        return ['success' => false, 'message' => '你现在正忙着呢。'];
    }
    
    // 检查是否在战斗中
    if (CombatDaemon::isInCombat($charId)) {
        return ['success' => false, 'message' => '战斗中还要诵经？'];
    }
    
    // 检查精神和气血
    $sen = intval($me['sen'] ?? 0);
    $maxSen = intval($me['max_sen'] ?? 100);
    $kee = intval($me['kee'] ?? 0);
    $maxKee = intval($me['max_kee'] ?? 100);
    
    if ($maxSen > 0 && $sen * 100 / $maxSen < 50) {
        return ['success' => false, 'message' => '你现在神智不清，再炼恐怕要走火入魔！'];
    }
    if ($maxKee > 0 && $kee * 100 / $maxKee < 50) {
        return ['success' => false, 'message' => '你现在体力不够，再练就要累死了！'];
    }
    
    // 检查饮水和法力
    $water = intval($me['water'] ?? 0);
    $mana = intval($me['mana'] ?? 0);
    if ($water < 10) {
        return ['success' => false, 'message' => '你现在口干舌燥，还是歇歇吧。'];
    }
    if ($mana < 50) {
        return ['success' => false, 'message' => '你现在法力太低，再炼恐怕要走火入魔！'];
    }
    
    // 计算修炼时间
    $level = $buddhismLevel;
    $time = intval($level / 2) + mt_rand(0, 1);
    if ($time > 60) {
        $time = 50 + mt_rand(0, 9);
    }
    if ($time < 2) {
        $time = 2;
    }
    
    // 黄风捕获：概率 = 3 * 3600 / (time + 1)
    // 参考 xyj2000-php/cmds/std/Chanting.php 第92行
    $captureChance = intval(3 * 3600 / ($time + 1));
    require_once __DIR__ . '/../daemons/Miscd.php';
    $captured = Miscd::randomCapture($charId, $captureChance);
    if ($captured) {
        $captureMsg = Miscd::getCaptureMessage($charId);
        return [
            'success' => true,
            'message' => $captureMsg ?? '你被一阵黄风卷走了！',
            'redirect' => 'room.php?area=qujing&room=baihuling/jail',
            'skip_queue' => true,
        ];
    }
    
    // 计算收益
    $constant = intval($level / 10) + 15;
    $dxGain = intval(($level * $constant) / 100);
    $dxGain = intval($dxGain / 2) + mt_rand(0, $dxGain - 1) + mt_rand(0, intval(intval($me['spi'] ?? 10) / 5)) + 1;
    if ($dxGain > 100) {
        $dxGain = 80 + mt_rand(0, 19);
    }
    
    $potGain = intval($time / 10) + intval(intval($me['int'] ?? 10) / 5) + 1;
    
    // 存储诵经状态（通过轮询完成）
    $_SESSION['pending_chanting'] = [
        'start_time' => time(),
        'total_rounds' => $time,
        'current_round' => 0,
        'last_round_time' => time(),
        'where' => $me['current_area'] . '/' . $me['current_room'],
        'dx_gain' => $dxGain,
        'pot_gain' => $potGain,
    ];
    
    // 设置忙碌状态
    set_player_busy($charId, $time * 2 + 1);
    
    // 佛教经文数组
    $chantingLines = [
        "多欲为苦，生死疲劳，从贪欲起；少欲无为，身心自在。",
        "懈怠坠落，常行精进，破烦恼恶，摧伏四魔，出阴界狱。",
        "愚痴生死，菩萨常念，广学多闻，增长智慧，成就辩才，教化一切，悉以大乐。",
        "贫苦多怨，横结恶缘，菩萨布施，等念怨亲，不念旧恶，不憎恶人。",
        "生死炽燃，苦恼无量，发大乘心，普济一切。",
        "愿代众生，受无量苦，令诸众生，毕竟大乐。",
        "愿以此功德，庄严佛净土，上报四重恩，下济三途苦。",
        "若有见闻者，悉发菩提心，常行菩萨道，广度诸有情。",
    ];
    $randomLine = $chantingLines[array_rand($chantingLines)];
    
    return [
        'success' => true,
        'message' => HTML_HIYEL . "你席地而坐，双目微闭，口中轻声诵起了经文。\n" . HTML_NOR
                   . HTML_HIYEL . "你轻声念道：" . $randomLine . "\n" . HTML_NOR,
        'skip_queue' => true,
        'current_round' => 0,
        'total_rounds' => $time,
        'action_type' => 'chanting',
    ];
}

/**
 * 执行一轮诵经
 * 由轮询系统调用
 */
function executeChantingRound(int $charId): ?array {
    if (empty($_SESSION['pending_chanting'])) {
        return null;
    }
    
    $state = $_SESSION['pending_chanting'];
    $now = time();
    
    // 检查是否到了下一轮的时间（每1秒一轮）
    if ($now - $state['last_round_time'] < 1) {
        return null;
    }
    
    // 获取角色信息
    $me = CharacterModel::find($charId);
    if (!$me) {
        unset($_SESSION['pending_chanting']);
        return null;
    }
    
    // 检查是否移动了位置
    $currentWhere = $me['current_area'] . '/' . $me['current_room'];
    if ($currentWhere !== $state['where']) {
        unset($_SESSION['pending_chanting']);
        set_player_busy($charId, 0);
        return ['success' => true, 'message' => '你被打断了，停止了诵经。'];
    }
    
    // 更新状态
    $_SESSION['pending_chanting']['current_round']++;
    $_SESSION['pending_chanting']['last_round_time'] = time();
    
    $currentRound = $_SESSION['pending_chanting']['current_round'];
    $totalRounds = $_SESSION['pending_chanting']['total_rounds'];
    
    // 检查是否完成所有轮次
    if ($currentRound >= $totalRounds) {
        return finishChanting($charId, $me, $state);
    }
    
    return null; // 轮询中，不需要返回消息
}

/**
 * 完成诵经
 */
function finishChanting(int $charId, array $me, array $state): array {
    $dxGain = $state['dx_gain'];
    $potGain = $state['pot_gain'];
    
    // 增加道行
    $currentDaoxing = intval($me['daoxing'] ?? 0);
    $newDaoxing = $currentDaoxing + $dxGain * 3;
    Database::execute("UPDATE characters SET daoxing = ? WHERE id = ?", [$newDaoxing, $charId]);
    
    // 增加潜能
    $potential = intval($me['potential'] ?? 0);
    $learnedPoints = intval($me['learned_points'] ?? 0);
    $potMsg = '';
    if (($potential - $learnedPoints) < 1000) {
        $newPotential = $potential + $potGain;
        Database::execute("UPDATE characters SET potential = ? WHERE id = ?", [$newPotential, $charId]);
        $potMsg = "\n" . HTML_HICYN . "你的潜能增加了" . chinese_number($potGain) . "点！" . HTML_NOR;
    }
    
    // 提升大乘佛法技能
    $i = $dxGain * 3 + mt_rand(0, $dxGain * 2 - 1);
    $familyName = $me['family'] ?? '';
    $budGain = $i;
    if ($familyName === '南海普陀山') {
        // 南海普陀山有加成
    } else {
        $budGain = intval($i / 3);
    }
    
    SkillManager::improveSkillOriginal($charId, 'buddhism', $budGain, false);
    
    // 消耗饮水和精神气血
    $newWater = max(0, intval($me['water'] ?? 0) - $potGain * 2);
    $newSen = max(0, intval($me['sen'] ?? 0) - intval($me['int'] ?? 10) * 2);
    $newKee = max(0, intval($me['kee'] ?? 0) - intval($me['int'] ?? 10) * 2);
    $newMana = max(0, intval($me['mana'] ?? 0) - 50);
    
    Database::execute(
        "UPDATE characters SET water = ?, sen = ?, kee = ?, mana = ? WHERE id = ?",
        [$newWater, $newSen, $newKee, $newMana, $charId]
    );
    
    // 清除状态
    unset($_SESSION['pending_chanting']);
    set_player_busy($charId, 1);
    
    $message = HTML_HIYEL . "你缓缓睁开眼睛，长舒一口气站了起来。" . HTML_NOR . "\n"
             . HTML_HICYN . "你的道行增加了" . chinese_number($dxGain * 3) . "时辰！" . HTML_NOR
             . "\n" . HTML_HICYN . "你的大乘佛法增加了" . chinese_number($budGain) . "点！" . HTML_NOR
             . $potMsg;
    
    return [
        'success' => true,
        'message' => $message,
        'skip_queue' => true,
        'dx_gain' => $dxGain * 3,
        'pot_gain' => $potGain,
        'bud_gain' => $budGain,
    ];
}
