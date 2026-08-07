<?php
/**
 * 统一动作处理器 - 精简版（已重构）
 * 使用 ActionRouter 分发到独立方法处理各种游戏动作
 */

// 检测是否是 AJAX 请求
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// AJAX 请求时禁用 HTML 错误输出，确保返回纯 JSON
if ($isAjax) {
    ini_set('html_errors', '0');
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    
    // 设置错误处理器，将错误转为 JSON
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        $error = [
            'success' => false,
            'message' => '服务器错误: ' . $errstr,
            'error' => [
                'errno' => $errno,
                'errstr' => $errstr,
                'errfile' => $errfile,
                'errline' => $errline
            ]
        ];
        echo json_encode($error, JSON_UNESCAPED_UNICODE);
        exit;
    });
    
    // 设置异常处理器
    set_exception_handler(function($e) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        $error = [
            'success' => false,
            'message' => '服务器异常: ' . $e->getMessage(),
            'error' => [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]
        ];
        echo json_encode($error, JSON_UNESCAPED_UNICODE);
        exit;
    });
} else {
    // 非 AJAX 请求，启用 HTML 错误显示以便调试
    ini_set('html_errors', '1');
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

// 最优先：立即启动输出缓冲，捕获一切输出
ob_start();

session_save_path(__DIR__ . '/../sessions');
session_start();

// 定义游戏环境常量，允许命令文件执行
define('IN_GAME', true);

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

// 确保数据库表结构完整
Database::addMarriedColumn();
Database::addSleepInvitationsTable();
Database::addKeeZeroTimeColumn();
Database::addGuestStatusColumn();
Database::addBabyColumns();

// 加载所有模型
require_once MODEL_PATH . 'Character.php';
require_once MODEL_PATH . 'Room.php';
require_once MODEL_PATH . 'Npc.php';
require_once MODEL_PATH . 'Item.php';
require_once MODEL_PATH . 'User.php';

// 加载所有守护进程
require_once DAEMON_PATH . 'CombatDaemon.php';
require_once DAEMON_PATH . 'MessageDaemon.php';
require_once DAEMON_PATH . 'NatureDaemon.php';
require_once DAEMON_PATH . 'LoginDaemon.php';
require_once DAEMON_PATH . 'CommandDaemon.php';
require_once DAEMON_PATH . 'ActionRouter.php';
require_once DAEMON_PATH . 'QujingHandler.php';

// 加载所有辅助类
require_once HELPER_PATH . 'ArmorHelper.php';
require_once HELPER_PATH . 'WeaponHelper.php';
require_once HELPER_PATH . 'FabaoHelper.php';
require_once HELPER_PATH . 'SkillManager.php';
require_once HELPER_PATH . 'SpellHelper.php';
require_once HELPER_PATH . 'AttributeHelper.php';
require_once HELPER_PATH . 'ExpHelper.php';
require_once HELPER_PATH . 'CombatMessages.php';
require_once HELPER_PATH . 'CombatSystemHelper.php';

// 加载所有命令函数
$commandFiles = glob(__DIR__ . '/../commands/*.php');
foreach ($commandFiles as $file) {
    require_once $file;
}

// 要求登录（会自动处理AJAX请求）
require_login();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$charId = get_char_id();

// 加载角色信息（用于广播消息等）
$char = CharacterModel::find($charId);

// 如果角色不存在，直接返回错误
if (!$char) {
    $isAjaxEarly = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                   strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    if ($isAjaxEarly) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['success' => false, 'message' => '角色不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        redirect('../index.php');
        exit;
    }
}

$result = ['success' => false, 'message' => '未知动作'];
$actualAction = $action; // 追踪实际动作名，用于事件保存

// 运行定时检查（取经失败、天魔茧收回等）
QujingHandler::runTimedChecks();

// 运行马盗的定时检查（警告、攻击等）
require_once HELPER_PATH . 'MadaoHelper.php';
$madaoMessages = MadaoHelper::checkTimers(get_char_id());
if (!empty($madaoMessages)) {
    require_once DAEMON_PATH . 'MessageDaemon.php';
    foreach ($madaoMessages as $msg) {
        MessageDaemon::sendToPlayer(get_char_id(), HTML_HIYEL . $msg . HTML_NOR, 'npc_chat');
    }
}

// ★ 石栈道陷阱检查（还原原始LPC: shizhan.c greeting() 机制）
// 玩家进入石栈道25秒后，自动传送到铁笼
require_once HELPER_PATH . 'TempStateHelper.php';
$shizhanEnterTime = TempStateHelper::get($charId, 'shizhan_enter_time');
if (is_array($shizhanEnterTime)) {
    $shizhanEnterTime = intval($shizhanEnterTime['_value'] ?? 0);
}
if ($shizhanEnterTime && $char['current_room'] === 'westway/shizhan') {
    $elapsed = time() - intval($shizhanEnterTime);
    if ($elapsed >= 25) {
        // 触发陷阱：传送到铁笼
        CharacterModel::updatePosition($charId, 'westway', 'westway/tielong');
        
        // 发送陷阱消息
        require_once DAEMON_PATH . 'MessageDaemon.php';
        MessageDaemon::sendToPlayer($charId, 
            HTML_HIRED . '忽然听到一阵古怪的石头滚动声。' . HTML_NOR . "\n" .
            HTML_HIYEL . '突然，有一只手把你从背后抓了起来，扔入一个铁笼子里。' . HTML_NOR, 
            'self_event'
        );
        
        // 广播到石栈道房间
        MessageDaemon::broadcastToRoom('westway/shizhan', 
            HTML_HIYEL . "{$char['name']}突然被一只手从背后抓了起来，消失在了空气中。" . HTML_NOR, 
            $charId
        );
        
        // 广播到铁笼房间
        MessageDaemon::broadcastToRoom('westway/tielong', 
            HTML_HIYEL . "{$char['name']}被扔入了铁笼子里。" . HTML_NOR, 
            $charId
        );
        
        // 清除进入时间标记
        TempStateHelper::remove($charId, 'shizhan_enter_time');
        
        // 设置陷阱冷却（原始LPC: call_out("reg", 300)，300秒后重置陷阱）
        $cooldownEnd = time() + 300;
        Database::execute(
            "INSERT INTO variables (var_key, value) VALUES ('shizhan_trap_cooldown', ?) 
             ON DUPLICATE KEY UPDATE value = ?",
            [$cooldownEnd, $cooldownEnd]
        );
        
        // 设置flash消息
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'content' => HTML_HIRED . '你被扔入了一个铁笼子里！' . HTML_NOR . "\n" .
                         HTML_CYN . '你可以尝试扳开(break)铁笼。' . HTML_NOR,
            'timestamp' => time()
        ];
        
        // 更新角色信息（位置已变）
        $char = CharacterModel::find($charId);
    }
}

// ★ 石栈道陷阱冷却重置检查（还原原始LPC: shizhan.c reg() 机制）
// 冷却时间结束后，清除冷却标记，允许陷阱再次触发
$trapCooldown = Database::queryOne("SELECT value FROM variables WHERE var_key = 'shizhan_trap_cooldown'");
if ($trapCooldown && time() >= intval($trapCooldown['value'])) {
    Database::execute("DELETE FROM variables WHERE var_key = 'shizhan_trap_cooldown'");
}

// ★ 状态效果处理（中毒、酒醉等）
// 每次动作都处理一次状态效果，确保非战斗场景也能生效
require_once HELPER_PATH . 'StatusEffectHelper.php';
$statusMessages = StatusEffectHelper::processRoundEffects($charId);
if (!empty($statusMessages)) {
    require_once DAEMON_PATH . 'MessageDaemon.php';
    foreach ($statusMessages as $msg) {
        MessageDaemon::sendToPlayer($charId, $msg, 'chat');
    }
    // 状态可能变化了（比如昏迷了），重新加载角色信息
    $char = CharacterModel::find($charId);
}

// 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
if (random_int(1, 100) <= 20) {
    require_once DAEMON_PATH . 'SleepDaemon.php';
    SleepDaemon::pulse();
}

// ★ 睡眠状态拦截（参考原版 disable_player 机制）
// 如果玩家正在睡眠中，拦截所有非 sleep 动作
if (!empty($char['sleep_state']) && $char['sleep_state'] == 1) {
    $sleepEndTime = intval($char['sleep_end_time'] ?? 0);
    $now = time();
    
    if ($now < $sleepEndTime) {
        // 睡眠未到期：检查是否是 sleep 命令（带其他玩家名字）
        // 这种情况下允许通过，以支持双人睡眠的接受邀请
        $param = $_GET['param'] ?? $_POST['param'] ?? '';
        $isSleepAccept = ($action === 'sleep' || $action === '休息') && !empty($param);
        
        if (!$isSleepAccept) {
            // 拒绝所有操作
            $remaining = $sleepEndTime - $now;
            $result = ['success' => false, 'message' => "你正在<睡梦中>，还需等待 {$remaining} 秒才能醒来。"];
            
            // 跳过后续动作分发，直接走输出流程
            $action = '__sleeping__';
        }
    } else {
        // 睡眠已到期：自动唤醒
        if (function_exists('wakeup_player')) {
            wakeup_player($charId);
            // 重新加载角色信息（唤醒后状态已变）
            $char = CharacterModel::find($charId);
        }
    }
}

// ★ 昏迷状态拦截
// 如果玩家正在昏迷中，拦截所有操作
if (!empty($char['unconscious_state']) && $char['unconscious_state'] == 1) {
    $unconsciousEndTime = intval($char['unconscious_end_time'] ?? 0);
    $now = time();
    
    if ($now < $unconsciousEndTime) {
        // 昏迷未到期：也处理一次状态效果（让酒醉值等持续下降）
        require_once HELPER_PATH . 'StatusEffectHelper.php';
        $statusMessages = StatusEffectHelper::processRoundEffects($charId);
        if (!empty($statusMessages)) {
            require_once DAEMON_PATH . 'MessageDaemon.php';
            foreach ($statusMessages as $msg) {
                MessageDaemon::sendToPlayer($charId, $msg, 'chat');
            }
        }
        // 重新加载角色信息（状态可能变化了）
        $char = CharacterModel::find($charId);
        
        // 再次检查是否还在昏迷
        if (!empty($char['unconscious_state']) && $char['unconscious_state'] == 1) {
            $remaining = intval($char['unconscious_end_time']) - time();
            if ($remaining > 0) {
                $result = ['success' => false, 'message' => "你正在<昏迷>中，还需等待 {$remaining} 秒才能醒来。"];
                $action = '__unconscious__';
            }
        }
    } else {
        // 昏迷已到期：自动苏醒
        if (function_exists('wakeup_unconscious_player')) {
            wakeup_unconscious_player($charId);
            $char = CharacterModel::find($charId);
        }
    }
}

// ★ 发呆状态拦截
// 如果玩家正在发呆中，允许部分操作但显示发呆提示
if (!empty($char['daze_state']) && $char['daze_state'] == 1) {
    $dazeEndTime = intval($char['daze_end_time'] ?? 0);
    $now = time();
    
    if ($now < $dazeEndTime) {
        $remaining = $dazeEndTime - $now;
        $result = ['success' => false, 'message' => "你正<发呆>中，还需等待 {$remaining} 秒才能回过神来。"];
        $action = '__dazing__';
    } else {
        // 发呆已到期：自动清醒
        if (function_exists('snap_out_of_daze')) {
            snap_out_of_daze($charId);
            $char = CharacterModel::find($charId);
        }
    }
}

// ★ 特殊处理：天王披风传送（记录/忘记/传送）
$skipDispatch = false;
if ($action === 'tianwang_teleport') {
    $param = $_GET['param'] ?? $_POST['param'] ?? '';
    $result = handleTianwangTeleport($charId, $param, $char);
    $skipDispatch = true;
}

// ★ 特殊处理：阅读取经指南，跳转到专门的阅读页面
if ($action === 'read') {
    $param = $_GET['param'] ?? $_POST['param'] ?? '';
    if ($param === 'book_qujing') {
        header("Location: read_book.php?item_id=book_qujing&page=0");
        exit;
    }
}

// 特殊处理：房间动作（如 wa qiuyin, diao 等）
// 这些命令可能包含空格，需要特殊解析
if ($skipDispatch) {
    // 已由特殊处理器处理，跳过
} elseif ($action === '__sleeping__') {
    // 睡眠拦截：不做任何操作，直接进入输出流程
} elseif ($action === '__unconscious__') {
    // 昏迷拦截：不做任何操作，直接进入输出流程
} elseif ($action === '__dazing__') {
    // 发呆拦截：不做任何操作，直接进入输出流程
} else {
    // 全局异常处理：捕获所有动作执行中的错误
    try {
        if (strpos($action, ' ') !== false) {
            // 解析命令和参数
            $parts = explode(' ', trim($action), 2);
            $cmd = $parts[0];
            $param = isset($parts[1]) ? $parts[1] : '';
            $actualAction = $cmd;
            
            // 尝试执行对应的命令函数
            $funcName = 'cmd_' . $cmd;
            if ($cmd === 'jump') {
                // 特殊处理:jump 命令需要优先检查房间动作(如跳瀑布、跳桥等)
                $roomActionResult = ActionRouter::handleCustomAction($charId, $cmd, $param);
                // 调试输出
                $debugInfo = [
                    'charId' => $charId,
                    'cmd' => $cmd,
                    'param' => $param,
                    'roomId' => $_GET['room'] ?? $_POST['room'] ?? 'unknown',
                    'roomActionResult' => $roomActionResult
                ];
                error_log('[DEBUG jump] ' . print_r($debugInfo, true));
                            
                if ($roomActionResult['success']) {
                    // 房间动作执行成功，使用其结果
                    $result = $roomActionResult;
                } elseif ($param === 'bridge' || $param === '桥') {
                    // 八卦桥迷宫跳桥
                    $currentRoom = $char['current_room'] ?? '';
                    if (preg_match('/qujing\/wuzhuang\/wzgmaze\d$/', $currentRoom)) {
                        require_once DAEMON_PATH . 'WzgmazeHandler.php';
                        $result = WzgmazeHandler::handleJumpBridge($charId, $currentRoom);
                    } elseif (function_exists($funcName)) {
                        $result = $funcName($charId, $param);
                    } else {
                        $result = ['success' => false, 'message' => '无法执行此动作'];
                    }
                } elseif (function_exists($funcName)) {
                    $result = $funcName($charId, $param);
                } else {
                    $result = ['success' => false, 'message' => '无法执行此动作'];
                }
            } elseif ($cmd === 'follow') {
                // ★ 海底迷宫：跟随小金鱼引路
                if ($param === 'goldfish' || $param === 'xiaojinyu' || $param === '小金鱼') {
                    $currentRoom = $char['current_room'] ?? '';
                    if ($currentRoom === 'dntg/donghai/mazee') {
                        require_once DAEMON_PATH . 'DonghaiMazeHandler.php';
                        $result = DonghaiMazeHandler::handleFollowGoldfish($charId, $char);
                    } else {
                        $result = ['success' => false, 'message' => '这里没有小金鱼可以跟随。'];
                    }
                }
                // ★ 蟠桃会：跟随太白金星上天
                elseif ($param === 'taibai' || $param === '太白金星') {
                    require_once DAEMON_PATH . 'PantaohuiHandler.php';
                    $result = PantaohuiHandler::handleFollowTaibai($charId, $char);
                }
                elseif (function_exists($funcName)) {
                    $result = $funcName($charId, $param);
                } else {
                    $result = ActionRouter::handleCustomAction($charId, $cmd, $param);
                }
            } elseif (function_exists($funcName)) {
                $result = $funcName($charId, $param);
            } else {
                // 如果命令不存在，尝试通用处理
                $result = ActionRouter::handleCustomAction($charId, $cmd, $param);
            }
        } else {
            // 检查是否有通过 URL 参数传递的 param 或 direction
            $urlParam = $_GET['param'] ?? $_POST['param'] ?? $_GET['direction'] ?? $_POST['direction'] ?? '';
            
            // 检查是否需要唤醒玩家（非睡眠命令时）
            if ($action !== 'sleep' && $action !== '休息') {
                if (function_exists('check_and_wakeup')) {
                    check_and_wakeup($charId);
                }
            }
            
            // 使用路由器分发到对应的处理器
            $result = ActionRouter::dispatch($charId, $action, $urlParam, $char);
        }
    } catch (\Exception $e) {
        error_log("[action.php] Exception in action=$action: " . $e->getMessage());
        error_log("[action.php] Stack trace: " . $e->getTraceAsString());
        $result = [
            'success' => false,
            'message' => '执行动作时发生错误：' . $e->getMessage(),
            'error' => $e->getMessage()
        ];
    }
}

// 保存游戏动作结果消息到 message_queue，供 chat.php 轮询显示
// 不管成功还是失败，只要有消息就保存
if ($char) {
    // 银行存取款消息使用 'bank' 类型单独处理
    $bankActions = ['deposit', 'withdraw', 'jicun', 'qu'];
    // 不写入 message_queue 的动作：帮助、属性查看、物品操作等纯本地展示
    // 注意：hit/k/combat 已从列表中移除，战斗回合消息现在会保存到 message_queue
    $neverSaveActions = ['help', 'inventory', 'i', 'score', 'sc',
                         'look', 'look_self', 'get', 'drop', 'wear', 'wield', 'unwield', 'remove',
                         'pick', 'trade', 'talk', 'suanming', 'fuyuan', 'follow', 'emote'];

    // 如果命令已经自行处理了消息广播（如ask），则跳过
    if (!empty($result['skip_queue'])) {
        // 已经在命令中通过MessageDaemon广播，不需要再次保存
    } else {
        // 优先使用 output 字段（广播消息），其次使用 message 字段
        $message = $result['output'] ?? $result['message'] ?? '';
        if (!empty($message)) {
            // 保留 ANSI 颜色码，只去除 HTML 标签（防止 XSS）
            $tempMessage = str_replace(['<br>', '<br/>', '<br />'], "\n", $message);
            $cleanMessage = strip_tags($tempMessage);
            $cleanMessage = html_entity_decode($cleanMessage, ENT_QUOTES, 'UTF-8');
            $cleanMessage = trim($cleanMessage);

            if (!empty($cleanMessage)) {
                $savedMsgId = 0;
                if (in_array($actualAction, $bankActions)) {
                    // 银行类操作写入 bank 类型
                    $savedMsgId = MessageDaemon::queueMessageToSelf($charId, $cleanMessage, 'bank');
                } elseif (in_array($actualAction, ['hit', 'k', 'combat', 'npc_attack'])) {
                    // 战斗回合消息写入 combat 类型
                    $savedMsgId = MessageDaemon::queueMessageToSelf($charId, $cleanMessage, 'combat');
                    
                    // 存储伤害数据到session（用于飘血显示）
                    if (isset($result['damage']) || isset($result['player_damage'])) {
                        $_SESSION['combat_damage_' . $charId] = [
                            'damage' => intval($result['damage'] ?? 0),  // 玩家造成的伤害
                            'player_damage' => intval($result['player_damage'] ?? 0),  // 玩家受到的伤害
                            'timestamp' => time()
                        ];
                    }
                } elseif (!in_array($actualAction, $neverSaveActions)) {
                    // 所有其他有意义的动作结果写入 self_event，让 chat.php 能看到
                    // 成功和失败消息都写入
                    $savedMsgId = MessageDaemon::queueMessageToSelf($charId, $cleanMessage, 'self_event');
                }
                // 保存消息ID到session，让room.php知道刚执行了一个动作
                // room.php会使用这个ID来避免新消息被轮询跳过
                if ($savedMsgId > 0) {
                    $_SESSION['last_ask_message_id'] = $savedMsgId;
                }
            }
        }
    }
}

// 特殊处理：移动命令的离开/到达消息广播
if ($result['success'] && isset($result['type']) && $result['type'] === 'move' && $char) {
    // 广播离开消息到原房间
    if (!empty($result['leave_message']) && !empty($result['old_room'])) {
        $oldRoomId = $result['old_room']['room_id'];
        MessageDaemon::broadcastToRoom($oldRoomId, $result['leave_message'], intval($charId));
    }
    
    // 广播到达消息到新房间
    if (!empty($result['arrive_message']) && !empty($result['new_room'])) {
        $newRoomId = $result['new_room']['room_id'];
        MessageDaemon::broadcastToRoom($newRoomId, $result['arrive_message'], intval($charId));
        
        // 向当前玩家自己发送到达消息（self_event类型，会覆盖其他玩家的到达消息）
        $selfArriveMsg = HTML_HIYEL . "你来到了" . $result['new_room']['name'] . "。" . HTML_NOR . "<br>";
        MessageDaemon::queueMessageToSelf(intval($charId), $selfArriveMsg, 'self_event');
        
        // 保存最后消息ID到session，供room.php使用
        $lastMsg = Database::queryOne(
            'SELECT MAX(id) as max_id FROM message_queue WHERE char_id = ?',
            [intval($charId)]
        );
        $_SESSION['last_move_message_id'] = intval($lastMsg['max_id'] ?? 0);
    }
}

// 特殊处理：逃跑成功时的离开/到达消息广播（参考原始LPC go.c:85-87）
if ($result['success'] && isset($result['type']) && $result['type'] === 'flee_success' && $char) {
    // 广播离开消息到原房间（"某某往北落荒而逃了。"）
    if (!empty($result['leave_message']) && !empty($result['old_room'])) {
        $oldRoomId = $result['old_room']['room_id'];
        MessageDaemon::broadcastToRoom($oldRoomId, $result['leave_message'], intval($charId));
    }
    
    // 广播到达消息到新房间（"某某跌跌撞撞地跑了过来，模样有些狼狈。"）
    if (!empty($result['arrive_message']) && !empty($result['new_room'])) {
        $newRoomId = $result['new_room']['room_id'];
        MessageDaemon::broadcastToRoom($newRoomId, $result['arrive_message'], intval($charId));
        
        // 保存最后消息ID到session，供room.php使用
        $lastMsg = Database::queryOne(
            'SELECT MAX(id) as max_id FROM message_queue WHERE char_id = ?',
            [intval($charId)]
        );
        $_SESSION['last_move_message_id'] = intval($lastMsg['max_id'] ?? 0);
    }
    
    // 逃跑后自动跳转到新房间页面
    $_SESSION['flash_message'] = [
        'type' => 'success',
        'content' => $result['message'] ?? '',
        'timestamp' => time(),
        'redirect' => 'room.php'
    ];
}

// 存储消息到session供下次显示
// 失败消息优先显示，成功消息5秒内不重复覆盖
// skip_queue 只跳过消息队列保存，不跳过 flash_message 显示
// skip_flash 只跳过 flash_message 显示，不跳过消息队列保存
if (!isset($result['skip_flash']) && (!$result['success'] || !isset($_SESSION['flash_message']) || time() - ($_SESSION['flash_message']['timestamp'] ?? 0) > 5)) {
    $_SESSION['flash_message'] = [
        'type' => $result['success'] ? 'success' : 'error',
        'content' => $result['output'] ?? $result['message'] ?? '',
        'timestamp' => time()
    ];
    
    // 如果消息已保存到队列，立即设置 last_flash_msg_id 供 chat.php 跳过已显示的消息
    // 这样可以避免竞争条件：chat轮询可能在room.php显示消息前就获取到消息
    // 添加过期时间，确保消息最终能在chat显示
    $lastAskMsgId = $_SESSION['last_ask_message_id'] ?? 0;
    if ($lastAskMsgId > 0) {
        $_SESSION['last_flash_msg_id'] = $lastAskMsgId;
        $_SESSION['last_flash_msg_expire'] = time() + 2; // 2秒过期
    }
}

// 检测是否是 AJAX/fetch 请求
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// 额外检测 fetch API 请求（fetch 默认不发送 X-Requested-With 头部）
$acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
if (!$isAjax && strpos($acceptHeader, 'application/json') !== false) {
    $isAjax = true;
}

// AJAX 请求优先返回 JSON（即使结果包含 html 字段）
if ($isAjax) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    
    // 调试：记录实际输出前的缓冲内容
    $previousOutput = ob_get_contents();
    if (!empty($previousOutput)) {
        error_log("[action.php] 输出缓冲中有内容: " . substr($previousOutput, 0, 500));
    }
    
    $json = json_encode(array_merge($result, [
        'saved_message_id' => $savedMsgId ?? 0
    ]), JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = json_encode(['success' => false, 'message' => 'JSON 编码失败', 'error' => json_last_error_msg()]);
    }
    // 调试：记录返回结果
    error_log("[action.php] action=$action, result=" . json_encode($result, JSON_UNESCAPED_UNICODE));
    echo $json;
    exit;
}

// 非 AJAX 请求：如果返回了HTML页面，直接输出完整页面
if (isset($result['html']) && !empty($result['html'])) {
    // 如果返回的 HTML 已经包含 <!DOCTYPE html> 或 <html> 标签，说明是完整页面
    if (stripos($result['html'], '<!DOCTYPE') !== false || stripos($result['html'], '<html') !== false) {
        // 直接输出，不嵌套
        echo $result['html'];
        exit;
    }

    // 否则添加 light-theme CSS 样式
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>技能学习</title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <style>
        body {
            max-width: 800px;
            margin: 20px auto;
            padding: 20px;
            background-color: #1a1a1a;
            color: #ffffff;
        }
        .container {
            background-color: #2d2d2d;
            border-radius: 8px;
            padding: 20px;
            margin: 10px 0;
        }
        hr {
            border: none;
            border-top: 1px solid #555555;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        ' . $result['html'] . '
    </div>
</body>
</html>';
    echo $html;
    exit;
}

// 普通请求：重定向回房间页面
if (isset($result['redirect'])) {
    // 如果结果中指定了重定向URL，使用它
    $redirectUrl = $result['redirect'];
} else if ($char) {
    // 检查是否来自战斗页面（fight.php）
    $fromFight = isset($_GET['from']) && $_GET['from'] === 'fight';
    // 检查是否是战斗开始动作（kill/fight 命令返回 combat_start）
    $isCombatStart = isset($result['type']) && $result['type'] === 'combat_start';
    
    if (($fromFight || $isCombatStart) && CombatDaemon::isInCombat($charId)) {
        // 战斗中（来自战斗页面 或 刚发起战斗），重定向回战斗页面
        $redirectUrl = 'fight.php';
    } else {
        // 否则使用角色当前房间
        $redirectUrl = room_url($char['current_area'], $char['current_room']);
    }
} else {
    $redirectUrl = 'room.php';
}

// 清除输出缓冲并重定向
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Location: ' . $redirectUrl);
exit;

/**
 * 天王披风传送处理（支持最多5个记录位置）
 * @param int $charId 角色ID
 * @param string $param 参数：list/save/forget/teleport + 可选 id
 * @param array $char 角色信息
 * @return array
 */
function handleTianwangTeleport(int $charId, string $param, array $char): array {
    // 检查是否拥有天王披风
    $hasCoat = Database::queryOne(
        "SELECT 1 FROM character_inventory WHERE char_id = ? AND item_id = 'tianwang_coat' LIMIT 1",
        [$charId]
    );
    if (!$hasCoat) {
        return ['success' => false, 'message' => '你没有天王披风，无法使用传送功能。'];
    }

    // 解析参数：可能是 "action:id" 格式，如 "save"、"forget:2"、"teleport:3"
    $action = $param;
    $recordId = 0;
    if (strpos($param, ':') !== false) {
        list($action, $recordId) = explode(':', $param, 2);
        $recordId = intval($recordId);
    }

    $maxRecords = 5; // 最多5个记录

    if ($action === 'list' || $action === 'info') {
        // 列出所有记录
        $records = Database::queryAll(
            "SELECT id, area, room_id, room_name, saved_at FROM tianwang_teleport WHERE char_id = ? ORDER BY saved_at DESC",
            [$charId]
        );
        return [
            'success' => true,
            'records' => $records ?: [],
            'max' => $maxRecords
        ];
    }

    if ($action === 'save') {
        // 检查是否已达到上限
        $count = Database::queryOne(
            "SELECT COUNT(*) as cnt FROM tianwang_teleport WHERE char_id = ?",
            [$charId]
        );
        if ($count['cnt'] >= $maxRecords) {
            return ['success' => false, 'message' => "天王披风最多只能记住 {$maxRecords} 个位置，请先「忘记」某个位置后再记录。"];
        }

        // 检查是否已记录过同一位置
        $area = $char['current_area'] ?? 'city';
        $roomId = $char['current_room'] ?? 'city/kezhan';
        $exists = Database::queryOne(
            "SELECT id FROM tianwang_teleport WHERE char_id = ? AND area = ? AND room_id = ?",
            [$charId, $area, $roomId]
        );
        if ($exists) {
            return ['success' => false, 'message' => '天王披风已经记住了此地，无需重复记录。'];
        }

        $room = Database::queryOne(
            "SELECT name FROM rooms WHERE area = ? AND room_id = ?",
            [$area, $roomId]
        );
        $roomName = $room['name'] ?? $roomId;

        Database::execute(
            "INSERT INTO tianwang_teleport (char_id, area, room_id, room_name) VALUES (?, ?, ?, ?)",
            [$charId, $area, $roomId, $roomName]
        );

        $newCount = $count['cnt'] + 1;
        return ['success' => true, 'message' => "天王披风已记住此地——「{$roomName}」。（{$newCount}/{$maxRecords}）"];
    }

    if ($action === 'forget') {
        if ($recordId > 0) {
            // 忘记指定ID的记录
            $record = Database::queryOne(
                "SELECT id, room_name FROM tianwang_teleport WHERE id = ? AND char_id = ?",
                [$recordId, $charId]
            );
            if (!$record) {
                return ['success' => false, 'message' => '未找到该记录。'];
            }
            Database::execute("DELETE FROM tianwang_teleport WHERE id = ? AND char_id = ?", [$recordId, $charId]);
            return ['success' => true, 'message' => "已忘记记录的位置「{$record['room_name']}」。"];
        }

        // 忘记全部
        Database::execute("DELETE FROM tianwang_teleport WHERE char_id = ?", [$charId]);
        return ['success' => true, 'message' => '已忘记所有记录的位置。'];
    }

    if ($action === 'teleport') {
        if ($recordId > 0) {
            $record = Database::queryOne(
                "SELECT area, room_id, room_name FROM tianwang_teleport WHERE id = ? AND char_id = ?",
                [$recordId, $charId]
            );
        } else {
            // 未指定ID，使用最新记录
            $record = Database::queryOne(
                "SELECT area, room_id, room_name FROM tianwang_teleport WHERE char_id = ? ORDER BY saved_at DESC LIMIT 1",
                [$charId]
            );
        }

        if (!$record) {
            return ['success' => false, 'message' => '你还没有记录任何位置，请先在目标地点使用「记录」功能。'];
        }

        // 检查是否已在目标位置
        $currentArea = $char['current_area'] ?? 'city';
        $currentRoom = $char['current_room'] ?? 'city/kezhan';
        if ($currentArea === $record['area'] && $currentRoom === $record['room_id']) {
            return ['success' => false, 'message' => '你已经在这里了，无需传送。'];
        }

        // 无视任何限制，直接传送
        $oldArea = $currentArea;
        $oldRoom = $currentRoom;
        CharacterModel::updatePosition($charId, $record['area'], $record['room_id']);

        // 构造传送消息
        $targetRoom = Database::queryOne(
            "SELECT name FROM rooms WHERE area = ? AND room_id = ?",
            [$record['area'], $record['room_id']]
        );
        $targetName = $targetRoom['name'] ?? $record['room_name'];

        log_game('TIANWANG_TELEPORT', "{$char['name']} 使用天王披风从 {$oldArea}/{$oldRoom} 传送到 {$record['area']}/{$record['room_id']}");

        return [
            'success' => true,
            'message' => "天王披风灵光一闪，你已回到「{$targetName}」！",
            'type' => 'move',
            'old_room' => ['room_id' => $oldRoom],
            'new_room' => ['room_id' => $record['room_id'], 'name' => $targetName],
            'leave_message' => "{$char['name']} 披风一展，身形瞬间消失不见。",
            'arrive_message' => "一道金光闪过，{$char['name']} 凭空出现在这里。"
        ];
    }

    return ['success' => false, 'message' => '无效的传送操作。'];
}
