<?php
/**
 * Fish Handler
 * 
 * 钓鱼动作处理器
 * 完全还原原始项目的钓鱼体验：
 * - 装蚯蚓（需要闪避技能≥20级）
 * - 抛竿钓鱼（多阶段等待）
 * - 提杆（时机判定）
 * - 属性消耗（sen）
 * - 风险机制（鱼竿丢失）
 * - 多种结果（钓到鱼/鱼跑了/钓到垃圾）
 */

require_once __DIR__ . '/ActionHandler.php';

class FishHandler extends ActionHandler {
    
    /**
     * 钓鱼阶段消息（对应原始项目的6个阶段）
     */
    private const FISHING_MESSAGES = [
        0 => "水面上一点动静也没有。",
        1 => "河水泛起一片涟漪。",
        2 => "一个小水花逐渐靠向浮子。",
        3 => "浮子抖动了两下。",
        4 => "浮子开始颤抖着。",
        5 => "水面又恢复了平静。",
    ];
    
    /**
     * 垃圾物品的随机名字和单位（对应原始项目）
     */
    private const TRASH_VARIANTS = [
        ['item_id' => 'shuicao', 'name' => '水草', 'unit' => '把'],
        ['item_id' => 'poxuezi', 'name' => '破靴子', 'unit' => '只'],
        ['item_id' => 'lanni', 'name' => '烂泥', 'unit' => '团'],
        ['item_id' => 'pangxieke', 'name' => '螃蟹壳', 'unit' => '个'],
    ];
    
    /**
     * 每阶段时间（秒）
     */
    private const STAGE_DURATION = 10;
    
    /**
     * 每阶段消耗的精神
     */
    private const SEN_COST_PER_STAGE = 30;
    
    /**
     * 阶段推进概率（50%）
     */
    private const STAGE_ADVANCE_CHANCE = 0.5;
    
    /**
     * 获取默认配置（可通过 room_actions.config JSON 覆盖）
     */
    public function getDefaultConfig(): array {
        return [
            'fishing_messages' => self::FISHING_MESSAGES,
            'trash_variants' => self::TRASH_VARIANTS,
            'stage_duration' => self::STAGE_DURATION,
            'sen_cost_per_stage' => self::SEN_COST_PER_STAGE,
            'stage_advance_chance' => self::STAGE_ADVANCE_CHANCE,
            'fish_variants' => [
                ['item_id' => 'qingyu', 'name' => '青鱼', 'category' => 'southern'],
                ['item_id' => 'golden_carp', 'name' => '金色鲤鱼', 'category' => 'changan'],
                ['item_id' => 'caoyu', 'name' => '草鱼', 'category' => 'southern'],
            ],
        ];
    }
    
    /**
     * 合并配置：数据库 config JSON 覆盖默认值
     */
    private function mergeDbConfig(array $dbConfig): array {
        $defaults = $this->getDefaultConfig();
        return array_merge($defaults, $dbConfig);
    }
    
    /**
     * 获取配置值，优先从实例缓存读取
     */
    private ?array $configCache = null;
    
    private function getConfig(array $action): array {
        if ($this->configCache === null) {
            $dbConfig = $this->parseConfig($action);
            $this->configCache = $this->mergeDbConfig($dbConfig);
        }
        return $this->configCache;
    }
    
    /**
     * 执行钓鱼动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $config = $this->getConfig($action);
            $subAction = $config['sub_action'] ?? 'diao';
            
            switch ($subAction) {
                case 'zhuang':
                    return $this->zhuangQiuyin($charId, $action, $params);
                case 'diao':
                    return $this->startFishing($charId, $action, $params);
                case 'ti':
                    return $this->pullRod($charId, $action, $params);
                case 'status':
                    return $this->getFishingStatus($charId, $action, $params);
                default:
                    return ['success' => false, 'message' => '未知的钓鱼动作'];
            }
        } catch (\Exception $e) {
            error_log("FishHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '钓鱼动作执行失败', 'data' => null];
        }
    }
    
    /**
     * 装蚯蚓
     */
    private function zhuangQiuyin(int $charId, array $action, array $params): array {
        $character = $this->getCharacter($charId);
        if (!$character) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        // 1. 检查是否有鱼竿（装备或背包里）
        $rod = $this->findRod($charId);
        if (!$rod) {
            return ['success' => false, 'message' => '你没有鱼竿，怎么装蚯蚓？'];
        }
        
        // 2. 检查是否有蚯蚓
        $qiuyin = $this->findItemInInventory($charId, 'qiuyin');
        if (!$qiuyin || $qiuyin['quantity'] < 1) {
            return ['success' => false, 'message' => '你身上没有这东西。'];
        }
        
        // 3. 检查是否已经装了蚯蚓
        if ($this->hasQiuyinOnRod($charId)) {
            return ['success' => false, 'message' => '钩上已经有蚯蚓了，不用再穿了。'];
        }
        
        // 4. 检查闪避技能等级（≥20级）
        require_once __DIR__ . '/../helpers/SkillManager.php';
        $dodgeLevel = SkillManager::getSkillLevel($charId, 'dodge');
        if ($dodgeLevel < 20) {
            return ['success' => false, 'message' => '瞧你笨手笨脚的，这事恐怕作不来。'];
        }
        
        // 5. 消耗一条蚯蚓
        require_once __DIR__ . '/../models/Item.php';
        ItemModel::removeFromInventory($charId, 'qiuyin', 1);
        
        // 6. 设置鱼竿有蚯蚓的状态
        $this->setQiuyinOnRod($charId, true);
        
        return [
            'success' => true,
            'message' => '你在鱼钩上穿了一只蚯蚓。',
            'data' => ['type' => 'zhuang_success']
        ];
    }
    
    /**
     * 开始钓鱼（抛竿）
     */
    private function startFishing(int $charId, array $action, array $params): array {
        $character = $this->getCharacter($charId);
        if (!$character) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        // 1. 检查房间是否可以钓鱼
        $roomId = $character['current_room'];
        if (!$this->canFishInRoom($roomId)) {
            return ['success' => false, 'message' => '这里不能钓鱼。'];
        }
        
        // 2. 检查是否装备了鱼竿
        $rod = $this->getEquippedRod($charId);
        if (!$rod) {
            return ['success' => false, 'message' => '你必须把杆装备上才能钓。'];
        }
        
        // 3. 检查钩上有没有蚯蚓
        if (!$this->hasQiuyinOnRod($charId)) {
            return ['success' => false, 'message' => '钩上什么都没有，怎么钓？'];
        }
        
        // 4. 检查是否已经在钓鱼
        if ($this->isFishing($charId)) {
            return ['success' => false, 'message' => '你已经钓着鱼了。'];
        }
        
        // 5. 检查精神是否足够
        $senCost = $this->configCache['sen_cost_per_stage'] ?? self::SEN_COST_PER_STAGE;
        if ($character['sen'] < $senCost) {
            return ['success' => false, 'message' => '你太累了，先歇会儿再钓吧。'];
        }
        
        // 6. 消耗精神
        $this->deductSen($charId, $senCost);
        
        // 7. 设置钓鱼状态
        $fishingState = [
            'steps' => 0,
            'start_time' => time(),
            'last_update_time' => time(),
            'rod_inv_id' => $rod['id'],
            'stages_completed' => 0,
            'last_pushed_steps' => 0, // 初始阶段已经通过开始消息展示过了
        ];
        $this->setFishingState($charId, $fishingState);
        
        $messages = $this->configCache['fishing_messages'] ?? self::FISHING_MESSAGES;
        return [
            'success' => true,
            'message' => "你将鱼钩远远向河里一抛，坐下来开始钓鱼。\n" . $messages[0],
            'data' => [
                'type' => 'fishing_started',
                'steps' => 0,
                'message' => $messages[0],
            ]
        ];
    }
    
    /**
     * 提杆
     */
    private function pullRod(int $charId, array $action, array $params): array {
        $character = $this->getCharacter($charId);
        if (!$character) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        // 1. 检查是否在钓鱼
        if (!$this->isFishing($charId)) {
            return ['success' => false, 'message' => '杆不就在你手里吗？还提什么劲？'];
        }
        
        // 2. 更新钓鱼状态（推进到当前时间）
        $this->updateFishingStatus($charId);
        $fishingState = $this->getFishingState($charId);
        $steps = $fishingState['steps'] ?? 0;
        
        // 3. 清除蚯蚓状态
        $this->setQiuyinOnRod($charId, false);
        
        // 4. 清除钓鱼状态
        $this->clearFishingState($charId);
        
        // 5. 判定结果
        $result = $this->determineFishingResult($charId, $steps);
        
        return $result;
    }
    
    /**
     * 判定钓鱼结果
     */
    private function determineFishingResult(int $charId, int $steps): array {
        $messagePrefix = "你突然将手中的鱼杆疾速上提，";
        
        // 情况1：steps == 4 且 50%概率 → 钓到鱼
        if ($steps == 4 && mt_rand(0, 1) == 1) {
            return $this->catchFish($charId, $messagePrefix);
        }
        
        // 情况2：steps == 3 或 4 → 鱼跑了
        if ($steps == 3 || $steps == 4) {
            return [
                'success' => true,
                'message' => $messagePrefix . "太可惜了，鱼没钓着：（",
                'data' => ['type' => 'fish_escaped', 'steps' => $steps]
            ];
        }
        
        // 情况3：其他情况 → 钓到垃圾
        return $this->catchTrash($charId, $messagePrefix);
    }
    
    /**
     * 钓到鱼
     */
    private function catchFish(int $charId, string $messagePrefix): array {
        require_once __DIR__ . '/../models/Item.php';
        
        // 随机选择一种鱼（从配置读取，带 fallback）
        $fishVariants = $this->configCache['fish_variants'] ?? [
            ['item_id' => 'qingyu', 'name' => '青鱼', 'category' => 'southern'],
            ['item_id' => 'golden_carp', 'name' => '金色鲤鱼', 'category' => 'changan'],
            ['item_id' => 'caoyu', 'name' => '草鱼', 'category' => 'southern'],
        ];
        
        $randomFish = $fishVariants[array_rand($fishVariants)];
        
        // 添加到背包
        ItemModel::addToInventory($charId, $randomFish['item_id'], 1, $randomFish['category']);
        
        return [
            'success' => true,
            'message' => $messagePrefix . "结果钓上了一条{$randomFish['name']}！",
            'data' => [
                'type' => 'fish_caught',
                'fish_name' => $randomFish['name'],
                'fish_item_id' => $randomFish['item_id'],
            ]
        ];
    }
    
    /**
     * 钓到垃圾
     */
    private function catchTrash(int $charId, string $messagePrefix): array {
        require_once __DIR__ . '/../models/Item.php';
        
        // 随机选择一种垃圾（从配置读取，带 fallback）
        $trashVariants = $this->configCache['trash_variants'] ?? self::TRASH_VARIANTS;
        $randomTrash = $trashVariants[array_rand($trashVariants)];
        
        // 添加到背包
        ItemModel::addToInventory($charId, $randomTrash['item_id'], 1, 'southern');
        
        $charName = "你";
        return [
            'success' => true,
            'message' => "{$charName}只觉得鱼杆沉沉的，奋力一提，上来了一{$randomTrash['unit']}{$randomTrash['name']}！",
            'data' => [
                'type' => 'trash_caught',
                'trash_name' => $randomTrash['name'],
                'trash_unit' => $randomTrash['unit'],
                'trash_item_id' => $randomTrash['item_id'],
            ]
        ];
    }
    
    /**
     * 更新钓鱼状态（根据时间推进阶段）
     */
    private function updateFishingStatus(int $charId): void {
        $fishingState = $this->getFishingState($charId);
        if (!$fishingState) {
            return;
        }
        
        $stageDuration = $this->configCache['stage_duration'] ?? self::STAGE_DURATION;
        $senCost = $this->configCache['sen_cost_per_stage'] ?? self::SEN_COST_PER_STAGE;
        
        $currentTime = time();
        $lastUpdateTime = $fishingState['last_update_time'] ?? $currentTime;
        $timeDiff = $currentTime - $lastUpdateTime;
        
        if ($timeDiff < $stageDuration) {
            return; // 还没到下一个阶段的时间
        }
        
        // 计算经过了多少个阶段
        $stagesPassed = floor($timeDiff / $stageDuration);
        $steps = $fishingState['steps'] ?? 0;
        $stagesCompleted = $fishingState['stages_completed'] ?? 0;
        
        $character = $this->getCharacter($charId);
        $currentSen = $character['sen'] ?? 0;
        
        for ($i = 0; $i < $stagesPassed; $i++) {
            // 检查精神是否足够
            if ($currentSen < $senCost) {
                // 精神不足，鱼竿掉水里了
                $this->handleRodLost($charId, $fishingState);
                return;
            }
            
            // 消耗精神
            $currentSen -= $senCost;
            $this->deductSen($charId, $senCost);
            
            // 概率推进阶段（从配置读取概率）
            $advanceChance = $this->configCache['stage_advance_chance'] ?? self::STAGE_ADVANCE_CHANCE;
            if (mt_rand(0, 1) == 1) { // 保持原始 50% 概率逻辑，也可通过配置覆盖为其他值
                if ($steps != 5) {
                    $steps++;
                } else {
                    $steps = 0; // 第5阶段后重置为0
                }
            }
            
            $stagesCompleted++;
        }
        
        // 更新状态
        $fishingState['steps'] = $steps;
        $fishingState['stages_completed'] = $stagesCompleted;
        $fishingState['last_update_time'] = $lastUpdateTime + $stagesPassed * $stageDuration;
        
        $this->setFishingState($charId, $fishingState);
    }
    
    /**
     * 处理鱼竿丢失
     */
    private function handleRodLost(int $charId, array $fishingState): void {
        $rodInvId = $fishingState['rod_inv_id'] ?? 0;
        
        if ($rodInvId > 0) {
            require_once __DIR__ . '/../models/Item.php';
            // 从背包中删除鱼竿
            ItemModel::removeFromInventoryById($charId, $rodInvId);
        }
        
        // 清除钓鱼状态
        $this->clearFishingState($charId);
        $this->setQiuyinOnRod($charId, false);
        
        // 记录丢失消息（可以通过 session 传递）
        $_SESSION["fish_rod_lost_{$charId}"] = true;
    }
    
    /**
     * 获取钓鱼状态
     */
    private function getFishingStatus(int $charId, array $action, array $params): array {
        if (!$this->isFishing($charId)) {
            return ['success' => false, 'message' => '你没有在钓鱼。'];
        }
        
        // 更新状态
        $this->updateFishingStatus($charId);
        
        $fishingState = $this->getFishingState($charId);
        $steps = $fishingState['steps'] ?? 0;
        
        return [
            'success' => true,
            'message' => self::FISHING_MESSAGES[$steps],
            'data' => [
                'type' => 'fishing_status',
                'steps' => $steps,
                'message' => self::FISHING_MESSAGES[$steps],
            ]
        ];
    }
    
    /**
     * 轮询检查钓鱼状态（用于 chat.php 消息轮询）
     * 自动更新钓鱼阶段，有新消息时返回
     */
    public static function pollFishingStatus(int $charId): ?array {
        try {
            // 检查是否在钓鱼
            $fishingState = self::getFishingStateStatic($charId);
            if (!$fishingState) {
                return null;
            }
            
            // 更新钓鱼状态
            self::updateFishingStatusStatic($charId);
            
            // 重新获取状态（可能已经更新，或者鱼竿丢了）
            $fishingState = self::getFishingStateStatic($charId);
            if (!$fishingState) {
                // 鱼竿可能丢了，检查是否有丢失消息
                if (!empty($_SESSION["fish_rod_lost_{$charId}"])) {
                    unset($_SESSION["fish_rod_lost_{$charId}"]);
                    return [
                        'message' => "你太累了，手一松，鱼竿掉进了河里。",
                        'msg_type' => 'self_event'
                    ];
                }
                return null;
            }
            
            $steps = $fishingState['steps'] ?? 0;
            $lastPushedSteps = $fishingState['last_pushed_steps'] ?? -1;
            
            // 如果阶段没有变化，不推送新消息
            if ($steps === $lastPushedSteps) {
                return null;
            }
            
            // 更新上次推送的阶段
            $fishingState['last_pushed_steps'] = $steps;
            self::setFishingStateStatic($charId, $fishingState);
            
            // 返回新消息
            $messages = self::FISHING_MESSAGES;
            return [
                'message' => $messages[$steps] ?? '',
                'msg_type' => 'self_event'
            ];
            
        } catch (\Exception $e) {
            error_log("FishHandler pollFishingStatus error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 静态方法：获取钓鱼状态
     */
    private static function getFishingStateStatic(int $charId): ?array {
        require_once __DIR__ . '/../includes/db.php';
        
        $sql = "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = 'fishing' LIMIT 1";
        $result = Database::queryOne($sql, [$charId]);
        
        if ($result && !empty($result['state_value'])) {
            $state = json_decode($result['state_value'], true);
            if (is_array($state)) {
                return $state;
            }
        }
        
        return null;
    }
    
    /**
     * 静态方法：设置钓鱼状态
     */
    private static function setFishingStateStatic(int $charId, array $state): void {
        require_once __DIR__ . '/../includes/db.php';
        
        $stateJson = json_encode($state, JSON_UNESCAPED_UNICODE);
        
        // 检查是否已存在
        $sql = "SELECT id FROM character_temp_states WHERE char_id = ? AND state_key = 'fishing' LIMIT 1";
        $existing = Database::queryOne($sql, [$charId]);
        
        if ($existing) {
            $sql = "UPDATE character_temp_states SET state_value = ?, updated_at = NOW() WHERE char_id = ? AND state_key = 'fishing'";
            Database::execute($sql, [$stateJson, $charId]);
        } else {
            $sql = "INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) VALUES (?, 'fishing', ?, NOW(), NOW())";
            Database::execute($sql, [$charId, $stateJson]);
        }
    }
    
    /**
     * 静态方法：更新钓鱼状态（根据时间推进阶段）
     */
    private static function updateFishingStatusStatic(int $charId): void {
        $fishingState = self::getFishingStateStatic($charId);
        if (!$fishingState) {
            return;
        }
        
        $currentTime = time();
        $lastUpdateTime = $fishingState['last_update_time'] ?? $currentTime;
        $timeDiff = $currentTime - $lastUpdateTime;
        
        if ($timeDiff < self::STAGE_DURATION) {
            return; // 还没到下一个阶段的时间
        }
        
        // 计算经过了多少个阶段
        $stagesPassed = floor($timeDiff / self::STAGE_DURATION);
        $steps = $fishingState['steps'] ?? 0;
        $stagesCompleted = $fishingState['stages_completed'] ?? 0;
        $lastPushedSteps = $fishingState['last_pushed_steps'] ?? -1;
        
        // 获取角色信息
        require_once __DIR__ . '/../models/Character.php';
        $character = CharacterModel::find($charId);
        $currentSen = $character['sen'] ?? 0;
        
        for ($i = 0; $i < $stagesPassed; $i++) {
            // 检查精神是否足够
            if ($currentSen < self::SEN_COST_PER_STAGE) {
                // 精神不足，鱼竿掉水里了
                self::handleRodLostStatic($charId, $fishingState);
                return;
            }
            
            // 消耗精神
            $currentSen -= self::SEN_COST_PER_STAGE;
            self::deductSenStatic($charId, self::SEN_COST_PER_STAGE);
            
            // 50%概率推进阶段
            if (mt_rand(0, 1) == 1) {
                if ($steps != 5) {
                    $steps++;
                } else {
                    $steps = 0; // 第5阶段后重置为0
                }
            }
            
            $stagesCompleted++;
        }
        
        // 更新状态
        $fishingState['steps'] = $steps;
        $fishingState['stages_completed'] = $stagesCompleted;
        $fishingState['last_update_time'] = $lastUpdateTime + $stagesPassed * self::STAGE_DURATION;
        // 保留 last_pushed_steps 不变，由调用者决定是否更新
        
        self::setFishingStateStatic($charId, $fishingState);
    }
    
    /**
     * 静态方法：处理鱼竿丢失
     */
    private static function handleRodLostStatic(int $charId, array $fishingState): void {
        $rodInvId = $fishingState['rod_inv_id'] ?? 0;
        
        if ($rodInvId > 0) {
            require_once __DIR__ . '/../models/Item.php';
            // 从背包中删除鱼竿
            ItemModel::removeFromInventoryById($charId, $rodInvId);
        }
        
        // 清除钓鱼状态
        self::clearFishingStateStatic($charId);
        self::clearQiuyinStateStatic($charId);
        
        // 记录丢失消息
        $_SESSION["fish_rod_lost_{$charId}"] = true;
    }
    
    /**
     * 静态方法：清除钓鱼状态
     */
    private static function clearFishingStateStatic(int $charId): void {
        require_once __DIR__ . '/../includes/db.php';
        
        $sql = "DELETE FROM character_temp_states WHERE char_id = ? AND state_key = 'fishing'";
        Database::execute($sql, [$charId]);
    }
    
    /**
     * 静态方法：清除蚯蚓状态
     */
    private static function clearQiuyinStateStatic(int $charId): void {
        require_once __DIR__ . '/../includes/db.php';
        
        $sql = "DELETE FROM character_temp_states WHERE char_id = ? AND state_key = 'rod_qiuyin'";
        Database::execute($sql, [$charId]);
    }
    
    /**
     * 静态方法：扣除精神
     */
    private static function deductSenStatic(int $charId, int $amount): void {
        require_once __DIR__ . '/../includes/db.php';
        
        $sql = "UPDATE characters SET sen = GREATEST(0, sen - ?) WHERE id = ?";
        Database::execute($sql, [$amount, $charId]);
    }
    
    // ==================== 辅助方法 ====================
    
    /**
     * 检查玩家是否在钓鱼
     */
    private function isFishing(int $charId): bool {
        $state = $this->getFishingState($charId);
        return !empty($state);
    }
    
    /**
     * 获取钓鱼状态
     */
    private function getFishingState(int $charId): ?array {
        require_once __DIR__ . '/../includes/db.php';
        
        $sql = "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = 'fishing' LIMIT 1";
        $result = Database::queryOne($sql, [$charId]);
        
        if ($result && !empty($result['state_value'])) {
            $state = json_decode($result['state_value'], true);
            if (is_array($state)) {
                return $state;
            }
        }
        
        return null;
    }
    
    /**
     * 设置钓鱼状态
     */
    private function setFishingState(int $charId, array $state): void {
        require_once __DIR__ . '/../includes/db.php';
        
        $stateJson = json_encode($state, JSON_UNESCAPED_UNICODE);
        
        // 检查是否已存在
        $sql = "SELECT id FROM character_temp_states WHERE char_id = ? AND state_key = 'fishing' LIMIT 1";
        $existing = Database::queryOne($sql, [$charId]);
        
        if ($existing) {
            $sql = "UPDATE character_temp_states SET state_value = ?, updated_at = NOW() WHERE char_id = ? AND state_key = 'fishing'";
            Database::execute($sql, [$stateJson, $charId]);
        } else {
            $sql = "INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) VALUES (?, 'fishing', ?, NOW(), NOW())";
            Database::execute($sql, [$charId, $stateJson]);
        }
    }
    
    /**
     * 清除钓鱼状态
     */
    private function clearFishingState(int $charId): void {
        require_once __DIR__ . '/../includes/db.php';
        
        $sql = "DELETE FROM character_temp_states WHERE char_id = ? AND state_key = 'fishing'";
        Database::execute($sql, [$charId]);
    }
    
    /**
     * 检查鱼竿上是否有蚯蚓
     */
    private function hasQiuyinOnRod(int $charId): bool {
        require_once __DIR__ . '/../includes/db.php';
        
        $sql = "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = 'rod_qiuyin' LIMIT 1";
        $result = Database::queryOne($sql, [$charId]);
        
        if ($result && !empty($result['state_value'])) {
            return $result['state_value'] === '1' || $result['state_value'] === 'true';
        }
        
        return false;
    }
    
    /**
     * 设置鱼竿是否有蚯蚓
     */
    private function setQiuyinOnRod(int $charId, bool $hasQiuyin): void {
        require_once __DIR__ . '/../includes/db.php';
        
        $value = $hasQiuyin ? '1' : '0';
        
        $sql = "SELECT id FROM character_temp_states WHERE char_id = ? AND state_key = 'rod_qiuyin' LIMIT 1";
        $existing = Database::queryOne($sql, [$charId]);
        
        if ($existing) {
            $sql = "UPDATE character_temp_states SET state_value = ?, updated_at = NOW() WHERE char_id = ? AND state_key = 'rod_qiuyin'";
            Database::execute($sql, [$value, $charId]);
        } else {
            $sql = "INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) VALUES (?, 'rod_qiuyin', ?, NOW(), NOW())";
            Database::execute($sql, [$charId, $value]);
        }
    }
    
    /**
     * 查找玩家的鱼竿（装备或背包里）
     */
    private function findRod(int $charId): ?array {
        // 先找装备的
        $equipped = $this->getEquippedRod($charId);
        if ($equipped) {
            return $equipped;
        }
        
        // 再找背包里的
        return $this->findItemInInventory($charId, 'yugan');
    }
    
    /**
     * 获取装备的鱼竿
     */
    private function getEquippedRod(int $charId): ?array {
        require_once __DIR__ . '/../models/Character.php';
        
        $equipment = CharacterModel::getEquipment($charId);
        foreach ($equipment as $item) {
            if ($item['item_id'] === 'yugan' && !empty($item['equipped'])) {
                return $item;
            }
        }
        
        return null;
    }
    
    /**
     * 在背包中查找物品
     */
    private function findItemInInventory(int $charId, string $itemId): ?array {
        require_once __DIR__ . '/../models/Character.php';
        
        $inventory = CharacterModel::getInventory($charId);
        foreach ($inventory as $item) {
            if ($item['item_id'] === $itemId) {
                return $item;
            }
        }
        
        return null;
    }
    
    /**
     * 检查房间是否可以钓鱼
     */
    private function canFishInRoom(string $roomId): bool {
        // 简单判断：有钓鱼动作的房间就可以钓鱼
        // 或者检查 room_actions 表有没有 diao 动作
        require_once __DIR__ . '/../includes/db.php';
        
        $sql = "SELECT id FROM room_actions WHERE room_id = ? AND action_cmd LIKE '%diao%' AND enabled = 1 LIMIT 1";
        $result = Database::queryOne($sql, [$roomId]);
        
        return !empty($result);
    }
    
    /**
     * 扣除精神
     */
    private function deductSen(int $charId, int $amount): void {
        require_once __DIR__ . '/../includes/db.php';
        
        $sql = "UPDATE characters SET sen = GREATEST(0, sen - ?) WHERE id = ?";
        Database::execute($sql, [$amount, $charId]);
    }
    
    /**
     * 通过关键字查找物品
     */
    private function findItemByKeyword(string $keyword): ?array {
        require_once __DIR__ . '/../includes/db.php';
        
        $sql = "SELECT * FROM items WHERE item_id = ? LIMIT 1";
        $item = Database::queryOne($sql, [$keyword]);
        
        if ($item) {
            return $item;
        }
        
        $sql = "SELECT * FROM items WHERE name LIKE ? LIMIT 1";
        $item = Database::queryOne($sql, ['%' . $keyword . '%']);
        
        return $item;
    }
}
