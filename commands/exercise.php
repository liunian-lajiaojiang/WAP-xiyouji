<?php
/**
 * 打坐命令 (exercise)
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 功能：运气练功，提升内力上限
 * 用法：exercise [<耗费「气」的量>]
 *       exercise 0 ：停止打坐
 */

require_once __DIR__ . '/../helpers/SkillManager.php';

function cmd_exercise(int $charId, string $param = ''): array {
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
                'message' => HTML_HIRED . '你昏迷中，无法打坐！' . HTML_NOR,
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
                    'message' => HTML_HIRED . '你昏迷中，无法打坐！(剩余' . $remaining . '秒)' . HTML_NOR,
                    'skip_queue' => true,
                ];
            }
            Database::execute('UPDATE characters SET unconscious_state = 0, unconscious_end_time = 0 WHERE id = ?', [$charId]);
        }
    }
    
    // 检查是否在禁止战斗/修炼的房间
    $room = RoomModel::load($me['current_area'], $me['current_room']);
    if ($room && (isset($room['no_fight']) && $room['no_fight'] || isset($room['no_magic']) && $room['no_magic'])) {
        return ['success' => false, 'message' => '安全区内禁止练功。'];
    }
    
    // 解析参数
    $keeCost = 0;
    if (empty($param)) {
        return ['success' => false, 'message' => '你要花多少气练功？'];
    }
    
    if (!is_numeric($param)) {
        return ['success' => false, 'message' => '你要花多少气练功？'];
    }

    $keeCost = intval($param);
    
    // 如果参数为0且正在修炼，停止修炼
    if ($keeCost <= 0 && !empty($_SESSION['pending_exercising'])) {
        unset($_SESSION['pending_exercising']);
        set_player_busy($charId, 0);
        clearTrainingState($charId);
        return ['success' => true, 'message' => '你停止了打坐。', 'skip_queue' => true];
    }
    
    // 检查忙碌状态或正在修炼
    if (is_player_busy($charId) || !empty($_SESSION['pending_exercising'])) {
        return ['success' => false, 'message' => '你现在正忙着呢。'];
    }
    
    // 检查是否在战斗中
    if (CombatDaemon::isInCombat($charId)) {
        return ['success' => false, 'message' => '战斗中不能练内功，会走火入魔。'];
    }
    
    // 检查是否已启用内功（enable force）
    $mappedForce = SkillManager::querySkillMapped($charId, 'force');
    if (empty($mappedForce)) {
        return ['success' => false, 'message' => '你必须先用 enable 选择你要用的内功心法。'];
    }
    
    // 最少消耗20气血
    if ($keeCost < 20) {
        return ['success' => false, 'message' => '你最少要花 20 点「气」才能练功。'];
    }
    
    // 检查气血是否足够
    $currentKee = intval($me['kee'] ?? 0);
    if ($currentKee < $keeCost) {
        return ['success' => false, 'message' => '你现在的气太少了，无法产生内息运行全身经脉。'];
    }
    
    // 计算忙碌时间和轮数
    $busyTime = intval($keeCost / 20);
    $totalRounds = $busyTime;
    
    // 设置修炼状态
    $_SESSION['pending_exercising'] = [
        'start_time' => time(),
        'total_rounds' => $totalRounds,
        'current_round' => 0,
        'kee_cost_per_round' => 20,
        'last_round_time' => time(),
        'where' => $me['current_area'] . '/' . $me['current_room']
    ];
    
    // 写入数据库标记（防止SESSION并发问题）
    Database::execute(
        "UPDATE characters SET training_state = 'exercising', training_end_time = ? WHERE id = ?",
        [time() + $busyTime * 2 + 1, $charId]
    );
    
    // 设置忙碌状态
    set_player_busy($charId, $busyTime * 2 + 1);
    
    // 执行第一轮修炼
    return executeExerciseRound($charId, $me);
}

/**
 * 执行一轮打坐修炼
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
function executeExerciseRound(int $charId, array $me): array {
    $exerciseState = $_SESSION['pending_exercising'];
    
    if (empty($exerciseState)) {
        return ['success' => false, 'message' => '你没有在修炼中。'];
    }
    
    // 检查是否移动了位置
    $currentWhere = $me['current_area'] . '/' . $me['current_room'];
    if ($currentWhere !== $exerciseState['where']) {
        unset($_SESSION['pending_exercising']);
        set_player_busy($charId, 0);
        clearTrainingState($charId);
        return ['success' => true, 'message' => '你被打断了，停止了打坐。'];
    }
    
    // 获取内功技能等级和根骨
    $mappedForce = SkillManager::querySkillMapped($charId, 'force');
    $forceSkillLevel = SkillManager::getSkillLevel($charId, $mappedForce);
    $con = intval($me['con'] ?? 10);
    
    // 内力增长公式（参考原始代码）
    $forceGain = intval($forceSkillLevel / 10) + intval($con / 3) + mt_rand(0, 2);
    if ($forceGain < 5) {
        $forceGain = 5;
    }
    if ($forceGain > 40) {
        $forceGain = 40;
    }
    $forceGain *= 2;
    
    // 当前内力状态
    $currentForce = intval($me['force'] ?? 0);
    $maxForce = intval($me['max_force'] ?? 0);
    $maxForceLimit = SkillManager::queryMaxForce($charId);
    $maximumForce = intval($me['maximum_force'] ?? 0);
    
    // 计算新的内力和气血
    $newKee = intval($me['kee'] ?? 0) - 20;
    $newForce = $currentForce + $forceGain;
    $newMaxForce = $maxForce;
    $forceUpgraded = false;
    $reachedLimit = false;
    
    // 检查是否达到上限提升条件（参考原始代码）
    if ($newForce > $maxForce * 2 && $maxForce > 0) {
        if ($maxForce >= $maxForceLimit) {
            // 已达到瓶颈
            $reachedLimit = true;
        } else {
            // 提升最大内力
            $newMaxForce = $maxForce + 1;
            $forceUpgraded = true;
        }
        $newForce = $newMaxForce;
        
        // 更新 maximum_force
        if ($newMaxForce > $maximumForce) {
            $maximumForce = $newMaxForce;
        }
    }
    
    // 更新数据库
    $sql = "UPDATE characters SET kee = ?, `force` = ?, max_force = ?, maximum_force = ? WHERE id = ?";
    Database::execute($sql, [$newKee, $newForce, $newMaxForce, $maximumForce, $charId]);

    // 内力提升时重算气血上限（max_force 影响 max_kee）
    if ($forceUpgraded) {
        CharacterModel::recalculateVitals($charId);
    }
    
    // 更新修炼状态
    $_SESSION['pending_exercising']['current_round']++;
    $_SESSION['pending_exercising']['last_round_time'] = time();
    
    // 构建输出消息
    $output = [];
    
    if ($_SESSION['pending_exercising']['current_round'] === 1) {
        $output[] = "你坐下来运气用功，一股内息开始在体内流动。";
    }
    
    if ($reachedLimit) {
        $output[] = "当你的内息遍布全身经脉时却没有功力提升的迹象，似乎内力修为已经遇到了瓶颈。";
        unset($_SESSION['pending_exercising']);
        set_player_busy($charId, 0);
        clearTrainingState($charId);
    } elseif ($forceUpgraded) {
        $output[] = "你的内力增强了！";
    }
    
    // 检查是否完成所有轮次
    if (empty($_SESSION['pending_exercising']) === false && 
        $_SESSION['pending_exercising']['current_round'] >= $_SESSION['pending_exercising']['total_rounds']) {
        $output[] = "你行功完毕，吸一口气，缓缓站了起来。";
        unset($_SESSION['pending_exercising']);
        set_player_busy($charId, 0);
        clearTrainingState($charId);
    }
    
    // 将修炼消息广播给玩家
    $fullMsg = implode("\n", $output);

    return [
        'success' => true,
        'message' => $fullMsg,
        'force_gain' => $forceGain,
        'kee_cost' => 20,
        'max_force' => $newMaxForce,
        'force_upgraded' => $forceUpgraded,
        'reached_limit' => $reachedLimit,
        'current_round' => $_SESSION['pending_exercising']['current_round'] ?? 0,
        'total_rounds' => $_SESSION['pending_exercising']['total_rounds'] ?? 0
    ];
}

/**
 * 处理修炼轮次推进（在轮询时调用）
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
function processExerciseRound(int $charId): ?array {
    if (empty($_SESSION['pending_exercising'])) {
        return null;
    }
    
    $exerciseState = $_SESSION['pending_exercising'];
    $now = time();
    
    // 检查是否到了下一轮的时间（每1秒一轮）
    if ($now - $exerciseState['last_round_time'] < 1) {
        return null;
    }
    
    // 获取角色信息
    $me = CharacterModel::find($charId);
    if (!$me) {
        unset($_SESSION['pending_exercising']);
        return null;
    }
    
    // 检查是否移动了位置
    $currentWhere = $me['current_area'] . '/' . $me['current_room'];
    if ($currentWhere !== $exerciseState['where']) {
        unset($_SESSION['pending_exercising']);
        set_player_busy($charId, 0);
        clearTrainingState($charId);
        return ['success' => true, 'message' => '你被打断了，停止了打坐。'];
    }
    
    // 执行修炼轮次
    return executeExerciseRound($charId, $me);
}
