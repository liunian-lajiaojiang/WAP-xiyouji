<?php
/**
 * 练习技能命令 (practice)
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 功能: 练习通过enable指定的专业技能（持续状态版本）
 * 用法: practice <技能类型> [次数]
 *       practice 0 ：停止练习
 */

require_once __DIR__ . '/../helpers/SkillManager.php';

function cmd_practice(int $charId, string $param = ''): array {
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
                'message' => HTML_HIRED . '你昏迷中，无法练习！' . HTML_NOR,
                'skip_queue' => true,
            ];
        } else {
            unset($_SESSION["unconscious_{$charId}"]);
            Database::execute('UPDATE characters SET unconscious_state = 0, unconscious_end_time = 0 WHERE id = ?', [$charId]);
        }
    } else {
        // 回退检查：DB 昏迷状态
        $dbChar = CharacterModel::find($charId);
        if ($dbChar && !empty($dbChar['unconscious_state']) && $dbChar['unconscious_state'] == 1) {
            $endTime = intval($dbChar['unconscious_end_time'] ?? 0);
            if ($endTime > 0 && time() < $endTime) {
                $remaining = $endTime - time();
                return [
                    'success' => false,
                    'message' => HTML_HIRED . '你昏迷中，无法练习！(剩余' . $remaining . '秒)' . HTML_NOR,
                    'skip_queue' => true,
                ];
            }
            Database::execute('UPDATE characters SET unconscious_state = 0, unconscious_end_time = 0 WHERE id = ?', [$charId]);
        }
    }
    
    // 检查是否在禁止战斗/修炼的房间
    $room = RoomModel::load($me['current_area'], $me['current_room']);
    if ($room && (isset($room['no_fight']) && $room['no_fight'] || isset($room['no_magic']) && $room['no_magic'])) {
        return ['success' => false, 'message' => '这里不能进行修炼。'];
    }
    
    // 检查是否正在战斗中
    if (CombatDaemon::isInCombat($charId)) {
        return ['success' => false, 'message' => '你已经在战斗中了，学一点实战经验吧。'];
    }
    
    // 解析参数
    $parts = explode(' ', trim($param));
    $skillType = $parts[0] ?? '';
    $times = intval($parts[1] ?? 1);
    
    // 如果参数为0且正在练习，停止练习
    if ($skillType === '0' && !empty($_SESSION['pending_practicing'])) {
        unset($_SESSION['pending_practicing']);
        set_player_busy($charId, 0);
        return ['success' => true, 'message' => '你停止了练习。', 'skip_queue' => true];
    }
    
    // 检查忙碌状态或正在练习
    if (is_player_busy($charId) || !empty($_SESSION['pending_practicing'])) {
        return ['success' => false, 'message' => '你现在正忙着呢，无法练习。'];
    }
    
    // 如果没有参数，显示enable信息
    if (empty($skillType)) {
        return showEnableInfo($charId);
    }
    
    // 从 character_skill_map 表获取技能映射
    $mappedSkill = SkillManager::querySkillMapped($charId, $skillType);
    
    if (!$mappedSkill) {
        return ['success' => false, 'message' => '你只能练习用 enable 指定的那项技能。'];
    }
    
    $skillName = $mappedSkill;
    
    // 获取基础技能等级和专业技能等级
    $basicSkill = SkillManager::getSkillLevel($charId, $skillType);
    $specialSkill = SkillManager::getSkillLevel($charId, $skillName);
    
    if ($specialSkill < 1) {
        return ['success' => false, 'message' => '你还没有学会这项技能吧，还是先去拜师学艺了。'];
    }
    
    if ($basicSkill < 1) {
        return ['success' => false, 'message' => '你这方面的技能基础一窍不通，怎么却从何学起？'];
    }
    
    // 修为限制公式: level^3 / 10 <= combat_exp
    $requiredExp = pow($specialSkill, 3) / 10;
    if ($requiredExp > ($me['combat_exp'] ?? 0)) {
        return ['success' => false, 'message' => '你的修为不够，无法继续提升这项技能。'];
    }
    
    // 验证是否可以学习（调用技能自身的验证）
    if (!SkillManager::canLearn($charId, $skillName)) {
        return ['success' => false, 'message' => '你不能在这里练习这项技能。'];
    }
    
    // 设置练习状态
    $_SESSION['pending_practicing'] = [
        'start_time' => time(),
        'skill_type' => $skillType,
        'skill_name' => $skillName,
        'total_rounds' => $times,
        'current_round' => 0,
        'last_round_time' => time(),
        'where' => $me['current_area'] . '/' . $me['current_room']
    ];
    
    // 设置忙碌状态
    set_player_busy($charId, $maxTimes * 2 + 1);
    
    // 执行第一轮练习
    return executePracticeRound($charId, $me);
}

/**
 * 执行一轮练习
 */
function executePracticeRound(int $charId, array $me): array {
    $practiceState = $_SESSION['pending_practicing'];
    
    if (empty($practiceState)) {
        return ['success' => false, 'message' => '你没有在练习中。'];
    }
    
    $skillType = $practiceState['skill_type'];
    $skillName = $practiceState['skill_name'];
    
    // 检查是否移动了位置
    $currentWhere = $me['current_area'] . '/' . $me['current_room'];
    if ($currentWhere !== $practiceState['where']) {
        unset($_SESSION['pending_practicing']);
        set_player_busy($charId, 0);
        return ['success' => true, 'message' => '你被打断了，停止了练习。'];
    }
    
    // 获取技能等级
    $basicSkill = SkillManager::getSkillLevel($charId, $skillType);
    $specialSkill = SkillManager::getSkillLevel($charId, $skillName);
    
    // 计算经验增加量（参考原始代码: skill_basic/5 + 1）
    $improveAmount = intval($basicSkill / 5) + 1;
    
    // 判断是否使用弱模式（基础技能 <= 专业技能时使用弱模式）
    $weakMode = ($basicSkill <= $specialSkill);
    
    // 黄风捕获：练习时有 1/20000 概率触发
    // 参考 xyj2000-php/cmds/std/Practice.php: MISC_D->random_capture($me, 20000, 0)
    if (mt_rand(0, 19999) === 0) {
        require_once __DIR__ . '/../daemons/Miscd.php';
        $captured = Miscd::randomCapture($charId, 20000);
        
        // 如果触发捕获，中断练习
        if ($captured) {
            unset($_SESSION['pending_practicing']);
            set_player_busy($charId, 0);
            $captureMsg = Miscd::getCaptureMessage($charId);
            return [
                'success' => true,
                'message' => $captureMsg ?? '你被一阵黄风卷走了！',
                'redirect' => 'room.php?area=qujing&room=baihuling/jail',
            ];
        }
        
        // 没触发捕获则正常继续（实际上这里不会到达，因为 mt_rand 已判定）
        $_SESSION['pending_practicing']['current_round']++;
        $_SESSION['pending_practicing']['last_round_time'] = time();
        
        if ($_SESSION['pending_practicing']['current_round'] >= $_SESSION['pending_practicing']['total_rounds']) {
            unset($_SESSION['pending_practicing']);
            set_player_busy($charId, 0);
            return ['success' => true, 'message' => '你反复练习这项技能，但是没有任何进展。'];
        }
        
        return ['success' => true, 'message' => '你反复练习这项技能，但是没有任何进展。'];
    }
    
    // 调用原始风格的 improve_skill
    $improveResult = SkillManager::improveSkillOriginal($charId, $skillName, $improveAmount, $weakMode);
    
    if (!$improveResult['success']) {
        $_SESSION['pending_practicing']['current_round']++;
        $_SESSION['pending_practicing']['last_round_time'] = time();
        
        if ($_SESSION['pending_practicing']['current_round'] >= $_SESSION['pending_practicing']['total_rounds']) {
            unset($_SESSION['pending_practicing']);
            set_player_busy($charId, 0);
        }
        
        return ['success' => false, 'message' => '你练习这项技能，但是没有任何进展。'];
    }
    
    $skillChinese = SkillManager::getSkillChineseName($skillName);
    
    // 更新练习状态
    $_SESSION['pending_practicing']['current_round']++;
    $_SESSION['pending_practicing']['last_round_time'] = time();
    
    // 构建输出消息
    $output = [];
    
    if ($_SESSION['pending_practicing']['current_round'] === 1) {
        $output[] = "你开始认真练习{$skillChinese}。";
    }
    
    // 检查是否需要师父指导（基础技能 <= 专业技能且随机1%概率）
    if ($basicSkill <= $specialSkill && rand(0, 99) === 0) {
        $output[] = "你觉得这项技能还需要师父指点一二。";
    }
    
    // 技能升级成功
    if ($improveResult['leveled_up']) {
        $newLevel = $improveResult['new_level'];
        $output[] = "你的{$skillChinese}有长进了！(等级: {$newLevel})";
    } else {
        $output[] = "你认真练习了{$skillChinese}，感觉有些心得。";
    }
    
    // 检查是否完成所有轮次
    if ($_SESSION['pending_practicing']['current_round'] >= $_SESSION['pending_practicing']['total_rounds']) {
        $output[] = "你练习完毕，缓缓收功。";
        unset($_SESSION['pending_practicing']);
        set_player_busy($charId, 0);
    }
    
    return [
        'success' => true,
        'message' => implode("\n", $output),
        'skip_queue' => true,
        'skill_type' => $skillType,
        'skill_name' => $skillName,
        'leveled_up' => $improveResult['leveled_up'],
        'new_level' => $improveResult['new_level'],
        'current_round' => $_SESSION['pending_practicing']['current_round'] ?? 0,
        'total_rounds' => $_SESSION['pending_practicing']['total_rounds'] ?? 0
    ];
}

/**
 * 处理练习轮次推进（在轮询时调用）
 */
function processPracticeRound(int $charId): ?array {
    if (empty($_SESSION['pending_practicing'])) {
        return null;
    }
    
    $practiceState = $_SESSION['pending_practicing'];
    $now = time();
    
    // 检查是否到了下一轮的时间（每1秒一轮）
    if ($now - $practiceState['last_round_time'] < 1) {
        return null;
    }
    
    // 获取角色信息
    $me = CharacterModel::find($charId);
    if (!$me) {
        unset($_SESSION['pending_practicing']);
        return null;
    }
    
    // 检查是否移动了位置
    $currentWhere = $me['current_area'] . '/' . $me['current_room'];
    if ($currentWhere !== $practiceState['where']) {
        unset($_SESSION['pending_practicing']);
        set_player_busy($charId, 0);
        return ['success' => true, 'message' => '你被打断了，停止了练习。'];
    }
    
    // 执行练习轮次
    return executePracticeRound($charId, $me);
}

/**
 * 显示当前 enable 的技能信息
 */
function showEnableInfo(int $charId): array {
    // 从 character_skill_map 表获取所有映射
    $mapRows = Database::queryAll(
        "SELECT skill_type, mapped_skill FROM character_skill_map WHERE char_id = ?",
        [$charId]
    );
    
    if (empty($mapRows)) {
        return ['success' => false, 'message' => '你还没有 enable 任何技能。请先使用 enable 命令选择要练习的技能。'];
    }
    
    $output = ["你目前 enable 的技能："];
    foreach ($mapRows as $row) {
        $skillType = $row['skill_type'];
        $skillId = $row['mapped_skill'];
        $skillName = SkillManager::getSkillChineseName($skillId);
        $level = SkillManager::getSkillLevel($charId, $skillId);
        $output[] = "  {$skillType}: {$skillName} (等级: {$level})";
    }
    $output[] = "\n使用 practice <技能类型> [次数] 来练习对应的技能。";
    
    return ['success' => true, 'message' => implode("\n", $output), 'skip_queue' => true];
}
