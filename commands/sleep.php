<?php
/**
 * 睡眠命令
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */

// 加载技能消耗配置
static $_skillCosts = null;
if ($_skillCosts === null) {
    $_skillCosts = require __DIR__ . '/../config/skill_costs.php';
}

function cmd_sleep(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    require_once MODEL_PATH . 'Room.php';
    $room = RoomModel::load($char['current_area'], $char['current_room']);
    
    // 检查是否在可睡眠房间
    if (!$room || !isset($room['sleep_room']) || !$room['sleep_room']) {
        return ['success' => false, 'message' => '这里不是睡觉的地方。'];
    }
    
    // is_busy() 检查（统一使用 session 机制）
    if (is_player_busy($charId)) {
        return ['success' => false, 'message' => '你正忙着呢！'];
    }
    
    // 检查是否在战斗中
    require_once DAEMON_PATH . 'CombatDaemon.php';
    if (CombatDaemon::isInCombat($charId)) {
        return ['success' => false, 'message' => '战斗中不能睡觉！'];
    }
    
    // 特殊处理：如果当前玩家已经处于睡眠状态，但 sleep 命令带了其他玩家名字
    // 这是为了支持：B 在 A 已睡眠的情况下，通过 `sleep A` 自动接受 A 的睡眠邀请转为双人睡眠
    if (!empty($char['sleep_state']) && $char['sleep_state'] == 1 && !empty($param) && $param !== ($char['id'] ?? '') && $param !== ($char['name'] ?? '')) {
        // 检查是否收到了对方的睡眠邀请
        $pendingInvite = Database::queryOne(
            "SELECT * FROM sleep_invitations 
             WHERE to_char_id = ? AND from_char_id = (SELECT id FROM characters WHERE name = ? LIMIT 1) 
             AND status = 'pending' AND expire_at > NOW()",
            [$charId, $param]
        );
        
        if ($pendingInvite) {
            $targetChar = CharacterModel::findByName($param);
            $hasBed = isset($room['if_bed']) && $room['if_bed'];
            
            if ($targetChar && $hasBed && ($targetChar['gender'] ?? 'male') !== ($char['gender'] ?? 'male')) {
                return do_double_sleep($charId, $char, $param, $room, $hasBed);
            }
        }
        
        return ['success' => false, 'message' => '你正在睡觉，无法操作。'];
    }
    
    // 检查冷却时间（从配置读取）
    $cooldown = $_skillCosts['sleep']['cooldown_seconds'];
    $lastSleep = intval($char['last_sleep'] ?? 0);
    if ((time() - $lastSleep) < $cooldown) {
        $remaining = $cooldown - (time() - $lastSleep);
        return ['success' => false, 'message' => "你刚睡过一觉，先活动活动吧。（还需等待 {$remaining} 秒）"];
    }
    
    // 检查精神值
    $effSen = intval($char['eff_sen'] ?? $char['sen'] ?? 0);
    if ($effSen < 1) {
        return ['success' => false, 'message' => '你精神太差，一睡下去可能再也醒不过来了！'];
    }
    
    // 检查气血值
    $effKee = intval($char['eff_kee'] ?? $char['kee'] ?? 0);
    if ($effKee < 1) {
        return ['success' => false, 'message' => '你失血过多，一睡下去可能再也醒不过来了！'];
    }
    
    // 检查是否有床
    $hasBed = isset($room['if_bed']) && $room['if_bed'];
    
    // 如果没有参数，执行单人睡眠
    if (empty($param)) {
        // 启动睡眠中的定期气血恢复
        $_SESSION['sleeping_recover_' . $charId] = time();
        return do_single_sleep($charId, $char, $room, $hasBed);
    }
    
    // 如果参数是自己的ID或自己的名字，执行单人睡眠
    if ($param === ($char['id'] ?? '') || $param === ($char['name'] ?? '')) {
        // 启动睡眠中的定期气血恢复
        $_SESSION['sleeping_recover_' . $charId] = time();
        return do_single_sleep($charId, $char, $room, $hasBed);
    }
    
    // 尝试双人睡眠
    $result = do_double_sleep($charId, $char, $param, $room, $hasBed);
    if (!empty($result['success']) && $result['success'] === true && !empty($result['started_sleep'])) {
        // 启动双方睡眠中的定期气血恢复
        $_SESSION['sleeping_recover_' . $charId] = time();
        if (isset($param)) {
            $targetChar = CharacterModel::findByName($param);
            if ($targetChar) {
                $_SESSION['sleeping_recover_' . $targetChar['id']] = time();
            }
        }
    }
    return $result;
}

function do_single_sleep(int $charId, array $char, array $room, bool $hasBed): array {
    // 检查是否有配偶可以双人睡眠（夫妻不需要邀请）
    $coupleId = intval($char['couple_id'] ?? 0);
    if ($coupleId > 0) {
        $partner = CharacterModel::find($coupleId);
        
        // 检查配偶是否满足双人睡眠条件
        if ($partner && 
            ($partner['gender'] ?? 'male') !== ($char['gender'] ?? 'male') &&
            $partner['current_room'] === $char['current_room'] &&
            $partner['online'] == 1 &&
            isset($partner['sleep_state']) && $partner['sleep_state'] != 1 &&
            (!isset($partner['unconscious_state']) || $partner['unconscious_state'] != 1) &&
            (!isset($partner['daze_state']) || $partner['daze_state'] != 1) &&
            $hasBed
        ) {
            // 配偶在线且在同房间，自动转为双人睡眠
            return do_double_sleep($charId, $char, $partner['name'], $room, $hasBed);
        }
    }
    
    // 检查是否有其他玩家邀请了自己双人睡眠（且符合双人睡眠条件）
    $pendingInvite = Database::queryOne(
        "SELECT * FROM sleep_invitations 
         WHERE to_char_id = ? AND status = 'pending' AND expire_at > NOW()",
        [$charId]
    );
    
    if ($pendingInvite) {
        $partnerId = intval($pendingInvite['from_char_id']);
        $partner = CharacterModel::find($partnerId);
        
        // 检查对方是否满足双人睡眠条件
        if ($partner && 
            ($partner['gender'] ?? 'male') !== ($char['gender'] ?? 'male') &&
            $partner['current_room'] === $char['current_room'] &&
            $hasBed
        ) {
            // 自动接受邀请，转为双人睡眠
            return do_double_sleep($charId, $char, $partner['name'], $room, $hasBed);
        }
    }
    
    // 设置睡眠状态
    Database::execute(
        "UPDATE characters SET last_sleep = ?, sleep_state = 1 WHERE id = ?",
        [time(), $charId]
    );
    
    // 计算睡眠时长（体质越高，睡得越短）
    $con = intval($char['con'] ?? 10);
    $sleepDuration = random_int(10, 35 - $con) + 10;
    if ($sleepDuration < 5) $sleepDuration = 5;
    
    // 记录睡眠结束时间
    Database::execute(
        "UPDATE characters SET sleep_end_time = ? WHERE id = ?",
        [time() + $sleepDuration, $charId]
    );
    
    // 根据容貌、性别和房间环境生成睡眠描述
    $per = intval($char['per'] ?? 10);
    $str = intval($char['str'] ?? 10);
    $gender = $char['gender'] ?? 'male';
    
    // 优化：根据容貌和房间环境生成不同的睡眠描述
    $sleepMsg = generateSleepMessage($char, $room, $hasBed);
    
    // 发送消息
    require_once DAEMON_PATH . 'MessageDaemon.php';
    
    if ($hasBed) {
        $selfMsg = "你躺到床上，" . $sleepMsg;
        $roomMsg = "<span style='color: #FFD700;'>{$char['name']}躺到床上，" . str_replace('你', '他', $sleepMsg) . "</span>";
    } else {
        $selfMsg = "你躺在地上，" . $sleepMsg;
        $roomMsg = "<span style='color: #FFD700;'>{$char['name']}躺在地上，" . str_replace('你', '他', $sleepMsg) . "</span>";
    }
    
    // 附加睡眠时长提示，方便前端倒计时
    $selfMsg .= "\n<span style='color:#999999;'>（预计睡眠 {$sleepDuration} 秒，期间请勿操作）</span>";
    
    MessageDaemon::sendRoomMessage($charId, $roomMsg);
    
    return [
        'success' => true,
        'message' => $selfMsg,
        'sleep_duration' => $sleepDuration,
        'skip_queue' => true,
        'started_sleep' => true
    ];
}

function do_double_sleep(int $charId, array $char, string $param, array $room, bool $hasBed): array {
    require_once DAEMON_PATH . 'MessageDaemon.php';
    
    // 检查目标是否存在
    $targetChar = CharacterModel::findByName($param);
    if (!$targetChar) {
        return ['success' => false, 'message' => '这里没有这个人。'];
    }
    
    $targetId = $targetChar['id'];
    
    // 检查目标是否在同一个房间
    if ($targetChar['current_room'] !== $char['current_room']) {
        return ['success' => false, 'message' => '目标不在当前房间。'];
    }
    
    // 检查目标是否为玩家
    if (!isset($targetChar['user_id']) || !$targetChar['user_id']) {
        return ['success' => false, 'message' => '你只能和玩家一起睡！'];
    }
    
    // 检查性别是否不同
    if (($char['gender'] ?? 'male') === ($targetChar['gender'] ?? 'male')) {
        return ['success' => false, 'message' => '你只能和异性一起睡觉！'];
    }
    
    // 检查是否有床
    if (!$hasBed) {
        return ['success' => false, 'message' => '这里没有床，太不雅观了。'];
    }
    
    // 检查气血值是否足够
    $charKee = intval($char['kee'] ?? 0);
    $charMaxKee = intval($char['max_kee'] ?? 100);
    if (($charKee * 100 / $charMaxKee) < 50) {
        return ['success' => false, 'message' => '你太累了，没力气做这种事情。'];
    }
    
    // 检查目标是否处于其他状态
    if (!empty($targetChar['sleep_state']) && $targetChar['sleep_state'] == 1) {
        return ['success' => false, 'message' => $targetChar['name'] . '正在睡觉，无法邀请。'];
    }
    if (!empty($targetChar['unconscious_state']) && $targetChar['unconscious_state'] == 1) {
        return ['success' => false, 'message' => $targetChar['name'] . '正在昏迷，无法邀请。'];
    }
    if (!empty($targetChar['daze_state']) && $targetChar['daze_state'] == 1) {
        return ['success' => false, 'message' => $targetChar['name'] . '正在发呆，无法邀请。'];
    }
    
    // 先清理过期的睡眠邀请
    Database::execute(
        "DELETE FROM sleep_invitations WHERE expire_at < NOW() AND status = 'pending'"
    );
    
    // 检查目标是否已经邀请了当前玩家（双向匹配）
    $pendingInvite = Database::queryOne(
        "SELECT * FROM sleep_invitations 
         WHERE from_char_id = ? AND to_char_id = ? AND status = 'pending' AND expire_at > NOW()",
        [$targetId, $charId]
    );
    
    if (!$pendingInvite) {
        // 检查当前玩家是否已经发送过邀请但未过期
        $existingInvite = Database::queryOne(
            "SELECT * FROM sleep_invitations 
             WHERE from_char_id = ? AND to_char_id = ? AND status = 'pending' AND expire_at > NOW()",
            [$charId, $targetId]
        );
        
        if ($existingInvite) {
            return ['success' => true, 'message' => "你已邀请 {$targetChar['name']} 同床共枕，等待对方输入 <span style='color:#FFD700;'>sleep {$char['name']}</span> 来接受邀请。超时时间5分钟。"];
        }
        
        // 创建邀请记录
        Database::execute(
            "INSERT INTO sleep_invitations (from_char_id, to_char_id, status, expire_at) 
             VALUES (?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 5 MINUTE))",
            [$charId, $targetId]
        );
        
        // 请求同意
        $acceptUrl = "action.php?action=sleep&param=" . urlencode($char['name']);
        MessageDaemon::sendPrivateMessage($targetId, 
            "<span style='color: #FF69B4;'>\n【双人睡眠邀请】\n{$char['name']}想要和你同床共枕！\n如果你也愿意，请 <a href='{$acceptUrl}' style='color:#FFD700;font-weight:bold;'>点击这里接受邀请</a>\n或点击房间内的\"上床\"按钮。\n超时时间5分钟。\n</span>",
            $charId
        );
        return ['success' => true, 'message' => "你已邀请 {$targetChar['name']} 同床共枕，等待对方接受邀请。超时时间5分钟。"];
    }
    
    // 双方同意，开始双人睡眠
    // 标记邀请为已接受
    Database::execute(
        "UPDATE sleep_invitations SET status = 'accepted', resolved_at = NOW() 
         WHERE from_char_id = ? AND to_char_id = ? AND status = 'pending'",
        [$targetId, $charId]
    );
    
    // 清理相关的所有待处理邀请
    Database::execute(
        "UPDATE sleep_invitations SET status = 'expired', resolved_at = NOW() 
         WHERE ((from_char_id = ? AND to_char_id = ?) OR (from_char_id = ? AND to_char_id = ?))
         AND status = 'pending'",
        [$charId, $targetId, $targetId, $charId]
    );
    
    // 设置双方睡眠状态
    Database::execute(
        "UPDATE characters SET last_sleep = ?, sleep_state = 1 WHERE id IN (?, ?)",
        [time(), $charId, $targetId]
    );
    
    // 计算睡眠时长
    $charCon = intval($char['con'] ?? 10);
    $targetCon = intval($targetChar['con'] ?? 10);
    $sleepDuration1 = random_int(10, 35 - $charCon) + 10;
    $sleepDuration2 = random_int(10, 35 - $targetCon) + 10;
    
    Database::execute(
        "UPDATE characters SET sleep_end_time = ? WHERE id = ?",
        [time() + $sleepDuration1, $charId]
    );
    Database::execute(
        "UPDATE characters SET sleep_end_time = ? WHERE id = ?",
        [time() + $sleepDuration2, $targetId]
    );
    
    // 发送消息
    $roomMsg = "<span style='color: #FFD700;'>\n{$char['name']}和{$targetChar['name']}相拥而卧，共度了一夜春宵...\n</span>";
    MessageDaemon::sendRoomMessage($charId, $roomMsg);
    
    $selfMsg1 = "";
    $selfMsg2 = "";
    
    if (($char['gender'] ?? 'male') === 'male') {
        $selfMsg1 = "<span style='color: #FFD700;'>\n你拥着{$targetChar['name']}的娇躯，感到无比的幸福和满足...\n</span>";
        $selfMsg2 = "<span style='color: #FFD700;'>\n你躺在{$char['name']}的怀里，感到无比的幸福和满足...\n</span>";
        MessageDaemon::queueMessageToSelf($charId, $selfMsg1, 'private');
        MessageDaemon::queueMessageToSelf($targetId, $selfMsg2, 'private');
    } else {
        $selfMsg2 = "<span style='color: #FFD700;'>\n你拥着{$char['name']}的娇躯，感到无比的幸福和满足...\n</span>";
        $selfMsg1 = "<span style='color: #FFD700;'>\n你躺在{$targetChar['name']}的怀里，感到无比的幸福和满足...\n</span>";
        MessageDaemon::queueMessageToSelf($targetId, $selfMsg2, 'private');
        MessageDaemon::queueMessageToSelf($charId, $selfMsg1, 'private');
    }
    
    return [
        'success' => true,
        'message' => "你和{$targetChar['name']}相拥而卧，共度了一夜春宵...",
        'skip_queue' => true,
        'started_sleep' => true
    ];
}

/**
 * 检查并唤醒玩家（在任何操作前调用）
 * @return bool 是否被唤醒
 */
function check_and_wakeup(int $charId): bool {
    $char = CharacterModel::find($charId);
    
    if (!$char) return false;
    
    // 检查是否正在睡眠
    if (!isset($char['sleep_state']) || $char['sleep_state'] != 1) {
        return false;
    }
    
    // 检查睡眠是否结束
    $sleepEndTime = intval($char['sleep_end_time'] ?? 0);
    if (time() < $sleepEndTime) {
        return false;
    }
    
    // 执行唤醒
    wakeup_player($charId);
    return true;
}

/**
 * 唤醒玩家
 * 参考原始项目 sleep.c 的 wakeup1 函数逻辑
 */
function wakeup_player(int $charId): void {
    $char = CharacterModel::find($charId);
    
    if (!$char) return;
    
    // 检查是否正在睡眠
    if (!isset($char['sleep_state']) || $char['sleep_state'] != 1) {
        return;
    }
    
    // 清理该角色相关的所有待处理睡眠邀请
    Database::execute(
        "UPDATE sleep_invitations SET status = 'expired', resolved_at = NOW() 
         WHERE (from_char_id = ? OR to_char_id = ?) AND status = 'pending'",
        [$charId, $charId]
    );
    
    // 参考原始项目：恢复精神到有效上限
    $effSen = intval($char['eff_sen'] ?? 0);
    $maxSen = intval($char['max_sen'] ?? $char['sen'] ?? 100);
    $newSen = $effSen > 0 ? $effSen : $maxSen;
    
    // 参考原始项目：恢复法力到上限
    $maxMana = intval($char['max_mana'] ?? $char['mana'] ?? 0);
    $newMana = $maxMana;
    
    // 参考原始项目：睡觉醒来不恢复气血！
    
    // 清除睡眠恢复标记
    unset($_SESSION['sleeping_recover_' . $charId]);
    
    Database::execute(
        "UPDATE characters SET
            sen = ?,
            mana = ?,
            near_death_time = 0,
            sleep_state = 0,
            sleep_end_time = NULL
         WHERE id = ?",
        [$newSen, $newMana, $charId]
    );
    
    // 发送唤醒消息
    require_once DAEMON_PATH . 'MessageDaemon.php';
    
    $wakeMessages = [
        '伸了个懒腰，慢慢醒了过来。',
        '打了个哈欠，睁开了眼睛。',
        '缓缓睁开眼睛，清醒了过来。',
        '一觉醒来，精神百倍。'
    ];
    
    $wakeMsg = $wakeMessages[random_int(0, count($wakeMessages) - 1)];
    
    $wakeRoomMsg = "<span style='color: #FFD700;'>{$char['name']}" . $wakeMsg . "</span>";
    $wakeSelfMsg = "<span style='color: #FFD700;'>你" . $wakeMsg . "</span>";
    
    // 参考原始项目：醒来时不显示气血恢复（因为不恢复气血）
    
    MessageDaemon::sendRoomMessage($charId, $wakeRoomMsg);
    
    // 将自己的醒来消息加入消息队列（用于聊天页面显示）
    MessageDaemon::queueMessageToSelf($charId, $wakeSelfMsg, 'room_event');
}

/**
 * 睡眠中气血恢复检查（已废弃）
 * 参考原始项目：睡觉期间不恢复气血
 * @deprecated
 * @return int 0
 */
function sleeping_recover_kee(int $charId): int {
    // 原始项目 sleep.c 中，睡觉醒来时：
    // - 恢复精神到 eff_sen
    // - 恢复法力到 max_mana
    // - 不恢复气血！
    // 所以此函数不再需要
    return 0;
}

/**
 * 根据玩家容貌、性别和房间环境生成睡眠描述
 * @param array $char 玩家数据
 * @param array $room 房间数据
 * @param bool $hasBed 是否有床
 * @return string 睡眠描述
 */
function generateSleepMessage(array $char, array $room, bool $hasBed): string {
    $per = intval($char['per'] ?? 10);
    $str = intval($char['str'] ?? 10);
    $gender = $char['gender'] ?? 'male';
    $roomName = $room['name'] ?? '';
    
    // 判断玩家是否容貌出众
    $isPretty = false;
    if (random_int(1, 100) <= $per * 2) {
        $isPretty = true;
    } elseif ($str < 8) {
        $isPretty = true;
    } elseif ($gender === 'female' && random_int(1, 5) > 1) {
        $isPretty = true;
    }
    
    // 根据房间环境分类
    $isQuiet = (
        strpos($roomName, '厢') !== false || 
        strpos($roomName, '房') !== false || 
        strpos($roomName, '居') !== false ||
        strpos($roomName, '殿') !== false ||
        strpos($roomName, '阁') !== false
    );
    
    $isWild = (
        strpos($roomName, '山') !== false || 
        strpos($roomName, '林') !== false || 
        strpos($roomName, '野') !== false ||
        strpos($roomName, '原') !== false
    );
    
    $messages = [];
    
    if ($isPretty) {
        // 容貌出众的睡眠描述
        $messages = [
            '你侧身躺下，不一会儿就睡着了。',
            '你轻轻躺下，很快就进入了甜美的梦乡。',
            '你安静地睡去，呼吸渐渐平稳。',
            '你闭目养神，缓缓进入了梦乡。',
        ];
        
        if ($hasBed) {
            $messages[] = '你倚靠在床头，整理好被褥后安然入睡。';
            $messages[] = '你优雅地躺下，不一会儿便进入了香甜的梦乡。';
        }
        
        if ($isQuiet) {
            $messages[] = '你轻轻躺下，在这幽静的氛围中很快进入了梦乡。';
        }
    } else {
        // 普通/较丑的睡眠描述
        $messages = [
            '你一躺下就呼呼大睡起来。',
            '你闭上眼睛，很快就进入了梦乡。',
            '你倒头便睡，鼾声大作。',
            '你沉沉睡去。',
            '你四仰八叉地躺着，不一会儿就打起了呼噜。',
        ];
        
        if (!$hasBed) {
            $messages[] = '你在地上找了个舒服的姿势，蜷缩着睡着了。';
            $messages[] = '你躺在硬邦邦的地上，很快发出了鼾声。';
        }
        
        if ($isWild) {
            $messages[] = '你躺在野外，伴着虫鸣声沉沉睡去。';
        }
        
        if ($gender === 'female' && $isPretty) {
            $messages[] = '你轻轻侧卧，呼吸渐渐均匀，睡容安详。';
        }
    }
    
    return $messages[array_rand($messages)];
}