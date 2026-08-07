<?php
/**
 * Quest Handler
 * 
 * 任务交互处理器
 * 处理玩家从NPC领取任务的功能
 * 支持开封解谜系统的NPC专属任务分配
 */

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/../helpers/QuestHelper.php';

class QuestHandler extends ActionHandler {

    /**
     * 开封解谜配置缓存
     */
    private static ?array $kaifengConfig = null;

    /**
     * 执行任务交互动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在'];
            }
            
            // 获取NPC ID
            $npcId = intval($_GET['npc_id'] ?? $_POST['npc_id'] ?? 0);
            
            if (!$npcId) {
                return ['success' => false, 'message' => '未指定NPC'];
            }
            
            // 获取NPC信息
            require_once __DIR__ . '/../models/Npc.php';
            $npc = NpcModel::find($npcId);
            
            if (!$npc) {
                return ['success' => false, 'message' => 'NPC不存在'];
            }
            
            // 检查是否是开封解谜NPC
            $npcIdStr = $npc['npc_id'] ?? strval($npcId);
            $kaifengConfig = $this->loadKaifengConfig();

            if ($kaifengConfig && isset($kaifengConfig['npc_map'][$npcIdStr])) {
                // 开封解谜NPC：按专属类型分配任务
                $npcInfo = $kaifengConfig['npc_map'][$npcIdStr];
                $questType = $npcInfo['quest_type'] ?? '';
                
                // 检查玩家是否已经有同类型的进行中任务（排除取经护送任务）
                $pendingQuests = QuestHelper::getPendingQuests($charId, $questType);
                
                if (!empty($pendingQuests)) {
                    $questList = [];
                    foreach ($pendingQuests as $quest) {
                        $questList[] = $this->formatQuestDescription($quest);
                    }
                    $message = "你当前有以下任务在身：\n";
                    $message .= implode("\n", $questList);
                    $message .= "\n\n先完成当前任务再来吧。";
                    return ['success' => true, 'message' => $message];
                }
                
                $charDaoxing = intval($character['daoxing'] ?? 0);
                $quest = $this->assignKaifengQuest($charId, $npcIdStr, $charDaoxing);

                if (!$quest) {
                    return ['success' => true, 'message' => $npc['name'] . '对你说："今天没什么任务了，你改天再来吧。"'];
                }

                $questDesc = $this->formatQuestDescription($quest);
                $message = $npc['name'] . '给了你一个任务：' . "\n" . $questDesc;

                return ['success' => true, 'message' => $message];
            }

            // 非开封解谜NPC：使用通用任务分配（动态获取可用任务类型）
            $questTypes = QuestHelper::getAvailableQuestTypes();
            
            $quest = null;
            $attempts = 0;
            while ($quest === null && $attempts < count($questTypes)) {
                $randomType = $questTypes[array_rand($questTypes)];
                
                $pendingQuests = QuestHelper::getPendingQuests($charId, $randomType);
                if (!empty($pendingQuests)) {
                    $attempts++;
                    continue;
                }
                
                $quest = QuestHelper::assignQuest($charId, $randomType);
                $attempts++;
            }
            
            if (!$quest) {
                return ['success' => true, 'message' => $npc['name'] . '对你说："今天没什么任务了，你改天再来吧。"'];
            }
            
            // 构建任务描述
            $questDesc = $this->formatQuestDescription($quest);
            $message = $npc['name'] . '给了你一个任务：' . "\n" . $questDesc;
            
            return ['success' => true, 'message' => $message];
            
        } catch (\Exception $e) {
            error_log("QuestHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '任务交互执行失败'];
        }
    }

    /**
     * 加载开封解谜配置（通过QuestHelper接口获取，支持数据库优先）
     */
    private function loadKaifengConfig(): ?array {
        if (self::$kaifengConfig !== null) {
            return self::$kaifengConfig;
        }

        require_once __DIR__ . '/../helpers/QuestHelper.php';
        
        $npcMap = QuestHelper::getNpcMap();
        $questPools = [];
        $questTypes = QuestHelper::getAvailableQuestTypes();
        foreach ($questTypes as $type) {
            $questPools[$type] = QuestHelper::getQuestPool($type);
        }
        
        self::$kaifengConfig = [
            'npc_map' => $npcMap,
            'quest_pools' => $questPools,
            'cache_size' => QuestHelper::getConfigParam('cache_size', 30),
            'index_delta' => QuestHelper::getConfigParam('index_delta', 20),
        ];
        
        return self::$kaifengConfig;
    }

    /**
     * 开封解谜NPC专属任务分配
     *
     * 从 kaifeng_quests.php 配置的任务池中，按道行值选取合适任务
     * 实现缓存机制避免重复（cache_size=30）
     * 实现二分查找 + 随机偏移（index_delta=20）
     *
     * @param int $charId 角色ID
     * @param string $npcId NPC标识
     * @param int $charDaoxing 角色道行值
     * @return array|null 任务数据，失败返回null
     */
    public function assignKaifengQuest(int $charId, string $npcId, int $charDaoxing): ?array {
        require_once __DIR__ . '/../includes/db.php';

        try {
            $kaifengConfig = $this->loadKaifengConfig();
            if (!$kaifengConfig) {
                error_log("assignKaifengQuest: 开封配置加载失败");
                return null;
            }

            // 获取NPC对应的专属任务类型
            $npcInfo = $kaifengConfig['npc_map'][$npcId] ?? null;
            if (!$npcInfo) {
                error_log("assignKaifengQuest: NPC {$npcId} 不在开封NPC映射中");
                return null;
            }
            $questType = $npcInfo['quest_type'];

            // 获取该类型的任务池（按道行值作为key的关联数组）
            $questPool = $kaifengConfig['quest_pools'][$questType] ?? [];
            if (empty($questPool)) {
                error_log("assignKaifengQuest: 任务池为空，类型={$questType}");
                return null;
            }

            $cacheSize = $kaifengConfig['cache_size'] ?? 30;
            $indexDelta = $kaifengConfig['index_delta'] ?? 20;

            // 将任务池按道行值排序，提取key列表用于索引
            $daoxingKeys = array_keys($questPool);
            sort($daoxingKeys);
            $totalKeys = count($daoxingKeys);

            // 二分查找：找到道行值 <= 角色道行值的最大索引位置
            $suitableIndex = 0;
            $low = 0;
            $high = $totalKeys - 1;

            while ($low <= $high) {
                $mid = intval(($low + $high) / 2);
                if ($daoxingKeys[$mid] <= $charDaoxing + 5000) {
                    $suitableIndex = $mid;
                    $low = $mid + 1;
                } else {
                    $high = $mid - 1;
                }
            }

            // 加入随机偏移
            $lower = max(0, $suitableIndex - $indexDelta);
            $upper = min($totalKeys - 1, $suitableIndex + $indexDelta);

            // 调整下限，允许访问更简单的任务
            $lower = intval($upper / 4);
            if ($upper - $lower < $indexDelta) {
                $lower = 0;
            }

            // 在范围内随机选择一个任务
            $selectedIndex = $lower + rand(0, max(0, $upper - $lower));
            $selectedDaxingKey = $daoxingKeys[$selectedIndex];
            $selectedQuest = $questPool[$selectedDaxingKey];

            // 缓存检查：避免重复任务
            $cacheCheckSql = "SELECT COUNT(*) as cnt FROM quest_cache WHERE char_id = ? AND quest_type = ? AND quest_index = ?";
            $cached = Database::queryOne($cacheCheckSql, [$charId, $questType, $selectedDaxingKey]);

            if ($cached && intval($cached['cnt']) > 0) {
                // 尝试选择其他任务（最多尝试5次）
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $retryIndex = $lower + rand(0, max(0, $upper - $lower));
                    $retryDaxingKey = $daoxingKeys[$retryIndex];
                    $retryCached = Database::queryOne($cacheCheckSql, [$charId, $questType, $retryDaxingKey]);

                    if (!$retryCached || intval($retryCached['cnt']) == 0) {
                        $selectedIndex = $retryIndex;
                        $selectedDaxingKey = $retryDaxingKey;
                        $selectedQuest = $questPool[$selectedDaxingKey];
                        break;
                    }
                }
            }

            // 构建任务描述
            $npcName = $npcInfo['name'];
            $questName = $selectedQuest['name'] ?? '未知任务';
            $targetId = $selectedQuest['id'] ?? '';

            // 计算动态奖励
            $char = $this->getCharacter($charId);
            $tempQuest = ['daoxing_require' => $selectedDaxingKey, 'quest_type' => $questType];
            $rewards = QuestHelper::calculateRewards($char, $tempQuest);

            // 创建任务记录（增加 npc_id 字段记录任务发布者）
            $color = $npcInfo['color_code'] ?? 'white';
            
            // 计算任务过期时间（原始LPC: delay = 300 + random(600)，即5~15分钟）
            $delay = 300 + rand(0, 600);
            $expiresAt = date('Y-m-d H:i:s', time() + $delay);
            
            $sql = "INSERT INTO character_quests 
                    (char_id, quest_type, quest_name, target_id, target_name, object_name, daoxing_require, reward_daoxing, reward_potential, reward_silver, color_code, npc_id, status, created_at, expires_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), ?)";

            $params = [
                $charId,
                $questType,
                $npcName . '的任务',
                $targetId,
                $questName,
                $selectedQuest['topic'] ?? '',
                $selectedDaxingKey,
                $rewards['daoxing'],
                $rewards['potential'],
                $rewards['silver'],
                $color,
                $npcId,  // 记录任务发布NPC
                $expiresAt
            ];

            $result = Database::execute($sql, $params);

            if ($result) {
                $questId = Database::lastInsertId();

                // 更新缓存：维护缓存大小
                $insertCacheSql = "INSERT INTO quest_cache (char_id, quest_type, quest_index, cached_at) VALUES (?, ?, ?, NOW())";
                Database::execute($insertCacheSql, [$charId, $questType, $selectedDaxingKey]);

                // 清理超出缓存大小的旧记录
                $cleanCacheSql = "DELETE FROM quest_cache WHERE char_id = ? AND quest_type = ? 
                                  AND id NOT IN (SELECT id FROM (SELECT id FROM quest_cache WHERE char_id = ? AND quest_type = ? ORDER BY cached_at DESC LIMIT {$cacheSize}) as tmp)";
                Database::execute($cleanCacheSql, [$charId, $questType, $charId, $questType]);

                return [
                    'id' => $questId,
                    'type' => $questType,
                    'name' => $selectedQuest['name'],
                    'target' => $selectedQuest['id'],
                    'object' => $selectedQuest['topic'] ?? '',
                    'color' => $color,
                    'reward_dx' => $rewards['daoxing'],
                    'reward_pot' => $rewards['potential'],
                    'reward_silver' => $rewards['silver'],
                ];
            }

            error_log("assignKaifengQuest: 数据库插入失败");
            return null;

        } catch (\Exception $e) {
            error_log("assignKaifengQuest error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 格式化任务描述
     */
    private function formatQuestDescription(array $quest): string {
        $typeNames = [
            'kill' => '杀怪',
            'give' => '送物',
            'ask' => '拜贤',
            'find' => '寻宝',
            'weapon' => '寻兵器',
            'food' => '寻食物',
            'cloth' => '寻衣物',
            'armor' => '寻盔甲',
            'wearing' => '寻穿戴',
            'misc' => '杂项',
        ];
        
        $typeName = $typeNames[$quest['type']] ?? $quest['type'];
        $desc = "【{$typeName}】{$quest['name']}";
        
        if (!empty($quest['target'])) {
            $desc .= " (目标: {$quest['target']})";
        }
        
        if (!empty($quest['object'])) {
            $desc .= " (物品: {$quest['object']})";
        }
        
        $desc .= "\n奖励：";
        if ($quest['reward_dx'] > 0) $desc .= "道行+{$quest['reward_dx']} ";
        if ($quest['reward_pot'] > 0) $desc .= "潜能+{$quest['reward_pot']} ";
        if ($quest['reward_silver'] > 0) $desc .= "白银+{$quest['reward_silver']}";
        
        return $desc;
    }
}

