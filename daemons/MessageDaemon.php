<?php
/**
 * 消息广播守护进程
 * 负责在玩家之间广播消息
 * 
 * 注意：本系统已重构为使用 HTML 格式，不再使用 ANSI 颜色代码
 */

class MessageDaemon {
    
    // HTML 颜色常量（替代 ANSI 颜色代码）
    const COLOR_RED    = '<span style="color:#FF0000;font-weight:bold">';      // 红色（HIRED）
    const COLOR_GREEN  = '<span style="color:#00FF00;font-weight:bold">';    // 绿色（HIGRP）
    const COLOR_YELLOW = '<span style="color:#FFFF00;font-weight:bold">';    // 黄色（HIYEL）
    const COLOR_BLUE   = '<span style="color:#0000FF;font-weight:bold">';    // 蓝色（HIBLU）
    const COLOR_MAGENTA= '<span style="color:#FF00FF;font-weight:bold">';    // 洋红（HIMAG）
    const COLOR_CYAN   = '<span style="color:#00FFFF;font-weight:bold">';    // 青色（HICYN）
    const COLOR_WHITE  = '<span style="color:#FFFFFF;font-weight:bold">';    // 白色（HIWHT）
    const COLOR_RESET  = '</span>';                                           // 重置颜色
    
    // 非粗体颜色
    const COLOR_DIM_RED    = '<span style="color:#FF0000">';
    const COLOR_DIM_GREEN  = '<span style="color:#00FF00">';
    const COLOR_DIM_YELLOW = '<span style="color:#FFFF00">';
    const COLOR_DIM_BLUE   = '<span style="color:#0000FF">';
    const COLOR_DIM_WHITE  = '<span style="color:#CCCCCC">';
    
    /**
     * 生成带颜色的消息（HTML 格式）
     * 
     * @param string $message 消息内容
     * @param string $color 颜色类型：red, green, yellow, blue, cyan, magenta, white
     * @param bool $bold 是否粗体
     * @return string 带颜色的 HTML 消息
     */
    public static function color(string $message, string $color = '', bool $bold = true): string {
        if (empty($color)) {
            return $message;
        }
        
        $colorMap = [
            'red'    => '#FF0000',
            'green'  => '#00FF00',
            'yellow' => '#FFFF00',
            'blue'   => '#0000FF',
            'magenta'=> '#FF00FF',
            'cyan'   => '#00FFFF',
            'white'  => '#FFFFFF',
            'hi_red' => '#FF0000',
            'hi_grp' => '#00FF00',
            'hi_yel' => '#FFFF00',
            'hi_blu' => '#0000FF',
            'hi_mag' => '#FF00FF',
            'hi_cyn' => '#00FFFF',
            'hi_wht' => '#FFFFFF',
        ];
        
        $colorCode = $colorMap[$color] ?? '#FFFFFF';
        $style = "color:{$colorCode}";
        if ($bold) {
            $style .= ";font-weight:bold";
        }
        
        return "<span style='{$style}'>{$message}</span>";
    }
    
    /**
     * 生成高亮消息（默认黄色粗体）
     */
    public static function highlight(string $message): string {
        return self::COLOR_YELLOW . $message . self::COLOR_RESET;
    }
    
    /**
     * 生成错误消息（默认红色）
     */
    public static function error(string $message): string {
        return self::COLOR_RED . $message . self::COLOR_RESET;
    }
    
    /**
     * 生成成功消息（默认绿色）
     */
    public static function success(string $message): string {
        return self::COLOR_GREEN . $message . self::COLOR_RESET;
    }
    
    /**
     * 广播消息给房间内的所有玩家
     * 
     * @param string $roomId 房间ID
     * @param string $message 消息内容（HTML 格式）
     * @param int $excludeCharId 排除的角色ID（发送者自己）
     * @param mixed $msgTypeOrEnv 消息类型字符串，或是否添加环境描述的布尔值
     * @return array 接收消息的玩家列表
     */
    public static function broadcastToRoom(string $roomId, string $message, int $excludeCharId = 0, $msgTypeOrEnv = false): array {
        // 兼容旧代码：如果第4个参数是布尔值，则作为$addEnvironment
        $addEnvironment = is_bool($msgTypeOrEnv) ? $msgTypeOrEnv : false;
        $msgType = is_string($msgTypeOrEnv) ? $msgTypeOrEnv : 'room';
        // 如果需要添加环境描述
        if ($addEnvironment) {
            // 获取房间信息以判断是否是室外
            $roomInfo = Database::queryOne('SELECT outdoors FROM rooms WHERE room_id = ?', [$roomId]);
            $isOutdoors = $roomInfo && intval($roomInfo['outdoors']) > 0;
            
            if ($isOutdoors) {
                require_once __DIR__ . '/NatureDaemon.php';
                $envDesc = NatureDaemon::getEnvironmentDescription(true);
                if (!empty($envDesc)) {
                    $message = "[窗外]：{$envDesc}\n" . $message;
                }
            }
        }
        
        // 获取房间内所有在线玩家
        if ($excludeCharId > 0) {
            // 排除指定玩家
            $sql = "SELECT id, name FROM characters 
                    WHERE current_room = ? AND online = 1 AND id != ?";
            $players = Database::queryAll($sql, [$roomId, $excludeCharId]);
        } else {
            // 不排除任何玩家
            $sql = "SELECT id, name FROM characters 
                    WHERE current_room = ? AND online = 1";
            $players = Database::queryAll($sql, [$roomId]);
        }
        
        if (empty($players)) {
            return [];
        }
        
        // 将消息加入每个玩家的队列，并记录发送者ID（用于过滤自己的消息）
        foreach ($players as $player) {
            self::queueMessage($player['id'], $message, $msgType, $excludeCharId);
        }
        
        return $players;
    }
    
    /**
     * 向房间内所有玩家广播消息（排除发送者自己）
     * 
     * @param int $charId 发送者角色ID
     * @param string $message 消息内容
     * @param string $msgType 消息类型
     * @return bool 是否成功
     */
    public static function sendRoomMessage(int $charId, string $message, string $msgType = 'room'): bool {
        // 获取发送者所在的房间
        $roomInfo = Database::queryOne(
            "SELECT current_room FROM characters WHERE id = ?",
            [$charId]
        );
        
        if (!$roomInfo || empty($roomInfo['current_room'])) {
            return false;
        }
        
        // 广播消息给房间内所有玩家（排除发送者自己）
        self::broadcastToRoom($roomInfo['current_room'], $message, $charId, $msgType);
        return true;
    }
    
    /**
     * 广播消息给所有在线玩家（包括发送者）
     * 
     * @param string $message 消息内容
     * @param int $fromCharId 发送者角色ID（仅用于日志，不排除）
     * @param string $msgType 消息类型，默认'system'
     * @return array 接收消息的玩家列表
     */
    public static function broadcastToAll(string $message, int $fromCharId = 0, string $msgType = 'system'): array {
        // 获取所有在线玩家（包括发送者）
        $sql = "SELECT id, name FROM characters 
                WHERE online = 1";
        
        $players = Database::queryAll($sql, []);
        
        if (empty($players)) {
            return [];
        }
        
        // 将消息加入每个玩家的队列
        foreach ($players as $player) {
            self::queueMessage($player['id'], $message, $msgType);
        }
        
        return $players;
    }
    
    /**
     * 私聊消息
     * 
     * @param int $targetCharId 目标角色ID
     * @param string $message 消息内容
     * @param int $fromCharId 发送者角色ID
     * @return bool 是否成功
     */
    public static function sendPrivateMessage(int $targetCharId, string $message, int $fromCharId): bool {
        // 检查目标玩家是否在线
        $sql = "SELECT id, name FROM characters WHERE id = ? AND online = 1";
        $target = Database::queryOne($sql, [$targetCharId]);
        
        if (!$target) {
            return false;
        }
        
        // 记录消息
        self::queueMessage($targetCharId, $message, 'private', $fromCharId);
        
        return true;
    }
    
    /**
     * 将消息加入玩家的消息队列
     * 
     * @param int $charId 角色ID
     * @param string $message 消息内容（HTML 格式）
     * @param string $type 消息类型: room, global, private
     * @param int $fromCharId 发送者ID（可选）
     * @return int 新插入的消息ID，失败返回0
     */
    private static function queueMessage(int $charId, string $message, string $type = 'room', int $fromCharId = 0): int {
        try {
            // 保存消息到数据库队列（is_html = 1 表示 HTML 格式）
            Database::execute(
                'INSERT INTO message_queue (char_id, message, type, from_char_id, created_at, is_html) 
                 VALUES (?, ?, ?, ?, NOW(), 1)',
                [$charId, $message, $type, $fromCharId > 0 ? $fromCharId : null]
            );
            return intval(Database::lastInsertId());
        } catch (Exception $e) {
            error_log("消息队列保存失败: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * 将消息加入Session（备用方案）
     */
    private static function queueMessageToSession(int $charId, string $message, string $type = 'room', int $fromCharId = 0): void {
        // 临时消息模式：不再持久化到数据库
        return;
    }
    
    /**
     * 保存消息到数据库（历史记录）
     * 
     * @param int $fromCharId 发送者ID
     * @param int|null $toCharId 接收者ID（NULL表示广播）
     * @param string $message 消息内容
     * @param string $type 消息类型
     * @param string $channel 频道
     * @param string $roomId 房间ID（可选）
     */
    public static function saveMessageHistory(int $fromCharId, ?int $toCharId, string $message, string $type, string $channel = 'room', string $roomId = ''): void {
        // 临时消息模式：不再持久化到数据库
        return;
    }
    
    /**
     * 保存系统消息（不需要 from_char_id）
     * 
     * @param string $message 消息内容
     * @param string $channel 频道（system/rumor等）
     * @param string $roomId 房间ID（可选，空表示全局）
     */
    public static function saveSystemMessage(string $message, string $channel = 'system', string $roomId = ''): void {
        self::saveMessageHistory(0, null, $message, 'system', $channel, $roomId);
    }
    
    /**
     * 获取聊天历史消息（分页）
     * 
     * @param int $charId 角色ID
     * @param int $page 页码（从1开始）
     * @param int $perPage 每页数量
     * @param string $channel 频道过滤（可选）
     * @return array ['messages' => [...], 'total' => int, 'page' => int, 'pages' => int]
     */
    public static function getChatHistory(int $charId, int $page = 1, int $perPage = 15, string $channel = ''): array {
        // 临时消息模式：不再持久化到数据库
        return [
            'messages' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => 0
        ];
    }
    
    /**
     * 向指定玩家发送消息（公开接口，供NPC系统等使用）
     * 
     * @param int $charId 角色ID
     * @param string $message 消息内容
     * @param string $type 消息类型，默认 'npc_chat'
     */
    public static function sendToPlayer(int $charId, string $message, string $type = 'npc_chat'): void {
        self::queueMessage($charId, $message, $type);
    }

    /**
     * 将消息队列给玩家自己（用于 chat.php 显示自己的动作结果）
     * 
     * @param int $charId 角色ID
     * @param string $message 消息内容
     * @param string $type 消息类型，默认 'self_event'
     * @return int 新插入的消息ID，失败返回0
     */
    public static function queueMessageToSelf(int $charId, string $message, string $type = 'self_event'): int {
        return self::queueMessage($charId, $message, $type);
    }
    
    /**
     * 获取玩家的所有未读消息
     * 
     * @param int $charId 角色ID
     * @return array 消息列表
     */
    public static function getPendingMessages(int $charId): array {
        try {
            // 从数据库获取未读消息
            $messages = Database::queryAll(
                'SELECT id, message, type, from_char_id, created_at 
                 FROM message_queue 
                 WHERE char_id = ? 
                 ORDER BY created_at ASC',
                [$charId]
            );
            
            // 删除已读取的消息
            if (!empty($messages)) {
                Database::execute(
                    'DELETE FROM message_queue WHERE char_id = ?',
                    [$charId]
                );
            }
            
            return $messages;
        } catch (Exception $e) {
            error_log("获取消息队列失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 更新玩家在线状态
     * 
     * @param int $charId 角色ID
     * @param bool $online 是否在线
     */
    public static function updateOnlineStatus(int $charId, bool $online): void {
        $sql = "UPDATE characters SET online = ?, last_save = NOW() WHERE id = ?";
        Database::execute($sql, [$online ? 1 : 0, $charId]);
    }
    
    /**
     * 获取房间内在线玩家数量
     * 
     * @param string $roomId 房间ID
     * @return int 玩家数量
     */
    public static function getRoomPlayerCount(string $roomId): int {
        $sql = "SELECT COUNT(*) as count FROM characters 
                WHERE current_room = ? AND online = 1";
        
        $result = Database::queryOne($sql, [$roomId]);
        return $result['count'] ?? 0;
    }
    
    /**
     * 获取房间内所有在线玩家信息
     * 
     * @param string $roomId 房间ID
     * @return array 玩家列表
     */
    public static function getRoomPlayers(string $roomId): array {
        $sql = "SELECT id, name, level FROM characters 
                WHERE current_room = ? AND online = 1";
        
        return Database::queryAll($sql, [$roomId]);
    }
    
    /**
     * 清理旧的已读消息（定期调用）
     * 
     * @param int $days 保留天数，默认7天
     * @return int 删除的消息数量
     */
    public static function cleanOldMessages(int $days = 7): int {
        try {
            // 简化逻辑：只清理超过指定天数的旧消息，不再区分玩家在线状态
            // 这样更安全，避免误删在线玩家的消息
            $sql = 'DELETE FROM message_queue WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)';
            $deleted = Database::execute($sql, [$days]);
            
            if ($deleted > 0) {
                log_game('CLEAN', "清理了 {$deleted} 条 {$days} 天前的旧消息");
            }
            return $deleted;
        } catch (Exception $e) {
            error_log("清理旧消息失败: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * 限制消息队列总数（自动删除最旧的未读消息）
     * 
     * @param int $maxCount 最大消息数量，默认300
     * @return int 删除的消息数量
     */
    public static function limitMessageQueue(int $maxCount = 300): int {
        try {
            // 只清理超过7天的旧消息，避免误删玩家需要的消息
            return Database::execute(
                'DELETE FROM message_queue WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
            );
        } catch (Exception $e) {
            error_log("清理消息队列失败: " . $e->getMessage());
            return 0;
        }
    }
}

