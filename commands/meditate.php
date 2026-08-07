<?php
/**
 * 冥思命令 (meditate)
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 功能：静坐冥思，提升法力上限
 * 用法：meditate [<耗费「精神」的量>]
 *       meditate 0 ：停止冥思
 */

require_once __DIR__ . '/../helpers/SkillManager.php';

function cmd_meditate(int $charId, string $param = ''): array {
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
                'message' => HTML_HIRED . '你昏迷中，无法冥思！' . HTML_NOR,
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
                    'message' => HTML_HIRED . '你昏迷中，无法冥思！(剩余' . $remaining . '秒)' . HTML_NOR,
                    'skip_queue' => true,
                ];
            }
            Database::execute('UPDATE characters SET unconscious_state = 0, unconscious_end_time = 0 WHERE id = ?', [$charId]);
        }
    }
    
    // 检查是否在禁止战斗/修炼的房间
    $room = RoomModel::load($me['current_area'], $me['current_room']);
    if ($room && (isset($room['no_fight']) && $room['no_fight'] || isset($room['no_magic']) && $room['no_magic'])) {
        return ['success' => false, 'message' => '这里不是修炼法力的地方。'];
    }
    
    // 解析参数
    $senCost = 0;
    if (empty($param)) {
        return ['success' => false, 'message' => '你要花多少精神冥思？'];
    }
    
    if (!is_numeric($param)) {
        return ['success' => false, 'message' => '你要花多少精神冥思？'];
    }
    
    $senCost = intval($param);
    
    // 如果参数为0且正在修炼，停止冥思
    if ($senCost <= 0 && !empty($_SESSION['pending_meditating'])) {
        unset($_SESSION['pending_meditating']);
        set_player_busy($charId, 0);
        clearTrainingState($charId);
        return ['success' => true, 'message' => '你停止了冥思。', 'skip_queue' => true];
    }
    
    // 检查忙碌状态或正在冥思
    if (is_player_busy($charId) || !empty($_SESSION['pending_meditating'])) {
        return ['success' => false, 'message' => '你现在正忙着呢。'];
    }
    
    // 检查是否在战斗中
    if (CombatDaemon::isInCombat($charId)) {
        return ['success' => false, 'message' => '战斗中不能冥思。'];
    }
    
    // 检查是否已启用法术（enable spells）
    $mappedSpells = SkillManager::querySkillMapped($charId, 'spells');
    if (empty($mappedSpells)) {
        return ['success' => false, 'message' => '你必须先用 enable 选择你要用的法术。'];
    }
    
    // 最少消耗20精神
    if ($senCost < 20) {
        return ['success' => false, 'message' => '你最少要花 20 点「精神」才能冥思。'];
    }
    
    // 检查精神是否足够
    $currentSen = intval($me['sen'] ?? 0);
    if ($currentSen < $senCost) {
        return ['success' => false, 'message' => '你现在神智不清，不能再想入非非了。'];
    }
    
    // 计算忙碌时间和轮数
    $busyTime = intval($senCost / 20);
    $totalRounds = $busyTime;
    
    // 设置冥思状态
    $_SESSION['pending_meditating'] = [
        'start_time' => time(),
        'total_rounds' => $totalRounds,
        'current_round' => 0,
        'sen_cost_per_round' => 20,
        'last_round_time' => time(),
        'where' => $me['current_area'] . '/' . $me['current_room']
    ];
    
    // 设置忙碌状态
    set_player_busy($charId, $busyTime * 2 + 1);
    
    // 写入数据库标记（防止SESSION并发问题）
    Database::execute(
        "UPDATE characters SET training_state = 'meditating', training_end_time = ? WHERE id = ?",
        [time() + $busyTime * 2 + 1, $charId]
    );
    
    // 执行第一轮冥思
    return executeMeditateRound($charId, $me);
}

/**
 * 执行一轮冥思修炼
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
function executeMeditateRound(int $charId, array $me): array {
    $meditateState = $_SESSION['pending_meditating'];
    
    if (empty($meditateState)) {
        return ['success' => false, 'message' => '你没有在冥思中。'];
    }
    
    // 检查是否移动了位置
    $currentWhere = $me['current_area'] . '/' . $me['current_room'];
    if ($currentWhere !== $meditateState['where']) {
        unset($_SESSION['pending_meditating']);
        set_player_busy($charId, 0);
        clearTrainingState($charId);
        return ['success' => true, 'message' => '你被打断了，停止了冥思。'];
    }
    
    // 获取法术技能等级和灵性
    $mappedSpells = SkillManager::querySkillMapped($charId, 'spells');
    $spellsSkillLevel = SkillManager::getSkillLevel($charId, $mappedSpells);
    $spi = intval($me['spi'] ?? 10);
    
    // 法力增长公式（参考原始代码）
    $manaGain = intval($spellsSkillLevel / 10) + intval($spi / 3) + mt_rand(0, 2);
    if ($manaGain < 5) {
        $manaGain = 5;
    }
    if ($manaGain > 40) {
        $manaGain = 40;
    }
    $manaGain *= 2;
    
    // 当前法力状态
    $currentMana = intval($me['mana'] ?? 0);
    $maxMana = intval($me['max_mana'] ?? 0);
    $maxManaLimit = SkillManager::queryMaxMana($charId);
    $maximumMana = intval($me['maximum_mana'] ?? 0);
    
    // 计算新的法力和精神
    $newSen = intval($me['sen'] ?? 0) - 20;
    $newMana = $currentMana + $manaGain;
    $newMaxMana = $maxMana;
    $manaUpgraded = false;
    $reachedLimit = false;
    
    // 检查是否达到上限提升条件（参考原始代码）
    if ($newMana > $maxMana * 2 && $maxMana > 0) {
        if ($maxMana >= $maxManaLimit) {
            // 已达到瓶颈
            $reachedLimit = true;
        } else {
            // 提升最大法力
            $newMaxMana = $maxMana + 1;
            $manaUpgraded = true;
        }
        $newMana = $newMaxMana;
        
        // 更新 maximum_mana
        if ($newMaxMana > $maximumMana) {
            $maximumMana = $newMaxMana;
        }
    }
    
    // 更新数据库
    $sql = "UPDATE characters SET sen = ?, mana = ?, max_mana = ?, maximum_mana = ? WHERE id = ?";
    Database::execute($sql, [$newSen, $newMana, $newMaxMana, $maximumMana, $charId]);
    
    // 更新冥思状态
    $_SESSION['pending_meditating']['current_round']++;
    $_SESSION['pending_meditating']['last_round_time'] = time();
    
    // 构建输出消息
    $output = [];
    
    if ($_SESSION['pending_meditating']['current_round'] === 1) {
        $output[] = "你盘膝而坐，静坐冥思了一会儿。";
    }
    
    if ($reachedLimit) {
        $output[] = "当你的法力增加的瞬间你忽然觉得脑中一片混乱，似乎法力的提升已经到了瓶颈。";
        unset($_SESSION['pending_meditating']);
        set_player_busy($charId, 0);
        clearTrainingState($charId);
    } elseif ($manaUpgraded) {
        $output[] = "你的法力增强了！";
    }
    
    // 检查是否完成所有轮次
    if (empty($_SESSION['pending_meditating']) === false && 
        $_SESSION['pending_meditating']['current_round'] >= $_SESSION['pending_meditating']['total_rounds']) {
        $output[] = "你行功完毕，从冥思中回过神来。";
        unset($_SESSION['pending_meditating']);
        set_player_busy($charId, 0);
        clearTrainingState($charId);
    }
    
    // 将冥思消息广播给玩家
    $fullMsg = implode("\n", $output);

    return [
        'success' => true,
        'message' => $fullMsg,
        'mana_gain' => $manaGain,
        'sen_cost' => 20,
        'max_mana' => $newMaxMana,
        'mana_upgraded' => $manaUpgraded,
        'reached_limit' => $reachedLimit,
        'current_round' => $_SESSION['pending_meditating']['current_round'] ?? 0,
        'total_rounds' => $_SESSION['pending_meditating']['total_rounds'] ?? 0
    ];
}

/**
 * 处理冥思轮次推进（在轮询时调用）
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
function processMeditateRound(int $charId): ?array {
    if (empty($_SESSION['pending_meditating'])) {
        return null;
    }
    
    $meditateState = $_SESSION['pending_meditating'];
    $now = time();
    
    // 检查是否到了下一轮的时间（每1秒一轮）
    if ($now - $meditateState['last_round_time'] < 1) {
        return null;
    }
    
    // 获取角色信息
    $me = CharacterModel::find($charId);
    if (!$me) {
        unset($_SESSION['pending_meditating']);
        return null;
    }
    
    // 检查是否移动了位置
    $currentWhere = $me['current_area'] . '/' . $me['current_room'];
    if ($currentWhere !== $meditateState['where']) {
        unset($_SESSION['pending_meditating']);
        set_player_busy($charId, 0);
        clearTrainingState($charId);
        return ['success' => true, 'message' => '你被打断了，停止了冥思。'];
    }
    
    // 执行冥思轮次
    return executeMeditateRound($charId, $me);
}
