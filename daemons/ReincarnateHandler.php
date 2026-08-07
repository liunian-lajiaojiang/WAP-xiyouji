<?php
/**
 * 还阳剧情处理器 (ReincarnateHandler)
 *
 * 玩家请求还阳时，按顺序播放崔判官的剧情消息，然后传送到荒郊小店。
 * 基于轮询机制实现消息的逐条播放效果。
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/MessageDaemon.php';

class ReincarnateHandler {
    
    // Session 键名前缀
    private const SESSION_PREFIX = 'reincarnate_';

    /** @var array|null 配置缓存 */
    private static ?array $reincarnateConfig = null;

    /**
     * 加载配置（从 config/reincarnate.php）
     */
    private static function loadConfig(): array {
        if (self::$reincarnateConfig === null) {
            $configFile = __DIR__ . '/../config/reincarnate.php';
            self::$reincarnateConfig = file_exists($configFile) ? require $configFile : [];
        }
        return self::$reincarnateConfig;
    }

    /**
     * 获取消息间隔（秒）
     */
    private static function getMessageInterval(): int {
        return self::loadConfig()['message_interval'] ?? 2;
    }

    /**
     * 获取消息列表
     */
    private static function getMessages(): array {
        return self::loadConfig()['messages'] ?? [
            '崔判官从怀中拿出一个黑底白字的册子翻看着。。。',
            '崔判官合上册子，说道：命不该死，多留无益，我这便送你还阳去吧！',
            '崔判官伸手向你一指，你的魂魄又回到了自己身上。。。',
            '一股阴冷的浓雾突然出现，很快地包围了你。',
        ];
    }
    
    /**
     * 启动还阳剧情
     * 
     * @param int $charId 角色ID
     * @param string $roomId 当前房间ID
     * @return array 响应结果
     */
    public static function start(int $charId, string $roomId): array {
        $char = \CharacterModel::find($charId);
        
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在。'];
        }
        
        // 检查是否已经在还阳剧情中
        if (!empty($_SESSION[self::SESSION_PREFIX . 'active'])) {
            return ['success' => false, 'message' => '你正在还阳过程中，请稍候。。。'];
        }
        
        // 检查阳寿：允许鬼魂(kee<=1) 或 刚穿墙过阴的普通玩家(hell_enter_time>0)
        $isGhost = intval($char['kee'] ?? 0) <= 1;
        $justPassedWall = intval($char['hell_enter_time'] ?? 0) > 0;
        if (!($isGhost || $justPassedWall)) {
            return ['success' => false, 'message' => '崔判官摇摇头说："你阳寿未尽，不能还魂。"'];
        }
        
        // 初始化剧情状态
        $_SESSION[self::SESSION_PREFIX . 'active'] = true;
        $_SESSION[self::SESSION_PREFIX . 'current_step'] = 0;
        $_SESSION[self::SESSION_PREFIX . 'room_id'] = $roomId;
        $_SESSION[self::SESSION_PREFIX . 'char_id'] = $charId;
        $_SESSION[self::SESSION_PREFIX . 'start_time'] = time();
        $_SESSION[self::SESSION_PREFIX . 'last_message_time'] = 0;
        
        // 立即发送第一条消息
        self::sendNextMessage($charId, $roomId);
        
        return [
            'success' => true,
            'message' => '崔判官抬起头看了你一眼。。。',
            'reincarnate_in_progress' => true
        ];
    }
    
    /**
     * 检查并推进还阳剧情
     * 在每次消息轮询时调用
     * 
     * @param int $charId 角色ID
     * @return void
     */
    public static function checkAndProgress(int $charId): void {
        // 检查是否有进行中的还阳剧情
        if (empty($_SESSION[self::SESSION_PREFIX . 'active'])) {
            return;
        }
        
        // 检查是否是当前角色的剧情
        if (intval($_SESSION[self::SESSION_PREFIX . 'char_id'] ?? 0) !== $charId) {
            return;
        }
        
        $currentStep = intval($_SESSION[self::SESSION_PREFIX . 'current_step'] ?? 0);
        $lastMessageTime = intval($_SESSION[self::SESSION_PREFIX . 'last_message_time'] ?? 0);
        $roomId = $_SESSION[self::SESSION_PREFIX . 'room_id'] ?? '';
        
        // 检查是否到了发送下一条消息的时间
        $now = time();
        if ($now - $lastMessageTime < self::getMessageInterval()) {
            return;
        }
        
        // 发送下一条消息
        self::sendNextMessage($charId, $roomId);
    }
    
    /**
     * 发送下一条剧情消息
     * 
     * @param int $charId 角色ID
     * @param string $roomId 房间ID
     * @return void
     */
    private static function sendNextMessage(int $charId, string $roomId): void {
        $currentStep = intval($_SESSION[self::SESSION_PREFIX . 'current_step'] ?? 0);
        $totalMessages = count(self::getMessages());
        
        if ($currentStep >= $totalMessages) {
            // 所有消息都已发送，执行传送
            self::finishReincarnate($charId);
            return;
        }
        
        // 发送当前消息
        $message = HTML_HIYEL . self::getMessages()[$currentStep] . HTML_NOR;
        MessageDaemon::broadcastToRoom($roomId, $message, 0, 'room');
        
        // 更新状态
        $_SESSION[self::SESSION_PREFIX . 'current_step'] = $currentStep + 1;
        $_SESSION[self::SESSION_PREFIX . 'last_message_time'] = time();
        
        // 如果是最后一条消息，设置传送标记
        if ($currentStep + 1 >= $totalMessages) {
            $_SESSION[self::SESSION_PREFIX . 'ready_to_transport'] = true;
            // 最后一条消息后，再等一个间隔就传送
            $_SESSION[self::SESSION_PREFIX . 'transport_time'] = time() + self::getMessageInterval();
        }
    }
    
    /**
     * 完成还阳，传送到荒郊小店
     * 
     * @param int $charId 角色ID
     * @return void
     */
    private static function finishReincarnate(int $charId): void {
        // 检查是否到了传送时间
        $transportTime = intval($_SESSION[self::SESSION_PREFIX . 'transport_time'] ?? 0);
        if ($transportTime > 0 && time() < $transportTime) {
            return;
        }
        
        $char = \CharacterModel::find($charId);
        if (!$char) {
            self::clearState();
            return;
        }
        
        // 恢复气血精神
        Database::execute(
            'UPDATE characters SET kee = max_kee, gin = max_gin, sen = max_sen, near_death_time = 0 WHERE id = ?',
            [$charId]
        );
        
        // 传送到荒郊小店
        $targetArea = 'ourhome';
        $targetRoom = 'ourhome/kedian';
        
        Database::execute(
            'UPDATE characters SET current_area = ?, current_room = ? WHERE id = ?',
            [$targetArea, $targetRoom, $charId]
        );
        
        // 在目标房间广播还阳成功消息
        $broadcastMessage = HTML_HIYEL . $char['name'] . '在崔判官的帮助下还魂成功，回到了阳间。' . HTML_NOR;
        MessageDaemon::broadcastToRoom($targetRoom, $broadcastMessage, intval($charId));
        
        // 给玩家自己也发一条消息
        $selfMessage = HTML_HIYEL . '你只觉得眼前一亮，又回到了阳间。' . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $selfMessage, 'room');
        
        // 清除所有剧情状态
        // 注意：前端会通过检测 current_room 的变化自动跳转，不需要特殊的 redirect 标记
        self::clearState();
    }
    
    /**
     * 清除所有还阳剧情状态
     * 
     * @return void
     */
    private static function clearState(): void {
        $keys = [
            'active',
            'current_step',
            'room_id',
            'char_id',
            'start_time',
            'last_message_time',
            'ready_to_transport',
            'transport_time',
            'need_redirect',
            'redirect_url',
        ];
        
        foreach ($keys as $key) {
            unset($_SESSION[self::SESSION_PREFIX . $key]);
        }
    }
    
    /**
     * 执行还魂核心逻辑（CLI/HTTP 通用，无 session 依赖）
     *
     * 统一还魂入口，同时供真人玩家（ReincarnateHandler::start/finishReincarnate）
     * 和 AI 玩家（AiPlayerHelper::handleGhostFlow）使用。
     *
     * 检查条件与 action.php?action=ask&npc_id=167&topic=life 一致：
     *   - kee <= 1（鬼魂状态）
     *   - 或 hell_enter_time > 0（刚穿墙过阴）
     *
     * @param int $charId 角色ID
     * @return array ['success' => bool, 'message' => string, 'action' => string]
     */
    public static function executeReincarnate(int $charId): array {
        $char = \CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在。'];
        }

        // 检查阳寿：允许鬼魂(kee<=1) 或 刚穿墙过阴的普通玩家(hell_enter_time>0)
        $isGhost = intval($char['kee'] ?? 0) <= 1;
        $justPassedWall = intval($char['hell_enter_time'] ?? 0) > 0;
        if (!($isGhost || $justPassedWall)) {
            return ['success' => false, 'message' => '崔判官摇摇头说："你阳寿未尽，不能还魂。"'];
        }

        $targetArea = 'ourhome';
        $targetRoom = 'ourhome/kedian';

        // 恢复气血精神 + 清除鬼魂状态 + 传送
        Database::execute(
            'UPDATE characters SET kee = max_kee, gin = max_gin, sen = max_sen, ' .
            'near_death_time = 0, is_ghost = 0, hell_enter_time = 0, ' .
            'current_area = ?, current_room = ? WHERE id = ?',
            [$targetArea, $targetRoom, $charId]
        );

        // 崔判官剧情消息（直接插入消息队列）
        foreach (self::getMessages() as $msg) {
            MessageDaemon::queueMessageToSelf($charId, HTML_HIYEL . $msg . HTML_NOR, 'room');
        }
        // 传送成功消息
        MessageDaemon::queueMessageToSelf(
            $charId,
            HTML_HIYEL . '你只觉得眼前一亮，又回到了阳间。' . HTML_NOR,
            'room'
        );

        // 广播还魂成功消息到目标房间
        MessageDaemon::broadcastToRoom(
            $targetRoom,
            HTML_HIYEL . $char['name'] . '在崔判官的帮助下还魂成功，回到了阳间。' . HTML_NOR,
            intval($charId)
        );

        return [
            'success' => true,
            'message' => '崔判官伸手一指，你只觉得眼前一亮，又回到了阳间。',
            'action' => 'reincarnate',
            'ai_detail' => '统一还魂: 传送至 ourhome/kedian'
        ];
    }

    /**
     * AI 自动还魂（直接复活鬼魂玩家并传送到 ourhome/kedian）
     *
     * @param int $charId 角色ID
     * @return array
     */
    public static function autoReincarnate(int $charId): array {
        $char = \CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }

        if (intval($char['is_ghost'] ?? 0) !== 1) {
            return ['success' => false, 'message' => '角色不是鬼魂状态'];
        }

        $targetArea = 'ourhome';
        $targetRoom = 'ourhome/kedian';

        Database::execute(
            'UPDATE characters SET kee = max_kee, gin = max_gin, sen = max_sen, near_death_time = 0, is_ghost = 0, hell_enter_time = 0, current_area = ?, current_room = ? WHERE id = ?',
            [$targetArea, $targetRoom, $charId]
        );

        $broadcastMessage = HTML_HIYEL . $char['name'] . '在崔判官的帮助下还魂成功，回到了阳间。' . HTML_NOR;
        MessageDaemon::broadcastToRoom($targetRoom, $broadcastMessage, intval($charId));

        $selfMessage = HTML_HIYEL . '你只觉得眼前一亮，又回到了阳间。' . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $selfMessage, 'room');

        self::clearState();

        return [
            'success' => true,
            'message' => 'AI自动还魂完成',
            'action' => 'reincarnate',
            'ai_detail' => 'AI从鬼门关直接还阳到 ourhome/kedian'
        ];
    }

    /**
     * AI 在崔判官处还魂（穿墙后走到阎罗宝殿触发）
     * 不依赖 session，直接播放剧情消息并传送到 ourhome/kedian
     *
     * @param int $charId 角色ID
     * @return array
     */
    public static function aiReincarnateAtJudge(int $charId): array {
        // 使用统一的 executeReincarnate
        $result = self::executeReincarnate($charId);
        if ($result['success']) {
            $result['action'] = 'reincarnate_judge';
        }
        return $result;
    }
    
    /**
     * 检查剧情是否正在进行中
     * 
     * @param int $charId 角色ID
     * @return bool
     */
    public static function isInProgress(int $charId): bool {
        return !empty($_SESSION[self::SESSION_PREFIX . 'active']) &&
               intval($_SESSION[self::SESSION_PREFIX . 'char_id'] ?? 0) === $charId;
    }
}
