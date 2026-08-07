<?php
/**
 * 聊天页面 - AJAX轮询模式
 * 通过定时AJAX请求获取新消息
 */
// 最优先：禁用所有错误报告，避免破坏JSON响应
error_reporting(0);
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '0');

session_save_path(__DIR__ . '/../sessions');
session_start();

// 启动输出缓冲
ob_start();

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once DAEMON_PATH . 'MessageDaemon.php';

// 要求登录
require_login();

$charId = get_char_id();
$char = CharacterModel::find($charId);

// 检测是否为AJAX请求
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// GET 请求：轮询获取新消息
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'poll') {
    // 清空输出缓冲区
    ob_clean();
    // 禁用错误报告
    error_reporting(0);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    
    try {
        $lastMessageId = intval($_GET['last_id'] ?? 0);
        
        if ($lastMessageId > 0) {
            // 增量加载：只拿比上次更新的消息
            $newMessages = Database::queryAll(
                'SELECT id, message, type as msg_type, from_char_id, created_at FROM message_queue 
                 WHERE char_id = ? AND id > ?
                 ORDER BY id ASC 
                 LIMIT 50',
                [$charId, $lastMessageId]
            );
            $responseLastId = !empty($newMessages) ? end($newMessages)['id'] : $lastMessageId;
        } else {
            // 首次加载：获取最近1小时内的消息（最多50条）
            $newMessages = Database::queryAll(
                'SELECT id, message, type as msg_type, from_char_id, created_at FROM message_queue 
                 WHERE char_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                 ORDER BY id ASC 
                 LIMIT 50',
                [$charId]
            );
            // 如果1小时内没有消息，则获取最近30条消息
            if (empty($newMessages)) {
                $newMessages = Database::queryAll(
                    'SELECT id, message, type as msg_type, from_char_id, created_at FROM message_queue 
                     WHERE char_id = ?
                     ORDER BY id DESC 
                     LIMIT 30',
                    [$charId]
                );
                // 反转消息顺序，让最早的在上面
                $newMessages = array_reverse($newMessages);
            }
            // 获取最大ID，下次只获取之后的新消息
            $maxIdRow = Database::queryOne(
                'SELECT COALESCE(MAX(id), 0) as max_id FROM message_queue WHERE char_id = ?',
                [$charId]
            );
            $responseLastId = $maxIdRow ? intval($maxIdRow['max_id']) : 0;
        }
        
        // 过滤掉可能重复的消息（不影响 last_id）
        $newMessages = array_filter($newMessages, function($msg) use ($charId) {
            // 跳过已在room.php显示过的消息（避免重复），但10秒后允许显示
            $lastFlashMsgId = $_SESSION['last_flash_msg_id'] ?? 0;
            $lastFlashMsgExpire = $_SESSION['last_flash_msg_expire'] ?? 0;
            if ($lastFlashMsgId > 0 && intval($msg['id']) === $lastFlashMsgId && time() < $lastFlashMsgExpire) {
                return false;
            }
            
            // 全局频道（rumor, xyj, es, sldh）需要过滤掉来自自己的消息
            // 因为 broadcastToAll 会发送给所有玩家（包括发送者自己）
            $globalChannels = ['rumor', 'xyj', 'es', 'sldh', 'system', 'global'];
            if (in_array($msg['msg_type'], $globalChannels) && intval($msg['from_char_id']) === $charId) {
                return false;
            }
            
            // 过滤掉来自自己的移动消息（"往XX离开"、"走了过来"）
            $currentChar = CharacterModel::find($charId);
            if ($currentChar && isset($msg['message'])) {
                $charName = preg_quote($currentChar['name'], '/');
                if (preg_match("/{$charName}(往[^。]+离开|走了过来)/", $msg['message'])) {
                    return false;
                }
            }
            
            // 过滤掉某些容易重复的self_event消息（如顺风耳）
            if ($msg['msg_type'] === 'self_event' && strpos($msg['message'] ?? '', '顺风耳告诉你') !== false) {
                return false;
            }
            
            return true;
        });
        $newMessages = array_values($newMessages);
        // 消息已经是 HTML 格式，不需要转换
        
        // 2%概率触发NPC日常聊天心跳（附带在轮询中，避免独立进程）
        if (random_int(1, 100) <= 2) {
            require_once DAEMON_PATH . 'NpcChatDaemon.php';
            NpcChatDaemon::pulse();
        }

        // 2%概率检查并释放过期被困者
        if (mt_rand(1, 100) <= 2) {
            require_once HELPER_PATH . 'FabaoHelper.php';
            FabaoHelper::checkAndReleaseExpired();
        }

        // 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
        if (random_int(1, 100) <= 5) {
            require_once DAEMON_PATH . 'SleepDaemon.php';
            SleepDaemon::pulse();
        }
        
        // 处理修炼轮次推进（打坐/冥思/练功）
        // 每次轮询都检查，确保修炼进度及时更新
        $exerciseResult = null;
        $meditateResult = null;
        $practiceResult = null;
        
        if (!empty($_SESSION['pending_exercising'])) {
            $exerciseCmdFile = __DIR__ . '/../commands/exercise.php';
            if (file_exists($exerciseCmdFile)) {
                require_once $exerciseCmdFile;
                $exerciseResult = processExerciseRound($charId);
            }
        }
        
        if (!empty($_SESSION['pending_meditating'])) {
            $meditateCmdFile = __DIR__ . '/../commands/meditate.php';
            if (file_exists($meditateCmdFile)) {
                require_once $meditateCmdFile;
                $meditateResult = processMeditateRound($charId);
            }
        }
        
        if (!empty($_SESSION['pending_practicing'])) {
            $practiceCmdFile = __DIR__ . '/../commands/practice.php';
            if (file_exists($practiceCmdFile)) {
                require_once $practiceCmdFile;
                $practiceResult = processPracticeRound($charId);
            }
        }
        
        if (!empty($_SESSION['pending_xiudao'])) {
            $xiudaoCmdFile = __DIR__ . '/../commands/xiudao.php';
            if (file_exists($xiudaoCmdFile)) {
                require_once $xiudaoCmdFile;
                $xiudaoResult = executeXiudaoRound($charId);
            }
        }
        
        // 如果有修炼结果，添加到消息中
        if ($exerciseResult && !empty($exerciseResult['message'])) {
            $newMessages[] = [
                'id' => intval(microtime(true) * 1000),
                'message' => $exerciseResult['message'],
                'msg_type' => 'self_event',
                'from_char_id' => $charId,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
        if ($meditateResult && !empty($meditateResult['message'])) {
            $newMessages[] = [
                'id' => intval(microtime(true) * 1000) + 1,
                'message' => $meditateResult['message'],
                'msg_type' => 'self_event',
                'from_char_id' => $charId,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
        if ($practiceResult && !empty($practiceResult['message'])) {
            $newMessages[] = [
                'id' => intval(microtime(true) * 1000) + 2,
                'message' => $practiceResult['message'],
                'msg_type' => 'self_event',
                'from_char_id' => $charId,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
        if (!empty($xiudaoResult) && !empty($xiudaoResult['message'])) {
            $newMessages[] = [
                'id' => intval(microtime(true) * 1000) + 3,
                'message' => $xiudaoResult['message'],
                'msg_type' => 'self_event',
                'from_char_id' => $charId,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
        // 检查钓鱼状态，有新消息时推送
        require_once DAEMON_PATH . 'FishHandler.php';
        $fishingResult = FishHandler::pollFishingStatus($charId);
        if ($fishingResult && !empty($fishingResult['message'])) {
            $newMessages[] = [
                'id' => intval(time() * 1000000 + mt_rand(0, 999999)),
                'message' => $fishingResult['message'],
                'msg_type' => $fishingResult['msg_type'] ?? 'self_event',
                'from_char_id' => $charId,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
        // 检查并推进还阳剧情
        if (!empty($_SESSION['reincarnate_active'])) {
            require_once DAEMON_PATH . 'ReincarnateHandler.php';
            ReincarnateHandler::checkAndProgress($charId);
        }
        
        // 重新获取角色信息（因为还阳剧情可能会更新房间）
        $char = CharacterModel::find($charId);
        
        // 检查玩家当前是否在战斗中（PVP/PVE实时同步）
        require_once DAEMON_PATH . 'CombatDaemon.php';
        $inCombat = CombatDaemon::isInCombat($charId);
        $combatStatus = null;
        $combatTargetName = '';
        $combatTargetId = '';
        $combatEnded = false;   // 对手已投降/逃跑，我方战斗也应该结束
        if ($inCombat) {
            $combatStatus = CombatDaemon::getCombatStatus($charId);
            if ($combatStatus) {
                $combatTargetName = $combatStatus['target_name'] ?? '';
                $combatTargetId = strval($combatStatus['target_id'] ?? '');
                // 如果 target_name 为空（跨session拉入），从DB补齐
                if (empty($combatTargetName) && ($combatStatus['target_type'] ?? '') === 'player') {
                    $tgt = Database::queryOne("SELECT name FROM characters WHERE id = ?", [intval($combatStatus['target_id'])]);
                    $combatTargetName = $tgt['name'] ?? '';
                }
                // PVP：检测对手是否已投降/逃跑（目标玩家的active_combats记录已消失）
                if (($combatStatus['target_type'] ?? '') === 'player' && !empty($combatStatus['target_id'])) {
                    $oppStillFighting = Database::queryOne(
                        "SELECT id FROM active_combats WHERE char_id = ? AND target_id = ? LIMIT 1",
                        [intval($combatStatus['target_id']), $charId]
                    );
                    if (!$oppStillFighting) {
                        $combatEnded = true;
                    }
                }
            }
        }
        
        // 检查是否有已接受的切磋请求（用于自动跳转）
        $fightAccepted = null;
        $fightRequest = Database::queryOne(
            'SELECT id, from_character_id, to_character_id, status FROM fight_requests 
             WHERE from_character_id = ? AND status = "accepted"
             ORDER BY created_at DESC LIMIT 1',
            [$charId]
        );
        if ($fightRequest) {
            $fightAccepted = intval($fightRequest['to_character_id']);
        }
        
        // 调试：查询所有相关请求，看看状态到底是什么
        $allRequests = Database::queryAll(
            'SELECT id, from_character_id, to_character_id, status, created_at FROM fight_requests 
             WHERE from_character_id = ? OR to_character_id = ?
             ORDER BY created_at DESC LIMIT 10',
            [$charId, $charId]
        );
        
        // 调试：记录查询结果
        error_log("[chat.php poll] char_id=$charId");
        error_log("[chat.php poll] fight_accepted=" . var_export($fightAccepted, true));
        error_log("[chat.php poll] fight_request=" . var_export($fightRequest, true));
        error_log("[chat.php poll] all_requests=" . var_export($allRequests, true));
        
        echo json_encode([
            'success' => true,
            'messages' => $newMessages,
            'last_id' => $responseLastId,
            'current_room' => $char['current_room'],
            'current_area' => $char['current_area'],
            'fight_accepted' => $fightAccepted,
            'in_combat' => $inCombat,
            'combat_ended' => $combatEnded,
            'combat_target_name' => $combatTargetName,
            'combat_target_id' => $combatTargetId,
            'test_debug' => 'hello_from_chat_php',  // 调试标记，确认代码执行到这里了
            'debug_all_requests' => $allRequests  // 调试：返回所有相关请求，方便前端查看
        ]);
    } catch (Exception $e) {
        error_log("chat.php poll error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => '获取消息失败: ' . $e->getMessage()
        ]);
    }
    exit;
}

// GET 请求：获取表情列表
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'emote_list') {
    ob_clean();
    error_reporting(0);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    try {
        // 查询所有活跃表情
        $emotes = Database::queryAll(
            'SELECT id, command, description FROM emotes WHERE is_active = 1 ORDER BY sort_order, command',
            []
        );

        // 分类映射
        $categories = [
            'friendly' => ['name' => '友好', 'commands' => ['smile', 'hug', 'kiss', 'bow', 'wave', 'greet', 'nod', 'thank', 'comfort', 'encourage', 'cheer']],
            'emotion'  => ['name' => '情绪', 'commands' => ['laugh', 'cry', 'angry', 'shy', 'fear', 'sigh', 'worry', 'happy', 'sad']],
            'special'  => ['name' => '特殊', 'commands' => ['qmarry', 'admire', 'congrats', 'salute', 'pray', 'meditate']],
            'funny'    => ['name' => '搞怪', 'commands' => ['hammer', 'slap', 'tease', 'escape', 'faint', 'yawn', 'snore', 'whistle', 'wink']],
        ];

        // 构建分类结果
        $categorized = [];
        foreach ($categories as $key => $cat) {
            $categorized[$key] = ['key' => $key, 'name' => $cat['name'], 'emotes' => []];
        }
        $categorized['other'] = ['key' => 'other', 'name' => '其他', 'emotes' => []];

        // 建立command到分类的反向映射
        $commandToCategory = [];
        foreach ($categories as $key => $cat) {
            foreach ($cat['commands'] as $cmd) {
                $commandToCategory[$cmd] = $key;
            }
        }

        // 将表情分配到各分类
        foreach ($emotes as $emote) {
            $cmd = $emote['command'];
            $catKey = isset($commandToCategory[$cmd]) ? $commandToCategory[$cmd] : 'other';
            $categorized[$catKey]['emotes'][] = [
                'command'     => $emote['command'],
                'description' => $emote['description'],
            ];
        }

        // 移除空分类，并重新索引
        $resultCategories = [];
        foreach ($categorized as $cat) {
            if (!empty($cat['emotes'])) {
                $resultCategories[] = $cat;
            }
        }

        // 查询所有在线玩家（排除自己）
        $players = Database::queryAll(
            'SELECT c.id, c.name FROM characters c WHERE c.id != ? AND c.online = 1 ORDER BY c.name',
            [$charId]
        );

        echo json_encode([
            'success'    => true,
            'categories' => $resultCategories,
            'players'    => $players,
        ]);
    } catch (Exception $e) {
        error_log('chat.php emote_list error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error'   => '获取表情列表失败: ' . $e->getMessage(),
        ]);
    }
    exit;
}

// POST 发送消息
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chatMessage = trim($_POST['message'] ?? '');
    $channel = $_POST['channel'] ?? 'chat';

    if (!empty($chatMessage)) {
        $userId = $char['user_id'];
        $isBlocked = Database::queryOne('SELECT id FROM user_blocks WHERE user_id = ? AND block_type = ?', [$userId, 'chat']);
        if ($isBlocked) {
            if ($isAjax) {
                ob_clean();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => '你的聊天功能已被封禁']);
            } else {
                header("Location: talk.php?error=blocked");
            }
            exit;
        }

        $roomId = $char['current_room'];

        // 构建聊天消息并广播
        if ($channel === 'rumor') {
            $broadcastMessage = HTML_HIMAG . '【谣言】' . '某人' . '：' . $chatMessage . HTML_NOR;
            MessageDaemon::broadcastToAll($broadcastMessage, intval($charId), 'rumor');
        } elseif ($channel === 'xyj') {
            $broadcastMessage = HTML_HIRED . '【西游记】' . HTML_HIGRN . $char['name'] . '：' . $chatMessage . HTML_NOR;
            MessageDaemon::broadcastToAll($broadcastMessage, intval($charId), 'xyj');
        } elseif ($channel === 'es') {
            $broadcastMessage = HTML_HIGRN . '【潭际闲聊】' . HTML_HICYN . $char['name'] . '：' . $chatMessage . HTML_NOR;
            MessageDaemon::broadcastToAll($broadcastMessage, intval($charId), 'es');
        } elseif ($channel === 'sldh') {
            $broadcastMessage = HTML_HIMAG . '【水陆大会】' . HTML_HIWHT . $char['name'] . '：' . $chatMessage . HTML_NOR;
            MessageDaemon::broadcastToAll($broadcastMessage, intval($charId), 'sldh');
        } else {
            $broadcastMessage = HTML_CYN . $char['name'] . '说道：' . $chatMessage . HTML_NOR;
            // 房间频道不排除发送者自己，让发送者也能收到消息
            MessageDaemon::broadcastToRoom($roomId, $broadcastMessage, 0, 'chat');
        }
        
        log_game('CHAT', "{$char['name']} 在{$channel}频道说: {$chatMessage}");
        
        if ($isAjax) {
            // 清空输出缓冲区，避免 BOM 字符或其他输出破坏 JSON
            ob_clean();
            // AJAX 请求：返回 JSON，前端自行显示自己的消息
            header('Content-Type: application/json; charset=utf-8');
            $selfMessage = '';
            if ($channel === 'rumor') {
                $selfMessage = HTML_HIMAG . '【谣言】你：' . $chatMessage . HTML_NOR;
            } elseif ($channel === 'xyj') {
                $selfMessage = HTML_HIRED . '【西游记】你：' . HTML_HIGRN . $chatMessage . HTML_NOR;
            } elseif ($channel === 'es') {
                $selfMessage = HTML_HIGRN . '【潭际闲聊】你：' . HTML_HICYN . $chatMessage . HTML_NOR;
            } elseif ($channel === 'sldh') {
                $selfMessage = HTML_HIMAG . '【水陆大会】你：' . HTML_HIWHT . $chatMessage . HTML_NOR;
            } else {
                $selfMessage = HTML_CYN . '你说道：' . $chatMessage . HTML_NOR;
            }
            echo json_encode([
                'success' => true,
                'self_message' => $selfMessage,
                'channel' => $channel,
                'msg_type' => ($channel === 'chat') ? 'room' : $channel
            ]);
            exit;
        }

        // 非AJAX：重定向避免重复提交
        header("Location: talk.php");
        exit;
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => '消息不能为空']);
        exit;
    }
}

// 频道颜色映射
$channelColors = [
    'chat' => '#33cc33',
    'rumor' => '#cc33cc',
    'system' => '#cc3333',
    'xyj' => '#ff0000',
    'es' => '#33cc33',
    'sldh' => '#cc33cc',
    'room_event' => '#ffcc00',
    'room' => '#ffcc00',
    'self_event' => '#aaa',
    'combat' => '#ff6633',    // 战斗消息 - 橙红色
    'global' => '#ff9900',
    'private' => '#ff69b4',
    'npc_dialog' => '#00cc00',  // NPC对话 - 绿色
    'npc_action' => '#cc66cc',  // NPC动作 - 紫色
    'npc_chat'   => '#66ccff',  // NPC日常聊天 - 浅蓝色
];

// 频道名称映射
$channelNames = [
    'chat' => '闲聊',
    'rumor' => '谣言',
    'system' => '系统',
    'xyj' => '西游记',
    'es' => '潭际闲聊',
    'sldh' => '水陆大会',
    'room_event' => '房间事件',
    'room' => '房间',
    'self_event' => '动作',
    'combat' => '战斗',
    'global' => '全局',
    'private' => '私聊',
    'npc_dialog' => 'NPC对话',
    'npc_action' => 'NPC动作',
    'npc_chat'   => 'NPC闲聊',
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
    <title>聊天_西游记mud</title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/chat.css">
</head>
<body>
【聊天室】在这里你可以和其他玩家交流。
<span class="ws-status connecting" id="wsStatus">
    <span class="ws-status-dot"></span>
    <span class="ws-status-text">连接中...</span>
</span>
<button type="button" id="resetBtn" style="font-size: 12px; margin-left: 10px;">重置消息</button>
<br>
<hr>

<!-- 频道过滤按钮 -->
<div class="chat-filter-bar" id="filterBar">
    <button class="chat-filter-btn active" data-channel="">全部</button>
    <button class="chat-filter-btn" data-channel="rumor" style="color: #cc33cc;">谣言</button>
    <button class="chat-filter-btn" data-channel="xyj" style="color: #ff0000;">西游记</button>
    <button class="chat-filter-btn" data-channel="es" style="color: #33cc33;">潭际闲聊</button>
    <button class="chat-filter-btn" data-channel="sldh" style="color: #cc33cc;">水陆大会</button>
    <button class="chat-filter-btn" data-channel="room" style="color: #ffcc00;">房间</button>
    <button class="chat-filter-btn" data-channel="system" style="color: #cc3333;">系统</button>
    <span class="chat-msg-count" id="msgCount"></span>
</div>

<!-- 实时消息容器 -->
<div class="chat-container" id="chatContainer">
    <div class="chat-empty" id="chatEmpty">
        <div class="chat-empty-icon">📨</div>
        等待新消息...
    </div>
    <div id="chatMessages"></div>
</div>

<hr>

<form id="chatForm" action="talk.php" method="POST" novalidate>
    <select name="channel" style="padding: 5px; font-size: 14px;">
        <option value="chat">房间</option>
        <option value="rumor">谣言</option>
        <option value="xyj">西游记</option>
        <option value="es">潭际闲聊</option>
        <option value="sldh">水陆大会</option>
    </select>
    <input type="text" name="message" id="chatInput" placeholder="说点什么..." required autofocus style="width: 50%;">
    <button type="submit" class="chat-send-btn" id="sendBtn">发送</button>
</form>
<button type="button" class="emote-trigger-btn" id="emoteBtn" title="表情动作">😀</button>

<!-- 表情面板 -->
<div class="emote-panel" id="emotePanel" style="display:none;">
    <div class="emote-panel-header">
        <div class="emote-tabs" id="emoteTabs">
            <!-- JS动态填充分类tab -->
        </div>
        <div class="emote-target-select">
            <label>目标:</label>
            <select id="emoteTarget">
                <option value="">无目标</option>
                <!-- JS动态填充玩家 -->
            </select>
        </div>
    </div>
    <div class="emote-grid" id="emoteGrid">
        <!-- JS动态填充表情项 -->
    </div>
</div>

<br>
<a href="room.php">返回游戏</a>

<script>
/**
 * AJAX轮询消息系统
 * 通过定时AJAX请求获取新消息
 */
(function() {
    // 内存消息数组，最大200条
    var messages = [];
    var MAX_MESSAGES = 200;
    
    // 跟踪最近发送的消息ID，避免重复显示
    var storageKey = 'chat_recentMsgs_<?= $charId ?>';
    var recentSentMessages = JSON.parse(localStorage.getItem(storageKey) || '{}');
    var MAX_RECENT_TRACKED = 1000;  // 增加到1000条

    // 当前频道过滤
    var currentFilter = '';
    
    // 轮询相关变量
    var lastMessageId = parseInt(localStorage.getItem('chat_lastMsgId_<?= $charId ?>') || '0');  // 最后一条消息的ID，从localStorage恢复
    var pollTimer = null;
    var POLL_INTERVAL = 1500; // 1.5秒轮询一次
    var isPolling = false;  // 防止并发轮询

    // 初始化时：重置 lastMessageId，加载所有历史消息
    lastMessageId = 0;
    // 同时清除去重缓存，避免历史消息被误过滤
    recentSentMessages = {};
    localStorage.removeItem(storageKey);
    console.log('📋 加载历史消息');

    // DOM 引用
    var chatContainer = document.getElementById('chatContainer');
    var chatMessages = document.getElementById('chatMessages');
    var chatEmpty = document.getElementById('chatEmpty');
    var wsStatus = document.getElementById('wsStatus');
    var msgCount = document.getElementById('msgCount');
    var filterBar = document.getElementById('filterBar');
    var chatForm = document.getElementById('chatForm');
    var chatInput = document.getElementById('chatInput');
    var sendBtn = document.getElementById('sendBtn');

    // 频道映射
    var channelColors = <?= json_encode($channelColors) ?>;
    var channelNames = <?= json_encode($channelNames) ?>;

    // ==================== AJAX轮询 ====================
    
    // 开始轮询
    function startPolling() {
        updateStatus('connected', '已连接，1.5秒刷新');
        pollMessages();
        pollTimer = setInterval(pollMessages, POLL_INTERVAL);
    }
    
    // 停止轮询
    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }
    
    // 轮询获取新消息
    function pollMessages() {
        // 防止并发轮询
        if (isPolling) {
            console.log('⏸️ 上次轮询还未完成，跳过');
            return;
        }
        
        isPolling = true;
        
        var url = 'talk.php?action=poll';
        if (lastMessageId > 0) {
            url += '&last_id=' + lastMessageId;
            console.log('🔄 轮询: last_id=' + lastMessageId);
        } else {
            console.log('🔄 首次轮询');
        }
        
        fetch(url, {credentials: 'same-origin'})
        .then(function(resp) {
            // 检查HTTP状态
            if (!resp.ok) {
                throw new Error('HTTP错误: ' + resp.status + ' ' + resp.statusText);
            }
            // 检查Content-Type
            var contentType = resp.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return resp.text().then(function(text) {
                    console.error('❌ 非JSON响应:', text.substring(0, 200));
                    throw new Error('服务器返回了非JSON响应，可能未登录或发生错误');
                });
            }
            return resp.json();
        })
        .then(function(data) {
            if (data.success && data.messages && data.messages.length > 0) {
                console.log('📨 收到', data.messages.length, '条新消息, last_id返回:', data.last_id);
                
                // 添加新消息
                for (var i = 0; i < data.messages.length; i++) {
                    var msg = data.messages[i];
                    console.log('  - 消息ID:', msg.id, '| 类型:', msg.msg_type);
                    addMessage({
                        message: msg.message,
                        msg_type: msg.msg_type || 'room',
                        timestamp: msg.created_at || new Date().toISOString(),
                        id: msg.id  // 传递消息ID
                    });
                }
                
                // 更新最后消息ID（在添加完所有消息后）
                var oldLastId = lastMessageId;
                lastMessageId = data.last_id || lastMessageId;
                localStorage.setItem('chat_lastMsgId_<?= $charId ?>', lastMessageId);
                
                console.log('✅ lastMessageId更新:', oldLastId, '->', lastMessageId);
            } else {
                console.log('ℹ️ 没有新消息');
            }
        })
        .catch(function(err) {
            console.error('❌ 轮询失败:', err);
            updateStatus('disconnected', '连接失败，将重试');
        })
        .finally(function() {
            isPolling = false;
        });
    }
    
    function updateStatus(state, text) {
        wsStatus.className = 'ws-status ' + state;
        wsStatus.querySelector('.ws-status-text').textContent = text;
    }

    // ==================== 消息管理 ====================

    function addMessage(data) {
        var msg = {
            message: data.message || '',
            msg_type: data.msg_type || data.type || 'self_event',
            timestamp: data.timestamp || new Date().toISOString(),
            id: data.id || 0
        };
        
        // 优先使用ID去重（最可靠）
        if (msg.id > 0) {
            // 正常消息ID
            var idKey = 'id_' + msg.id;
            if (recentSentMessages[idKey]) {
                console.log('⚠️ 跳过重复消息(ID):', msg.id, '| 内容:', msg.message.substring(0, 30));
                return;
            }
            recentSentMessages[idKey] = true;
            console.log('✅ 添加新消息(ID):', msg.id);
        } else if (msg.id === -1) {
            // 自己发送的消息（临时ID=-1），使用内容+时间去重
            var msgContent = msg.message.replace(/<[^>]*>/g, '');
            var timeStr = msg.timestamp.substring(0, 19);
            var msgKey = 'self_' + msgContent + '|' + timeStr;
            
            if (recentSentMessages[msgKey]) {
                console.log('跳过重复消息(自发送):', msg.message.substring(0, 20));
                return;
            }
            recentSentMessages[msgKey] = true;
        } else {
            // 没有ID，使用内容+时间去重
            var msgContent = msg.message.replace(/<[^>]*>/g, '');
            var timeStr = msg.timestamp.substring(0, 19);
            var msgKey = msgContent + '|' + timeStr;
            
            if (recentSentMessages[msgKey]) {
                console.log('跳过重复消息(内容):', msg.message.substring(0, 20));
                return;
            }
            recentSentMessages[msgKey] = true;
        }
        
        // 限制 recentSentMessages 容量，避免localStorage过大
        var keys = Object.keys(recentSentMessages);
        if (keys.length > MAX_RECENT_TRACKED) {
            // 删除最早的条目
            for (var i = 0; i < keys.length - MAX_RECENT_TRACKED + 100; i++) {
                delete recentSentMessages[keys[i]];
            }
        }
        
        // 保存到localStorage（刷新页面后也能过滤旧消息）
        try {
            localStorage.setItem(storageKey, JSON.stringify(recentSentMessages));
        } catch (e) {
            // localStorage已满，忽略错误
        }

        // 追加到数组
        messages.push(msg);

        // 超过200条时从头部移除旧消息（同时清理DOM）
        while (messages.length > MAX_MESSAGES) {
            messages.shift();
            // 移除DOM中最早的一条消息
            var firstMsgEl = chatMessages.querySelector('.chat-msg-item');
            if (firstMsgEl) chatMessages.removeChild(firstMsgEl);
        }

        // 隐藏空状态提示
        chatEmpty.style.display = 'none';

        // 渲染消息（仅在当前过滤器匹配时显示）
        if (matchesFilter(msg)) {
            renderMessage(msg);
            scrollToBottom();
        }

        // 更新消息计数
        updateMsgCount();
    }

    function matchesFilter(msg) {
        if (!currentFilter) return true;
        var mt = msg.msg_type;
        // room 分页显示房间内闲聊（chat）、房间消息（room）、房间事件（room_event）、NPC消息（npc_dialog, npc_action, npc_chat）、动作结果（self_event）和战斗消息（combat）
        if (currentFilter === 'room' && (mt === 'chat' || mt === 'room' || mt === 'room_event' || mt === 'npc_dialog' || mt === 'npc_action' || mt === 'npc_chat' || mt === 'self_event' || mt === 'combat')) return true;
        return mt === currentFilter;
    }

    function renderMessage(msg) {
        var div = document.createElement('div');
        div.className = 'chat-msg-item';
        div.setAttribute('data-channel', msg.msg_type);

        var html = '';

        // 时间戳
        var timeStr = formatTime(msg.timestamp);
        if (timeStr) {
            html += '<span class="chat-timestamp">' + timeStr + '</span>';
        }

        // 频道标签（已隐藏）
        // var msgType = msg.msg_type;
        // if (channelNames[msgType]) {
        //     var tagColor = channelColors[msgType] || '#aaa';
        //     html += '<span class="chat-channel-tag" style="color:' + tagColor +
        //         ';border:1px solid ' + tagColor + ';">' + channelNames[msgType] + '</span>';
        // }

        // 消息内容（WebSocket服务器已将ANSI转为HTML，直接显示）
        html += msg.message.replace(/\n/g, '<br>');
        div.innerHTML = html;

        chatMessages.appendChild(div);
    }

    function formatTime(timestamp) {
        if (!timestamp) return '';
        var d;
        if (typeof timestamp === 'string') {
            if (timestamp.length > 16) {
                return timestamp.substring(11, 16);
            }
            d = new Date(timestamp);
        } else {
            d = new Date(timestamp * 1000);
        }
        if (isNaN(d.getTime())) return '';
        return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
    }

    function scrollToBottom() {
        chatContainer.scrollTop = chatContainer.scrollHeight;
    }

    function updateMsgCount() {
        var filtered = currentFilter
            ? messages.filter(function(m) { return matchesFilter(m); }).length
            : messages.length;
        msgCount.textContent = filtered + '/' + messages.length + ' 条';
    }

    // ==================== 频道过滤 ====================

    function applyFilter(channel) {
        currentFilter = channel;

        // 更新按钮状态
        var btns = filterBar.querySelectorAll('.chat-filter-btn');
        for (var i = 0; i < btns.length; i++) {
            if (btns[i].getAttribute('data-channel') === channel) {
                btns[i].classList.add('active');
            } else {
                btns[i].classList.remove('active');
            }
        }

        // 重新渲染所有消息
        rerenderAll();
    }

    function rerenderAll() {
        // 清空消息容器
        chatMessages.innerHTML = '';

        if (messages.length === 0) {
            chatEmpty.style.display = '';
            return;
        }

        chatEmpty.style.display = 'none';

        // 渲染所有匹配的消息
        for (var i = 0; i < messages.length; i++) {
            if (matchesFilter(messages[i])) {
                renderMessage(messages[i]);
            }
        }

        scrollToBottom();
        updateMsgCount();
    }

    // 绑定过滤按钮点击
    filterBar.addEventListener('click', function(e) {
        var btn = e.target.closest('.chat-filter-btn');
        if (btn) {
            applyFilter(btn.getAttribute('data-channel'));
        }
    });

    // ==================== 表单发送（AJAX） ====================

    chatForm.addEventListener('submit', function(e) {
        e.preventDefault();

        var msgText = chatInput.value.trim();
        if (!msgText) return;

        // 禁用发送按钮
        sendBtn.disabled = true;
        sendBtn.textContent = '发送中...';

        var formData = new FormData(chatForm);

        fetch('talk.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(resp) {
            console.log('POST响应状态:', resp.status);
            return resp.text();  // 先获取文本
        })
        .then(function(text) {
            console.log('POST响应内容:', text.substring(0, 200));
            try {
                return JSON.parse(text);  // 再解析JSON
            } catch(e) {
                console.error('JSON解析失败:', e);
                throw new Error('响应不是有效的JSON');
            }
        })
        .then(function(data) {
            console.log('POST解析成功:', data);
            if (data.success) {
                // 清空输入框
                chatInput.value = '';
                chatInput.focus();

                // 不立即显示，等待轮询拉取（确保不重复）
                // 由于broadcastToRoom包含发送者，1.5秒内会轮询到自己的消息
            } else {
                alert(data.error || '发送失败');
            }
        })
        .catch(function(err) {
            console.error('发送消息失败:', err);
            // 不再降级到传统表单提交，避免重复发送
            // 只显示错误提示，让用户手动重试
            alert('发送失败，请重试。如果问题持续存在，请刷新页面。');
        })
        .finally(function() {
            sendBtn.disabled = false;
            sendBtn.textContent = '发送';
        });
    });

    // ==================== 重置功能 ====================
    
    document.getElementById('resetBtn').addEventListener('click', function() {
        if (confirm('确定要重置并重新加载所有消息吗？')) {
            // 清除localStorage
            localStorage.removeItem('chat_lastMsgId_<?= $charId ?>');
            localStorage.removeItem(storageKey);

            // 重置内存中的变量
            lastMessageId = 0;
            messages = [];
            recentSentMessages = {};
            // 重新渲染
            rerenderAll();
            console.log('🔄 已重置，重新加载消息...');
            // 立即轮询一次
            pollMessages();
        }
    });

    // ==================== 初始化 ====================

    // 页面加载后启动轮询
    startPolling();
    
    // 页面卸载时停止轮询
    window.addEventListener('beforeunload', function() {
        stopPolling();
    });

})();

// === 表情面板功能 ===
// 当前角色名（用于"对自己使用"表情动作，后端按 strcasecmp 判定为自身目标）
var currentCharName = '<?= addslashes($char['name'] ?? '') ?>';

var emotePanel = document.getElementById('emotePanel');
var emoteBtn = document.getElementById('emoteBtn');
var emoteTabs = document.getElementById('emoteTabs');
var emoteGrid = document.getElementById('emoteGrid');
var emoteTarget = document.getElementById('emoteTarget');
var emoteCache = null; // 缓存表情数据

// 切换面板显示
emoteBtn.addEventListener('click', function(e) {
    e.preventDefault();
    e.stopPropagation();
    if (emotePanel.style.display === 'none') {
        emotePanel.style.display = 'flex';
        if (!emoteCache) {
            loadEmotes();
        } else {
            refreshPlayers();
        }
    } else {
        emotePanel.style.display = 'none';
    }
});

// 点击面板外关闭
document.addEventListener('click', function(e) {
    if (!emotePanel.contains(e.target) && e.target !== emoteBtn) {
        emotePanel.style.display = 'none';
    }
});

// 加载表情数据
function loadEmotes() {
    fetch('talk.php?action=emote_list')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                emoteCache = data;
                if (!data.categories || data.categories.length === 0) {
                    emoteTabs.innerHTML = '';
                    emoteGrid.innerHTML = '<div style="color:#ccc;padding:10px;">当前没有可用的表情动作</div>';
                    renderPlayers(data.players || []);
                    return;
                }
                renderEmoteTabs(data.categories);
                renderEmoteGrid(data.categories[0]);
                renderPlayers(data.players || []);
            }
        })
        .catch(function(err) {
            console.error('加载表情失败:', err);
            emoteGrid.innerHTML = '<div style="color:#f66;padding:10px;">加载失败</div>';
        });
}

// 刷新玩家列表（面板已打开时）
function refreshPlayers() {
    fetch('talk.php?action=emote_list')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                renderPlayers(data.players);
            }
        });
}

// 渲染分类tab
function renderEmoteTabs(categories) {
    emoteTabs.innerHTML = '';
    categories.forEach(function(cat, index) {
        var tab = document.createElement('button');
        tab.type = 'button';
        tab.className = 'emote-tab' + (index === 0 ? ' active' : '');
        tab.textContent = cat.name;
        tab.addEventListener('click', function() {
            emoteTabs.querySelectorAll('.emote-tab').forEach(function(t) { t.classList.remove('active'); });
            tab.classList.add('active');
            renderEmoteGrid(cat);
        });
        emoteTabs.appendChild(tab);
    });
}

// 渲染表情网格
function renderEmoteGrid(category) {
    emoteGrid.innerHTML = '';
    if (!category || !Array.isArray(category.emotes)) {
        return;
    }
    category.emotes.forEach(function(emote) {
        var item = document.createElement('div');
        item.className = 'emote-item';
        item.innerHTML = '<div>' + emote.description + '</div><div class="emote-cmd">' + emote.command + '</div>';
        item.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            sendEmote(emote.command);
        });
        emoteGrid.appendChild(item);
    });
}

// 渲染玩家列表（含"自己"选项，支持对自身使用表情动作）
function renderPlayers(players) {
    emoteTarget.innerHTML = '<option value="">无目标</option>';

    // "自己"选项：value 设为当前角色名，后端按 strcasecmp 判定为自身目标
    if (currentCharName) {
        var selfOpt = document.createElement('option');
        selfOpt.value = currentCharName;
        selfOpt.textContent = '自己';
        emoteTarget.appendChild(selfOpt);
    }

    if (players && players.length > 0) {
        players.forEach(function(p) {
            // 避免与"自己"选项重复（value 相同无意义）
            if (currentCharName && p.name === currentCharName) {
                return;
            }
            var opt = document.createElement('option');
            opt.value = p.name;
            opt.textContent = p.name;
            emoteTarget.appendChild(opt);
        });
    }
}

// 发送表情命令
function sendEmote(command) {
    var target = emoteTarget.value;

    // 通过action.php发送标准emote请求
    var formData = new FormData();
    formData.append('action', 'emote');
    formData.append('emote', command);
    if (target) {
        formData.append('target', target);
    }

    fetch('action.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        // 关闭面板
        emotePanel.style.display = 'none';
    })
    .catch(function(err) {
        console.error('发送表情失败:', err);
    });
}
</script>
</body>
</html>

