<?php
/**
 * 动作路由器 - 将庞大的switch-case拆分到独立方法
 */

class ActionRouter {
    
    /** @var array|null 命令路由映射缓存 */
    private static ?array $routeCache = null;

    /**
     * 从数据库加载命令路由映射表
     * @return array [command => method]
     */
    private static function loadRouteMap(): array {
        if (self::$routeCache !== null) {
            return self::$routeCache;
        }
        try {
            // 确保 Database 类已加载
            if (!class_exists('Database')) {
                require_once __DIR__ . '/../includes/db.php';
            }
            $db = Database::getInstance();
            $stmt = $db->query("SELECT command, method FROM command_routes WHERE enabled = 1");
            self::$routeCache = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                self::$routeCache[$row['command']] = $row['method'];
            }
        } catch (\Exception $e) {
            error_log("[ActionRouter] Failed to load route map: " . $e->getMessage());
            // 返回备用硬编码映射（最小集，确保基本命令可用）
            return self::getFallbackRouteMap();
        }
        return self::$routeCache;
    }

    /**
     * 备用路由映射：当数据库不可用时使用
     */
    private static function getFallbackRouteMap(): array {
        return [
            'look' => 'handleLook', '看' => 'handleLook', 'kan' => 'handleLook',
            'go' => 'handleGo', 'say' => 'handleSay', 'tell' => 'handleTell', 't' => 'handleTell',
            'fight' => 'handleFight', 'kill' => 'handleKill', 'hit' => 'handleHit', 'k' => 'handleHit',
            'flee' => 'handleFlee', 'exert' => 'handleExert', 'cast' => 'handleCast',
            'sleep' => 'handleSleep', '休息' => 'handleSleep',
            'eat' => 'handleEat', 'drink' => 'handleDrink',
            'get' => 'handleGet', 'drop' => 'handleDrop',
            'wear' => 'handleWear', 'wield' => 'handleWield', 'remove' => 'handleRemove',
            'inventory' => 'handleInventory', 'i' => 'handleInventory', 'score' => 'handleScore',
            'team' => 'handleTeam', 'duiwu' => 'handleTeam',
            'ji' => 'handleJi', 'out' => 'handleOut',
            'apprentice' => 'handleApprentice', '拜师' => 'handleApprentice',
            'learn' => 'handleLearn', '学习' => 'handleLearn',
            'learnFromMaster' => 'handleLearnFromMaster',
            'doLearnFromMaster' => 'handleDoLearnFromMaster',
            'exercise' => 'handleExercise', '打坐' => 'handleExercise',
            'meditate' => 'handleMeditate', '冥思' => 'handleMeditate',
            'stop' => 'handleStop', '停止' => 'handleStop',
            'chanting' => 'handleChanting', '诵经' => 'handleChanting',
            'abandon' => 'handleAbandon', 'fangqi' => 'handleAbandon', '放弃' => 'handleAbandon',
            'practiceData' => 'handlePracticeData',
            'jump' => 'handleJump', 'open' => 'handleOpen', 'close' => 'handleClose',
            'huimeng' => 'handleHuimeng', '回梦' => 'handleHuimeng',
        ];
    }

    /**
     * 获取角色的显示名称（考虑变化状态）
     * 
     * @param array $char 角色信息
     * @param int|null $charId 角色ID（可选）
     * @return string 显示名称
     */
    private static function getDisplayName(array $char, ?int $charId = null): string {
        // 如果没有提供 ID，从 char 数据中获取
        if ($charId === null) {
            $charId = $char['id'] ?? 0;
        }
        
        // 尝试从数据库获取变化状态
        if (function_exists('get_transform_state_from_db')) {
            $transformData = get_transform_state_from_db($charId);
            if ($transformData && isset($transformData['target_name'])) {
                return $transformData['target_name'];
            }
        }
        
        // 尝试从 Session 获取变化状态
        if (isset($_SESSION['transform_' . $charId])) {
            $transformData = $_SESSION['transform_' . $charId];
            if (isset($transformData['target_name'])) {
                return $transformData['target_name'];
            }
        }
        
        // 没有变化，返回真实名称
        return $char['name'];
    }
    
    /**
     * 分发动作到对应处理器
     */
    public static function dispatch(int $charId, string $action, string $param, array $char): array {

        // === 服务端驱动NPC攻击 ===
        // 每次动作请求时检查并触发待处理的NPC攻击
        // 还原LPC heart_beat机制：NPC有自己的心跳，不依赖前端定时器
        // 注意：npc_attack动作本身就是NPC攻击，不需要再触发
        if ($action !== 'npc_attack') {
            require_once DAEMON_PATH . 'CombatDaemon.php';
            $npcAttackResult = CombatDaemon::processPendingNpcAttacks($charId);
            if ($npcAttackResult['attacks'] > 0 && !empty($npcAttackResult['messages'])) {
                // 将NPC攻击消息存入flash_message，让页面显示
                $msgKey = "flash_message_{$charId}";
                if (!isset($_SESSION[$msgKey]) || !is_array($_SESSION[$msgKey])) {
                    $_SESSION[$msgKey] = [];
                }
                $msgLines = array_filter(explode("\n", trim($npcAttackResult['messages'])));
                foreach ($msgLines as $line) {
                    if (!empty($line)) {
                        $_SESSION[$msgKey][] = $line;
                    }
                }
            }
        }

        // 从数据库加载动作映射表（带缓存）
        $actionMap = self::loadRouteMap();
        
        // 监禁状态拦截：在监禁房间中限制命令
        // 还原原始LPC punish.c 的 block_cmd() 逻辑
        require_once __DIR__ . '/../helpers/BanHelper.php';
        if (BanHelper::isInPrison($charId)) {
            // 只允许 say, help, look, quit 等基本命令
            $prisonAllowedActions = ['look', 'say', 'help', 'score', 'quit', 'kan', '看', 'look_self', 'inventory', 'i', 'who', 'chat'];
            if (!in_array($action, $prisonAllowedActions)) {
                return [
                    'success' => false,
                    'message' => HTML_HIRED . '你被关在死牢之中，手脚被铁链锁住，无法做任何事。' . HTML_NOR
                ];
            }
        }

        // 被困状态拦截
        require_once __DIR__ . '/../helpers/FabaoHelper.php';
        $trapState = FabaoHelper::isTrapped($charId);
        if ($trapState) {
            $allowedCommands = ['out', 'quit', 'score', 'look_self', 'help'];
            if ($trapState['trap_type'] === 'bind') {
                // bind类型：禁止移动命令，其他允许
                $blockedCommands = ['go', 'flee', 'fly', 'qu'];
                if (in_array($action, $blockedCommands)) {
                    return ['success' => false, 'message' => HTML_HIYEL . '你被法宝束缚，无法移动！试试「out」命令挣脱。' . HTML_NOR];
                }
            } else {
                // trap类型：只允许out和少量命令
                if (!in_array($action, $allowedCommands)) {
                    return ['success' => false, 'message' => HTML_HIYEL . '你被困在法宝之中，无法行动！试试「out」命令逃脱。' . HTML_NOR];
                }
            }
        }

        if (isset($actionMap[$action])) {
            $method = $actionMap[$action];
            return self::$method($charId, $param, $char);
        }
        
        // ★ 无底洞惩罚室交互：search 和 dig 命令（逃脱机制）
        // 还原原始LPC punish.c 的 search → dig 逃脱逻辑
        if ($action === 'search' || $action === 'dig') {
            require_once __DIR__ . '/../helpers/YushuPunishHelper.php';
            $punishResult = YushuPunishHelper::handlePunishRoomAction($charId, $action);
            if ($punishResult !== null) {
                if ($action === 'search' && $punishResult['success']) {
                    YushuPunishHelper::markSearched($charId);
                }
                return $punishResult;
            }
        }
        
        // 特殊处理：jump 命令需要优先检查房间动作（如跳瀑布、跳桥等）
        if ($action === 'jump') {
            // 直接检查是否是跳瀑布动作
            if ($param === 'pubu') {
                $charData = CharacterModel::find($charId);
                if ($charData && ($charData['current_room'] === 'dntg/hgs/pubu' || strpos($charData['current_room'], 'pubu') !== false)) {
                    require_once DAEMON_PATH . 'WaterfallHandler.php';
                    $handler = new WaterfallHandler();
                    return $handler->execute($charId, ['action_cmd' => 'jump pubu', 'handler_class' => 'WaterfallHandler'], ['arg' => 'pubu']);
                }
            }
            // 检查是否是跳桥动作
            if ($param === 'bridge') {
                $charData = CharacterModel::find($charId);
                if ($charData && ($charData['current_room'] === 'dntg/hgs/tiebanqiao' || strpos($charData['current_room'], 'tiebanqiao') !== false)) {
                    require_once DAEMON_PATH . 'WaterfallHandler.php';
                    $handler = new WaterfallHandler();
                    return $handler->execute($charId, ['action_cmd' => 'jump bridge', 'handler_class' => 'WaterfallHandler'], ['arg' => 'bridge']);
                }
            }
            $roomActionResult = self::handleCustomAction($charId, $action, $param);
            if ($roomActionResult['success']) {
                return $roomActionResult;
            }
        }
        
        // 未知动作，尝试查找对应的 cmd_xxx 函数
        $cmdFunc = 'cmd_' . $action;
        if (function_exists($cmdFunc)) {
            return $cmdFunc($charId, $param);
        }
        
        // 未知动作，尝试自定义处理
        return self::handleCustomAction($charId, $action, $param);
    }
    
    private static function handleLook(int $charId, string $param, array $char): array {
        if (function_exists('cmd_look')) {
            return cmd_look($charId, $param);
        }
        return ['success' => true, 'message' => '你仔细观察四周...'];
    }
    
    private static function handleMo(int $charId, string $param, array $char): array {
        if (function_exists('cmd_mo')) {
            return cmd_mo($charId, $param);
        }
        return ['success' => true, 'message' => '你到处摸了摸，什么也没摸到。'];
    }

    /**
     * 处理龙珠 touch 命令
     * 玩家触摸龙珠修炼内功心法
     */
    private static function handleTouch(int $charId, string $param, array $char): array {
        $itemId = $_GET['item_id'] ?? $_POST['item_id'] ?? $param;
        if (empty($itemId)) {
            return ['success' => false, 'message' => '请指定要触摸的龙珠。'];
        }

        require_once DAEMON_PATH . 'LongzhuHandler.php';
        $handler = new LongzhuHandler();
        return $handler->execute($charId, ['action_cmd' => 'touch'], ['cmd' => 'touch', 'item_id' => $itemId]);
    }

    /**
     * 处理龙珠 combine 合成命令
     * 集齐九颗龙珠合并为九彩云龙珠
     */
    private static function handleCombine(int $charId, string $param, array $char): array {
        require_once DAEMON_PATH . 'LongzhuHandler.php';
        $handler = new LongzhuHandler();
        return $handler->execute($charId, ['action_cmd' => 'combine'], ['cmd' => 'combine']);
    }

    private static function handleTalk(int $charId, string $param, array $char): array {
        $npcId = intval($_GET['npc_id'] ?? $_POST['npc_id'] ?? 0);
        if (!$npcId) {
            return ['success' => false, 'message' => '你要和谁交谈？'];
        }
        
        $npc = NpcModel::find($npcId);
        if (!$npc) {
            return ['success' => false, 'message' => 'NPC不存在。'];
        }

        // 检查NPC是否处于睡眠/定身状态（回梦/迷魂效果）
        $npcState = Database::queryOne(
            "SELECT temp_key FROM npc_temp WHERE npc_id = ? AND temp_key IN ('sleep_state', 'daze_state') AND temp_value = '1' AND updated_at > ?",
            [$npcId, time()]
        );
        if ($npcState) {
            $stateMsg = $npcState['temp_key'] === 'sleep_state' ? '正在睡梦中' : '正在发呆中';
            return ['success' => false, 'message' => $npc['name'] . $stateMsg . '，无法回应你。'];
        }

        $fullParam = $npc['name'] . ' about general';
        require_once __DIR__ . '/../commands/ask.php';
        if (function_exists('cmd_ask')) {
            return cmd_ask($charId, $fullParam);
        }
        return ['success' => true, 'message' => '你和' . $npc['name'] . '交谈了几句。'];
    }
    
    /**
     * 处理说话命令 (say)
     * 在当前房间向所有人说话
     */
    private static function handleSay(int $charId, string $param, array $char): array {
        if (function_exists('cmd_say')) {
            return cmd_say($charId, $param);
        }
        return ['success' => false, 'message' => '说话功能不可用'];
    }
    
    private static function handleAsk(int $charId, string $param, array $char): array {
        $npcId = intval($_GET['npc_id'] ?? $_POST['npc_id'] ?? 0);
        $topic = $_GET['topic'] ?? $_POST['topic'] ?? '';
        $targetName = '';
        
        // 支持 param 格式: "高员外 about 女儿"
        if (!$npcId && !empty($param) && preg_match('/^(.+?)\s+about\s+(.+)$/i', $param, $matches)) {
            $targetName = trim($matches[1]);
            $topic = trim($matches[2]);
            
            $area = $char['current_area'] ?? '';
            $roomId = $char['current_room'] ?? '';
            if (empty($area) || empty($roomId)) {
                return ['success' => false, 'message' => '你不在任何房间中。'];
            }
            
            $fullRoomId = (strpos($roomId, '/') !== false) ? $roomId : $area . '/' . $roomId;
            $room = RoomModel::getFullInfo($area, $fullRoomId);
            $npcs = $room['npcs'] ?? [];
            
            foreach ($npcs as $npc) {
                if (stripos($npc['name'], $targetName) !== false || 
                    stripos($npc['npc_id'], $targetName) !== false) {
                    $npcId = $npc['id'];
                    break;
                }
            }
            
            if (!$npcId) {
                // NPC不在数据库列表中，检查是否是特殊虚拟NPC场景
                // 人参果园事件：镇元大仙是虚拟NPC，由 ask.php 处理
                if ($fullRoomId === 'qujing/wuzhuang/renshenguo-yuan') {
                    require_once DAEMON_PATH . 'RenshenEventHandler.php';
                    $rsPhase = RenshenEventHandler::getCurrentPhase();
                    if ($rsPhase !== 'idle' && $rsPhase !== 'cooldown') {
                        if (mb_stripos($targetName, '镇元') !== false || mb_stripos($targetName, 'zhenyuan') !== false
                            || mb_stripos('镇元大仙', $targetName) !== false || mb_stripos('zhenyuan', $targetName) !== false) {
                            // 降级到 cmd_ask，它有完整的虚拟NPC处理
                            require_once __DIR__ . '/../commands/ask.php';
                            return cmd_ask($charId, $param);
                        }
                    }
                }
                return ['success' => false, 'message' => '这里没有这个人。'];
            }
        }
        
        if (!$npcId || !$topic) {
            return ['success' => false, 'message' => '你要问什么？'];
        }
        
        $npc = NpcModel::find($npcId);
        if (!$npc) {
            return ['success' => false, 'message' => 'NPC不存在。'];
        }

        // 检查NPC是否处于睡眠/定身状态（回梦/迷魂效果）
        $npcState = Database::queryOne(
            "SELECT temp_key FROM npc_temp WHERE npc_id = ? AND temp_key IN ('sleep_state', 'daze_state') AND temp_value = '1' AND updated_at > ?",
            [$npcId, time()]
        );
        if ($npcState) {
            $stateMsg = $npcState['temp_key'] === 'sleep_state' ? '正在睡梦中' : '正在发呆中';
            return ['success' => false, 'message' => $npc['name'] . $stateMsg . '，无法回应你。'];
        }

        // 加载高老庄NPC助手
        require_once __DIR__ . '/../helpers/GaoNpcHelper.php';
        
        // 检查高员外物品交互
        if ($npc['id'] === 206 && !empty($_POST['item_id'])) {
            $itemId = $_POST['item_id'];
            $itemInteraction = GaoNpcHelper::handleGaoItemInteraction($npc, $char, $itemId);
            if ($itemInteraction['success']) {
                return ['success' => true, 'message' => $itemInteraction['message']];
            }
        }
        
        // 检查是否为掌门NPC - 掌门ask走掌门交互流程
        require_once __DIR__ . '/../helpers/SectHelper.php';
        $sect = SectHelper::getSectByNpcId($npcId);
        if ($sect) {
            // 门派相关话题走掌门交互
            $sectTopics = ['门派', '拜师', '入门', '技能', '条件', 'sect', 'apprentice', 'join', 'skills', 'requirements'];
            if (in_array(strtolower($topic), array_map('strtolower', $sectTopics))) {
                require_once __DIR__ . '/InteractHandler.php';
                return InteractHandler::handleSectMasterInteract($charId, $npcId, 'ask');
            }
        }
        
        // ★ 开封解谜任务系统对接：在 inquiry 查询之前拦截
        // 注意：这里只做NPC识别，具体的任务处理交给 cmd_ask 统一处理
        // 避免 ActionRouter::handleAsk 和 cmd_ask 重复处理导致消息重复
        require_once __DIR__ . '/../helpers/QuestHelper.php';
        $kfNpcId = $npc['npc_id'] ?? '';
        $kfNpcMap = QuestHelper::getNpcMap();
        $kfNpc = $kfNpcMap[$kfNpcId] ?? null;
        file_put_contents(__DIR__ . '/../debug_ask.log', date('Y-m-d H:i:s') . " | handleAsk topic=[$topic] npc_id=[$kfNpcId] inMap=" . ($kfNpc ? 'YES' : 'NO') . "\n", FILE_APPEND);
        // 科举考试答题处理：如果玩家正在进行科举考试，且topic是答案格式（如ABC），直接处理答题
        if (isset($_SESSION['exam_questions']) && $_SESSION['exam_questions']['char_id'] === $charId) {
            $userAnswer = strtoupper(trim($topic));
            if (preg_match('/^[ABCD]{3}$/', $userAnswer)) {
                require_once __DIR__ . '/../helpers/NpcInquiryHelper.php';
                $rankNames = [
                    0 => '白丁',
                    1 => '秀才',
                    2 => '举人',
                    3 => '进士',
                    4 => '翰林',
                    5 => '侍郎',
                ];
                $examResult = NpcInquiryHelper::processExamAnswer($charId, $userAnswer, $npc['name'], $rankNames);
                return ['success' => true, 'message' => $examResult, 'skip_queue' => true];
            }
        }
        
        if ($kfNpc) {
            $kfType = $kfNpc['quest_type'];
            $kfTopic = $kfNpc['topic'] ?? '';
            $extraTriggers = [
                'food'    => ['食物', '美食', '吃', '饭', 'food'],
                'weapon'  => ['武器', '兵器', '刀', '剑', 'weapon'],
                'armor'   => ['盔甲', '护具', '防具', '盾', 'armor'],
                'cloth'   => ['衣物', '衣服', '布', '裙', 'cloth'],
                'wearing' => ['首饰', '珠宝', '饰品', '佩', 'wearing'],
                'misc'    => ['家具', '什物', '杂物', '物', 'misc'],
                'ask'     => ['求签', '问卦', '求福', '祭祖', '祭贤', '问安', 'ask', '求'],
                'kill'    => ['灭妖', '斩妖', '除怪', '杀', 'kill'],
            ];
            $triggers = $extraTriggers[$kfType] ?? [$kfTopic];
            if (!in_array($kfTopic, $triggers)) { $triggers[] = $kfTopic; }
            // 从 NPC inquiry 中提取所有话题键作为额外触发词
            $inquiryData = !empty($npc['inquiry']) ? json_decode($npc['inquiry'], true) : [];
            if (is_array($inquiryData)) {
                foreach ($inquiryData as $inqKey => $inqVal) {
                    if ($inqKey !== 'here' && $inqKey !== 'name' && !in_array($inqKey, $triggers)) {
                        $triggers[] = $inqKey;
                    }
                }
            }
            // 模糊匹配：topic包含任意触发词 或 触发词包含topic
            $matched = false;
            foreach ($triggers as $t) {
                if (mb_stripos($topic, $t) !== false || mb_stripos($t, $topic) !== false) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                // 开封解谜NPC，直接交给 cmd_ask 处理，避免重复分配任务
                $fullParam = $npc['name'] . ' about ' . $topic;
                require_once __DIR__ . '/../commands/ask.php';
                if (function_exists('cmd_ask')) {
                    $result = cmd_ask($charId, $fullParam);
                    return $result;
                }
            }
        }

        $inquiryData = !empty($npc['inquiry']) ? json_decode($npc['inquiry'], true) : [];
        
        // ★ 开封解谜：检查是否在问目标NPC（用于完成 ask 类型任务）
        require_once __DIR__ . '/../helpers/QuestHelper.php';
        $pendingQuests = QuestHelper::getPendingQuests($charId);
        foreach ($pendingQuests as $quest) {
            if (($quest['quest_type'] ?? '') === 'ask' && ($quest['target_id'] ?? '') === ($npc['npc_id'] ?? '')) {
                $questObject = $quest['object_name'] ?? '';
                // 话题匹配任务目标：精确匹配或包含匹配
                if ($topic === $questObject || mb_stripos($topic, $questObject) !== false || mb_stripos($questObject, $topic) !== false) {
                    // ★ 使用 markQuestDone 标记为 done（需回访领奖），而非直接completed
                    require_once __DIR__ . '/../helpers/QuestHelper.php';
                    QuestHelper::markQuestDone($charId, 'ask', $npc['npc_id'] ?? '');
                    $targetName = $quest['quest_name'] ?? $npc['name'];
                    return [
                        'success' => true,
                        'message' => HTML_HIYEL . "【开封解谜】" . HTML_NOR . "{$npc['name']}告诉了你关于「{$questObject}」的消息。\n任务完成！快回去复命吧。"
                    ];
                }
            }
        }
        
        if (!empty($inquiryData) && isset($inquiryData[$topic])) {
            $response = $inquiryData[$topic];
            
            if (is_array($response) && isset($response['action'])) {
                return self::handleInquiryAction($charId, $npc, $response['action']);
            }
            
            // 处理 callable 格式: ["callable", "method_name"]
            if (is_array($response) && isset($response[0]) && $response[0] === 'callable') {
                require_once __DIR__ . '/../helpers/NpcInquiryHelper.php';
                $callableResult = NpcInquiryHelper::handleCallable($response, $npc, $char, $topic);
                if ($callableResult !== null) {
                    // 广播"玩家向NPC打听消息"给房间里其他玩家（和 cmd_ask 流程一致）
                    $broadcastMessage = HTML_HIYEL . $char['name'] . '向' . $npc['name'] . '打听有关「' . $topic . '」的消息。' . HTML_NOR;
                    MessageDaemon::broadcastToRoom($char['current_room'], $broadcastMessage, intval($charId));
                    
                    // 处理 callable 返回数组的情况（如传送功能、科举考试弹窗、战斗开始）
                    if (is_array($callableResult)) {
                        $message = $callableResult['message'] ?? '';
                        $redirect = $callableResult['redirect'] ?? '';
                        $newArea = $callableResult['new_area'] ?? '';
                        $newRoom = $callableResult['new_room'] ?? '';
                        $html = $callableResult['html'] ?? '';
                        $examData = $callableResult['exam_data'] ?? null;
                        $type = $callableResult['type'] ?? '';
                        $output = $callableResult['output'] ?? '';
                        
                        $result = [
                            'success' => true, 
                            'message' => $message ?: $output,
                            'redirect' => $redirect,
                            'new_area' => $newArea,
                            'new_room' => $newRoom,
                            'html' => $html,
                            'exam_data' => $examData,
                            'type' => $type
                        ];
                        
                        return $result;
                    }
                    // 处理字符串返回值（callable已自带NPC名前缀）
                    return ['success' => true, 'message' => $callableResult];
                }
                // callable 处理失败，降级为“不知道”
                return ['success' => true, 'message' => $npc['name'] . '想了一会儿，说道：对不起，你问的事我实在没有印象。'];
            }
            
            // 检查是否为高老庄特殊NPC的对话
            $msg = '';
            if (GaoNpcHelper::isGaoSpecialNpc($npc['id'])) {
                $gaoDialogue = GaoNpcHelper::getGaopoDialogue($npc, $topic);
                if (!empty($gaoDialogue)) {
                    $msg = $gaoDialogue;
                } else {
                    $msg = is_array($response) ? ($response['response'] ?? '...') : $response;
                }
            } else {
                $msg = is_array($response) ? ($response['response'] ?? '...') : $response;
            }
            
            // 广播"玩家向NPC打听消息"给房间里其他玩家（和 cmd_ask 流程一致）
            $broadcastMessage = HTML_HIYEL . $char['name'] . '向' . $npc['name'] . '打听有关「' . $topic . '」的消息。' . HTML_NOR;
            MessageDaemon::broadcastToRoom($char['current_room'], $broadcastMessage, intval($charId));
            
            return ['success' => true, 'message' => $npc['name'] . '说：' . $msg];
        }
        
        $fullParam = $npc['name'] . ' about ' . $topic;
        require_once __DIR__ . '/../commands/ask.php';
        if (function_exists('cmd_ask')) {
            $result = cmd_ask($charId, $fullParam);
            
            if ($result['success']) {
                $broadcastMessage = HTML_HIYEL . $char['name'] . '向' . $npc['name'] . '打听有关「' . $topic . '」的消息。' . HTML_NOR;
                MessageDaemon::broadcastToRoom($char['current_room'], $broadcastMessage, intval($charId));
            }
            
            return $result;
        }
        
        return ['success' => true, 'message' => '你向' . $npc['name'] . '询问了关于「' . $topic . '」的事。'];
    }
    
    private static function handleInquiryAction(int $charId, array $npc, string $action): array {
        if ($action === 'reincarnate') {
            // 使用还阳剧情处理器
            require_once __DIR__ . '/ReincarnateHandler.php';
            $char = \CharacterModel::find($charId);
            $result = ReincarnateHandler::start($charId, $char['current_room'] ?? '');
            
            // 如果剧情启动成功，添加NPC名称前缀
            if ($result['success']) {
                $result['message'] = $npc['name'] . '抬起头看了你一眼。。。';
            } else {
                // 错误消息保持原样
            }
            
            return $result;
        }
        
        if ($action === 'mieyao') {
            // 灭妖任务
            require_once __DIR__ . '/MieyaoHandler.php';
            $handler = new MieyaoHandler();
            // 根据 NPC 的 ID 确定入口类型
            $npcId = strtolower($npc['id'] ?? '');
            $npcConfig = [];
            if (strpos($npcId, 'lijing') !== false || strpos($npcId, 'litian') !== false || strpos($npcId, 'tianwang') !== false) {
                $npcConfig = ['npc_name' => 'litianwang'];
            } else {
                $npcConfig = ['npc_name' => 'yuantiangang'];
            }
            return $handler->execute($charId, $npcConfig, []);
        }
        
        return ['success' => true, 'message' => $npc['name'] . '说：这个功能还未实现。'];
    }
    
    private static function handleFight(int $charId, string $param, array $char): array {
        // 先检查是否有 target 参数（玩家对战）
        $targetId = intval($_GET['target'] ?? $_POST['target'] ?? 0);
        
        if ($targetId) {
            // 玩家对战：使用切磋请求机制
            require_once MODEL_PATH . 'Character.php';
            $target = CharacterModel::find($targetId);
            
            if (!$target) {
                return ['success' => false, 'message' => '你想攻击谁？'];
            }
            
            if ($target['current_room'] !== $char['current_room']) {
                return ['success' => false, 'message' => '这里没有这个人。'];
            }
            
            // 调用 cmd_fight 函数处理（包含切磋请求逻辑）
            $cmdFile = __DIR__ . '/../commands/fight.php';
            $cmdFileExists = file_exists($cmdFile);
            
            // 调试日志
            $debugLog = "=== handleFight 调试 ===\n";
            $debugLog .= "targetId: {$targetId}\n";
            $debugLog .= "targetName: " . ($target['name'] ?? 'unknown') . "\n";
            $debugLog .= "cmdFile: {$cmdFile}\n";
            $debugLog .= "cmdFileExists: " . ($cmdFileExists ? 'yes' : 'no') . "\n";
            
            if ($cmdFileExists) {
                require_once $cmdFile;
                $debugLog .= "cmd_fight function exists: " . (function_exists('cmd_fight') ? 'yes' : 'no') . "\n";
            }
            
            file_put_contents(__DIR__ . '/../debug_fight.log', $debugLog, FILE_APPEND);
            
            // 临时禁用 cmd_fight 调用，直接使用备用逻辑（更可靠）
            if (false && function_exists('cmd_fight')) {
                $result = cmd_fight($charId, $target['name']);
                
                // 如果是切磋邀请等待状态，保存消息并重定向到房间
                if (isset($result['pending']) && $result['pending']) {
                    require_once DAEMON_PATH . 'MessageDaemon.php';
                    $message = isset($result['output']) ? $result['output'] : $result['message'];
                    MessageDaemon::queueMessageToSelf($charId, $message, 'self_event');
                    
                    // 重定向回房间页面
                    $redirectUrl = room_url($char['current_area'], $char['current_room']);
                    header("Location: $redirectUrl");
                    exit;
                }
                
                // 如果战斗开始，重定向到战斗页面
                if ($result['success'] && isset($result['type']) && $result['type'] === 'combat_start') {
                    header('Location: fight.php');
                    exit;
                }
                
                // 其他情况（失败等），保存消息并重定向
                if (!$result['success']) {
                    require_once DAEMON_PATH . 'MessageDaemon.php';
                    MessageDaemon::queueMessageToSelf($charId, $result['message'] ?? '切磋失败', 'self_event');
                    
                    $redirectUrl = room_url($char['current_area'], $char['current_room']);
                    header("Location: $redirectUrl");
                    exit;
                }
                
                return $result;
            }
            
            // 备用逻辑：切磋请求机制（如果 cmd_fight 不存在，也使用请求机制）
            require_once DAEMON_PATH . 'CombatDaemon.php';
            require_once DAEMON_PATH . 'MessageDaemon.php';
            
            // 检查对方是否在战斗中
            if (CombatDaemon::isInCombat($targetId)) {
                $message = "{$target['name']} 正在战斗中，无法发起切磋。";
                MessageDaemon::queueMessageToSelf($charId, $message, 'self_event');
                $redirectUrl = room_url($char['current_area'], $char['current_room']);
                header("Location: $redirectUrl");
                exit;
            }
            
            // 检查是否已有待处理的请求（向同一人）
            $existing = Database::queryOne(
                'SELECT id FROM fight_requests 
                 WHERE from_character_id = ? AND to_character_id = ? AND status = "pending"',
                [$charId, $targetId]
            );
            
            if ($existing) {
                $message = "你已经向 {$target['name']} 发出了切磋邀请，等待对方回应。";
                MessageDaemon::queueMessageToSelf($charId, $message, 'self_event');
                $redirectUrl = room_url($char['current_area'], $char['current_room']);
                header("Location: $redirectUrl");
                exit;
            }
            
            // 检查自己向对方发起的请求是否已被接受
            $acceptedRequest = Database::queryOne(
                'SELECT id FROM fight_requests 
                 WHERE from_character_id = ? AND to_character_id = ? AND status = "accepted"',
                [$charId, $targetId]
            );
            
            if ($acceptedRequest) {
                // 请求已被接受，开始战斗
                $result = CombatDaemon::startFight($charId, $targetId, 'player');
                
                if ($result['success']) {
                    // 标记请求为已完成
                    Database::execute(
                        'UPDATE fight_requests SET status = "completed", resolved_at = NOW() WHERE id = ?',
                        [$acceptedRequest['id']]
                    );
                    header('Location: fight.php');
                    exit;
                }
                
                MessageDaemon::queueMessageToSelf($charId, $result['message'] ?? '切磋失败', 'self_event');
                $redirectUrl = room_url($char['current_area'], $char['current_room']);
                header("Location: $redirectUrl");
                exit;
            }
            
            // 检查对方是否也向自己发起了切磋请求
            $reverseRequest = Database::queryOne(
                'SELECT id FROM fight_requests 
                 WHERE from_character_id = ? AND to_character_id = ? AND status = "pending"',
                [$targetId, $charId]
            );
            
            if ($reverseRequest) {
                // 对方已同意（也发起了请求），开始比试
                Database::execute(
                    'UPDATE fight_requests SET status = "accepted", resolved_at = NOW() WHERE id = ?',
                    [$reverseRequest['id']]
                );
                
                $result = CombatDaemon::startFight($charId, $targetId, 'player');
                
                if ($result['success']) {
                    // 通知对方战斗开始了
                    $charName = $char['name'] ?? '对方';
                    $jumpUrl = "action.php?action=fight&target=" . $charId;
                    $startMsg = '<span style="color:#00FF00;font-weight:bold">【切磋】</span> ' . $charName . ' 接受了你的切磋邀请，战斗即将开始！';
                    $startMsg .= '<span data-auto-jump="' . htmlspecialchars($jumpUrl) . '" style="display:none"></span>';
                    MessageDaemon::sendToPlayer($targetId, $startMsg, 'system');
                    
                    header('Location: fight.php');
                    exit;
                }
                
                MessageDaemon::queueMessageToSelf($charId, $result['message'] ?? '切磋失败', 'self_event');
                $redirectUrl = room_url($char['current_area'], $char['current_room']);
                header("Location: $redirectUrl");
                exit;
            }
            
            // 创建新的切磋请求
            Database::execute(
                'INSERT INTO fight_requests (from_character_id, to_character_id, status, expires_at) 
                 VALUES (?, ?, "pending", DATE_ADD(NOW(), INTERVAL 30 SECOND))',
                [$charId, $targetId]
            );
            
            // 向目标玩家发送邀请通知
            $charName = $char['name'] ?? '有人';
            $acceptUrl = "action.php?action=accept+" . urlencode("fight");
            $rejectUrl = "action.php?action=reject+" . urlencode("fight");
            
            $inviteMsg = "{$charName}向你发起了切磋邀请！";
            $inviteMsg .= " <a href=\"{$acceptUrl}\" style=\"color:#00cc00;font-weight:bold;\">[接受切磋]</a> ";
            $inviteMsg .= "<a href=\"{$rejectUrl}\" style=\"color:#999;\">[拒绝切磋]</a> ";
            $inviteMsg .= "（30秒后自动失效）";
            
            MessageDaemon::sendPrivateMessage($targetId, $inviteMsg, $charId);
            
            // 给发起者的提示
            $message = "你向 {$target['name']} 发出了切磋邀请，等待对方回应。";
            MessageDaemon::queueMessageToSelf($charId, $message, 'self_event');
            
            // 重定向回房间页面
            $redirectUrl = room_url($char['current_area'], $char['current_room']);
            header("Location: $redirectUrl");
            exit;
        }
        
        // 如果没有 target，则检查 npc_id（NPC 战斗）
        $npcId = intval($_GET['npc_id'] ?? $_POST['npc_id'] ?? 0);
        if (!$npcId) {
            return ['success' => false, 'message' => '你想攻击谁？'];
        }
        
        $npc = NpcModel::find($npcId);
        if (!$npc) {
            return ['success' => false, 'message' => 'NPC不存在。'];
        }
        
        // 先广播发起战斗的消息（无论成功与否，其他玩家都应该看到发起消息）
        require_once DAEMON_PATH . 'MessageDaemon.php';
        $broadcastMessage = HTML_HIYEL . $char['name'] . ' 对着 ' . $npc['name'] . ' 喝道：「领教高招！」' . HTML_NOR;
        MessageDaemon::broadcastToRoom($char['current_room'], $broadcastMessage, intval($charId));
        
        if (function_exists('cmd_fight')) {
            $result = cmd_fight($charId, $npc['name']);
            
            if ($result['success'] && isset($result['type']) && $result['type'] === 'combat_start') {
                $roomUrl = room_url($char['current_area'], $char['current_room']);
                $cleanMessage = strip_tags(ansi_to_html($broadcastMessage));
                $cleanMessage = html_entity_decode($cleanMessage, ENT_QUOTES, 'UTF-8');
                $redirectUrl = $roomUrl . '&msg=' . urlencode($cleanMessage) . '&auto_fight=1';
                header("Location: $redirectUrl");
                exit;
            }
            
            // 如果失败，保存消息到 message_queue
            if (!$result['success']) {
                MessageDaemon::queueMessageToSelf($charId, $result['message'] ?? '战斗开始失败', 'self_event');
            }
            
            return self::handleFightRedirect($charId, $npc, $result);
        }
        
        // 备用逻辑
        $fightResult = CombatDaemon::startFight($charId, $npcId, 'npc');
        
        if (!$fightResult['success']) {
            // 保存失败消息到 message_queue，让 chat.php 也能看到
            MessageDaemon::queueMessageToSelf($charId, $fightResult['message'] ?? '战斗开始失败', 'self_event');
            
            $cleanMessage = strip_tags(ansi_to_html($fightResult['message'] ?? '战斗开始失败'));
            $cleanMessage = html_entity_decode($cleanMessage, ENT_QUOTES, 'UTF-8');
            $redirectUrl = room_url($char['current_area'], $char['current_room']) . '&msg=' . urlencode($cleanMessage);
            header("Location: $redirectUrl");
            exit;
        }
        
        $roomUrl = room_url($char['current_area'], $char['current_room']);
        $cleanMessage = strip_tags(ansi_to_html($broadcastMessage));
        $cleanMessage = html_entity_decode($cleanMessage, ENT_QUOTES, 'UTF-8');
        $redirectUrl = $roomUrl . '&msg=' . urlencode($cleanMessage) . '&auto_fight=1';
        header("Location: $redirectUrl");
        exit;
    }
    
    private static function handleKill(int $charId, string $param, array $char): array {
        // 先检查是否有 target 参数（玩家对战）
        $targetId = intval($_GET['target'] ?? $_POST['target'] ?? 0);
        
        if ($targetId) {
            // 玩家对战
            require_once MODEL_PATH . 'Character.php';
            $target = CharacterModel::find($targetId);
            
            if (!$target) {
                return ['success' => false, 'message' => '你想杀谁？'];
            }
            
            if ($target['current_room'] !== $char['current_room']) {
                return ['success' => false, 'message' => '这里没有这个人。'];
            }
            
            // 调用 cmd_kill 函数处理
            $cmdFile = __DIR__ . '/../commands/kill.php';
            if (file_exists($cmdFile)) {
                require_once $cmdFile;
            }
            
            if (function_exists('cmd_kill')) {
                $result = cmd_kill($charId, $target['name']);
                
                // 如果有需要通知对方的消息，发送出去
                if (!empty($result['tell_target']) && !empty($result['tell_message'])) {
                    require_once DAEMON_PATH . 'MessageDaemon.php';
                    MessageDaemon::sendToPlayer($result['tell_target'], $result['tell_message'], 'system');
                }
                
                // 如果战斗开始，存储消息并重定向到战斗页面
                if ($result['success'] && isset($result['type']) && $result['type'] === 'combat_start') {
                    // 将战斗开始消息存入闪存，让 fight.php 立即显示
                    if (!empty($result['output'])) {
                        $_SESSION['flash_message'] = [
                            'content' => $result['output'],
                            'timestamp' => time()
                        ];
                    }
                    header('Location: fight.php');
                    exit;
                }
                
                // 其他情况（失败等），保存消息并重定向
                if (!$result['success']) {
                    require_once DAEMON_PATH . 'MessageDaemon.php';
                    MessageDaemon::queueMessageToSelf($charId, $result['message'] ?? '战斗开始失败', 'self_event');
                    
                    $redirectUrl = room_url($char['current_area'], $char['current_room']);
                    header("Location: $redirectUrl");
                    exit;
                }
                
                return $result;
            }
            
            // 备用逻辑：直接开始战斗（如果 cmd_kill 不存在）
            $userId = intval($char['user_id']);
            $isBlocked = Database::queryOne('SELECT id FROM user_blocks WHERE user_id = ? AND block_type = ?', [$userId, 'pk']);
            if ($isBlocked) {
                return ['success' => false, 'message' => '你的PK功能已被封禁'];
            }
            
            // 调用 CombatDaemon 开始战斗
            require_once DAEMON_PATH . 'CombatDaemon.php';
            $result = CombatDaemon::startKill($charId, $targetId, 'player');
            
            if (!$result['success']) {
                return $result;
            }
            
            // 重定向到战斗页面
            header('Location: fight.php');
            exit;
        }
        
        // 如果没有 target，则检查 npc_id（NPC 战斗）
        $npcId = intval($_GET['npc_id'] ?? $_POST['npc_id'] ?? 0);
        if (!$npcId) {
            return ['success' => false, 'message' => '你想杀谁？'];
        }
        
        $npc = NpcModel::find($npcId);
        if (!$npc) {
            return ['success' => false, 'message' => 'NPC不存在。'];
        }
        
        // 化身NPC被攻击时消失（不可被击杀）
        if (($npc['npc_id'] ?? '') === 'tongtian_huashen') {
            require_once __DIR__ . '/TongtianHandler.php';
            return TongtianHandler::handleHuashenAttack($charId, $char, $npc);
        }
        
        if (function_exists('cmd_kill')) {
            $result = cmd_kill($charId, $npc['name']);
            
            if ($result['success'] && isset($result['type']) && $result['type'] === 'combat_start') {
                return self::broadcastAndRedirect($charId, $npc, $result, 'kill');
            }
            
            // 如果失败，保存消息到 message_queue
            if (!$result['success']) {
                require_once DAEMON_PATH . 'MessageDaemon.php';
                MessageDaemon::queueMessageToSelf($charId, $result['message'] ?? '战斗开始失败', 'self_event');
            }
            
            return self::handleFightRedirect($charId, $npc, $result);
        }
        
        $fightResult = CombatDaemon::startKill($charId, $npcId, 'npc');
        
        if (!$fightResult['success']) {
            // 保存失败消息到 message_queue，让 chat.php 也能看到
            require_once DAEMON_PATH . 'MessageDaemon.php';
            MessageDaemon::queueMessageToSelf($charId, $fightResult['message'] ?? '战斗开始失败', 'self_event');
            
            $cleanMessage = strip_tags(ansi_to_html($fightResult['message'] ?? '战斗开始失败'));
            $cleanMessage = html_entity_decode($cleanMessage, ENT_QUOTES, 'UTF-8');
            $redirectUrl = room_url($char['current_area'], $char['current_room']) . '&msg=' . urlencode($cleanMessage);
            header("Location: $redirectUrl");
            exit;
        }
        
        return self::broadcastAndRedirect($charId, $npc, $fightResult, 'kill');
    }
    
    private static function broadcastAndRedirect(int $charId, array $npc, array $result, string $type): array {
        $char = CharacterModel::find($charId);
        $roomId = $char['current_room'];
        
        require_once __DIR__ . '/../helpers/RankHelper.php';
        
        if ($type === 'kill') {
            $selfTitle = RankHelper::querySelfRude($char);
            $rudeTitle = RankHelper::queryRude($npc);
            $broadcastMessage = HTML_HIRED . $char['name'] . '对着' . $npc['name'] . '喝道："' . $rudeTitle . '，' . $selfTitle . '今日不是你死就是我活！"' . HTML_NOR;
        } else {
            $broadcastMessage = HTML_HIYEL . $char['name'] . ' 对着 ' . $npc['name'] . ' 喝道：「领教高招！」' . HTML_NOR;
        }
        
        MessageDaemon::broadcastToRoom($roomId, $broadcastMessage, intval($charId));
        
        $roomUrl = room_url($char['current_area'], $char['current_room']);
        $cleanMessage = strip_tags(ansi_to_html($broadcastMessage));
        $cleanMessage = html_entity_decode($cleanMessage, ENT_QUOTES, 'UTF-8');
        $redirectUrl = $roomUrl . '&msg=' . urlencode($cleanMessage) . '&auto_fight=1';
        
        header("Location: $redirectUrl");
        exit;
    }
    
    private static function handleFightRedirect(int $charId, array $npc, array $result): array {
        $char = CharacterModel::find($charId);
        
        if (!$result['success']) {
            $cleanMessage = strip_tags(ansi_to_html($result['message'] ?? ''));
            $cleanMessage = html_entity_decode($cleanMessage, ENT_QUOTES, 'UTF-8');
            $redirectUrl = room_url($char['current_area'], $char['current_room']) . '&msg=' . urlencode($cleanMessage);
            header("Location: $redirectUrl");
            exit;
        }
        
        $roomUrl = room_url($char['current_area'], $char['current_room']);
        $redirectUrl = $roomUrl . '&auto_fight=1';
        header("Location: $redirectUrl");
        exit;
    }
    
    private static function handleHit(int $charId, string $param, array $char): array {
        if (!function_exists('cmd_hit')) {
            return ['success' => false, 'message' => '你没有在战斗中！'];
        }
        
        $result = cmd_hit($charId, $param);
        
        if ($result['success'] && isset($result['killed']) && $result['killed']) {
            return self::handleCombatEnd($charId, $result, $char);
        }
        
        $msg = $result['output'] ?? $result['message'] ?? '';
        if (!empty($msg) && empty($result['skip_flash'])) {
            $_SESSION['flash_message'] = [
                'type' => 'combat',
                'content' => $msg,
                'timestamp' => time()
            ];
        }
        
        // 存储伤害数据到session（用于飘血显示）
        if (isset($result['damage']) || isset($result['player_damage'])) {
            $_SESSION['combat_damage_' . $charId] = [
                'damage' => intval($result['damage'] ?? 0),
                'player_damage' => intval($result['player_damage'] ?? 0),
                'timestamp' => time()
            ];
        }
        
        // 存储切磋模式的NPC血量到session
        $combatStatus = CombatDaemon::getCombatStatus($charId);
        if (isset($combatStatus['friendly']) && $combatStatus['friendly'] && isset($result['target_hp'])) {
            $_SESSION['npc_hp_friendly_' . $combatStatus['target_id']] = $result['target_hp'];
        }
        
        // AJAX 请求返回 JSON（让客户端决定是刷新 fight.php 还是跳转）
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return [
                'success' => true,
                'message' => $msg,
                'damage' => intval($result['damage'] ?? 0),
                'player_damage' => intval($result['player_damage'] ?? 0),
                'reload' => 'fight.php'
            ];
        }
        
        redirect('fight.php');
    }

    /**
     * NPC独立攻击回合处理
     * 还原LPC heart_beat：NPC有自己的心跳，即使玩家不攻击也会主动进攻
     */
    private static function handleNpcAttack(int $charId, string $param, array $char): array {
        if (!function_exists('cmd_npc_attack')) {
            return ['success' => false, 'message' => ''];
        }

        $result = cmd_npc_attack($charId, '');

        if (!$result['success']) {
            return ['success' => false, 'message' => ''];
        }

        // NPC击杀了玩家
        if (isset($result['killed']) && $result['killed']) {
            return self::handleCombatEnd($charId, $result, $char);
        }

        $msg = $result['output'] ?? '';
        if (!empty($msg)) {
            $_SESSION['flash_message'] = [
                'type' => 'combat',
                'content' => $msg,
                'timestamp' => time()
            ];
        }

        // 存储伤害数据到session（用于飘血显示）
        if (isset($result['player_damage']) && $result['player_damage'] > 0) {
            $_SESSION['combat_damage_' . $charId] = [
                'damage' => 0,
                'player_damage' => intval($result['player_damage'] ?? 0),
                'timestamp' => time()
            ];
        }

        // AJAX 请求返回 JSON
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return [
                'success' => true,
                'message' => $msg,
                'output' => $msg,
                'damage' => 0,
                'player_damage' => intval($result['player_damage'] ?? 0),
                'player_hp' => intval($result['player_hp'] ?? 0),
                'npc_turn' => true
            ];
        }

        return ['success' => true, 'message' => $msg];
    }

    private static function handleCombatEnd(int $charId, array $result, array $char): array {
        $combatStatus = CombatDaemon::getCombatStatus($charId);
        // 优先使用result中的friendly标记，因为战斗状态可能已被清除
        $isFriendly = isset($result['friendly']) && $result['friendly'];
        $roomId = $char['current_room'];
        
        CombatDaemon::endCombat($charId);
        unset($_SESSION["combat_log_{$charId}"]);
        
        $char = CharacterModel::getFullInfo($charId);
        
        if ($isFriendly) {
            self::broadcastFightResult($charId, $result, $char, $roomId);
        }
        
        $fullMessage = $result['output'] ?? $result['message'] ?? '';
        
        require_once DAEMON_PATH . 'MessageDaemon.php';
        $msgId = 0;
        
        // 切磋模式：显示胜利消息（佩服、承让等）
        if ($isFriendly && !empty($fullMessage)) {
            // 三星挑战特殊处理：提取 NPC 认输消息 + 物品奖励消息
            $isSanxingWin = isset($result['sanxing_win']) && $result['sanxing_win'];
            if ($isSanxingWin) {
                // 三星胜利消息已通过 CombatDaemon 内部广播到房间
                // 这里只处理玩家自己的消息，提取奖励部分
                $cleanMessage = $fullMessage;
                // 从消息中提取"你获得了XXX"部分（在 HTML_NOR 之后的纯文本）
                if (preg_match('/你获得了.+？/u', strip_tags($cleanMessage), $rewardMatch)) {
                    $cleanMessage = $rewardMatch[0];
                } else {
                    // 回退：取最后一行作为奖励消息
                    $lines = explode("\n", strip_tags($cleanMessage));
                    $cleanMessage = trim(end($lines));
                }
                $cleanMessage = html_entity_decode($cleanMessage, ENT_QUOTES, 'UTF-8');
                $cleanMessage = trim($cleanMessage);
            } else {
                $htmlMessage = ansi_to_html($fullMessage);
                $cleanMessage = strip_tags($htmlMessage);
                $cleanMessage = html_entity_decode($cleanMessage, ENT_QUOTES, 'UTF-8');
                $cleanMessage = trim($cleanMessage);
            }
            
            if (!empty($cleanMessage)) {
                $msgId = MessageDaemon::queueMessageToSelf($charId, $cleanMessage, 'combat');
                $redirectUrl = room_url($char['current_area'], $char['current_room']) . '&msg=' . urlencode($cleanMessage);
                $_SESSION['flash_message'] = [
                    'type' => 'combat',
                    'content' => $cleanMessage,
                    'timestamp' => time()
                ];
            } else {
                $redirectUrl = room_url($char['current_area'], $char['current_room']);
            }
        } elseif (preg_match('/你击败了.*$/s', $fullMessage, $matches)) {
            $victoryMessage = $matches[0];
            $htmlMessage = ansi_to_html($victoryMessage);
            $cleanMessage = strip_tags($htmlMessage);
            $cleanMessage = html_entity_decode($cleanMessage, ENT_QUOTES, 'UTF-8');
            
            $msgId = MessageDaemon::queueMessageToSelf($charId, $cleanMessage, 'combat');
            $redirectUrl = room_url($char['current_area'], $char['current_room']) . '&msg=' . urlencode($cleanMessage);
            $_SESSION['flash_message'] = [
                'type' => 'combat',
                'content' => $cleanMessage,
                'timestamp' => time()
            ];
        } elseif (isset($result['npc_fled']) && $result['npc_fled']) {
            // 第一条消息：攻击结果
            $attackMessage = $result['output'] ?? $result['message'] ?? '';
            $attackHtml = ansi_to_html($attackMessage);
            $attackClean = strip_tags($attackHtml);
            $attackClean = html_entity_decode($attackClean, ENT_QUOTES, 'UTF-8');
            $msgId = MessageDaemon::queueMessageToSelf($charId, $attackClean, 'combat');
            
            // 第二条消息：NPC逃跑消息
            $fleeMessage = $result['flee_output'] ?? $result['flee_message'] ?? '';
            if (!empty($fleeMessage)) {
                $fleeHtml = ansi_to_html($fleeMessage);
                $fleeClean = strip_tags($fleeHtml);
                $fleeClean = html_entity_decode($fleeClean, ENT_QUOTES, 'UTF-8');
                $msgId = MessageDaemon::queueMessageToSelf($charId, $fleeClean, 'combat');
            }
            
            // 重定向URL中显示逃跑消息
            $displayMsg = !empty($fleeMessage) ? $fleeClean : $attackClean;
            $redirectUrl = room_url($char['current_area'], $char['current_room']) . '&msg=' . urlencode($displayMsg);
            $_SESSION['flash_message'] = [
                'type' => 'combat',
                'content' => $displayMsg,
                'timestamp' => time()
            ];
        } else {
            $redirectUrl = room_url($char['current_area'], $char['current_room']);
        }
        
        if ($msgId > 0) {
            $_SESSION['last_ask_message_id'] = $msgId;
        }
        
        // 如果是 AJAX 请求，返回 JSON 包含重定向信息
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return ['success' => true, 'redirect' => $redirectUrl];
        }
        
        // 非 AJAX 请求直接重定向
        header("Location: $redirectUrl");
        exit;
    }
    
    private static function broadcastFightResult(int $charId, array $result, array $char, string $roomId): void {
        require_once __DIR__ . '/../helpers/RankHelper.php';
        
        $fullMessage = $result['output'] ?? $result['message'] ?? '';
        $winner = null;
        $loser = null;
        
        if (preg_match('/你击败了(.+?)[。！]/', $fullMessage, $matches)) {
            $winner = $char['name'];
            $loser = trim($matches[1]);
        } elseif (preg_match('/你被(.+?)击败了[。！]/', $fullMessage, $matches)) {
            $winner = trim($matches[1]);
            $loser = $char['name'];
        }
        
        if ($winner && $loser) {
            require_once __DIR__ . '/../helpers/RankHelper.php';
            
            // 获取失败者的自称
            $loserChar = CharacterModel::findByName($loser);
            $loserSelfTitle = $loserChar ? RankHelper::querySelf($loserChar) : '';
            
            if ($winner === $char['name']) {
                // 玩家胜利，NPC认输
                $broadcastMessage = HTML_HIYEL . "【切磋】" . HTML_NOR . " {$loserSelfTitle}{$loser}对着{$winner}抱拳道：'佩服佩服！'" . HTML_NOR;
            } else {
                // 玩家失败，向对方认输
                $playerSelfTitle = RankHelper::querySelf($char);
                $broadcastMessage = HTML_HIYEL . "【切磋】" . HTML_NOR . " {$playerSelfTitle}{$char['name']}对着{$winner}抱拳道：'佩服佩服！'" . HTML_NOR;
            }
            
            MessageDaemon::broadcastToRoom($roomId, $broadcastMessage, intval($charId));
            
            $cleanBroadcastMessage = strip_tags(ansi_to_html($broadcastMessage));
            $cleanBroadcastMessage = html_entity_decode($cleanBroadcastMessage, ENT_QUOTES, 'UTF-8');
            
            $_SESSION['fight_result_broadcast'] = [
                'message' => $cleanBroadcastMessage,
                'timestamp' => time()
            ];
        }
    }
    
    private static function handleEmote(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/EmoteDaemon.php';
        
        $emoteCmd = $_GET['emote'] ?? $_POST['emote'] ?? $param;
        $target = $_GET['target'] ?? $_POST['target'] ?? null;
        
        if (empty($emoteCmd)) {
            return ['success' => false, 'message' => '请指定表情动作'];
        }
        
        return EmoteDaemon::execute($charId, $emoteCmd, $target);
    }
    
    private static function handleGive(int $charId, string $param, array $char): array {
        $itemId = $_GET['item_id'] ?? $_POST['item_id'] ?? '';
        $npcId = intval($_GET['npc_id'] ?? $_POST['npc_id'] ?? 0);
        $targetId = intval($_GET['target'] ?? $_POST['target'] ?? 0);
        $quantity = intval($_GET['quantity'] ?? $_POST['quantity'] ?? 1);
        $category = $_GET['category'] ?? $_POST['category'] ?? '';
        
        if (empty($itemId)) {
            return ['success' => false, 'message' => '参数不完整 (itemId=' . $itemId . ')'];
        }
        
        if ($npcId > 0) {
            return self::handleGiveToNpc($charId, $npcId, $itemId, $char, $quantity, $category);
        } elseif ($targetId > 0) {
            return self::handleGiveToPlayer($charId, $targetId, $itemId, $char, $quantity, $category);
        } else {
            $referer = $_SERVER['HTTP_REFERER'] ?? 'room.php';
            return ['success' => false, 'message' => '参数不完整', 'redirect' => $referer];
        }
    }
    
    private static function handleGiveToNpc(int $charId, int $npcId, string $itemId, array $char, int $quantity, string $category): array {
        require_once MODEL_PATH . 'Npc.php';
        $npc = NpcModel::find($npcId);
        
        if (!$npc) {
            return ['success' => false, 'message' => 'NPC不存在'];
        }
        
        // === 婚礼服务特殊处理 ===
        $npcStringId = $npc['npc_id'] ?? '';
        if (($npcStringId === 'jftou' || $npcStringId === 'jiaofu tou') && $itemId === 'coin') {
            require_once __DIR__ . '/WeddingServiceHandler.php';
            require_once HELPER_PATH . 'MoneyHelper.php';
            $handler = new WeddingServiceHandler();
            
            // 检查玩家是否有足够的铜钱
            $money = MoneyHelper::getMoneyInventory($charId);
            if ($money['coin'] < $quantity) {
                return ['success' => false, 'message' => '你没有足够的铜钱。'];
            }
            
            // 计算总金额（每个铜钱价值1）
            $totalValue = $quantity;
            
            // 处理婚礼服务支付
            $result = $handler->handlePayment($charId, $npcId, $totalValue);
            
            // 如果支付成功，扣除金钱
            if ($result['success']) {
                MoneyHelper::deductMoney($charId, $totalValue);
                log_game('WEDDING', "{$char['name']} 雇佣婚礼服务，花费 {$totalValue} 铜钱");
            }
            
            return $result;
        }
        
        // === 喜福会老板(老害虫)接受黄金办喜宴 ===
        if ($npcStringId === 'boss' && $itemId === 'gold') {
            require_once HELPER_PATH . 'MoneyHelper.php';
            $partyPrice = 5000; // 喜宴价格：5000两金子
            
            // 检查是否已经在办喜宴
            $isHost = Database::queryOne(
                "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'host_of_party'",
                [$charId]
            );
            if ($isHost) {
                return ['success' => false, 'message' => "{$npc['name']}说：你已经在办喜宴了，还付什么钱？"];
            }
            
            // 检查金子数量是否足够
            if ($quantity < $partyPrice) {
                return ['success' => false, 'message' => "{$npc['name']}说：办喜宴要" . $partyPrice . "两金子，你只给了" . $quantity . "两，不够啊。"];
            }
            
            // 检查玩家是否有足够的黄金
            $money = MoneyHelper::getMoneyInventory($charId);
            if ($money['gold'] < $quantity) {
                return ['success' => false, 'message' => '你没有足够的黄金。'];
            }
            
            // 扣除黄金（5000两 = 5000 * 10000 铜钱）
            $deductAmount = $partyPrice * 10000;
            MoneyHelper::deductMoney($charId, $deductAmount);
            
            // 设置喜宴状态（对齐原版 accept_object）
            Database::execute(
                "INSERT INTO character_temp (char_id, temp_key, temp_value) VALUES (?, 'host_of_party', '1')
                 ON DUPLICATE KEY UPDATE temp_value = '1'",
                [$charId]
            );
            // 删除 ready_to_pay（原版收钱后删除此标记）
            Database::execute(
                "DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'ready_to_pay'",
                [$charId]
            );
            // NPC准备好办喜宴（原版 start_party 自动设置）
            Database::execute(
                "INSERT INTO npc_temp (npc_id, temp_key, temp_value) VALUES (?, 'ready_to_party', '1')
                 ON DUPLICATE KEY UPDATE temp_value = '1'",
                [$npc['id']]
            );
            
            // 记录喜福会收入
            $npcMoney = Database::queryOne(
                "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'money'",
                [$npc['id']]
            );
            $currentMoney = $npcMoney ? intval($npcMoney['temp_value']) : 0;
            Database::execute(
                "INSERT INTO npc_temp (npc_id, temp_key, temp_value) VALUES (?, 'money', ?)
                 ON DUPLICATE KEY UPDATE temp_value = ?",
                [$npc['id'], $currentMoney + $partyPrice, $currentMoney + $partyPrice]
            );
            
            // 收钱公告：全局广播（对齐原版 count_money + start_party 自动触发）
            require_once DAEMON_PATH . 'MessageDaemon.php';
            $currentRoom = $char['current_room'];
            $broadcastMsg = HTML_HIYEL . "{$npc['name']}把{$char['name']}给的钱仔细的清点了一遍。\n" .
                "{$npc['name']}痛快的说：开席！\n" .
                "{$npc['name']}：各位老爷太太少爷小姐，今日{$char['name']}在喜福会大开酒席，欢迎各位前来捧场！\n" .
                "{$npc['name']}说：您要开始(start)，我便开席。您要上菜(serve)，我就上菜，等您吃饱了，玩腻了，咱就结束(finish)。" . HTML_NOR;
            MessageDaemon::broadcastToAll($broadcastMsg, 0, 'system');
            
            log_game('PARTY', "{$char['name']} 支付 {$partyPrice} 两金子办喜宴");
            
            return [
                'success' => true,
                'message' => "你交给{$npc['name']}" . $partyPrice . "两金子。\n" .
                    "{$npc['name']}笑眯眯地收下金子，仔细的清点了一遍。\n" .
                    "{$npc['name']}痛快的说：开席！\n" .
                    "(点击「开始喜宴」按钮正式开席)",
                'skip_queue' => true
            ];
        }
        
        require_once MODEL_PATH . 'Item.php';
        $inventory = ItemModel::getCharacterItems($charId);
        $item = null;
        
        foreach ($inventory as $invItem) {
            if ($invItem['item_id'] === $itemId && ($category === '' || ($invItem['category'] ?? '') === $category)) {
                $item = $invItem;
                break;
            }
        }
        
        if (!$item) {
            return ['success' => false, 'message' => '你没有这个物品'];
        }
        
        $itemName = $item['name'];
        $itemCategory = $item['category'] ?? '';
        
        // === 任务系统检查（参考原始LPC xgong.c/xpo.c accept_object） ===
        $npcStringId = $npc['npc_id'] ?? '';
        
        if (!empty($npcStringId)) {
            require_once HELPER_PATH . 'QuestHelper.php';
            
            // 1. 检查 give 类型任务（target_id = 接收物品的NPC的npc_id）
            $pendingGiveQuests = QuestHelper::getPendingQuests($charId, 'give');
            foreach ($pendingGiveQuests as $quest) {
                if ($quest['target_id'] === $npcStringId) {
                    $requiredObject = $quest['object_name'] ?? '';
                    // give任务：把物品送给指定NPC，object_name为空则接受任何物品
                    if (empty($requiredObject) || $requiredObject === $itemId) {
                        $removeQty = min($quantity, $item['quantity'] ?? 1);
                        self::removeInventoryItem($charId, $itemId, $itemCategory, $removeQty);
                        
                        $questResult = QuestHelper::markQuestDone($charId, 'give', $npcStringId);
                        $message = "你将{$itemName}交给{$npc['name']}。\n{$npc['name']}由衷地向你道谢。";
                        if ($questResult) {
                            $message .= "\n【任务完成】目标已达成！快回去领奖吧。";
                        }
                        log_game('GIVE_QUEST', "{$char['name']} 完成给予任务: 给予 {$npc['name']} {$itemName}");
                        return ['success' => true, 'message' => $message];
                    }
                }
            }
            
            // 2. 检查 find 类型任务（target_id = 物品ID，需匹配当前给的物品）
            //    参考原始LPC xgong.c accept_object: 检查 name 和 id 双重匹配
            $findTypes = ['weapon', 'armor', 'cloth', 'food', 'wearing', 'misc'];
            foreach ($findTypes as $ft) {
                $pendingFindQuests = QuestHelper::getPendingQuests($charId, $ft);
                foreach ($pendingFindQuests as $quest) {
                    $targetId = $quest['target_id'] ?? '';
                    $objectName = $quest['object_name'] ?? '';
                    $questNpcId = $quest['npc_id'] ?? '';
                    
                    // 检查：物品ID匹配 AND (NPC是任务发布者 OR npc_id匹配)
                    // 参考原始LPC：物品给任务发布者NPC，name+id双重匹配
                    if ($targetId === $itemId) {
                        // 如果任务有npc_id字段，验证当前NPC是否为任务发布者
                        if (!empty($questNpcId) && $questNpcId !== $npcStringId) {
                            continue; // 物品要给任务发布者，不是当前NPC
                        }
                        
                        $removeQty = min($quantity, $item['quantity'] ?? 1);
                        self::removeInventoryItem($charId, $itemId, $itemCategory, $removeQty);
                        
                        // ★ 标记done：用物品ID作为target_id
                        $questResult = QuestHelper::markQuestDone($charId, $ft, $targetId);
                        
                        $message = "你将{$itemName}交给{$npc['name']}。\n{$npc['name']}仔细端详了一番，满意地点点头。";
                        if ($questResult) {
                            $message .= "\n【任务完成】寻物目标已达成！快回去领奖吧。";
                        }
                        log_game('GIVE_QUEST', "{$char['name']} 完成寻物任务: {$ft} {$itemName} → {$npc['name']}");
                        return ['success' => true, 'message' => $message];
                    }
                }
            }
        }
        
        // 袁天罡接收饭盒（硬编码任务逻辑）
        if (($npc['npc_id'] ?? '') === 'yuantiangang' || ($npc['id'] ?? 0) == 136) {
            if ($itemId !== 'fanhe') {
                return ['success' => false, 'message' => "{$npc['name']}摇了摇头：此物我不需要。"];
            }

            $removeQty = min($quantity, $item['quantity'] ?? 1);
            if ($itemCategory !== '') {
                Database::execute(
                    'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND category = ?',
                    [$removeQty, $charId, $itemId, $itemCategory]
                );
                Database::execute(
                    'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ? AND quantity <= 0',
                    [$charId, $itemId, $itemCategory]
                );
            } else {
                Database::execute(
                    'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\')',
                    [$removeQty, $charId, $itemId]
                );
                Database::execute(
                    'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\') AND quantity <= 0',
                    [$charId, $itemId]
                );
            }

            $charFamily = $char['family'] ?? '';
            if ($charFamily === 'wuzhuang') {
                Database::execute(
                    "INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) VALUES (?, 'yuan-learn', '1', NOW(), NOW()) ON DUPLICATE KEY UPDATE state_value = '1', updated_at = NOW()",
                    [$charId]
                );
                return [
                    'success' => true,
                    'message' => HTML_HICYN . "袁天罡笑眯眯地接过饭盒，仔细地品尝了一番。\n" .
                        "袁天罡微微点头：不错不错，难得你有这份孝心。\n" .
                        "（你已获得袁天罡的认可，日后可向其学习道术）" . HTML_NOR
                ];
            } else {
                Database::execute("UPDATE characters SET silver = silver + 1 WHERE id = ?", [$charId]);
                return [
                    'success' => true,
                    'message' => HTML_HICYN . "袁天罡笑眯眯地接过饭盒，仔细地品尝了一番。\n" .
                        "袁天罡笑道：劳烦了劳烦了，一点心意，不成敬意。" . HTML_NOR
                ];
            }
        }
        
        // 李玉娘送饭给玩家（硬编码任务逻辑）
        if ((($npc['npc_id'] ?? '') === 'liyu' || ($npc['id'] ?? 0) == 89) && $itemId === 'fanhe') {
            $hasFanhe = Database::queryOne(
                "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = 'fanhe' LIMIT 1",
                [$charId]
            );
            if ($hasFanhe) {
                return ['success' => false, 'message' => "你已经有了饭盒，快去送饭吧！"];
            }
            Database::execute(
                "INSERT INTO character_inventory (char_id, item_id, category, quantity) VALUES (?, 'fanhe', 'food', 1)",
                [$charId]
            );
            return [
                'success' => true,
                'message' => HTML_HICYN . "李玉娘四下打量了一番，将一个热腾腾的饭盒塞到你手中。\n" .
                    "李玉娘低声说道：劳烦了，帮我把饭送给天监台的袁天罡吧。" . HTML_NOR
            ];
        }
        
        // 却俟大师送饭任务（硬编码任务逻辑）
        if (strpos($npc['name'] ?? '', '却俟') !== false && $itemId === 'fan_cai') {
            $removeQty = min($quantity, $item['quantity'] ?? 1);
            if ($itemCategory !== '') {
                Database::execute(
                    'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND category = ?',
                    [$removeQty, $charId, $itemId, $itemCategory]
                );
                Database::execute(
                    'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ? AND quantity <= 0',
                    [$charId, $itemId, $itemCategory]
                );
            } else {
                Database::execute(
                    'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\')',
                    [$removeQty, $charId, $itemId]
                );
                Database::execute(
                    'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\') AND quantity <= 0',
                    [$charId, $itemId]
                );
            }

            try {
                Database::execute(
                    "UPDATE characters SET silver = silver + 500, combat_exp = combat_exp + 200, deliver_food_time = NOW() WHERE id = ?",
                    [$charId]
                );
            } catch (\Exception $e) {
                error_log('DeliverFood reward error: ' . $e->getMessage());
            }
            return [
                'success' => true,
                'message' => HTML_HIGRN . "你将饭菜交给却俟大师，他满意地点点头。" . HTML_NOR . "\n" .
                    HTML_HICYN . "奖励：银两+500，经验+200" . HTML_NOR
            ];
        }
        
        // 马盗收钱放行（饮马峪拦路抢劫）
        if (($npc['npc_id'] ?? '') === 'madao' || ($npc['id'] ?? 0) == 522) {
            require_once HELPER_PATH . 'MadaoHelper.php';
            $giveResult = MadaoHelper::handleGive($charId, $item);
            
            if ($giveResult && !empty($giveResult['consume_item'])) {
                $removeQty = min($quantity, $item['quantity'] ?? 1);
                if ($itemCategory !== '') {
                    Database::execute(
                        'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND category = ?',
                        [$removeQty, $charId, $itemId, $itemCategory]
                    );
                    Database::execute(
                        'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ? AND quantity <= 0',
                        [$charId, $itemId, $itemCategory]
                    );
                } else {
                    Database::execute(
                        'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\')',
                        [$removeQty, $charId, $itemId]
                    );
                    Database::execute(
                        'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\') AND quantity <= 0',
                        [$charId, $itemId]
                    );
                }
            }
            
            return [
                'success' => $giveResult['success'] ?? true,
                'message' => HTML_HIYEL . ($giveResult['message'] ?? '马盗收下了你的东西。') . HTML_NOR
            ];
        }

        // 守门牛精收油放行（青龙山玄英洞通道）
        if (($npc['npc_id'] ?? '') === 'shoumenniujing' || ($npc['id'] ?? 0) == 1744) {
            require_once HELPER_PATH . 'ShoumenniujingHelper.php';
            $giveResult = ShoumenniujingHelper::handleGive($charId, $item);

            if ($giveResult && !empty($giveResult['consume_item'])) {
                $invId = $item['id'] ?? 0;
                if ($invId > 0) {
                    Database::execute("DELETE FROM character_inventory WHERE id = ?", [$invId]);
                } else {
                    Database::execute(
                        "DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND COALESCE(category, '') = ?",
                        [$charId, $itemId, $item['category'] ?? '']
                    );
                }
            }

            return [
                'success' => $giveResult['success'] ?? true,
                'message' => HTML_HIYEL . ($giveResult['message'] ?? '守门牛精收下了你的东西。') . HTML_NOR
            ];
        }

        // 高员外接收物品（高翠兰任务 - 玉佩）
        if (($npc['npc_id'] ?? '') === 'gao' || ($npc['id'] ?? 0) == 206) {
            // 检查是否是玉佩
            $isYupei = false;
            if ($itemId === 'xiaojie' || $itemId === 'tong-pai' || $itemId === 'yupei') {
                $isYupei = true;
            }
            if (mb_strpos($itemName, '玉佩') !== false) {
                $isYupei = true;
            }
            
            if ($isYupei) {
                // 检查玩家是否已经完成过这个任务
                $questCompleted = Database::queryOne(
                    "SELECT 1 FROM character_temp_states WHERE char_id = ? AND state_key = 'gao_yupei_quest'",
                    [$charId]
                );
                
                if ($questCompleted) {
                    return [
                        'success' => false,
                        'message' => HTML_HICYN . '高员外摇了摇头：这玉佩你已经送回来了，多谢你的好意。' . HTML_NOR
                    ];
                }
                
                // 移除玩家的玉佩
                $removeQty = min($quantity, $item['quantity'] ?? 1);
                if ($itemCategory !== '') {
                    Database::execute(
                        'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND category = ?',
                        [$removeQty, $charId, $itemId, $itemCategory]
                    );
                    Database::execute(
                        'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ? AND quantity <= 0',
                        [$charId, $itemId, $itemCategory]
                    );
                } else {
                    Database::execute(
                        'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\')',
                        [$removeQty, $charId, $itemId]
                    );
                    Database::execute(
                        'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\') AND quantity <= 0',
                        [$charId, $itemId]
                    );
                }
                
                // 标记任务完成
                Database::execute(
                    "INSERT INTO character_temp_states (char_id, state_key, state_value) VALUES (?, 'gao_yupei_quest', '1')
                     ON DUPLICATE KEY UPDATE state_value = '1'",
                    [$charId]
                );
                
                // 给予奖励
                $silverReward = 50;
                $expReward = 5000;
                $daoxingReward = 1000;
                
                Database::execute(
                    "UPDATE characters SET silver = silver + ?, combat_exp = combat_exp + ?, daoxing = daoxing + ? WHERE id = ?",
                    [$silverReward, $expReward, $daoxingReward, $charId]
                );
                
                $message = "高员外接过玉佩，双手颤抖，老泪纵横：\n";
                $message .= "「这……这是翠兰的玉佩！怎么会在你手里？」\n";
                $message .= "你告诉高员外，玉佩是在清风寨内室找到的。\n";
                $message .= "高员外听完，扑通一声跪倒在地：\n";
                $message .= "「多谢大侠！多谢大侠救了小女！\n";
                $message .= "  小老儿无以为报，这点心意，请您务必收下！」\n";
                $message .= "（你获得了：白银 {$silverReward} 两，经验 {$expReward}，道行 {$daoxingReward}）";
                
                log_game('GIVE', "{$char['name']} 给予 NPC {$npc['name']} 玉佩（高翠兰任务）");
                
                return [
                    'success' => true,
                    'message' => HTML_HICYN . $message . HTML_NOR
                ];
            }
            
            // 其他物品，高员外不要
            return [
                'success' => false,
                'message' => HTML_HICYN . '高员外摇了摇头：此物我不需要。' . HTML_NOR
            ];
        }
        
        // 广羲子接收物品（还书、松果）
        if (($npc['npc_id'] ?? '') === 'guangxi' || ($npc['id'] ?? 0) == 335) {
            require_once HELPER_PATH . 'GuangxiHelper.php';
            $giveResult = GuangxiHelper::handleGive($charId, $npc, $item, $quantity);
            
            return [
                'success' => $giveResult['success'] ?? true,
                'message' => HTML_HIYEL . ($giveResult['message'] ?? '广羲子收下了你的东西。') . HTML_NOR
            ];
        }
        
        // 袁守诚接收物品（金色鲤鱼、桂花酒袋）
        if (($npc['npc_id'] ?? '') === 'shouchen' || ($npc['id'] ?? 0) == 30) {
            $itemId = $item['item_id'] ?? '';
            $itemCategory = $item['category'] ?? '';
            
            // 金色鲤鱼 - 算命付费
            if ($itemId === 'golden_carp') {
                // 扣除物品
                $removeQty = min($quantity, $item['quantity'] ?? 1);
                if ($itemCategory !== '') {
                    Database::execute(
                        'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND category = ?',
                        [$removeQty, $charId, $itemId, $itemCategory]
                    );
                    Database::execute(
                        'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ? AND quantity <= 0',
                        [$charId, $itemId, $itemCategory]
                    );
                } else {
                    Database::execute(
                        'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\')',
                        [$removeQty, $charId, $itemId]
                    );
                    Database::execute(
                        'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\') AND quantity <= 0',
                        [$charId, $itemId]
                    );
                }
                
                // 设置付费状态（24小时有效）
                $expireTime = date('Y-m-d H:i:s', time() + 86400);
                $stateValue = json_encode([
                    'paid' => true,
                    'pay_time' => date('Y-m-d H:i:s'),
                    'expire_time' => $expireTime
                ]);
                
                Database::execute(
                    'INSERT INTO character_temp_states (char_id, state_key, state_value, expire_time) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), expire_time = VALUES(expire_time), updated_at = NOW()',
                    [$charId, 'suanming/paid', $stateValue, $expireTime]
                );
                
                log_game('GIVE', "{$char['name']} 给予 NPC {$npc['name']} 金色鲤鱼");
                
                return [
                    'success' => true,
                    'message' => HTML_HICYN . "袁守诚满面堆欢：多谢施主，在下正需要这个，有什么问题您尽管问！" . HTML_NOR
                ];
            }
            
            // 桂花酒袋 - 赠送天书
            if ($itemId === 'guihua-jiudai') {
                // 扣除物品
                $removeQty = min($quantity, $item['quantity'] ?? 1);
                if ($itemCategory !== '') {
                    Database::execute(
                        'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND category = ?',
                        [$removeQty, $charId, $itemId, $itemCategory]
                    );
                    Database::execute(
                        'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ? AND quantity <= 0',
                        [$charId, $itemId, $itemCategory]
                    );
                } else {
                    Database::execute(
                        'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\')',
                        [$removeQty, $charId, $itemId]
                    );
                    Database::execute(
                        'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\') AND quantity <= 0',
                        [$charId, $itemId]
                    );
                }
                
                // 检查是否已经领过
                $hasReceived = Database::queryOne(
                    "SELECT id FROM character_temp_states WHERE char_id = ? AND state_key = 'received_wine'",
                    [$charId]
                );
                
                $message = "袁守诚接过桂花酒袋，微微一笑，说道：这位施主跟小道投缘！这里我也有一点小意思，请笑纳。";
                
                if (!$hasReceived) {
                    // 奖励天书
                    require_once MODEL_PATH . 'Item.php';
                    ItemModel::addToInventory($charId, 'nowords', 1);
                    
                    // 设置标记
                    Database::execute(
                        "INSERT INTO character_temp_states (char_id, state_key, state_value) VALUES (?, 'received_wine', '1') ON DUPLICATE KEY UPDATE state_value = '1'",
                        [$charId]
                    );
                    
                    $message .= "\n袁守诚回赠你一本无字天书。";
                }
                
                log_game('GIVE', "{$char['name']} 给予 NPC {$npc['name']} 桂花酒袋");
                
                return [
                    'success' => true,
                    'message' => HTML_HICYN . $message . HTML_NOR
                ];
            }
            
            // 其他物品
            return [
                'success' => false,
                'message' => HTML_HICYN . "袁守诚摇了摇头：此物我不需要。" . HTML_NOR
            ];
        }
        
        $npcActions = !empty($npc['actions']) ? json_decode($npc['actions'], true) : [];
        $specialAcceptAction = null;
        
        foreach ($npcActions as $action) {
            if (isset($action['type']) && $action['type'] === 'accept_object') {
                if (isset($action['accepted_items']) && in_array($itemId, $action['accepted_items'])) {
                    $specialAcceptAction = $action;
                    break;
                }
            }
        }
        
        if ($specialAcceptAction) {
            $removeQty = min($quantity, $item['quantity'] ?? 1);
            if ($itemCategory !== '') {
                Database::execute(
                    'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND category = ?',
                    [$removeQty, $charId, $itemId, $itemCategory]
                );
                Database::execute(
                    'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ? AND quantity <= 0',
                    [$charId, $itemId, $itemCategory]
                );
            } else {
                Database::execute(
                    'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\')',
                    [$removeQty, $charId, $itemId]
                );
                Database::execute(
                    'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\') AND quantity <= 0',
                    [$charId, $itemId]
                );
            }
            
            $responseMessage = $specialAcceptAction['response_message'] ?? "{$npc['name']}收下了你的{$itemName}。";
            
            if (!empty($specialAcceptAction['reward_item'])) {
                ItemModel::addToInventory($charId, $specialAcceptAction['reward_item'], 1);
                $rewardItem = Database::queryOne('SELECT name FROM items WHERE item_id = ?', [$specialAcceptAction['reward_item']]);
                $rewardName = $rewardItem ? $rewardItem['name'] : '物品';
                $responseMessage .= "\n{$npc['name']}回赠你一个{$rewardName}。";
            }
            
            if (!empty($specialAcceptAction['set_temp'])) {
                foreach ($specialAcceptAction['set_temp'] as $key => $value) {
                    $stateValue = is_array($value) || is_object($value) ? json_encode($value) : strval($value);
                    Database::execute(
                        'INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = NOW()',
                        [$charId, $key, $stateValue]
                    );
                }
            }
            
            log_game('GIVE', "{$char['name']} 给予 NPC {$npc['name']} {$itemName}");
            
            return [
                'success' => true,
                'message' => $responseMessage
            ];
        } else {
            // NPC 没有配置接受物品的动作，直接拒绝
            return [
                'success' => false,
                'message' => "{$npc['name']}不要你的{$itemName}。"
            ];
        }
    }
    
    private static function handleGiveToPlayer(int $charId, int $targetId, string $itemId, array $char, int $quantity, string $category): array {
        require_once MODEL_PATH . 'Character.php';
        $target = CharacterModel::find($targetId);
        
        if (!$target) {
            return ['success' => false, 'message' => '目标不存在'];
        }
        
        if ($target['current_room'] !== $char['current_room']) {
            return ['success' => false, 'message' => '目标不在你所在的房间'];
        }
        
        require_once MODEL_PATH . 'Item.php';
        $inventory = ItemModel::getCharacterItems($charId);
        $item = null;
        
        foreach ($inventory as $invItem) {
            if ($invItem['item_id'] === $itemId && ($category === '' || ($invItem['category'] ?? '') === $category)) {
                $item = $invItem;
                break;
            }
        }
        
        if (!$item) {
            return ['success' => false, 'message' => '你没有这个物品'];
        }
        
        if (isset($item['no_give']) && $item['no_give']) {
            return ['success' => false, 'message' => '这样东西不能给予他人'];
        }
        
        $giveQty = min($quantity, $item['quantity'] ?? 1);
        $itemCategory = $item['category'] ?? '';
        $itemEnchantments = $item['enchantments'] ?? '';
        $liquidRemaining = $item['liquid_remaining'] ?? 0;
        $liquidType = $item['liquid_type'] ?? '';
        $liquidName = $item['liquid_name'] ?? '';
        
        // 检查是否是容器物品
        $isContainer = !empty($item['is_container']) && intval($item['is_container']) > 0;
        $oldContainerId = 0;
        if ($isContainer && $giveQty == ($item['quantity'] ?? 1)) {
            // 整个容器给予，记录旧的容器ID
            $oldContainerId = intval($item['id']);
        }
        
        // 如果给予的数量小于物品总数，更新数量；否则删除记录
        if ($giveQty < ($item['quantity'] ?? 1)) {
            if ($itemCategory !== '') {
                Database::execute(
                    'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND category = ?',
                    [$giveQty, $charId, $itemId, $itemCategory]
                );
            } else {
                Database::execute(
                    'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\')',
                    [$giveQty, $charId, $itemId]
                );
            }
        } else {
            if ($itemCategory !== '') {
                Database::execute(
                    'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ?',
                    [$charId, $itemId, $itemCategory]
                );
            } else {
                Database::execute(
                    'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\')',
                    [$charId, $itemId]
                );
            }
        }
        
        // 添加到接收者背包（液体容器会自动拆分为独立行）
        ItemModel::addToInventory($targetId, $itemId, $giveQty, $itemCategory, $itemEnchantments, $liquidRemaining, $liquidType, $liquidName);
        
        // 如果是容器物品并且是整个给予，转移容器里的物品
        if ($isContainer && $oldContainerId > 0) {
            // 查询接收者那边新创建的容器记录ID
            if ($itemCategory !== '') {
                $newContainer = Database::queryOne(
                    "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ? ORDER BY id DESC LIMIT 1",
                    [$targetId, $itemId, $itemCategory]
                );
            } else {
                $newContainer = Database::queryOne(
                    "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = '') ORDER BY id DESC LIMIT 1",
                    [$targetId, $itemId]
                );
            }
            
            if ($newContainer && !empty($newContainer['id'])) {
                $newContainerId = intval($newContainer['id']);
                // 更新 container_items 表，把容器里的物品转移到新容器
                Database::execute(
                    "UPDATE container_items SET container_id = ? WHERE container_type = 'character_inventory' AND container_id = ?",
                    [$newContainerId, $oldContainerId]
                );
            }
        }
        
        log_game('GIVE', "{$char['name']} 给予 {$target['name']} {$giveQty}{$item['unit']}{$item['name']}");
        
        // 发送私聊消息给接收者
        require_once DAEMON_PATH . 'MessageDaemon.php';
        $receiveMessage = "{$char['name']}给了你{$giveQty}{$item['unit']}{$item['name']}。";
        MessageDaemon::sendPrivateMessage($targetId, $receiveMessage, $charId);
        
        return [
            'success' => true,
            'message' => "你给{$target['name']}{$giveQty}{$item['unit']}{$item['name']}。"
        ];
    }
    
    private static function handleSpecialAction(int $charId, string $param, array $char): array {
        $npcId = intval($_GET['npc_id'] ?? $_POST['npc_id'] ?? 0);
        
        if (!$npcId) {
            return ['success' => false, 'message' => '未指定NPC'];
        }
        
        $npc = NpcModel::find($npcId);
        if (!$npc) {
            return ['success' => false, 'message' => 'NPC不存在'];
        }
        
        $actions = !empty($npc['actions']) ? json_decode($npc['actions'], true) : [];
        $matchedAction = null;
        
        foreach ($actions as $actionConfig) {
            if (isset($actionConfig['action_cmd']) && $actionConfig['action_cmd'] === $param) {
                $matchedAction = $actionConfig;
                break;
            }
        }
        
        if (!$matchedAction) {
            return ['success' => false, 'message' => '该NPC没有此动作'];
        }
        
        if (empty($matchedAction['handler_class'])) {
            return ['success' => false, 'message' => '动作配置不完整'];
        }
        
        $handlerClass = $matchedAction['handler_class'];
        $handlerFile = __DIR__ . '/' . $handlerClass . '.php';
        
        if (!file_exists($handlerFile)) {
            return ['success' => false, 'message' => '动作处理器不存在'];
        }
        
        require_once $handlerFile;
        
        if (!class_exists($handlerClass)) {
            return ['success' => false, 'message' => '动作处理器类不存在'];
        }
        
        $handler = new $handlerClass();
        
        if (!($handler instanceof ActionHandler)) {
            return ['success' => false, 'message' => '动作处理器类型错误'];
        }
        
        try {
            return $handler->execute($charId, $matchedAction, []);
        } catch (\Exception $e) {
            error_log("Handler execution error: " . $e->getMessage());
            return ['success' => false, 'message' => '动作执行失败'];
        }
    }
    
    private static function handleFlee(int $charId, string $param, array $char): array {
        if (!function_exists('cmd_flee')) {
            return ['success' => false, 'message' => '逃跑功能未实现！'];
        }
        
        $result = cmd_flee($charId, '');
        
        $msg = $result['output'] ?? $result['message'] ?? '';
        
        // 逃跑成功：广播房间消息，清除战斗日志，跳转新房间
        if ($result['success'] && isset($result['fled']) && $result['fled'] && isset($result['new_room_id'])) {
            // CombatDaemon::flee() 已经调用了 endCombat 和更新位置，不需要重复
            
            // 广播离开/到达消息到房间
            if (!empty($result['leave_message']) && !empty($result['old_room'])) {
                MessageDaemon::broadcastToRoom($result['old_room']['room_id'], $result['leave_message'], $charId);
            }
            if (!empty($result['arrive_message']) && !empty($result['new_room'])) {
                MessageDaemon::broadcastToRoom($result['new_room']['room_id'], $result['arrive_message'], $charId);
            }
            
            // 逃跑成功才清除战斗日志
            unset($_SESSION["combat_log_{$charId}"]);
            
            list($newArea, $newRoom) = explode('/', $result['new_room_id'], 2);
            $cleanMessage = strip_tags(ansi_to_html($msg));
            $cleanMessage = preg_replace('/<[^>]+>/', '', $cleanMessage);
            $cleanMessage = html_entity_decode($cleanMessage, ENT_QUOTES, 'UTF-8');
            
            $redirectUrl = room_url($newArea, $newRoom) . '&msg=' . urlencode($cleanMessage);
            
            // 如果是 AJAX 请求，返回 JSON 包含重定向信息
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                return ['success' => true, 'redirect' => $redirectUrl];
            }
            
            // 非 AJAX 请求直接重定向
            header("Location: $redirectUrl");
            exit;
        }
        
        // 逃跑失败：不清除战斗日志，消息会追加到战斗记录中
        $cleanMessage = strip_tags(ansi_to_html($msg));
        $cleanMessage = preg_replace('/<[^>]+>/', '', $cleanMessage);
        
        $redirectUrl = 'fight.php?msg=' . urlencode($cleanMessage);
        
        // 如果是 AJAX 请求，返回 JSON 包含重定向信息
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return ['success' => false, 'redirect' => $redirectUrl];
        }
        
        // 非 AJAX 请求直接重定向
        header("Location: $redirectUrl");
        exit;
    }
    
    /**
     * 切换多目标战斗中的优先攻击目标
     */
    private static function handleSwitchTarget(int $charId, string $param, array $char): array {
        $combat = CombatDaemon::getCombatStatus($charId);
        if (!$combat) {
            $redirectUrl = 'fight.php?msg=' . urlencode('你不在战斗中！');
            
            // 如果是 AJAX 请求，返回 JSON 包含重定向信息
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                return ['success' => false, 'redirect' => $redirectUrl];
            }
            
            // 非 AJAX 请求直接重定向
            header('Location: ' . $redirectUrl);
            exit;
        }
        
        $multiTargets = $combat['multi_targets'] ?? [];
        $targetIndex = intval($param);
        
        // 0 = 当前主目标，1+ = multi_targets 中的索引
        if ($targetIndex === 0) {
            // 切换到当前主目标，无需操作
            $redirectUrl = 'fight.php?msg=' . urlencode('当前目标已是优先攻击目标。');
            
            // 如果是 AJAX 请求，返回 JSON 包含重定向信息
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                return ['success' => true, 'redirect' => $redirectUrl];
            }
            
            // 非 AJAX 请求直接重定向
            header('Location: ' . $redirectUrl);
            exit;
        }
        
        // 获取要切换的NPC（multi_targets 索引从 0 开始，param 从 1 开始）
        $mtIndex = $targetIndex - 1;
        if (!isset($multiTargets[$mtIndex])) {
            $redirectUrl = 'fight.php?msg=' . urlencode('目标不存在！');
            
            // 如果是 AJAX 请求，返回 JSON 包含重定向信息
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                return ['success' => false, 'redirect' => $redirectUrl];
            }
            
            // 非 AJAX 请求直接重定向
            header('Location: ' . $redirectUrl);
            exit;
        }
        
        $newTarget = $multiTargets[$mtIndex];
        $newTargetId = intval($newTarget['id']);
        $newTargetName = $newTarget['name'];
        $newTargetMaxHp = max(100, intval($newTarget['max_kee'] ?? 100));
        
        // 将当前主目标放回 multi_targets
        $oldTarget = [
            'id' => $combat['target_id'],
            'name' => $combat['target_name'],
            'combat_exp' => 0  // 旧目标已经在战斗中，不需要重新计算血量
        ];
        
        // 从 multi_targets 移除新目标
        array_splice($multiTargets, $mtIndex, 1);
        // 将旧目标加入
        $multiTargets[] = $oldTarget;
        
        // 更新战斗状态
        CombatDaemon::insertActiveCombat($charId, $newTargetId, 'npc', $newTargetMaxHp, false);
        $_SESSION["combat_{$charId}"] = [
            'target_id' => $newTargetId,
            'target_type' => 'npc',
            'target_name' => $newTargetName,
            'start_time' => $combat['start_time'] ?? time(),
            'round' => $combat['round'] ?? 0,
            'multi_targets' => $multiTargets
        ];
        
        $msg = "你将优先攻击目标切换为 {$newTargetName}。";
        $redirectUrl = 'fight.php?msg=' . urlencode($msg);
        
        // 如果是 AJAX 请求，返回 JSON 包含重定向信息
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return ['success' => true, 'redirect' => $redirectUrl];
        }
        
        // 非 AJAX 请求直接重定向
        header('Location: ' . $redirectUrl);
        exit;
    }
    
    private static function handleCombatStatus(int $charId, string $param, array $char): array {
        if (function_exists('cmd_combat')) {
            return cmd_combat($charId, '');
        }
        return ['success' => false, 'message' => '你没有在战斗中！'];
    }
    
    private static function handleExamine(int $charId, string $param, array $char): array {
        $npcId = intval($_GET['npc_id'] ?? $_POST['npc_id'] ?? 0);
        if (!$npcId) {
            return ['success' => false, 'message' => '你想探查谁？<br>'];
        }
        
        $npc = NpcModel::find($npcId);
        if (!$npc) {
            return ['success' => false, 'message' => '目标不存在。<br>'];
        }
        
        $charDaoxing = intval($char['daoxing'] ?? 0);
        if ($charDaoxing < 5000) {
            return ['success' => false, 'message' => "你的道行不够（当前: {$charDaoxing}，需要: 5000），无法使用地耳灵。<br>"];
        }
        
        $charMana = intval($char['mana'] ?? 0);
        if ($charMana < 100) {
            return ['success' => false, 'message' => "你的法力不够（当前: {$charMana}，需要: 100），无法使用地耳灵。<br>"];
        }
        
        $charSen = intval($char['sen'] ?? 100);
        if ($charSen <= 50) {
            return ['success' => false, 'message' => "你精神太累了（当前: {$charSen}），休息休息吧！<br>"];
        }
        
        $spellSkill = $char['spells'] ?? 0;
        $manaCost = -((100 - $spellSkill) / 4) - 40;
        $manaCost = $manaCost + 10;
        if ($manaCost > -50) $manaCost = -50;
        
        Database::execute(
            'UPDATE characters SET mana = mana + ?, sen = sen - ? WHERE id = ?',
            [intval($manaCost), 50, intval($charId)]
        );
        
        // 给房间内的人广播消息（不需要给NPC发消息，因为NPC是固定对象）
        $broadcastMessage = HTML_HIYEL . $npc['name'] . "忽然莫名其妙地哆嗦了一下。" . HTML_NOR;
        MessageDaemon::broadcastToRoom($char['current_room'], $broadcastMessage, intval($charId));
        
        $descDaoxing = describe_dx($npc['daoxing'] ?? 0);
        $descExp = describe_exp($npc['combat_exp'] ?? 0);
        $descFali = describe_fali($npc['max_mana'] ?? 0);
        $descNeili = describe_neili($npc['max_force'] ?? 0);
        
        $resultMessage = "你口中念了几句咒语，眼中突然精光一闪，大喝一声「顺风耳何在！」<br>";
        $resultMessage .= "只听嘿嘿几声奸笑，不知从哪里钻出来一个肥头大耳的家伙，在你耳边低声说了几句话。<br>";
        $resultMessage .= "顺风耳告诉你：" . $npc['name'] . "的道行已达" . $descDaoxing . "的境界。<br>";
        $resultMessage .= "顺风耳告诉你：" . $npc['name'] . "的武功已达" . $descExp . "的境界。<br>";
        $resultMessage .= "顺风耳告诉你：" . $npc['name'] . "的法力修为已达" . $descFali . "的境界。<br>";
        $resultMessage .= "顺风耳告诉你：" . $npc['name'] . "的内力修为已达" . $descNeili . "的火候。<br>";
        
        return [
            'success' => true,
            'message' => $resultMessage,
            'redirect' => 'room.php'
        ];
    }
    
    private static function handleCheck(int $charId, string $param, array $char): array {
        // 从 URL 参数获取目标玩家 ID
        $targetId = intval($_GET['target'] ?? $_POST['target'] ?? 0);
        
        if (!$targetId) {
            return ['success' => false, 'message' => '你想探查谁？'];
        }
        
        // 查找目标玩家
        require_once MODEL_PATH . 'Character.php';
        $target = CharacterModel::find($targetId);
        
        if (!$target) {
            return ['success' => false, 'message' => '这里没有这个人。'];
        }
        
        // 检查是否在同一房间
        if ($target['current_room'] !== $char['current_room']) {
            return ['success' => false, 'message' => '这里没有这个人。'];
        }
        
        // 检查探查条件
        $charDaoxing = intval($char['daoxing'] ?? 0);
        if ($charDaoxing < 5000) {
            return ['success' => false, 'message' => "你的道行不够（当前: {$charDaoxing}，需要: 5000），无法使用地耳灵。\n"];
        }
        
        $charMana = intval($char['mana'] ?? 0);
        if ($charMana < 100) {
            return ['success' => false, 'message' => "你的法力不够（当前: {$charMana}，需要: 100），无法使用地耳灵。\n"];
        }
        
        $charSen = intval($char['sen'] ?? 100);
        if ($charSen <= 50) {
            return ['success' => false, 'message' => "你精神太累了（当前: {$charSen}），休息休息吧！\n"];
        }
        
        // 消耗法力和精神
        $spellSkill = $char['spells'] ?? 0;
        $manaCost = -((100 - $spellSkill) / 4) - 40;
        $manaCost = $manaCost + 10;
        if ($manaCost > -50) $manaCost = -50;
        
        Database::execute(
            'UPDATE characters SET mana = mana + ?, sen = sen - ? WHERE id = ?',
            [intval($manaCost), 50, intval($charId)]
        );
        
        // 给房间内的人广播消息（给不同的人不同的消息）
        require_once DAEMON_PATH . 'MessageDaemon.php';
        
        // 获取房间内所有在线玩家
        $playersInRoom = Database::queryAll(
            "SELECT id FROM characters WHERE current_room = ? AND online = 1",
            [$char['current_room']]
        );
        
        // 获取探查者的显示名称（考虑变化状态）
        $actorDisplayName = self::getDisplayName($char, $charId);
        
        foreach ($playersInRoom as $player) {
            if ($player['id'] == $charId) {
                // 探查者不需要消息
                continue;
            } elseif ($player['id'] == $targetId) {
                // 被探查者收到第一人称消息
                $msg = HTML_HIYEL . "你忽然莫名其妙地哆嗦了一下。" . HTML_NOR;
                MessageDaemon::sendRoomMessage($player['id'], $msg, 'room');
                
                // 给被探查者发送"你心中一阵..."的消息
                $targetDaoxing = intval($target['daoxing'] ?? 0);
                $checkerDaoxing = intval($char['daoxing'] ?? 0);
                $msg2 = "你心中一阵狐疑，";
                if ($targetDaoxing > $checkerDaoxing / 5) {
                    $msg2 .= "原来是" . $actorDisplayName . "(" . $char['id'] . ")" . "在探查你的底细！";
                } else {
                    $msg2 .= "不过只是一片模糊，然后就什么也没有了。";
                }
                MessageDaemon::sendRoomMessage($player['id'], $msg2, 'room');
            } else {
                // 其他玩家收到第三人称消息
                $msg = HTML_HIYEL . $target['name'] . "忽然莫名其妙地哆嗦了一下。" . HTML_NOR;
                MessageDaemon::sendRoomMessage($player['id'], $msg, 'room');
            }
        }
        
        // 构建探查结果
        $descDaoxing = describe_dx($target['daoxing'] ?? 0);
        $descExp = describe_exp($target['combat_exp'] ?? 0);
        $descFali = describe_fali($target['max_mana'] ?? 0);
        $descNeili = describe_neili($target['max_force'] ?? 0);
        
        $resultMessage = "你口中念了几句咒语，眼中突然精光一闪，大喝一声「顺风耳何在！」<br>";
        $resultMessage .= "只听嘿嘿几声奸笑，不知从哪里钻出来一个肥头大耳的家伙，在你耳边低声说了几句话。<br>";
        $resultMessage .= "顺风耳告诉你：" . $target['name'] . "的道行已达" . $descDaoxing . "的境界。<br>";
        $resultMessage .= "顺风耳告诉你：" . $target['name'] . "的武功已达" . $descExp . "的境界。<br>";
        $resultMessage .= "顺风耳告诉你：" . $target['name'] . "的法力修为已达" . $descFali . "的境界。<br>";
        $resultMessage .= "顺风耳告诉你：" . $target['name'] . "的内力修为已达" . $descNeili . "的火候。";
        
        // 返回探查结果（不自动跳转，让用户看到探查结果）
        return [
            'success' => true,
            'message' => $resultMessage
        ];
    }
    
    private static function handleFollow(int $charId, string $param, array $char): array {
        // 获取变化后的名称
        $actorDisplayName = self::getDisplayName($char, $charId);
        
        // 先检查是否有 target 参数（跟随玩家）
        $targetId = intval($_GET['target'] ?? $_POST['target'] ?? 0);
        $npcId = intval($_GET['npc_id'] ?? $_POST['npc_id'] ?? 0);
        
        // 如果没有参数，取消跟随
        if (!$targetId && !$npcId && empty($param)) {
            // 清除跟随状态
            Database::execute(
                "UPDATE characters SET following_id = NULL WHERE id = ?",
                [$charId]
            );
            
            // 获取变化后的名称
            require_once DAEMON_PATH . 'MessageDaemon.php';
            $playersInRoom = Database::queryAll(
                'SELECT id, name FROM characters WHERE current_room = ? AND online = 1',
                [$char['current_room']]
            );
            
            foreach ($playersInRoom as $player) {
                if ($player['id'] == $charId) {
                    $msg = HTML_HIYEL . '你取消了跟随。' . HTML_NOR;
                } else {
                    $msg = HTML_HIYEL . $actorDisplayName . '取消了跟随。' . HTML_NOR;
                }
                Database::execute(
                    'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
                    [$player['id'], $msg, 'room']
                );
            }
            
            return [
                'success' => true,
                'message' => '你取消了跟随。',
                'output' => HTML_HIYEL . $actorDisplayName . '取消了跟随。' . HTML_NOR,
                'skip_queue' => true
            ];
        }
        
        if ($targetId) {
            // 跟随玩家
            require_once MODEL_PATH . 'Character.php';
            $target = CharacterModel::find($targetId);
            
            if (!$target) {
                return ['success' => false, 'message' => '你想跟随谁？'];
            }
            
            // 检查是否在同一房间
            if ($target['current_room'] !== $char['current_room']) {
                return ['success' => false, 'message' => '这里没有这个人。'];
            }
            
            // 不能跟随自己
            if ($targetId == $charId) {
                // 取消跟随
                Database::execute(
                    "UPDATE characters SET following_id = NULL WHERE id = ?",
                    [$charId]
                );
                return ['success' => true, 'message' => 'Ok.'];
            }
            
            // 保存跟随关系
            Database::execute(
                "UPDATE characters SET following_id = ? WHERE id = ?",
                [$targetId, $charId]
            );
            
            // 广播到房间（包括发送者和目标）
            require_once DAEMON_PATH . 'MessageDaemon.php';
            
            // 获取房间内所有玩家
            $playersInRoom = Database::queryAll(
                'SELECT id, name FROM characters WHERE current_room = ? AND online = 1',
                [$char['current_room']]
            );
            
            // 为每个玩家生成个性化消息并保存到消息队列
            foreach ($playersInRoom as $player) {
                if ($player['id'] == $charId) {
                    // 发送者自己看到的消息
                    $msg = HTML_HIYEL . '你决定开始跟随' . $target['name'] . '一起行动。' . HTML_NOR;
                } elseif ($player['id'] == $targetId) {
                    // 目标玩家看到的消息
                    $msg = HTML_HIYEL . $actorDisplayName . '决定开始跟随你一起行动。' . HTML_NOR;
                } else {
                    // 其他玩家看到的消息
                    $msg = HTML_HIYEL . $actorDisplayName . '决定开始跟随' . $target['name'] . '一起行动。' . HTML_NOR;
                }
                
                // 直接插入消息队列
                Database::execute(
                    'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
                    [$player['id'], $msg, 'room']
                );
            }
            
            return [
                'success' => true,
                'message' => '你决定开始跟随' . $target['name'] . '一起行动。',
                'output' => HTML_HIYEL . $actorDisplayName . '决定开始跟随' . $target['name'] . '一起行动。' . HTML_NOR,
                'skip_queue' => true  // 已经手动广播，不需要action.php再次保存
            ];
        }
        
        // 如果没有 target，则检查 npc_id（跟随 NPC）
        if ($npcId) {
            $npc = NpcModel::find($npcId);
            if (!$npc) {
                return ['success' => false, 'message' => 'NPC不存在。'];
            }

            // 精卫专属跟随逻辑（填海任务）
            if ($npc['npc_id'] === 'jingwei') {
                require_once DAEMON_PATH . 'JingweiDaemon.php';
                $result = JingweiDaemon::followJingwei($charId);
                if ($result['success']) {
                    // 跟随成功，也同时设置通用跟随状态
                    Database::execute(
                        "UPDATE characters SET following_id = ? WHERE id = ?",
                        [-$npcId, $charId]
                    );
                }
                return [
                    'success' => $result['success'],
                    'message' => $result['message']
                ];
            }

            // 保存跟随 NPC 的状态（使用负数区分玩家）
            Database::execute(
                "UPDATE characters SET following_id = ? WHERE id = ?",
                [-$npcId, $charId]
            );

            // 广播到房间（包括发送者）
            require_once DAEMON_PATH . 'MessageDaemon.php';

            // 获取房间内所有玩家
            $playersInRoom = Database::queryAll(
                'SELECT id, name FROM characters WHERE current_room = ? AND online = 1',
                [$char['current_room']]
            );

            // 为每个玩家生成个性化消息并保存到消息队列
            foreach ($playersInRoom as $player) {
                if ($player['id'] == $charId) {
                    // 发送者自己看到的消息
                    $msg = HTML_HIYEL . '你决定开始跟随' . $npc['name'] . '一起行动。' . HTML_NOR;
                } else {
                    // 其他玩家看到的消息
                    $msg = HTML_HIYEL . $actorDisplayName . '决定开始跟随' . $npc['name'] . '一起行动。' . HTML_NOR;
                }

                // 直接插入消息队列
                Database::execute(
                    'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
                    [$player['id'], $msg, 'room']
                );
            }

            return [
                'success' => true,
                'message' => '你决定开始跟随' . $npc['name'] . '一起行动。',
                'output' => HTML_HIYEL . $actorDisplayName . '决定开始跟随' . $npc['name'] . '一起行动。' . HTML_NOR,
                'skip_queue' => true  // 已经手动广播，不需要action.php再次保存
            ];
        }
        
        return ['success' => false, 'message' => '你想跟随谁？'];
    }
    
    private static function handleTransform(int $charId, string $param, array $char): array {
        $npcId = intval($_GET['npc_id'] ?? $_POST['npc_id'] ?? 0);
        $targetId = intval($_GET['target'] ?? $_POST['target'] ?? 0);
        
        // 首先检查是否正在战斗中
        if (isset($_SESSION['combat_' . $charId]) && $_SESSION['combat_' . $charId]) {
            return ['success' => false, 'message' => '你正在战斗中，无暇施展变化术。<br>'];
        }
        
        // 检查是否已经在变化状态，如果是则恢复原形
        if (isset($_SESSION['transform_' . $charId])) {
            $transformData = $_SESSION['transform_' . $charId];
            $originalName = $transformData['original_name'] ?? $char['name'];
            
            unset($_SESSION['transform_' . $charId]);
            unset($_SESSION['transform_mana_' . $charId]);
            unset($_SESSION['transform_timer_' . $charId]);
            
            // 清除数据库中的变化状态
            save_transform_state($charId, null);
            
            // 获取房间内所有在线玩家
            $roomPlayers = MessageDaemon::getRoomPlayers($char['current_room']);
            
            // 为不同玩家发送不同的消息
            foreach ($roomPlayers as $player) {
                // 判断这个玩家是不是自己
                $isSelf = ($player['id'] == $charId);
                
                if ($isSelf) {
                    // 自己看到的消息
                    $msg = HTML_HIRED . '你神色一白，一阵烟雾之后，已经恢复了原形。<br>' . HTML_NOR;
                } else {
                    // 其他人看到的消息
                    $msg = HTML_HIRED . '只见' . $originalName . '神色一白，一阵烟雾之后，已经恢复了原形。<br>' . HTML_NOR;
                }
                
                // 使用 sendPrivateMessage 发送个性化消息（直接发送给单个玩家）
                MessageDaemon::sendPrivateMessage($player['id'], $msg, $charId);
            }
            
            return ['success' => true, 'message' => HTML_HIRED . '你恢复了原形。<br>' . HTML_NOR];
        }
        
        // 准备变成新的目标
        $targetName = '';
        $targetType = ''; // 'npc' 或 'player'
        $targetData = null;
        
        if ($npcId) {
            // 变成 NPC
            $npc = NpcModel::find($npcId);
            if (!$npc) {
                return ['success' => false, 'message' => 'NPC不存在。<br>'];
            }
            $targetName = $npc['name'];
            $targetType = 'npc';
            $targetData = $npc;
        } elseif ($targetId) {
            // 变成玩家
            require_once MODEL_PATH . 'Character.php';
            $target = CharacterModel::find($targetId);
            if (!$target) {
                return ['success' => false, 'message' => '玩家不存在。<br>'];
            }
            // 检查是否在同一房间
            if ($target['current_room'] !== $char['current_room']) {
                return ['success' => false, 'message' => '这里没有这个人。<br>'];
            }
            $targetName = $target['name'];
            $targetType = 'player';
            $targetData = $target;
        } else {
            return ['success' => false, 'message' => '你想变成谁？<br>'];
        }
        
        $daoxing = $char['daoxing'] ?? 0;
        if ($daoxing < 16000) {
            return ['success' => false, 'message' => '你的道行还不够，无法施展变化术。<br>'];
        }
        
        $maxMana = $char['max_mana'] ?? 0;
        if ($maxMana < 640) {
            return ['success' => false, 'message' => '你的法力修为不够，无法支撑变化术。<br>'];
        }
        
        $mana = $char['mana'] ?? 0;
        if ($mana < 150) {
            return ['success' => false, 'message' => '你目前法力不足，无法施展变化术。<br>'];
        }
        
        $spells = 25;
        $dMana = 30 + 400 / ($spells - 20);
        
        // 获取房间内所有在线玩家
        $roomPlayers = MessageDaemon::getRoomPlayers($char['current_room']);
        
        // 为不同玩家发送不同的消息
        foreach ($roomPlayers as $player) {
            // 判断这个玩家是不是自己
            $isSelf = ($player['id'] == $charId);
            // 判断这个玩家是不是被变化的目标
            $isTargetPlayer = ($targetType === 'player' && $targetId === $player['id']);
            
            if ($isSelf) {
                // 自己看到的消息
                $msg = HTML_HIYEL . '你浑身上下真元活动，口中念念有词，摇身一变，变得和' . $targetName . '一模一样！<br>' . HTML_NOR;
            } elseif ($isTargetPlayer) {
                // 如果是被变化的目标，显示"变得和你一模一样！"
                $msg = HTML_HIYEL . '只见' . $char['name'] . '浑身上下真元活动，口中念念有词，摇身一变，变得和你一模一样！<br>' . HTML_NOR;
            } else {
                // 其他人看到正常消息
                $msg = HTML_HIYEL . '只见' . $char['name'] . '浑身上下真元活动，口中念念有词，摇身一变，变得和' . $targetName . '一模一样！<br>' . HTML_NOR;
            }
            
            // 使用 sendPrivateMessage 发送个性化消息（直接发送给单个玩家）
            MessageDaemon::sendPrivateMessage($player['id'], $msg, $charId);
        }
        
        // 保存变化信息到session
        $transformData = [
            'target_id' => $npcId ?: $targetId,
            'target_type' => $targetType,
            'target_name' => $targetName,
            'target_data' => $targetData,
            'start_time' => time(),
            'd_mana' => $dMana,
            'original_name' => $char['name']
        ];
        
        $_SESSION['transform_' . $charId] = $transformData;
        save_transform_state($charId, $transformData);
        
        Database::execute(
            'UPDATE characters SET mana = mana - 100 WHERE id = ?',
            [$charId]
        );
        
        $_SESSION['transform_timer_' . $charId] = time();
        
        return [
            'success' => true, 
            'message' => HTML_HIYEL . '你施展变化术，变成了' . $targetName . '的模样。（每次消耗' . $dMana . '点法力）' . HTML_NOR
        ];
    }
    
    /**
     * 处理问候动作 (greet)
     */
    private static function handleGreet(int $charId, string $param, array $char): array {
        // 优先检查NPC目标（掌门交互）
        $npcId = intval($_GET['npc_id'] ?? $_POST['npc_id'] ?? 0);
        if ($npcId) {
            return self::handleNpcSocialAction($charId, $npcId, $char, 'greet');
        }
        
        // 从 URL 参数获取目标玩家 ID
        $targetId = intval($_GET['target'] ?? $_POST['target'] ?? 0);
        
        if (!$targetId) {
            return ['success' => false, 'message' => '你要向谁请了？'];
        }
        
        // 构建问候消息
        require_once MODEL_PATH . 'Character.php';
        $target = CharacterModel::find($targetId);
        
        if (!$target) {
            return ['success' => false, 'message' => '这里没有这个人。'];
        }
        
        if ($target['current_room'] !== $char['current_room']) {
            return ['success' => false, 'message' => '这里没有这个人。'];
        }
        
        // 获取变化后的名称
        $actorDisplayName = self::getDisplayName($char, $charId);
        
        // 广播到房间（包括发送者和目标）
        require_once DAEMON_PATH . 'MessageDaemon.php';
        
        // 获取房间内所有玩家
        $playersInRoom = Database::queryAll(
            'SELECT id, name FROM characters WHERE current_room = ? AND online = 1',
            [$char['current_room']]
        );
        
        // 为每个玩家生成个性化消息并保存到消息队列
        foreach ($playersInRoom as $player) {
            if ($player['id'] == $charId) {
                // 发送者自己看到的消息
                $msg = HTML_HIYEL . '你对着' . $target['name'] . '作了个揖，说道："请了！"' . HTML_NOR;
            } elseif ($player['id'] == $targetId) {
                // 目标玩家看到的消息
                $msg = HTML_HIYEL . $actorDisplayName . '对着你作了个揖，说道："请了！"' . HTML_NOR;
            } else {
                // 其他玩家看到的消息
                $msg = HTML_HIYEL . $actorDisplayName . '对着' . $target['name'] . '作了个揖，说道："请了！"' . HTML_NOR;
            }
            
            // 直接插入消息队列
            Database::execute(
                'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
                [$player['id'], $msg, 'room']
            );
        }
        
        return [
            'success' => true,
            'message' => HTML_HIYEL . '你对着' . $target['name'] . '作了个揖，说道："请了！"' . HTML_NOR,
            'output' => HTML_HIYEL . $actorDisplayName . '对着' . $target['name'] . '作了个揖，说道："请了！"' . HTML_NOR,
            'skip_queue' => true  // 已经手动广播，不需要action.php再次保存
        ];
    }
    
    /**
     * 处理感谢动作 (thank)
     */
    private static function handleThank(int $charId, string $param, array $char): array {
        // 从 URL 参数获取目标玩家 ID
        $targetId = intval($_GET['target'] ?? $_POST['target'] ?? 0);
        
        if (!$targetId) {
            return ['success' => false, 'message' => '你要感谢谁？'];
        }
        
        // 构建感谢消息
        require_once MODEL_PATH . 'Character.php';
        $target = CharacterModel::find($targetId);
        
        if (!$target) {
            return ['success' => false, 'message' => '这里没有这个人。'];
        }
        
        if ($target['current_room'] !== $char['current_room']) {
            return ['success' => false, 'message' => '这里没有这个人。'];
        }
        
        // 获取变化后的名称
        $actorDisplayName = self::getDisplayName($char, $charId);
        
        // 广播到房间（包括发送者和目标）
        require_once DAEMON_PATH . 'MessageDaemon.php';
        
        // 获取房间内所有玩家
        $playersInRoom = Database::queryAll(
            'SELECT id, name FROM characters WHERE current_room = ? AND online = 1',
            [$char['current_room']]
        );
        
        // 为每个玩家生成个性化消息并保存到消息队列
        foreach ($playersInRoom as $player) {
            if ($player['id'] == $charId) {
                // 发送者自己看到的消息
                $msg = HTML_HIYEL . '你对着' . $target['name'] . '拱手道谢："多谢！"' . HTML_NOR;
            } elseif ($player['id'] == $targetId) {
                // 目标玩家看到的消息
                $msg = HTML_HIYEL . $actorDisplayName . '对着你拱手道谢："多谢！"' . HTML_NOR;
            } else {
                // 其他玩家看到的消息
                $msg = HTML_HIYEL . $actorDisplayName . '对着' . $target['name'] . '拱手道谢："多谢！"' . HTML_NOR;
            }
            
            // 直接插入消息队列
            Database::execute(
                'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
                [$player['id'], $msg, 'room']
            );
        }
        
        return [
            'success' => true,
            'message' => HTML_HIYEL . '你对着' . $target['name'] . '拱手道谢："多谢！"' . HTML_NOR,
            'output' => HTML_HIYEL . $actorDisplayName . '对着' . $target['name'] . '拱手道谢："多谢！"' . HTML_NOR,
            'skip_queue' => true  // 已经手动广播，不需要action.php再次保存
        ];
    }
    
    /**
     * 处理鞠躬动作 (bow)
     */
    private static function handleBow(int $charId, string $param, array $char): array {
        // 优先检查NPC目标（掌门交互）
        $npcId = intval($_GET['npc_id'] ?? $_POST['npc_id'] ?? 0);
        if ($npcId) {
            return self::handleNpcSocialAction($charId, $npcId, $char, 'bow');
        }
        
        // 从 URL 参数获取目标玩家 ID
        $targetId = intval($_GET['target'] ?? $_POST['target'] ?? 0);
        
        if (!$targetId) {
            return ['success' => false, 'message' => '你要向谁鞠躬？'];
        }
        
        // 构建鞠躬消息
        require_once MODEL_PATH . 'Character.php';
        $target = CharacterModel::find($targetId);
        
        if (!$target) {
            return ['success' => false, 'message' => '这里没有这个人。'];
        }
        
        if ($target['current_room'] !== $char['current_room']) {
            return ['success' => false, 'message' => '这里没有这个人。'];
        }
        
        // 获取变化后的名称
        $actorDisplayName = self::getDisplayName($char, $charId);
        
        // 广播到房间（包括发送者和目标）
        require_once DAEMON_PATH . 'MessageDaemon.php';
        
        // 获取房间内所有玩家
        $playersInRoom = Database::queryAll(
            'SELECT id, name FROM characters WHERE current_room = ? AND online = 1',
            [$char['current_room']]
        );
        
        // 为每个玩家生成个性化消息并保存到消息队列
        foreach ($playersInRoom as $player) {
            if ($player['id'] == $charId) {
                // 发送者自己看到的消息
                $msg = HTML_HIYEL . '你恭恭敬敬地向' . $target['name'] . '鞠了一躬。' . HTML_NOR;
            } elseif ($player['id'] == $targetId) {
                // 目标玩家看到的消息
                $msg = HTML_HIYEL . $actorDisplayName . '恭恭敬敬地向你鞠了一躬。' . HTML_NOR;
            } else {
                // 其他玩家看到的消息
                $msg = HTML_HIYEL . $actorDisplayName . '恭恭敬敬地向' . $target['name'] . '鞠了一躬。' . HTML_NOR;
            }
            
            // 直接插入消息队列
            Database::execute(
                'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
                [$player['id'], $msg, 'room']
            );
        }
        
        return [
            'success' => true,
            'message' => HTML_HIYEL . '你恭恭敬敬地向' . $target['name'] . '鞠了一躬。' . HTML_NOR,
            'output' => HTML_HIYEL . $actorDisplayName . '恭恭敬敬地向' . $target['name'] . '鞠了一躬。' . HTML_NOR,
            'skip_queue' => true  // 已经手动广播，不需要action.php再次保存
        ];
    }
    
    /**
     * 处理亲吻动作 (kiss)
     */
    private static function handleKiss(int $charId, string $param, array $char): array {
        // 从 URL 参数获取目标玩家 ID
        $targetId = intval($_GET['target'] ?? $_POST['target'] ?? 0);
        
        if (!$targetId) {
            return ['success' => false, 'message' => '你想亲吻谁？'];
        }
        
        // 构建亲吻消息
        require_once MODEL_PATH . 'Character.php';
        $target = CharacterModel::find($targetId);
        
        if (!$target) {
            return ['success' => false, 'message' => '这里没有这个人。'];
        }
        
        if ($target['current_room'] !== $char['current_room']) {
            return ['success' => false, 'message' => '这里没有这个人。'];
        }
        
        // 获取变化后的名称
        $actorDisplayName = self::getDisplayName($char, $charId);
        
        // 广播到房间（包括发送者和目标）
        require_once DAEMON_PATH . 'MessageDaemon.php';
        
        // 获取房间内所有玩家
        $playersInRoom = Database::queryAll(
            'SELECT id, name FROM characters WHERE current_room = ? AND online = 1',
            [$char['current_room']]
        );
        
        // 为每个玩家生成个性化消息并保存到消息队列
        foreach ($playersInRoom as $player) {
            if ($player['id'] == $charId) {
                // 发送者自己看到的消息
                $msg = HTML_HIYEL . '你轻轻地亲了' . $target['name'] . '一下。' . HTML_NOR;
            } elseif ($player['id'] == $targetId) {
                // 目标玩家看到的消息
                $msg = HTML_HIYEL . $actorDisplayName . '轻轻地亲了你一下。' . HTML_NOR;
            } else {
                // 其他玩家看到的消息
                $msg = HTML_HIYEL . $actorDisplayName . '轻轻地亲了' . $target['name'] . '一下。' . HTML_NOR;
            }
            
            // 直接插入消息队列
            Database::execute(
                'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
                [$player['id'], $msg, 'room']
            );
        }
        
        return [
            'success' => true,
            'message' => HTML_HIYEL . '你轻轻地亲了' . $target['name'] . '一下。' . HTML_NOR,
            'output' => HTML_HIYEL . $actorDisplayName . '轻轻地亲了' . $target['name'] . '一下。' . HTML_NOR,
            'skip_queue' => true  // 已经手动广播，不需要action.php再次保存
        ];
    }

    /**
     * 处理回梦技能 (huimeng / 回梦)
     * 月宫秘技，使目标陷入梦境（睡眠状态）
     * 还原原始项目：月宫弟子可对他人施展，使对方进入睡眠
     */
    private static function handleHuimeng(int $charId, string $param, array $char): array {
        $targetId = intval($_GET['target'] ?? $_POST['target'] ?? 0);
        $npcId = intval($_GET['npc_id'] ?? $_POST['npc_id'] ?? 0);

        if ($npcId) {
            // NPC 目标
            $targetType = 'npc';
            $targetId = $npcId;
            require_once MODEL_PATH . 'Npc.php';
            $target = NpcModel::find($targetId);
            if (!$target) {
                return ['success' => false, 'message' => '这里没有这个人。'];
            }
        } else {
            if (!$targetId) {
                return ['success' => false, 'message' => '你想对谁施展回梦？'];
            }
            $targetType = 'player';
            require_once MODEL_PATH . 'Character.php';
            $target = CharacterModel::find($targetId);
            if (!$target) {
                return ['success' => false, 'message' => '这里没有这个人。'];
            }
            if ($target['current_room'] !== $char['current_room']) {
                return ['success' => false, 'message' => '这里没有这个人。'];
            }
            if ($targetId === $charId) {
                return ['success' => false, 'message' => '你不能对自己施展回梦。'];
            }
        }

        // 战斗中不能施展
        require_once DAEMON_PATH . 'CombatDaemon.php';
        if (CombatDaemon::isInCombat($charId)) {
            return ['success' => false, 'message' => '战斗中无法施展回梦！'];
        }

        // 检查目标是否已经睡眠
        $alreadySleeping = false;
        if ($targetType === 'player') {
            // 目标正在修炼中，不可回梦
            if (is_player_busy($targetId)) {
                return ['success' => false, 'message' => '对方正在闭关修炼，你的回梦术对修炼者无效。'];
            }
            $alreadySleeping = !empty($target['sleep_state']) && $target['sleep_state'] == 1;
        } else {
            // NPC: 检查 npc_temp 表
            $npcSleep = Database::queryOne(
                "SELECT temp_value, updated_at FROM npc_temp WHERE npc_id = ? AND temp_key = 'sleep_state'",
                [$targetId]
            );
            $alreadySleeping = $npcSleep && $npcSleep['temp_value'] == '1' && intval($npcSleep['updated_at'] ?? 0) > time();
        }
        if ($alreadySleeping) {
            return ['success' => false, 'message' => $target['name'] . '已经陷入了梦境。'];
        }

        // 检查是否拥有回梦技能（月宫秘技：moonshentong 子法术）
        require_once HELPER_PATH . 'SkillManager.php';
        $mappedSpells = SkillManager::querySkillMapped($charId, 'spells');
        if (empty($mappedSpells) || (strpos($mappedSpells, 'moonshentong') === false && strpos($mappedSpells, 'moon') === false)) {
            return ['success' => false, 'message' => '回梦是月宫仙法中的秘技，你需要先启用月宫神通。'];
        }
        $moonshentongLevel = SkillManager::getSkillLevel($charId, $mappedSpells);
        if ($moonshentongLevel < 60) {
            return ['success' => false, 'message' => '你的月宫仙法还不够火候（需要60级以上）。'];
        }
        $spellsLevel = SkillManager::querySkill($charId, 'spells');
        if ($spellsLevel < 100) {
            return ['success' => false, 'message' => '你的有效法术等级不够（需要100以上）。'];
        }

        // 检查法力消耗（需要足够的法力）
        $manaCost = 200;
        if (intval($char['mana'] ?? 0) < $manaCost) {
            return ['success' => false, 'message' => '你的法力不足以施展回梦。'];
        }

        // 成功率判定（基于月宫仙法等级）
        $successRate = min(0.8, max(0.2, $moonshentongLevel / 200));
        if (mt_rand() / mt_getrandmax() > $successRate) {
            // 失败：消耗法力但未生效
            Database::execute(
                'UPDATE characters SET mana = GREATEST(mana - ?, 0) WHERE id = ?',
                [$manaCost, $charId]
            );
            $failMsg = '你试图对' . $target['name'] . '施展回梦，但失败了！';
            self::broadcastSocialToRoom($charId, $char['current_room'], $char, $target, 'huimeng_fail');
            return ['success' => false, 'message' => $failMsg];
        }

        // 成功：设置目标睡眠状态
        $sleepDuration = 60; // 睡眠60秒
        $endTime = time() + $sleepDuration;

        Database::beginTransaction();
        try {
            // 消耗法力
            Database::execute(
                'UPDATE characters SET mana = GREATEST(mana - ?, 0) WHERE id = ?',
                [$manaCost, $charId]
            );
            // 设置目标睡眠
            if ($targetType === 'player') {
                Database::execute(
                    'UPDATE characters SET sleep_state = 1, sleep_end_time = ? WHERE id = ?',
                    [$endTime, $targetId]
                );
            } else {
                // NPC: 存入 npc_temp 表（updated_at 记录睡眠结束时间）
                Database::execute(
                    "INSERT INTO npc_temp (npc_id, temp_key, temp_value, updated_at) VALUES (?, 'sleep_state', '1', ?) ON DUPLICATE KEY UPDATE temp_value = '1', updated_at = ?",
                    [$targetId, $endTime, $endTime]
                );
            }
            Database::commit();
        } catch (\Exception $e) {
            Database::rollBack();
            return ['success' => false, 'message' => '施展回梦失败，请稍后再试。'];
        }

        // 广播消息
        $actorDisplayName = self::getDisplayName($char, $charId);
        require_once DAEMON_PATH . 'MessageDaemon.php';
        $playersInRoom = Database::queryAll(
            'SELECT id, name FROM characters WHERE current_room = ? AND online = 1',
            [$char['current_room']]
        );

        foreach ($playersInRoom as $player) {
            if ($player['id'] == $charId) {
                $msg = '<span style="color:#dda0dd;">你轻启朱唇，对' . $target['name'] . '施展了回梦之术，' . $target['name'] . '眼神渐渐迷离，陷入了梦境。</span>';
            } elseif ($targetType === 'player' && $player['id'] == $targetId) {
                $msg = '<span style="color:#dda0dd;">' . $actorDisplayName . '对你施展了回梦之术，你感到一阵困意袭来，不知不觉陷入了梦境...</span>';
            } else {
                $msg = '<span style="color:#dda0dd;">' . $actorDisplayName . '对' . $target['name'] . '施展了回梦之术，' . $target['name'] . '眼神渐渐迷离，陷入了梦境。</span>';
            }
            Database::execute(
                'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
                [$player['id'], $msg, 'room']
            );
        }

        return [
            'success' => true,
            'message' => '<span style="color:#dda0dd;">你成功对' . $target['name'] . '施展了回梦之术！</span>',
            'skip_queue' => true,
        ];
    }

    /**
     * 辅助：广播社交动作到房间（用于回梦失败等场景）
     */
    private static function broadcastSocialToRoom(int $charId, string $room, array $char, array $target, string $action): void {
        $actorDisplayName = self::getDisplayName($char, $charId);
        require_once DAEMON_PATH . 'MessageDaemon.php';
        $playersInRoom = Database::queryAll(
            'SELECT id, name FROM characters WHERE current_room = ? AND online = 1',
            [$room]
        );
        foreach ($playersInRoom as $player) {
            $msg = '';
            if ($player['id'] == $charId) {
                if ($action === 'huimeng_fail') {
                    $msg = '<span style="color:#ff6347;">你试图对' . $target['name'] . '施展回梦，但被对方灵巧地避开了！</span>';
                }
            } else {
                if ($action === 'huimeng_fail') {
                    $msg = '<span style="color:#ff6347;">' . $actorDisplayName . '试图对' . $target['name'] . '施展回梦，但未能成功。</span>';
                }
            }
            if ($msg) {
                Database::execute(
                    'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
                    [$player['id'], $msg, 'room']
                );
            }
        }
    }

    private static function handleTrade(int $charId, string $param, array $char): array {
        $npcId = intval($_GET['npc_id'] ?? $_POST['npc_id'] ?? 0);
        if (!$npcId) {
            return ['success' => false, 'message' => '你想和谁交易？<br>'];
        }
        
        $npc = NpcModel::find($npcId);
        if (!$npc) {
            return ['success' => false, 'message' => 'NPC不存在。<br>'];
        }
        
        // 如果是 AJAX 请求，返回 JSON 包含重定向信息
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return ['success' => true, 'redirect' => 'trade.php?npc_id=' . $npcId];
        }
        
        // 非 AJAX 请求直接重定向
        header('Location: trade.php?npc_id=' . $npcId);
        exit;
    }
    
    private static function handleJicun(int $charId, string $param, array $char): array {
        $commandFile = __DIR__ . '/commands/' . $param . '.php';
        if (file_exists($commandFile)) {
            require_once $commandFile;
            return $result ?? ['success' => false, 'message' => '命令执行失败'];
        }
        return ['success' => false, 'message' => "命令 {$param} 不存在。\n"];
    }
    
    private static function handleGet(int $charId, string $param, array $char): array {
        // 支持从 GET/POST 获取 item_id
        $itemId = $_GET['item_id'] ?? $_POST['item_id'] ?? $param;
        
        if (function_exists('cmd_get')) {
            $result = cmd_get($charId, (string)$itemId);
            
            if ($result['success'] && !empty($result['broadcast_message'])) {
                MessageDaemon::broadcastToRoom(
                    $char['current_room'],
                    $result['broadcast_message'],
                    $charId
                );
            }
            
            return $result;
        }
        return ['success' => true, 'message' => '你捡起了物品。'];
    }
    
    private static function handleDrop(int $charId, string $param, array $char): array {
        // 支持从 GET/POST 获取 item_id 和 quantity
        $itemId = $_GET['item_id'] ?? $_POST['item_id'] ?? $param;
        $quantity = intval($_GET['quantity'] ?? $_POST['quantity'] ?? 0);
        
        if (function_exists('cmd_drop')) {
            $result = cmd_drop($charId, (string)$itemId, $quantity);
            
            if ($result['success'] && !empty($result['broadcast_message'])) {
                require_once DAEMON_PATH . 'MessageDaemon.php';
                MessageDaemon::broadcastToRoom(
                    $char['current_room'],
                    $result['broadcast_message'],
                    $charId
                );
            }
            
            return $result;
        }
        return ['success' => true, 'message' => '你丢下了物品。'];
    }
    
    private static function handleCarry(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/carry.php';
        if (function_exists('cmd_carry')) {
            $result = cmd_carry($charId, (string)$param);
            
            if ($result['success'] && !empty($result['broadcast_message'])) {
                MessageDaemon::broadcastToRoom(
                    $char['current_room'],
                    $result['broadcast_message'],
                    $charId
                );
            }
            
            return $result;
        }
        return ['success' => false, 'message' => '背起功能不可用'];
    }
    
    private static function handleBury(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/bury.php';
        if (function_exists('cmd_bury')) {
            $result = cmd_bury($charId, (string)$param);
            
            if ($result['success'] && !empty($result['broadcast_message'])) {
                MessageDaemon::broadcastToRoom(
                    $char['current_room'],
                    $result['broadcast_message'],
                    $charId
                );
            }
            
            return $result;
        }
        return ['success' => false, 'message' => '埋葬功能不可用'];
    }
    
    private static function handleTell(int $charId, string $param, array $char): array {
        if (function_exists('cmd_tell')) {
            return cmd_tell($charId, (string)$param);
        }
        return ['success' => false, 'message' => '私聊功能不可用'];
    }
    
    private static function handleReply(int $charId, string $param, array $char): array {
        if (function_exists('cmd_reply')) {
            return cmd_reply($charId, (string)$param);
        }
        return ['success' => false, 'message' => '回复功能不可用'];
    }
    
    private static function handleWear(int $charId, string $param, array $char): array {
        $itemId = $_GET['item_id'] ?? $_POST['item_id'] ?? $param;
        $category = $_GET['category'] ?? $_POST['category'] ?? '';
        
        if (function_exists('cmd_wear')) {
            return cmd_wear($charId, (string)$itemId, $category);
        }
        return ['success' => true, 'message' => '你穿上了装备。'];
    }
    
    private static function handleWield(int $charId, string $param, array $char): array {
        $itemId = $_GET['item_id'] ?? $_POST['item_id'] ?? $param;
        $category = $_GET['category'] ?? $_POST['category'] ?? '';
        
        if (function_exists('cmd_wield')) {
            return cmd_wield($charId, (string)$itemId, $category);
        }
        return ['success' => true, 'message' => '你拿起了武器。'];
    }
    
    private static function handleUnwield(int $charId, string $param, array $char): array {
        $itemId = $_GET['item_id'] ?? $_POST['item_id'] ?? $param;
        
        if (function_exists('cmd_unwield')) {
            return cmd_unwield($charId, (string)$itemId);
        }
        return ['success' => false, 'message' => '放下武器功能不可用'];
    }

    private static function handleRemove(int $charId, string $param, array $char): array {
        $itemId = $_GET['item_id'] ?? $_POST['item_id'] ?? $param;
        
        if (function_exists('cmd_remove')) {
            return cmd_remove($charId, (string)$itemId);
        }
        return ['success' => true, 'message' => '你卸下了装备。'];
    }
    
    private static function handleInventory(int $charId, string $param, array $char): array {
        // 如果是 AJAX 请求，返回 JSON 包含重定向信息
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return ['success' => true, 'redirect' => 'inventory.php'];
        }
        
        // 非 AJAX 请求直接重定向
        redirect('inventory.php');
    }
    
    private static function handleScore(int $charId, string $param, array $char): array {
        // 如果是 AJAX 请求，返回 JSON 包含重定向信息
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return ['success' => true, 'redirect' => 'score.php'];
        }
        
        // 非 AJAX 请求直接重定向
        redirect('score.php');
    }
    
    /**
     * 处理吃命令
     */
    private static function handleEat(int $charId, string $param, array $char): array {
        if (!function_exists('cmd_eat')) {
            require_once __DIR__ . '/../commands/food_water.php';
        }
        return cmd_eat($charId, $param);
    }
    
    /**
     * 处理喝命令
     */
    private static function handleDrink(int $charId, string $param, array $char): array {
        if (!function_exists('cmd_drink')) {
            require_once __DIR__ . '/../commands/food_water.php';
        }
        return cmd_drink($charId, $param);
    }
    
    /**
     * 处理装水命令 (fill)
     * 兼容三种语义：
     *   - "fill sea" / "fill 海" / "fill 东海" 在海滩房间 → 填海（JingweiDaemon）
     *   - 在金平府民居房间 → 灌酥合香油（油葫芦）
     *   - "fill <容器>" → 装水（food_water.php::cmd_fill）
     */
    private static function handleFill(int $charId, string $param, array $char): array {
        // 填海分支：在海滩房间且参数为 sea/海/东海
        $charRoom = ($char['current_room'] ?? '');
        // current_room 可能存储完整路径(如 changan/beach)或仅房间名(如 beach)
        if (strpos($charRoom, '/') === false) {
            $charRoom = ($char['current_area'] ?? '') . '/' . $charRoom;
        }
        $fillSeaParams = ['sea', '海', '东海', 'hai'];
        if ($charRoom === 'changan/beach' && in_array(trim($param), $fillSeaParams, true)) {
            require_once __DIR__ . '/JingweiDaemon.php';
            $result = JingweiDaemon::fillSea($charId);
            return [
                'success' => $result['success'],
                'message' => $result['message']
            ];
        }

        // 金平府灌油分支：在民居房间且有油葫芦
        $jinpingOilRooms = [
            'qujing/jinping/minju1',
            'qujing/jinping/minju2',
            'qujing/jinping/minju3',
            'qujing/jinping/minju4',
        ];
        if (in_array($charRoom, $jinpingOilRooms, true)) {
            return self::handleJinpingFillOil($charId, $char);
        }

        if (!function_exists('cmd_fill')) {
            require_once __DIR__ . '/../commands/food_water.php';
        }
        return cmd_fill($charId, $param);
    }

    /**
     * 金平府灌油逻辑
     * 参考 xyj2000/d/qujing/jinping/obj/hulu.c do_fill()
     * 在民居油罐灌满油葫芦，油罐耗尽后10分钟再生
     */
    private static function handleJinpingFillOil(int $charId, array $char): array {
        require_once MODEL_PATH . 'Item.php';
        require_once DAEMON_PATH . 'MessageDaemon.php';

        $roomId = ($char['current_room'] ?? '');
        if (strpos($roomId, '/') === false) {
            $roomId = ($char['current_area'] ?? '') . '/' . $roomId;
        }

        // 查找玩家背包中的油葫芦/油瓶
        // items表有三种油容器: hulu(油葫芦,qujing)、youhulu(油葫芦,food)、youping(油瓶,city/obj)
        $inventory = ItemModel::getCharacterItems($charId);
        $hulu = null;
        foreach ($inventory as $item) {
            $iid = $item['item_id'] ?? '';
            $iname = $item['name'] ?? '';
            if ($iid === 'hulu' || $iid === 'youhulu' || $iid === 'youping' ||
                $iname === '油葫芦' || $iname === '油瓶') {
                $hulu = $item;
                break;
            }
        }
        if (!$hulu) {
            return ['success' => false, 'message' => '你身上没有油葫芦。'];
        }

        // 检查容器是否已装有油
        $liquidRemaining = intval($hulu['liquid_remaining'] ?? 0);
        if ($liquidRemaining > 0) {
            return ['success' => false, 'message' => '容器里已装有酥合香油了。'];
        }

        // 检查房间油罐是否有油（通过room_states追踪）
        $oilRegenSeconds = 600;
        $row = Database::queryOne(
            "SELECT value FROM room_states WHERE room_path = ? AND state_key = 'oil_taken_at'",
            [$roomId]
        );
        if ($row) {
            $takenAt = intval($row['value']);
            if ((time() - $takenAt) < $oilRegenSeconds) {
                return ['success' => false, 'message' => '罐子里已没有酥合香油了。'];
            }
        }

        // 灌满油葫芦
        $invId = $hulu['id'] ?? 0;
        $huluName = $hulu['name'] ?? '油葫芦';
        if ($invId > 0) {
            Database::execute(
                "UPDATE character_inventory SET liquid_remaining = 10, liquid_type = 'oil', liquid_name = '酥合香油' WHERE id = ?",
                [$invId]
            );
        } else {
            $huluItemId = $hulu['item_id'] ?? 'hulu';
            $huluCat = $hulu['category'] ?? 'qujing';
            Database::execute(
                "UPDATE character_inventory SET liquid_remaining = 10, liquid_type = 'oil', liquid_name = '酥合香油'
                 WHERE char_id = ? AND item_id = ? AND COALESCE(category, '') = ?",
                [$charId, $huluItemId, $huluCat]
            );
        }

        // 标记房间油罐已耗尽
        Database::execute(
            "INSERT INTO room_states (room_path, state_key, value, created_at, updated_at)
             VALUES (?, 'oil_taken_at', ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()",
            [$roomId, strval(time())]
        );

        $msg = '<span style="color:#FFD700;">你将' . $huluName . '灌满酥合香油。</span>';

        MessageDaemon::broadcastToRoom($roomId,
            '<span style="color:#FFD700;">' . $char['name'] . '将' . $huluName . '灌满酥合香油。</span>',
            $charId, 'room');

        return ['success' => true, 'message' => $msg];
    }

    /**
     * 处理倒掉命令 (pour)
     * 兼容三种语义：
     *   - 在金灯桥房间 → 将酥合香油倒入金灯缸（金平府任务）
     *   - "pour <容器>"         → 倒空液体容器（food_water.php::cmd_pour）
     *   - "pour <药> in <容器>" → 把迷魂散倒入液体容器（toy.php::cmd_poison_pour，还原 LPC 原版）
     */
    private static function handlePour(int $charId, string $param, array $char): array {
        // 金平府倒油分支：在金灯桥房间
        $charRoom = ($char['current_room'] ?? '');
        if (strpos($charRoom, '/') === false) {
            $charRoom = ($char['current_area'] ?? '') . '/' . $charRoom;
        }
        if ($charRoom === 'qujing/jinping/qiao') {
            return self::handleJinpingPourOil($charId, $char);
        }

        if (strpos($param, ' in ') !== false) {
            if (!function_exists('cmd_poison_pour')) {
                require_once __DIR__ . '/../commands/toy.php';
            }
            return cmd_poison_pour($charId, $param);
        }

        if (!function_exists('cmd_pour')) {
            require_once __DIR__ . '/../commands/food_water.php';
        }
        return cmd_pour($charId, $param);
    }

    /**
     * 金平府倒油逻辑
     * 参考 xyj2000/d/qujing/jinping/obj/hulu.c do_pour() + coming()
     * 将油葫芦中的酥合香油倒入金灯缸，达到所需次数后佛爷出现
     */
    private static function handleJinpingPourOil(int $charId, array $char): array {
        require_once MODEL_PATH . 'Item.php';
        require_once DAEMON_PATH . 'MessageDaemon.php';

        $roomId = 'qujing/jinping/qiao';

        // 查找玩家背包中的油葫芦/油瓶
        // items表有三种油容器: hulu(油葫芦,qujing)、youhulu(油葫芦,food)、youping(油瓶,city/obj)
        $inventory = ItemModel::getCharacterItems($charId);
        $hulu = null;
        foreach ($inventory as $item) {
            $iid = $item['item_id'] ?? '';
            $iname = $item['name'] ?? '';
            if ($iid === 'hulu' || $iid === 'youhulu' || $iid === 'youping' ||
                $iname === '油葫芦' || $iname === '油瓶') {
                $hulu = $item;
                break;
            }
        }
        if (!$hulu) {
            return ['success' => false, 'message' => '你身上没有油葫芦。'];
        }

        // 检查容器是否有油
        $huluName = $hulu['name'] ?? '油葫芦';
        $liquidRemaining = intval($hulu['liquid_remaining'] ?? 0);
        $liquidType = trim($hulu['liquid_type'] ?? '');
        $liquidName = trim($hulu['liquid_name'] ?? '');
        if ($liquidRemaining <= 0) {
            return ['success' => false, 'message' => $huluName . '里没有油。'];
        }
        if ($liquidType !== 'oil' && $liquidName !== '酥合香油') {
            return ['success' => false, 'message' => $huluName . '里装的不是酥合香油。'];
        }

        // 倒空油容器
        $invId = $hulu['id'] ?? 0;
        if ($invId > 0) {
            Database::execute(
                "UPDATE character_inventory SET liquid_remaining = 0, liquid_type = '', liquid_name = '' WHERE id = ?",
                [$invId]
            );
        } else {
            $huluItemId = $hulu['item_id'] ?? 'hulu';
            $huluCat = $hulu['category'] ?? 'qujing';
            Database::execute(
                "UPDATE character_inventory SET liquid_remaining = 0, liquid_type = '', liquid_name = ''
                 WHERE char_id = ? AND item_id = ? AND COALESCE(category, '') = ?",
                [$charId, $huluItemId, $huluCat]
            );
        }

        // 增加倒油计数
        $oilCount = self::getJinpingOilCount($charId);
        $oilCount++;
        self::setJinpingOilCount($charId, $oilCount);

        $msg = '<span style="color:#FFD700;">你将' . $huluName . '里的酥合香油倒进金灯缸。</span>';

        MessageDaemon::broadcastToRoom($roomId,
            '<span style="color:#FFD700;">' . $char['name'] . '将' . $huluName . '里的酥合香油倒进金灯缸。</span>',
            $charId, 'room');

        // 计算还需要倒多少次
        $kar = intval($char['kar'] ?? 10);
        $required = 40 - $kar;
        if ($required < 10) {
            $required = 10;
        }
        $remaining = $required - $oilCount;

        if ($remaining > 0) {
            $msg .= "\n" . '<span style="color:#87CEEB;">灯官告诉你：再倒' . self::chineseNumber($remaining) . '次便可。</span>';
            return ['success' => true, 'message' => $msg];
        }

        // 倒油次数足够，佛爷出现！
        $msg .= "\n" . '<span style="color:#FF00FF;">灯官告诉你：佛爷要来了！</span>';
        $foyeMsg = self::triggerJinpingFoyeEvent($charId, $char, $roomId, $hulu);
        $msg .= "\n" . $foyeMsg;

        return ['success' => true, 'message' => $msg];
    }

    /**
     * 触发金平府佛爷出现事件
     * 参考 xyj2000/d/qujing/jinping/obj/hulu.c coming()
     */
    private static function triggerJinpingFoyeEvent(int $charId, array $char, string $currentRoom, array $hulu): string {
        $messages = [];
        $huluInvId = $hulu['id'] ?? 0;

        // 1. 佛爷出现
        $messages[] = '<span style="color:#FF00FF;">一阵狂风吹来，佛爷出现！</span>';
        MessageDaemon::broadcastToRoom($currentRoom,
            '<span style="color:#FF00FF;">一阵狂风吹来，佛爷出现！</span>',
            $charId, 'room');

        // 2. 搜走非绑定物品
        $inventory = ItemModel::getCharacterItems($charId);
        foreach ($inventory as $item) {
            $invId = $item['id'] ?? 0;
            if ($invId > 0 && $invId == $huluInvId) {
                continue;
            }

            $itemInfo = Database::queryOne(
                "SELECT no_drop, no_sell FROM items WHERE item_id = ? AND category = ? LIMIT 1",
                [$item['item_id'] ?? '', $item['category'] ?? '']
            );
            $noDrop = intval($itemInfo['no_drop'] ?? 0);
            $noSell = intval($itemInfo['no_sell'] ?? 0);
            $equipped = intval($item['equipped'] ?? 0);
            if ($noDrop || $noSell || $equipped) {
                continue;
            }

            $itemName = $item['name'] ?? $item['item_id'] ?? '物品';
            $messages[] = '<span style="color:#FFD700;">佛爷从' . $char['name'] . '身上搜出' . $itemName . '！</span>';
            if ($invId > 0) {
                Database::execute("DELETE FROM character_inventory WHERE id = ?", [$invId]);
            }
        }

        // 3. 佛爷携着玩家飞上天空
        $messages[] = '<span style="color:#FF00FF;">佛爷携着' . $char['name'] . '飞上天空！</span>';
        MessageDaemon::broadcastToRoom($currentRoom,
            '<span style="color:#FF00FF;">佛爷携着' . $char['name'] . '飞上天空！</span>',
            $charId, 'room');

        // 4. 传送到青龙山山头
        $targetRoom = 'qujing/qinglong/shantou';
        Database::execute(
            "UPDATE characters SET current_room = ?, current_area = 'qujing' WHERE id = ?",
            [$targetRoom, $charId]
        );

        // 5. 打昏玩家
        $con = intval($char['con'] ?? 10);
        $duration = random_int(30, max(30, 120 - $con * 2));
        $endTime = time() + $duration;
        Database::execute(
            'UPDATE characters SET unconscious_state = 1, unconscious_end_time = ?, kee = 0, gin = 0, sen = 0 WHERE id = ?',
            [$endTime, $charId]
        );
        $_SESSION["unconscious_{$charId}"] = [
            'timestamp' => time(),
            'duration' => $duration,
        ];

        $messages[] = '<span style="color:#FF00FF;">佛爷突然停下来，顺便将' . $char['name'] . '往地上一扔！</span>';
        MessageDaemon::broadcastToRoom($targetRoom,
            '<span style="color:#FF00FF;">佛爷携着' . $char['name'] . '从天而降，将其往地上一扔！</span>',
            $charId, 'room');

        return implode("\n", $messages);
    }

    /**
     * 获取玩家金平府倒油计数
     */
    private static function getJinpingOilCount(int $charId): int {
        $row = Database::queryOne(
            "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = 'obstacle/jinping_oil'",
            [$charId]
        );
        return $row ? intval($row['state_value']) : 0;
    }

    /**
     * 设置玩家金平府倒油计数
     */
    private static function setJinpingOilCount(int $charId, int $count): void {
        Database::execute(
            'INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = NOW()',
            [$charId, 'obstacle/jinping_oil', strval($count)]
        );
    }

    /**
     * 中文数字（简单版）
     */
    private static function chineseNumber(int $n): string {
        $digits = ['零', '一', '二', '三', '四', '五', '六', '七', '八', '九'];
        if ($n < 10) return $digits[$n];
        if ($n < 20) return '十' . ($n % 10 > 0 ? $digits[$n % 10] : '');
        if ($n < 100) {
            $tens = intval($n / 10);
            $ones = $n % 10;
            return $digits[$tens] . '十' . ($ones > 0 ? $digits[$ones] : '');
        }
        return strval($n);
    }
    
    /**
     * 处理使用药品命令 (use)
     */
    private static function handleUse(int $charId, string $param, array $char): array {
        if (!function_exists('cmd_use')) {
            require_once __DIR__ . '/../commands/food_water.php';
        }
        $result = cmd_use($charId, $param);
        
        // 使用物品成功后，广播到房间（让 room.php 和 chat.php 都能看到）
        if ($result['success'] && !empty($char['current_room'])) {
            require_once DAEMON_PATH . 'MessageDaemon.php';
            $charName = $char['name'] ?? '某人';
            $broadcastMsg = $result['broadcast_message'] ?? "{$charName}使用了{item}。";
            // 替换 {item} 占位符
            $broadcastMsg = str_replace('{item}', $param, $broadcastMsg);
            MessageDaemon::broadcastToRoom($char['current_room'], $broadcastMsg, $charId);
        }
        
        return $result;
    }
    
    /**
     * 处理阅读命令 (read)
     */
    private static function handleRead(int $charId, string $param, array $char): array {
        if (!function_exists('cmd_read')) {
            require_once __DIR__ . '/../commands/read.php';
        }
        return cmd_read($charId, $param);
    }
    
    /**
     * 处理撕书命令 (tear/si)
     * 
     * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
     * 撕开无字天书 → 获得避水咒(zhou)，天书销毁
     */
    private static function handleTear(int $charId, string $param, array $char): array {
        require_once MODEL_PATH . 'Item.php';
        require_once DAEMON_PATH . 'MessageDaemon.php';
        
        // param 是 item_id（从URL传过来的）
        $itemId = $param ?: ($_GET['param'] ?? '');
        
        if (empty($itemId)) {
            return ['success' => false, 'message' => '你要撕什么？'];
        }
        
        // 检查背包中是否有该物品
        // 注意：尸体拾取不保留category，所以JOIN只匹配item_id（不要求category一致）
        $item = Database::queryOne(
            "SELECT ci.id, ci.item_id, ci.quantity, ci.category, i.name FROM character_inventory ci JOIN items i ON ci.item_id = i.item_id WHERE ci.char_id = ? AND ci.item_id = ? LIMIT 1",
            [$charId, $itemId]
        );
        
        if (!$item) {
            return ['success' => false, 'message' => '你身上没有这样东西。'];
        }
        
        // 根据物品类型执行不同的撕破逻辑
        if ($item['item_id'] === 'nowords') {
            // === 撕无字天书 → 获得避水咒 ===
            // 移除无字天书
            ItemModel::removeFromInventory($charId, 'nowords', 1);
            
            // 添加避水咒
            ItemModel::addToInventory($charId, 'zhou', 1);
            
            // 构建消息
            $msg = HTML_HIYEL . '你嘶啦一声撕开了' . $item['name'] . '的书页……' . HTML_NOR . "\n";
            $msg .= HTML_HICYN . '从书页夹层中滑出一张小纸片——上面写着"避水咒"三个字！' . HTML_NOR . "\n";
            $msg .= HTML_HIGRN . '（获得：避水咒）' . HTML_NOR;
            
            // 广播到房间
            $roomId = $char['current_room'] ?? '';
            if (!empty($roomId)) {
                MessageDaemon::broadcastToRoom($roomId,
                    HTML_HIYEL . $char['name'] . '撕开了一本古书，从书页中取出一张小纸片。' . HTML_NOR,
                    $charId, 'room');
            }
        } elseif ($item['item_id'] === 'sengxie') {
            // === 撕僧鞋 → 获得东海蓬莱山地图 ===
            // 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
            // 移除僧鞋（指定category）
            $cat = $item['category'] ?? '';
            if (!empty($cat)) {
                ItemModel::removeFromInventory($charId, 'sengxie', 1, $cat);
            } else {
                ItemModel::removeFromInventory($charId, 'sengxie', 1);
            }
            
            // 添加东海蓬莱山地图
            ItemModel::addToInventory($charId, 'ditu', 1, 'penglai');
            
            // 构建消息
            $msg = HTML_HIYEL . '你使劲一撕僧鞋……鞋底夹层里露出一张小纸片！' . HTML_NOR . "\n";
            $msg .= HTML_HICYN . '原来是一张手绘的地图——上面标注着东海蓬莱山的位置！' . HTML_NOR . "\n";
            $msg .= HTML_HIGRN . '（获得：东海蓬莱山地图）' . HTML_NOR;
            
            // 广播到房间
            $roomId = $char['current_room'] ?? '';
            if (!empty($roomId)) {
                MessageDaemon::broadcastToRoom($roomId,
                    HTML_HIYEL . $char['name'] . '用力撕破了一双僧鞋，从鞋底夹层中取出一张小纸片。' . HTML_NOR,
                    $charId, 'room');
            }
        } else {
            return ['success' => false, 'message' => '这样东西不能撕开。'];
        }
        
        return [
            'success' => true,
            'message' => $msg,
        ];
    }
    
    /**
     * 处理恢复命令
     */
    private static function handleRecover(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../helpers/FoodWaterHelper.php';
        return FoodWaterHelper::recover($charId);
    }

    /**
     * 处理技能激发命令 (enable/jifa)
     */
    private static function handleEnable(int $charId, string $param, array $char): array {
        $type = $_GET['type'] ?? '';
        $skill = $_GET['skill'] ?? '';

        // 如果有 type 参数，执行激发/取消操作
        if (!empty($type)) {
            if (!function_exists('cmd_enable')) {
                return ['success' => false, 'message' => '技能系统未加载'];
            }

            $paramStr = ($skill === 'none') ? "$type none" : "$type $skill";
            $result = cmd_enable($charId, $paramStr);

            $message = $result['output'] ?? $result['message'] ?? '';

            // 操作成功后刷新页面
            return [
                'success' => $result['success'],
                'message' => $message,
                'redirect' => 'action.php?action=enable'
            ];
        }

        // 无参数：渲染技能管理页面
        $data = self::renderEnablePage($charId);
        $html = self::renderEnableHtml($data);
        return ['success' => true, 'html' => $html];
    }

    /**
     * 获取技能激发页面数据（公开方法，供 skills_enable.php 调用）
     */
    public static function renderEnablePagePublic(int $charId): array {
        return self::renderEnablePage($charId);
    }

    /**
     * 将技能激发数据渲染为 HTML（供 handleEnable 内部使用）
     */
    private static function renderEnableHtml(array $data): string {
        $activeTypes = $data['activeTypes'];
        $currentMap = $data['currentMap'];
        $skillsByType = $data['skillsByType'];
        $charId = $data['charId'];

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
    <title>技能激发_西游记mud</title>
    <link rel="stylesheet" href="../assets/css/skills.css">
    <style>
        .skill-page { max-width: 400px; }
        h5 { color: #ffcc80; font-size: 11px; margin: 8px 0 4px 0; }
        .tag-mapped { color: #00ff00; font-size: 10px; }
        .tag-none { color: #666; font-size: 10px; }
        .btn-enable { color: #66ccff; font-size: 11px; text-decoration: none; }
        .btn-enable:hover { color: #99ddff; }
        .btn-disable { color: #ff6666; font-size: 11px; text-decoration: none; margin-left: 6px; }
        .btn-disable:hover { color: #ff8888; }
        .btn-abandon { color: #ff9966; font-size: 11px; text-decoration: none; margin-left: 6px; cursor: pointer; }
        .btn-abandon:hover { color: #ffbb88; }
    </style>
    <script>
    function abandonSkill(skillId, skillName, skillLevel) {
        if (skillLevel <= 0) { return; }
        var input = prompt('放弃「' + skillName + '」(等级' + skillLevel + ')\n\n请输入要放弃的等级数：\n（输入 ' + skillLevel + ' 则完全放弃，输入 0 取消）');
        if (input === null || input === '') return;
        var level = parseInt(input);
        if (isNaN(level) || level < 0) { alert('请输入有效的数字。'); return; }
        if (level === 0) return;
        if (level > skillLevel) { level = skillLevel; }
        var msg = (level >= skillLevel)
            ? '确定要完全放弃「' + skillName + '」(等级' + skillLevel + ')？\n此操作不可撤销！'
            : '确定要放弃「' + skillName + '」的 ' + level + ' 级吗？\n将从 ' + skillLevel + ' 级降至 ' + (skillLevel - level) + ' 级。\n此操作不可撤销！';
        if (!confirm(msg)) return;
        window.location.href = 'action.php?action=enable&param=' + encodeURIComponent(skillId) + '&level=' + level;
    }
    </script>
</head>
<body>
<div class="skill-page">
<h3>【技能激发】</h3>

<!-- 当前技能映射 -->
<h4>当前技能映射</h4>
<table class="skill-table">
    <tr>
        <th>技能类型</th>
        <th>当前激发</th>
        <th>等级</th>
        <th>有效</th>
        <th>操作</th>
    </tr>
    <?php
    $hasMapped = false;
    foreach ($activeTypes as $type => $desc):
        $hasMapped = true;
        $mapped = $currentMap[$type] ?? null;
        $mappedName = $mapped ? SkillManager::getSkillChineseName($mapped) : '无';
        $rawLevel = SkillManager::querySkill($charId, $type, true);
        $finalLevel = SkillManager::querySkill($charId, $type, false);
    ?>
    <tr>
        <td><span class="skill-name"><?php echo h($desc); ?></span> <span class="skill-id">(<?php echo h($type); ?>)</span></td>
        <td><?php if ($mapped): ?><span class="tag-mapped"><?php echo h($mappedName); ?></span> <span class="skill-id">(<?php echo h($mapped); ?>)</span><?php else: ?><span class="tag-none">无</span><?php endif; ?></td>
        <td><span class="lv"><?php echo $rawLevel; ?></span></td>
        <td><span class="lv"><?php echo $finalLevel; ?></span></td>
        <td>
            <?php if ($mapped): ?>
            <a href="action.php?action=enable&amp;type=<?php echo h($type); ?>&amp;skill=none" class="btn-disable">取消激发</a>
            <?php else: ?>
                <?php $availableSkills = $skillsByType[$type] ?? []; ?>
                <?php if (!empty($availableSkills)): ?>
                <a href="#enable_<?php echo h($type); ?>" class="btn-enable">激发</a>
                <?php else: ?>
                <span class="tag-none">-</span>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($rawLevel > 0): ?>
            <a href="javascript:abandonSkill('<?php echo h($type); ?>','<?php echo h($desc); ?>',<?php echo $rawLevel; ?>)" class="btn-abandon">放弃</a>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$hasMapped): ?>
    <tr><td colspan="5" class="no-data">你现在没有使用任何特殊技能。</td></tr>
    <?php endif; ?>
</table>

<!-- 可激发的技能列表 -->
<?php
$hasEnableable = false;
foreach ($activeTypes as $type => $desc):
    $typeSkills = $skillsByType[$type] ?? [];
    if (empty($typeSkills)) continue;
    $hasEnableable = true;
    $currentMapped = $currentMap[$type] ?? null;
?>
<h4 id="enable_<?php echo h($type); ?>"><?php echo h($desc); ?></h4>
<table class="skill-table">
    <tr>
        <th>技能</th>
        <th>等级</th>
        <th>有效</th>
        <th>操作</th>
    </tr>
    <?php foreach ($typeSkills as $skill):
        $skillId = $skill['skill_id'];
        $skillName = $skill['name'] ?? SkillManager::getSkillChineseName($skillId);
        $skillLevel = $skill['level'] ?? 0;
        $effectiveLevel = SkillManager::querySkill($charId, $skillId, false);
        $isMapped = ($currentMapped === $skillId);
    ?>
    <tr>
        <td><span class="skill-name"><?php echo h($skillName); ?></span> <span class="skill-id">(<?php echo h($skillId); ?>)</span></td>
        <td><span class="lv"><?php echo $skillLevel; ?></span></td>
        <td><span class="lv"><?php echo $effectiveLevel; ?></span></td>
        <td>
            <?php if ($isMapped): ?>
            <span class="tag-mapped">已激发</span>
            <a href="action.php?action=enable&amp;type=<?php echo h($type); ?>&amp;skill=none" class="btn-disable">取消</a>
            <?php else: ?>
            <a href="action.php?action=enable&amp;type=<?php echo h($type); ?>&amp;skill=<?php echo h($skillId); ?>" class="btn-enable">激发</a>
            <?php endif; ?>
            <a href="javascript:abandonSkill('<?php echo h($skillId); ?>','<?php echo h($skillName); ?>',<?php echo $skillLevel; ?>)" class="btn-abandon">放弃</a>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endforeach; ?>
<?php if (!$hasEnableable): ?>
<p class="no-data">你还没有学会任何可以激发的特殊技能。</p>
<?php endif; ?>

<a href="room.php" class="btn-back">返回游戏</a>
</div>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * 获取练功页面数据（公开方法，供 skills_practice.php 调用）
     */
    public static function renderPracticePagePublic(int $charId): array {
        return self::renderPracticePage($charId);
    }

    /**
     * 将练功数据渲染为 HTML（供 handlePractice 内部使用）
     */
    private static function renderPracticeHtml(array $data): string {
        $combatExp = $data['combatExp'];
        $potential = $data['potential'];
        $availablePotential = $data['availablePotential'];
        $potentialCostPerRound = $data['potentialCostPerRound'];
        $skillMap = $data['skillMap'];
        $skills = $data['skills'];

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
    <title>练功_西游记mud</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background-color: #1a1a1a;
            color: #ccc;
            font-family: 'Microsoft YaHei', 'PingFang SC', sans-serif;
            padding: 10px;
            font-size: 13px;
            line-height: 1.5;
        }
        .skill-page {
            background-color: #2d2d2d;
            border: 2px solid #4a4a4a;
            border-radius: 8px;
            padding: 12px;
            margin: 0 auto;
            box-shadow: 0 5px 15px rgba(0,0,0,0.5);
        }
        h3 {
            color: #FFD700;
            text-align: center;
            font-size: 14px;
            margin-bottom: 10px;
            font-weight: bold;
        }
        h4 {
            color: #90EE90;
            font-size: 12px;
            margin: 10px 0 6px 0;
            padding-bottom: 3px;
            border-bottom: 1px solid #444;
        }
        .info-bar {
            margin-bottom: 12px;
            padding: 8px 10px;
            background-color: #222;
            border: 1px solid #444;
            border-radius: 4px;
            color: #aaa;
            font-size: 11px;
        }
        .info-bar strong { color: #B6D4FE; }
        .skill-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }
        .skill-table th {
            text-align: left;
            padding: 2px 4px;
            color: #aaa;
            font-weight: bold;
            font-size: 10px;
            border-bottom: 1px solid #444;
        }
        .skill-table td {
            padding: 2px 4px;
            color: #ccc;
            vertical-align: middle;
        }
        .skill-table tr:hover td {
            background-color: #3a3a3a;
        }
        .skill-name { color: #eee; }
        .skill-id { color: #888; font-size: 10px; }
        .lv { color: #ffd700; font-weight: bold; }
        .btn-practice { color: #66ccff; font-size: 11px; text-decoration: none; cursor: pointer; }
        .btn-practice:hover { color: #99ddff; }
        .btn-back {
            display: block;
            width: 100%;
            margin-top: 12px;
            padding: 7px;
            background-color: #555;
            border: none;
            border-radius: 4px;
            color: #fff;
            cursor: pointer;
            font-size: 12px;
            text-align: center;
            text-decoration: none;
        }
        .btn-back:hover { background-color: #666; }
        .no-data { color: #888; font-size: 11px; padding: 4px 0; }
        .tag-disabled { color: #666; font-size: 11px; }
    </style>
</head>
<body>
<div class="skill-page">
<h3>【练功】</h3>

<div class="info-bar">
    <strong>修为：</strong><?php echo number_format($combatExp); ?> &nbsp;&nbsp;
    <strong>潜能：</strong><?php echo $availablePotential; ?> / <?php echo $potential; ?> &nbsp;&nbsp;
    <strong>每次消耗：</strong><?php echo $potentialCostPerRound; ?> 点
</div>

<h4>可练习的技能</h4>
<?php if (empty($skillMap)): ?>
    <p class="no-data">你还没有激发任何技能。请先到 <a href="skills_enable.php" style="color: #66ccff;">技能激发</a> 页面设置技能映射。</p>
<?php else: ?>
    <table class="skill-table">
        <tr>
            <th>技能类型</th>
            <th>当前映射</th>
            <th>等级</th>
            <th>有效</th>
            <th>操作</th>
        </tr>
        <?php foreach ($skills as $s): ?>
        <tr>
            <td><span class="skill-name"><?php echo h($s['typeName']); ?></span> <span class="skill-id">(<?php echo h($s['type']); ?>)</span></td>
            <td><span class="skill-name"><?php echo h($s['skillName']); ?></span> <span class="skill-id">(<?php echo h($s['skillId']); ?>)</span></td>
            <td><span class="lv"><?php echo $s['skillLevel']; ?></span></td>
            <td><span class="lv"><?php echo $s['effectiveLevel']; ?></span></td>
            <td>
                <?php if ($s['canPractice']): ?>
                    <a href="javascript:void(0)" onclick="practiceSkill('<?php echo urlencode($s['type']); ?>')" class="btn-practice">练习</a>
                <?php else: ?>
                    <?php if ($availablePotential <= 0): ?>
                        <span class="tag-disabled">潜能不足</span>
                    <?php else: ?>
                        <span class="tag-disabled" title="需要修为: <?php echo number_format($s['requiredExp']); ?>">修为不足</span>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<script>
function practiceSkill(type) {
    var times = prompt('请输入练习次数（可用潜能: <?php echo $availablePotential; ?>）', '1');
    if (times === null) return;
    times = parseInt(times);
    if (isNaN(times) || times < 1) {
        alert('请输入有效的练习次数');
        return;
    }
    window.location.href = 'action.php?action=practice&type=' + type + '&times=' + times;
}
</script>

<a href="room.php" class="btn-back">返回游戏</a>
</div>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * 获取技能激发管理页面数据
     */
    private static function renderEnablePage(int $charId): array {
        $validTypes = [
            "unarmed"    => "拳脚",
            "sword"      => "剑法",
            "blade"      => "刀法",
            "stick"      => "棍法",
            "staff"      => "杖法",
            "throwing"   => "暗器",
            "force"      => "内功",
            "parry"      => "招架",
            "dodge"      => "轻功",
            "spells"     => "法术",
            "whip"       => "鞭法",
            "spear"      => "枪法",
            "axe"        => "斧法",
            "mace"       => "锏法",
            "fork"       => "叉法",
            "rake"       => "钯法",
            "archery"    => "弓箭",
            "hammer"     => "锤法",
            "magic"      => "魔法",
            "literate"   => "读书写字",
            "buddhism"   => "佛法",
            "daoism"     => "道法",
            "taiyi"      => "太乙",
        ];

        // 获取角色已学会的所有技能
        $learnedSkills = SkillManager::getAllSkills($charId);

        // 获取当前技能映射
        $currentMap = [];
        foreach ($validTypes as $type => $desc) {
            $mapped = SkillManager::querySkillMapped($charId, $type);
            if ($mapped) {
                $currentMap[$type] = $mapped;
            }
        }

        // 按类型分组可激发的技能（已学会的、非基础类型的技能）
        $skillsByType = [];
        foreach ($validTypes as $type => $desc) {
            $skillsByType[$type] = [];
        }

        foreach ($learnedSkills as $skill) {
            $skillId = $skill['skill_id'];
            $skillConfig = SkillManager::getSkillConfig($skillId);
            if (!$skillConfig) {
                continue;
            }

            // 检查 valid_enable 字段，确定该技能可用于哪些类型
            $validEnableRaw = $skillConfig['valid_enable'] ?? null;
            if (!empty($validEnableRaw)) {
                $validEnable = is_string($validEnableRaw)
                    ? json_decode($validEnableRaw, true)
                    : $validEnableRaw;
                if (is_array($validEnable)) {
                    foreach ($validEnable as $enableType) {
                        // 排除基础类型自身（如 dodge 的 valid_enable 是 ["dodge"]，不应出现在可激发列表中）
                        if (isset($validTypes[$enableType]) && $enableType !== $skillId) {
                            $skillsByType[$enableType][] = $skill;
                        }
                    }
                }
            }
        }

        // 收集需要显示的技能类型：有映射 OR 有基础等级 OR 有可激发技能
        $activeTypes = [];
        foreach ($validTypes as $type => $desc) {
            $hasMap = isset($currentMap[$type]);
            $baseLevel = SkillManager::querySkill($charId, $type, true);
            $hasSkills = !empty($skillsByType[$type]);
            if ($hasMap || $baseLevel > 0 || $hasSkills) {
                $activeTypes[$type] = $desc;
            }
        }

        return [
            'charId'        => $charId,
            'activeTypes'   => $activeTypes,
            'currentMap'    => $currentMap,
            'skillsByType'  => $skillsByType,
            'learnedSkills' => $learnedSkills,
        ];
    }

    private static function handleGo(int $charId, string $param, array $char): array {
        if (!function_exists('cmd_go')) {
            return ['success' => false, 'message' => '移动功能未实现'];
        }
        
        $result = cmd_go($charId, $param);
        
        if ($result['success'] && isset($result['new_room'])) {
            // 广播离开/到达消息给房间里的其他人（不包括自己）
            // 注意：不需要给自己发 leave/arrive 消息，因为玩家会通过页面跳转看到新房间
            if (!empty($result['leave_message']) && isset($result['old_room'])) {
                MessageDaemon::broadcastToRoom(
                    $result['old_room']['room_id'],
                    $result['leave_message'],
                    $charId
                );
            }
            
            if (!empty($result['arrive_message'])) {
                MessageDaemon::broadcastToRoom(
                    $result['new_room']['room_id'],
                    $result['arrive_message'],
                    $charId
                );
            }
            
            // ★ 开封解谜：进入房间时检查是否有done任务对应房间内的NPC
            // 模仿原始LPC NPC check_player() 自动检测机制
            $newRoomId = $result['new_room']['room_id'];
            $newAreaName = $result['new_room']['area'];
            if (file_exists(__DIR__ . '/../helpers/QuestHelper.php')) {
                require_once __DIR__ . '/../helpers/QuestHelper.php';
                
                // 1. 检查 done 状态任务（完成目标等待领奖）
                $doneQuests = QuestHelper::getDoneQuests($charId);
                if (!empty($doneQuests)) {
                    $npcMap = QuestHelper::getNpcMap();
                    foreach ($doneQuests as $dq) {
                        $dqType = $dq['quest_type'] ?? '';
                        foreach ($npcMap as $npcId => $npcInfo) {
                            if ($npcInfo['quest_type'] === $dqType) {
                                $npcRoom = $npcInfo['room'] ?? '';
                                $fullNpcRoom = $newAreaName . '/' . $newRoomId;
                                if ($npcRoom === $fullNpcRoom || strpos($npcRoom, $newRoomId) !== false || strpos($fullNpcRoom, $npcRoom) !== false) {
                                    $npcName = $npcInfo['name'];
                                    $questName = $dq['quest_name'] ?? '';
                                    $hintMsg = HTML_HIYEL . "【提示】" . HTML_NOR . "你完成了「{$questName}」，{$npcName}就在这里，快去领赏吧！";
                                    MessageDaemon::sendToPlayer($charId, $hintMsg, 'quest_hint');
                                }
                            }
                        }
                    }
                }
                
                // 2. ★ 赴京请赏提醒：如果 quest_reward > 0，任何开封NPC都应提示
                //    参考原始LPC greeting.h: who->query("quest/reward") > 0 → "仙体祥云笼罩，请速去拜见吾王太宗！"
                $charReward = intval($char['quest_reward'] ?? 0);
                if ($charReward > 0) {
                    $npcMap2 = QuestHelper::getNpcMap();
                    $colorData = QuestHelper::getColorCounter($charId);
                    $colorCount = $colorData['count'];
                    
                    foreach ($npcMap2 as $npcId => $npcInfo) {
                            $npcRoom = $npcInfo['room'] ?? '';
                            $fullNpcRoom = $newAreaName . '/' . $newRoomId;
                            if ($npcRoom === $fullNpcRoom || strpos($npcRoom, $newRoomId) !== false || strpos($fullNpcRoom, $npcRoom) !== false) {
                                $npcName = $npcInfo['name'];
                                if ($colorCount > 1) {
                                    $colorDesc = QuestHelper::getColorCloudsDisplay($charId);
                                    $cloudNames = array_column($colorDesc, 'name');
                                    $cloudStr = implode('、', $cloudNames);
                                    $greetingMsg = HTML_HICYN . "{$npcName}" . HTML_NOR . "向你一躬：仙体{$cloudStr}祥云笼罩，请速去拜见吾王太宗！";
                                } else {
                                    $greetingMsg = HTML_HICYN . "{$npcName}" . HTML_NOR . "向你一躬：仙体祥云笼罩，请速去拜见吾王太宗！";
                                }
                                MessageDaemon::sendToPlayer($charId, $greetingMsg, 'quest_greeting');
                            }
                        }
                }
            }
            
            $newArea = preg_replace('/^d\//', '', $result['new_room']['area']);
            $redirectUrl = room_url($newArea, $result['new_room']['room_id']);
            
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                // 清空输出缓冲区，防止BOM或其他意外字符干扰JSON解析
                ob_clean();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'message' => $result['message'],
                    'redirect' => $redirectUrl
                ]);
                exit;
            }
            
            redirect($redirectUrl);
        }
        
        return $result;
    }
    
    /**
     * 处理自定义房间动作
     */
    public static function handleCustomAction(int $charId, string $cmd, string $param): array {
        error_log("[handleCustomAction] charId=$charId, cmd=$cmd, param=$param");
        
        $char = CharacterModel::find($charId);
        if (!$char) {
            error_log("[handleCustomAction] 角色不存在");
            return ['success' => false, 'message' => '角色不存在'];
        }

        // 优先使用请求参数中的房间ID，否则使用角色当前房间
        $roomId = $_GET['room'] ?? $_POST['room'] ?? $char['current_room'];
        error_log("[handleCustomAction] roomId=$roomId");
        
        $action = self::findRoomAction($roomId, $cmd, $param);
        error_log("[handleCustomAction] findRoomAction结果: " . ($action ? print_r($action, true) : 'null'));

        if ($action) {
            if (!empty($action['handler_class'])) {
                return self::executeWithHandler($charId, $action, ['arg' => $param]);
            } else {
                return self::executeDefaultAction($charId, $action, $param);
            }
        }

        // 未找到匹配的房间动作，尝试作为表情命令处理
        error_log("[handleCustomAction] 未找到房间动作，尝试表情命令");
        require_once __DIR__ . '/EmoteDaemon.php';
        $emoteResult = EmoteDaemon::execute($charId, $cmd, !empty($param) ? $param : null);
        if ($emoteResult['success']) {
            return $emoteResult;
        }

        // 表情也不匹配，返回失败
        return ['success' => false, 'message' => "无法执行动作: {$cmd}"];
    }

    private static function findRoomAction(string $roomId, string $cmd, string $param): ?array {
        // 优先尝试完整命令（当cmd包含空格时，如 "wa qiuyin"）
        $fullCmd = !empty($param) ? $cmd . ' ' . $param : $cmd;
        
        // 调试日志：记录查询参数
        error_log("[findRoomAction] roomId=$roomId, cmd=$cmd, param=$param, fullCmd=$fullCmd");
        
        // 首先尝试精确匹配
        $sql = "SELECT * FROM room_actions WHERE room_id = ? AND action_cmd = ? AND enabled = 1 LIMIT 1";
        $action = Database::queryOne($sql, [$roomId, $fullCmd]);
        
        if ($action) {
            error_log("[findRoomAction] 精确匹配成功: " . print_r($action, true));
            return $action;
        }
        
        // 如果精确匹配失败，尝试用 LIKE 匹配 room_id（处理 room_id 格式不一致的情况）
        $roomPattern = $roomId . '%';
        $sql = "SELECT * FROM room_actions WHERE room_id LIKE ? AND action_cmd = ? AND enabled = 1 LIMIT 1";
        $action = Database::queryOne($sql, [$roomPattern, $fullCmd]);
        
        if ($action) {
            error_log("[findRoomAction] LIKE匹配成功: " . print_r($action, true));
            return $action;
        }
        
        // 尝试反向匹配（当 roomId 是完整路径但数据库中是短路径时）
        $shortRoomId = basename($roomId);
        $sql = "SELECT * FROM room_actions WHERE room_id = ? AND action_cmd = ? AND enabled = 1 LIMIT 1";
        $action = Database::queryOne($sql, [$shortRoomId, $fullCmd]);
        
        if ($action) {
            error_log("[findRoomAction] 短路径匹配成功: " . print_r($action, true));
            return $action;
        }
        
        // 尝试数据库中的 room_id 包含 roomId 的情况
        $roomPattern2 = '%' . $roomId . '%';
        $sql = "SELECT * FROM room_actions WHERE room_id LIKE ? AND action_cmd = ? AND enabled = 1 LIMIT 1";
        $action = Database::queryOne($sql, [$roomPattern2, $fullCmd]);
        
        if ($action) {
            error_log("[findRoomAction] 包含匹配成功: " . print_r($action, true));
            return $action;
        }
        
        // 如果完整命令没找到，尝试单独的命令（当param为空时）
        if (empty($param)) {
            $action = Database::queryOne("SELECT * FROM room_actions WHERE room_id = ? AND action_cmd = ? AND enabled = 1 LIMIT 1", [$roomId, $cmd]);
            if ($action) {
                error_log("[findRoomAction] 单独命令精确匹配成功: " . print_r($action, true));
                return $action;
            }
            
            $action = Database::queryOne("SELECT * FROM room_actions WHERE room_id LIKE ? AND action_cmd = ? AND enabled = 1 LIMIT 1", [$roomPattern, $cmd]);
            if ($action) {
                error_log("[findRoomAction] 单独命令LIKE匹配成功: " . print_r($action, true));
                return $action;
            }
            
            $action = Database::queryOne("SELECT * FROM room_actions WHERE room_id = ? AND action_cmd = ? AND enabled = 1 LIMIT 1", [$shortRoomId, $cmd]);
            if ($action) {
                error_log("[findRoomAction] 单独命令短路径匹配成功: " . print_r($action, true));
                return $action;
            }
        }
        
        error_log("[findRoomAction] 未找到匹配的房间动作");
        return null;
    }
    
    private static function executeWithHandler(int $charId, array $action, array $params = []): array {
        $handlerClass = $action['handler_class'];
        $handlerFile = __DIR__ . '/' . $handlerClass . '.php';
        
        if (!file_exists($handlerFile)) {
            error_log("Handler file not found: {$handlerFile}");
            return ['success' => false, 'message' => '动作处理器不存在', 'data' => null];
        }
        
        require_once $handlerFile;
        
        if (!class_exists($handlerClass)) {
            error_log("Handler class not found: {$handlerClass}");
            return ['success' => false, 'message' => '动作处理器类不存在', 'data' => null];
        }
        
        $handler = new $handlerClass();
        
        if (!($handler instanceof ActionHandler)) {
            error_log("Handler does not extend ActionHandler: {$handlerClass}");
            return ['success' => false, 'message' => '动作处理器类型错误', 'data' => null];
        }
        
        try {
            $result = $handler->execute($charId, $action, $params);
            return $result;
        } catch (\Exception $e) {
            error_log("Handler execution error: " . $e->getMessage());
            return ['success' => false, 'message' => '动作执行失败', 'data' => null];
        }
    }
    
    private static function executeDefaultAction(int $charId, array $action, string $param): array {
        require_once __DIR__ . '/DefaultActionHandler.php';
        $handler = new DefaultActionHandler();
        return $handler->execute($charId, $action, ['arg' => $param]);
    }
    
    /**
     * 处理旧版遗留动作（已迁移到数据库 + Handler 架构）
     * 现在作为 fallback，直接委托给 handleCustomAction
     * 原有的硬编码 switch-case 逻辑已迁移到对应的 Handler 类
     */
    private static function handleLegacyAction(int $charId, string $cmd, string $param): array {
        return ['success' => false, 'message' => "无法执行动作: {$cmd}"];
    }
    
    /**
     * 处理灭妖任务 - 杀妖怪
     */
    private static function handleKillYaoguai(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/MieyaoHandler.php';
        require_once DAEMON_PATH . 'CombatDaemon.php';
        
        $yaoguaiId = intval($_GET['yaoguai_id'] ?? $_POST['yaoguai_id'] ?? 0);
        
        if (!$yaoguaiId) {
            return ['success' => false, 'message' => '你要杀哪个妖怪？'];
        }
        
        // 获取妖怪信息
        $yaoguai = Database::queryOne("SELECT * FROM mieyao_yaoguai WHERE id = ?", [$yaoguaiId]);
        
        if (!$yaoguai || $yaoguai['is_killed']) {
            return ['success' => false, 'message' => '妖怪已不存在'];
        }
        
        // 检查是否在同一房间
        if ($yaoguai['area'] != $char['current_area'] || $yaoguai['room_id'] != $char['current_room']) {
            return ['success' => false, 'message' => '妖怪不在这个房间！'];
        }
        
        // 调用 CombatDaemon 开始与妖怪的战斗
        $result = CombatDaemon::startKill($charId, $yaoguaiId, 'yaoguai');
        
        if (!$result['success']) {
            return $result;
        }
        
        // 重定向到战斗页面
        header('Location: fight.php');
        exit;
    }

    /**
     * 处理放弃灭妖任务
     */
    private static function handleAbandonMieyao(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/MieyaoHandler.php';
        
        $result = MieyaoHandler::abandonTask($charId);
        
        if ($result['success']) {
            $redirectUrl = 'quests.php';
            
            // 如果是 AJAX 请求，返回 JSON 包含重定向信息
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                return ['success' => true, 'redirect' => $redirectUrl];
            }
            
            // 非 AJAX 请求直接重定向
            header('Location: ' . $redirectUrl);
            exit;
        }
        
        return $result;
    }

    /**
     * 处理房产申请动作
     */
    private static function handleApply(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/HomeHandler.php';

        $handler = new HomeHandler();
        $action = [
            'action_name' => '申购房产',
            'action_cmd' => 'apply house',
        ];
        return $handler->execute($charId, $action, ['arg' => $param]);
    }
    
    /**
     * 处理修理命令 (repair)
     * 花费银两修复装备耐久
     */
    private static function handleRepair(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/repair.php';
        return cmd_repair($charId, $param);
    }

    /**
     * 处理祭法宝命令 (ji)
     */
    private static function handleJi(int $charId, string $param, array $char): array {
        if (function_exists('cmd_ji')) {
            return cmd_ji($charId, $param);
        }
        return ['success' => false, 'message' => '祭法宝功能不可用'];
    }

    /**
     * 处理逃脱法宝命令 (out)
     */
    private static function handleOut(int $charId, string $param, array $char): array {
        if (function_exists('cmd_out')) {
            return cmd_out($charId, $param);
        }
        return ['success' => false, 'message' => '逃脱功能不可用'];
    }
    
    /**
     * 处理任务领取动作
     */
    private static function handleQuest(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/QuestHandler.php';
        
        $handler = new QuestHandler();
        // 我们需要构造一个基本的 action 数组
        $action = [
            'action_cmd' => 'quest',
            'description' => '领取任务'
        ];
        
        return $handler->execute($charId, $action, []);
    }
    
    private static function handleAutoFindYaoguai(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/AutoFindYaoguaiHandler.php';
        
        $handler = new AutoFindYaoguaiHandler();
        return $handler->execute($charId, [], []);
    }

    /**
     * 处理拜师命令 (apprentice / 拜师 / baishi)
     */
    private static function handleApprentice(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/apprentice.php';
        if (function_exists('cmd_apprentice')) {
            return cmd_apprentice($charId, $param);
        }
        return ['success' => false, 'message' => '拜师功能不可用。'];
    }

    /**
     * 处理收徒命令 (recruit / 收徒 / shoutu)
     */
    private static function handleRecruit(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/recruit.php';
        if (function_exists('cmd_recruit')) {
            return cmd_recruit($charId, $param);
        }
        return ['success' => false, 'message' => '收徒功能不可用。'];
    }

    /**
     * 处理逐出命令 (expell / 逐出 / zhuchu)
     */
    private static function handleExpell(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/expell.php';
        if (function_exists('cmd_expell')) {
            return cmd_expell($charId, $param);
        }
        return ['success' => false, 'message' => '逐出师门功能不可用。'];
    }

    /**
     * 处理判师命令 (leaveSect / 判师 / 背叛)
     */
    private static function handleLeaveSect(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/ApprenticeHandler.php';
        
        $result = ApprenticeHandler::leaveSect($charId);
        if ($result['success']) {
            $_SESSION['flash_message'] = $result['message'];
        }
        
        return $result;
    }

    /**
     * 处理学习技能命令 (learn / 学习 / xuexi)
     * 已迁移到独立页面 skills_learn.php?npc_id=xxx
     * 此处仅保留玉鼠精惩罚检查，否则重定向到独立页面
     */
    private static function handleLearn(int $charId, string $param, array $char): array {
        $npcId = intval($_GET['npc_id'] ?? 0);

        // ★ 玉鼠精惩罚检查：背叛无底洞后向玉鼠精学技能触发惩罚
        // 还原原始LPC yushu.c::prevent_learn() 逻辑
        if ($npcId === 652) { // 玉鼠精 NPC ID
            require_once __DIR__ . '/../helpers/YushuPunishHelper.php';
            if (YushuPunishHelper::hasBetrayedWudidong($charId)) {
                $punishResult = YushuPunishHelper::executePunishment($charId);
                if (!empty($punishResult['success'])) {
                    return $punishResult;
                }
            }
        }

        // 重定向到独立学习页面
        return [
            'success'    => true,
            'redirect'   => 'skills_learn.php?npc_id=' . $npcId,
            'skip_flash' => true,
            'skip_queue' => true,
        ];
    }

    /**
     * 向玩家师傅学习技能（按原始项目 learn.c 逻辑还原）
     * 显示师傅已学会的所有技能列表
     */
    private static function handleLearnFromMaster(int $charId, string $param, array $char): array {
        $masterId = intval($_GET['master_id'] ?? 0);

        if (!$masterId) {
            return ['success' => false, 'message' => '请指定要学习的师傅。'];
        }

        require_once __DIR__ . '/../helpers/SkillManager.php';
        require_once __DIR__ . '/../helpers/SectHelper.php';
        require_once __DIR__ . '/../helpers/AttributeHelper.php';
        require_once __DIR__ . '/ApprenticeHandler.php';

        // 检查师徒关系
        if (!ApprenticeHandler::isApprenticeOf($charId, $masterId)) {
            return ['success' => false, 'message' => '对方不是你的师父。'];
        }

        // 获取师傅信息
        $master = Database::queryOne('SELECT id, name, family, current_room, sen, max_sen FROM characters WHERE id = ?', [$masterId]);
        if (!$master) {
            return ['success' => false, 'message' => '师傅不存在。'];
        }

        // 检查是否在同一房间
        if (($master['current_room'] ?? '') !== ($char['current_room'] ?? '')) {
            return ['success' => false, 'message' => '师父不在你身边。'];
        }

        // 获取徒弟悟性（用于计算每次学习提升量 random(int)+1）
        $playerInt = AttributeHelper::queryInt($char);

        // 获取徒弟潜能和已学习点数（原始项目：可用潜能 = potential - learned_points）
        $playerInfo = Database::queryOne('SELECT potential, learned_points, combat_exp, daoxing FROM characters WHERE id = ?', [$charId]);
        $potential = intval($playerInfo['potential'] ?? 0);
        $learnedPoints = intval($playerInfo['learned_points'] ?? 0);
        $availablePotential = $potential - $learnedPoints;
        $combatExp = intval($playerInfo['combat_exp'] ?? 0);
        $daoxing = intval($playerInfo['daoxing'] ?? 0);

        // 获取师傅的所有技能
        $masterSkills = SkillManager::getAllSkills($masterId);

        // 过滤可教技能：
        // - 排除基础技能类型（unarmed, sword, dodge 等技能类别本身）
        // - 师傅至少 1 级（玩家师傅不能教 0→1，但本列表只显示师傅会的）
        $teachableSkills = [];
        foreach ($masterSkills as $ms) {
            $skillId = $ms['skill_id'] ?? '';
            $masterLevel = intval($ms['level'] ?? 0);
            if (SkillManager::isBaseSkillType($skillId)) {
                continue;
            }
            if ($masterLevel < 1) {
                continue;
            }

            // 获取技能类型（martial/magic）用于经验限制检查
            $skillType = $ms['type'] ?? 'martial';

            // 弟子当前等级
            $playerLevel = SkillManager::getSkillLevel($charId, $skillId);

            // 经验限制检查：能否继续学习
            $expBlocked = false;
            $expBlockReason = '';
            if ($playerLevel >= 100) {
                $expBlocked = true;
                $expBlockReason = '已达100级硬上限';
            } elseif ($playerLevel < 1) {
                $expBlocked = true;
                $expBlockReason = '需先学会基础(≥1级)才能向玩家师傅学习';
            } elseif ($skillType === 'martial' && $playerLevel > 0) {
                // 武技：combat_exp >= skill³ / 10
                $expNeeded = intval(pow($playerLevel, 3) / 10);
                if ($combatExp < $expNeeded) {
                    $expBlocked = true;
                    $expBlockReason = '战斗经验不足(需' . $expNeeded . ')';
                }
            } elseif ($skillType === 'magic' && $playerLevel > 0) {
                // 法术：daoxing >= skill³ / 10
                $expNeeded = intval(pow($playerLevel, 3) / 10);
                if ($daoxing < $expNeeded) {
                    $expBlocked = true;
                    $expBlockReason = '道行不足(需' . $expNeeded . ')';
                }
            }

            // 超过师傅等级时：只能积累，不能升级（仍可学习）
            $beyondMaster = $playerLevel >= $masterLevel;

            $teachableSkills[] = [
                'id' => $skillId,
                'name' => $ms['name'] ?? $skillId,
                'type' => $skillType,
                'master_level' => $masterLevel,
                'player_level' => $playerLevel,
                'exp_blocked' => $expBlocked,
                'exp_block_reason' => $expBlockReason,
                'beyond_master' => $beyondMaster,
            ];
        }

        // 按技能类型分组
        $grouped = [];
        foreach ($teachableSkills as $ts) {
            $type = $ts['type'] ?: '其他';
            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            $grouped[$type][] = $ts;
        }

        if (empty($teachableSkills)) {
            return ['success' => false, 'message' => '师父目前没有可以传授给你的技能。'];
        }

        // 渲染学习页面（与NPC学习页面一致的样式）
        $masterName = h($master['name']);
        $masterIdH = $masterId;
        $availablePotentialH = $availablePotential;
        $playerSenH = intval($playerInfo['sen'] ?? 0);
        $masterSenH = intval($master['sen'] ?? 0);
        ob_start();
        ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>学习技能 - <?php echo $masterName; ?></title>
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #1a1a1a;
            color: #e0e0e0;
            font-family: "Microsoft YaHei", sans-serif;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
            padding: 20px;
        }
        .learn-modal {
            background-color: #2d2d2d;
            border: 2px solid #4a4a4a;
            border-radius: 8px;
            padding: 16px;
            max-width: 400px;
            width: 100%;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 5px 20px rgba(0,0,0,0.5);
        }
        .learn-modal h3 {
            color: #FFD700;
            margin: 0 0 6px 0;
            text-align: center;
            font-size: 16px;
            font-weight: bold;
        }
        .learn-modal-desc {
            color: #aaaaaa;
            font-size: 12px;
            margin-bottom: 12px;
            text-align: center;
        }
        .learn-potential {
            color: #ff9966;
            font-size: 12px;
            text-align: center;
            margin-bottom: 8px;
            padding: 6px;
            background: #1e2a3a;
            border-radius: 4px;
            border: 1px solid #3a5a7a;
        }
        .learn-stats {
            color: #88ccff;
            font-size: 12px;
            margin-bottom: 12px;
            padding: 6px 8px;
            background: #1a1a2e;
            border-radius: 4px;
            border: 1px solid #3a3a5a;
            line-height: 1.6;
        }
        .learn-stats span { color: #ffd700; }
        .learn-skill-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 6px;
        }
        .learn-skill-btn {
            background-color: #3d3d3d;
            border: 1px solid #555555;
            border-radius: 4px;
            padding: 10px 12px;
            cursor: pointer;
            text-align: left;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .learn-skill-btn:hover:not(.disabled) {
            background-color: #4a4a4a;
            border-color: #888888;
        }
        .learn-skill-btn:active:not(.disabled) {
            transform: scale(0.99);
        }
        .learn-skill-btn.beyond {
            border-color: #f39c12;
            background-color: #3a2e20;
        }
        .learn-skill-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .learn-skill-info {
            flex: 1;
            min-width: 0;
        }
        .learn-skill-name {
            color: #ffffff;
            font-weight: bold;
            font-size: 13px;
            margin-bottom: 3px;
        }
        .learn-skill-desc {
            color: #90EE90;
            font-size: 11px;
        }
        .learn-skill-desc.beyond { color: #f39c12; }
        .learn-skill-desc.blocked { color: #e74c3c; }
        .learn-skill-level {
            color: #90EE90;
            font-size: 12px;
            font-weight: bold;
            flex-shrink: 0;
            text-align: right;
            min-width: 60px;
        }
        .learn-skill-level.master-level {
            color: #87ceeb;
            font-size: 11px;
            font-weight: normal;
        }
        .learn-btn {
            flex: 1;
            padding: 5px 8px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 11px;
            transition: all 0.2s ease;
            text-align: center;
        }
        .learn-btn.learn {
            background-color: #3a6a3a;
            color: #ffffff;
        }
        .learn-btn.learn:hover { background-color: #4a8a4a; }
        .learn-btn.learn:disabled { background-color: #555; cursor: not-allowed; }
        .learn-modal-close {
            display: block;
            width: 100%;
            margin-top: 12px;
            padding: 8px;
            background-color: #555555;
            border: none;
            border-radius: 4px;
            color: #ffffff;
            cursor: pointer;
            font-size: 13px;
        }
        .learn-modal-close:hover { background-color: #666666; }

        @media screen and (max-width: 375px) {
            body { padding: 10px; }
            .learn-modal { padding: 12px; max-height: 80vh; }
            .learn-modal h3 { font-size: 14px; }
            .learn-skill-btn { padding: 8px 10px; }
            .learn-skill-name { font-size: 12px; }
        }
    </style>
</head>
<body>
    <div class="learn-modal">
        <h3><?php echo $masterName; ?> · 师徒授业</h3>
        <div class="learn-modal-desc">选择你要学习的技能</div>
        <div class="learn-potential">
            <strong>可用潜能：</strong><?php echo $availablePotentialH; ?> 点（每次学习消耗 1 点）
        </div>
        <div class="learn-stats">
            你的精神：<span><?php echo $playerSenH; ?></span>
            &nbsp;|&nbsp; 师父精神：<span><?php echo $masterSenH; ?></span>
            <br>
            玩家师傅教授上限：<span>100级</span>，超过师父等级只能积累经验。
        </div>

        <div class="learn-skill-grid">
            <?php foreach ($grouped as $type => $skills): ?>
            <?php foreach ($skills as $skill):
                $isBeyond = $skill['beyond_master'];
                $isBlocked = $skill['exp_blocked'];
                $canLearn = !$isBlocked && $availablePotentialH > 0;
            ?>
            <div class="learn-skill-btn<?php echo $isBeyond ? ' beyond' : ''; ?><?php echo $isBlocked ? ' disabled' : ''; ?>" id="skill-<?php echo h($skill['id']); ?>">
                <div class="learn-skill-info">
                    <div class="learn-skill-name"><?php echo h($skill['name']); ?></div>
                    <div class="learn-skill-desc<?php echo $isBlocked ? ' blocked' : ($isBeyond ? ' beyond' : ''); ?>">
                        <?php if ($isBlocked): ?>
                            <?php echo h($skill['exp_block_reason']); ?>
                        <?php elseif ($isBeyond): ?>
                            已超过师父（只积累不升级）
                        <?php else: ?>
                            点击向师父请教
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <div class="learn-skill-level">Lv.<?php echo $skill['player_level']; ?></div>
                    <div class="learn-skill-level master-level">师 Lv.<?php echo $skill['master_level']; ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

        <button class="learn-modal-close" onclick="window.location.href='character.php?id=<?php echo $masterIdH; ?>'">返回</button>
    </div>

    <script>
        function learnFromMaster(skillId, masterId) {
            fetch('action.php?action=doLearnFromMaster&skill_id=' + encodeURIComponent(skillId) + '&master_id=' + masterId, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert(data.message);
                }
            })
            .catch(function(e) { alert('学习失败：' + e.message); });
        }

        // 点击技能卡片触发学习
        var skillCards = document.querySelectorAll('.learn-skill-btn:not(.disabled)');
        for (var i = 0; i < skillCards.length; i++) {
            skillCards[i].addEventListener('click', (function(card) {
                return function() {
                    var skillId = card.id.replace('skill-', '');
                    var masterId = <?php echo $masterIdH; ?>;
                    learnFromMaster(skillId, masterId);
                };
            })(skillCards[i]));
        }
    </script>
</body>
</html>
        <?php
        $html = ob_get_clean();
        return ['success' => true, 'html' => $html];
    }

    /**
     * 实际执行向玩家师傅学习技能（按原始项目 learn.c 逻辑还原）
     *
     * 原始项目规则：
     * - 潜能消耗：每次 1 点（learned_points += 1）
     * - 精神消耗（徒弟）：sen_cost = 300 / int(悟性)；初学(0级)×2
     * - 精神消耗（师傅）：sen_cost + 1
     * - 每次提升量：random(int) + 1 点积累
     * - 升级阈值：learned > (level+1)²
     * - 玩家师傅硬上限：100 级
     * - 初始门槛：必须已有 ≥1 级才能跟玩家师傅学
     * - 超过师傅等级：只积累 learned，不升级（weak_mode）
     * - 武技经验限制：combat_exp >= skill³ / 10
     * - 法术经验限制：daoxing >= skill³ / 10
     */
    private static function handleDoLearnFromMaster(int $charId, string $param, array $char): array {
        $skillId = $_GET['skill_id'] ?? '';
        $masterId = intval($_GET['master_id'] ?? 0);

        if (empty($skillId) || !$masterId) {
            return ['success' => false, 'message' => '参数不正确。'];
        }

        require_once __DIR__ . '/../helpers/SkillManager.php';
        require_once __DIR__ . '/../helpers/AttributeHelper.php';
        require_once __DIR__ . '/ApprenticeHandler.php';

        // ===== 1. 师徒关系检查 =====
        if (!ApprenticeHandler::isApprenticeOf($charId, $masterId)) {
            return ['success' => false, 'message' => '对方不是你的师父。'];
        }

        // ===== 2. 师傅信息 & 同一房间检查 =====
        $master = Database::queryOne('SELECT id, name, current_room, sen, max_sen, int AS master_int FROM characters WHERE id = ?', [$masterId]);
        if (!$master) {
            return ['success' => false, 'message' => '师傅不存在。'];
        }
        if (($master['current_room'] ?? '') !== ($char['current_room'] ?? '')) {
            return ['success' => false, 'message' => '师父不在你身边。'];
        }

        // ===== 3. 忙碌检查 =====
        if (is_player_busy($charId)) {
            return ['success' => false, 'message' => '你正忙着呢，无法学习技能。'];
        }

        // ===== 4. 技能存在检查 =====
        $skillInfo = Database::queryOne('SELECT skill_id, name, type FROM skills WHERE skill_id = ?', [$skillId]);
        if (!$skillInfo) {
            return ['success' => false, 'message' => '该技能不存在。'];
        }
        $skillType = $skillInfo['type'] ?? 'martial';

        // ===== 5. 师傅掌握该技能检查 =====
        $masterLevel = SkillManager::getSkillLevel($masterId, $skillId);
        if ($masterLevel < 1) {
            return ['success' => false, 'message' => '师父还没有掌握这项技能。'];
        }

        // ===== 6. 初始门槛：玩家必须已有 ≥1 级（原始项目 learn.c:123） =====
        $playerLevel = SkillManager::getSkillLevel($charId, $skillId);
        if ($playerLevel < 1) {
            return ['success' => false, 'message' => '你怎么也弄不明白，需先学会基础才能向玩家师傅学习。'];
        }

        // ===== 7. 100 级硬上限（原始项目 learn.c:129） =====
        if ($playerLevel >= 100) {
            return ['success' => false, 'message' => '你跟' . $master['name'] . '已经没办法再指点了，玩家师傅教授上限为100级。'];
        }

        // ===== 8. 潜能检查（原始项目 learn.c:83-87） =====
        // 可用潜能 = potential - learned_points，每次学习消耗 1 点
        $playerInfo = Database::queryOne('SELECT potential, learned_points, sen, max_sen, combat_exp, daoxing, int AS player_int FROM characters WHERE id = ?', [$charId]);
        $potential = intval($playerInfo['potential'] ?? 0);
        $learnedPoints = intval($playerInfo['learned_points'] ?? 0);
        $availablePotential = $potential - $learnedPoints;
        if ($availablePotential <= 0) {
            return ['success' => false, 'message' => '你的潜能已经耗尽，无法再学习。'];
        }

        // ===== 9. 精神消耗计算（原始项目 learn.c:76-81） =====
        // sen_cost = 300 / int(悟性)；初学(0级)×2
        // 使用 AttributeHelper 获取有效悟性
        $playerInt = AttributeHelper::queryInt($char);
        $playerInt = max(1, $playerInt); // 防止除零
        $senCost = intval(300 / $playerInt);
        if ($playerLevel < 1) {
            $senCost *= 2; // 初学翻倍（虽然前面已拒绝 0 级，保留逻辑完整性）
        }
        $senCost = max(1, $senCost); // 至少 1 点

        // 徒弟精神检查
        $playerSen = intval($playerInfo['sen'] ?? 0);
        if ($playerSen < $senCost) {
            return ['success' => false, 'message' => '你的精神不足，无法集中精力学习（需要 ' . $senCost . ' 点精神）。'];
        }

        // ===== 10. 师傅精神检查 & 消耗（原始项目 learn.c:136-144） =====
        // 玩家师傅每次扣 sen_cost + 1 点精神
        $masterSenCost = $senCost + 1;
        $masterSen = intval($master['sen'] ?? 0);
        if ($masterSen < $masterSenCost) {
            return ['success' => false, 'message' => '师父精神不济，无法继续指点你。'];
        }

        // ===== 11. 经验限制检查（原始项目 learn.c:148-155） =====
        $combatExp = intval($playerInfo['combat_exp'] ?? 0);
        $daoxing = intval($playerInfo['daoxing'] ?? 0);
        if ($skillType === 'martial') {
            // 武技：combat_exp >= skill³ / 10
            $expNeeded = intval(pow($playerLevel, 3) / 10);
            if ($combatExp < $expNeeded) {
                return ['success' => false, 'message' => '你的战斗经验不足，无法领悟更高深的武技（需要 ' . $expNeeded . ' 点战斗经验）。'];
            }
        } elseif ($skillType === 'magic') {
            // 法术：daoxing >= skill³ / 10
            $expNeeded = intval(pow($playerLevel, 3) / 10);
            if ($daoxing < $expNeeded) {
                return ['success' => false, 'message' => '你的道行不足，无法领悟更高深的法术（需要 ' . $expNeeded . ' 点道行）。'];
            }
        }

        // ===== 12. 计算提升量（原始项目 learn.c:89-111） =====
        // 每次学习增加 random(int) + 1 点积累
        $amount = mt_rand(0, $playerInt - 1) + 1;

        // ===== 13. 升级判定（原始项目 learn.c:162-176 + skill.c:245-251） =====
        // 获取当前技能数据
        $skillData = Database::queryOne(
            "SELECT level, exp FROM character_skills WHERE char_id = ? AND skill_id = ? LIMIT 1",
            [$charId, $skillId]
        );

        $currentLevel = intval($skillData['level'] ?? $playerLevel);
        $currentExp = intval($skillData['exp'] ?? 0);
        $newExp = $currentExp + $amount;
        $leveledUp = 0;

        // 判断是否超过师傅等级（weak_mode）
        $beyondMaster = $currentLevel >= $masterLevel;

        if (!$beyondMaster) {
            // 正常模式：可以升级
            // 升级阈值：learned > (level+1)²
            $maxLevel = min(100, $masterLevel); // 不超过100级和师傅等级
            while ($currentLevel < $maxLevel) {
                $expNeeded = intval(pow($currentLevel + 1, 2));
                if ($newExp <= $expNeeded) {
                    break;
                }
                $newExp -= $expNeeded;
                $currentLevel++;
                $leveledUp++;
            }
        }
        // 超过师傅等级时：只积累 exp，不升级（weak_mode=1）

        // ===== 14. 扣除消耗 =====
        // 徒弟：learned_points += 1, sen -= sen_cost
        Database::execute(
            "UPDATE characters SET learned_points = learned_points + 1, sen = GREATEST(0, sen - ?) WHERE id = ?",
            [$senCost, $charId]
        );
        // 师傅：sen -= (sen_cost + 1)
        Database::execute(
            "UPDATE characters SET sen = GREATEST(0, sen - ?) WHERE id = ?",
            [$masterSenCost, $masterId]
        );

        // 更新技能经验
        if ($skillData) {
            Database::execute(
                "UPDATE character_skills SET level = ?, exp = ? WHERE char_id = ? AND skill_id = ?",
                [$currentLevel, $newExp, $charId, $skillId]
            );
        } else {
            // 不应到达此处（前面已拒绝 0 级），但保留兜底
            Database::execute(
                "INSERT INTO character_skills (char_id, skill_id, level, exp) VALUES (?, ?, ?, ?)",
                [$charId, $skillId, $currentLevel, $newExp]
            );
        }

        // 设置 busy 1 秒
        set_player_busy($charId, 1);

        // ===== 15. 构建消息 =====
        $skillName = $skillInfo['name'] ?? $skillId;
        $message = '你向' . $master['name'] . '请教' . $skillName . '，';

        if ($leveledUp > 0) {
            $message .= '有所领悟，' . $skillName . '提升到了 ' . $currentLevel . ' 级！';
        } elseif ($beyondMaster) {
            $message .= '你积累了 ' . $amount . ' 点经验，但已超过师父，需要自己修炼才能突破。';
        } else {
            $message .= '略有心得，' . $skillName . '熟练度增加了 ' . $amount . ' 点。';
        }
        $message .= '（消耗潜能 1 点，精神 ' . $senCost . ' 点）';

        // 给师傅发消息
        require_once __DIR__ . '/MessageDaemon.php';
        \MessageDaemon::sendPrivateMessage(
            $masterId,
            $char['name'] . '向你请教' . $skillName . '，你耐心地指点了' . (intval($char['gender']) === 2 ? '她' : '他') . '一番。（消耗精神 ' . $masterSenCost . ' 点）',
            $charId
        );

        return [
            'success' => true,
            'message' => $message,
            'new_level' => $currentLevel,
            'exp_gained' => $amount,
            'potential_cost' => 1,
            'sen_cost' => $senCost,
            'master_sen_cost' => $masterSenCost,
            'leveled_up' => $leveledUp,
            'beyond_master' => $beyondMaster,
        ];
    }

    /**
     * 处理启用技能命令
     */
    private static function handleEnableSkill(int $charId, string $param, array $char): array {
        $skillId = $_GET['skill_id'] ?? '';
        
        if (empty($skillId)) {
            return ['success' => false, 'message' => '请选择要启用的技能。'];
        }
        
        // 保存到session
        $_SESSION['enabled_skill_' . $charId] = $skillId;
        
        // 获取技能名称
        require_once __DIR__ . '/../helpers/SkillManager.php';
        $skillName = \SkillManager::getSkillChineseName($skillId);
        $message = "你已将「{$skillName}」设为当前练习技能。";
        
        // 保存到消息队列，让房间页面显示
        require_once __DIR__ . '/MessageDaemon.php';
        \MessageDaemon::queueMessageToSelf($charId, $message, 'self_event');
        
        return ['success' => true, 'message' => $message];
    }
    
    /**
     * 处理接受拜师请求命令
     */
    private static function handleAcceptApprentice(int $charId, string $param, array $char): array {
        $targetId = intval($_GET['target'] ?? 0);
        
        require_once __DIR__ . '/ApprenticeHandler.php';
        
        $result = ApprenticeHandler::acceptApprenticeship($charId, $targetId);
        if ($result['success']) {
            $_SESSION['flash_message'] = $result['message'];
        }
        
        return $result;
    }
    
    /**
     * 处理拒绝拜师请求命令
     */
    private static function handleRejectApprentice(int $charId, string $param, array $char): array {
        $targetId = intval($_GET['target'] ?? 0);
        
        require_once __DIR__ . '/ApprenticeHandler.php';
        
        // 查找对应的拜师请求
        $request = Database::queryOne(
            'SELECT id FROM apprentice_requests 
              WHERE from_character_id = ? AND to_character_id = ? AND status = "pending"',
            [$targetId, $charId]
        );
        
        if (!$request) {
            return ['success' => false, 'message' => '没有找到待处理的拜师请求。'];
        }
        
        $result = ApprenticeHandler::rejectApprenticeship($charId, $request['id']);
        if ($result['success']) {
            $_SESSION['flash_message'] = $result['message'];
        }
        
        return $result;
    }
    
    /**
     * 处理打坐命令 (exercise)
     */
    private static function handleExercise(int $charId, string $param, array $char): array {
        // 加载命令文件
        $cmdFile = __DIR__ . '/../commands/exercise.php';
        if (!file_exists($cmdFile)) {
            return ['success' => false, 'message' => '打坐命令未加载'];
        }
        require_once $cmdFile;
        
        // 检查是否有待处理的修炼轮次
        if (!empty($_SESSION['pending_exercising'])) {
            $result = processExerciseRound($charId);
            if ($result) {
                return $result;
            }
        }
        
        // 从 GET 参数获取气血值
        $kee = $_GET['kee'] ?? '';
        if (!empty($kee)) {
            $param = $kee;
        }
        
        // 如果没有参数，显示输入弹窗页面
        if (empty($param)) {
            $currentKee = $char['kee'] ?? 0;
            $html = self::renderExerciseDialog($charId, $currentKee);
            return ['success' => true, 'html' => $html];
        }
        
        // 执行打坐命令
        return cmd_exercise($charId, $param);
    }
    
    /**
     * 处理冥思命令 (meditate)
     */
    private static function handleMeditate(int $charId, string $param, array $char): array {
        // 加载命令文件
        $cmdFile = __DIR__ . '/../commands/meditate.php';
        if (!file_exists($cmdFile)) {
            return ['success' => false, 'message' => '冥思命令未加载'];
        }
        require_once $cmdFile;
        
        // 检查是否有待处理的冥思轮次
        if (!empty($_SESSION['pending_meditating'])) {
            $result = processMeditateRound($charId);
            if ($result) {
                return $result;
            }
        }
        
        // 从 GET 参数获取精神值
        $sen = $_GET['sen'] ?? '';
        if (!empty($sen)) {
            $param = $sen;
        }
        
        // 如果没有参数，显示输入弹窗页面
        if (empty($param)) {
            $currentSen = $char['sen'] ?? 0;
            $html = self::renderMeditateDialog($charId, $currentSen);
            return ['success' => true, 'html' => $html];
        }
        
        // 执行冥思命令
        return cmd_meditate($charId, $param);
    }
    
    /**
     * 处理停止命令 (stop)
     */
    private static function handleStop(int $charId, string $param, array $char): array {
        // 加载命令文件
        $cmdFile = __DIR__ . '/../commands/stop.php';
        if (!file_exists($cmdFile)) {
            return ['success' => false, 'message' => '停止命令未加载'];
        }
        require_once $cmdFile;
        
        return cmd_stop($charId, $param);
    }
    
    /**
     * 处理诵经命令 (chanting)
     * 参考 xyj2000-php/cmds/std/Chanting.php
     */
    private static function handleChanting(int $charId, string $param, array $char): array {
        // 加载命令文件
        $cmdFile = __DIR__ . '/../commands/chanting.php';
        if (!file_exists($cmdFile)) {
            return ['success' => false, 'message' => '诵经命令未加载'];
        }
        require_once $cmdFile;
        
        // 检查是否有待处理的诵经轮次
        if (!empty($_SESSION['pending_chanting'])) {
            $result = executeChantingRound($charId);
            if ($result !== null) {
                return $result;
            }
            // 还在进行中，返回进度信息
            $state = $_SESSION['pending_chanting'];
            return [
                'success' => true,
                'message' => '',
                'skip_queue' => true,
                'current_round' => $state['current_round'],
                'total_rounds' => $state['total_rounds'],
                'action_type' => 'chanting',
            ];
        }
        
        // 执行诵经命令
        return cmd_chanting($charId, $param);
    }
    
    /**
     * 处理放弃命令 (abandon)
     * - 有参数(skill_id)：放弃技能
     * - 无参数且在三心宫区域(清心宫/宁心宫/静心宫/三心宫)：放弃任务
     * - 无参数不在特定房间：提示输入技能名
     * 参考 xyj2000-php/cmds/std/Abandon.php
     */
    private static function handleAbandon(int $charId, string $param, array $char): array {
        // 如果无参数，判断是否是放弃任务场景
        if (empty($param)) {
            $roomId = $char['current_room'] ?? '';
            $allowedRooms = ['qingxin', 'ningxin', 'jingxin', 'sanxin', '清心宫', '宁心宫', '静心宫', '三心宫'];
            $roomAllowed = false;
            foreach ($allowedRooms as $allowed) {
                if (mb_stripos($roomId, $allowed) !== false) {
                    $roomAllowed = true;
                    break;
                }
            }
            
            if ($roomAllowed) {
                // 在三心宫区域内，执行放弃任务
                return self::handleAbandonQuest($charId, $param, $char);
            }
            
            // 不在特定房间，提示需要输入技能名
            return ['success' => false, 'message' => '你要放弃哪一项技能？'];
        }
        
        // 有参数：放弃技能
        $cmdFile = __DIR__ . '/../commands/abandon.php';
        if (!file_exists($cmdFile)) {
            return ['success' => false, 'message' => '放弃技能命令未加载'];
        }
        require_once $cmdFile;
        
        return cmd_abandon($charId, $param);
    }
    
    /**
     * 处理练习页面请求 - 显示可练习的技能列表
     */
    private static function handlePractice(int $charId, string $param, array $char): array {
        // 加载命令文件
        $cmdFile = __DIR__ . '/../commands/practice.php';
        if (file_exists($cmdFile)) {
            require_once $cmdFile;
        }
        
        // 如果有 type 参数，执行练习
        $type = $_GET['type'] ?? '';
        $times = $_GET['times'] ?? '1';
        if (!empty($type)) {
            if (!function_exists('cmd_practice')) {
                return ['success' => false, 'message' => '练习系统未加载'];
            }
            
            $param = $type . ' ' . $times;
            $result = cmd_practice($charId, $param);
            $message = $result['output'] ?? $result['message'] ?? '';
            
            // 练习是持续状态，完成后返回 room
            return [
                'success' => $result['success'],
                'message' => $message,
                'redirect' => 'room.php'
            ];
        }
        
        // 无参数：渲染练习页面
        $data = self::renderPracticePage($charId);
        $html = self::renderPracticeHtml($data);
        return ['success' => true, 'html' => $html];
    }
    
    /**
     * 渲染打坐输入对话框
     */
    private static function renderExerciseDialog(int $charId, int $kee): string {
        ob_start();
        ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
    <title>打坐修炼_西游记mud</title>
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
    <style>
        .dialog-container {
            max-width: 400px;
            margin: 50px auto;
            padding: 20px;
            background-color: #1e2a3a;
            border: 1px solid #4a7ab5;
            border-radius: 5px;
        }
        .dialog-title {
            font-size: 18px;
            font-weight: bold;
            color: #B6D4FE;
            margin-bottom: 15px;
            text-align: center;
        }
        .dialog-info {
            margin-bottom: 15px;
            padding: 10px;
            background-color: #0d1b2a;
            border-radius: 3px;
            color: #8ba4c4;
        }
        .dialog-form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .dialog-input {
            padding: 10px;
            border: 1px solid #4a7ab5;
            background-color: #0d1b2a;
            color: #ffffff;
            border-radius: 3px;
            font-size: 14px;
        }
        .dialog-input:focus {
            outline: none;
            border-color: #66ccff;
        }
        .dialog-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
        }
        .btn-primary {
            background-color: #4a7ab5;
            color: #ffffff;
        }
        .btn-primary:hover {
            background-color: #5a8ac5;
        }
        .btn-secondary {
            background-color: #2a3a4a;
            color: #ffffff;
        }
        .btn-secondary:hover {
            background-color: #3a4a5a;
        }
    </style>
</head>
<body>
    <div class="dialog-container">
        <div class="dialog-title">打坐修炼</div>
        <div class="dialog-info">
            <p>你盘膝而坐，准备运气修炼。</p>
            <p>当前气血: <strong><?php echo $kee; ?></strong></p>
            <p style="font-size: 12px; color: #666;">提示：每次修炼消耗20点气血，最少需要20点</p>
        </div>
        <form class="dialog-form" action="action.php" method="GET">
            <input type="hidden" name="action" value="exercise">
            <input class="dialog-input" type="number" name="kee" placeholder="请输入消耗气血值（最少20）" min="20" max="<?php echo $kee; ?>" value="100" required>
            <div class="dialog-buttons">
                <button type="submit" class="btn btn-primary">开始修炼</button>
                <a href="room.php" class="btn btn-secondary">取消</a>
            </div>
        </form>
    </div>
</body>
</html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * 渲染冥思输入对话框
     */
    private static function renderMeditateDialog(int $charId, int $sen): string {
        ob_start();
        ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
    <title>冥思修炼_西游记mud</title>
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
    <style>
        .dialog-container {
            max-width: 400px;
            margin: 50px auto;
            padding: 20px;
            background-color: #1e2a3a;
            border: 1px solid #4a7ab5;
            border-radius: 5px;
        }
        .dialog-title {
            font-size: 18px;
            font-weight: bold;
            color: #B6D4FE;
            margin-bottom: 15px;
            text-align: center;
        }
        .dialog-info {
            margin-bottom: 15px;
            padding: 10px;
            background-color: #0d1b2a;
            border-radius: 3px;
            color: #8ba4c4;
        }
        .dialog-form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .dialog-input {
            padding: 10px;
            border: 1px solid #4a7ab5;
            background-color: #0d1b2a;
            color: #ffffff;
            border-radius: 3px;
            font-size: 14px;
        }
        .dialog-input:focus {
            outline: none;
            border-color: #66ccff;
        }
        .dialog-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
        }
        .btn-primary {
            background-color: #4a7ab5;
            color: #ffffff;
        }
        .btn-primary:hover {
            background-color: #5a8ac5;
        }
        .btn-secondary {
            background-color: #2a3a4a;
            color: #ffffff;
        }
        .btn-secondary:hover {
            background-color: #3a4a5a;
        }
    </style>
</head>
<body>
    <div class="dialog-container">
        <div class="dialog-title">冥思修炼</div>
        <div class="dialog-info">
            <p>你盘膝而坐，准备冥思修炼。</p>
            <p>当前精神: <strong><?php echo $sen; ?></strong></p>
            <p style="font-size: 12px; color: #666;">提示：每次冥思消耗20点精神，最少需要20点</p>
        </div>
        <form class="dialog-form" action="action.php" method="GET">
            <input type="hidden" name="action" value="meditate">
            <input class="dialog-input" type="number" name="sen" placeholder="请输入消耗精神值（最少20）" min="20" max="<?php echo $sen; ?>" value="100" required>
            <div class="dialog-buttons">
                <button type="submit" class="btn btn-primary">开始冥思</button>
                <a href="room.php" class="btn btn-secondary">取消</a>
            </div>
        </form>
    </div>
</body>
</html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * AJAX 接口：返回练功弹窗所需的数据
     */
    private static function handlePracticeData(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../helpers/SkillManager.php';
        
        // 从 character_skill_map 表获取技能映射
        $mapRows = Database::queryAll(
            "SELECT skill_type, mapped_skill FROM character_skill_map WHERE char_id = ?",
            [$charId]
        );
        $skillMap = [];
        foreach ($mapRows as $row) {
            $skillMap[$row['skill_type']] = $row['mapped_skill'];
        }
        
        // 获取角色属性
        $charInfo = Database::queryOne('SELECT combat_exp, potential, learned_points, `int` FROM characters WHERE id = ?', [$charId]);
        $combatExp = $charInfo['combat_exp'] ?? 0;
        $potential = $charInfo['potential'] ?? 0;
        $learnedPoints = $charInfo['learned_points'] ?? 0;
        $int = $charInfo['int'] ?? 10;
        $availablePotential = $potential - $learnedPoints;
        $potentialCostPerRound = max(1, intval(150 / $int));
        
        $validTypes = [
            "unarmed"    => "拳脚",
            "sword"      => "剑法",
            "blade"      => "刀法",
            "stick"      => "棍法",
            "staff"      => "杖法",
            "throwing"   => "暗器",
            "force"      => "内功",
            "parry"      => "招架",
            "dodge"      => "轻功",
            "spells"     => "法术",
            "whip"       => "鞭法",
            "spear"      => "枪法",
            "axe"        => "斧法",
            "mace"       => "锏法",
            "fork"       => "叉法",
            "rake"       => "钯法",
            "archery"    => "弓箭",
            "hammer"     => "锤法",
            "magic"      => "魔法",
            "literate"   => "读书写字",
            "buddhism"   => "佛法",
            "daoism"     => "道法",
            "taiyi"      => "太乙",
        ];
        
        $skills = [];
        foreach ($skillMap as $type => $skillId) {
            $typeName = $validTypes[$type] ?? $type;
            $skillName = SkillManager::getSkillChineseName($skillId);
            $skillLevel = SkillManager::querySkill($charId, $skillId, true);
            $effectiveLevel = SkillManager::querySkill($charId, $skillId, false);
            
            $requiredExp = pow($skillLevel, 3) / 10;
            $canPractice = ($combatExp >= $requiredExp) && ($availablePotential > 0);
            
            $disabledReason = '';
            if (!$canPractice) {
                if ($availablePotential <= 0) {
                    $disabledReason = '潜能不足';
                } else {
                    $disabledReason = '修为不足';
                }
            }
            
            $skills[] = [
                'type' => $type,
                'typeName' => $typeName,
                'skillId' => $skillId,
                'skillName' => $skillName,
                'level' => $skillLevel,
                'effectiveLevel' => $effectiveLevel,
                'canPractice' => $canPractice,
                'disabledReason' => $disabledReason,
                'requiredExp' => number_format($requiredExp),
            ];
        }
        
        return [
            'success' => true,
            'combatExp' => $combatExp,
            'potential' => $potential,
            'availablePotential' => $availablePotential,
            'potentialCost' => $potentialCostPerRound,
            'skills' => $skills,
        ];
    }

    /**
     * 获取练习页面数据
     */
    private static function renderPracticePage(int $charId): array {
        require_once __DIR__ . '/../helpers/SkillManager.php';
        
        // 从 character_skill_map 表获取技能映射
        $mapRows = Database::queryAll(
            "SELECT skill_type, mapped_skill FROM character_skill_map WHERE char_id = ?",
            [$charId]
        );
        $skillMap = [];
        foreach ($mapRows as $row) {
            $skillMap[$row['skill_type']] = $row['mapped_skill'];
        }
        
        // 获取角色属性
        $charInfo = Database::queryOne('SELECT combat_exp, potential, learned_points, `int` FROM characters WHERE id = ?', [$charId]);
        $combatExp = $charInfo['combat_exp'] ?? 0;
        $potential = $charInfo['potential'] ?? 0;
        $learnedPoints = $charInfo['learned_points'] ?? 0;
        $int = $charInfo['int'] ?? 10;
        $availablePotential = $potential - $learnedPoints;
        
        // 计算每次练习的潜能消耗（比 learn 减少50%）
        $potentialCostPerRound = max(1, intval(150 / $int));
        
        // 有效的技能类型
        $validTypes = [
            "unarmed"    => "拳脚",
            "sword"      => "剑法",
            "blade"      => "刀法",
            "stick"      => "棍法",
            "staff"      => "杖法",
            "throwing"   => "暗器",
            "force"      => "内功",
            "parry"      => "招架",
            "dodge"      => "轻功",
            "spells"     => "法术",
            "whip"       => "鞭法",
            "spear"      => "枪法",
            "axe"        => "斧法",
            "mace"       => "锏法",
            "fork"       => "叉法",
            "rake"       => "钯法",
            "archery"    => "弓箭",
            "hammer"     => "锤法",
            "magic"      => "魔法",
            "literate"   => "读书写字",
            "buddhism"   => "佛法",
            "daoism"     => "道法",
            "taiyi"      => "太乙",
        ];
        
        // 构建技能行数据
        $skills = [];
        foreach ($skillMap as $type => $skillId) {
            $typeName = $validTypes[$type] ?? $type;
            $skillName = SkillManager::getSkillChineseName($skillId);
            $skillLevel = SkillManager::querySkill($charId, $skillId, true);
            $effectiveLevel = SkillManager::querySkill($charId, $skillId, false);
            $requiredExp = pow($skillLevel, 3) / 10;
            $canPractice = ($combatExp >= $requiredExp) && ($availablePotential > 0);
            
            $skills[] = [
                'type'           => $type,
                'typeName'       => $typeName,
                'skillId'        => $skillId,
                'skillName'      => $skillName,
                'skillLevel'     => $skillLevel,
                'effectiveLevel' => $effectiveLevel,
                'requiredExp'    => $requiredExp,
                'canPractice'    => $canPractice,
            ];
        }
        
        return [
            'combatExp'             => $combatExp,
            'potential'             => $potential,
            'availablePotential'    => $availablePotential,
            'potentialCostPerRound' => $potentialCostPerRound,
            'skillMap'              => $skillMap,
            'skills'                => $skills,
        ];
    }
    
    /**
     * 处理练习技能命令
     */
    private static function handlePracticeSkill(int $charId, string $param, array $char): array {
        $skillId = $_GET['skill_id'] ?? '';
        
        if (empty($skillId)) {
            return ['success' => false, 'message' => '请选择要练习的技能。'];
        }
        
        require_once __DIR__ . '/../helpers/SkillManager.php';
        require_once __DIR__ . '/../helpers/SectHelper.php';
        
        // 检查是否可以学习
        $canLearn = SectHelper::canLearnSkill($charId, $skillId);
        if (!$canLearn['allowed']) {
            return ['success' => false, 'message' => $canLearn['reason']];
        }
        
        // 获取角色信息
        $charInfo = Database::queryOne('SELECT name, potential FROM characters WHERE id = ?', [$charId]);
        if (!$charInfo) {
            return ['success' => false, 'message' => '角色不存在。'];
        }
        
        // 获取技能等级
        $skillLevel = SkillManager::querySkill($charId, $skillId);
        
        // 计算潜能消耗
        $potentialCost = max(1, intval($skillLevel / 10) + 1);
        
        // 检查潜能
        if ((int)($charInfo['potential'] ?? 0) < $potentialCost) {
            return ['success' => false, 'message' => '潜能不足，无法练习。'];
        }
        
        // 随机失败概率 (20000分之一)
        if (rand(0, 19999) === 0) {
            return ['success' => true, 'message' => '你反复练习这项技能，但是没有任何进展。'];
        }
        
        // 执行练习
        $result = SkillManager::practiceSkill($charId, $skillId);
        
        if ($result['success']) {
            // 扣除潜能
            Database::execute(
                "UPDATE characters SET potential = potential - ? WHERE id = ?",
                [$potentialCost, $charId]
            );
            
            // 提升技能等级
            $basicSkill = SkillManager::getSkillLevel($charId, $skillId);
            $improveAmount = intval($basicSkill / 5) + 1;
            $shouldLimit = ($basicSkill > $skillLevel) ? false : true;
            $sectBonus = $canLearn['bonus'] ?? 0;
            
            $improveResult = SkillManager::improveSkill($charId, $skillId, $improveAmount, $shouldLimit, $sectBonus);
            
            $skillName = SkillManager::getSkillChineseName($skillId);
            
            require_once __DIR__ . '/MessageDaemon.php';
            
            if (!$improveResult['success']) {
                $message = "你的{$skillName}似乎已经无法再提升了。(消耗潜能: {$potentialCost})";
                \MessageDaemon::queueMessageToSelf($charId, $message, 'self_event');
                return [
                    'success' => true,
                    'message' => $message
                ];
            }
            
            $newLevel = $improveResult['level_up'] + $skillLevel;
            
            $sectBonusMsg = '';
            if ($sectBonus > 0) {
                if ($canLearn['is_sect_skill'] ?? false) {
                    $sectBonusMsg = "\n[本门专属武学，修炼效率+{$sectBonus}%]";
                } else {
                    $sectBonusMsg = "\n[本门重点技艺，修炼效率+{$sectBonus}%]";
                }
            }
            
            $message = "你的{$skillName}有长进了！当前等级: {$newLevel}{$sectBonusMsg}\n(消耗潜能: {$potentialCost})";
            \MessageDaemon::queueMessageToSelf($charId, $message, 'self_event');
            
            return [
                'success' => true,
                'message' => $message
            ];
        }
        
        return ['success' => false, 'message' => $result['message'] ?? '练习失败，请稍后再试。'];
    }
    
    /**
     * 渲染技能学习页面
     */
    private static function renderSkillLearningPage($sect, $skills, $enabledSkill, $potential, $npcId): string {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="zh-CN">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>学习技能 - <?php echo h($sect['name']); ?></title>
            <link rel="stylesheet" href="../assets/css/light-theme.css">
            <script src="../assets/js/theme-init.js"></script>
            <link rel="stylesheet" href="../assets/css/footer.css">
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body {
                    background: #1a1a1a;
                    color: #e0e0e0;
                    font-family: "Microsoft YaHei", sans-serif;
                    display: flex;
                    justify-content: center;
                    align-items: flex-start;
                    min-height: 100vh;
                    padding: 20px;
                }
                .learn-modal {
                    background-color: #2d2d2d;
                    border: 2px solid #4a4a4a;
                    border-radius: 8px;
                    padding: 16px;
                    max-width: 400px;
                    width: 100%;
                    max-height: 85vh;
                    overflow-y: auto;
                    box-shadow: 0 5px 20px rgba(0,0,0,0.5);
                }
                .learn-modal h3 {
                    color: #FFD700;
                    margin: 0 0 6px 0;
                    text-align: center;
                    font-size: 16px;
                    font-weight: bold;
                }
                .learn-modal-desc {
                    color: #aaaaaa;
                    font-size: 12px;
                    margin-bottom: 12px;
                    text-align: center;
                }
                .learn-potential {
                    color: #ff9966;
                    font-size: 12px;
                    text-align: center;
                    margin-bottom: 12px;
                    padding: 6px;
                    background: #1e2a3a;
                    border-radius: 4px;
                    border: 1px solid #3a5a7a;
                }
                .learn-skill-grid {
                    display: grid;
                    grid-template-columns: 1fr;
                    gap: 6px;
                }
                .learn-skill-btn {
                    background-color: #3d3d3d;
                    border: 1px solid #555555;
                    border-radius: 4px;
                    padding: 10px 12px;
                    cursor: pointer;
                    text-align: left;
                    transition: all 0.2s ease;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                .learn-skill-btn:hover {
                    background-color: #4a4a4a;
                    border-color: #888888;
                }
                .learn-skill-btn:active {
                    transform: scale(0.99);
                }
                .learn-skill-btn.enabled {
                    border-color: #FFD700;
                    background-color: #3a3520;
                }
                .learn-skill-info {
                    flex: 1;
                    min-width: 0;
                }
                .learn-skill-name {
                    color: #ffffff;
                    font-weight: bold;
                    font-size: 13px;
                    margin-bottom: 3px;
                }
                .learn-skill-level {
                    color: #90EE90;
                    font-size: 12px;
                    font-weight: bold;
                    flex-shrink: 0;
                    text-align: right;
                    min-width: 40px;
                }
                .learn-skill-actions {
                    display: flex;
                    gap: 4px;
                    margin-top: 6px;
                }
                .learn-btn {
                    flex: 1;
                    padding: 5px 8px;
                    border: none;
                    border-radius: 3px;
                    cursor: pointer;
                    font-size: 11px;
                    transition: all 0.2s ease;
                    text-align: center;
                }
                .learn-btn.enable {
                    background-color: #4a6a8a;
                    color: #ffffff;
                }
                .learn-btn.enable:hover { background-color: #5a8ab8; }
                .learn-btn.practice {
                    background-color: #3a6a3a;
                    color: #ffffff;
                }
                .learn-btn.practice:hover { background-color: #4a8a4a; }
                .learn-btn.enabled-mark {
                    background-color: #5a4a00;
                    color: #FFD700;
                    cursor: default;
                    font-weight: bold;
                }
                .learn-modal-close {
                    display: block;
                    width: 100%;
                    margin-top: 12px;
                    padding: 8px;
                    background-color: #555555;
                    border: none;
                    border-radius: 4px;
                    color: #ffffff;
                    cursor: pointer;
                    font-size: 13px;
                }
                .learn-modal-close:hover { background-color: #666666; }

                @media screen and (max-width: 375px) {
                    body { padding: 10px; }
                    .learn-modal { padding: 12px; max-height: 80vh; }
                    .learn-modal h3 { font-size: 14px; }
                    .learn-skill-btn { padding: 8px 10px; }
                    .learn-skill-name { font-size: 12px; }
                }
            </style>
        </head>
        <body>
            <div class="learn-modal">
                <h3><?php echo h($sect['name']); ?> · 技能学习</h3>
                <div class="learn-modal-desc">选择你要修炼的武学技能</div>
                <div class="learn-potential">
                    <strong>潜能：</strong><?php echo $potential; ?> 点
                </div>

                <div class="learn-skill-grid">
                    <?php foreach ($skills as $skill): 
                        $isEnabled = ($skill['id'] === $enabledSkill);
                    ?>
                        <div class="learn-skill-btn<?php echo $isEnabled ? ' enabled' : ''; ?>" id="skill-<?php echo h($skill['id']); ?>">
                            <div class="learn-skill-info">
                                <div class="learn-skill-name"><?php echo h($skill['name']); ?></div>
                                <div class="learn-skill-actions">
                                    <?php if (!$isEnabled): ?>
                                        <button class="learn-btn enable" onclick="enableSkill('<?php echo h($skill['id']); ?>')">设为练习</button>
                                    <?php else: ?>
                                        <span class="learn-btn enabled-mark">✓ 当前练习</span>
                                    <?php endif; ?>
                                    <button class="learn-btn practice" onclick="practiceSkill('<?php echo h($skill['id']); ?>')">修炼</button>
                                </div>
                            </div>
                            <div class="learn-skill-level">Lv.<?php echo $skill['level']; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button class="learn-modal-close" onclick="window.location.href='npc.php?id=<?php echo $npcId; ?>'">返回</button>
            </div>

            <script>
                function enableSkill(skillId) {
                    fetch('action.php?action=enableSkill&skill_id=' + encodeURIComponent(skillId), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        }
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            // 移除所有 enabled 状态
                            var items = document.querySelectorAll('.learn-skill-btn.enabled');
                            for (var i = 0; i < items.length; i++) {
                                items[i].classList.remove('enabled');
                                var oldMark = items[i].querySelector('.enabled-mark');
                                if (oldMark) {
                                    var enableBtn = document.createElement('button');
                                    enableBtn.className = 'learn-btn enable';
                                    enableBtn.textContent = '设为练习';
                                    enableBtn.onclick = (function(id) {
                                        return function() { enableSkill(id); };
                                    })(oldMark.getAttribute('data-skill') || '');
                                    oldMark.parentNode.replaceChild(enableBtn, oldMark);
                                }
                            }
                            // 设置新技能的 enabled 状态
                            var currentItem = document.getElementById('skill-' + skillId);
                            if (currentItem) {
                                currentItem.classList.add('enabled');
                                var actions = currentItem.querySelector('.learn-skill-actions');
                                var enableBtn = actions.querySelector('.learn-btn.enable');
                                if (enableBtn) {
                                    var mark = document.createElement('span');
                                    mark.className = 'learn-btn enabled-mark';
                                    mark.textContent = '✓ 当前练习';
                                    enableBtn.parentNode.replaceChild(mark, enableBtn);
                                }
                            }
                        } else {
                            alert(data.message);
                        }
                    });
                }

                function practiceSkill(skillId) {
                    fetch('action.php?action=practiceSkill&skill_id=' + encodeURIComponent(skillId), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        }
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            window.location.href = '/functions/room.php';
                        } else {
                            alert(data.message);
                        }
                    });
                }
            </script>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * 处理门派信息命令 (family / 门派 / menpai)
     */
    private static function handleFamily(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/family.php';
        if (function_exists('cmd_family')) {
            return cmd_family($charId, $param);
        }
        return ['success' => false, 'message' => '门派信息功能不可用。'];
    }

    // =========================================================
    // NPC社交动作处理（掌门交互）
    // =========================================================

    /**
     * 处理对NPC的社交动作（greet/bow/ask）
     * 如果NPC是掌门，走掌门交互流程；否则走普通NPC逻辑
     *
     * @param int    $charId 角色ID
     * @param int    $npcId  NPC的ID
     * @param array  $char   角色信息
     * @param string $action 动作类型 greet/bow/ask
     * @return array
     */
    private static function handleNpcSocialAction(int $charId, int $npcId, array $char, string $action): array
    {
        require_once __DIR__ . '/../models/Npc.php';
        $npc = NpcModel::find($npcId);

        if (!$npc) {
            return ['success' => false, 'message' => '这里没有这个人。'];
        }

        // 检查NPC是否在当前房间
        $area = $char['current_area'] ?? '';
        $roomId = $char['current_room'] ?? '';
        $fullRoomId = (strpos($roomId, '/') !== false) ? $roomId : $area . '/' . $roomId;
        $npcRoom = $npc['spawn_room'] ?? '';
        if (!empty($npcRoom) && $npcRoom !== $fullRoomId) {
            return ['success' => false, 'message' => '这里没有这个人。'];
        }

        // 检查是否为掌门NPC
        require_once __DIR__ . '/../helpers/SectHelper.php';
        $sect = SectHelper::getSectByNpcId($npcId);

        if ($sect) {
            // 掌门NPC交互 - 调用 InteractHandler
            require_once __DIR__ . '/InteractHandler.php';
            $handler = new InteractHandler();
            $result = InteractHandler::handleSectMasterInteract($charId, $npcId, $action);

            if ($result['success']) {
                // 广播到房间
                $actorName = self::getDisplayName($char, $charId);
                $npcName = $npc['name'] ?? ($sect['master_npc'] ?? '掌门');
                $broadcastMsg = '';

                if ($action === 'greet') {
                    $broadcastMsg = HTML_HIYEL . $actorName . '对着' . $npcName . '作了个揖。' . HTML_NOR;
                } elseif ($action === 'bow') {
                    $broadcastMsg = HTML_HIYEL . $actorName . '恭恭敬敬地向' . $npcName . '鞠了一躬。' . HTML_NOR;
                }

                if (!empty($broadcastMsg)) {
                    require_once DAEMON_PATH . 'MessageDaemon.php';
                    $playersInRoom = Database::queryAll(
                        'SELECT id FROM characters WHERE current_room = ? AND online = 1 AND id != ?',
                        [$fullRoomId, $charId]
                    );
                    foreach ($playersInRoom as $player) {
                        Database::execute(
                            'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
                            [$player['id'], $broadcastMsg, 'room']
                        );
                    }
                }
            }

            return $result;
        }

        // 非掌门NPC - 普通社交响应
        $actorName = self::getDisplayName($char, $charId);
        $npcName = $npc['name'] ?? 'NPC';

        require_once DAEMON_PATH . 'MessageDaemon.php';
        $playersInRoom = Database::queryAll(
            'SELECT id, name FROM characters WHERE current_room = ? AND online = 1',
            [$fullRoomId]
        );

        if ($action === 'greet') {
            $selfMsg = HTML_HIYEL . '你对着' . $npcName . '作了个揖，说道："请了！"' . HTML_NOR;
            $otherMsg = HTML_HIYEL . $actorName . '对着' . $npcName . '作了个揖。' . HTML_NOR;
        } elseif ($action === 'bow') {
            $selfMsg = HTML_HIYEL . '你恭恭敬敬地向' . $npcName . '鞠了一躬。' . HTML_NOR;
            $otherMsg = HTML_HIYEL . $actorName . '恭恭敬敬地向' . $npcName . '鞠了一躬。' . HTML_NOR;
        } else {
            $selfMsg = HTML_HIYEL . '你向' . $npcName . '点头致意。' . HTML_NOR;
            $otherMsg = HTML_HIYEL . $actorName . '向' . $npcName . '点头致意。' . HTML_NOR;
        }

        foreach ($playersInRoom as $player) {
            $msg = ($player['id'] == $charId) ? $selfMsg : $otherMsg;
            Database::execute(
                'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
                [$player['id'], $msg, 'room']
            );
        }

        return [
            'success' => true,
            'message' => $selfMsg,
            'skip_queue' => true,
        ];
    }

    /**
     * 处理添加好友请求
     */
    private static function handleAddFriend(int $charId, string $param, array $char): array {
        $targetId = intval($_GET['target'] ?? $_POST['target'] ?? 0);
        
        if (!$targetId) {
            return ['success' => false, 'message' => '请指定要添加的好友。'];
        }
        
        if ($targetId == $charId) {
            return ['success' => false, 'message' => '不能添加自己为好友。'];
        }
        
        // 检查目标角色是否存在
        $targetChar = CharacterModel::find($targetId);
        if (!$targetChar) {
            return ['success' => false, 'message' => '目标角色不存在。'];
        }
        
        // 检查是否已有好友关系（任何状态）
        $existing = Database::queryOne(
            'SELECT * FROM friends WHERE (from_character_id = ? AND to_character_id = ?) OR (from_character_id = ? AND to_character_id = ?)',
            [$charId, $targetId, $targetId, $charId]
        );
        
        if ($existing) {
            switch ($existing['status']) {
                case 'pending':
                    if ($existing['from_character_id'] == $charId) {
                        return ['success' => false, 'message' => '你已经向' . $targetChar['name'] . '发送了好友请求，请等待对方确认。'];
                    } else {
                        return ['success' => false, 'message' => $targetChar['name'] . '已经向你发送了好友请求，请前往好友列表确认。'];
                    }
                case 'accepted':
                    return ['success' => false, 'message' => '你和' . $targetChar['name'] . '已经是好友了。'];
                case 'blocked':
                    return ['success' => false, 'message' => '无法添加对方为好友。'];
            }
        }
        
        // 插入好友请求
        Database::execute(
            "INSERT INTO friends (from_character_id, to_character_id, status) VALUES (?, ?, 'pending')",
            [$charId, $targetId]
        );
        
        return ['success' => true, 'message' => '已向' . $targetChar['name'] . '发送好友请求，请等待对方确认。'];
    }

    /**
     * 处理运功命令 (exert / yungong)
     */
    private static function handleExert(int $charId, string $param, array $char): array {
        if (function_exists('cmd_exert')) {
            return cmd_exert($charId, $param);
        }
        return ['success' => false, 'message' => '运功功能不可用。'];
    }

    /**
     * 处理内力加力命令 (enforce / jiali)
     */
    private static function handleEnforce(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/enforce.php';
        if (function_exists('cmd_enforce')) {
            return cmd_enforce($charId, $param);
        }
        return ['success' => false, 'message' => '内力加力功能不可用。'];
    }

    /**
     * 处理法术施放命令 (cast / fa)
     */
    private static function handleCast(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/cast.php';
        if (function_exists('cmd_cast')) {
            return cmd_cast($charId, $param);
        }
        return ['success' => false, 'message' => '法术施放功能不可用。'];
    }

    /**
     * 处理组队命令 (team / duiwu)
     */
    private static function handleTeam(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/team.php';
        if (function_exists('cmd_team')) {
            return cmd_team($charId, $param);
        }
        return ['success' => false, 'message' => '组队功能不可用。'];
    }

    /**
     * 处理耳语命令 (whisper / eryu)
     */
    private static function handleWhisper(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/whisper.php';
        if (function_exists('cmd_whisper')) {
            return cmd_whisper($charId, $param);
        }
        return ['success' => false, 'message' => '耳语功能不可用。'];
    }

    /**
     * 处理睡眠命令 (sleep / 休息)
     */
    private static function handleSleep(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/sleep.php';
        if (function_exists('cmd_sleep')) {
            return cmd_sleep($charId, $param);
        }
        return ['success' => false, 'message' => '睡眠功能不可用。'];
    }
    
    /**
     * 处理休息命令 (rest / xiu)
     */
    private static function handleRest(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/rest.php';
        if (function_exists('cmd_rest')) {
            return cmd_rest($charId, $param);
        }
        return ['success' => false, 'message' => '休息功能不可用。'];
    }

    /**
     * 处理昏迷命令 (faint / 昏迷 / uncon)
     */
    private static function handleFaint(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/faint.php';
        if (function_exists('cmd_faint')) {
            return cmd_faint($charId, $param);
        }
        return ['success' => false, 'message' => '昏迷功能不可用。'];
    }

    /**
     * 处理发呆命令 (daze / 发呆 / fadai)
     */
    private static function handleDaze(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/daze.php';
        if (function_exists('cmd_daze')) {
            return cmd_daze($charId, $param);
        }
        return ['success' => false, 'message' => '发呆功能不可用。'];
    }

    /**
     * 处理唤醒命令 (wake / 唤醒 / hunxing / jiaoxing)
     */
    private static function handleWake(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/wake.php';
        if (function_exists('cmd_wake')) {
            return cmd_wake($charId, $param);
        }
        return ['success' => false, 'message' => '唤醒功能不可用。'];
    }

    /**
     * 处理开门命令
     */
    private static function handleOpen(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/open.php';
        return cmd_open($charId, $param);
    }

    /**
     * 处理关门命令
     */
    private static function handleClose(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/open.php';
        return cmd_close($charId, $param);
    }

    /**
     * 处理跳跃命令
     * 特殊处理：八卦桥迷宫中 jump bridge → 跳桥到荷塘中
     * 其他情况：作为普通表情动作处理
     */
    private static function handleJump(int $charId, string $param, array $char): array {
        // 雪山迷宫：jump → 跳石壁
        $currentRoom = $char['current_room'];
        if (preg_match('/xueshan\/snowmaze\d$/', $currentRoom)) {
            require_once __DIR__ . '/SnowMazeHandler.php';
            return SnowMazeHandler::handleJumpBridge($charId, $currentRoom);
        }
        
        // 八卦桥迷宫：jump bridge → 跳桥
        if (preg_match('/qujing\/wuzhuang\/wzgmaze\d$/', $currentRoom)) {
            if ($param === 'bridge' || $param === '桥') {
                require_once __DIR__ . '/WzgmazeHandler.php';
                return WzgmazeHandler::handleJumpBridge($charId, $currentRoom);
            }
        }
        
        // 水帘洞瀑布：jump pubu → 跳瀑布
        if ($param === 'pubu' && ($currentRoom === 'dntg/hgs/pubu' || strpos($currentRoom, 'pubu') !== false)) {
            require_once DAEMON_PATH . 'WaterfallHandler.php';
            $handler = new WaterfallHandler();
            return $handler->execute($charId, ['action_cmd' => 'jump pubu', 'handler_class' => 'WaterfallHandler'], ['arg' => 'pubu']);
        }
        
        // 铁板桥：jump bridge → 跳出瀑布
        if ($param === 'bridge' && ($currentRoom === 'dntg/hgs/tiebanqiao' || strpos($currentRoom, 'tiebanqiao') !== false)) {
            require_once DAEMON_PATH . 'WaterfallHandler.php';
            $handler = new WaterfallHandler();
            return $handler->execute($charId, ['action_cmd' => 'jump bridge', 'handler_class' => 'WaterfallHandler'], ['arg' => 'bridge']);
        }

        // ★ 检查是否有 room_actions 中注册的自定义 jump 动作（如泾水桥跳桥等）
        $customResult = self::handleCustomAction($charId, 'jump', $param);
        if ($customResult['success']) {
            return $customResult;
        }

        // 无匹配的动作：作为表情处理
        require_once __DIR__ . '/EmoteDaemon.php';
        $target = !empty($param) ? $param : null;
        return EmoteDaemon::execute($charId, 'jump', $target);
    }

    /**
     * 处理雪山迷宫敲墙动作
     * 格式: qiang
     */
    private static function handleQiang(int $charId, string $param, array $char): array {
        // 检查当前房间是否为雪山迷宫房间
        $currentRoom = $char['current_room'];
        if (preg_match('/xueshan\/snowmaze\d$/', $currentRoom)) {
            require_once __DIR__ . '/SnowMazeHandler.php';
            return SnowMazeHandler::handleKnockWall($charId, $currentRoom);
        }
        
        // 非雪山迷宫房间：返回错误信息
        return [
            'success' => false,
            'message' => "这里没有墙可以敲。\n"
        ];
    }

    /**
     * 处理通天河区域动作
     * 统一路由到 TongtianHandler
     * 
     * 支持的 action_cmd：
     * - cross_ice: 冰面穿越（冰道房间）
     * - hedi_cold: 河底寒冷伤害
     * - exit_hedi: 从河底上浮
     * - ask_huashen: 询问化身NPC
     */
    private static function handleTongtianAction(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/TongtianHandler.php';
        
        $handler = new TongtianHandler();
        
        // 从请求中获取动作命令
        $actionCmd = $_GET['action'] ?? $_POST['action'] ?? $param;
        
        $action = [
            'action_cmd' => $actionCmd,
            'action_name' => '通天河事件',
        ];
        
        return $handler->execute($charId, $action, []);
    }

    /**
     * 处理白虎岭迷宫中拾取舍利子
     * 舍利子存储在session中，不走标准get命令流程
     */
    private static function handleGetShelizi(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/BaihulingHandler.php';
        return BaihulingHandler::handlePickShelizi($charId);
    }

    /**
     * 处理白虎岭区域动作
     * 统一路由到 BaihulingHandler
     * 
     * 支持的 action_cmd：
     * - enter: 进入主迷宫（入口房间）
     * - zuan: 钻入小迷宫（地牢房间）
     * - bury: 埋葬舍利子（出口房间）
     */
    private static function handleBaihulingAction(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/BaihulingHandler.php';
        
        $handler = new BaihulingHandler();
        
        // 从请求中获取动作命令
        $actionCmd = $_GET['action'] ?? $_POST['action'] ?? $param;
        
        $action = [
            'action_cmd' => $actionCmd,
            'action_name' => '白虎岭事件',
        ];
        
        return $handler->execute($charId, $action, []);
    }
    
    /**
     * 处理婚礼服务相关动作
     */
    private static function handleWeddingAction(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/WeddingServiceHandler.php';
        $handler = new WeddingServiceHandler();
        
        // 从请求中获取动作命令
        $actionCmd = $_GET['action'] ?? $_POST['action'] ?? $param;
        
        // 获取NPC ID
        $npcId = isset($_GET['npc_id']) ? intval($_GET['npc_id']) : (isset($_POST['npc_id']) ? intval($_POST['npc_id']) : 0);
        
        if ($actionCmd === 'enter_palanquin') {
            // 新娘进入花轿
            return $handler->brideEnterPalanquin($charId, $npcId);
        } elseif ($actionCmd === 'arrive_destination') {
            // 到达目的地
            return $handler->arriveDestination($charId, $npcId);
        }
        
        return ['success' => false, 'message' => '未知的婚礼动作。'];
    }
    
    /**
     * 处理喜福会喜宴动作 (start_party, serve, finish)
     */
    private static function handleXifuhuiAction(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/XifuhuiHandler.php';
        $handler = new XifuhuiHandler();
        
        $actionCmd = $_GET['action'] ?? $_POST['action'] ?? $param;
        $npcId = intval($_GET['npc_id'] ?? $_POST['npc_id'] ?? 0);
        
        $params = ['npc_id' => $npcId, 'char_id' => $charId];
        
        switch ($actionCmd) {
            case 'start_party':
                return $handler->handleStartParty($charId, $npcId);
            case 'serve':
                return $handler->handleServe($charId, $npcId);
            case 'finish':
                return $handler->handleFinish($charId, $npcId);
            default:
                return ['success' => false, 'message' => '未知的喜宴动作。'];
        }
    }
    
    /**
     * 处理赴京请赏命令 (reward / 请赏)
     * 在皇宫大殿向太宗请赏，需要至少100点品德值
     */
    private static function handleKaifengReward(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/kaifeng_reward.php';
        return cmd_kaifeng_reward($charId, $param);
    }

    /**
     * 处理巫师管理命令 (admin)
     * 统一入口，子命令委托给对应命令文件
     * 命令函数 cmd_admin() 位于 functions/admin.php
     */
    private static function handleAdmin(int $charId, string $param, array $char): array {
        if (!defined('_ADMIN_CMD_MODE')) {
            define('_ADMIN_CMD_MODE', true);
        }
        require_once __DIR__ . '/../functions/admin.php';
        if (function_exists('cmd_admin')) {
            return cmd_admin($charId, $param);
        }
        return ['success' => false, 'message' => '管理命令暂不可用。'];
    }

    /**
     * 处理放弃任务命令 (abandon / 放弃 / 跪拜)
     * 在清心宫/宁心宫/静心宫跪拜放弃当前任务
     */
    private static function handleAbandonQuest(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../helpers/QuestHelper.php';

        // 检查是否在允许放弃任务的地点
        $roomId = $char['current_room'] ?? '';
        $area = $char['current_area'] ?? '';
        $allowedRooms = ['qingxin', 'ningxin', 'jingxin', '清心宫', '宁心宫', '静心宫'];
        $allowedAreas = ['kaifeng', '开封'];

        $roomAllowed = false;
        foreach ($allowedRooms as $allowed) {
            if (mb_stripos($roomId, $allowed) !== false) {
                $roomAllowed = true;
                break;
            }
        }

        // 如果不在指定房间，检查是否在开封区域内
        if (!$roomAllowed) {
            foreach ($allowedAreas as $allowed) {
                if (mb_stripos($area, $allowed) !== false || mb_stripos($roomId, $allowed) !== false) {
                    $roomAllowed = true;
                    break;
                }
            }
        }

        if (!$roomAllowed) {
            return [
                'success' => false,
                'message' => '你需在清心宫、宁心宫或静心宫诚心跪拜，方能放弃任务。'
            ];
        }

        return QuestHelper::abandonQuest($charId);
    }

    /**
     * 处理骑乘命令 (mount / ride / 骑)
     */
    private static function handleMount(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/mount.php';
        if (class_exists('MountCommand')) {
            $params = !empty($param) ? explode(' ', $param) : [];
            return MountCommand::execute($charId, $params);
        }
        return ['success' => false, 'message' => '骑乘功能不可用'];
    }

    /**
     * 处理下马命令 (dismount / 下马)
     */
    private static function handleDismount(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/dismount.php';
        if (class_exists('DismountCommand')) {
            return DismountCommand::execute($charId, []);
        }
        return ['success' => false, 'message' => '下马功能不可用'];
    }

    /**
     * 处理驯服命令 (train / 驯服 / xunfu)
     */
    private static function handleTrain(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/train.php';
        if (class_exists('TrainCommand')) {
            $params = !empty($param) ? explode(' ', $param) : [];
            return TrainCommand::execute($charId, $params);
        }
        return ['success' => false, 'message' => '驯服功能不可用'];
    }

    /**
     * 处理牵马命令 (qian / 牵)
     */
    private static function handleQian(int $charId, string $param, array $char): array {
        require_once __DIR__ . '/../commands/qian.php';
        if (class_exists('QianCommand')) {
            $params = !empty($param) ? explode(' ', $param) : [];
            return QianCommand::execute($charId, $params);
        }
        return ['success' => false, 'message' => '牵马功能不可用'];
    }
    
    /**
     * 从玩家背包中移除物品（辅助方法）
     * 
     * @param int $charId 角色ID
     * @param string $itemId 物品ID
     * @param string $category 物品分类
     * @param int $quantity 移除数量
     */
    private static function removeInventoryItem(int $charId, string $itemId, string $category, int $quantity): void {
        require_once __DIR__ . '/../includes/db.php';
        
        if ($category !== '') {
            Database::execute(
                'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND category = ?',
                [$quantity, $charId, $itemId, $category]
            );
            Database::execute(
                'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ? AND quantity <= 0',
                [$charId, $itemId, $category]
            );
        } else {
            Database::execute(
                'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\')',
                [$quantity, $charId, $itemId]
            );
            Database::execute(
                'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\') AND quantity <= 0',
                [$charId, $itemId]
            );
        }
    }
    
    /**
     * 处理使用毫毛命令 (usehair)
     * 使用毫毛变化出对应类型的物品
     */
    private static function handleUseHair(int $charId, string $param, array $char): array {
        require_once MODEL_PATH . 'Item.php';
        require_once DAEMON_PATH . 'MessageDaemon.php';
        
        $hairName = trim($param);
        
        $hairMap = [
            'amberhair' => ['衣服', 'cloth', 'armor', 'armor_type'],
            'blackhair' => ['食物', 'food', 'food', 'type'],
            'bluehair' => ['棍类武器', 'stick', 'weapon', 'weapon_type'],
            'brownhair' => ['剑类武器', 'sword', 'weapon', 'weapon_type'],
            'greenhair' => ['锤类武器', 'hammer', 'weapon', 'weapon_type'],
            'indigohair' => ['锏类武器', 'mace', 'weapon', 'weapon_type'],
            'magentahair' => ['帽子', 'head', 'armor', 'armor_type'],
            'maroonhair' => ['鞋子', 'boots', 'armor', 'armor_type'],
            'orangehair' => ['刀类武器', 'blade', 'weapon', 'weapon_type'],
            'pinkhair' => ['枪类武器', 'spear', 'weapon', 'weapon_type'],
            'redhair' => ['斧类武器', 'axe', 'weapon', 'weapon_type'],
            'scarlethair' => ['项链', 'neck', 'armor', 'armor_type'],
            'violethair' => ['鞭类武器', 'whip', 'weapon', 'weapon_type'],
            'whitehair' => ['饮料', 'water', 'food', 'type'],
            'yellowhair' => ['叉类武器', 'fork', 'weapon', 'weapon_type'],
            'hamber' => ['衣服', 'cloth', 'armor', 'armor_type'],
            'hblack' => ['食物', 'food', 'food', 'type'],
            'hblue' => ['棍类武器', 'stick', 'weapon', 'weapon_type'],
            'hbrown' => ['剑类武器', 'sword', 'weapon', 'weapon_type'],
            'hgreen' => ['锤类武器', 'hammer', 'weapon', 'weapon_type'],
            'hindigo' => ['锏类武器', 'mace', 'weapon', 'weapon_type'],
            'hmagenta' => ['帽子', 'head', 'armor', 'armor_type'],
            'hmaroon' => ['鞋子', 'boots', 'armor', 'armor_type'],
            'horange' => ['刀类武器', 'blade', 'weapon', 'weapon_type'],
            'hpink' => ['枪类武器', 'spear', 'weapon', 'weapon_type'],
            'hred' => ['斧类武器', 'axe', 'weapon', 'weapon_type'],
            'hscarlet' => ['项链', 'neck', 'armor', 'armor_type'],
            'hviolet' => ['鞭类武器', 'whip', 'weapon', 'weapon_type'],
            'hwhite' => ['饮料', 'water', 'food', 'type'],
            'hyellow' => ['叉类武器', 'fork', 'weapon', 'weapon_type'],
        ];
        
        if (!isset($hairMap[$hairName])) {
            return ['success' => false, 'message' => '未知的毫毛类型。<br>'];
        }
        
        [$targetTypeName, $targetTypeValue, $itemCategory, $typeColumn] = $hairMap[$hairName];
        
        $removeResult = ItemModel::removeFromInventory($charId, $hairName, 1);
        if (!$removeResult) {
            return ['success' => false, 'message' => '你没有这种毫毛。<br>'];
        }
        
        $items = Database::queryAll(
            "SELECT id, name, item_id, type, category FROM items WHERE $itemCategory = ? AND $typeColumn = ? AND is_real = 1 ORDER BY RAND() LIMIT 1",
            [$itemCategory, $targetTypeValue]
        );
        
        if (empty($items)) {
            return ['success' => false, 'message' => '毫毛失去了法力，什么也没变成。<br>'];
        }
        
        $generatedItem = $items[0];
        ItemModel::addToInventory($charId, $generatedItem['name'], 1);
        
        $hairItem = Database::queryOne("SELECT item_id FROM items WHERE name = ?", [$hairName]);
        $hairTitle = $hairItem ? $hairItem['item_id'] : '毫毛';
        
        $message = HTML_HIYEL . "你捻起{$hairTitle}，口中念念有词，毫毛化作一道金光，变成了" . $generatedItem['item_id'] . "！<br>" . HTML_NOR;
        
        $roomId = $char['current_room'] ?? '';
        if (!empty($roomId)) {
            MessageDaemon::broadcastToRoom($roomId,
                HTML_HIYEL . $char['name'] . '捻起一根毫毛，口中念念有词，毫毛化作一道金光消失了。' . HTML_NOR,
                $charId, 'room');
        }
        
        return [
            'success' => true,
            'message' => $message,
        ];
    }
}

