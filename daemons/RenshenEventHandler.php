<?php
/**
 * 人参果事件处理器 (RenshenEventHandler)
 * 
 * 还原原始LPC项目逻辑:
 * 原始文件: /d/qujing/wuzhuang/renshenguo-yuan.c + /d/qujing/wuzhuang/npc/zhenyuan.c
 * 
 * 事件流程:
 * 1. 玩家使用黄铜钥匙打开西瓜地北门 → 传送到人参果园 → 触发事件
 * 2. 镇元大仙出现，全服广播第一次公告
 * 3. 1分钟后第二次广播
 * 4. 2分钟后第三次广播，开始分发人参果
 * 5. 玩家「问人参果」→ 1-3人在场时每人获得一枚人参果
 * 6. 分发完毕，镇元大仙5秒后消失，事件结束
 * 7. 事件结束后10分钟冷却期
 * 
 * 人参果效果 (原始: /d/obj/drug/renshen-guo.c):
 * - +20 max_force (最大内力)
 * - +20 max_mana (最大法力)
 * - obstacle/wuzhuang = "done"
 * - 累计36颗 → 长生不老 (live_forever)
 * 
 * 简化处理（相比原始LPC）:
 * - 原版邀请循环(inviting)会传送到全服 accept 的玩家，PHP版简化为只限已在果园的玩家
 * - 原版有 clear/back 命令，PHP版用向南出口代替
 * - 原版镇元大仙会主动攻击 live_forever 玩家，PHP版简化为不邀请 live_forever 玩家
 */

class RenshenEventHandler {
    
    const STATE_FILE = __DIR__ . '/../data/renshen_event_state.json';
    
    /** @var array|null 事件配置缓存 */
    private static ?array $eventConfig = null;
    
    /**
     * 加载事件配置
     */
    private static function loadConfig(): array {
        if (self::$eventConfig !== null) {
            return self::$eventConfig;
        }
        self::$eventConfig = require __DIR__ . '/../config/renshen.php';
        return self::$eventConfig;
    }
    
    // 事件阶段时间（秒）—— 从配置读取，常量保留作为 fallback
    const PHASE1_DELAY = 180;
    const PHASE2_DELAY = 180;
    const COOLDOWN     = 600;
    const MAX_RECIPIENTS = 3;
    
    // NPC 信息（事件期间虚拟显示）
    const ZHENYUAN_NAME = '镇元大仙';
    const ZHENYUAN_TITLE = '五庄观观主';
    
    /**
     * 获取事件状态
     */
    public static function getState(): array {
        if (!file_exists(self::STATE_FILE)) {
            return self::getDefaultState();
        }
        $data = json_decode(file_get_contents(self::STATE_FILE), true);
        return $data ?: self::getDefaultState();
    }
    
    /**
     * 保存事件状态
     */
    public static function saveState(array $state): void {
        file_put_contents(self::STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 默认状态
     */
    private static function getDefaultState(): array {
        return [
            'event_active' => false,
            'event_started_at' => 0,
            'trigger_char_id' => null,
            'current_phase' => 'idle', // idle, announcing, phase1, phase2, distributing, finished
            'fruit_distributed' => false,
            'event_ended_at' => 0,
        ];
    }
    
    /**
     * 检查事件是否活跃
     */
    public static function isEventActive(): bool {
        $state = self::getState();
        return $state['event_active'] === true;
    }
    
    /**
     * 获取当前阶段（同时根据时间推进阶段）
     * 返回: announcing / phase1 / phase2 / distributing / finished / idle
     */
    public static function getCurrentPhase(): string {
        $state = self::getState();
        
        if (!$state['event_active']) {
            // 检查是否在冷却期
            $cfg = self::loadConfig();
            if ($state['event_ended_at'] > 0 && time() < $state['event_ended_at'] + $cfg['cooldown']) {
                return 'cooldown';
            }
            return 'idle';
        }
        
        $elapsed = time() - $state['event_started_at'];
        
        // 根据时间推进阶段
        if ($state['fruit_distributed']) {
            return 'finished';
        }
        
        $cfg = self::loadConfig();
        if ($elapsed < $cfg['phase1_delay']) {
            return 'announcing';
        }
        
        $cfg = self::loadConfig();
        if ($elapsed < $cfg['phase1_delay'] + $cfg['phase2_delay']) {
            // 检查是否需要广播第二阶段
            if ($state['current_phase'] === 'announcing') {
                $state['current_phase'] = 'phase1';
                self::saveState($state);
                self::broadcastPhase1();
            }
            return 'phase1';
        }
        
        // 可以分发果实了
        if ($state['current_phase'] !== 'phase2' && $state['current_phase'] !== 'distributing') {
            $state['current_phase'] = 'phase2';
            self::saveState($state);
            self::broadcastPhase2();
        }
        
        return 'phase2';
    }
    
    /**
     * 启动事件（当玩家使用钥匙时调用）
     */
    public static function startEvent(int $triggerCharId): void {
        $state = self::getDefaultState();
        $state['event_active'] = true;
        $state['event_started_at'] = time();
        $state['trigger_char_id'] = $triggerCharId;
        $state['current_phase'] = 'announcing';
        self::saveState($state);
        
        // 全服广播第一次公告
        self::broadcastStart($triggerCharId);
    }
    
    /**
     * 检查并推进事件阶段（在房间加载时调用）
     */
    public static function checkAndAdvance(): void {
        $state = self::getState();
        if (!$state['event_active']) return;
        
        $phase = self::getCurrentPhase();
        
        // 检查 finished 状态：分发完毕后自动结束
        if ($phase === 'finished' && $state['fruit_distributed']) {
            // 延迟几秒后结束（简化为立即结束）
            self::endEvent();
        }
    }
    
    /**
     * 分发人参果
     * @return array ['success' => bool, 'recipients' => [...], 'message' => string]
     */
    public static function distributeFruit(int $requesterCharId): array {
        $state = self::getState();
        if (!$state['event_active']) {
            return ['success' => false, 'message' => ''];
        }
        
        $phase = self::getCurrentPhase();
        
        if ($phase === 'announcing') {
            return [
                'success' => true,
                'message' => HTML_HICYN . '镇元大仙笑道：「人参果可是个好东西啊。」' . HTML_NOR
            ];
        }
        
        if ($phase === 'phase1') {
            return [
                'success' => true,
                'message' => HTML_HICYN . '镇元大仙笑道：「别急别急，再等一会儿。」' . HTML_NOR
            ];
        }
        
        if ($state['fruit_distributed']) {
            return [
                'success' => true,
                'message' => HTML_HICYN . '镇元大仙笑道：「果子已经分完了，下次再来吧。」' . HTML_NOR
            ];
        }
        
        // phase2：可以分发
        // 统计果园内的非巫师在线玩家
        $players = Database::queryAll(
            "SELECT id, name FROM characters 
             WHERE current_room = 'qujing/wuzhuang/renshenguo-yuan' 
             AND online = 1",
            []
        );
        
        // 过滤掉 live_forever 的玩家（简化版原LPC的反长生检查）
        $eligiblePlayers = [];
        foreach ($players as $player) {
            $liveForever = Database::queryOne(
                "SELECT state_value FROM character_temp_states 
                 WHERE char_id = ? AND state_key = 'live_forever'",
                [$player['id']]
            );
            if (!$liveForever) {
                $eligiblePlayers[] = $player;
            }
        }
        
        $count = count($eligiblePlayers);
        
        if ($count === 0) {
            return [
                'success' => true,
                'message' => HTML_HICYN . '镇元大仙四下看看，笑道：「人都去哪了？谁来吃果子？」' . HTML_NOR
            ];
        }
        
        $cfg = self::loadConfig();
        if ($count > $cfg['max_recipients']) {
            return [
                'success' => true,
                'message' => HTML_HICYN . '镇元大仙皱眉道：「人太多了，果子不够分啊。」' . HTML_NOR
            ];
        }
        
        // 分发人参果给每位在场玩家
        require_once MODEL_PATH . 'Item.php';
        $recipients = [];
        
        foreach ($eligiblePlayers as $player) {
            // 给予人参果
            ItemModel::addToInventory($player['id'], 'renshen-guo', 1);
            $recipients[] = $player['name'];
            
            // 通知每位玩家获得了人参果
            MessageDaemon::queueMessageToSelf(
                $player['id'],
                HTML_HIYEL . '镇元大仙笑着递给你一枚白白胖胖的人参果。' . HTML_NOR,
                'self_event'
            );
        }
        
        // 广播分发结果
        $namesList = implode('、', $recipients);
        $distMsg = HTML_HIMAG . '【人参果会】' . HTML_NOR 
            . HTML_HICYN . '镇元大仙笑道：「好！今日有缘，每人赏一枚人参果！」' . HTML_NOR;
        MessageDaemon::broadcastToRoom('qujing/wuzhuang/renshenguo-yuan', $distMsg, 0, 'event');
        
        // 标记分发完毕
        $state['fruit_distributed'] = true;
        $state['current_phase'] = 'finished';
        self::saveState($state);
        
        return [
            'success' => true,
            'recipients' => $recipients,
            'message' => HTML_HICYN . '镇元大仙笑着将人参果分给了' . HTML_HIYEL . $namesList 
                . HTML_HICYN . '。' . HTML_NOR 
                . "\n" . HTML_HICYN . '镇元大仙微微一笑，身形渐渐淡去……' . HTML_NOR,
        ];
    }
    
    /**
     * 结束事件
     */
    public static function endEvent(): void {
        $state = self::getDefaultState();
        $state['event_ended_at'] = time();
        self::saveState($state);
        
        // 广播事件结束
        $endMsg = HTML_HICYN . '镇元大仙微微一笑，化作一道清风，消失不见了。' . HTML_NOR;
        MessageDaemon::broadcastToRoom('qujing/wuzhuang/renshenguo-yuan', $endMsg, 0, 'event');
    }
    
    /**
     * 获取事件冷却剩余时间（秒）
     */
    public static function getCooldownRemaining(): int {
        $state = self::getState();
        if ($state['event_ended_at'] <= 0) return 0;
        $cfg = self::loadConfig();
        $remaining = ($state['event_ended_at'] + $cfg['cooldown']) - time();
        return max(0, $remaining);
    }
    
    // ================================================================
    //  全服广播消息
    // ================================================================
    
    /**
     * 事件开始广播（第一次公告）
     * 原始LPC: "五庄观人参果品尝会就要开始了！"
     */
    private static function broadcastStart(int $triggerCharId): void {
        $char = CharacterModel::find($triggerCharId);
        $name = $char ? $char['name'] : '某人';
        
        $msg = HTML_HIMAG . '【人参果会】' . HTML_NOR 
            . HTML_HIYEL . '五庄观人参果品尝会就要开始了！' . HTML_NOR . "\n"
            . HTML_HICYN . '镇元大仙笑道：「既然' . HTML_HIYEL . $name 
            . HTML_HICYN . '找到了老道，老道就请大家吃人参果！」' . HTML_NOR;
        
        MessageDaemon::broadcastToAll($msg, 0, 'event');
    }
    
    /**
     * 第二次广播（phase1）
     * 原始LPC: "快要开始了"
     */
    private static function broadcastPhase1(): void {
        $msg = HTML_HIMAG . '【人参果会】' . HTML_NOR 
            . HTML_HIYEL . '五庄观人参果品尝会快要开始了！' . HTML_NOR . "\n"
            . HTML_HICYN . '镇元大仙拈须微笑：「莫急，果子马上就好。」' . HTML_NOR;
        
        MessageDaemon::broadcastToAll($msg, 0, 'event');
    }
    
    /**
     * 第三次广播（phase2，可以分发了）
     * 原始LPC: "开始了"
     */
    private static function broadcastPhase2(): void {
        $msg = HTML_HIMAG . '【人参果会】' . HTML_NOR 
            . HTML_HIYEL . '五庄观人参果品尝会正式开始了！' . HTML_NOR . "\n"
            . HTML_HICYN . '镇元大仙笑道：「好了好了，想吃的快来问老道要人参果吧！」' . HTML_NOR;
        
        MessageDaemon::broadcastToAll($msg, 0, 'event');
    }
    
    // ================================================================
    //  人参果食用效果
    // ================================================================
    
    /**
     * 处理人参果食用效果
     * 原始LPC: /d/obj/drug/renshen-guo.c do_eat()
     * 
     * 效果:
     * - +20 max_force (最大内力)
     * - +20 max_mana (最大法力)
     * - obstacle/wuzhuang = "done"
     * - 累计36枚 → 长生不老
     */
    public static function handleEatRenshenGuo(int $charId): array {
        require_once MODEL_PATH . 'Character.php';
        
        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        // 1. 增加 max_force（从配置读取增量）
        $cfg = self::loadConfig();
        $newMaxForce = intval($char['max_force']) + $cfg['force_gain'];
        Database::execute('UPDATE characters SET max_force = ? WHERE id = ?', [$newMaxForce, $charId]);
        
        // 2. 增加 max_mana（从配置读取增量）
        $newMaxMana = intval($char['max_mana']) + $cfg['mana_gain'];
        Database::execute('UPDATE characters SET max_mana = ? WHERE id = ?', [$newMaxMana, $charId]);
        
        // 3. 设置 obstacle/wuzhuang = done
        Database::execute(
            "INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) 
             VALUES (?, 'obstacle/wuzhuang', 'done', NOW(), NOW())
             ON DUPLICATE KEY UPDATE state_value = 'done', updated_at = NOW()",
            [$charId]
        );
        
        // 4. 累加 rsg_eaten 计数
        $rsgRow = Database::queryOne(
            "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = 'rsg_eaten'",
            [$charId]
        );
        $rsgEaten = $rsgRow ? intval($rsgRow['state_value']) + 1 : 1;
        
        Database::execute(
            "INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) 
             VALUES (?, 'rsg_eaten', ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE state_value = ?, updated_at = NOW()",
            [$charId, $rsgEaten, $rsgEaten]
        );
        
        // 5. 构建消息
        $msg = HTML_HIWHT . '你小心翼翼地咬了一口人参果，那果子入口即化，一股暖流涌遍全身……' . HTML_NOR . "\n";
        $msg .= HTML_HICYN . '你只觉得通体舒泰，精神大振！' . HTML_NOR . "\n";
        $msg .= HTML_HIYEL . '最大内力 +20 (' . ($char['max_force']) . ' → ' . $newMaxForce . ')' . HTML_NOR . "\n";
        $msg .= HTML_HIYEL . '最大法力 +20 (' . ($char['max_mana']) . ' → ' . $newMaxMana . ')' . HTML_NOR . "\n";
        $msg .= HTML_GRN . '【五庄观障碍】已完成' . HTML_NOR . "\n";
        
        // 6. 广播到房间
        $roomMsg = HTML_HIYEL . $char['name'] . '服下一枚人参果，浑身金光大盛！' . HTML_NOR;
        MessageDaemon::broadcastToRoom($char['current_room'] ?? '', $roomMsg, $charId, 'room');
        
        // 7. 检查长生不老（从配置读取阈值）
        if ($rsgEaten >= $cfg['live_forever_threshold']) {
            // 检查是否已经长生
            $existing = Database::queryOne(
                "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = 'live_forever'",
                [$charId]
            );
            if (!$existing) {
                Database::execute(
                    "INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) 
                     VALUES (?, 'live_forever', '1', NOW(), NOW())
                     ON DUPLICATE KEY UPDATE state_value = '1', updated_at = NOW()",
                    [$charId]
                );
                
                $msg .= "\n" . HTML_HIMAG . '【天道昭彰】' . HTML_NOR 
                    . HTML_HIYEL . $char['name'] . '服下第三十六枚人参果，终于得证长生！' . HTML_NOR;
                
                // 全服广播
                $liveMsg = HTML_HIMAG . '【天道昭彰】' . HTML_NOR 
                    . HTML_HIYEL . '恭喜' . $char['name'] . '服下第三十六枚人参果，修得长生不老之身！' . HTML_NOR;
                MessageDaemon::broadcastToAll($liveMsg, 0, 'rumor');
            }
        }
        
        $msg .= HTML_CYN . '（已累计服用 ' . $rsgEaten . ' 枚人参果）' . HTML_NOR;
        
        return ['success' => true, 'message' => $msg];
    }
    
    /**
     * 获取事件信息文本（用于房间渲染）
     */
    public static function getEventInfoText(): string {
        $phase = self::getCurrentPhase();
        
        switch ($phase) {
            case 'announcing':
                $cfg = self::loadConfig();
                $remaining = $cfg['phase1_delay'] - (time() - self::getState()['event_started_at']);
                return '镇元大仙正笑吟吟地站在那里，似乎在等待什么人。'
                    . '（' . max(0, $remaining) . '秒后开始）';
                    
            case 'phase1':
                return '镇元大仙拈须微笑，似乎在等待果子成熟。';
                
            case 'phase2':
                return '镇元大仙笑道：「想吃人参果的，快来问老道吧！」';
                
            case 'finished':
                return '镇元大仙的身形渐渐淡去……';
                
            case 'cooldown':
                $remaining = self::getCooldownRemaining();
                return '果园恢复了平静。（' . ceil($remaining / 60) . '分钟后可再次开启）';
                
            default:
                return '';
        }
    }
}
