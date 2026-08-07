<?php
/**
 * 询问命令 (ask) - 向NPC询问信息
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 用法: ask <某人> about <某事>
 * 
 * 核心逻辑流程:
 * 1. 解析参数，提取NPC目标和话题
 * 2. 在当前房间查找NPC（从房间数据）
 * 3. 检查NPC是否能说话 (can_talk字段)
 * 4. 查询NPC的inquiry配置（JSON字段）
 * 5. 匹配topic: 精确匹配优先，部分匹配次之
 * 6. 处理匹配结果: 字符串直接回答, callable调用NpcInquiryHelper
 * 7. 如果无匹配: 随机返回"不知道"消息
 */

// 加载任务配置
static $_questCfg = null;
if ($_questCfg === null) {
    $_questCfg = require __DIR__ . '/../config/quest.php';
}

// NPC无法回答消息模板（来自原始项目）
$MSG_DUNNO = [
    '{npc_name}摇摇头，说道：没听说过。',
    '{npc_name}疑惑地看着{player_name}，摇了摇头。',
    '{npc_name}睁大眼睛望着{player_name}，显然不知道在说什么。',
    '{npc_name}耸了耸肩，很抱歉地说：无可奉告。',
    '{npc_name}说道：嗯...这我可不清楚，你最好问问别人吧。',
    '{npc_name}想了一会儿，说道：对不起，你问的事我实在没有印象。'
];

/**
 * 执行ask命令
 * @param int $charId 角色ID
 * @param string $param 命令参数
 * @return array 结果数组
 */
function cmd_ask(int $charId, string $param = ''): array {
    if (empty($param)) {
        return ['success' => false, 'message' => '你要问谁什么事？'];
    }
    
    // 解析参数: ask 张三 about 宝藏
    if (!preg_match('/^(.+?)\s+about\s+(.+)$/i', $param, $matches)) {
        return ['success' => false, 'message' => '你要问谁什么事？'];
    }
    
    $targetName = trim($matches[1]);
    $topic = trim($matches[2]);
    
    // 获取角色完整信息（包含技能）
    $char = CharacterModel::getFullInfo($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    $area = $char['current_area'] ?? '';
    $roomId = $char['current_room'] ?? '';
    if (empty($area) || empty($roomId)) {
        return ['success' => false, 'message' => '你不在任何房间中。'];
    }
    
    // 获取房间信息（包含NPC列表）
    $fullRoomId = (strpos($roomId, '/') !== false) ? $roomId : $area . '/' . $roomId;
    $room = RoomModel::getFullInfo($area, $fullRoomId);
    
    // 如果无法获取房间信息，使用空列表
    $npcs = $room['npcs'] ?? [];
    
    // 查找目标NPC（优先精确匹配名称，再匹配npc_id，最后部分匹配）
    $targetNpc = findNpcInRoom($npcs, $targetName);
    
    // 特殊处理：人参果园事件中的镇元大仙（虚拟NPC）
    $renshenEventAsk = false;
    if (!$targetNpc && $fullRoomId === 'qujing/wuzhuang/renshenguo-yuan') {
        require_once DAEMON_PATH . 'RenshenEventHandler.php';
        $rsPhase = RenshenEventHandler::getCurrentPhase();
        if ($rsPhase !== 'idle' && $rsPhase !== 'cooldown') {
            // 检查是否在询问镇元大仙
            if (mb_stripos('镇元大仙', $targetName) !== false || mb_stripos('zhenyuan', $targetName) !== false) {
                $targetNpc = [
                    'id' => 663,
                    'npc_id' => 'zhenyuan',
                    'name' => '镇元大仙',
                    'can_talk' => true,
                    'inquiry' => []
                ];
                $renshenEventAsk = true;
            }
        }
    }
    
    if (!$targetNpc) {
        return ['success' => false, 'message' => '这里没有这个人。'];
    }
    
    // 加载消息广播模块
    require_once DAEMON_PATH . 'MessageDaemon.php';
    
    // 检查NPC是否可以说话
    if (isset($targetNpc['can_talk']) && !$targetNpc['can_talk']) {
        $senderMessage = "你向{$targetNpc['name']}打听有关『{$topic}』的消息，但是它显然听不懂人话。";
        broadcastAskMessages($fullRoomId, $charId, $char['name'], $targetNpc['name'], $topic, $senderMessage);
        return [
            'success' => true,
            'message' => $senderMessage,
            'skip_queue' => true
        ];
    }
    
    // ★ 开封解谜：检查是否在问目标NPC（用于完成 ask 类型任务）
    require_once __DIR__ . '/../helpers/QuestHelper.php';
    $pendingQuests = QuestHelper::getPendingQuests($charId);
    foreach ($pendingQuests as $quest) {
        if (($quest['quest_type'] ?? '') === 'ask' && ($quest['target_id'] ?? '') === ($targetNpc['npc_id'] ?? '')) {
            $questObject = $quest['object_name'] ?? '';
            if ($topic === $questObject || mb_stripos($topic, $questObject) !== false || mb_stripos($questObject, $topic) !== false) {
                // ★ 使用 markQuestDone 标记为 done（需回访领奖），而非直接completed
                QuestHelper::markQuestDone($charId, 'ask', $targetNpc['npc_id'] ?? '');
                $senderMessage = HTML_HIYEL . "【开封解谜】" . HTML_NOR . "{$targetNpc['name']}告诉了你关于「{$questObject}」的消息。\n任务完成！快回去复命吧。";
                broadcastAskMessages($fullRoomId, $charId, $char['name'], $targetNpc['name'], $topic, $senderMessage);
                return ['success' => true, 'message' => $senderMessage];
            }
        }
    }
    
    // 显示询问消息
    $askMessage = "你向{$targetNpc['name']}打听有关『{$topic}』的消息。";
    
    // ★ 开封解谜任务系统优先检查（必须在 inquiry 匹配之前，否则 test_player 等 callable 会吞掉任务话题）
    $kaifengResult = handleKaifengQuest($charId, $char, $targetNpc, $topic);
    if ($kaifengResult !== null) {
        $senderMessage = "{$askMessage}\n{$kaifengResult}";
        broadcastAskMessages($fullRoomId, $charId, $char['name'], $targetNpc['name'], $topic, $senderMessage);
        return [
            'success' => true,
            'message' => $senderMessage,
            'skip_queue' => true
        ];
    }
    
    // 解析NPC的inquiry配置
    $inquiryData = parseInquiryData($targetNpc);
    
    // 科举考试答题处理：如果玩家正在进行科举考试，且topic是答案格式（如ABC），直接处理答题
    $examAnswer = null;
    if (isset($_SESSION['exam_questions']) && $_SESSION['exam_questions']['char_id'] === $charId) {
        $userAnswer = strtoupper(trim($topic));
        if (preg_match('/^[ABCD]{3}$/', $userAnswer)) {
            require_once HELPER_PATH . 'NpcInquiryHelper.php';
            $rankNames = [
                0 => '白丁',
                1 => '秀才',
                2 => '举人',
                3 => '进士',
                4 => '翰林',
                5 => '侍郎',
            ];
            $examAnswer = NpcInquiryHelper::processExamAnswer($charId, $userAnswer, $targetNpc['name'], $rankNames);
        }
    }
    
    // 匹配topic，获取回答
    $answer = $examAnswer ?? matchInquiry($inquiryData, $targetNpc, $char, $topic);
    
    // 特殊处理：人参果园事件 - 问人参果
    if ($renshenEventAsk && (mb_stripos($topic, '人参果') !== false || mb_stripos($topic, 'renshenguo') !== false)) {
        require_once DAEMON_PATH . 'RenshenEventHandler.php';
        $result = RenshenEventHandler::distributeFruit($charId);
        $senderMessage = $askMessage . "\n" . ($result['message'] ?? '');
        broadcastAskMessages($fullRoomId, $charId, $char['name'], $targetNpc['name'], $topic, $senderMessage);
        // 写入消息队列，在 chat 页显示（同跳桥逻辑）
        MessageDaemon::queueMessageToSelf($charId, $senderMessage, 'self_event');
        return [
            'success' => $result['success'],
            'message' => $senderMessage,
            'skip_queue' => true
        ];
    }
    
    // 检查大闹天宫任务（第2关：与龙王对话）
    $dntgMsg = '';
    require_once DAEMON_PATH . 'DntgQuestHandler.php';
    $dntgResult = DntgQuestHandler::checkInteraction(
        $charId,
        'ask',
        $fullRoomId,
        $targetNpc['name'] ?? null
    );
    if ($dntgResult && !empty($dntgResult['success'])) {
        $dntgMsg = "\n" . HTML_HIYEL . '【大闹天宫】' . HTML_NOR . ' 龙王将定海神针金箍棒交到你手中，说道："此物与你有缘，拿去罢！"';
        if (!empty($dntgResult['message'])) {
            $dntgMsg .= "\n" . $dntgResult['message'];
        }
    }
    
    if ($answer !== null) {
        // 检查是否包含重定向信息（数组返回值）
        if (is_array($answer)) {
            $message = $answer['message'] ?? '';
            $redirect = $answer['redirect'] ?? '';
            $newArea = $answer['new_area'] ?? '';
            $newRoom = $answer['new_room'] ?? '';
            
            // 广播消息
            $senderMessage = "{$askMessage}\n{$message}{$dntgMsg}";
            broadcastAskMessages($fullRoomId, $charId, $char['name'], $targetNpc['name'], $topic, $senderMessage);
            
            $result = [
                'success' => true,
                'message' => $senderMessage,
                'skip_queue' => true
            ];
            
            // 如果包含重定向信息，添加到返回值
            if (!empty($redirect)) {
                $result['redirect'] = $redirect;
            }
            if (!empty($newArea) && !empty($newRoom)) {
                $result['new_area'] = $newArea;
                $result['new_room'] = $newRoom;
            }
            
            return $result;
        }
        
        // NPC有回答 - 检查handler返回的消息是否已包含NPC名字前缀，避免重复
        $npcName = $targetNpc['name'];
        $plainAnswer = strip_tags(preg_replace('/<[^>]+>/', '', $answer));
        if (mb_strpos($plainAnswer, $npcName) === 0) {
            // handler已包含NPC名字，直接使用
            $npcSayHtml = $answer;
        } else {
            // 字符串类型回答，加上NPC名字前缀
            $npcSayHtml = HTML_HICYN . "{$npcName}说道：{$answer}" . HTML_NOR;
        }
        $senderMessage = "{$askMessage}\n{$npcSayHtml}{$dntgMsg}";
        broadcastAskMessages($fullRoomId, $charId, $char['name'], $targetNpc['name'], $topic, $senderMessage);
        return [
            'success' => true,
            'message' => $senderMessage,
            'skip_queue' => true
        ];
    }
    
    // 没有预设回答，使用随机"不知道"消息
    global $MSG_DUNNO;
    $randomMsg = $MSG_DUNNO[array_rand($MSG_DUNNO)];
    $randomMsg = str_replace(
        ['{npc_name}', '{player_name}'],
        [$targetNpc['name'], $char['name']],
        $randomMsg
    );
    
    // "不知道"消息也用青色显示
    $dunnoHtml = HTML_HICYN . $randomMsg . HTML_NOR;
    $senderMessage = "{$askMessage}\n{$dunnoHtml}{$dntgMsg}";
    broadcastAskMessages($fullRoomId, $charId, $char['name'], $targetNpc['name'], $topic, $senderMessage);
    
    return [
        'success' => true,
        'message' => $senderMessage,
        'skip_queue' => true
    ];
}

/**
 * 在房间NPC列表中查找目标NPC
 * 匹配优先级：1.精确名称匹配 2.npc_id匹配 3.部分名称匹配
 * 
 * @param array $npcs 房间内的NPC列表
 * @param string $targetName 目标名称或ID
 * @return array|null 匹配到的NPC数据，null表示未找到
 */
function findNpcInRoom(array $npcs, string $targetName): ?array {
    if (empty($npcs)) {
        return null;
    }
    
    // 优先级1: 精确匹配名称
    foreach ($npcs as $npc) {
        if (strcasecmp($npc['name'], $targetName) === 0) {
            return $npc;
        }
    }
    
    // 优先级2: 匹配npc_id
    foreach ($npcs as $npc) {
        if (strcasecmp($npc['npc_id'], $targetName) === 0) {
            return $npc;
        }
    }
    
    // 优先级3: 部分匹配名称（包含关系）
    foreach ($npcs as $npc) {
        if (mb_stripos($npc['name'], $targetName) !== false) {
            return $npc;
        }
    }
    
    // 优先级4: 部分匹配npc_id
    foreach ($npcs as $npc) {
        if (stripos($npc['npc_id'], $targetName) !== false) {
            return $npc;
        }
    }
    
    return null;
}

/**
 * 解析NPC的inquiry数据
 * 从JSON字符串解析为数组格式
 * 
 * @param array $npc NPC数据
 * @return array 解析后的inquiry数组，key为话题，value为回答内容
 */
function parseInquiryData(array $npc): array {
    $raw = $npc['inquiry'] ?? null;
    
    if (empty($raw)) {
        return [];
    }
    
    // 如果已经是数组（从数据库取出的JSON字段可能已被自动解析）
    if (is_array($raw)) {
        return $raw;
    }
    
    // 尝试JSON解码
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    
    return [];
}

/**
 * 匹配inquiry话题
 * 匹配优先级：1.精确匹配 2.部分匹配（topic包含在key中，或key包含在topic中）
 * 
 * @param array $inquiryData inquiry配置数组
 * @param array $npc NPC数据
 * @param array $char 玩家数据
 * @param string $topic 询问话题
 * @return string|null 回答内容，null表示无匹配
 */
function matchInquiry(array $inquiryData, array $npc, array $char, string $topic) {
    if (empty($inquiryData)) {
        return null;
    }
    
    // 优先级1: 精确匹配（不区分大小写）
    foreach ($inquiryData as $key => $value) {
        if (strcasecmp($key, $topic) === 0) {
            return resolveInquiryValue($value, $npc, $char, $topic);
        }
    }
    
    // 优先级2: 部分匹配 - topic包含在key中，或key包含在topic中
    foreach ($inquiryData as $key => $value) {
        if (mb_stripos($key, $topic) !== false || mb_stripos($topic, $key) !== false) {
            return resolveInquiryValue($value, $npc, $char, $topic);
        }
    }
    
    return null;
}

/**
 * 解析inquiry值
 * 支持: 字符串直接回答, ["callable", "method_name"] 调用NpcInquiryHelper
 * 
 * @param mixed $value inquiry的值
 * @param array $npc NPC数据
 * @param array $char 玩家数据
 * @param string $topic 询问话题
 * @return string|null 回答内容
 */
function resolveInquiryValue($value, array $npc, array $char, string $topic) {
    if (is_string($value)) {
        // 字符串: 直接作为回答，替换模板变量
        return str_replace(
            ['{player_name}', '{npc_name}', '{topic}'],
            [$char['name'] ?? '', $npc['name'] ?? '', $topic],
            $value
        );
    }
    
    if (is_array($value) && isset($value[0]) && $value[0] === 'callable') {
        // callable格式: ["callable", "method_name"] 或 ["callable", "method_name", "extra_param"]
        require_once HELPER_PATH . 'NpcInquiryHelper.php';
        $result = NpcInquiryHelper::handleCallable($value, $npc, $char, $topic);
        if ($result !== null) {
            return $result;
        }
        // callable处理失败，降级为null（将使用"不知道"消息）
        return null;
    }
    
    // 其他类型尝试转为字符串
    if (is_array($value) && isset($value['message'])) {
        // 对象格式: {"message": "回答内容"}
        return str_replace(
            ['{player_name}', '{npc_name}', '{topic}'],
            [$char['name'] ?? '', $npc['name'] ?? '', $topic],
            $value['message']
        );
    }
    
    return null;
}

/**
 * 广播ask命令消息到房间
 * 发送者看到完整消息（含NPC回答），其他玩家只看到询问动作
 * 
 * @param string $fullRoomId 完整房间ID
 * @param int $charId 发送者角色ID
 * @param string $charName 发送者名称
 * @param string $npcName NPC名称
 * @param string $topic 询问话题
 * @param string $senderMessage 发送者看到的完整消息
 */
function broadcastAskMessages(string $fullRoomId, int $charId, string $charName, string $npcName, string $topic, string $senderMessage): void {
    // 发送给询问者自己的完整消息
    MessageDaemon::queueMessageToSelf($charId, $senderMessage, 'npc_dialog');
    
    // 广播给房间内其他玩家（只看到询问动作，不看到回答）
    $othersMessage = "{$charName}向{$npcName}打听有关『{$topic}』的消息。";
    MessageDaemon::broadcastToRoom($fullRoomId, $othersMessage, $charId, 'npc_dialog');
}

/**
 * 处理开封解谜任务
 * 检查NPC是否为解谜NPC，根据话题分配新任务或完成已有任务
 * 
 * @param int $charId 角色ID
 * @param array $char 角色数据
 * @param array $npc NPC数据
 * @param string $topic 询问话题
 * @return string|null 任务消息，null表示NPC不是解谜NPC或话题不匹配
 */
function handleKaifengQuest(int $charId, array $char, array $npc, string $topic): ?string {
    require_once __DIR__ . '/../helpers/QuestHelper.php';
    
    $npcMap = QuestHelper::getNpcMap();
    if (empty($npcMap)) {
        return null;
    }
    
    // 检查当前NPC是否为开封解谜NPC（通过npc_id匹配）
    $npcId = $npc['npc_id'] ?? '';
    $questConfig = $npcMap[$npcId] ?? null;
    
    if (!$questConfig) {
        return null;
    }
    
    // 检查话题是否匹配NPC的触发词（使用topic字段）
    $npcTopic = $questConfig['topic'] ?? '';
    $topicMatched = false;
    
    // 直接匹配NPC的主题词
    if (mb_stripos($topic, $npcTopic) !== false || mb_stripos($npcTopic, $topic) !== false) {
        $topicMatched = true;
    }
    
    // 额外的触发词映射（同一个NPC可能有多种说法，同时支持英文quest_type作为触发词）
    $extraTriggers = [
        'food'    => ['食物', '美食', '吃', '饭', 'food', '食物'],
        'weapon'  => ['武器', '兵器', '刀', '剑', 'weapon'],
        'armor'   => ['盔甲', '护具', '防具', '盾', 'armor'],
        'cloth'   => ['衣物', '衣服', '布', '裙', 'cloth'],
        'wearing' => ['首饰', '珠宝', '饰品', '佩', 'wearing'],
        'misc'    => ['家具', '什物', '杂物', '物', 'misc'],
        'ask'     => ['求签', '问卦', '求福', '祭祖', '祭贤', '问安', 'ask', '求'],
        'kill'    => ['灭妖', '斩妖', '除怪', '杀', 'kill', '妖魔', '妖怪', '怪物', '除妖'],
        'give'    => ['施舍', '送礼', '送东西', '给', '送', 'give', '馈赠'],
    ];
    $questType = $questConfig['quest_type'];
    if (!$topicMatched && isset($extraTriggers[$questType])) {
        foreach ($extraTriggers[$questType] as $trigger) {
            if (mb_stripos($topic, $trigger) !== false || mb_stripos($trigger, $topic) !== false) {
                $topicMatched = true;
                break;
            }
        }
    }
    // 从 NPC inquiry 中提取所有话题键作为额外触发词
    if (!$topicMatched) {
        $inquiryData = !empty($npc['inquiry']) ? json_decode($npc['inquiry'], true) : [];
        if (is_array($inquiryData)) {
            foreach ($inquiryData as $inqKey => $inqVal) {
                if ($inqKey !== 'here' && $inqKey !== 'name') {
                    if (mb_stripos($topic, $inqKey) !== false || mb_stripos($inqKey, $topic) !== false) {
                        $topicMatched = true;
                        break;
                    }
                }
            }
        }
    }
    
    if (!$topicMatched) {
        return null;
    }
    
    // 1. ★ 先检查是否有 done 状态的任务（玩家完成目标后回来领奖）
    $doneQuests = QuestHelper::getDoneQuests($charId);
    foreach ($doneQuests as $quest) {
        if (($quest['quest_type'] ?? '') === $questType) {
            // 调用回访领奖（claimQuestReward）发放奖励
            $claimResult = QuestHelper::claimQuestReward($charId, $quest['id'], $npcId);
            if ($claimResult['success']) {
                $npcName = $questConfig['name'];
                $questName = $claimResult['quest_name'] ?? '';
                
                // 原始项目风格的奖励消息（参考 hu.c rewarding 函数）
                $questTypeText = [
                    'kill'  => '消灭',
                    'ask'   => '询问',
                    'give'  => '送达',
                    'find'  => '寻找',
                    'weapon' => '寻找兵器',
                    'food'  => '寻找食物',
                    'cloth' => '寻找衣物',
                    'armor' => '寻找盔甲',
                    'wearing' => '寻找穿戴',
                    'misc'  => '寻找',
                ];
                $questText = $questTypeText[$questType] ?? '完成';
                
                $rewardMsg = "{$questText}「{$questName}」";
                if ($claimResult['daoxing'] > 0) $rewardMsg .= "，获得道行{$claimResult['daoxing']}";
                if ($claimResult['potential'] > 0) $rewardMsg .= "，潜能{$claimResult['potential']}";
                if ($claimResult['silver'] > 0) $rewardMsg .= "，白银{$claimResult['silver']}";
                $rewardMsg .= "，品德+" . $claimResult['moral'];
                
                return HTML_HIYEL . "{$npcName}对你说道：\"" . HTML_NOR 
                     . "各位！这侠士{$rewardMsg}，其赏金可以在京师领取！\"" . HTML_NOR . "\n"
                     . $claimResult['cloud_message'];
            }
        }
    }
    
    // 2. 检查是否有进行中的任务（先检查过期）
    QuestHelper::checkExpiredQuests($charId);
    $pendingQuests = QuestHelper::getPendingQuests($charId);
    $hasMyQuest = false;
    $expiredQuest = null;
    foreach ($pendingQuests as $quest) {
        if (($quest['quest_type'] ?? '') === $questType) {
            $hasMyQuest = true;
            break;
        }
    }
    
    // 3. 已有进行中的任务，提示玩家继续完成
    if ($hasMyQuest) {
        $npcName = $questConfig['name'];
        $hint = '';
        foreach ($pendingQuests as $quest) {
            if (($quest['quest_type'] ?? '') === $questType) {
                $targetName = $quest['quest_name'] ?? $quest['target_id'] ?? '';
                $objectName = $quest['object_name'] ?? '';
                if ($questType === 'ask') {
                    $hint = "去「{$targetName}」那里打听「{$objectName}」的消息吧！";
                } elseif ($questType === 'kill') {
                    $hint = "去消灭「{$targetName}」吧！";
                } elseif ($questType === 'give') {
                    $hint = "去把东西送给「{$targetName}」吧！";
                } else {
                    $hint = "去寻「{$targetName}」吧！";
                }
                break;
            }
        }
        return HTML_HICYN . "{$npcName}皱着眉头对你说道：" . HTML_NOR 
             . "\"你手头还有事情没做完，速速去办！\"" . HTML_NOR . "\n"
             . $hint;
    }
    
    // 玩家没有进行中的任务，分配新任务
    $quest = QuestHelper::assignQuest($charId, $questType, $npcId);
    
    if ($quest) {
        $npcName = $questConfig['name'];
        $targetName = $quest['name'] ?? '';
        $objectName = $quest['object'] ?? $quest['topic'] ?? '';
        
        // 生成对话消息（参考原始LPC get_message 词库拼接设计）
        $message = generateQuestMessage($questType, $targetName, $objectName, $npcName);
        
        return HTML_HIYEL . "{$npcName}说道：" . HTML_NOR . "\"{$message}\"" . HTML_NOR;
    }
    
    // 任务分配失败（可能已有同类型任务）
    $npcName = $questConfig['name'];
    return HTML_HICYN . "{$npcName}说道：\"你手头还有事情没做完，先去办完再来吧。\"" . HTML_NOR;
}

/**
 * 生成任务派发对话消息（参考原始LPC chen.c get_message 词库拼接设计）
 * 
 * 原始LPC设计：
 * - 70%概率用动态拼接（msg1+msg2+$w+msg3+msg4+$o+msg5+msg6）
 * - 30%概率用固定模板
 * - $w=目标名称, $o=任务主题
 *
 * @param string $questType 任务类型
 * @param string $targetName 目标名称
 * @param string $objectName 对象/主题名称
 * @param string $npcName NPC名称
 * @return string 生成的对话消息
 */
function generateQuestMessage(string $questType, string $targetName, string $objectName, string $npcName): string {
    // ============ ask类型：动态词库拼接（参考 chen.c get_message）============
    if ($questType === 'ask') {
        $msg1 = [
            "燃起一炷香，对你说道：太上老君急如律令，速去",
            "拈香一拜，正色道：此事紧迫，速去",
            "掐指一算，抬头道：卦象已明，速去",
            "翻开黄历，对你说道：今日吉时，速去",
            "闭目凝神片刻，睁眼道：机缘已至，速去",
            "焚香祷告毕，对你言道：天机已现，速去",
        ];
        $msg2 = [
            "拜见", "参拜", "问候", "拜访", "造访", "求见", "参见",
            "请教", "问候一下", "拜访一下", "拜见一下", "请教一下",
            "参见一下", "求见一下", "参拜一下", "拜访拜见", "问候问候",
            "拜访拜访", "请教请教",
        ];
        $msg3 = ["顺便", "顺道", "顺路", "顺途", "顺脚", "顺便", ""];
        $msg4 = [
            "探问", "探听", "打听", "查问", "探询", "查询", "探知", "询问",
            "探访", "探寻", "打探", "探察", "查听", "察探",
            "探问探问", "询问询问", "打探打探", "察探查探", "一并探知", "打探一下",
            "探问有关", "打探有关", "查听有关", "查询有关", "探知有关", "探寻有关",
            "探问关于", "打探关于", "查听关于", "查询关于", "探知关于",
            "打听一下", "打听有关", "询问关于", "探询探询", "打听打听",
        ];
        $msg5 = ["一下", "之事", "的消息", "的情况", "的事情", ""];
        $msg6 = ["吧", "罢", "好不好", "！", "。"];
        
        // 30%概率用固定模板
        if (rand(0, 9) < 3) {
            $fixedTemplates = [
                "掐指一算，对你说：去{$targetName}那里，{$objectName}之事便知分晓！",
                "微微一笑：你且去{$targetName}处，{$objectName}的消息自然就有了。",
                "点了点头：此去{$targetName}，{$objectName}之事必有收获。",
                "抚掌笑道：好极！{$targetName}那里正有你所需，速去{$objectName}！",
                "击节赞赏，一拍大腿对你说道：好！{$targetName}那里有你要找的消息，去问问吧！",
                "拍案而起，对你说道：近日听说{$targetName}知道些事情，去打听打听{$objectName}！",
                "捋着胡须，微微一笑：出门在外，去{$targetName}那里问问{$objectName}的消息吧！",
            ];
            return $fixedTemplates[array_rand($fixedTemplates)];
        }
        
        // 70%概率动态拼接
        $part1 = $msg1[array_rand($msg1)];
        $part2 = $msg2[array_rand($msg2)];
        $part3 = !empty($part3 = $msg3[array_rand($msg3)]) ? $part3 : '';
        $part4 = $msg4[array_rand($msg4)];
        $part5 = $msg5[array_rand($msg5)];
        $part6 = $msg6[array_rand($msg6)];
        
        return "{$part1}{$part2}「{$targetName}」{$part3}{$part4}「{$objectName}」{$part5}{$part6}";
    }
    
    // ============ kill类型（参考 hu.c strs 数组）============
    if ($questType === 'kill') {
        $messages = [
            "站在山头，低声道：「{$targetName}」为祸一方，去为民除害吧！",
            "提起钢鞭厉声道：「{$targetName}」不除，百姓难安，速去！",
            "眼放精光：吾观天象，「{$targetName}」气数将尽，正是你建功之时！",
            "大喝道：小小「{$targetName}」也敢猖狂？你去取它首级来！",
            "皱眉道：上仙速占一卦，得知「{$targetName}」正蠢蠢欲动，速去将其斩了！",
            "哼了一声：最近那「{$targetName}」很不老实，你去看看吧！",
            "抬头一看：「{$targetName}」的营寨就在前方，去探探虚实！",
            "站在高处：最近「{$targetName}」为非作歹，你去收拾收拾！",
            "皱着眉头：「{$targetName}」又在作乱了，去制止他们！",
            "低声对你说道：「{$targetName}」在附近出没，务必小心！",
            "握紧钢鞭：「{$targetName}」作恶多端，今日便是它的死期！",
            "眼神凝重：「{$targetName}」实力不容小觑，你要多加小心！",
        ];
        return $messages[array_rand($messages)];
    }
    
    // ============ give类型 ============
    if ($questType === 'give') {
        $messages = [
            "叹了口气，对你说道：此事还需「{$targetName}」相助，你将此物送去！",
            "皱着眉头，对你说道：麻烦你跑一趟，把东西送给「{$targetName}」。",
            "点点头，对你说道：有劳去「{$targetName}」那里跑一趟。",
            "微微一笑，对你说道：去「{$targetName}」那里将此物交与他。",
            "招手示意，对你说道：此事需「{$targetName}」知晓，速去！",
            "郑重其事地交给你一件东西：速将此物送到「{$targetName}」手中！",
        ];
        return $messages[array_rand($messages)];
    }
    
    // ============ 寻物类型（weapon/armor/cloth/food/wearing/misc）============
    // 参考 xgong.c/xpo.c 的半固定模板 + 动态拼接设计
    $questPrefixes = [
        'weapon'  => ['兵器', '把', '一把'],
        'armor'   => ['盔甲防具', '副', '一副'],
        'cloth'   => ['衣物', '件', '一件'],
        'food'    => ['食物', '份', '一些'],
        'wearing' => ['首饰', '件', '一件'],
        'misc'    => ['物品', '个', '一个'],
    ];
    
    $prefix = $questPrefixes[$questType] ?? ['物品', '个', '一个'];
    $categoryName = $prefix[0];
    
    // 固定开场白（参考 xgong.c strs 数组）
    $openings = [
        "点点头，对你说道：正好缺{$categoryName}，去找{$prefix[2]}「{$targetName}」来！",
        "叹了口气，对你说道：去寻{$prefix[2]}「{$targetName}」来，此事甚急！",
        "招手对你说道：去市面上找{$prefix[2]}「{$targetName}」来！",
        "眉头微皱：急需{$prefix[2]}「{$targetName}」，你能否帮忙寻来？",
        "沉吟片刻，对你说道：老夫正需{$prefix[2]}「{$targetName}」，有劳了。",
        "抬头看了看你：这位壮士，能否帮老夫寻{$prefix[2]}「{$targetName}」？",
    ];
    
    // 30%概率追加帮忙请求（参考 xgong.c: "这位"+RANK_D->query_respect(who)+"能否帮老臣个忙？"）
    if (rand(0, 9) < 3) {
        return $openings[array_rand($openings)] . "\n这位侠士能否帮个忙？";
    }
    
    return $openings[array_rand($openings)];
}

/**
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 *
 * @param string $colorCode 颜色代码
 * @return string 颜色云消息
 */
function getColorCloudMessage(string $colorCode): string {
    $colorNames = [
        'red' => '红',
        'green' => '绿',
        'yellow' => '黄',
        'blue' => '蓝',
        'purple' => '紫',
        'cyan' => '青',
        'white' => '白',
        'pink' => '粉',
        'orange' => '橙',
    ];
    
    $colorName = $colorNames[$colorCode] ?? '白';
    
    $messages = [
        "一朵{$colorName}色祥云从你脚下升起，托着你缓缓飘上天空...",
        "只见你头顶一朵{$colorName}色祥云升起，瑞气千条，霞光万道！",
        "{$colorName}色祥云从天而降，笼罩在你身上，缓缓升起...",
        "一阵仙风拂过，一朵{$colorName}色祥云出现在你脚下，托着你徐徐上升。",
        "{$colorName}色祥云环绕你周身，缓缓升起，光芒四射！",
    ];
    
    return $messages[array_rand($messages)];
}
