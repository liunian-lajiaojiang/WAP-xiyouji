<?php
/**
 * 灭妖任务处理器
 * 完整实现原始项目的灭妖任务系统
 * 
 * 双入口设计：
 * - 入口一：袁天罡（人间·长安城天监台）- 新手 ≤50000
 * - 入口二：李靖（天廷·云楼台）- 高级玩家，无等级限制
 */

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/MieyaoYaoguai.php';
require_once __DIR__ . '/../helpers/RankHelper.php';
require_once __DIR__ . '/../helpers/CorpseHelper.php';
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../models/Corpse.php';
require_once __DIR__ . '/../daemons/MessageDaemon.php';

class MieyaoHandler extends ActionHandler {
    
    // 任务超时时间：12分钟=720秒
    const TASK_TIMEOUT = 720;
    
    // 经验门槛（袁天罡入口上限）
    const YUAN_MAX_POWER = 50000;

    /**
     * 默认配置
     */
    public function getDefaultConfig(): array {
        return [
            'max_power_threshold' => self::YUAN_MAX_POWER,
            'npc_name' => 'yuantiangang', // 默认 NPC 标识
        ];
    }

    /**
     * 获取配置（带缓存）
     */
    private function getMieyaoConfig(array $action): array {
        static $cache = null;
        if ($cache === null) {
            $cache = $this->mergeConfig($this->getDefaultConfig(), $this->parseConfig($action));
        }
        return $cache;
    }

    /**
     * 执行灭妖任务（路由到对应 NPC 入口）
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在'];
            }

            $cfg = $this->getMieyaoConfig($action);
            $npcName = $cfg['npc_name'] ?? 'yuantiangang';

            // 根据 NPC 类型路由
            if ($npcName === 'litianwang' || $npcName === 'lijing') {
                return $this->executeLijing($charId, $character);
            }
            
            return $this->executeYuantiangang($charId, $character, $cfg);
            
        } catch (\Exception $e) {
            error_log("MieyaoHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '灭妖任务执行失败: ' . $e->getMessage()];
        }
    }

    /**
     * 袁天罡入口：新手灭妖（经验门槛 ≤50000）
     * 还原原始项目 yuantiangang.c 逻辑
     */
    private function executeYuantiangang(int $charId, array $character, array $cfg): array {
        $dx = intval($character['daoxing'] ?? 0);
        $exp = intval($character['combat_exp'] ?? 0);
        $total = ($dx + $exp) / 2;
        
        if ($total > $cfg['max_power_threshold']) {
            $this->broadcastToRoom(
                $character['current_room'],
                HIY . "袁天罡对" . $character['name'] . "哈哈一笑，说道：这位" . RankHelper::queryRespect($character) . "不必去捉妖了。" . NOR,
                $charId
            );
            
            return [
                'success' => true,
                'message' => "袁天罡对你哈哈一笑，说道：这位" . RankHelper::queryRespect($character) . "不必去捉妖了。"
            ];
        }

        // 袁天罡使用人间版妖怪（isHeaven=false）
        return $this->startJob($charId, $character, false, 'yuantiangang');
    }

    /**
     * 李靖入口：高级灭妖（天廷·云楼台，无等级限制）
     * 还原原始项目 litianwang.c 逻辑
     */
    private function executeLijing(int $charId, array $character): array {
        // 李靖没有等级限制，任何到达云楼台的玩家都可以接任务
        return $this->startJob($charId, $character, true, 'litianwang');
    }
    
    /**
     * 开始灭妖任务
     * @param bool $isHeaven 是否天廷入口（影响妖怪强度和区域）
     * @param string $npcKey NPC标识（用于消息文本）
     */
    private function startJob(int $charId, array $character, bool $isHeaven = false, string $npcKey = 'yuantiangang'): array {
        // 获取当前任务等级
        $stateKey = $isHeaven ? 'mieyao_level' : 'mieyao_level';
        $level = $this->getTaskLevel($charId, $stateKey);
        
        // NPC 名称映射
        $npcNames = [
            'yuantiangang' => '袁天罡',
            'litianwang' => '李靖',
            'lijing' => '李靖',
        ];
        $npcName = $npcNames[$npcKey] ?? '袁天罡';
        
        // 检查是否有未完成的任务
        $existingTask = $this->getActiveTask($charId);
        if ($existingTask) {
            // 如果任务还没过期
            if (strtotime($existingTask['expires_at']) > time()) {
                return [
                    'success' => true,
                    'message' => $npcName . "冷哼一声，说道：你不是已经去寻找" . $existingTask['npc_name'] . "了吗？"
                ];
            }
            // 任务过期了，降低等级
            if ($level > 0) {
                $level--;
                $this->updateTaskLevel($charId, $level, $stateKey);
            }
            // 清理过期任务
            $this->cleanUpExpiredTasks($charId);
        }

        // 生成妖怪（传入 isHeaven 参数）
        $yaoguai = MieyaoYaoguai::calculateAttributes($character, $level, $isHeaven);
        if (!$yaoguai['room']) {
            return ['success' => false, 'message' => '暂时无法生成妖怪，请稍后再试。'];
        }

        // 保存妖怪到数据库（含技能/法术/装备信息）
        $yaoguaiId = $this->saveYaoguai($charId, $yaoguai);
        
        // 保存任务状态
        $this->setTaskStart($charId, $yaoguai['name'], $level, $stateKey, $isHeaven);

        // 获取地点描述
        $placeDesc = $this->getPlaceDescription($yaoguai['room']);

        // 广播消息（天廷入口用不同文本）
        if ($isHeaven) {
            $this->broadcastToRoom(
                $character['current_room'],
                HIY . "李靖取出照妖镜向" . $character['name'] . "一晃。" . NOR,
                $charId
            );
            $message = "李靖取出照妖镜向你一晃，说道：最近" . $placeDesc . "出现了一个叫做" . $yaoguai['name'] . "(" . ($yaoguai['title'] ?? '') . ")的妖怪，你去把它除掉吧！";
        } else {
            $this->broadcastToRoom(
                $character['current_room'],
                HIY . "袁天罡在" . $character['name'] . "耳边低声说了几句话。" . NOR,
                $charId
            );
            $message = "袁天罡在你耳边低声说道：最近" . $placeDesc . "出现了一个叫做" . $yaoguai['name'] . "(" . ($yaoguai['title'] ?? '') . ")的妖怪，你快去把他除掉吧！";
        }

        return [
            'success' => true,
            'message' => $message
        ];
    }

    /**
     * 获取任务等级
     */
    private function getTaskLevel(int $charId, string $stateKey = 'mieyao_level'): int {
        $state = Database::queryOne(
            "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?",
            [$charId, $stateKey]
        );

        if ($state && !empty($state['state_value'])) {
            return intval($state['state_value']);
        }
        return 0;
    }

    /**
     * 更新任务等级
     */
    private function updateTaskLevel(int $charId, int $level, string $stateKey = 'mieyao_level'): void {
        Database::execute(
            "INSERT INTO character_temp_states (char_id, state_key, state_value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE state_value = ?",
            [$charId, $stateKey, $level, $level]
        );
    }

    /**
     * 获取活跃任务
     */
    private function getActiveTask(int $charId): ?array {
        return Database::queryOne(
            "SELECT * FROM mieyao_yaoguai WHERE owner_id = ? AND is_killed = 0",
            [$charId]
        );
    }

    /**
     * 保存妖怪（增强版：含技能/法术/装备）
     */
    private function saveYaoguai(int $charId, array $yaoguai): int {
        $expiresAt = date('Y-m-d H:i:s', time() + self::TASK_TIMEOUT);

        // 序列化技能/法术/装备数据
        $skillsJson = json_encode($yaoguai['skills'] ?? [], JSON_UNESCAPED_UNICODE);
        $mappingsJson = json_encode($yaoguai['skill_mappings'] ?? [], JSON_UNESCAPED_UNICODE);
        $spellsJson = json_encode($yaoguai['cast_spells'] ?? [], JSON_UNESCAPED_UNICODE);
        $exertsJson = json_encode($yaoguai['exert_funcs'] ?? [], JSON_UNESCAPED_UNICODE);
        $performsJson = json_encode($yaoguai['perform_actions'] ?? [], JSON_UNESCAPED_UNICODE);
        $weaponJson = json_encode([
            'type' => $yaoguai['weapon_type'] ?? '',
            'id' => $yaoguai['weapon_id'] ?? '',
        ], JSON_UNESCAPED_UNICODE);

        $sql = "INSERT INTO mieyao_yaoguai 
                (npc_name, npc_id, title, daoxing, combat_exp, max_kee, max_sen, 
                 current_kee, current_sen, exp_reward, pot_reward, daoxing_reward, area, room_id, 
                 owner_id, monster_type, face, expires_at,
                 skills_json, skill_mappings_json, cast_spells_json, exert_funcs_json,
                 perform_actions_json, weapon_json, cast_chance, monster_type_key, is_heaven)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        Database::execute($sql, [
            $yaoguai['name'],
            $yaoguai['id'],
            $yaoguai['title'] ?? '',
            $yaoguai['daoxing'],
            $yaoguai['combat_exp'],
            $yaoguai['max_kee'],
            $yaoguai['max_sen'],
            $yaoguai['max_kee'],
            $yaoguai['max_sen'],
            $yaoguai['exp_reward'],
            $yaoguai['pot_reward'],
            $yaoguai['daoxing_reward'] ?? 0,
            $yaoguai['room']['area'],
            $yaoguai['room']['room_id'],
            $charId,
            $yaoguai['type'],
            $yaoguai['face'],
            $expiresAt,
            $skillsJson,
            $mappingsJson,
            $spellsJson,
            $exertsJson,
            $performsJson,
            $weaponJson,
            $yaoguai['cast_chance'] ?? 10,
            $yaoguai['monster_type_key'] ?? '',
            $yaoguai['is_heaven'] ? 1 : 0,
        ]);

        return Database::lastInsertId();
    }

    /**
     * 设置任务开始
     */
    private function setTaskStart(int $charId, string $yaoguaiName, int $level, string $stateKey = 'mieyao_task', bool $isHeaven = false): void {
        $data = json_encode([
            'name' => $yaoguaiName,
            'start_time' => time(),
            'level' => $level,
            'is_heaven' => $isHeaven,
        ]);

        Database::execute(
            "INSERT INTO character_temp_states (char_id, state_key, state_value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE state_value = ?",
            [$charId, $stateKey, $data, $data]
        );
    }

    /**
     * 清理过期任务
     */
    private function cleanUpExpiredTasks(int $charId): void {
        Database::execute(
            "DELETE FROM mieyao_yaoguai WHERE owner_id = ? AND expires_at < NOW()",
            [$charId]
        );
    }

    /**
     * 获取地点描述
     */
    private function getPlaceDescription(array $room): string {
        $areaNames = [
            'city' => '长安城',
            'westway' => '城西大道',
            'kaifeng' => '开封府',
            'lingtai' => '灵台方寸',
            'moon' => '月宫',
            'gao' => '高老庄',
            'sea' => '东海',
            'nanhai' => '南海',
            'eastway' => '城东大道',
            'xueshan' => '大雪山',
            'wuzhuang' => '五庄观',
            'death' => '地府',
            'meishan' => '梅山',
        ];

        $area = $room['area'];
        $areaName = $areaNames[$area] ?? $area;

        return $areaName . '一带';
    }

    /**
     * 处理妖怪被击杀
     */
    public static function handleKillYaoguai(int $yaoguaiId, int $killerId): array {
        $yaoguai = Database::queryOne(
            "SELECT * FROM mieyao_yaoguai WHERE id = ?",
            [$yaoguaiId]
        );

        if (!$yaoguai || $yaoguai['is_killed']) {
            return ['success' => false, 'message' => '妖怪已不存在'];
        }

        // 获取击杀者名称
        $killerName = '';
        $killer = CharacterModel::find($killerId);
        if ($killer) {
            $killerName = $killer['name'];
        }

        // 生成妖怪尸体
        $corpseId = Corpse::createNpcCorpse(
            $yaoguaiId,
            $yaoguai['npc_name'],
            $yaoguai['area'],
            $yaoguai['room_id'],
            $killerId,
            $killerName
        );

        // 为妖怪尸体生成掉落物品（增强版装备掉落）
        self::dropYaoguaiItems($corpseId, $yaoguai);

        // AI 自动拾取货币（白银/黄金/铜钱），装备留在尸体中供玩家手动拾取
        // 用 try-catch 包裹，防止拾取异常影响击杀主流程
        try {
            CorpseHelper::lootCorpseCurrency($killerId, $corpseId);
        } catch (\Throwable $e) {
            error_log("CorpseHelper::lootCorpseCurrency failed: " . $e->getMessage());
        }

        // 标记为已杀
        Database::execute(
            "UPDATE mieyao_yaoguai SET is_killed = 1, killed_at = NOW() WHERE id = ?",
            [$yaoguaiId]
        );

        // 如果是任务发布的主人，给奖励
        if ($yaoguai['owner_id'] == $killerId) {
            $character = CharacterModel::find($killerId);
            if ($character) {
                // 获取任务等级和入口类型
                $level = 0;
                $isHeaven = false;
                $stateKey = 'mieyao_task';
                $levelStateKey = 'mieyao_level';
                
                $taskState = Database::queryOne(
                    "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?",
                    [$killerId, $stateKey]
                );
                if ($taskState && !empty($taskState['state_value'])) {
                    $taskData = json_decode($taskState['state_value'], true);
                    $level = $taskData['level'] ?? 0;
                    $isHeaven = !empty($taskData['is_heaven']);
                }

                // 计算奖励折扣（原始项目逻辑）
                $maxKee = $yaoguai['max_kee'] ?? 1;
                $otherKee = $yaoguai['other_kee'] ?? 0;
                
                $ratio = 100 * ($maxKee - $otherKee) / $maxKee;
                if ($ratio < 0) $ratio = 0;
                if ($ratio > 100) $ratio = 100;

                // 应用折扣后的奖励
                $expReward = intval($yaoguai['exp_reward'] * $ratio / 100);
                $potReward = intval($yaoguai['pot_reward'] * $ratio / 100);
                $daoxingReward = intval(($yaoguai['daoxing_reward'] ?? 0) * $ratio / 100);

                // 确保最低奖励
                if ($expReward < 1) $expReward = 1;
                if ($potReward < 1) $potReward = 1;
                if ($daoxingReward < 1) $daoxingReward = 1;

                Database::execute(
                    "UPDATE characters SET combat_exp = combat_exp + ?, potential = potential + ?, daoxing = daoxing + ? WHERE id = ?",
                    [$expReward, $potReward, $daoxingReward, $killerId]
                );

                // 增加等级（如果小于9级）
                if ($level < 9) {
                    $level++;
                } else {
                    // 天廷版9级后回1，人间版9级后回5
                    $level = $isHeaven ? 1 : 5;
                }

                Database::execute(
                    "INSERT INTO character_temp_states (char_id, state_key, state_value) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE state_value = ?",
                    [$killerId, $levelStateKey, $level, $level]
                );

                $message = "你杀死了" . $yaoguai['npc_name'] . "！";
                
                // 等级9完成且ratio>50时，奖励技能（还原原始项目 give_reward 逻辑）
                $skillRewardMsg = '';
                if ($level == 9 && $ratio > 50) {
                    $skillReward = self::giveSkillReward($killerId);
                    if ($skillReward) {
                        $skillRewardMsg = " 并获得了「{$skillReward}」技能提升！";
                    }
                }

                $rewardMsg = "获得了" . $expReward . "点实战经验、" . $potReward . "点潜能和" . $daoxingReward . "年道行！";
                if ($ratio < 100) {
                    $rewardMsg = "(因他人协助，奖励打" . intval($ratio) . "折) " . $rewardMsg;
                }
                
                MessageDaemon::sendRoomMessage($killerId, $message . $rewardMsg . $skillRewardMsg, 'room');

                return [
                    'success' => true,
                    'message' => $message . $rewardMsg . $skillRewardMsg
                ];
            }
        }

        return ['success' => true, 'message' => '你杀死了' . $yaoguai['npc_name'] . '！'];
    }

    /**
     * 为妖怪尸体生成掉落物品（增强版：完整装备系统）
     * 还原原始项目装备掉落机制 + 扩展的品质装备体系
     */
    private static function dropYaoguaiItems(int $corpseId, array $yaoguai): void {
        $daoxing = $yaoguai['daoxing'] ?? 0;
        $isHeaven = !empty($yaoguai['is_heaven']);
        $monsterTypeKey = $yaoguai['monster_type_key'] ?? '';

        // 基础掉落：白银
        $silverDrop = mt_rand(0, 10);
        Corpse::addItem($corpseId, [
            'item_id' => 'silver',
            'item_name' => '白银',
            'quantity' => intval($silverDrop),
            'item_type' => 'currency'
        ]);

        // ===== 装备掉落系统（还原原始项目品质体系） =====
        $equipmentDrop = self::getEquipmentDrop($daoxing, $isHeaven, $monsterTypeKey);
        foreach ($equipmentDrop as $item) {
            Corpse::addItem($corpseId, $item);
        }

        // 食物类掉落
        if (mt_rand(1, 100) <= 40) {
            $foods = ['baozi', 'huasheng', 'zongzi', 'jiudai', 'mooncake'];
            $foodNames = ['包子', '花生豆', '粽子', '酒袋', '月饼'];
            $idx = array_rand($foods);

            Corpse::addItem($corpseId, [
                'item_id' => $foods[$idx],
                'item_name' => $foodNames[$idx],
                'quantity' => mt_rand(1, 4),
                'item_type' => 'food'
            ]);
        }
    }

    /**
     * 获取装备掉落列表（根据妖怪道行和类型决定品质）
     * 还原原始项目装备品质体系：木→铁→铜→钢→银→金→宝→神→仙→圣→天→魔
     */
    private static function getEquipmentDrop(int $daoxing, bool $isHeaven, string $monsterTypeKey): array {
        $drops = [];
        $baseChance = $isHeaven ? 35 : 20; // 天廷版更高掉落率

        // 确定品质等级
        $qualityTier = self::getQualityTier($daoxing);
        $qualityPrefixes = [
            1 => 'mu',      // 木制
            2 => 'tie',     // 铁制
            3 => 'tong',    // 铜制
            4 => 'gang',    // 钢制
            5 => 'yin',     // 银制
            6 => 'jin',     // 金制
            7 => 'bao',     // 宝制
            8 => 'shen',    // 神制
            9 => 'xian',    // 仙制
            10 => 'sheng',  // 圣制
            11 => 'tian',   // 天制
            12 => 'mo',     // 魔制
        ];
        $prefix = $qualityPrefixes[$qualityTier] ?? 'mu';

        // 武器掉落
        if (mt_rand(1, 100) <= $baseChance) {
            $weapon = self::getRandomWeapon($prefix, $monsterTypeKey);
            if ($weapon) {
                $drops[] = $weapon;
            }
        }

        // 护甲掉落（道行 > 200 开始）
        if ($daoxing > 200 && mt_rand(1, 100) <= $baseChance - 5) {
            $armor = self::getRandomArmor($prefix);
            if ($armor) {
                $drops[] = $armor;
            }
        }

        return $drops;
    }

    /**
     * 根据道行确定装备品质等级
     * 品质分12级：木(1)→铁(2)→铜(3)→钢(4)→银(5)→金(6)→宝(7)→神(8)→仙(9)→圣(10)→天(11)→魔(12)
     */
    private static function getQualityTier(int $daoxing): int {
        if ($daoxing <= 50) return 1;       // 木制
        if ($daoxing <= 200) return 2;      // 铁制
        if ($daoxing <= 500) return 3;      // 铜制
        if ($daoxing <= 1000) return 4;     // 钢制
        if ($daoxing <= 3000) return 5;     // 银制
        if ($daoxing <= 8000) return 6;     // 金制
        if ($daoxing <= 20000) return 7;    // 宝制
        if ($daoxing <= 50000) return 8;    // 神制
        if ($daoxing <= 100000) return 9;   // 仙制
        if ($daoxing <= 200000) return 10;  // 圣制
        if ($daoxing <= 500000) return 11;  // 天制
        return 12;                           // 魔制
    }

    /**
     * 随机获取武器（根据品质前缀和妖怪类型）
     */
    private static function getRandomWeapon(string $prefix, string $monsterTypeKey): ?array {
        // 妖怪门派对应的武器偏好
        $typeWeapons = [
            'dragon'   => ['fork', 'hammer'],
            'fangcun'  => ['stick'],
            'hell'     => ['whip', 'stick'],
            'jjf'      => ['spear', 'axe'],
            'moon'     => ['whip', 'sword'],
            'putuo'    => ['staff'],
            'wudidong' => ['blade', 'sword'],
            'wzg'      => ['hammer', 'sword'],
            'xueshan'  => ['blade', 'sword'],
        ];

        $weaponTypes = $typeWeapons[$monsterTypeKey] ?? ['sword', 'blade', 'stick'];
        $weaponType = $weaponTypes[array_rand($weaponTypes)];

        // 武器类型中文名
        $typeNames = [
            'sword' => '剑', 'blade' => '刀', 'stick' => '棒', 'staff' => '杖',
            'whip' => '鞭', 'hammer' => '锤', 'fork' => '叉', 'spear' => '枪',
            'axe' => '斧',
        ];

        $qualityNames = [
            'mu' => '木', 'tie' => '铁', 'tong' => '铜', 'gang' => '钢',
            'yin' => '银', 'jin' => '金', 'bao' => '宝', 'shen' => '神',
            'xian' => '仙', 'sheng' => '圣', 'tian' => '天', 'mo' => '魔',
        ];

        $typeName = $typeNames[$weaponType] ?? '剑';
        $qualityName = $qualityNames[$prefix] ?? '';
        $itemId = $prefix . $weaponType;
        $itemName = $qualityName . $typeName;

        return [
            'item_id' => $itemId,
            'item_name' => $itemName,
            'quantity' => 1,
            'item_type' => 'weapon',
            'equipment_slot' => 'weapon',
            'weapon_type' => $weaponType,
            'quality' => $prefix,
        ];
    }

    /**
     * 随机获取护甲（根据品质前缀）
     */
    private static function getRandomArmor(string $prefix): ?array {
        $armorTypes = [
            ['id' => 'dun', 'name' => '盾', 'slot' => 'shield'],
            ['id' => 'jia', 'name' => '甲', 'slot' => 'body'],
            ['id' => 'kui', 'name' => '盔', 'slot' => 'head'],
            ['id' => 'xue', 'name' => '靴', 'slot' => 'feet'],
        ];

        $armorType = $armorTypes[array_rand($armorTypes)];

        $qualityNames = [
            'mu' => '木', 'tie' => '铁', 'tong' => '铜', 'gang' => '钢',
            'yin' => '银', 'jin' => '金', 'bao' => '宝', 'shen' => '神',
            'xian' => '仙', 'sheng' => '圣', 'tian' => '天', 'mo' => '魔',
        ];

        $qualityName = $qualityNames[$prefix] ?? '';
        $itemId = $prefix . $armorType['id'];
        $itemName = $qualityName . $armorType['name'];

        return [
            'item_id' => $itemId,
            'item_name' => $itemName,
            'quantity' => 1,
            'item_type' => 'armor',
            'equipment_slot' => $armorType['slot'],
            'quality' => $prefix,
        ];
    }

    /**
     * 获取房间中的妖怪
     */
    public static function getRoomYaoguai(string $area, string $roomId): array {
        return Database::queryAll(
            "SELECT * FROM mieyao_yaoguai WHERE area = ? AND room_id = ? AND is_killed = 0 AND expires_at > NOW()",
            [$area, $roomId]
        );
    }

    /**
     * 放弃灭妖任务
     */
    public static function abandonTask(int $charId): array {
        // 获取当前活跃的任务
        $yaoguai = Database::queryOne(
            "SELECT * FROM mieyao_yaoguai WHERE owner_id = ? AND is_killed = 0",
            [$charId]
        );

        if (!$yaoguai) {
            return ['success' => false, 'message' => '你当前没有灭妖任务！'];
        }

        // 删除妖怪记录
        Database::execute(
            "DELETE FROM mieyao_yaoguai WHERE id = ?",
            [$yaoguai['id']]
        );

        // 降低任务等级
        $level = 0;
        $taskState = Database::queryOne(
            "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = 'mieyao_level'",
            [$charId]
        );
        if ($taskState && !empty($taskState['state_value'])) {
            $level = intval($taskState['state_value']);
            if ($level > 0) {
                $level--;
            }
        }

        Database::execute(
            "INSERT INTO character_temp_states (char_id, state_key, state_value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE state_value = ?",
            [$charId, 'mieyao_level', $level, $level]
        );

        return [
            'success' => true,
            'message' => "你放弃了灭妖任务！"
        ];
    }

    /**
     * 等级9完成任务时奖励技能
     * 从玩家已学会的技能中随机选择一个提升等级
     * 还原原始项目 yaoguai_reward.c::give_reward() 概率递减逻辑：
     * - skill < 70: 100% +1
     * - skill 70-139: 50% +1
     * - skill 140-209: 25% +1
     * - skill >= 210: ~16.7% +1
     */
    private static function giveSkillReward(int $charId): ?string {
        // 获取玩家已学会的技能
        $skills = Database::queryAll(
            "SELECT s.id, s.name, cs.level 
             FROM character_skills cs 
             JOIN skills s ON cs.skill_id = s.id 
             WHERE cs.char_id = ? AND cs.level > 0
             ORDER BY cs.level ASC",
            [$charId]
        );

        if (empty($skills)) {
            return null;
        }

        // 随机选择一个技能
        $skill = $skills[array_rand($skills)];
        $currentLevel = intval($skill['level']);

        // 概率递减判定（还原原始项目逻辑）
        $chance = 100;
        if ($currentLevel >= 210) {
            $chance = 17;  // ~16.7%
        } elseif ($currentLevel >= 140) {
            $chance = 25;
        } elseif ($currentLevel >= 70) {
            $chance = 50;
        }

        if (mt_rand(1, 100) > $chance) {
            return null; // 概率判定失败，不提升
        }

        // 提升技能等级
        Database::execute(
            "UPDATE character_skills SET level = level + 1 WHERE char_id = ? AND skill_id = ?",
            [$charId, $skill['id']]
        );

        return $skill['name'];
    }
}

