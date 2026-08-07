<?php
/**
 * QiongcaoHandler - 琼草系统处理器
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 功能：
 * - 琼草7阶段动态生长（从"紫红色小草"到"七叶灵芝草"，约2.5小时）
 * - 采集琼草触发白鹤守卫战斗
 * - 食用琼草：全属性恢复 + 永久内力/法力上限+5 + 年龄修正
 * 
 * 生长触发：玩家从蓬莱仙岛移动到青石崖时自动触发
 * 数据存储：使用 variables 表，键名格式 qiongcao_{field}_{md5(roomId)}
 */

require_once __DIR__ . '/ActionHandler.php';

class QiongcaoHandler extends ActionHandler {
    
    // 琼草物品ID
    const QIONGCAO_ITEM_ID = 'qiongcao';
    
    // 拔草消耗体力
    const KEE_COST = 30;
    
    // 生长阶段总数（0-6为生长中，7为成熟）
    const GROWTH_STAGES = 7;
    
    // 刷新周期（分钟）- 从开始生长到成熟约2.5小时
    const REFRESH_INTERVAL_MINUTES = 150;
    
    // 白鹤NPC ID
    const BAIHE_NPC_ID = 'baihe';
    
    // 琼草房间
    const QIONGCAO_ROOM = 'penglai/yashang';
    
    /**
     * 各阶段累计等待时间（秒）
     * 阶段0→1: 10分钟, 1→2: 10分钟, 2→3: 15分钟, 3→4: 20分钟
     * 4→5: 25分钟, 5→6: 30分钟, 6→7: 40分钟
     * 总计约150分钟
     */
    const STAGE_TIMES = [
        0 => 0,        // 刚种下
        1 => 600,      // 10分钟后 → 一叶琼草
        2 => 1200,     // 20分钟后 → 二叶琼草
        3 => 2100,     // 35分钟后 → 三叶琼草
        4 => 3300,     // 55分钟后 → 四叶琼草
        5 => 4800,     // 80分钟后 → 五叶琼草
        6 => 6600,     // 110分钟后 → 六叶琼草
        7 => 9000,     // 150分钟后 → 七叶灵芝草（成熟）
    ];
    
    /**
     * 各阶段名称（还原原始LPC qiongcao.c 的 grow_a ~ grow_g）
     */
    const STAGE_NAMES = [
        0 => '紫红色小草',
        1 => '一叶琼草',
        2 => '二叶琼草',
        3 => '三叶琼草',
        4 => '四叶琼草',
        5 => '五叶琼草',
        6 => '六叶琼草',
        7 => '七叶灵芝草',
    ];
    
    /**
     * 配置缓存
     */
    private ?array $configCache = null;
    
    /**
     * 获取配置（优先从 room_actions.config JSON 读取）
     */
    private function getQiongcaoConfig(array $action): array {
        if ($this->configCache === null) {
            $dbConfig = $this->parseConfig($action);
            $this->configCache = [
                'kee_cost' => $dbConfig['kee_cost'] ?? self::KEE_COST,
                'growth_stages' => $dbConfig['growth_stages'] ?? self::GROWTH_STAGES,
                'refresh_interval_minutes' => $dbConfig['refresh_interval_minutes'] ?? self::REFRESH_INTERVAL_MINUTES,
                'stage_times' => $dbConfig['stage_times'] ?? self::STAGE_TIMES,
                'stage_names' => $dbConfig['stage_names'] ?? self::STAGE_NAMES,
                'qiongcao_item_id' => $dbConfig['qiongcao_item_id'] ?? self::QIONGCAO_ITEM_ID,
                'baihe_npc_id' => $dbConfig['baihe_npc_id'] ?? self::BAIHE_NPC_ID,
                'qiongcao_room' => $dbConfig['qiongcao_room'] ?? self::QIONGCAO_ROOM,
            ];
        }
        return $this->configCache;
    }
    
    /**
     * 执行采集琼草动作（拔琼草）
     * 还原原始LPC: qiongcao.c do_dig()
     */
    public function execute(int $charId, array $action, array $params = []): array {
        require_once MODEL_PATH . 'Character.php';
        require_once __DIR__ . '/../includes/db.php';
        
        $config = $this->getQiongcaoConfig($action);
        
        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        $roomId = self::QIONGCAO_ROOM;
        
        // 1. 检查琼草生长状态
        $growthInfo = $this->getGrowthInfo($roomId);
        
        if (!$growthInfo['mature']) {
            if ($growthInfo['stage'] == 0) {
                return ['success' => false, 'message' => '山崖上的异草还没有开始生长。'];
            }
            return [
                'success' => false, 
                'message' => '山崖上的' . $growthInfo['stage_name'] . '还没有成熟，现在还无法采摘。'
            ];
        }
        
        // 2. 检查体力
        if ($char['kee'] < self::KEE_COST) {
            return ['success' => false, 'message' => '你太累了，无法拔草。'];
        }
        
        // 3. 触发白鹤守卫战斗
        // 还原原始LPC: if(baihe=present("bai he")) { baihe->kill_ob(who); }
        $baiheFightMsg = $this->triggerBaiheFight($charId, $roomId);
        
        // 4. 事务处理：扣除体力、添加琼草、重置生长状态
        Database::beginTransaction();
        
        try {
            // 扣除体力
            Database::execute(
                'UPDATE characters SET kee = kee - ? WHERE id = ?',
                [self::KEE_COST, $charId]
            );
            
            // 添加琼草到背包
            Database::execute(
                'INSERT INTO character_inventory (char_id, item_id, quantity)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE quantity = quantity + 1',
                [$charId, self::QIONGCAO_ITEM_ID]
            );
            
            // 重置生长状态（琼草被拔走，需要重新生长）
            // 还原原始LPC: where->delete("grow_grass")
            $this->resetGrowth($roomId);
            
            Database::commit();
            
            // 5. 广播消息
            $selfMsg = HTML_HIGRN . '你小心翼翼地将' . self::STAGE_NAMES[7] . '从山崖上拔了起来，放入了背包。' . HTML_NOR;
            if (!empty($baiheFightMsg)) {
                $selfMsg .= "\n" . $baiheFightMsg;
            }
            
            $broadcastMsg = HTML_HIYEL . $char['name'] . '小心翼翼地从山崖上拔起了一株灵草。' . HTML_NOR;
            $this->broadcastToRoom($roomId, $broadcastMsg, $charId);
            
            return [
                'success' => true,
                'message' => $selfMsg,
                'data' => ['item' => self::QIONGCAO_ITEM_ID]
            ];
            
        } catch (Exception $e) {
            Database::rollBack();
            error_log('QiongcaoHandler error: ' . $e->getMessage());
            return ['success' => false, 'message' => '拔草失败：' . $e->getMessage()];
        }
    }
    
    /**
     * 获取琼草生长状态
     * 
     * @param string $roomId 房间ID
     * @return array ['stage' => int, 'stage_name' => string, 'mature' => bool, 'started_at' => int]
     */
    public function getGrowthInfo(string $roomId): array {
        require_once __DIR__ . '/../includes/db.php';
        
        $roomKey = md5($roomId);
        
        // 读取生长起始时间
        $activeResult = Database::queryOne(
            'SELECT `value` FROM variables WHERE var_key = ?',
            ['qiongcao_active_' . $roomKey]
        );
        
        if (!$activeResult || $activeResult['value'] != '1') {
            return [
                'stage' => 0,
                'stage_name' => '',
                'mature' => false,
                'started_at' => 0
            ];
        }
        
        // 读取生长开始时间戳
        $timeResult = Database::queryOne(
            'SELECT `value` FROM variables WHERE var_key = ?',
            ['qiongcao_time_' . $roomKey]
        );
        
        $startedAt = $timeResult ? intval($timeResult['value']) : time();
        $elapsed = time() - $startedAt;
        
        // 根据经过时间计算当前阶段
        $currentStage = 0;
        for ($i = self::GROWTH_STAGES; $i >= 0; $i--) {
            if ($elapsed >= self::STAGE_TIMES[$i]) {
                $currentStage = $i;
                break;
            }
        }
        
        return [
            'stage' => $currentStage,
            'stage_name' => self::STAGE_NAMES[$currentStage],
            'mature' => ($currentStage >= self::GROWTH_STAGES),
            'started_at' => $startedAt
        ];
    }
    
    /**
     * 触发新一轮生长
     * 还原原始LPC: qiongcao.c invocation() 中的 call_out 生长机制
     * 
     * @param string $roomId 房间ID
     * @return bool 是否成功启动了新的生长
     */
    public function tryStartGrowth(string $roomId): bool {
        require_once __DIR__ . '/../includes/db.php';
        
        $roomKey = md5($roomId);
        
        // 检查是否已有琼草在生长
        $activeResult = Database::queryOne(
            'SELECT `value` FROM variables WHERE var_key = ?',
            ['qiongcao_active_' . $roomKey]
        );
        
        if ($activeResult && $activeResult['value'] == '1') {
            // 检查是否已经成熟但未被采摘（超过成熟时间+30分钟后自动重置）
            $timeResult = Database::queryOne(
                'SELECT `value` FROM variables WHERE var_key = ?',
                ['qiongcao_time_' . $roomKey]
            );
            $startedAt = $timeResult ? intval($timeResult['value']) : 0;
            $elapsed = time() - $startedAt;
            
            // 成熟后30分钟未被采摘，枯萎重新生长
            if ($elapsed > self::STAGE_TIMES[7] + 1800) {
                $this->resetGrowth($roomId);
                // 继续往下走，重新启动生长
            } else {
                // 正在生长中或已成熟等待采摘，不重新启动
                return false;
            }
        }
        
        // 启动新一轮生长
        $now = time();
        
        // 设置活跃标记
        Database::execute(
            'INSERT INTO variables (var_key, `value`, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE `value` = ?, updated_at = NOW()',
            ['qiongcao_active_' . $roomKey, '1', '1']
        );
        
        // 设置开始时间
        Database::execute(
            'INSERT INTO variables (var_key, `value`, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE `value` = ?, updated_at = NOW()',
            ['qiongcao_time_' . $roomKey, (string)$now, (string)$now]
        );
        
        return true;
    }
    
    /**
     * 重置生长状态（琼草被拔走或枯萎后）
     */
    private function resetGrowth(string $roomId): void {
        require_once __DIR__ . '/../includes/db.php';
        
        $roomKey = md5($roomId);
        
        Database::execute(
            'UPDATE variables SET `value` = ?, updated_at = NOW() WHERE var_key = ?',
            ['0', 'qiongcao_active_' . $roomKey]
        );
        
        Database::execute(
            'UPDATE variables SET `value` = ?, updated_at = NOW() WHERE var_key = ?',
            ['0', 'qiongcao_time_' . $roomKey]
        );
    }
    
    /**
     * 触发白鹤守卫战斗
     * 还原原始LPC: qiongcao.c do_dig() 中的白鹤守卫逻辑
     * 
     * @param int $charId 角色ID
     * @param string $roomId 房间ID
     * @return string 战斗触发消息
     */
    private function triggerBaiheFight(int $charId, string $roomId): string {
        require_once __DIR__ . '/../includes/db.php';
        require_once DAEMON_PATH . 'CombatDaemon.php';
        
        // 查找房间中的白鹤NPC
        $baihe = Database::queryOne(
            "SELECT id, name FROM npcs WHERE npc_id = ? AND (spawn_room = ? OR current_room = ?)",
            [self::BAIHE_NPC_ID, $roomId, $roomId]
        );
        
        if ($baihe) {
            // 白鹤在房间，触发战斗
            // 还原原始LPC: baihe->kill_ob(who); who->fight_ob(baihe);
            $result = CombatDaemon::startKill($charId, $baihe['id'], 'npc', $baihe['name']);
            
            if ($result['success']) {
                return HTML_HIRED . '白鹤看到你拔草，发出愤怒的鸣叫，向你扑来！' . HTML_NOR;
            }
            return HTML_HIRED . '白鹤向你发出了攻击！' . HTML_NOR;
        }
        
        // 白鹤不在房间，检查是否需要临时创建
        // 还原原始LPC: if (!baihe && me->query("eatable")) { baihe=new(...); baihe->move(where); }
        // 简化处理：从远处飞来一只白鹤
        $baiheAnywhere = Database::queryOne(
            "SELECT id, name FROM npcs WHERE npc_id = ? LIMIT 1",
            [self::BAIHE_NPC_ID]
        );
        
        if ($baiheAnywhere) {
            // 将白鹤移到当前房间
            Database::execute(
                "UPDATE npcs SET current_room = ? WHERE id = ?",
                [$roomId, $baiheAnywhere['id']]
            );
            
            // 触发战斗
            $result = CombatDaemon::startKill($charId, $baiheAnywhere['id'], 'npc', $baiheAnywhere['name']);
            
            if ($result['success']) {
                return HTML_HIRED . '一只白鹤从远处飞来，看到你拔草，愤怒地向你扑来！' . HTML_NOR;
            }
            return HTML_HIRED . '一只白鹤从远处飞来，向你发起了攻击！' . HTML_NOR;
        }
        
        return '';
    }
    
    /**
     * 食用琼草的效果
     * 还原原始LPC: qiongcao.c do_eat()
     * 
     * 效果：
     * 1. 恢复所有属性到最大值（精、气、神、食物、饮水）
     * 2. 永久增加内力上限和法力上限各5点
     * 3. 年龄修正（减少年龄）
     * 
     * @param int $charId 角色ID
     * @return array 食用结果
     */
    public static function handleEatQiongcao(int $charId): array {
        require_once MODEL_PATH . 'Character.php';
        require_once __DIR__ . '/../includes/db.php';
        
        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        $messages = [];
        
        // 1. 恢复所有属性到最大值
        // 还原原始LPC: 
        //   me->set("eff_gin", me->query("max_gin"));
        //   me->set("eff_kee", me->query("max_kee"));
        //   me->set("eff_sen", me->query("max_sen"));
        //   me->set("gin", me->query("max_gin"));
        //   me->set("kee", me->query("max_kee"));
        //   me->set("sen", me->query("max_sen"));
        //   me->set("food", me->max_food_capacity());
        //   me->set("water", me->max_water_capacity());
        // 注意：当前项目characters表无eff_gin/eff_kee/eff_sen字段，跳过
        Database::execute(
            'UPDATE characters SET 
                gin = max_gin,
                kee = max_kee,
                sen = max_sen,
                food = max_food, water = max_water
            WHERE id = ?',
            [$charId]
        );
        $messages[] = '你的精神完全恢复了！';
        
        // 2. 永久增加内力和法力上限各5点
        // 还原原始LPC:
        //   me->add("max_force", 5);
        //   me->add("force", 5);
        //   me->add("max_mana", 5);
        //   me->add("mana", 5);
        Database::execute(
            'UPDATE characters SET 
                max_force = max_force + 5,
                force = force + 5,
                max_mana = max_mana + 5,
                mana = mana + 5
            WHERE id = ?',
            [$charId]
        );
        $messages[] = '你的内力和法力上限各增长了5点！';
        
        // 3. 年龄修正效果
        // 还原原始LPC:
        //   if (me->query("mud_age") > 1382400)
        //       me->add("age_modify", -3600);
        $mudAge = intval($char['mud_age'] ?? 0);
        if ($mudAge > 1382400) {
            Database::execute(
                'UPDATE characters SET age_modify = age_modify - 3600 WHERE id = ?',
                [$charId]
            );
            $messages[] = '你感到自己似乎年轻了一些。';
        }
        
        // 重新获取角色数据以显示当前值
        $newChar = CharacterModel::find($charId);
        $newMaxForce = $newChar['max_force'] ?? 0;
        $newMaxMana = $newChar['max_mana'] ?? 0;
        
        $fullMessage = HTML_HICYN 
            . "你将琼草送入口中，只觉一股暖流直冲脑门，浑身发热发烫，说不出的舒服。\n"
            . implode("\n", $messages) . "\n"
            . "（当前内力上限：{$newMaxForce}，法力上限：{$newMaxMana}）"
            . HTML_NOR;
        
        return [
            'success' => true,
            'message' => $fullMessage,
            'skip_queue' => true
        ];
    }
    
    /**
     * 获取当前琼草生长阶段名称（供页面显示）
     */
    public static function getCurrentStageName(int $stage): string {
        return self::STAGE_NAMES[$stage] ?? '';
    }
    
    /**
     * 获取下次成熟时间（供页面显示）
     */
    public function getNextMatureTime(string $roomId): ?string {
        $growthInfo = $this->getGrowthInfo($roomId);
        
        if ($growthInfo['mature']) {
            return null; // 已成熟
        }
        
        if ($growthInfo['started_at'] == 0) {
            return null; // 未开始
        }
        
        $nextStageTime = self::STAGE_TIMES[($growthInfo['stage'] + 1)] ?? self::STAGE_TIMES[7];
        $nextTime = $growthInfo['started_at'] + $nextStageTime;
        
        return date('H:i:s', $nextTime);
    }
}
