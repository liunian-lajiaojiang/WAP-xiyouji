<?php

// 调试模式：在URL加上 ?debug=1 可查看错误信息
if (isset($_GET['debug'])) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 房间主页 - 网页版MUD游戏核心页面
 */

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

// 确保数据库表结构完整
//Database::addMarriedColumn();
//Database::addRoomItemsEnchantmentsColumn();
//Database::addUnconsciousAndDazeColumns();
//Database::addLiquidContainerColumns();
Database::addSleepInvitationsTable();

require_once MODEL_PATH . 'Character.php';
require_once MODEL_PATH . 'Room.php';
require_once MODEL_PATH . 'Corpse.php';
require_once MODEL_PATH . 'NpcRespawn.php';
require_once DAEMON_PATH . 'CombatDaemon.php';
require_once DAEMON_PATH . 'MessageDaemon.php';
require_once DAEMON_PATH . 'NatureDaemon.php';

// 定期清理过期房间物品，10%概率触发
if (rand(1, 10) === 1) {
    RoomModel::cleanExpiredItems(30); // 清理30分钟前的物品
}

// 定期推进尸体腐烂阶段 + 清理过期尸体，5%概率触发
if (rand(1, 20) === 1) {
    Corpse::advanceDecayPhases();
    Corpse::cleanupDecayedCorpses();
}

// 定期处理NPC重生，10%概率触发
if (rand(1, 10) === 1) {
    NpcRespawn::processPendingRespawns();
}

// ==================== 全局变量初始化 ====================

// 人参果园事件状态（默认false，后续根据房间判断）
$renshenEventActive = false;
$renshenPhase = '';

// 定期清理五庄观暗室特殊物品过期（10%概率触发）
// 还原原始LPC: huangtong-key.c self_dest() 和 taiyi-book.c destroy_book()
if (rand(1, 10) === 1) {
    try {
        $now_clean = time();
        
        // ---- 黄铜钥匙过期清理 ----
        // 原始LPC: huangtong-key.c init() 中 call_out("self_dest", 1200+random(600))
        //   self_dest 中: tell_object(environment(me), name()+"化做一道青烟消散了。\n"); destruct(me);
        $keyStateFile = __DIR__ . '/../data/wuzhuang_key_state.json';
        if (file_exists($keyStateFile)) {
            $keyState = json_decode(file_get_contents($keyStateFile), true);
            if ($keyState && ($keyState['key_expire_at'] ?? 0) > 0 && $now_clean >= $keyState['key_expire_at']) {
                // 1. 从地面移除（如果还在暗室地面）
                if ($keyState['on_floor'] ?? false) {
                    RoomModel::removeItemFromRoom('qujing', 'qujing/wuzhuang/anshi', 'huangtong-key');
                }
                // 2. 从持有者背包移除（如果已被拾取）
                $keyHolderId = $keyState['key_holder_char_id'] ?? null;
                if ($keyHolderId) {
                    ItemModel::removeFromInventory($keyHolderId, 'huangtong-key', 1);
                    // 通知持有者（还原 self_dest 中的 tell_object）
                    $keyHolder = CharacterModel::find($keyHolderId);
                    if ($keyHolder && !empty($keyHolder['current_room']) && ($keyHolder['online'] ?? 0) == 1) {
                        $destMsg = HTML_WHT . "你背包中的黄铜钥匙忽然化做一道青烟消散了。" . HTML_NOR;
                        MessageDaemon::broadcastToRoom($keyHolder['current_room'], $destMsg, $keyHolderId);
                        MessageDaemon::queueMessageToSelf($keyHolderId, $destMsg, 'self_event');
                    }
                }
                // 3. 重置生成状态
                $keyState['next_key_available'] = 0;
                $keyState['key_expire_at'] = 0;
                $keyState['key_taken_at'] = 0;
                $keyState['key_holder_char_id'] = null;
                $keyState['on_floor'] = false;
                file_put_contents($keyStateFile, json_encode($keyState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        }
        
        // ---- 太乙真经过期清理 ----
        // 原始LPC: taiyi-book.c destroy_book()
        //   message_vision("书中放出一只白鹤，把" + name() + "叼起来消失不见了。\n", ...)
        //   destruct(this_object())
        $bookStateFile = __DIR__ . '/../data/wuzhuang_secret_room_state.json';
        if (file_exists($bookStateFile)) {
            $bookState = json_decode(file_get_contents($bookStateFile), true);
            if ($bookState && ($bookState['book_expire_at'] ?? 0) > 0 && $now_clean >= $bookState['book_expire_at']) {
                // 1. 从地面移除（如果还在密室地面）
                if ($bookState['book_on_floor'] ?? false) {
                    RoomModel::removeItemFromRoom('qujing', 'qujing/wuzhuang/anshi-more1', 'taiyi');
                }
                // 2. 从持有者背包移除（如果已被拾取）
                $bookHolderId = $bookState['book_holder_char_id'] ?? null;
                if ($bookHolderId) {
                    ItemModel::removeFromInventory($bookHolderId, 'taiyi', 1);
                    // 通知持有者（还原 destroy_book 中的 message_vision）
                    $bookHolder = CharacterModel::find($bookHolderId);
                    if ($bookHolder && !empty($bookHolder['current_room']) && ($bookHolder['online'] ?? 0) == 1) {
                        $destMsg = HTML_WHT . "你背包中的太乙真经忽然化为一道金光消散了。" . HTML_NOR;
                        MessageDaemon::broadcastToRoom($bookHolder['current_room'], $destMsg, $bookHolderId);
                        MessageDaemon::queueMessageToSelf($bookHolderId, $destMsg, 'self_event');
                    }
                }
                // 3. 重置状态
                $bookState['book_generated_at'] = 0;
                $bookState['book_expire_at'] = 0;
                $bookState['book_holder_char_id'] = null;
                $bookState['book_on_floor'] = false;
                file_put_contents($bookStateFile, json_encode($bookState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        }
    } catch (\Exception $e) {
        error_log("五庄观特殊物品清理失败: " . $e->getMessage());
    }
}

// 夜间自动关门（游戏时间：18:00-03:59为夜间）
// 还原原始LPC: 门在夜间自动关闭的机制
if (rand(1, 5) === 1) {
    try {
        require_once DAEMON_PATH . 'NatureDaemon.php';
        if (NatureDaemon::isNight()) {
            // 查找当前房间所有开着的门，自动关闭
            $openDoors = Database::queryAll(
                "SELECT room_id, direction, door_name FROM room_exits 
                 WHERE door_name IS NOT NULL AND door_name != '' AND door_closed = 0"
            );
            if (!empty($openDoors)) {
                foreach ($openDoors as $door) {
                    Database::execute(
                        "UPDATE room_exits SET door_closed = 1 WHERE room_id = ? AND direction = ?",
                        [$door['room_id'], $door['direction']]
                    );
                    // 同时关闭对面的门
                    $oppositeMap = [
                        'north' => 'south', 'south' => 'north',
                        'east' => 'west', 'west' => 'east',
                        'up' => 'down', 'down' => 'up',
                        'northeast' => 'southwest', 'southwest' => 'northeast',
                        'northwest' => 'southeast', 'southeast' => 'northwest',
                    ];
                    $oppositeDir = $oppositeMap[$door['direction']] ?? null;
                    if ($oppositeDir) {
                        // 找到对面房间ID
                        $exitInfo = Database::queryOne(
                            "SELECT target_area, target_room FROM room_exits WHERE room_id = ? AND direction = ?",
                            [$door['room_id'], $door['direction']]
                        );
                        if ($exitInfo) {
                            $targetRoomId = $exitInfo['target_area'] . '/' . $exitInfo['target_room'];
                            Database::execute(
                                "UPDATE room_exits SET door_closed = 1 WHERE room_id = ? AND direction = ?",
                                [$targetRoomId, $oppositeDir]
                            );
                        }
                    }
                }
            }
        }
    } catch (\Exception $e) {
        error_log("夜间自动关门失败: " . $e->getMessage());
    }
}

// 要求登录
require_login();

// 检查服务器维护状态：如果正在维护且当前用户不是大巫师及以上，强制登出
$userId = intval($_SESSION['user_id'] ?? 0);
$shutdownStatus = Database::queryOne("SELECT value FROM variables WHERE var_key = 'shutdown_status'");
if ($shutdownStatus && $shutdownStatus['value'] === 'active') {
    $user = Database::queryOne("SELECT wizard_level FROM users WHERE id = ?", [$userId]);
    $wizLevel = intval($user['wizard_level'] ?? 0);
    if ($wizLevel < 5) {
        // 强制登出
        session_destroy();
        redirect('../index.php?error=maintenance');
    }
}

$charId = get_char_id();
$char = CharacterModel::getFullInfo($charId);
if (!$char) {
    redirect('character_select.php');
}

// 食物和饮水自然减耗（每60秒减1）
$lastFoodCheck = $_SESSION['last_food_water_check'] ?? 0;
$currentTime = time();
if ($currentTime - $lastFoodCheck >= 60) {
    $newFood = max(0, $char['food'] - 1);
    $newWater = max(0, $char['water'] - 1);
    
    // 只有当有变化时才更新数据库
    if ($newFood != $char['food'] || $newWater != $char['water']) {
        Database::execute(
            'UPDATE characters SET food = ?, water = ? WHERE id = ?',
            [$newFood, $newWater, $charId]
        );
        // 重新获取角色数据
        $char = CharacterModel::getFullInfo($charId);
    }
    $_SESSION['last_food_water_check'] = $currentTime;
}

// 发呆状态定时检查（每10秒检查一次，偶尔显示随机消息）
$lastDazeCheck = $_SESSION['last_daze_check_' . $charId] ?? 0;
if ($currentTime - $lastDazeCheck >= 10) {
    if (!empty($char['daze_state']) && $char['daze_state'] == 1) {
        $dazeEndTime = intval($char['daze_end_time'] ?? 0);
        if ($currentTime >= $dazeEndTime) {
            if (function_exists('snap_out_of_daze')) {
                snap_out_of_daze($charId);
                $char = CharacterModel::getFullInfo($charId);
            }
        } else {
            if (rand(1, 5) === 1) {
                $dazeThoughts = [
                    '你望着远方，思绪飘到了九霄云外...',
                    '你呆呆地站着，不知道在想些什么。',
                    '你眼神空洞，整个人都放空了。',
                    '你心不在焉地站着，思绪不知飘到了哪里。',
                    '你发起呆来，周围的一切似乎都与你无关。',
                    '你愣愣地站着，似乎在思考人生的意义。',
                    '你两眼发直，陷入了沉思...',
                    '你望着天空，不知道在想什么。',
                    '你沉浸在自己的世界里，对外界毫无察觉。',
                    '你发呆中，时间仿佛静止了。'
                ];
                $randomThought = $dazeThoughts[array_rand($dazeThoughts)];
                $_SESSION['flash_message'] = [
                    'content' => '<span style="color:#AAAAAA;">' . $randomThought . '</span>',
                    'timestamp' => time()
                ];
            }
        }
    }
    $_SESSION['last_daze_check_' . $charId] = $currentTime;
}

// 设置角色在线状态为 1（防止直接访问 room.php 时状态不正确）
if ($char['online'] != 1) {
    CharacterModel::updateOnlineStatus($charId, true);
}

// 检查木筏状态（如果在相关房间）
$currentRoomId = $char['current_room'];
if (in_array($currentRoomId, ['changan/eastseashore', 'changan/mufa', 'changan/aolaiws'])) {
    require_once DAEMON_PATH . 'MufaHandler.php';
    try {
        $mufaHandler = new MufaHandler();
        // 检查木筏状态并向当前玩家发送时间线消息
        $mufaHandler->checkMufaStateForPlayer($charId, $currentRoomId);
    } catch (\Exception $e) {
        // 忽略错误，不影响页面加载
        error_log("木筏状态检查失败: " . $e->getMessage());
    }
}

// 检查变化术状态，持续消耗法力
if (isset($_SESSION['transform_' . $charId])) {
    $transformData = $_SESSION['transform_' . $charId];
    $lastCheck = $_SESSION['transform_timer_' . $charId] ?? time();
    $currentTime = time();

    // 每5秒检查一次
    if ($currentTime - $lastCheck >= 5) {
        $dmana = $transformData['dmana'] ?? 5; // 默认5，防止错误
        $originalName = $transformData['original_name'] ?? $char['name'];
        $currentMana = $char['mana'] ?? 0;

        // 计算经过的时间周期
        $periods = floor(($currentTime - $lastCheck) / 5);
        $totalCost = $dmana * $periods;
        if ($currentMana - $totalCost > 50) {
            // 法力充足，继续消耗法力
            Database::execute(
                'UPDATE characters SET mana = mana - ? WHERE id = ?',
                [$totalCost, $charId]
            );
            $_SESSION['transform_timer_' . $charId] = $currentTime;
        } else {
            // 法力不足，恢复原状
            unset($_SESSION['transform_' . $charId]);
            unset($_SESSION['transform_timer_' . $charId]);

            // 清除数据库中的变化状态
            save_transform_state($charId, null);

            // 广播恢复消息给其他人
            $roomId = $char['current_room'];
            $broadcastMessage = HTML_HIRED . '只见' . $originalName . '神色一白，一阵烟雾之后，已经恢复了原形。<br>' . HTML_NOR;
            MessageDaemon::broadcastToRoom($roomId, $broadcastMessage, intval($charId));

            // 发送恢复消息给自己
            $selfMessage = HTML_HIRED . '你神色一白，一阵烟雾之后，已经恢复了原形。<br>' . HTML_NOR;
            MessageDaemon::sendPrivateMessage(intval($charId), $selfMessage, intval($charId));

            // 添加提示消息
            $_SESSION['flash_message'] = [
                'content' => HTML_HIRED . '你的法力不足以维持变化术,已经恢复了原样' . HTML_NOR,
                'timestamp' => time()
            ];
        }
    }
}

// 获取URL参数中的房间信息
$area = $_GET['area'] ?? '';
$roomId = $_GET['room'] ?? '';

// 如果没有指定房间，使用角色当前房间
if (empty($area) || empty($roomId)) {
    $area = $char['current_area'];
    $roomId = $char['current_room'];
}

// 如果当前区域是 home（玩家房产），重定向到 home.php
if ($area === 'home' || strpos($roomId, 'home/') === 0) {
    header('Location: home.php');
    exit;
}

// 检查玩家是否被监禁，如果是则强制跳转到监禁房间
// 同时检查是否有到期自动释放的监禁
if (isset($_SESSION['imprisoned']) && $_SESSION['imprisoned']) {
    require_once __DIR__ . '/../helpers/BanHelper.php';
    
    // 检查是否到了自动释放时间
    $releaseState = Database::queryOne(
        "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = 'prison_release'",
        [$charId]
    );
    if ($releaseState && !empty($releaseState['state_value'])) {
        $releaseTime = strtotime($releaseState['state_value']);
        if ($releaseTime && time() >= $releaseTime) {
            // 监禁到期，自动释放
            $userId = $_SESSION['user_id'] ?? 0;
            if ($userId > 0) {
                BanHelper::releaseUser(intval($userId));
                unset($_SESSION['imprisoned']);
                $area = $char['current_area'];
                $roomId = $char['current_room'];
                $latestMessage = HTML_HICYN . '你的监禁期已满，已被自动释放！你被送回了客栈。' . HTML_NOR;
                // 不需要跳转，直接恢复正常房间
            }
        }
    }
    
    // 如果仍然被监禁，强制跳转
    if (isset($_SESSION['imprisoned']) && $_SESSION['imprisoned']) {
        $area = 'wiz';
        $roomId = 'prison';
        // 确保角色位置更新到监禁房间
        CharacterModel::updatePosition($charId, 'wiz', 'wiz/prison');
        $latestMessage = HTML_HIRED . '你已被关入监禁室！' . HTML_NOR
            . '<br>' . HTML_CYN . '你只能静静等待，或请求巫师提前释放。' . HTML_NOR;
    }
}

// 检查玩家是否在欢迎室，如果是则强制跳转到欢迎室
require_once __DIR__ . '/../commands/toguest.php';
$userId = $_SESSION['user_id'] ?? 0;
if (isInGuestRoom(intval($userId))) {
    $area = 'wiz';
    $roomId = 'guest';
    // 确保角色位置更新到欢迎室
    CharacterModel::updatePosition($charId, 'wiz', 'wiz/guest');
    $latestMessage = HTML_HIYEL . '你正在等待巫师审核，请耐心等待。' . HTML_NOR;
}

// 检查玩家是否在公堂（被逮捕），如果是则强制跳转到公堂
$courtSuspect = Database::queryOne(
    "SELECT cs.*, u.username as arrested_by_name FROM court_suspects cs LEFT JOIN users u ON cs.arrested_by = u.id WHERE cs.user_id = ? AND cs.status IN (1, 2) ORDER BY cs.arrest_time DESC LIMIT 1",
    [$userId]
);
if ($courtSuspect) {
    $area = 'wiz';
    $roomId = 'gongtang';
    CharacterModel::updatePosition($charId, 'wiz', 'wiz/gongtang');
    $latestMessage = HTML_HIRED . '你已被逮捕，正在公堂等待审判！' . HTML_NOR
        . '<br>' . HTML_YEL . '逮捕原因：' . h($courtSuspect['reason']) . HTML_NOR
        . '<br>' . HTML_YEL . '逮捕者：' . h($courtSuspect['arrested_by_name']) . HTML_NOR
        . '<br>' . HTML_CYN . '请等待巫师审理你的案件。' . HTML_NOR;
}

// 检查巫师区域权限：wiz区域只有巫师才能进入（监禁、欢迎室、公堂玩家除外）
if ($area === 'wiz' || $area === 'd/wiz' || (strpos($roomId, 'wiz/') === 0)) {
    // 检查是否是特殊房间（监禁室、欢迎室、公堂）
    $isImprisoned = isset($_SESSION['imprisoned']) && $_SESSION['imprisoned'];
    $isInGuest = isInGuestRoom($userId);
    $isPrisonRoom = (strpos($roomId, 'prison') !== false);
    $isGuestRoom = (strpos($roomId, 'guest') !== false);
    $isCourtRoom = (strpos($roomId, 'gongtang') !== false || strpos($roomId, 'court') !== false);
    
    // 特殊房间允许非巫师进入
    $allowedInSpecialRoom = ($isImprisoned && $isPrisonRoom) || 
                            ($isInGuest && $isGuestRoom) || 
                            $isCourtRoom;
    
    if (!$allowedInSpecialRoom) {
        // 非特殊房间，检查巫师权限
        require_once MODEL_PATH . 'User.php';
        if (!UserModel::isElder(intval($userId))) {
            // 没有巫师权限，显示提示并使用角色当前房间
            $latestMessage = HTML_HIRED . '那里只有巫师才能进去' . HTML_NOR;
            $area = $char['current_area'];
            $roomId = $char['current_room'];
            // 不更新位置，使用角色当前房间
        }
    }
}

// 构建完整的 room_id（处理 roomId 不包含区域前缀的情况）
if (strpos($roomId, '/') === false && !empty($area)) {
    // roomId 只是房间名（如 flowerfruit），需要拼接 area
    $fullRoomId = $area . '/' . $roomId;
} elseif (!empty($area) && strpos($roomId, $area . '/') !== 0) {
    // roomId 包含斜杠但不以 area 开头（如 hgs/flowerfruit），需要添加 area 前缀
    $fullRoomId = $area . '/' . $roomId;
} else {
    // roomId 已经是完整路径（如 dntg/hgs/flowerfruit）
    $fullRoomId = $roomId;
}

// 更新角色位置（如果是从其他页面跳转过来的话）
if ($area && $roomId) {
    // 去除 'd/' 前缀以匹配数据库格式（如 d/hgs/flowerfruit -> hgs/flowerfruit）
    $cleanArea = preg_replace('/^d\//', '', $area);

    // 构建完整的room_id
    if (strpos($roomId, '/') === false) {
        // roomId 只是房间名，需要拼接 area
        $fullRoomId = $cleanArea . '/' . $roomId;
    } elseif (strpos($roomId, $cleanArea . '/') !== 0) {
        // roomId 包含斜杠但不以 area 开头，需要添加 area 前缀
        $fullRoomId = $cleanArea . '/' . $roomId;
    } else {
        // roomId 已经是完整路径
        $fullRoomId = $roomId;
    }
    CharacterModel::updatePosition($charId, $cleanArea, $fullRoomId);
    // 重新获取角色信息
    $char = CharacterModel::getFullInfo($charId);
}

// 获取当前房间完整信息（使用完整路径）
$room = RoomModel::getFullInfo($area, $fullRoomId);

// 白虎岭迷宫特殊处理：如果当前房间是迷宫，且有迷宫数据，生成迷宫内容
if ($fullRoomId === 'qujing/baihuling/maze') {
    // 获取迷宫数据
    $mazeKey = 'baihuling_maze_' . $charId;
    if (isset($_SESSION[$mazeKey])) {
        $mazeData = $_SESSION[$mazeKey];
        $pos = $_GET['pos'] ?? $_SESSION['baihuling_current_pos_' . $charId] ?? '0,0,0';
        
        // 保存当前位置到Session
        $_SESSION['baihuling_current_pos_' . $charId] = $pos;
        
        // 生成迷宫HTML
        require_once DAEMON_PATH . 'BaihulingHandler.php';
        $handler = new BaihulingHandler();
        $mazeHtml = $handler->generateMazeRoomHtmlPublic($charId, $mazeData, $pos);
        
        // 将迷宫HTML存储到session，供JavaScript使用
        $_SESSION['baihuling_maze_html'] = $mazeHtml;
    }
}

// 检测五庄观特殊物品（钥匙/太乙真经）是否已被当前玩家从地面拾取
// 还原原始LPC: huangtong-key.c announce() 机制——物品被拾取时自动触发广播
// 注意：即使房间没有物品也要检测，因为钥匙可能是唯一物品被拾取后房间变空
if ($room && in_array($fullRoomId, ['qujing/wuzhuang/anshi', 'qujing/wuzhuang/anshi-more1'])) {
    require_once __DIR__ . '/../commands/mo.php';
    if (function_exists('checkSpecialItemPickup')) {
        $roomItems = $room['items'] ?? [];
        checkSpecialItemPickup(intval($charId), $roomItems);
    }
}

// 精卫填海任务：在东海之滨或东海海滩时更新精卫飞行状态
if ($room && in_array($fullRoomId, ['changan/eastseashore', 'changan/beach'], true)) {
    require_once DAEMON_PATH . 'JingweiDaemon.php';
    JingweiDaemon::updateState();

    // 在海滩房间检查涨潮
    if ($fullRoomId === 'changan/beach') {
        $floodResult = JingweiDaemon::checkFlood(intval($charId));
        if ($floodResult !== null) {
            $msgKey = "flash_message_{$charId}";
            if (!isset($_SESSION[$msgKey]) || !is_array($_SESSION[$msgKey])) {
                $_SESSION[$msgKey] = [];
            }
            $_SESSION[$msgKey][] = $floodResult['message'];
        }
    }
}

// 八卦桥迷宫逻辑（还原原始LPC wzgmaze1-8.c 的哈密顿路径谜题）
// 进入迷宫房间时：标记访问 → 检测重复 → 检测完成 → 传送/重置
if ($room && preg_match('/qujing\/wuzhuang\/wzgmaze\d$/', $fullRoomId)) {
    require_once DAEMON_PATH . 'WzgmazeHandler.php';
    $mazeResult = WzgmazeHandler::handleEnterRoom(intval($charId), $fullRoomId);
    if ($mazeResult) {
        // 迷宫完成 → 传送到 northpool
        if (!empty($mazeResult['message'])) {
            $_SESSION['flash_message'] = [
                'type' => 'success',
                'content' => $mazeResult['message'],
                'timestamp' => time(),
            ];
        }
        // 重定向到 northpool
        header('Location: room.php?area=qujing&room=wuzhuang/northpool');
        exit;
    }
}

// 荷塘中(inpool)进入时重置八卦桥进度

// 雪山迷宫逻辑
if ($room && preg_match('/xueshan\\/snowmaze\\d$/', $fullRoomId)) {
    require_once DAEMON_PATH . 'SnowMazeHandler.php';
    $mazeResult = SnowMazeHandler::handleEnterRoom(intval($charId), $fullRoomId);
    if ($mazeResult) {
        // 迷宫完成 → 传送到指定房间
        if (!empty($mazeResult['message'])) {
            $_SESSION['flash_message'] = [
                'type' => 'success',
                'content' => $mazeResult['message'],
                'timestamp' => time(),
            ];
        }
        // 重定向到指定房间
        header('Location: room.php?area=qujing&room=xueshan/snow_maze_exit');
        exit;
    }
}

// ★ 海底莽林迷宫逻辑（sea/maze0~maze9）
// 还原原始LPC sea/maze 随机迷宫
if ($room && preg_match('/sea\\/maze\\d$/', $fullRoomId)) {
    require_once DAEMON_PATH . 'SeaMazeHandler.php';
    $mazeResult = SeaMazeHandler::handleEnterRoom(intval($charId), $fullRoomId);
    if ($mazeResult) {
        if (!empty($mazeResult['flash'])) {
            $_SESSION['flash_message'] = [
                'type' => 'info',
                'content' => $mazeResult['flash'],
                'timestamp' => time(),
            ];
        }
        if (!empty($mazeResult['redirect'])) {
            header('Location: ' . $mazeResult['redirect']);
            exit;
        }
    }
    // ★ 为迷宫房间动态生成虚拟出口，解决方向显示"未知"的问题
    // 数据库中 maze1~maze9 的出口 target_room 指向不存在的 sea/maze，导致显示"未知"
    $seaMazeExits = SeaMazeHandler::getVirtualExits($fullRoomId);
    if ($seaMazeExits !== null) {
        $room['exits'] = $seaMazeExits;
    }
}

// ★ 海底迷宫逻辑（dntg/donghai/maze*）
// 处理进入海底迷宫房间（哈密顿路径追踪 + 小金鱼NPC对话标记）
if ($room && preg_match('/dntg\/donghai\/maze/', $fullRoomId)) {
    require_once DAEMON_PATH . 'DonghaiMazeHandler.php';
    $mazeResult = DonghaiMazeHandler::handleEnterRoom(intval($charId), $fullRoomId);
    if ($mazeResult) {
        if (!empty($mazeResult['flash'])) {
            $_SESSION['flash_message'] = [
                'type' => 'info',
                'content' => $mazeResult['flash'],
                'timestamp' => time(),
            ];
        }
        if (!empty($mazeResult['redirect'])) {
            header('Location: ' . $mazeResult['redirect']);
            exit;
        }
    }
    // ★ 为海底迷宫随机区房间（mazea~mazed）动态生成虚拟出口
    $donghaiMazeExits = DonghaiMazeHandler::getVirtualExits($fullRoomId);
    if ($donghaiMazeExits !== null) {
        $room['exits'] = $donghaiMazeExits;
    }
}

// ★ 火焰山迷宫逻辑（qujing/firemount/huoyan）
// 燃烧时：所有出口都指向自己（迷宫陷阱）
// 熄灭后：出口打开，可前往山外和山边
if ($room && $fullRoomId === 'qujing/firemount/huoyan') {
    require_once DAEMON_PATH . 'FiremountHandler.php';
    $firemountExits = FiremountHandler::getRoomExits($fullRoomId);
    if ($firemountExits !== null) {
        $room['exits'] = $firemountExits;
    }
}

// ★ 铁笼房间特殊处理（westway/tielong）
// 铁笼打开后，动态添加 out 出口指向山洞内
if ($room && $fullRoomId === 'westway/tielong') {
    require_once HELPER_PATH . 'TempStateHelper.php';
    $tielongOpen = TempStateHelper::get(intval($charId), 'shizhan_tielong_open');
    if (is_array($tielongOpen)) {
        $tielongOpen = !empty($tielongOpen['_value']);
    }
    if ($tielongOpen) {
        $room['exits'][] = [
            'direction' => 'out',
            'target_area' => 'westway',
            'target_room' => 'westway/lu1',
            'target_name' => '山洞内',
            'door_name' => '',
            'door_closed' => 0
        ];
    }
}

// 还原原始LPC: inpool.c init() 清除所有 wzgmaze 临时变量
if ($room && $fullRoomId === 'qujing/wuzhuang/inpool') {
    require_once DAEMON_PATH . 'WzgmazeHandler.php';
    WzgmazeHandler::resetMaze(intval($charId));
}

// 获取房间内其他在线玩家
$players = [];
if ($room) {
    $sql = "SELECT id, name, sleep_state, unconscious_state, daze_state FROM characters 
            WHERE current_room = ? AND online = 1 AND id != ?
            ORDER BY name";
    $players = Database::queryAll($sql, [$fullRoomId, intval($charId)]);
}

// 获取当前房间内妖魔鬼怪任务的目标
$yaoguaiList = [];
if ($room) {
    require_once __DIR__ . '/../daemons/MieyaoHandler.php';
    $yaoguaiList = MieyaoHandler::getRoomYaoguai($area, $fullRoomId);
}

// 获取当前房间内的尸体
$corpses = [];
$carriedCorpses = [];
if ($room) {
    $corpses = Corpse::getCorpsesInRoom($area, $fullRoomId);
    // 获取玩家携带的尸体
    $carriedCorpses = Corpse::getCarriedCorpses($charId);
}

// 大闹天宫任务检查
require_once DAEMON_PATH . 'DntgQuestHandler.php';
$dntgResult = null;
try {
    $dntgResult = DntgQuestHandler::checkAndTrigger($charId, $fullRoomId);
} catch (\Exception $e) {
    error_log("大闹天宫任务检查失败: " . $e->getMessage());
}

// 瀑布前进入剧情（花果山猴子发现瀑布）
require_once DAEMON_PATH . 'PubuIntroHandler.php';
PubuIntroHandler::checkAndPlay($charId, $fullRoomId);

// 容错：如果URL 参数中的 area 不正确导致找不到房间，尝试 fallback 查找
if (!$room) {
    $fallbackRoom = null;
    if (strpos($roomId, '/') === false) {
        // roomId 只是房间名（如 zhongnan），尝试 LIKE 匹配
        $fallbackRoom = Database::queryOne(
            "SELECT * FROM rooms WHERE room_id LIKE ? LIMIT 1",
            ['%/' . $roomId]
        );
    } else {
        // roomId 已包含路径（如 misc/kantai），尝试精确匹配 room_id
        $fallbackRoom = Database::queryOne(
            "SELECT * FROM rooms WHERE room_id = ? LIMIT 1",
            [$roomId]
        );
    }
    if ($fallbackRoom) {
        $area = $fallbackRoom['area'];
        $fullRoomId = $fallbackRoom['room_id'];
        $room = RoomModel::getFullInfo($area, $fullRoomId);
        // 更新角色位置为正确的区域和房间
        $cleanArea = preg_replace('/^d\//', '', $area);
        CharacterModel::updatePosition($charId, $cleanArea, $fullRoomId);
        $char = CharacterModel::getFullInfo($charId);
    }
}

// 初始化最新消息变量（room.php 只显示最新一条，其他页面去 chat.php 查看全部消息）
// 注意：不要覆盖前面状态检查（监禁/欢迎室/公堂）设置的消息
if (!isset($latestMessage)) {
    $latestMessage = '';
}

// 处理 URL 参数中的 msg（来自 action.php 的重定向，保留到最后使用）
$urlMessage = '';
if (isset($_GET['msg']) && !empty($_GET['msg'])) {
    $urlMessage = urldecode($_GET['msg']);
}

// 获取当前角色的最后一条消息ID，用于轮询时避免显示历史消息
// 优先使用session中存储的命令消息ID（确保刚执行的命令能显示）
try {
    if (isset($_SESSION['last_ask_message_id']) && $_SESSION['last_ask_message_id'] > 0) {
        
        // 使用ask命令保存的消息ID（减1以包含此消息）
        $lastMessageId = $_SESSION['last_ask_message_id'] - 1;
        
        // 清除session，避免下次页面加载重复使用
        unset($_SESSION['last_ask_message_id']);
    } elseif (isset($_SESSION['last_move_message_id']) && $_SESSION['last_move_message_id'] > 0) {
        
        // 使用移动命令保存的消息ID（减1以包含此消息）
        $lastMessageId = $_SESSION['last_move_message_id'] - 1;

        // 清除session，避免下次页面加载重复使用
        unset($_SESSION['last_move_message_id']);
    } else {

        // 正常情况：查询数据库中最新的消息ID
        $lastMsgRow = Database::queryOne(
            'SELECT MAX(id) as max_id FROM message_queue WHERE char_id = ?',
            [$charId]
        );
        $lastMessageId = intval($lastMsgRow['max_id'] ?? 0);
    }
} catch (Exception $e) {
    $lastMessageId = 0;
}

// 如果房间不存在，显示错误提示并跳转到默认房间
if (!$room) {
    $latestMessage = HTML_HIYEL . '你请求前往的位置不存在' . HTML_NOR;
    
    // 使用默认房间信息
    $room = RoomModel::getFullInfo('city', 'city/kezhan');
    if (!$room) {
        // 兜底：直接从数据库里找任意有效房间作为最后保底
        $anyRoom = Database::queryOne(
            "SELECT area, room_id FROM rooms WHERE area != 'wiz' ORDER BY id ASC LIMIT 1"
        );
        if ($anyRoom) {
            $room = RoomModel::getFullInfo($anyRoom['area'], $anyRoom['room_id']);
            if ($room) {
                CharacterModel::updatePosition($charId, $anyRoom['area'], $anyRoom['room_id']);
                $char = CharacterModel::getFullInfo($charId);
            }
        }
    }
    if (!$room) {
        die('系统错误：无法加载房间数据！');
    }
    
    // 修复：更新角色位置到默认房间，避免下次访问时仍然指向无效位置
    CharacterModel::updatePosition($charId, 'city', 'city/kezhan');
    $char = CharacterModel::getFullInfo($charId);
}

// 获取待显示的消息（来自session，只显示最新一条）
$flashMessage = $_SESSION['flash_message'] ?? null;
if ($flashMessage && time() - ($flashMessage['timestamp'] ?? 0) < 10) {
    $latestMessage = $flashMessage['content'];
}
// 无论是否显示，都要清除 flash_message，避免刷新页面后重复显示
unset($_SESSION['flash_message']);

// 显示黄风捕获消息（Miscd::randomCapture 产生的消息）
// 优先覆盖其他消息，确保玩家看到自己被捕获的提示
$captureMessage = $_SESSION["capture_message_{$charId}"] ?? null;
if ($captureMessage) {
    $latestMessage = $captureMessage;
    unset($_SESSION["capture_message_{$charId}"]);
}

// 显示切磋结果广播消息（让玩家自己也能看到，只显示最新一条）
$fightBroadcast = $_SESSION['fight_result_broadcast'] ?? null;
if ($fightBroadcast && time() - ($fightBroadcast['timestamp'] ?? 0) < 10) {
    $latestMessage = $fightBroadcast['message'];
}
// 无论是否显示，都要清除，避免刷新页面后重复显示
unset($_SESSION['fight_result_broadcast']);

// 显示战斗开始广播消息（让玩家自己也能看到房间角落的消息）
$fightStartBroadcast = $_SESSION['fight_start_broadcast'] ?? null;
if ($fightStartBroadcast && time() - ($fightStartBroadcast['timestamp'] ?? 0) < 10) {
    // 如果还没有其他消息，显示战斗开始广播消息
    if (empty($latestMessage)) {
        $latestMessage = $fightStartBroadcast['message'];
    }
}
// 无论是否显示，都要清除，避免刷新页面后重复显示
unset($_SESSION['fight_start_broadcast']);

// 显示NPC主动攻击消息（进入房间时被NPC攻击的详细提示）
$autoAttackFlash = $_SESSION['auto_attack_flash'] ?? null;
if ($autoAttackFlash && time() - ($autoAttackFlash['timestamp'] ?? 0) < 10) {
    // NPC主动攻击消息优先显示（覆盖房间名flash）
    $latestMessage = $autoAttackFlash['message'];
}
// 无论是否显示，都要清除，避免刷新页面后重复显示
unset($_SESSION['auto_attack_flash']);

// 检查是否需要广播天气变化（每小时整点，仅户外房间）
$isOutdoors = RoomModel::isOutdoors($area, $fullRoomId);
if ($isOutdoors && NatureDaemon::shouldBroadcastWeatherChange()) {
    // 检查是否已经在当前小时广播过（使用session记录）
    $lastWeatherBroadcast = $_SESSION['last_weather_broadcast'] ?? 0;
    $currentHour = intval(date('H'));
    if ($lastWeatherBroadcast != $currentHour) {
        // 广播天气变化到房间内其他玩家（不添加到当前页面的 $messages）
        $weatherMsg = NatureDaemon::getWeatherChangeMessage();

        // 记录广播时间
        $_SESSION['last_weather_broadcast'] = $currentHour;

        // 广播到房间内所有玩家（包括自己，通过 AJAX 轮询获取）
        require_once DAEMON_PATH . 'MessageDaemon.php';
        MessageDaemon::broadcastToRoom($fullRoomId, $weatherMsg, $charId);

        // 天气消息 - 队列给自己
        MessageDaemon::queueMessageToSelf(intval($charId), $weatherMsg, 'self_event');

        // 同时在当前页面显示天气变化消息
        if (empty($latestMessage)) {
            $latestMessage = $weatherMsg;
        }
    }
}
// 地府鬼魂声音定时广播（阴阳界房间，使用JavaScript实现音效效果）
$enableGhostVoice = ($fullRoomId === 'death/gate') ? true : false;

// 检查当前房间是否有留言板
$roomBoard = null;
try {
    // 数据库中的location可能是'/d/city/kezhan' 或'city/kezhan'格式，需要同时匹配两种格式
    // 需要同时匹配两种格式
    $roomBoard = Database::queryOne(
        "SELECT board_id, name FROM boards 
         WHERE (location = ? OR location = ?) AND is_active = 1 
         LIMIT 1",
        [$fullRoomId, '/d/' . $fullRoomId]
    );
} catch (Exception $e) {
    // 忽略错误
}

// 处理飞行逻辑（从 fly.php 跳回来）
if (isset($_GET['do_fly']) && $_GET['do_fly'] == '1') {
    $flyData = $_SESSION['fly_takeoff_msg'] ?? null;
    if ($flyData && time() - ($flyData['timestamp'] ?? 0) < 10) {

        // 显示起飞消息（room.php 只显示最新一条）
        $latestMessage = $flyData['content'];

        // 广播起飞消息给房间内其他玩家
        MessageDaemon::broadcastToRoom(
            $fullRoomId,
            $flyData['content'],
            intval($charId)
        );

        // 飞行起飞 - 队列给自己（chat.php 轮询）
        MessageDaemon::queueMessageToSelf(intval($charId), $flyData['content'], 'self_event');

        // 更新角色位置到目标房间
        // 注意：flyData['target_room'] 已经是完整路径（如：changan/aolaiws）
        Database::execute(
            "UPDATE characters SET current_area = ?, current_room = ? WHERE id = ?",
            [$flyData['target_area'], $flyData['target_room'], $charId]
        );
        // 生成到达消息（先按门派判断，无匹配则按种族降级）
        $familyName = $char['family_name'] ?? '';
        $race = $char['race'] ?? '';
        $gender = $char['gender'] ?? '';
        $level = $char['level'] ?? 0;

        $familyArrivalMap = [
            '灵台方寸山' => '只见七彩祥云缓缓降落，{name}从祥云中走了出来。',
            '花果山水帘洞' => '只见狂风骤起又骤停，{name}从风中走了出来。',
            '东海龙宫' => '只见一道水柱落下化为细雨，{name}从水雾中走了出来。',
            '南海普陀山' => '只见白莲花瓣纷纷飘落，{name}从佛光中走了出来。',
            '月宫' => '只见月华流转银辉洒落，{name}踏月而下。',
            '五庄观' => '只见清风阵阵仙气飘飘，{name}从风中走了出来。',
            '阎罗地府' => '只见平地吹起一阵阴风，{name}从里面走了出来。',
            '将军府' => '只见金光闪耀从天而降，{name}稳稳落地。',
            '火云洞' => '只见火光一闪从天而落，{name}从火焰中走了出来。',
            '大雪山' => '只见狂风大作，{name}从里面走了出来。',
        ];

        if (isset($familyArrivalMap[$familyName])) {
            $arrivalDesc = $familyArrivalMap[$familyName];
            $arrivalMsg = "\n\n到了！你按下云头跳了下来。\n" . str_replace('{name}', '你', $arrivalDesc);
            $broadcastMsg = HTML_HIYEL . str_replace('{name}', $char['name'], $arrivalDesc) . HTML_NOR;
        } elseif ($race === 'mo' || $race === 'yao') {
            $arrivalMsg = "\n\n黑风骤停，你从黑风中出身。\n只见一股黑风散去，你从里面走了出来。";
            $broadcastMsg = HTML_HIYEL . "黑风骤停，{$char['name']}从黑风中出身。\n只见一股黑风散去，{$char['name']}从里面走了出来。" . HTML_NOR;
        } elseif ($race === 'xian' || $race === 'shen') {
            $arrivalMsg = "\n\n祥光收敛，你从云中缓缓降下。\n只见祥光收敛，你从云中缓缓落下。";
            $broadcastMsg = HTML_HICYN . "祥光收敛，{$char['name']}从云中缓缓降下。\n只见祥光收敛，{$char['name']}从云中缓缓落下。" . HTML_NOR;
        } elseif ($race === 'fo' || $race === 'ni') {
            $arrivalMsg = "\n\n金光散去，莲花消散于无形。\n只见金光收敛，一朵莲花消散，你出现在原地。";
            $broadcastMsg = HTML_HIYEL . "金光散去，莲花消散于无形。\n只见金光收敛，一朵莲花消散，{$char['name']}出现在原地。" . HTML_NOR;
        } elseif ($race === 'gui' || $race === 'hun') {
            $arrivalMsg = "\n\n青烟凝聚，你的身影渐渐显现。\n只见青烟凝聚，幽幽鬼火闪烁，你的身影渐渐显现。";
            $broadcastMsg = HTML_HIMAG . "青烟凝聚，{$char['name']}的身影渐渐显现。\n只见青烟凝聚，幽幽鬼火闪烁，{$char['name']}的身影渐渐显现。" . HTML_NOR;
        } elseif ($level >= 50) {
            $arrivalMsg = "\n\n长虹贯日，你稳稳落地。\n只见一道长虹从天而降，你稳稳落地，气势非凡。";
            $broadcastMsg = HTML_HIRED . "长虹贯日，{$char['name']}稳稳落地。\n只见一道长虹从天而降，{$char['name']}稳稳落地，气势非凡。" . HTML_NOR;
        } elseif ($gender === 'female') {
            $arrivalMsg = "\n\n白云轻散，你如仙子般轻盈落下。\n只见白云轻散，你如仙子般轻盈落下，衣袂飘飘。";
            $broadcastMsg = HTML_HICYN . "白云轻散，{$char['name']}如仙子般轻盈落下。\n只见白云轻散，{$char['name']}如仙子般轻盈落下，衣袂飘飘。" . HTML_NOR;
        } else {
            $arrivalMsg = "\n\n到了！你按下云头跳了下来。\n只见半空中降下一朵云彩，你从里面走了出来。";
            $broadcastMsg = HTML_HIYEL . "到了！{$char['name']}按下云头跳了下来。\n只见半空中降下一朵云彩，{$char['name']}从里面走了出来。" . HTML_NOR;
        }
        
        // 广播到达消息给目标房间的其他玩家
        MessageDaemon::broadcastToRoom(
            $flyData['target_room'],
            $broadcastMsg,
            intval($charId)
        );

        // 飞行到达 - 队列给自己
        MessageDaemon::queueMessageToSelf(intval($charId), $broadcastMsg, 'self_event');

        // 设置到达消息flash message
        $_SESSION['flash_message'] = [
            'content' => $arrivalMsg,
            'timestamp' => time()
        ];

        // 清除飞行数据
        unset($_SESSION['fly_takeoff_msg']);

        // 落地消息已移除（原 fly_landing session 创建）

        // 在页面底部添加自动跳转逻辑（1.5秒后跳转到目标房间）
        $targetAreaClean = preg_replace('/^d\//', '', $flyData['target_area']);
        $targetUrl = room_url($targetAreaClean, $flyData['target_room']);

        // 将跳转URL存储到session，在页面渲染后通过JavaScript跳转
        $_SESSION['fly_redirect'] = [
            'url' => $targetUrl,
            'delay' => 1500  // 1.5秒
        ];
    }
}

// 检测是否进入南城客栈，触发店小二欢迎消息
if ($fullRoomId === 'city/kezhan' || $roomId === 'city/kezhan') {
    // 检查是否有进店标记（避免重复显示）
    $lastEnterKezhan = $_SESSION['last_enter_kezhan'] ?? 0;
    $currentTime = time();

    // 如果距离上次进入超过60秒，则显示欢迎消息
    if ($currentTime - $lastEnterKezhan > 60) {

        // 获取角色信息
        $char = CharacterModel::find($charId);
        if ($char) {

            // 使用 RankHelper 获取对玩家的尊称
            require_once __DIR__ . '/../helpers/RankHelper.php';
            $title = RankHelper::queryRespect($char);

            // 检查是否在变化状态，获取变化后的名称
            $displayName = $char['name'];
            if (function_exists('get_transform_state_from_db')) {
                $transformState = get_transform_state_from_db($charId);
                if ($transformState && isset($transformState['target_name'])) {
                    $displayName = $transformState['target_name'];
                }
            }
            // 店小二欢迎消息，同时显示在 room.php 和 chat.php
            $xiaoerMessage = "\n店小二笑眯眯地说道：{$displayName}，这位{$title}，进来歇歇脚，喝两盅吧。";

            // 添加到room.php 的最新消息显示
            if (empty($latestMessage)) {
                $latestMessage = $xiaoerMessage;
            }
            // 同时广播到消息队列，让chat.php 也能显示
            require_once DAEMON_PATH . 'MessageDaemon.php';
            MessageDaemon::sendRoomMessage($charId, HTML_HIGRN . '店小二' . HTML_NOR . '笑眯眯说道：' . $displayName . '，这位' . $title . '，进来歇歇脚，喝两盅吧。', 'npc_dialog');
        }

        // 更新进店时间标记
        $_SESSION['last_enter_kezhan'] = $currentTime;
    }
}
// 消息统一到 chat.php 展示，room.php 不再消费 message_queue
// 将NPC的general话题和chat_msg广播到房间（用于chat.php显示消息）
if (!empty($room['npcs']) && $charId) {
    require_once DAEMON_PATH . 'MessageDaemon.php';

    // 检查是否已经广播过（避免每次刷新都广播）
    $sessionKey = 'npc_broadcasted_' . $roomId;
    if (!isset($_SESSION[$sessionKey]) || $_SESSION[$sessionKey] !== true) {
        foreach ($room['npcs'] as $npc) {

            // 1. 添加 inquiry 中的 general 话题
            if (!empty($npc['inquiry'])) {
                $inquiryData = json_decode($npc['inquiry'], true);
                if (!empty($inquiryData['general'])) {
                    $messages[] = addslashes(h($npc['name']) . '：' . h($inquiryData['general']));
                }
            }
            // 2. 添加 chat_msg 字段的消息
            if (!empty($npc['chat_msg'])) {
                $chatMsgData = json_decode($npc['chat_msg'], true);
                if (is_array($chatMsgData)) {
                    foreach ($chatMsgData as $msg) {
                        if (is_string($msg)) {
                            // 简单字符串消息
                            $messages[] = addslashes(h($msg));
                        } elseif (is_array($msg) && isset($msg['type']) && $msg['type'] === 'action') {
                            // 动作行为消息，带特殊标记
                            $messages[] = addslashes('[ACTION:' . h($msg['method']) . ':' . h($msg['description']) . ']');
                        }
                    }
                }
            }
        }
        // 标记已广播
        $_SESSION[$sessionKey] = true;
    }
}
// 定期清理旧消息（1%概率触发，避免频繁清理）
if (rand(1, 100) === 1) {
    MessageDaemon::cleanOldMessages(7); // 清理7天前的已读消息
    MessageDaemon::limitMessageQueue(300); // 限制消息队列总数不超过300
}

// 检查是否在战斗中
$inCombat = CombatDaemon::isInCombat($charId);
$combatStatus = null;
if ($inCombat) {
    $combatStatus = CombatDaemon::getCombatStatus($charId);

    // PVP: 检测对手是否已投降/逃跑（防止从 fight.php 跳回 room.php 后死循环）
    // 逻辑与 chat.php poll 保持一致
    if ($combatStatus && ($combatStatus['target_type'] ?? '') === 'player' && !empty($combatStatus['target_id'])) {
        $oppStillFighting = Database::queryOne(
            "SELECT id FROM active_combats WHERE char_id = ? AND target_id = ? LIMIT 1",
            [intval($combatStatus['target_id']), $charId]
        );
        if (!$oppStillFighting) {
            // 对手已经投降/逃跑，我方战斗也应该结束
            CombatDaemon::endCombat($charId);
            $inCombat = false;
            $combatStatus = null;
        }
    }

    // 如果战斗仍然有效，处理 NPC 攻击
    if ($inCombat) {
        // === 服务端驱动NPC攻击 ===
        // 即使在房间页面，NPC也会按照自己的heart_beat自动攻击
        // 还原LPC heart_beat机制
        $npcAttackResult = CombatDaemon::processPendingNpcAttacks($charId);
        if ($npcAttackResult['attacks'] > 0 && !empty($npcAttackResult['messages'])) {
            // 将NPC攻击消息添加到消息队列
            require_once DAEMON_PATH . 'MessageDaemon.php';
            $msgLines = array_filter(explode("\n", trim($npcAttackResult['messages'])));
            foreach ($msgLines as $line) {
                if (!empty($line)) {
                    MessageDaemon::queueMessageToSelf($charId, $line, 'combat');
                }
            }

            // 如果NPC击杀了玩家，结束战斗
            if ($npcAttackResult['killed']) {
                $inCombat = false;
                $combatStatus = null;
            }
        }
    }
}



?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="keywords" content="西游记mud,西游记文字mud，西游记h5">
    <meta name="description" content="WAP西游记2012，源自Mud西游记2012的经典还原H5网页文字游戏">
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css" id="theme-link">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
    <link rel="stylesheet" href="../assets/css/modal.css">
    <link rel="stylesheet" href="../assets/css/room.css">
    <title><?= h($room['name']) ?>_WAP西游记2012</title>

    <script>var xyjCharId = <?= $charId ?>;</script>
    <script src="../assets/js/room.js"></script>
    <script>
    // 白虎岭迷宫特殊处理：检查是否有迷宫HTML需要显示
    <?php if (isset($_SESSION['baihuling_maze_html'])): ?>
    (function() {
        var mazeHtml = <?php echo json_encode($_SESSION['baihuling_maze_html']); ?>;
        var mazeFlashMsg = <?php echo json_encode($latestMessage ?? ''); ?>;
        <?php unset($_SESSION['baihuling_maze_html']); ?>
        
        window.addEventListener('DOMContentLoaded', function() {
            var roomContent = document.querySelector('.room-content');
            if (roomContent && mazeHtml) {
                roomContent.innerHTML = mazeHtml;
                if (mazeFlashMsg) {
                    var msgLog = document.getElementById('room-msg-log');
                    if (msgLog) {
                        var div = document.createElement('div');
                        div.className = 'room-msg';
                        div.innerHTML = mazeFlashMsg;
                        msgLog.appendChild(div);
                        msgLog.scrollTop = msgLog.scrollHeight;
                    }
                }
            }
        });
    })();
    <?php endif; ?>

    function inviteSleep(playerName) {
        hideSleepInviteDialog();
        const area = '<?= addslashes($room['area']) ?>';
        const room = '<?= addslashes($room['room_id']) ?>';
        executeAction('sleep', area, room, playerName);
    }
    </script>
<?php if (in_array($room['room_id'], ['changan/eastseashore', 'changan/mufa', 'changan/aolaiws'])): ?>
    <script>
    // ===== 木筏状态：前端本地计算 + 定时同步 =====
    // 用纯 JS 计算进度，不使用 SSE 长连接，避免耗尽 PHP 进程
    (function() {
        var mufaRoom = <?= json_encode($room['room_id']) ?>;

        var state = {
            status: 'at_shore',
            triggerTime: 0,
            serverTimeOffset: 0
        };

        var timeline = {
            'at_shore':      { next: 'sailing_away',  trigger: 0,  duration: 15 },
            'sailing_away':  { next: 'at_dest',       trigger: 15, duration: 20 },
            'at_dest':       { next: 'sailing_back',  trigger: 35, duration: 20 },
            'sailing_back':  { next: 'at_shore',      trigger: 55, duration: 10 }
        };

        var messages = {
            'changan/eastseashore': {
                'at_shore→sailing_away': '一阵浪头打来，木筏缓缓漂去...',
                'sailing_back→at_shore': '一只木筏缓缓漂回岸边。'
            },
            'changan/mufa': {
                'at_shore→sailing_away': '周围是白茫茫一片大海，你已经远离任何陆地的视线...',
                'sailing_away→at_dest': '木筏一沉，搁浅了。忽然竟是登陆之处，赶紧上去罢。',
                'at_dest→sailing_back': '一阵浪头打来，木筏缓缓漂去...'
            },
            'changan/aolaiws': {
                'sailing_away→at_dest': '木筏已经靠岸，可以上船了。',
                'at_dest→sailing_back': '木筏缓缓离开岸边，向大海深处漂去...'
            }
        };

        function calcNow() {
            return Math.floor(Date.now() / 1000) + state.serverTimeOffset;
        }

        function calcState() {
            var now = calcNow();
            var elapsed = now - state.triggerTime;
            if (elapsed >= 65) { elapsed = elapsed % 65; }

            var status = 'at_shore';
            var keys = Object.keys(timeline);
            for (var i = 0; i < keys.length; i++) {
                var key = keys[i];
                if (elapsed >= timeline[key].trigger) {
                    status = key;
                } else {
                    break;
                }
            }

            var info = timeline[status];
            var phaseElapsed = elapsed - info.trigger;
            var remaining = Math.max(0, info.duration - phaseElapsed);
            var progressPct = info.duration > 0 ? Math.min(100, (phaseElapsed / info.duration) * 100) : 100;

            return {
                status: status,
                elapsed: elapsed,
                remaining: remaining,
                progress_pct: Math.round(progressPct * 10) / 10
            };
        }

        function updateProgress(s) {
            var progressEl = document.getElementById('mufa-progress');
            if (progressEl) {
                progressEl.style.width = s.progress_pct + '%';
            }
        }

        function showTransitionMessage(from, to) {
            var key = from + '→' + to;
            var msg = messages[mufaRoom] && messages[mufaRoom][key];
            if (!msg) return;

            var msgLog = document.getElementById('room-msg-log');
            if (!msgLog) return;

            var div = document.createElement('div');
            div.className = 'room-msg';
            div.innerHTML = '<span style="color:#f1c40f;">' + msg + '</span>';
            msgLog.appendChild(div);
            msgLog.scrollTop = msgLog.scrollHeight;
        }

        var lastStatus = null;

        function tick() {
            if (!state.triggerTime) return;
            var s = calcState();
            updateProgress(s);

            if (s.status !== lastStatus) {
                if (lastStatus) {
                    showTransitionMessage(lastStatus, s.status);
                }
                lastStatus = s.status;
            }
        }

        function syncState() {
            fetch('../api/mufa_state.php?room=' + encodeURIComponent(mufaRoom), {
                headers: { 'Accept': 'application/json' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.trigger_time) {
                    var oldStatus = state.status;
                    state.triggerTime = data.trigger_time;
                    state.serverTimeOffset = data.server_time - Math.floor(Date.now() / 1000);

                    var s = calcState();
                    state.status = s.status;
                    lastStatus = s.status;
                    updateProgress(s);
                }
            })
            .catch(function() {});
        }

        window.addEventListener('DOMContentLoaded', function() {
            var roomMsgLog = document.getElementById('room-msg-log');
            if (roomMsgLog) {
                var progressBar = document.createElement('div');
                progressBar.id = 'mufa-progress-bar';
                progressBar.style.cssText = 'margin:6px 0;height:3px;background:rgba(255,255,255,0.1);border-radius:2px;overflow:hidden;';
                progressBar.innerHTML = '<div id="mufa-progress" style="height:100%;width:0%;background:linear-gradient(90deg,#f1c40f,#e67e22);transition:width 1s linear;border-radius:2px;"></div>';
                roomMsgLog.parentNode.insertBefore(progressBar, roomMsgLog);
            }

            syncState();
            setInterval(tick, 1000);
            setInterval(syncState, 30000);
        });
    })();
    </script>
<?php endif; ?>
</head>
<body>
<a href="javascript:location.reload();">【<?php echo HTML_HICYN . h($room['name']) . HTML_NOR ?>】</a>&ensp;
<a href="map.php">地图</a>&ensp;
<a href="fly.php">飞行</a>&ensp;
<a href="quests.php">任务</a>
<hr>

<?php 
// 如果是户外房间，显示环境描述（参考原始项目 look.cmd 的实现）
// 注意：环境描述独立于房间描述显示，确保每次都能显示
if ($isOutdoors):
    $envDesc = NatureDaemon::getEnvironmentDescription(true);
    if (!empty($envDesc)):
?>
<div style="margin: 5px 0; color: #888; font-style: italic;">
[窗外]<?= h($envDesc) ?>
</div>
<?php 
    endif;
endif;
?>

<div class="room-content">
<?php if ($room['description']): ?>
<div class="room-description" id="setting-room-desc">
<?= nl2br($room['description']) ?>
</div>
<?php endif; ?>

<?php
// 死牢氛围文本：还原原始LPC punish.c 的牢房对话（hehe() 机制）
// 被监禁的玩家在 prison 房间会看到牢房氛围描述
if (($fullRoomId === 'wiz/prison' || $roomId === 'prison') && isset($_SESSION['imprisoned']) && $_SESSION['imprisoned']):
?>
<div style="margin: 10px 0; padding: 10px; background: rgba(139, 0, 0, 0.15); border: 1px solid rgba(139, 0, 0, 0.3); border-radius: 4px;">
    <div style="color: #8B0000; font-size: 0.9em; line-height: 1.8;">
        <p style="margin: 3px 0;">&#128481; 死牢深处传来阵阵皮鞭抽打的声音...</p>
        <p style="margin: 3px 0;">&#128481; 隔壁牢房隐约传来犯人的惨叫声，令人不寒而栗。</p>
        <p style="margin: 3px 0;">&#128481; 冰冷的铁链拖在地上，发出刺耳的摩擦声。</p>
        <p style="margin: 3px 0;">&#128481; 一阵阴风吹过，烛火摇曳，墙壁上的影子仿佛在晃动。</p>
        <p style="margin: 3px 0;">&#128481; 远处传来狱卒的脚步声，由远及近，又渐渐远去...</p>
        <p style="margin: 3px 0;">&#128481; 几只老鼠从墙角窜过，吱吱叫着消失在黑暗中。</p>
        <p style="margin: 3px 0;">&#128481; 你蜷缩在阴暗潮湿的角落里，不知何时才能重见天日。</p>
    </div>
</div>
<?php endif; ?>

<?php
// 蓬莱青石崖琼草状态显示（还原原始LPC: qiongcao.c 的生长阶段描述）
if ($fullRoomId === 'penglai/yashang') {
    require_once DAEMON_PATH . 'QiongcaoHandler.php';
    $qiongcaoHandler = new QiongcaoHandler();
    $growthInfo = $qiongcaoHandler->getGrowthInfo('penglai/yashang');
    
    if ($growthInfo['stage'] > 0) {
        echo '<div style="margin: 8px 0; padding: 6px; background: rgba(200, 100, 255, 0.1); border: 1px solid rgba(200, 100, 255, 0.3); border-radius: 4px;">';
        if ($growthInfo['mature']) {
            echo HTML_HIMAG . '崖壁上有一株' . $growthInfo['stage_name'] . '在微微发光，似乎已经成熟可以采摘了！' . HTML_NOR;
        } else {
            echo HTML_HICYN . '崖壁上有一株' . $growthInfo['stage_name'] . '在微微发光。' . HTML_NOR;
            echo '<br>' . HTML_CYN . '（还在生长中...）' . HTML_NOR;
        }
        echo '</div>';
    }
}
?>

<?php
// 人参果园事件状态显示
if ($fullRoomId === 'qujing/wuzhuang/renshenguo-yuan') {
    require_once DAEMON_PATH . 'RenshenEventHandler.php';
    $rsPhase = RenshenEventHandler::getCurrentPhase();
    if ($rsPhase !== 'idle' && $rsPhase !== 'cooldown') {
        $eventText = '';
        switch ($rsPhase) {
            case 'announcing':
                $state = RenshenEventHandler::getState();
                $remaining = RenshenEventHandler::PHASE1_DELAY - (time() - $state['event_started_at']);
                $eventText = HTML_HICYN . '镇元大仙笑吟吟地站在那里，说道：「稍等片刻，果子马上就好。」' . HTML_NOR
                    . '<br>' . HTML_CYN . '（' . max(0, $remaining) . '秒后进入下一阶段）' . HTML_NOR;
                // 首次进入 announcing 阶段时写入 chat 消息
                if (empty($_SESSION['renshen_announced'])) {
                    $_SESSION['renshen_announced'] = true;
                    MessageDaemon::queueMessageToSelf(intval($charId),
                        HTML_HIMAG . '【人参果会】' . HTML_NOR . HTML_HIYEL . '五庄观人参果品尝会就要开始了！' . HTML_NOR,
                        'self_event');
                }
                break;
            case 'phase1':
                $state = RenshenEventHandler::getState();
                $remaining = (RenshenEventHandler::PHASE1_DELAY + RenshenEventHandler::PHASE2_DELAY) - (time() - $state['event_started_at']);
                $eventText = HTML_HICYN . '镇元大仙拈须微笑，似乎在等待果子成熟。' . HTML_NOR
                    . '<br>' . HTML_CYN . '（' . max(0, $remaining) . '秒后可以领取人参果）' . HTML_NOR;
                // 首次进入 phase1 时写入 chat 消息
                if (empty($_SESSION['renshen_phase1_notified'])) {
                    $_SESSION['renshen_phase1_notified'] = true;
                    MessageDaemon::queueMessageToSelf(intval($charId),
                        HTML_HIMAG . '【人参果会】' . HTML_NOR . HTML_HIYEL . '五庄观人参果品尝会快要开始了！' . HTML_NOR . "\n"
                        . HTML_HICYN . '镇元大仙拈须微笑：「莫急，果子马上就好。」' . HTML_NOR,
                        'self_event');
                }
                break;
            case 'phase2':
                $eventText = HTML_HICYN . '镇元大仙笑道：「想吃人参果的，快来问老道吧！」' . HTML_NOR;
                // 首次进入 phase2 时写入 chat 消息
                if (empty($_SESSION['renshen_phase2_notified'])) {
                    $_SESSION['renshen_phase2_notified'] = true;
                    MessageDaemon::queueMessageToSelf(intval($charId),
                        HTML_HIMAG . '【人参果会】' . HTML_NOR . HTML_HIYEL . '五庄观人参果品尝会正式开始了！' . HTML_NOR . "\n"
                        . HTML_HICYN . '镇元大仙笑道：「好了好了，想吃的快来问老道要人参果吧！」' . HTML_NOR,
                        'self_event');
                }
                break;
            case 'finished':
                // 分发完毕后结束事件（原版: NPC 5秒后 destruct）
                RenshenEventHandler::endEvent();
                // 通过 chat 消息通知，不用事件状态框
                MessageDaemon::queueMessageToSelf(intval($charId),
                    HTML_HICYN . '镇元大仙微微一笑，化作一道清风，消失不见了。' . HTML_NOR,
                    'self_event');
                // 清除 session 标记
                unset($_SESSION['renshen_announced'], $_SESSION['renshen_phase1_notified'], $_SESSION['renshen_phase2_notified']);
                break;
        }
        if ($eventText) {
            echo '<div style="margin: 10px 0; padding: 8px; background: rgba(0, 255, 255, 0.1); border: 1px solid rgba(0, 255, 255, 0.3); border-radius: 4px;">';
            echo $eventText;
            echo '</div>';
        }
    }
    // 事件结束后清除 session 标记，以便下次事件重新通知
    if ($rsPhase === 'cooldown' || $rsPhase === 'idle') {
        unset($_SESSION['renshen_announced'], $_SESSION['renshen_phase1_notified'], $_SESSION['renshen_phase2_notified']);
    }
    // 检查冷却状态
    if ($rsPhase === 'cooldown') {
        $remaining = RenshenEventHandler::getCooldownRemaining();
        echo '<div style="margin: 10px 0; padding: 8px; background: rgba(255, 255, 0, 0.1); border: 1px solid rgba(255, 255, 0, 0.3); border-radius: 4px;">';
        echo HTML_HIYEL . '果园恢复了平静。（约' . ceil($remaining / 60) . '分钟后可再次开启）' . HTML_NOR;
        echo '</div>';
    }
}
?>

<!-- 房间消息 -->
<div id="room-msg-log">
<?php 
// 如果有URL消息（战斗结果等），优先显示
if (!empty($urlMessage)) {
    $latestMessage = $urlMessage;
}
if (!empty($latestMessage)): 
?>
<div class="room-msg"><?= $latestMessage ?></div>
<?php endif; ?>
</div>

<?php if ($inCombat && $combatStatus): ?>
<!-- 战斗提示信息 -->
<p style="color: #ff4444; font-weight: bold; animation: blink 1s infinite;">
你正在与 <?= h($combatStatus['target_name']) ?> 战斗中！ <a href="fight.php" style="color: #ffff00;">进入战斗</a>
</p>

<?php // 自动跳转到战斗页（让玩家能先看到攻击消息，然后进入战斗） ?>
<script>
setTimeout(function() {
    window.location.href = 'fight.php';
}, 2000);
</script>

<?php // 显示NPC的general话题和chat_msg（绿色，每隔15秒自动显示） ?>
<div id="npc-room-messages" style="min-height: 20px;"></div>

<script>
// NPC房间消息数据
const npcMessages = [
<?php 
if (!empty($room['npcs'])) {
    $messages = [];
    foreach ($room['npcs'] as $npc) {
        if (!empty($npc['inquiry'])) {
            $inquiryData = json_decode($npc['inquiry'], true);
            if (!empty($inquiryData['general'])) {
                $messages[] = addslashes(h($npc['name']) . '：' . h($inquiryData['general']));
            }
        }
        if (!empty($npc['chat_msg'])) {
            $chatMsgData = json_decode($npc['chat_msg'], true);
            if (is_array($chatMsgData)) {
                foreach ($chatMsgData as $msg) {
                    if (is_string($msg)) {
                        $messages[] = addslashes(h($msg));
                    } elseif (is_array($msg) && isset($msg['type']) && $msg['type'] === 'action') {
                        $messages[] = addslashes('[ACTION:' . h($msg['method']) . ':' . h($msg['description']) . ']');
                    }
                }
            }
        }
    }
    echo implode(",\n", array_map(function($m) { return "    '" . $m . "'"; }, $messages));
}
?>
];

function initNpcMessages() {
    if (npcMessages.length === 0) return;
    
    let currentMessageIndex = 0;
    
    function showNextMessage() {
        const container = document.getElementById('npc-room-messages');
        const message = npcMessages[currentMessageIndex];

        if (message.startsWith('[ACTION:')) {
            const match = message.match(/\[ACTION:(\w+):(.+)\]/);
            if (match) {
                const msgElement = document.createElement('div');
                msgElement.style.color = '#f39c12';
                msgElement.style.fontWeight = 'bold';
                msgElement.style.marginTop = '10px';
                msgElement.textContent = match[2];
                container.appendChild(msgElement);
            }
        } else {
            const msgElement = document.createElement('div');
            msgElement.style.color = '#27ae60';
            msgElement.style.fontWeight = 'bold';
            msgElement.style.marginTop = '10px';
            msgElement.textContent = message;
            container.appendChild(msgElement);
        }
        
        setTimeout(() => {
            const lastChild = container.lastElementChild;
            if (lastChild) {
                lastChild.style.transition = 'opacity 1s';
                lastChild.style.opacity = '0';
                setTimeout(() => {
                    lastChild.remove();
                }, 1000);
            }
        }, 3000);
        
        currentMessageIndex = Math.floor(Math.random() * npcMessages.length);
    }
    
    showNextMessage();
    setInterval(showNextMessage, 15000);
}

document.addEventListener('DOMContentLoaded', initNpcMessages);
</script>
<?php endif; ?>

<!-- 房间消息实时更新 -->
<script>
(function() {
    var lastRoomMessageId = <?= intval($lastMessageId ?? 0) ?>;
    var msgLog = document.getElementById('room-msg-log');
    var POLL_INTERVAL = 2000;
    var currentRoomId = '<?= addslashes($fullRoomId ?? '') ?>';
    var currentCharName = '<?= addslashes($char['name'] ?? '') ?>';
    var MAX_ROOM_MSGS = 3;

    var storageKey = 'lastDisplayedMessageId_room_<?= $charId ?>';
    var lastDisplayedMessageId = parseInt(localStorage.getItem(storageKey) || '0');

    var storedLastId = parseInt(localStorage.getItem('lastRoomMessageId_<?= $charId ?>') || '0');
    if (storedLastId > lastRoomMessageId) {
        lastRoomMessageId = storedLastId;
    }

    // 向消息日志追加一条消息
    function appendMessage(html, isPrivate) {
        if (!msgLog) return;
        var div = document.createElement('div');
        div.className = isPrivate ? 'room-msg-private' : 'room-msg';
        div.innerHTML = html;
        msgLog.appendChild(div);
        // 超过上限时移除最早的
        while (msgLog.children.length > MAX_ROOM_MSGS) {
            msgLog.removeChild(msgLog.firstChild);
        }
        // 自动滚动到底部
        msgLog.scrollTop = msgLog.scrollHeight;
        
        // 检测自动跳转标记
        var autoJumpEl = div.querySelector('[data-auto-jump]');
        if (autoJumpEl) {
            var jumpUrl = autoJumpEl.getAttribute('data-auto-jump');
            if (jumpUrl) {
                setTimeout(function() {
                    location.href = jumpUrl;
                }, 500); // 延迟500ms跳转，让用户能看到消息
            }
        }
    }

    function pollRoomMessages() {
        var url = 'talk.php?action=poll';
        if (lastRoomMessageId > 0) {
            url += '&last_id=' + lastRoomMessageId;
        }
        fetch(url)
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            console.log('[轮询] 返回数据:', data);
            if (data.success && data.messages && data.messages.length > 0) {

                // 过滤出房间相关消息
                var roomMessages = data.messages.filter(function(msg) {
                    if (msg.msg_type !== 'room' &&
                        msg.msg_type !== 'chat' &&
                        msg.msg_type !== 'npc_dialog' &&
                        msg.msg_type !== 'npc_action' &&
                        msg.msg_type !== 'npc_chat' &&
                        msg.msg_type !== 'combat' &&
                        msg.msg_type !== 'rumor' &&
                        msg.msg_type !== 'self_event' &&
                        msg.msg_type !== 'private' &&
                        msg.msg_type !== 'system' &&
                        msg.msg_type !== 'global') {
                        return false;
                    }
                    // 过滤自己的移动消息
                    if (currentCharName && msg.message) {
                        var escapedName = currentCharName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                        var movePattern = new RegExp(escapedName + '(从.+?离开|走了过来)');
                        if (movePattern.test(msg.message)) {
                            return false;
                        }
                    }
                    return true;
                });

                // 逐条追加所有消息
                var hasNew = false;
                var autoJumpUrl = null;
                roomMessages.forEach(function(msg) {
                    if (msg.id > lastDisplayedMessageId) {
                        var isPrivate = (msg.msg_type === 'private');
                        // 过滤求婚消息
                        if (isPrivate && msg.message && msg.message.includes('【姻缘】')) return;
                        appendMessage(msg.message, isPrivate);
                        hasNew = true;
                        
                        // 检测自动跳转标记
                        if (msg.message && msg.message.indexOf('data-auto-jump=') !== -1) {
                            var match = msg.message.match(/data-auto-jump="([^"]+)"/);
                            if (match && match[1]) {
                                autoJumpUrl = match[1];
                            }
                        }
                    }
                });
                
                // 如果有自动跳转，延迟后跳转
                if (autoJumpUrl) {
                    setTimeout(function() {
                        location.href = autoJumpUrl;
                    }, 1000);
                }
                
                if (hasNew) {
                    var lastMsg = roomMessages[roomMessages.length - 1];
                    lastDisplayedMessageId = lastMsg.id;
                    localStorage.setItem(storageKey, lastMsg.id);
                }
            }

            // 检测是否有已接受的切磋请求（自动跳转）
            if (data.fight_accepted) {
                console.log('[自动跳转] 检测到已接受的请求，对方ID:', data.fight_accepted);
                setTimeout(function() {
                    console.log('[自动跳转] 执行跳转');
                    location.href = 'action.php?action=fight&target=' + data.fight_accepted;
                }, 800);
                return;
            }

            // 检测是否被拉入战斗（PVP/PVE实时同步）→ 自动跳转 fight.php
            if (data.in_combat && !location.pathname.match(/\/fight\.php$/)) {
                console.log('[战斗同步] 检测到被拉入战斗，目标:', data.combat_target_name);
                setTimeout(function() {
                    location.href = 'fight.php';
                }, 800);
                return;
            }

            // 检测房间变化
            if (data.current_room && data.current_room !== currentRoomId) {
                setTimeout(function() {
                    var newUrl = 'room.php';
                    if (data.current_area) {
                        newUrl += '?area=' + encodeURIComponent(data.current_area);
                        if (data.current_room) {
                            newUrl += '&room=' + encodeURIComponent(data.current_room);
                        }
                    }
                    location.href = newUrl;
                }, 300);
                return;
            }

            if (data.last_id && data.last_id > lastRoomMessageId) {
                lastRoomMessageId = data.last_id;
                localStorage.setItem('lastRoomMessageId_<?= $charId ?>', lastRoomMessageId);
            }
        })
        .catch(function(err) {
            console.error('[Room Poll] 错误:', err);
        });
    }

    pollRoomMessages();
    setInterval(pollRoomMessages, POLL_INTERVAL);
})();
</script>

<?php if (!empty($room['exits'])): ?>
<br>
明显的方向有：
<?php
// 定义方向优先级顺序
$directionOrder = [
    'north' => 1,
    'east' => 2,
    'south' => 3,
    'west' => 4,
    'up' => 5,
    'right' => 6,
    'down' => 7,
    'left' => 8,
    'in' => 9,
    'out' => 10,
    'enter' => 11,
    'northeast' => 12,
    'northwest' => 13,
    'southeast' => 14,
    'southwest' => 15,

    // 复合方向命令
    'northup' => 16,
    'southup' => 17,
    'eastup' => 18,
    'westup' => 19,
    'northdown' => 20,
    'southdown' => 21,
    'eastdown' => 22,
    'westdown' => 23,
    'northeastup' => 24,
    'northwestup' => 25,
    'southeastup' => 26,
    'southwestup' => 27,
];
// 按优先级排序出口
$sortedExits = $room['exits'];
usort($sortedExits, function($a, $b) use ($directionOrder) {
    $orderA = $directionOrder[$a['direction']] ?? 99;
    $orderB = $directionOrder[$b['direction']] ?? 99;
    return $orderA - $orderB;
});

foreach ($sortedExits as $exit):
?>

<?php
// 人参果园事件状态更新（如果在此房间）
if ($fullRoomId === 'qujing/wuzhuang/renshenguo-yuan') {
    require_once DAEMON_PATH . 'RenshenEventHandler.php';
    $renshenPhase = RenshenEventHandler::getCurrentPhase();
    if ($renshenPhase !== 'idle' && $renshenPhase !== 'cooldown' && $renshenPhase !== 'finished') {
        $renshenEventActive = true;
    }
}

// 方向映射：英文->中文 + 箭头
$directionMap = [
    'north' => '北↑',
    'south' => '南↓',
    'east' => '东→',
    'west' => '西←',
    'northeast' => '东北↗',
    'northwest' => '西北↖',
    'southeast' => '东南↘',
    'southwest' => '西南↙',
    'up' => '上⇧',
    'down' => '下⇩',
    'in' => '进⇨',
    'out' => '出⇦',
    'right' => '右→',
    'left' => '左←',
    'enter' => '进⇨',

    // 复合方向命令
    'northup' => '北上',
    'southup' => '南上',
    'eastup' => '东上',
    'westup' => '西上',
    'northdown' => '北下',
    'southdown' => '南下',
    'eastdown' => '东下',
    'westdown' => '西下',
    'northeastup' => '东北上⬈',
    'northwestup' => '西北上⬉',
    'southeastup' => '东南上⬊',
    'southwestup' => '西南上⬋',
    'northeastdown' => '东北下⇗',
    'northwestdown' => '西北下⇖',
    'southeastdown' => '东南下⇘',
    'southwestdown' => '西南下⇙',
];
$dirText = $directionMap[$exit['direction']] ?? $exit['direction'];
$targetName = !empty($exit['target_name']) ? h($exit['target_name']) : '未知';

// 门关闭时出口方向灰色显示
$exitStyle = 'cursor:pointer;';
$doorHint = '';
$isExitDisabled = false;
$disabledMessage = '';
if (!empty($exit['door_name']) && !empty($exit['door_closed'])) {
    $exitStyle = 'cursor:not-allowed; color:#666; text-decoration:line-through;';
    $doorHint = ' <span style="color:#888; font-size:0.85em;">[' . h($exit['door_name']) . '关闭]</span>';
}

// 检查是否是禁用出口（来自special_events配置）
if (!$isExitDisabled && !empty($room['special_events'])) {
    $specialEvents = is_string($room['special_events']) 
        ? json_decode($room['special_events'], true) 
        : $room['special_events'];
    
    if (isset($specialEvents['disabled_exits'][$exit['direction']])) {
        $isExitDisabled = true;
        $exitStyle = 'cursor:not-allowed; color:#666; text-decoration:line-through;';
        $disabledConfig = $specialEvents['disabled_exits'][$exit['direction']];
        $disabledMessage = $disabledConfig['message'] ?? '';
        if ($disabledMessage) {
            $doorHint = ' <span style="color:#888; font-size:0.85em;">[' . h($disabledMessage) . ']</span>';
        }
    }
}
?>

<br>
<a href="javascript:void(0);" onclick="executeAction('go', '<?= addslashes($room['area']) ?>', '<?= addslashes($room['room_id']) ?>', '<?= addslashes($exit['direction']) ?>')" style="<?= $exitStyle ?>"><?= $dirText ?> - <?= $targetName ?></a>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($room['npcs']) || !empty($yaoguaiList) || !empty($players) || $renshenEventActive || $roomBoard): ?>
<br>
<br>
这里有：
<?php 
$itemsList = [];

foreach ($room['npcs'] as $npc) {
    $npcText = '<a href="' . npc_url($npc['id']) . '">' . h($npc['name']) . '</a>';
    if (!empty($npc['alias'])) {
        $npcText .= '(' . h($npc['alias']) . ')';
    }
    if (!empty($npc['equipped_weapon'])) {
        $npcText .= '<span style="color:#888;font-size:0.85em;">(手持' . h($npc['equipped_weapon']) . '，身披' . h($npc['equipped_armor'] ?? '') . ')</span>';
    }
    // 检查NPC睡眠/定身状态（回梦/迷魂效果）
    $npcState = Database::queryOne(
        "SELECT temp_key FROM npc_temp WHERE npc_id = ? AND temp_key IN ('sleep_state', 'daze_state') AND temp_value = '1' AND updated_at > ?",
        [$npc['id'], time()]
    );
    if ($npcState) {
        if ($npcState['temp_key'] === 'sleep_state') {
            $npcText .= '<span style="color:#999999;">&lt;睡梦中&gt;</span>';
        } else {
            $npcText .= '<span style="color:#AAAAAA;">&lt;发呆&gt;</span>';
        }
    }
    $itemsList[] = $npcText;
}

if ($renshenEventActive) {
    $itemsList[] = '<span style="color: #00FFFF; font-weight: bold;">镇元大仙</span>(五庄观观主)';
}

if ($area === 'moon' && $roomId === 'moon/ylt') {
    $hasYuexiaLaoren = false;
    foreach ($room['npcs'] as $npc) {
        if (strpos($npc['name'], '月下老人') !== false) {
            $hasYuexiaLaoren = true;
            break;
        }
    }
    if ($hasYuexiaLaoren) {
        $itemsList[] = '[<a href="marry.php" style="color: #ff69b4;">姻缘簿</a>]';
    }
}

foreach ($yaoguaiList as $yaoguai) {
    $itemsList[] = '<a href="yaoguai.php?id=' . intval($yaoguai['id']) . '" style="color: #ff0000; font-weight: bold; text-decoration: none;">' . h($yaoguai['npc_name']) . '</a>';
}

foreach ($players as $player) {
    $displayName = get_char_display_name($player);
    $sleepTag = (!empty($player['sleep_state']) && $player['sleep_state'] == 1) ? '<span style="color:#999999;">&lt;睡梦中&gt;</span>' : '';
    $unconsciousTag = (!empty($player['unconscious_state']) && $player['unconscious_state'] == 1) ? '<span style="color:#FF4444;">&lt;昏迷&gt;</span>' : '';
    $dazeTag = (!empty($player['daze_state']) && $player['daze_state'] == 1) ? '<span style="color:#AAAAAA;">&lt;发呆&gt;</span>' : '';
    $itemsList[] = '<a href="character.php?id=' . $player['id'] . ($fullRoomId === 'city/misc/kantai' ? '&from=kantai' : '') . '">' . h($displayName) . '</a>' . $sleepTag . $unconsciousTag . $dazeTag;
}

if ($roomBoard) {
    $itemsList[] = '<a href="board.php?board=' . urlencode($roomBoard['board_id']) . '">' . h($roomBoard['name']) . '</a>';
}

echo implode(', ', $itemsList);
?>
<?php endif; ?>

<?php if (!empty($room['items'])): ?>
<br>
地上有：
<?php foreach ($room['items'] as $item): ?>
<a href="<?= item_url($item['item_id'], $item['category'] ?? '') ?>"><?= h($item['item_name'] ?? $item['item_id']) ?></a><?php if ($item['quantity'] > 1): ?> x<?= $item['quantity'] ?><?php endif; ?>
<?php if (!empty($item['max_liquid']) && $item['max_liquid'] > 0 && (!empty($item['liquid_remaining']) || !empty($item['liquid_name']))): ?>
<span style="color:#888;">(<?= h($item['liquid_name'] ?? '液体') ?> <?= intval($item['liquid_remaining'] ?? 0) ?>/<?= intval($item['max_liquid']) ?>)</span>
<?php endif; ?>
[<a href="<?= action_url('get', ['item_id' => $item['item_id'], 'category' => $item['category'] ?? '']) ?>">拿</a>]&ensp;
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($corpses)): ?>
<br>
这里躺着：
<?php 
$corpseList = [];
foreach ($corpses as $corpse) {
    $corpseList[] = '<a href="corpse.php?id=' . intval($corpse['id']) . '">' . h(Corpse::getCorpseDisplayName($corpse)) . '</a>';
}
echo implode(',', $corpseList);
?>
<?php endif; ?>

<?php 
// 根据房间内的特殊动作和NPC添加功能链接
$allActions = [];

// 加载各类动作（按类型分类）
$specialActions = $room['actions'] ?? [];  // 所有动作
$movementActions = array_filter($specialActions, fn($a) => ($a['action_type'] ?? '') === 'movement');
$commerceActions = array_filter($specialActions, fn($a) => ($a['action_type'] ?? '') === 'commerce');
$npcTaskActions = array_filter($specialActions, fn($a) => ($a['action_type'] ?? '') === 'npc_task');
$linkActions = array_filter($specialActions, fn($a) => ($a['action_type'] ?? '') === 'link');
$otherSpecialActions = array_filter($specialActions, fn($a) => ($a['action_type'] ?? '') === 'special' || empty($a['action_type']));

// 1. 移动类特殊动作（穿过、跳墙、出去等）
if (!empty($movementActions)) {
    foreach ($movementActions as $action) {
        // 如果 action_cmd 是标准方向命令（包括 go + 方向）
        if (preg_match('/^go\s+(north|south|east|west|up|down|out|northeast|northwest|southeast|southwest|n|s|e|w|u|d|ne|nw|se|sw)$/i', $action['action_cmd'], $matches)) {
            // go north 等方向命令
            $direction = $matches[1];
            $allActions[] = '<a href="javascript:void(0);" onclick="executeAction(\'go\', \'' . urlencode($area) . '\', \'' . urlencode($roomId) . '\', \'' . $direction . '\')">' . h($action['action_name']) . '</a>';
        } elseif (in_array($action['action_cmd'], ['north', 'south', 'east', 'west', 'up', 'down', 'out', 'northeast', 'northwest', 'southeast', 'southwest', 'n', 's', 'e', 'w', 'u', 'd', 'ne', 'nw', 'se', 'sw'])) {

            // 纯方向命令
            $allActions[] = '<a href="javascript:void(0);" onclick="executeAction(\'go\', \'' . urlencode($area) . '\', \'' . urlencode($roomId) . '\', \'' . $action['action_cmd'] . '\')">' . h($action['action_name']) . '</a>';
        } else {
            // 其他移动命令（如 tiaqiang 或 go mufa）
            // 如果 action_cmd 包含空格（如 "go mufa"）
            if (strpos($action['action_cmd'], ' ') !== false) {
                // 分割命令和参数
                $parts = explode(' ', $action['action_cmd'], 2);
                $cmd = $parts[0];
                $param = isset($parts[1]) ? $parts[1] : '';
                $allActions[] = '<a href="javascript:void(0);" onclick="executeAction(\'' . $cmd . '\', \'' . urlencode($area) . '\', \'' . urlencode($roomId) . '\', \'' . urlencode($param) . '\')">' . h($action['action_name']) . '</a>';
            } else {
                // 没有参数的命令
                $allActions[] = '<a href="javascript:void(0);" onclick="executeAction(\'' . $action['action_cmd'] . '\', \'' . urlencode($area) . '\', \'' . urlencode($roomId) . '\')">' . h($action['action_name']) . '</a>';
            }
        }
    }
}
// 2. 特殊动作（挖掘、垂钓等）
if (!empty($otherSpecialActions)) {
    foreach ($otherSpecialActions as $action) {
        if (in_array($action['action_cmd'], ['north', 'south', 'east', 'west', 'up', 'down', 'out', 'northeast', 'northwest', 'southeast', 'southwest', 'n', 's', 'e', 'w', 'u', 'd', 'ne', 'nw', 'se', 'sw'])) {
            // 如果 action_cmd 是方向，则作为移动处理
            $allActions[] = '<a href="javascript:void(0);" onclick="executeAction(\'go\', \'' . urlencode($area) . '\', \'' . urlencode($roomId) . '\', \'' . $action['action_cmd'] . '\')">' . h($action['action_name']) . '</a>';
        } else {
            // 其他命令使用 executeAction
            // 如果 action_cmd 包含空格（如 "open guancai"）
            if (strpos($action['action_cmd'], ' ') !== false) {

                // 分割命令和参数
                $parts = explode(' ', $action['action_cmd'], 2);
                $cmd = $parts[0];
                $param = isset($parts[1]) ? $parts[1] : '';
                $allActions[] = '<a href="javascript:void(0);" onclick="executeAction(\'' . $cmd . '\', \'' . urlencode($area) . '\', \'' . urlencode($roomId) . '\', \'' . urlencode($param) . '\')">' . h($action['action_name']) . '</a>';
            } else {

                // 没有参数的命令
                $allActions[] = '<a href="javascript:void(0);" onclick="executeAction(\'' . $action['action_cmd'] . '\', \'' . urlencode($area) . '\', \'' . urlencode($roomId) . '\')">' . h($action['action_name']) . '</a>';
            }
        }
    }
}
// 3. 商业动作（钱庄存取款、当铺买卖、寄存等）
if (!empty($commerceActions)) {
    foreach ($commerceActions as $action) {
        $cmdName = $action['action_cmd'];
        $actionName = h($action['action_name']);

        // 根据命令类型设置不同的处理
        if (in_array($cmdName, ['jicun', 'qu', 'pick'])) {

            // 寄存、取回、购买：跳转到专门页面
            $allActions[] = '<a href="deposit.php?area=' . urlencode($area) . '&room=' . urlencode($roomId) . '">' . $actionName . '</a>';
        } elseif (in_array($cmdName, ['deposit', 'withdraw'])) {

            // 钱庄存取款：跳转到交易页面
            $allActions[] = '<a href="trade.php?action=' . $cmdName . '&area=' . urlencode($area) . '&room=' . urlencode($roomId) . '">' . $actionName . '</a>';
        } elseif (in_array($cmdName, ['buy', 'sell'])) {

            // 当铺买卖：跳转到交易页面
            $allActions[] = '<a href="trade.php?action=' . $cmdName . '&area=' . urlencode($area) . '&room=' . urlencode($roomId) . '">' . $actionName . '</a>';
        } elseif ($cmdName === 'sanhua_bounty') {

            // 三花堂悬赏管理：跳转到悬赏页面
            $allActions[] = '<a href="sanhua_bounty.php?area=' . urlencode($area) . '&room=' . urlencode($roomId) . '">' . $actionName . '</a>';
        } else {
            // 其他商业动作
            $allActions[] = '<a href="javascript:void(0);" onclick="executeAction(\'' . $cmdName . '\', \'' . urlencode($area) . '\', \'' . urlencode($roomId) . '\')">' . $actionName . '</a>';

        }

    }

}

// 注意：NPC的特殊动作（如还魂丹等）不在room页显示，在npc.php页面显示
// 如果需要添加其他类型的房间动作，在这里扩展

// 4. 外链动作（赌大小、股市、虚拟币、押签等独立页面）
if (!empty($linkActions)) {
    foreach ($linkActions as $action) {
        $url = $action['action_cmd'];
        $name = h($action['action_name']);
        $config = json_decode($action['config'] ?? '{}', true);
        $color = $config['color'] ?? '';
        $style = $color ? ' style="color:' . h($color) . ';"' : '';
        $allActions[] = '<a href="' . h($url) . '"' . $style . '>' . $name . '</a>';
    }
}

// 5. 门系统：为有门的出口添加开门/关门按钮到"你可以"区域
$doorActions = [];
$sortedExits = $sortedExits ?? [];
foreach ($sortedExits as $exit) {
    if (!empty($exit['door_name'])) {
        $doorName = h($exit['door_name']);
        $dir = addslashes($exit['direction']);
        if (!empty($exit['door_closed'])) {
            // 门关闭：显示"打开门"
            $doorActions[] = '<a href="javascript:void(0);" onclick="executeAction(\'open\', \'' . addslashes($room['area']) . '\', \'' . addslashes($room['room_id']) . '\', \'' . $dir . '\')">开' . $doorName . '</a>';
        } else {
            // 门开启：显示"关门"
            $doorActions[] = '<a href="javascript:void(0);" onclick="executeAction(\'close\', \'' . addslashes($room['area']) . '\', \'' . addslashes($room['room_id']) . '\', \'' . $dir . '\')">关' . $doorName . '</a>';
        }
    }
}
$allActions = array_merge($allActions, $doorActions);

// 6. 房间物品描述（如大旗、告示等可查看的物品）
// 对应 LPC 的 item_desc，让玩家可以通过链接查看这些物品
$itemDescActions = [];
$itemDescs = $room['item_descs'] ?? [];
foreach ($itemDescs as $itemDesc) {
    if (!empty($itemDesc['item_key']) && !empty($itemDesc['item_name'])) {
        $itemKey = addslashes($itemDesc['item_key']);
        $itemName = h($itemDesc['item_name']);
        // 添加"查看XXX"链接，触发 look 命令
        $itemDescActions[] = '<a href="javascript:void(0);" onclick="executeAction(\'look\', \'' . addslashes($room['area']) . '\', \'' . addslashes($room['room_id']) . '\', \'' . $itemKey . '\')">看' . $itemName . '</a>';
        
        // ★ 火焰山石门特殊处理：添加"捡乱石"和"砸石门"操作
        if ($room['room_id'] === 'qujing/firemount/shimen' && $itemDesc['item_key'] === 'stone') {
            $itemDescActions[] = '<a href="javascript:void(0);" onclick="executeAction(\'get\', \'' . addslashes($room['area']) . '\', \'' . addslashes($room['room_id']) . '\', \'stone\')">捡' . $itemName . '</a>';
            $itemDescActions[] = '<a href="javascript:void(0);" onclick="executeAction(\'hit\', \'' . addslashes($room['area']) . '\', \'' . addslashes($room['room_id']) . '\', \'shimen\')">砸石门</a>';
        }
    }
}
$allActions = array_merge($allActions, $itemDescActions);
?>

<?php
// 检查当前角色是否正在变化
// 大闹天宫交互动作
$dntgActions = DntgQuestHandler::getRoomActions($charId, $fullRoomId);
foreach ($dntgActions as $dntgAction) {
    if (!empty($dntgAction['name']) && !empty($dntgAction['cmd'])) {
        $allActions[] = '<a href="javascript:void(0);" onclick="executeAction(\'' . $dntgAction['cmd'] . '\', \'' . addslashes($room['area']) . '\', \'' . addslashes($room['room_id']) . '\')">' . h($dntgAction['name']) . '</a>';
    }
}

// 房间固定对象（fixed_objects）：添加“看[名字]”动作
if (!empty($room['fixed_objects'])) {
    foreach ($room['fixed_objects'] as $obj) {
        $allActions[] = '<a href="javascript:void(0);" onclick="executeAction(\'look\', \'' . addslashes($room['area']) . '\', \'' . addslashes($room['room_id']) . '\', \'' . addslashes($obj['object_id']) . '\')">看' . h($obj['name']) . '</a>';
    }
}

// 婚礼服务：检查房间内是否有轿夫头NPC且婚礼服务已启用
$weddingActions = [];
if (!empty($room['npcs'])) {
    foreach ($room['npcs'] as $roomNpc) {
        $roomNpcStringId = $roomNpc['npc_id'] ?? '';
        if ($roomNpcStringId === 'jftou' || $roomNpcStringId === 'jiaofu tou') {
            $roomNpcDbId = $roomNpc['id']; // 数据库数字ID
            $weddingInJob = Database::queryOne(
                "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'in_job'",
                [$roomNpcDbId]
            );
            if ($weddingInJob && $weddingInJob['temp_value'] == '1') {
                // 检查当前玩家是否是新娘
                $weddingBride = Database::queryOne(
                    "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'bride'",
                    [$roomNpcDbId]
                );
                if ($weddingBride && $weddingBride['temp_value'] == $charId) {
                    $weddingOnWay = Database::queryOne(
                        "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'on_way'",
                        [$roomNpcDbId]
                    );
                    if (!$weddingOnWay || $weddingOnWay['temp_value'] != '1') {
                        $allActions[] = '<a href="action.php?action=enter_palanquin&npc_id=' . $roomNpcDbId . '" style="color:#FF69B4; font-weight:bold;">进入花轿</a>';
                    }
                }
                // 检查当前玩家是否是新郎
                $weddingGroom = Database::queryOne(
                    "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'groom'",
                    [$roomNpcDbId]
                );
                if ($weddingGroom && $weddingGroom['temp_value'] == $charId) {
                    $weddingOnWay = Database::queryOne(
                        "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'on_way'",
                        [$roomNpcDbId]
                    );
                    if ($weddingOnWay && $weddingOnWay['temp_value'] == '1') {
                        $allActions[] = '<a href="action.php?action=arrive_destination&npc_id=' . $roomNpcDbId . '" style="color:#FFD700; font-weight:bold;">到达目的地</a>';
                    }
                }
            }
            break; // 只处理第一个轿夫头
        }
    }
}

$isTransformed = isset($_SESSION['transform_' . $charId]);
if ($isTransformed):
    $transformData = $_SESSION['transform_' . $charId];
?>
<br>

<span style="color: #ff6600;">【变化中】你现在变成了<?= h($transformData['target_name']) ?> 的样子！</span>
[<a href="action.php?action=transform">恢复原形</a>]
<?php endif; ?>

<?php
$statusTags = [];
if (!empty($char['sleep_state']) && $char['sleep_state'] == 1) {
    $sleepRemaining = max(0, intval($char['sleep_end_time'] ?? 0) - time());
    $statusTags[] = '<span style="color:#999999;">💤 睡梦中(' . $sleepRemaining . '秒)</span>';
}
if (!empty($char['unconscious_state']) && $char['unconscious_state'] == 1) {
    $unconsciousRemaining = max(0, intval($char['unconscious_end_time'] ?? 0) - time());
    $statusTags[] = '<span style="color:#FF4444;">😵 昏迷(' . $unconsciousRemaining . '秒)</span>';
}
if (!empty($char['daze_state']) && $char['daze_state'] == 1) {
    $dazeRemaining = max(0, intval($char['daze_end_time'] ?? 0) - time());
    $statusTags[] = '<span style="color:#AAAAAA;">😴 发呆(' . $dazeRemaining . '秒)</span>';
}
if (!empty($statusTags)):
?>
<br>
<span style="font-size: 0.9em;">你的状态：<?= implode(' ', $statusTags) ?></span>
<?php endif; ?>

<?php
// 检查房间是否支持睡眠
$hasSleepRoom = !empty($room['sleep_room']);
$hasBed = !empty($room['if_bed']);

if (!empty($allActions) || $hasSleepRoom || !empty($dntgActions) || $fullRoomId === 'lingtai/inside4' || $fullRoomId === 'qujing/wuzhuang/anshi' || $renshenEventActive || preg_match('/qujing\/wuzhuang\/wzgmaze\d$/', $fullRoomId) || preg_match('/dntg\/donghai\/maze/', $fullRoomId) || preg_match('/sea\/maze\d$/', $fullRoomId)):
?>
<br>
你可以：
<?php
    if (!empty($allActions)) {
        echo implode(' | ', $allActions);
    }
    if ($fullRoomId === 'qujing/wuzhuang/anshi') {
        if (!empty($allActions)) echo ' | ';
        echo '<a href="javascript:void(0);" onclick="executeAction(\'mo\', \'' . addslashes($room['area']) . '\', \'' . addslashes($room['room_id']) . '\')">摸一下</a>';
    }
    // 人参果园事件动作
    if ($renshenEventActive) {
        $hasPrevActions = !empty($allActions) || $fullRoomId === 'qujing/wuzhuang/anshi';
        if ($hasPrevActions) echo ' | ';
        if ($renshenPhase === 'phase2') {
            echo '<a href="javascript:void(0);" onclick="executeAction(\'ask\', \'' . addslashes($room['area']) . '\', \'' . addslashes($room['room_id']) . '\', \'zhenyuan about 人参果\')" style="color:#00FFFF;">问人参果</a>';
            echo ' | ';
        }
        echo '<a href="javascript:void(0);" onclick="executeAction(\'go\', \'' . addslashes($room['area']) . '\', \'' . addslashes($room['room_id']) . '\', \'south\')" style="color:#FFFF00;">离开果园</a>';
    }
    // 八卦桥迷宫：跳桥动作
    if (preg_match('/qujing\/wuzhuang\/wzgmaze\d$/', $fullRoomId)) {
        $hasPrevActions = !empty($allActions) || $fullRoomId === 'qujing/wuzhuang/anshi' || $renshenEventActive;
        if ($hasPrevActions) echo ' | ';
        echo '<a href="javascript:void(0);" onclick="executeAction(\'jump bridge\', \'' . addslashes($room['area']) . '\', \'' . addslashes($room['room_id']) . '\')" style="color:#FF6600;">跳桥</a>';
    }
    // ★ 海底迷宫：小金鱼引路动作
    if ($fullRoomId === 'dntg/donghai/mazee') {
        $hasPrevActions = !empty($allActions) || $fullRoomId === 'qujing/wuzhuang/anshi' || $renshenEventActive;
        if ($hasPrevActions) echo ' | ';
        echo '<a href="javascript:void(0);" onclick="executeAction(\'follow goldfish\', \'' . addslashes($room['area']) . '\', \'' . addslashes($room['room_id']) . '\')" style="color:#FFD700;">跟随小金鱼</a>';
    }
    // ★ 海底莽林：返回入口动作（在 maze0）
    if ($fullRoomId === 'sea/maze0') {
        $hasPrevActions = !empty($allActions) || $fullRoomId === 'qujing/wuzhuang/anshi' || $renshenEventActive;
        if ($hasPrevActions) echo ' | ';
        echo '<a href="javascript:void(0);" onclick="executeAction(\'go\', \'' . addslashes($room['area']) . '\', \'' . addslashes($room['room_id']) . '\', \'northeast\')" style="color:#00BFFF;">回到海底</a>';
    }
    if ($hasSleepRoom):
        $sleepText = $hasBed ? '上床' : '睡觉';
        if (!empty($allActions) || $fullRoomId === 'qujing/wuzhuang/anshi') echo ' | ';
?>
<a href="javascript:void(0);" onclick="executeAction('sleep', '<?= addslashes($room['area']) ?>', '<?= addslashes($room['room_id']) ?>')"><?= $sleepText ?></a>
<?php
        // 双人睡眠邀请：检查房间内是否有异性玩家
        if ($hasBed && !empty($players)):
            $myGender = $char['gender'] ?? 'male';
            $oppositeGenderPlayers = [];
            foreach ($players as $player) {
                $playerGender = $player['gender'] ?? 'male';
                if ($playerGender !== $myGender) {
                    // 排除睡眠/昏迷/发呆状态的玩家
                    if (empty($player['sleep_state']) || $player['sleep_state'] != 1) {
                        if (empty($player['unconscious_state']) || $player['unconscious_state'] != 1) {
                            if (empty($player['daze_state']) || $player['daze_state'] != 1) {
                                $oppositeGenderPlayers[] = $player;
                            }
                        }
                    }
                }
            }
            if (!empty($oppositeGenderPlayers)):
                echo ' | <a href="javascript:void(0);" onclick="showSleepInviteDialog()" style="color:#FF69B4;">💕邀请同床</a>';
                // 输出隐藏的选择框HTML
                echo '<div id="sleep-invite-dialog" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:#2a2a2a; border:2px solid #FF69B4; padding:15px; z-index:1000; min-width:200px;">';
                echo '<div style="color:#FFD700; margin-bottom:10px; text-align:center;">选择要邀请的玩家</div>';
                foreach ($oppositeGenderPlayers as $player):
                    $playerName = get_char_display_name($player);
                    echo '<a href="javascript:void(0);" onclick="inviteSleep(\'' . addslashes($player['name']) . '\')" style="display:block; padding:5px; margin:3px 0; background:#3a3a3a; color:#FF69B4; text-align:center;">' . h($playerName) . '</a>';
                endforeach;
                echo '<a href="javascript:void(0);" onclick="hideSleepInviteDialog()" style="display:block; padding:5px; margin:10px 0 0 0; background:#555; color:#fff; text-align:center;">取消</a>';
                echo '</div>';
            endif;
        endif;
    endif;
    // 厨房特殊功能：要吃的
    if ($fullRoomId === 'lingtai/inside4'):
        if (!empty($allActions) || $hasSleepRoom) echo ' | ';
?>
<a href="javascript:void(0);" onclick="executeAction('yao', '<?= addslashes($room['area']) ?>', '<?= addslashes($room['room_id']) ?>')" style="color:#D2691E;">🥟要吃的</a>
<?php
    endif;
endif;
?>
</div>
<a href="look_self.php"><br>看自己</a><?php if (Database::queryOne("SELECT 1 FROM character_inventory WHERE char_id = ? AND item_id = 'tianwang_coat' LIMIT 1", [$charId])): ?> | <a href="javascript:void(0);" onclick="openTeleportModal()">传送</a><?php endif; ?>
<hr>

<?php
// 检查玩家是否有房产
require_once HELPER_PATH . 'HomeHelper.php';
$hasHome = HomeHelper::hasHome($charId);
?>
<div style="width: 150px; display: grid; grid-template-columns: repeat(4, 1fr); gap: 1px 1px; margin: 0px 0;">

<a href="data.php" id="setting-data">数据</a>
<?php if ($hasHome): ?>
<a href="action.php?action=home&param=enter" id="setting-home">回家</a>
<?php endif; ?>
<a href="score.php" id="setting-score">状态</a>
<a href="action.php?action=recover" id="setting-recover">恢复</a>
<a href="http://127.0.0.1/functions/skills_enable.php" id="setting-skill">技能</a>
<a href="javascript:void(0);" onclick="openExertModal()" id="setting-exert">运功</a>
<a href="http://127.0.0.1/functions/skills_practice.php" id="setting-practice">练功</a>
<a href="javascript:void(0);" onclick="openExerciseModal()" id="setting-exercise">打坐</a>
<a href="javascript:void(0);" onclick="openMeditateModal()" id="setting-meditate">冥思</a>
<a href="action.php?action=stop" id="setting-stop">停功</a>
<a href="talk.php" id="setting-chat">聊天</a>
<a href="../rankd/rankd.php" id="setting-rank">排行</a>
<a href="inventory.php" id="setting-bag">背包</a>
<a href="shop.php" id="setting-shop">商城</a>
<a href="friends.php" id="setting-friends">好友</a>
<a href="../help/" target="_blank" id="setting-help">帮助</a>
<a href="../news.php" id="setting-news">新闻</a>
<a href="recharge.php" id="setting-recharge">充值</a>
</div>
<hr id="setting-bottom-hr">
<a href="javascript:void(0)" onclick="openSettingsModal()">设置</a> | <a href="redeem.php">兑换</a> | <a href="javascript:void(0)" onclick="showGameTime()">西游时辰</a>

<div class="back-link">
    当前时间:
    <script src="../assets/js/time.js"></script>
    <br>
    <a href="../logout.php" class="logout-btn">退出</a> | 
    <a href="javascript:window.location.reload();">刷新</a>
    <?php
    require_once MODEL_PATH . 'User.php';
    $userId = $_SESSION['user_id'] ?? 0;
    if (UserModel::isWizard(intval($userId))):
    ?>
     | <a href="admin.php">管理</a>
    <?php endif; ?>
</div>

<script>
// 定时刷新在线状态
window.currentCharId = <?= $charId ?>;
var currentCharName = '<?= isset($char['name']) ? addslashes($char['name']) : '' ?>';
console.log('[Room] currentCharName:', currentCharName);

// 检查是否有需要跳转到战斗页面的标记
<?php if (isset($_GET['auto_fight']) && $_GET['auto_fight'] == '1'): ?>
setTimeout(function() {
    window.location.href = 'fight.php';
}, 1500);  // 1.5秒后跳转到战斗页面，让用户看到广播消息
<?php endif; ?>

// 检查是否有飞行跳转
<?php if (isset($_SESSION['fly_redirect'])): ?>
setTimeout(function() {
    window.location.href = '<?= $_SESSION['fly_redirect']['url'] ?>';
}, <?= $_SESSION['fly_redirect']['delay'] ?>);

<?php 
    // 清除 session
    unset($_SESSION['fly_redirect']);
?>
<?php endif; ?>

// 地府鬼魂声音特效效果（显示为红色房间消息）
<?php if ($enableGhostVoice): ?>
(function() {
    const ghostMessages = [
        '<?= HTML_HIRED . '附近传来鬼魂的声音：还我命来~~' . HTML_NOR ?>',
        '<?= HTML_HIRED . '远处传来凄厉的哭声：冤枉啊~~' . HTML_NOR ?>',
        '<?= HTML_HIRED . '阴暗角落传来低沉的声音：放我出去~~' . HTML_NOR ?>',
        '<?= HTML_HIRED . '黑暗中响起哀嚎：好冷啊~~' . HTML_NOR ?>'
    ];

    function showGhostMessage() {
        var msgLog = document.getElementById('room-msg-log');
        if (!msgLog) return;
        var text = ghostMessages[Math.floor(Math.random() * ghostMessages.length)];
        var div = document.createElement('div');
        div.className = 'room-msg';
        div.innerHTML = text;
        div.style.opacity = '0';
        div.style.transition = 'opacity 0.3s ease-in-out';
        msgLog.appendChild(div);
        msgLog.scrollTop = msgLog.scrollHeight;
        while (msgLog.children.length > 3) {
            msgLog.removeChild(msgLog.firstChild);
        }
        // 淡入
        setTimeout(function() { div.style.opacity = '1'; }, 50);
        // 闪烁后淡出
        var blinkCount = 0;
        var blinkInterval = setInterval(function() {
            div.style.opacity = div.style.opacity === '1' ? '0.3' : '1';
            blinkCount++;
            if (blinkCount >= 6) {
                clearInterval(blinkInterval);
                div.style.opacity = '0.7';
            }
        }, 800);
    }

    // 随机间隔显示鬼魂消息
    function scheduleGhost() {
        var delay = 5000 + Math.random() * 10000;
        setTimeout(function() {
            showGhostMessage();
            scheduleGhost();
        }, delay);
    }
    showGhostMessage();
    scheduleGhost();
})();
<?php endif; ?>

</script>

<?php
// 落地消息已移除（原 fly_landing 60秒延迟显示逻辑）
?>



<!-- 运功选择弹窗 -->

<div id="exertModal" class="exert-modal-overlay">
    <div class="exert-modal">
        <h3>【内功运用】</h3>
        <div id="exertContent">
            <p class="exert-modal-desc">选择你要运用的内功效果</p>
            <div class="exert-type-grid">
                <button class="exert-type-btn" onclick="doExert('recover')">
                    <div class="name">恢复</div>
                    <div class="desc">运功恢复气血</div>
                    <div class="cost">消耗内力 20+</div>
                </button>
                <button class="exert-type-btn" onclick="doExert('refresh')">
                    <div class="name">提神</div>
                    <div class="desc">运功恢复精力</div>
                    <div class="cost">消耗内力 20+</div>
                </button>
                <button class="exert-type-btn" onclick="doExert('regenerate')">
                    <div class="name">再生</div>
                    <div class="desc">恢复有效气血上限</div>
                    <div class="cost">消耗内力 100</div>
                </button>
                <button class="exert-type-btn" onclick="doExert('heal')">
                    <div class="name">疗伤</div>
                    <div class="desc">运功疗伤</div>
                    <div class="cost">消耗内力 50、精神 30</div>
                </button>
                <button class="exert-type-btn" onclick="doExert('powerup')">
                    <div class="name">蓄力</div>
                    <div class="desc">临时提升攻击防御</div>
                    <div class="cost">消耗内力 100</div>
                </button>
                <button class="exert-type-btn" onclick="doExert('jingxin')">
                    <div class="name">静心诀</div>
                    <div class="desc">降低杀气、闪避加成</div>
                    <div class="cost">消耗内力 200</div>
                    <div class="require">需要：冷泉神功</div>
                </button>
                <button class="exert-type-btn" onclick="doExert('powerfade')">
                    <div class="name">化功</div>
                    <div class="desc">大幅降低杀气</div>
                    <div class="cost">消耗内力 100、精神 100</div>
                    <div class="require">需要：摄气诀</div>
                </button>
                <button class="exert-type-btn" onclick="doExertTarget('transfer')">
                    <div class="name">真气传送</div>
                    <div class="desc">传送内力给同门</div>
                    <div class="cost">消耗内力 100+</div>
                </button>
                <button class="exert-type-btn" onclick="doExertTarget('sheqi')">
                    <div class="name">舍气</div>
                    <div class="desc">战斗中吸取气血</div>
                    <div class="cost">消耗精神</div>
                    <div class="require">需要：摄气诀 30级</div>
                </button>
                <button class="exert-type-btn" onclick="doExertTarget('yuanyue')">
                    <div class="name">月圆</div>
                    <div class="desc">解毒+治疗气血</div>
                    <div class="cost">消耗内力 600</div>
                    <div class="require">需要：月宫圆月心法 80级</div>
                </button>
                <button class="exert-type-btn" onclick="doExertTarget('lifeheal')">
                    <div class="name">生命治疗</div>
                    <div class="desc">治疗他人气血</div>
                    <div class="cost">消耗内力 150</div>
                    <div class="require">需要：莲花心法</div>
                </button>
            </div>
        </div>
        <button class="exert-modal-close" onclick="closeExertModal()">关闭</button>
    </div>
</div>

<!-- 天王披风传送弹窗 -->
<div id="teleportModal" class="teleport-modal-overlay">
    <div class="teleport-modal">
        <h3>【天王披风】</h3>
        <p class="teleport-modal-desc">点击格子记录位置，最多5个·点击已记录格子即可传送</p>
        <div id="teleportGrid" class="teleport-grid">加载中...</div>
        <div class="teleport-bottom">
            <button class="teleport-btn" onclick="doTeleport('save')" id="teleportSaveBtn">📌 记录当前位置</button>
            <button class="teleport-btn danger" onclick="toggleForgetMode()" id="teleportForgetBtn">🗑 忘记模式</button>
        </div>
        <button class="teleport-modal-close" onclick="closeTeleportModal()">关闭</button>
    </div>
</div>





<!-- 打坐弹窗 -->
<div id="exerciseModal" class="exercise-modal-overlay">
    <div class="exercise-modal">
        <h3>【打坐练功】</h3>
        <p class="exercise-modal-desc">消耗气血来修炼内功</p>
        <div class="exercise-input-group">
            <label for="exerciseAmount">请输入消耗的气血数量</label>
            <input type="number" id="exerciseAmount" min="1" placeholder="例如：100">
        </div>
        <p class="exercise-modal-tips">提示：消耗气血越多，获得的内功熟练度越高</p>
        <div class="exercise-modal-buttons">
            <button class="exercise-modal-btn primary" onclick="doExercise()">开始打坐</button>
            <button class="exercise-modal-btn secondary" onclick="closeExerciseModal()">取消</button>
        </div>
    </div>
</div>

<!-- 冥思弹窗 -->
<div id="meditateModal" class="meditate-modal-overlay">
    <div class="meditate-modal">
        <h3>【冥思修炼】</h3>
        <p class="meditate-modal-desc">消耗精神来修炼法术</p>
        <div class="meditate-input-group">
            <label for="meditateAmount">请输入消耗的精神数量</label>
            <input type="number" id="meditateAmount" min="1" placeholder="例如：50">
        </div>
        <p class="meditate-modal-tips">提示：消耗精神越多，获得的法术熟练度越高</p>
        <div class="meditate-modal-buttons">
            <button class="meditate-modal-btn primary" onclick="doMeditate()">开始冥思</button>
            <button class="meditate-modal-btn secondary" onclick="closeMeditateModal()">取消</button>
        </div>
    </div>
</div>

<!-- 设置弹窗 -->
<div id="settingsModal" class="meditate-modal-overlay">
    <div class="meditate-modal" style="max-width: 380px;">
        <h3>【显示设置】</h3>
        <p class="meditate-modal-desc">选择要在房间页面隐藏的功能（取消勾选即隐藏）</p>
        
        <div style="max-height: 300px; overflow-y: auto; text-align: left; padding: 5px 10px;">
            <div style="margin-bottom: 8px;">
                <strong style="color: #4a90d9; display: block; margin-bottom: 4px; font-size: 0.85em;">基础信息</strong>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 2px 10px;">
                    <label style="display: flex; align-items: center; margin: 1px 0; font-size: 0.85em;">
                        <input type="checkbox" id="set-room-desc" checked onchange="applySettings()" style="margin-right: 1px; transform: scale(0.85);"> 房间描述
                    </label>
                    <label style="display: flex; align-items: center; margin: 1px 0; font-size: 0.85em;">
                        <input type="checkbox" id="set-data" checked onchange="applySettings()" style="margin-right: 1px; transform: scale(0.85);"> 数据
                    </label>
                    <label style="display: flex; align-items: center; margin: 1px 0; font-size: 0.85em;">
                        <input type="checkbox" id="set-home" checked onchange="applySettings()" style="margin-right: 1px; transform: scale(0.85);"> 回家
                    </label>
                </div>
            </div>
            
            <div style="margin-bottom: 8px;">
                <strong style="color: #4a90d9; display: block; margin-bottom: 4px; font-size: 0.85em;">修炼相关</strong>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 2px 6px;">
                    <label style="display: flex; align-items: center; margin: 1px 0; font-size: 0.85em;">
                        <input type="checkbox" id="set-score" checked onchange="applySettings()" style="margin-right: 1px; transform: scale(0.85);"> 状态
                    </label>
                    <label style="display: flex; align-items: center; margin: 1px 0; font-size: 0.85em;">
                        <input type="checkbox" id="set-recover" checked onchange="applySettings()" style="margin-right: 1px; transform: scale(0.85);"> 恢复
                    </label>
                    <label style="display: flex; align-items: center; margin: 1px 0; font-size: 0.85em;">
                        <input type="checkbox" id="set-skill" checked onchange="applySettings()" style="margin-right: 1px; transform: scale(0.85);"> 技能
                    </label>
                    <label style="display: flex; align-items: center; margin: 1px 0; font-size: 0.85em;">
                        <input type="checkbox" id="set-exert" checked onchange="applySettings()" style="margin-right: 1px; transform: scale(0.85);"> 运功
                    </label>
                    <label style="display: flex; align-items: center; margin: 1px 0; font-size: 0.85em;">
                        <input type="checkbox" id="set-practice" checked onchange="applySettings()" style="margin-right: 1px; transform: scale(0.85);"> 练功
                    </label>
                    <label style="display: flex; align-items: center; margin: 1px 0; font-size: 0.85em;">
                        <input type="checkbox" id="set-exercise" checked onchange="applySettings()" style="margin-right: 1px; transform: scale(0.85);"> 打坐
                    </label>
                    <label style="display: flex; align-items: center; margin: 1px 0; font-size: 0.85em;">
                        <input type="checkbox" id="set-meditate" checked onchange="applySettings()" style="margin-right: 1px; transform: scale(0.85);"> 冥思
                    </label>
                    <label style="display: flex; align-items: center; margin: 1px 0; font-size: 0.85em;">
                        <input type="checkbox" id="set-stop" checked onchange="applySettings()" style="margin-right: 1px; transform: scale(0.85);"> 停功
                    </label>
                </div>
            </div>
            
            <div style="margin-bottom: 8px;">
                <strong style="color: #4a90d9; display: block; margin-bottom: 4px; font-size: 0.85em;">功能入口</strong>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 2px 6px;">
                    <label style="display: flex; align-items: center; margin: 1px 0; font-size: 0.85em;">
                        <input type="checkbox" id="set-chat" checked onchange="applySettings()" style="margin-right: 1px; transform: scale(0.85);"> 聊天
                    </label>
                    <label style="display: flex; align-items: center; margin: 1px 0; font-size: 0.85em;">
                        <input type="checkbox" id="set-rank" checked onchange="applySettings()" style="margin-right: 1px; transform: scale(0.85);"> 排行
                    </label>
                    <label style="display: flex; align-items: center; margin: 1px 0; font-size: 0.85em;">
                        <input type="checkbox" id="set-bag" checked onchange="applySettings()" style="margin-right: 1px; transform: scale(0.85);"> 背包
                    </label>
                    <label style="display: flex; align-items: center; margin: 1px 0; font-size: 0.85em;">
                        <input type="checkbox" id="set-shop" checked onchange="applySettings()" style="margin-right: 1px; transform: scale(0.85);"> 商城
                    </label>
                    <label style="display: flex; align-items: center; margin: 1px 0; font-size: 0.85em;">
                        <input type="checkbox" id="set-friends" checked onchange="applySettings()" style="margin-right: 1px; transform: scale(0.85);"> 好友
                    </label>
                    <label style="display: flex; align-items: center; margin: 1px 0; font-size: 0.85em;">
                        <input type="checkbox" id="set-help" checked onchange="applySettings()" style="margin-right: 1px; transform: scale(0.85);"> 帮助
                    </label>
                    <label style="display: flex; align-items: center; margin: 1px 0; font-size: 0.85em;">
                        <input type="checkbox" id="set-news" checked onchange="applySettings()" style="margin-right: 1px; transform: scale(0.85);"> 新闻
                    </label>
                    <label style="display: flex; align-items: center; margin: 1px 0; font-size: 0.85em;">
                        <input type="checkbox" id="set-recharge" checked onchange="applySettings()" style="margin-right: 1px; transform: scale(0.85);"> 充值
                    </label>
                </div>
            </div>

            <div style="margin-bottom: 8px;">
                <strong style="color: #4a90d9; display: block; margin-bottom: 4px; font-size: 0.85em;">主题风格</strong>
                <div style="display: flex; gap: 10px; align-items: center; font-size: 0.85em;">
                    <label style="display: flex; align-items: center;">
                        <input type="radio" name="theme-choice" id="theme-light" value="light" onchange="switchTheme('light')" style="margin-right: 3px; transform: scale(0.85);"> 默认
                    </label>
                    <label style="display: flex; align-items: center;">
                        <input type="radio" name="theme-choice" id="theme-dark" value="dark" onchange="switchTheme('dark')" style="margin-right: 3px; transform: scale(0.85);"> 🌙 暗色
                    </label>
                </div>
            </div>
        </div>

        <div class="meditate-modal-buttons">
            <button class="meditate-modal-btn primary" onclick="saveSettings()">保存设置</button>
            <button class="meditate-modal-btn secondary" onclick="closeSettingsModal()">关闭</button>
        </div>
    </div>
</div>

<!-- 科举考试弹窗 -->
<div id="examModal" class="exam-modal-overlay">
    <div class="exam-modal">
        <h3>【科举考试】</h3>
        <div id="examQuestions" style="max-height: 40vh; overflow-y: auto; margin-bottom: 15px; text-align: left;">
            <p style="color: #aaa; text-align: center;">加载中...</p>
        </div>
        <div style="margin-bottom: 15px;">
            <input type="text" id="examAnswerInput" placeholder="请输入答案（如：ABC）" 
                   style="width: 100%; padding: 10px; font-size: 16px; text-align: center; background-color: #3d3d3d; border: 2px solid #555; border-radius: 4px; color: #fff;" 
                   maxlength="3" oninput="this.value = this.value.toUpperCase()">
        </div>
        <div class="exam-modal-buttons">
            <button class="exam-modal-btn primary" onclick="submitExamAnswer()">提交答案</button>
            <button class="exam-modal-btn secondary" onclick="closeExamModal()">取消考试</button>
        </div>
    </div>
</div>

<script>
let isShowingGameTime = false;

function showGameTime() {
    if (isShowingGameTime) return; // 防止重复点击
    isShowingGameTime = true;
    
    // 视觉反馈：改变鼠标样式
    document.body.style.cursor = 'wait';
    
    fetch('show_game_time.php')
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('获取时间失败: ' + (data.error || '未知错误'));
            }
        })
        .catch(e => {
            alert('请求失败: ' + e.message);
        })
        .finally(() => {
            isShowingGameTime = false;
            document.body.style.cursor = '';
        });
}

// ========== 设置功能 ==========

// 设置项与元素ID的映射
const SETTINGS_MAP = {
    'room-desc': 'setting-room-desc',
    'lookself': 'setting-lookself',
    'data': 'setting-data',
    'home': 'setting-home',
    'score': 'setting-score',
    'recover': 'setting-recover',
    'skill': 'setting-skill',
    'exert': 'setting-exert',
    'practice': 'setting-practice',
    'exercise': 'setting-exercise',
    'meditate': 'setting-meditate',
    'stop': 'setting-stop',
    'chat': 'setting-chat',
    'rank': 'setting-rank',
    'bag': 'setting-bag',
    'shop': 'setting-shop',
    'friends': 'setting-friends',
    'help': 'setting-help',
    'news': 'setting-news',
    'recharge': 'setting-recharge'
};

const SETTINGS_KEY = 'xyj_room_settings';
const THEME_KEY = 'xyj_theme';

// ========== 主题切换功能 ==========
function switchTheme(theme) {
    const themeLink = document.getElementById('theme-link');
    if (!themeLink) return;
    const basePath = themeLink.href.includes('/assets/css/') ? themeLink.href.substring(0, themeLink.href.lastIndexOf('/') + 1) : '';
    if (theme === 'dark') {
        themeLink.href = basePath + 'dark-theme.css';
    } else {
        themeLink.href = basePath + 'light-theme.css';
    }
    localStorage.setItem(THEME_KEY, theme);
}

// 页面加载时恢复主题偏好
(function initTheme() {
    const saved = localStorage.getItem(THEME_KEY) || 'light';
    switchTheme(saved);
    // 同步 radio 按钮
    const radio = document.getElementById('theme-' + saved);
    if (radio) radio.checked = true;
})();

// 加载设置
function loadSettings() {
    try {
        const saved = localStorage.getItem(SETTINGS_KEY);
        if (saved) {
            const settings = JSON.parse(saved);
            // 设置复选框状态
            for (const key in SETTINGS_MAP) {
                const checkbox = document.getElementById('set-' + key);
                if (checkbox) {
                    checkbox.checked = settings[key] !== false; // 默认显示
                }
            }
        }
        applySettings();
    } catch (e) {
        console.error('加载设置失败:', e);
    }
}

// 保存设置
function saveSettings() {
    try {
        const settings = {};
        for (const key in SETTINGS_MAP) {
            const checkbox = document.getElementById('set-' + key);
            if (checkbox) {
                settings[key] = checkbox.checked;
            }
        }
        localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings));
        applySettings();
        closeSettingsModal();
        alert('设置已保存！');
    } catch (e) {
        console.error('保存设置失败:', e);
        alert('保存设置失败');
    }
}

// 应用设置（实时预览）
function applySettings() {
    let allOthersHidden = true;
    
    for (const key in SETTINGS_MAP) {
        const checkbox = document.getElementById('set-' + key);
        const element = document.getElementById(SETTINGS_MAP[key]);
        if (checkbox && element) {
            const isVisible = checkbox.checked;
            element.style.display = isVisible ? '' : 'none';
            
            // 检查除了房间描述外的其他项
            if (key !== 'room-desc' && isVisible) {
                allOthersHidden = false;
            }
        }
    }
    
    // 如果除了房间描述外其他都隐藏了，隐藏最下面的hr
    const bottomHr = document.getElementById('setting-bottom-hr');
    if (bottomHr) {
        bottomHr.style.display = allOthersHidden ? 'none' : '';
    }
}

// 打开设置弹窗
function openSettingsModal() {
    const modal = document.getElementById('settingsModal');
    if (modal) {
        modal.classList.add('active');
    }
}

// 关闭设置弹窗
function closeSettingsModal() {
    const modal = document.getElementById('settingsModal');
    if (modal) {
        modal.classList.remove('active');
    }
}

// 页面加载时自动加载设置
document.addEventListener('DOMContentLoaded', loadSettings);

// 如果页面已经加载完成，立即执行
if (document.readyState !== 'loading') {
    loadSettings();
}
</script>

</body>
</html>
