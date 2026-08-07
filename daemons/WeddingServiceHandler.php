<?php
/**
 * 婚礼服务处理器
 * 
 * 处理轿夫头的婚礼服务逻辑
 * 包括：雇佣花轿、轿夫、吹鼓手
 * 
 * 注意：由于 xyj 项目不支持 call_out 定时任务，
 *      NPC 跟随功能需由前端页面刷新或玩家手动触发
 */

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/../helpers/MoneyHelper.php';

class WeddingServiceHandler extends ActionHandler {
    
    /**
     * 默认配置
     */
    public function getDefaultConfig(): array {
        return [
            'basic_service_price' => 10000,   // 基础服务价格（花轿+轿夫）
            'premium_service_price' => 20000,  // 高级服务价格（+吹鼓手）
        ];
    }

    /**
     * 获取配置（带缓存）
     */
    private function getWeddingConfig(array $action): array {
        static $cache = null;
        if ($cache === null) {
            $cache = $this->mergeConfig($this->getDefaultConfig(), $this->parseConfig($action));
        }
        return $cache;
    }

    /**
     * 实现父类抽象方法（婚礼服务通过专用方法调用，此方法作为通用入口）
     */
    public function execute(int $charId, array $action, array $params = []): array {
        $cmd = $action['action_cmd'] ?? '';
        switch ($cmd) {
            case 'wedding_payment':
                return $this->handlePayment($charId, $params['npc_id'] ?? 0, $params['amount'] ?? 0, $action);
            case 'enter_palanquin':
                return $this->brideEnterPalanquin($charId, $params['npc_id'] ?? 0);
            case 'arrive_destination':
                return $this->arriveDestination($charId, $params['npc_id'] ?? 0);
            default:
                return ['success' => false, 'message' => '未知的婚礼动作。'];
        }
    }
    
    /**
     * 获取NPC信息
     */
    protected function getNpc(int $npcId): ?array {
        require_once __DIR__ . '/../models/Npc.php';
        return NpcModel::find($npcId);
    }
    
    /**
     * 处理给予金钱（雇佣婚礼服务）
     */
    public function handlePayment(int $charId, int $npcId, int $amount, array $action = []): array {
        $cfg = $this->getWeddingConfig($action);
        $char = $this->getCharacter($charId);
        $npc = $this->getNpc($npcId);
        
        if (!$char || !$npc) {
            return ['success' => false, 'message' => '角色或NPC不存在'];
        }
        
        // 检查是否是轿夫头
        if ($npc['npc_id'] !== 'jftou' && $npc['npc_id'] !== 'jiaofu tou') {
            return ['success' => false, 'message' => '该NPC不提供婚礼服务'];
        }
        
        // 检查是否已经有婚礼在进行中
        if (!empty($npc['in_job'])) {
            return ['success' => false, 'message' => '轿夫头说：十分抱歉，我现在刚好有桩生意，请您待会儿再来好吗？'];
        }
        
        // 检查玩家是否有结婚状态
        $marrying = Database::queryOne("SELECT * FROM character_temp WHERE char_id = ? AND temp_key = 'marrying'", [$charId]);
        $brideId = $marrying ? $marrying['temp_value'] : null;
        
        if (!$brideId) {
            return ['success' => false, 'message' => '轿夫头说：哎呀，您老太客气了，没事儿就打赏，等您成亲的时候，小的一定尽心服务。'];
        }
        
        // 检查新娘是否在场
        $bride = Database::queryOne("SELECT * FROM characters WHERE id = ? AND current_room = ?", [$brideId, $char['current_room']]);
        if (!$bride) {
            return ['success' => false, 'message' => '轿夫头说：哎呀，新娘子不在这儿，没法办啊。'];
        }
        
        // 处理不同金额的雇佣
        $basicPrice = $cfg['basic_service_price'];
        $premiumPrice = $cfg['premium_service_price'];
        if ($amount === $basicPrice) {
            return $this->hireBasicService($charId, $npcId, $brideId);
        } elseif ($amount === $premiumPrice) {
            return $this->hirePremiumService($charId, $npcId, $brideId);
        } else {
            return ['success' => false, 'message' => '轿夫头说：哎呀，您给的银子不对，花轿加轿夫一百两，加吹鼓手两百两。'];
        }
    }
    
    /**
     * 雇佣基础服务（花轿 + 轿夫）
     */
    private function hireBasicService(int $charId, int $npcId, int $brideId): array {
        $char = $this->getCharacter($charId);
        $npc = $this->getNpc($npcId);
        
        // 检查轿夫是否存在
        $jiaofu = Database::queryOne("SELECT * FROM npcs WHERE npc_id = 'jiaofu' AND spawn_room = ?", [$npc['spawn_room']]);
        if (!$jiaofu) {
            return ['success' => false, 'message' => '轿夫头说：哎哟，我这儿人手不齐，没法儿给您办了，太对不起了，您老多包含。'];
        }
        
        // 金钱扣除已在ActionRouter中通过MoneyHelper::deductMoney完成
        // 这里不再扣除，只设置NPC状态
        
        // 设置NPC状态
        $this->setNpcJobStatus($npcId, $charId, $brideId);
        
        // 设置轿夫头跟随新郎（这样go.php中才能查询到轿夫头跟随玩家）
        $this->setFollower($npcId, $charId);
        
        // 设置轿夫跟随轿夫头
        $this->setFollower($jiaofu['id'], $npcId);
        
        // 广播消息给房间内的其他玩家
        require_once DAEMON_PATH . 'MessageDaemon.php';
        $roomMsg = "轿夫头说：好的好的，多谢您老的银子，花轿这就备好，请新娘子上轿。\n";
        $roomMsg .= "轿夫头说：您老放心，吹鼓手随队跟着吹打，保证您一路风光。\n";
        $roomMsg .= "轿夫头把轿帘撩了起来，新娘可以进去了。";
        
        // 设置 flash message 让 room.php 也能显示
        $_SESSION['flash_message'] = [
            'content' => HTML_HIYEL . $roomMsg . HTML_NOR,
            'timestamp' => time()
        ];
        
        // 广播给房间内所有人（不排除任何人）
        MessageDaemon::broadcastToRoom($char['current_room'], HTML_HIYEL . $roomMsg . HTML_NOR, 0);
        
        return [
            'success' => true,
            'message' => "轿夫头说：好的好的，多谢您老的银子，花轿这就备好，请新娘子上轿。\n轿夫头把轿帘撩了起来，新娘可以进去了。"
        ];
    }
    
    /**
     * 雇佣高级服务（花轿 + 轿夫 + 吹鼓手）
     */
    private function hirePremiumService(int $charId, int $npcId, int $brideId): array {
        $char = $this->getCharacter($charId);
        $npc = $this->getNpc($npcId);
        
        // 检查所有人员是否存在
        $jiaofu = Database::queryOne("SELECT * FROM npcs WHERE npc_id = 'jiaofu' AND spawn_room = ?", [$npc['spawn_room']]);
        $lgshou = Database::queryOne("SELECT * FROM npcs WHERE npc_id = 'lgshou' AND spawn_room = ?", [$npc['spawn_room']]);
        $snshou = Database::queryOne("SELECT * FROM npcs WHERE npc_id = 'snshou' AND spawn_room = ?", [$npc['spawn_room']]);
        
        if (!$jiaofu || !$lgshou || !$snshou) {
            return ['success' => false, 'message' => '轿夫头说：哎哟，我这儿人手不齐，没法儿给您办了，太对不起了，您老多包含。'];
        }
        
        // 金钱扣除已在ActionRouter中通过MoneyHelper::deductMoney完成
        // 这里不再扣除，只设置NPC状态
        
        // 设置NPC状态
        $this->setNpcJobStatus($npcId, $charId, $brideId);
        
        // 设置轿夫头跟随新郎（这样go.php中才能查询到轿夫头跟随玩家）
        $this->setFollower($npcId, $charId);
        
        // 设置跟随关系：轿夫跟随轿夫头，吹鼓手跟随轿夫
        $this->setFollower($jiaofu['id'], $npcId);
        $this->setFollower($lgshou['id'], $jiaofu['id']);
        $this->setFollower($snshou['id'], $jiaofu['id']);
        
        // 广播消息给房间内的其他玩家
        require_once DAEMON_PATH . 'MessageDaemon.php';
        $roomMsg = "轿夫头说：好的好的，多谢您老的银子，花轿这就备好，请新娘子上轿。\n";
        $roomMsg .= "轿夫头说：您老放心，吹鼓手随队跟着吹打，保证您一路风光。\n";
        $roomMsg .= "唢呐手大声地吹起了唢呐，锣鼓手用力地敲锣打鼓，好不热闹。";
        
        // 设置 flash message 让 room.php 也能显示
        $_SESSION['flash_message'] = [
            'content' => HTML_HIYEL . $roomMsg . HTML_NOR,
            'timestamp' => time()
        ];
        
        // 广播给房间内所有人（不排除任何人）
        MessageDaemon::broadcastToRoom($char['current_room'], HTML_HIYEL . $roomMsg . HTML_NOR, 0);
        
        return [
            'success' => true,
            'message' => "轿夫头说：好的好的，多谢您老的银子，花轿这就备好，请新娘子上轿。\n轿夫头说：您老放心，吹鼓手随队跟着吹打，保证您一路风光。\n唢呐手大声地吹起了唢呐，锣鼓手用力地敲锣打鼓，好不热闹。"
        ];
    }
    
    /**
     * 设置NPC工作状态
     */
    private function setNpcJobStatus(int $npcId, int $groomId, int $brideId): void {
        // 标记NPC正在工作中
        Database::execute(
            "INSERT INTO npc_temp (npc_id, temp_key, temp_value) VALUES (?, 'in_job', '1') ON DUPLICATE KEY UPDATE temp_value = '1'",
            [$npcId]
        );
        
        // 记录新郎ID
        Database::execute(
            "INSERT INTO npc_temp (npc_id, temp_key, temp_value) VALUES (?, 'groom', ?) ON DUPLICATE KEY UPDATE temp_value = ?",
            [$npcId, $groomId, $groomId]
        );
        
        // 记录新娘ID
        Database::execute(
            "INSERT INTO npc_temp (npc_id, temp_key, temp_value) VALUES (?, 'bride', ?) ON DUPLICATE KEY UPDATE temp_value = ?",
            [$npcId, $brideId, $brideId]
        );
    }
    
    /**
     * 设置跟随关系
     * 
     * 设置 NPC 跟随另一个 NPC 或玩家，并记录当前位置
     */
    private function setFollower(int $followerId, int $leaderId): void {
        // 获取跟随者信息
        $follower = $this->getNpc($followerId);
        
        // 设置 leader 状态
        Database::execute(
            "INSERT INTO npc_temp (npc_id, temp_key, temp_value) VALUES (?, 'leader', ?) ON DUPLICATE KEY UPDATE temp_value = ?",
            [$followerId, $leaderId, $leaderId]
        );

        // 设置不返回状态
        Database::execute(
            "INSERT INTO npc_temp (npc_id, temp_key, temp_value) VALUES (?, 'no_return', '1') ON DUPLICATE KEY UPDATE temp_value = '1'",
            [$followerId]
        );
        
        // 记录当前位置（area 和 room 存储为 JSON）
        // 优先获取leader（可能是玩家或NPC）的当前位置
        $currentArea = '';
        $currentRoom = '';
        
        // 先尝试获取玩家（character）的当前位置
        $leaderChar = Database::queryOne(
            "SELECT current_area, current_room FROM characters WHERE id = ?",
            [$leaderId]
        );
        if ($leaderChar) {
            // leader是玩家
            $currentArea = $leaderChar['current_area'] ?? '';
            $currentRoom = $leaderChar['current_room'] ?? '';
        } else {
            // leader是NPC
            $leaderNpc = $this->getNpc($leaderId);
            if ($leaderNpc) {
                $currentArea = $leaderNpc['spawn_area'] ?? '';
                $currentRoom = $leaderNpc['spawn_room'] ?? '';
            } elseif ($follower) {
                // 如果都不是，使用跟随者自己的出生位置
                $currentArea = $follower['spawn_area'] ?? '';
                $currentRoom = $follower['spawn_room'] ?? '';
            }
        }
        
        if (!empty($currentArea) && !empty($currentRoom)) {
            Database::execute(
                "INSERT INTO npc_temp (npc_id, temp_key, temp_value) VALUES (?, 'current_location', ?) ON DUPLICATE KEY UPDATE temp_value = ?",
                [$followerId, json_encode(['area' => $currentArea, 'room' => $currentRoom]), json_encode(['area' => $currentArea, 'room' => $currentRoom])]
            );
        }
    }
    
    /**
     * 新娘进入花轿
     */
    public function brideEnterPalanquin(int $brideId, int $npcId): array {
        $bride = $this->getCharacter($brideId);
        $npc = $this->getNpc($npcId);
        
        if (!$bride || !$npc) {
            return ['success' => false, 'message' => '角色或NPC不存在'];
        }
        
        // 检查是否是新娘
        $expectedBride = Database::queryOne("SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'bride'", [$npcId]);
        if (!$expectedBride || $expectedBride['temp_value'] != $brideId) {
            return ['success' => false, 'message' => '你不是新娘，不能进入花轿。'];
        }
        
        // 移动新娘到花轿内部（moon/jiaoli房间）
        $jiaoliRoom = Database::queryOne("SELECT room_id, area FROM rooms WHERE room_id = 'moon/jiaoli'");
        if ($jiaoliRoom) {
            Database::execute(
                "UPDATE characters SET current_area = ?, current_room = ? WHERE id = ?",
                [$jiaoliRoom['area'], 'moon/jiaoli', $brideId]
            );
            
            // 向原房间广播消息（使用完整的房间ID）
            $originalRoomId = $bride['current_room'];  // current_room已经是完整路径
            require_once DAEMON_PATH . 'MessageDaemon.php';
            $roomMsg = "{$bride['name']}羞羞答答地坐进了花轿里，轿夫马上把轿帘放了下来。\n";
            $roomMsg .= "轿夫头和另一个轿夫一起，忽悠一下把轿子抬了起来。";
            
            // 设置 flash message 让 room.php 也能显示
            $_SESSION['flash_message'] = [
                'content' => HTML_HIYEL . $roomMsg . HTML_NOR,
                'timestamp' => time()
            ];
            
            // 广播给房间内所有人（不排除任何人）
            MessageDaemon::broadcastToRoom($originalRoomId, HTML_HIYEL . $roomMsg . HTML_NOR, 0);
        }
        
        // 设置花轿在路上状态
        Database::execute(
            "INSERT INTO npc_temp (npc_id, temp_key, temp_value) VALUES (?, 'on_way', '1') ON DUPLICATE KEY UPDATE temp_value = '1'",
            [$npcId]
        );
        
        return [
            'success' => true,
            'message' => "新娘子{$bride['name']}羞羞答答地坐进了花轿里，轿夫马上把轿帘放了下来。\n轿夫头和另一个轿夫一起，忽悠一下把轿子抬了起来。\n你感到轿子被人抬了起来，看来是上路了。",
            'redirect' => 'room.php?area=moon&room=moon/jiaoli'
        ];
    }
    
    /**     * 到达目的地
     */
    public function arriveDestination(int $charId, int $npcId): array {
        $char = $this->getCharacter($charId);
        $npc = $this->getNpc($npcId);

        if (!$char || !$npc) {
            return ['success' => false, 'message' => '新郎官，您找错人了。'];
        }

        // 检查新娘是否已上轿
        $brideId = Database::queryOne(
            "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'bride'",
            [$npcId]
        );
        if (!$brideId) {
            return ['success' => false, 'message' => '轿夫头说：哎呀，新娘子还没上轿呢，没法办啊。'];
        }

        // 检查新郎是否是结婚登记的主人（避免别人冒用）
        $groomId = Database::queryOne(
            "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'groom'",
            [$npcId]
        );
        if (!$groomId || $groomId['temp_value'] != $charId) {
            return ['success' => false, 'message' => '轿夫头说：这可不是您的婚事，别瞎折腾。'];
        }

        // 检查是否已在路上
        $onWay = Database::queryOne(
            "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'on_way'",
            [$npcId]
        );
        if (!$onWay || $onWay['temp_value'] != '1') {
            return ['success' => false, 'message' => '轿夫头说：哎呀，队伍还没出发呢，您急啥？'];
        }

        // 清除临时状态
        Database::execute(
            "DELETE FROM npc_temp WHERE npc_id = ? AND temp_key IN ('on_way', 'groom', 'bride')",
            [$npcId]
        );
        
        // 获取当前房间ID（用于广播）
        $currentRoomId = $char['current_room'];

        // 消息内容
        $roomMsg = "花轿缓缓停下，轿夫头喊道：恭喜新郎官，新娘子已经安全到达目的地！婚礼圆满成功！^_^
";
        
        // 设置 flash message 让 room.php 也能显示
        $_SESSION['flash_message'] = [
            'content' => HTML_HIYEL . $roomMsg . HTML_NOR,
            'timestamp' => time()
        ];
        
        // 通知房间内所有参与者婚礼完成
        require_once DAEMON_PATH . 'MessageDaemon.php';
        // 先广播给房间内所有人（不排除任何人）
        MessageDaemon::broadcastToRoom($currentRoomId, HTML_HIYEL . $roomMsg . HTML_NOR, 0);
        // 再单独给自己发送一条
        MessageDaemon::queueMessageToSelf($charId, HTML_HIYEL . $roomMsg . HTML_NOR, 'room');

        // 更新新娘状态，设置为已婚
        Database::execute(
            "UPDATE characters SET married = 1, married_at = NOW() WHERE id = ?",
            [$brideId['temp_value']]
        );
        
        // 将新娘从花轿中移出到当前房间（和新郎在一起）
        $currentRoom = Database::queryOne(
            "SELECT current_area, current_room FROM characters WHERE id = ?",
            [$charId]
        );
        if ($currentRoom) {
            Database::execute(
                "UPDATE characters SET current_area = ?, current_room = ? WHERE id = ?",
                [$currentRoom['current_area'], $currentRoom['current_room'], $brideId['temp_value']]
            );
        }
        
        // 清理婚礼队伍（清除NPC跟随状态，并让NPC从房间消失）
        $this->cleanupWeddingParty($npcId, $currentRoomId);

        // 通知新郎婚礼成功
        MessageDaemon::queueMessageToSelf($charId, "恭喜你！新娘子已经安全到达目的地，你们的婚礼圆满成功！^_^\n", 'self_event');

        return ['success' => true, 'message' => '婚礼圆满完成！'];
    }
    
    /**
     * 清理婚礼队伍（递归清理所有跟随的NPC）
     */
    private function cleanupWeddingParty(int $npcId, string $currentRoomId = ''): void {
        // 使用队列进行广度优先遍历，清理所有跟随链上的NPC
        $queue = [$npcId];
        $processed = [];
        
        while (!empty($queue)) {
            $currentId = array_shift($queue);
            
            // 避免重复处理
            if (in_array($currentId, $processed)) {
                continue;
            }
            $processed[] = $currentId;
            
            // 清除当前NPC的所有状态
            Database::execute(
                "DELETE FROM npc_temp WHERE npc_id = ? AND temp_key IN ('in_job', 'groom', 'bride', 'on_way', 'leader', 'no_return', 'current_location')",
                [$currentId]
            );
            
            // 查找所有跟随当前NPC的NPC
            $followers = Database::queryAll(
                "SELECT npc_id FROM npc_temp WHERE temp_key = 'leader' AND temp_value = ?",
                [$currentId]
            );
            
            foreach ($followers as $follower) {
                $queue[] = $follower['npc_id'];
            }
        }
    }
}