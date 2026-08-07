<?php
/**
 * 大闹天宫任务处理器 (DntgQuestHandler)
 * 
 * 10关线性任务系统：
 * 从花果山称王到大闹天宫，重走齐天大圣之路。
 * 
 * 集成点：
 * - functions/room.php: 进入房间时调用 checkAndTrigger()
 * - commands/hit.php: 击杀NPC后调用 onNpcKilled()
 * - commands/ask.php: 与NPC对话后调用 checkInteraction()
 * - room.php 特殊动作: 交互关卡通过 executeAction 调用
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../models/Item.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/MessageDaemon.php';
require_once __DIR__ . '/CombatDaemon.php';

class DntgQuestHandler {
    
    /**
     * 10关配置
     * stage => 配置数组
     * 
     * 配置说明：
     * - announce_npc: 完成时模拟出现的NPC名称
     * - announce_room_msg: 房间内NPC宣布的消息
     * - announce_player_msg: 发送给玩家的个人消息
     * - announce_global: 是否全服公告
     * - random_daoxing: 道行奖励是否启用随机加成（格式：max_bonus）
     */
    private static array $stages = [
        0 => [
            'stage_name'    => '第一关：花果山称王',
            'trigger_type'  => 'arrive',
            'trigger_room'  => 'dntg/hgs/dongnei',
            'target_type'   => 'combat',
            'target_npc'    => '混世魔王',
            'hint'          => '混世魔王端坐虎皮椅上，冷笑道："就凭你也想当这水帘洞的主人？先过了我这一关！"',
            'broadcast'     => '{name}闯入水帘洞内，与混世魔王对峙！一场称王之战即将打响！',
            // 马元帅NPC互动配置
            'announce_npc'  => '马元帅',
            'announce_room_msg' => '马元帅搬来一块石碑来。',
            'announce_player_msg' => '马元帅指着$N对大家说道：此人正是大圣爷麾下最厉害的一位。',
            'announce_global' => true,  // 全服公告
            'announce_global_msg' => '{name}挑战了混世魔王，夺了水帘洞的猴王称号！此功堪比天降大任！',
            'announce_global_msg2' => '从此后该{name}为花果山的当家伙伴才可！',
            'rewards'       => [
                'daoxing'    => 3000,
                'random_daoxing_max' => 500,  // 随机加成最大499（原版 random(500)）
                'items'      => [
                    ['item_id' => 'shipan', 'item_name' => '石盘', 'quantity' => 1],
                    ['item_id' => 'mihoutao', 'item_name' => '猕猴桃', 'quantity' => 10],
                ],
            ],
        ],
        1 => [
            'stage_name'    => '第二关：龙宫借宝',
            'trigger_type'  => 'arrive',
            'trigger_room'  => 'donghai/longgong',
            'target_type'   => 'dialog',
            'target_npc'    => '龙王',
            'interaction'   => 'ask',
            'hint'          => '你来到东海龙宫，龙王见你气度不凡。试着向龙王询问"兵器"。',
            'broadcast'     => '{name}来到东海龙宫，向龙王借取神兵！',
            'rewards'       => [
                'combat_exp' => 300,
                'item_id'    => 'jingubang',
                'item_name'  => '金箍棒',
            ],
        ],
        2 => [
            'stage_name'    => '第三关：地府除名',
            'trigger_type'  => 'arrive',
            'trigger_room'  => 'death/yanluodian',
            'target_type'   => 'combat',
            'target_npc'    => '阎罗王',
            'hint'          => '阎罗王怒道："大胆狂徒，竟敢擅闯地府！"',
            'broadcast'     => '{name}闯入地府，与阎罗王对峙！',
            'rewards'       => [
                'combat_exp' => 800,
                'daoxing'    => 200,
            ],
        ],
        3 => [
            'stage_name'    => '第四关：初闯南天',
            'trigger_type'  => 'arrive',
            'trigger_room'  => 'dntg/sky/nantian',
            'target_type'   => 'combat',
            'target_npc'    => '魔礼青',
            'hint'          => '增长天王魔礼青手持青光宝剑，喝道："妖猴休得放肆！"',
            'broadcast'     => '{name}杀到南天门，与增长天王魔礼青展开激战！',
            'rewards'       => [
                'combat_exp' => 1000,
            ],
            'extra_reward_desc' => '你获得了进入天宫内部的许可！',
        ],
        4 => [
            'stage_name'    => '第五关：齐天大圣',
            'trigger_type'  => 'arrive',
            'trigger_room'  => 'dntg/sky/dadao3',
            'target_type'   => 'combat',
            'target_npc'    => '托塔天王',
            'hint'          => '托塔李天王手持玲珑宝塔，怒目而视："妖猴，今日便是你的死期！"',
            'broadcast'     => '{name}在天宫大道上遭遇托塔天王李靖！',
            'rewards'       => [
                'combat_exp' => 1500,
                'title'      => '齐天大圣',
            ],
        ],
        5 => [
            'stage_name'    => '第六关：蟠桃园',
            'trigger_type'  => 'arrive',
            'trigger_room'  => 'dntg/sky/pantaoyuan',
            'target_type'   => 'interaction',
            'interaction'   => 'pick_peach',
            'hint'          => '蟠桃园中仙桃累累，三千年一熟的、六千年一熟的、九千年一熟的……',
            'broadcast'     => '{name}潜入蟠桃园，目光落在那些诱人的仙桃上……',
            'rewards'       => [
                'restore_all' => true,
            ],
            'action_name'   => '偷桃',
            'action_cmd'    => 'dntg_pick_peach',
        ],
        6 => [
            'stage_name'    => '第七关：搅乱蟠桃会',
            'trigger_type'  => 'arrive',
            'trigger_room'  => 'dntg/sky/yaochi',
            'target_type'   => 'interaction',
            'interaction'   => 'disturb',
            'hint'          => '瑶池之上，仙乐飘飘，众仙正在筹备蟠桃盛会。你心生一计……',
            'broadcast'     => '{name}来到瑶池，眼中闪过一丝狡黠的光芒。',
            'rewards'       => [
                'combat_exp' => 1000,
            ],
            'action_name'   => '搅乱蟠桃会',
            'action_cmd'    => 'dntg_disturb',
        ],
        7 => [
            'stage_name'    => '第八关：盗取仙丹',
            'trigger_type'  => 'arrive',
            'trigger_room'  => 'dntg/sky/yunlue3',
            'target_type'   => 'interaction',
            'interaction'   => 'take_pill',
            'hint'          => '兜率宫中丹香四溢，太上老君的仙丹就放在丹炉旁……',
            'broadcast'     => '{name}悄悄潜入兜率宫，盯上了太上老君的仙丹！',
            'rewards'       => [
                'daoxing'    => 500,
            ],
            'action_name'   => '盗取仙丹',
            'action_cmd'    => 'dntg_take_pill',
        ],
        8 => [
            'stage_name'    => '第九关：大战二郎神',
            'trigger_type'  => 'arrive',
            'trigger_room'  => 'dntg/sky/nantian',
            'target_type'   => 'combat',
            'target_npc'    => '二郎神',
            'hint'          => '二郎神杨戬手持三尖两刃刀，冷冷道："妖猴，你的死期到了！"',
            'broadcast'     => '{name}在南天门遭遇显圣二郎真君！一场恶战在所难免！',
            'rewards'       => [
                'combat_exp' => 2000,
                'daoxing'    => 300,
            ],
        ],
        9 => [
            'stage_name'    => '第十关：大闹天宫',
            'trigger_type'  => 'arrive',
            'trigger_room'  => 'dntg/sky/lingxiao',
            'target_type'   => 'combat',
            'target_npc'    => '天兵',
            'hint'          => '凌霄宝殿上，天兵天将列阵以待，玉皇大帝高坐龙椅之上……',
            'broadcast'     => '{name}杀到凌霄宝殿，天兵天将群起而攻之！',
            'rewards'       => [
                'combat_exp' => 5000,
                'title'      => '大闹天宫',
            ],
            'extra_reward_desc' => '你获得了至高无上的称号：大闹天宫！',
        ],
    ];
    
    /**
     * 主入口：检查并触发任务
     * 
     * @param int $charId 角色ID
     * @param string $currentRoom 当前房间完整ID（如 sky/nantian）
     * @return array|null 触发结果，无触发时返回null
     */
    public static function checkAndTrigger(int $charId, string $currentRoom): ?array {
        $stage = self::getCurrentStage($charId);
        $config = self::$stages[$stage] ?? null;
        
        if (!$config) {
            return null;
        }
        
        // 只处理到达触发的关卡
        if (($config['trigger_type'] ?? '') !== 'arrive') {
            return null;
        }
        
        // 检查房间是否匹配
        if ($config['trigger_room'] !== $currentRoom) {
            return null;
        }
        
        // 同一session内防止重复触发（战斗关卡还会额外检查是否在战斗中）
        $sessionKey = 'dntg_triggered_' . $stage;
        if (!empty($_SESSION[$sessionKey])) {
            return null;
        }
        
        $char = CharacterModel::find($charId);
        if (!$char) {
            return null;
        }
        
        // 标记已触发
        $_SESSION[$sessionKey] = true;
        
        $targetType = $config['target_type'] ?? '';
        
        if ($targetType === 'combat') {
            return self::triggerCombat($charId, $stage, $config, $char);
        }
        
        // 交互/对话类型：返回提示信息，由调用方显示
        return self::triggerInteractionHint($charId, $stage, $config, $char);
    }
    
    /**
     * 战斗触发
     */
    private static function triggerCombat(int $charId, int $stage, array $config, array $char): array {
        $npcName = $config['target_npc'] ?? '';
        $roomId = $config['trigger_room'] ?? '';
        
        // 检查是否已经在战斗中
        if (CombatDaemon::isInCombat($charId)) {
            $combat = CombatDaemon::getCombatStatus($charId);
            if ($combat && ($combat['target_name'] ?? '') === $npcName) {
                return [
                    'triggered' => false,
                    'reason'    => 'already_in_combat',
                    'message'   => '你正在与 ' . $npcName . ' 战斗中！',
                ];
            }
        }
        
        // 查找NPC
        $npc = self::findNpcByName($npcName, $roomId);
        if (!$npc) {
            error_log("DntgQuestHandler: 未找到NPC {$npcName} 在房间 {$roomId}");
            return [
                'triggered' => false,
                'reason'    => 'npc_not_found',
                'message'   => $config['hint'] ?? '强敌挡在前方！',
            ];
        }
        
        // 广播触发消息到房间
        $broadcast = $config['broadcast'] ?? '';
        if (!empty($broadcast)) {
            $broadcast = str_replace('{name}', $char['name'] ?? '有人', $broadcast);
            MessageDaemon::broadcastToRoom($roomId, HTML_HIYEL . '【大闹天宫】' . HTML_NOR . ' ' . $broadcast, $charId);
        }
        
        // 触发战斗
        $combatResult = CombatDaemon::startKill($charId, intval($npc['id']), 'npc');
        
        return [
            'triggered'      => true,
            'type'           => 'combat',
            'stage'          => $stage,
            'combat_result'  => $combatResult,
            'message'        => $config['hint'] ?? '强敌来袭！',
        ];
    }
    
    /**
     * 交互/对话触发提示（非战斗类关卡）
     */
    private static function triggerInteractionHint(int $charId, int $stage, array $config, array $char): array {
        $roomId = $config['trigger_room'] ?? '';
        
        // 广播触发消息
        $broadcast = $config['broadcast'] ?? '';
        if (!empty($broadcast)) {
            $broadcast = str_replace('{name}', $char['name'] ?? '有人', $broadcast);
            MessageDaemon::broadcastToRoom($roomId, HTML_HIYEL . '【大闹天宫】' . HTML_NOR . ' ' . $broadcast, $charId);
        }
        
        // 向玩家自己发送提示（通过消息队列和flash双重显示）
        $hint = $config['hint'] ?? '';
        if (!empty($hint)) {
            $hintMsg = HTML_HIYEL . '【大闹天宫】' . HTML_NOR . ' ' . $hint;
            MessageDaemon::queueMessageToSelf($charId, $hintMsg, 'self_event');
            // 同时设置flash消息，确保room.php立即显示
            $_SESSION['flash_message'] = [
                'content' => $hintMsg,
                'timestamp' => time()
            ];
        }
        
        return [
            'triggered' => true,
            'type'      => $config['target_type'] ?? 'interaction',
            'stage'     => $stage,
            'message'   => $hint,
            'action'    => [
                'name' => $config['action_name'] ?? '',
                'cmd'  => $config['action_cmd'] ?? '',
            ],
        ];
    }
    
    /**
     * NPC被击杀回调
     * 在 commands/hit.php 中调用
     * 
     * @param int $charId 角色ID
     * @param string $npcName 被击杀的NPC名称
     * @param string $roomId 当前房间ID
     * @return array|null 如果推进了阶段则返回结果
     */
    public static function onNpcKilled(int $charId, string $npcName, string $roomId): ?array {
        // 风婆/天河支线：击败天蓬元帅（对应原始 LPC dntg/bmw 战斗）
        if ($npcName === '天蓬元帅') {
            require_once __DIR__ . '/TianheRainHandler.php';
            $result = TianheRainHandler::handleTianpengDefeated($charId);
            if (!empty($result['success'])) {
                return $result;
            }
            // 若未处于交战状态（dntg/bmw != 'fight'），则继续走大闹天宫主线逻辑
        }

        $stage = self::getCurrentStage($charId);
        $config = self::$stages[$stage] ?? null;
        
        if (!$config) {
            return null;
        }
        
        // 检查是否是当前关卡的战斗目标
        if (($config['target_type'] ?? '') !== 'combat') {
            return null;
        }
        
        $targetNpc = $config['target_npc'] ?? '';
        
        // 天兵特殊处理：目标NPC是"天兵"，但实际击杀的可能是"天兵甲"、"天兵乙"等
        if ($targetNpc === '天兵') {
            if (stripos($npcName, '天兵') === false) {
                return null;
            }
        } elseif ($targetNpc !== $npcName) {
            return null;
        }
        
        // 完成关卡
        return self::completeStage($charId, $stage, $config);
    }
    
    /**
     * 检查交互动作
     * 供 pick、ask、特殊动作等命令调用
     * 
     * @param int $charId 角色ID
     * @param string $action 动作标识（如 'ask', 'pick_peach', 'disturb', 'take_pill'）
     * @param string $roomId 当前房间ID
     * @param string|null $npcName 交互的NPC名称（对话类需要）
     * @return array|null 如果推进了阶段则返回结果
     */
    public static function checkInteraction(int $charId, string $action, string $roomId, ?string $npcName = null): ?array {
        $stage = self::getCurrentStage($charId);
        $config = self::$stages[$stage] ?? null;
        
        if (!$config) {
            return null;
        }
        
        // 检查房间是否匹配
        if ($config['trigger_room'] !== $roomId) {
            return null;
        }
        
        // 检查是否是交互/对话类型
        $targetType = $config['target_type'] ?? '';
        if (!in_array($targetType, ['interaction', 'dialog'], true)) {
            return null;
        }
        
        // 检查交互动作是否匹配
        $expectedInteraction = $config['interaction'] ?? '';
        $expectedCmd = $config['action_cmd'] ?? '';
        
        if ($action !== $expectedInteraction && $action !== $expectedCmd) {
            return null;
        }
        
        // 对话类型额外检查NPC名称
        if ($targetType === 'dialog' && $npcName !== null) {
            $targetNpc = $config['target_npc'] ?? '';
            if (stripos($npcName, $targetNpc) === false) {
                return null;
            }
        }
        
        // 完成关卡
        return self::completeStage($charId, $stage, $config);
    }
    
    /**
     * 完成关卡
     * 
     * @param int $charId 角色ID
     * @param int $stage 当前阶段（0-9）
     * @param array|null $config 关卡配置（可选，不传则自动获取）
     * @return array 完成结果
     */
    public static function completeStage(int $charId, int $stage, ?array $config = null): array {
        if ($config === null) {
            $config = self::$stages[$stage] ?? null;
        }
        
        if (!$config) {
            return ['success' => false, 'message' => '关卡配置不存在'];
        }
        
        $nextStage = $stage + 1;
        
        try {
            $char = CharacterModel::find($charId);
            $charName = $char['name'] ?? '有人';
            $stageName = $config['stage_name'] ?? '第' . ($stage + 1) . '关';
            $roomId = $config['trigger_room'] ?? ($char['current_room'] ?? '');
            
            // === 1. 发放奖励（需要在更新阶段前发放，以便获得随机道行加成信息）===
            $rewardResult = self::grantRewards($charId, $config['rewards'] ?? [], $config);
            
            // === 2. 更新角色任务进度 ===
            Database::execute(
                "UPDATE characters SET dntg_quest_stage = ? WHERE id = ?",
                [$nextStage, $charId]
            );
            
            // === 3. 马元帅NPC互动宣布（还原原始LPC逻辑）===
            self::announceStageComplete($charId, $charName, $stage, $config, $rewardResult);
            
            // === 4. 广播完成消息到房间 ===
            $completeMsg = HTML_HIGRN . '【大闹天宫】' . HTML_NOR . ' ' . $charName . ' 完成了 ' . $stageName . '！';
            MessageDaemon::broadcastToRoom($roomId, $completeMsg, $charId);
            
            // === 5. 给玩家自己的详细消息 ===
            $selfMsg = HTML_HIGRN . '【大闹天宫】' . HTML_NOR . ' 恭喜你完成了 ' . $stageName . '！';
            if (!empty($rewardResult['message'])) {
                $selfMsg .= "\n" . $rewardResult['message'];
            }
            if (!empty($config['extra_reward_desc'])) {
                $selfMsg .= "\n" . HTML_HIYEL . $config['extra_reward_desc'] . HTML_NOR;
            }
            MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'self_event');
            
            // === 6. 清除session中的触发标记，为下一关做准备 ===
            unset($_SESSION['dntg_triggered_' . $stage]);
            
            return [
                'success'      => true,
                'stage'        => $stage,
                'next_stage'   => $nextStage,
                'stage_name'   => $stageName,
                'message'      => $selfMsg,
                'rewards'      => $rewardResult,
            ];
            
        } catch (\Exception $e) {
            error_log("DntgQuestHandler completeStage error: " . $e->getMessage());
            return ['success' => false, 'message' => '任务完成处理失败'];
        }
    }
    
    /**
     * 发放奖励
     * 
     * @param int $charId 角色ID
     * @param array $rewards 奖励配置
     * @param array $config 关卡配置
     * @return array 奖励发放结果
     */
    private static function grantRewards(int $charId, array $rewards, array $config): array {
        if (empty($rewards)) {
            return ['message' => ''];
        }
        
        $updateFields = [];
        $updateParams = [];
        $rewardMsgs = [];
        
        $char = CharacterModel::find($charId);
        
        // 经验奖励
        if (!empty($rewards['combat_exp']) && $rewards['combat_exp'] > 0) {
            $updateFields[] = "combat_exp = combat_exp + ?";
            $updateParams[] = $rewards['combat_exp'];
            $rewardMsgs[] = "实战经验 +" . $rewards['combat_exp'];
        }
        
        // 道行奖励（支持随机加成）
        if (!empty($rewards['daoxing']) && $rewards['daoxing'] > 0) {
            $baseDaoxing = intval($rewards['daoxing']);
            $randomBonus = 0;
            
            // 检查是否启用随机加成
            if (!empty($rewards['random_daoxing_max'])) {
                $maxBonus = intval($rewards['random_daoxing_max']);
                if ($maxBonus > 0) {
                    $randomBonus = rand(0, $maxBonus - 1);  // 0 到 maxBonus-1，与原版 random(maxBonus) 等效
                }
            }
            
            $totalDaoxing = $baseDaoxing + $randomBonus;
            
            $updateFields[] = "daoxing = daoxing + ?";
            $updateParams[] = $totalDaoxing;
            
            if ($randomBonus > 0) {
                $rewardMsgs[] = "道行 +" . $totalDaoxing . " (随机加成+" . $randomBonus . ")";
            } else {
                $rewardMsgs[] = "道行 +" . $baseDaoxing;
            }
        }
        
        // 称号奖励
        if (!empty($rewards['title'])) {
            $updateFields[] = "title = ?";
            $updateParams[] = $rewards['title'];
            $rewardMsgs[] = "获得称号：" . $rewards['title'];
        }
        
        // 恢复全属性
        if (!empty($rewards['restore_all'])) {
            $updateFields[] = "kee = max_kee";
            $updateFields[] = "mana = max_mana";
            $updateFields[] = "food = max_food";
            $updateFields[] = "water = max_water";
            $rewardMsgs[] = "全属性已恢复！";
        }
        
        // 物品奖励（单个物品，保持兼容）
        if (!empty($rewards['item_id'])) {
            self::giveItem($charId, $rewards['item_id'], $rewards['item_name'] ?? '');
            $rewardMsgs[] = "获得物品：" . ($rewards['item_name'] ?? $rewards['item_id']);
        }

        // 物品奖励（多个物品，支持数量）
        if (!empty($rewards['items']) && is_array($rewards['items'])) {
            foreach ($rewards['items'] as $item) {
                $itemId = $item['item_id'] ?? '';
                $itemName = $item['item_name'] ?? $itemId;
                $quantity = intval($item['quantity'] ?? 1);
                if (empty($itemId)) continue;

                for ($i = 0; $i < $quantity; $i++) {
                    self::giveItem($charId, $itemId, $itemName);
                }
                $rewardMsgs[] = "获得物品：{$itemName}" . ($quantity > 1 ? " x{$quantity}" : '');
            }
        }
        
        // 执行数据库更新
        if (!empty($updateFields)) {
            $updateParams[] = $charId;
            $sql = "UPDATE characters SET " . implode(', ', $updateFields) . " WHERE id = ?";
            Database::execute($sql, $updateParams);
        }
        
        return [
            'message' => implode(' | ', $rewardMsgs),
            'details' => $rewards,
        ];
    }
    
    /**
     * 给予物品
     */
    private static function giveItem(int $charId, string $itemId, string $itemName): bool {
        try {
            // 使用统一的 addToInventory，自动处理液体容器不堆叠
            ItemModel::addToInventory($charId, $itemId, 1);
            return true;
        } catch (\Exception $e) {
            error_log("DntgQuestHandler giveItem error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取任务进度信息
     */
    public static function getQuestInfo(int $charId): array {
        $stage = self::getCurrentStage($charId);
        $config = self::$stages[$stage] ?? null;
        
        $info = [
            'quest_name'   => '大闹天宫',
            'current_stage'=> $stage,
            'total_stages' => 10,
            'is_completed' => $stage >= 10,
        ];
        
        if ($stage >= 10) {
            $info['status'] = '已完成';
            $info['message'] = '你已经完成了大闹天宫的全部十关！';
        } elseif ($config) {
            $info['status'] = '进行中';
            $info['current_stage_name'] = $config['stage_name'] ?? '第' . ($stage + 1) . '关';
            $info['hint'] = $config['hint'] ?? '';
        } else {
            $info['status'] = '未开始';
            $info['message'] = '前往花果山开启大闹天宫之旅！';
        }
        
        return $info;
    }
    
    /**
     * 获取当前房间的特殊任务动作（用于room.php显示交互按钮）
     * 
     * @param int $charId 角色ID
     * @param string $roomId 当前房间ID
     * @return array 动作列表 [['name' => '偷桃', 'cmd' => 'dntg_pick_peach'], ...]
     */
    public static function getRoomActions(int $charId, string $roomId): array {
        $stage = self::getCurrentStage($charId);
        $config = self::$stages[$stage] ?? null;
        
        if (!$config) {
            return [];
        }
        
        // 只给交互类关卡返回动作
        if (($config['target_type'] ?? '') !== 'interaction') {
            return [];
        }
        
        // 检查房间是否匹配
        if ($config['trigger_room'] !== $roomId) {
            return [];
        }
        
        $actionName = $config['action_name'] ?? '';
        $actionCmd = $config['action_cmd'] ?? '';
        
        if (empty($actionName) || empty($actionCmd)) {
            return [];
        }
        
        return [[
            'name' => $actionName,
            'cmd'  => $actionCmd,
        ]];
    }
    
    /**
     * 获取当前阶段（从数据库读取）
     */
    public static function getCurrentStage(int $charId): int {
        $row = Database::queryOne(
            "SELECT dntg_quest_stage FROM characters WHERE id = ?",
            [$charId]
        );
        return intval($row['dntg_quest_stage'] ?? 0);
    }
    
    /**
     * 根据名称查找NPC
     */
    private static function findNpcByName(string $npcName, string $roomId): ?array {
        // 优先精确匹配名称和房间
        $sql = "SELECT id, name, spawn_room, spawn_area FROM npcs WHERE name = ? AND spawn_room = ? LIMIT 1";
        $npc = Database::queryOne($sql, [$npcName, $roomId]);
        
        if ($npc) {
            return $npc;
        }
        
        // 模糊匹配（如"天兵"匹配"天兵甲"、"天兵乙"等）
        $sql = "SELECT id, name, spawn_room, spawn_area FROM npcs WHERE name LIKE ? AND spawn_room = ? LIMIT 1";
        $npc = Database::queryOne($sql, ['%' . $npcName . '%', $roomId]);
        
        if ($npc) {
            return $npc;
        }
        
        // 如果房间不匹配，尝试只按名称查找
        $sql = "SELECT id, name, spawn_room, spawn_area FROM npcs WHERE name = ? LIMIT 1";
        $npc = Database::queryOne($sql, [$npcName]);
        
        return $npc ?: null;
    }
    
    /**
     * 关卡完成时的NPC宣布和全服公告
     * 还原原始LPC的马元帅宣布逻辑
     * 
     * @param int $charId 角色ID
     * @param string $charName 角色名称
     * @param int $stage 当前关卡
     * @param array $config 关卡配置
     * @param array $rewardResult 奖励发放结果
     */
    private static function announceStageComplete(int $charId, string $charName, int $stage, array $config, array $rewardResult): void {
        $roomId = $config['trigger_room'] ?? '';
        
        // 1. 房间内NPC宣布消息
        $announceNpc = $config['announce_npc'] ?? '';
        $announceRoomMsg = $config['announce_room_msg'] ?? '';
        
        if (!empty($announceNpc) && !empty($announceRoomMsg)) {
            // 替换 $N 为角色名称（与原版 LPC 语法一致）
            $roomMsg = str_replace('$N', $charName, $announceRoomMsg);
            $formattedMsg = HTML_HIGRN . '【' . $announceNpc . '宣布】' . HTML_NOR . ' ' . $roomMsg;
            MessageDaemon::broadcastToRoom($roomId, $formattedMsg, $charId);
        }
        
        // 2. 玩家个人消息（NPC对玩家说的话）
        $announcePlayerMsg = $config['announce_player_msg'] ?? '';
        if (!empty($announcePlayerMsg)) {
            $playerMsg = str_replace('$N', $charName, $announcePlayerMsg);
            $formattedPlayerMsg = HTML_HICYN . $announcePlayerMsg . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $formattedPlayerMsg, 'self_event');
        }
        
        // 3. 全服公告（第一关完成时）
        $announceGlobal = $config['announce_global'] ?? false;
        if ($announceGlobal) {
            $globalMsg = $config['announce_global_msg'] ?? '';
            if (!empty($globalMsg)) {
                $formattedGlobalMsg = str_replace('{name}', $charName, $globalMsg);
                $broadcastMsg = HTML_HIGRN . '【大闹天宫】' . HTML_HIYEL . $formattedGlobalMsg . HTML_NOR;
                MessageDaemon::broadcastToAll($broadcastMsg);
            }
            
            // 第二条全服公告消息
            $globalMsg2 = $config['announce_global_msg2'] ?? '';
            if (!empty($globalMsg2)) {
                $formattedGlobalMsg2 = str_replace('{name}', $charName, $globalMsg2);
                $broadcastMsg2 = HTML_HIGRN . '【大闹天宫】' . HTML_HIYEL . $formattedGlobalMsg2 . HTML_NOR;
                MessageDaemon::broadcastToAll($broadcastMsg2);
            }
        }
        
        // 4. 记录游戏日志
        log_game('DNTG_STAGE_COMPLETE', "{$charName} 完成大闹天宫第{$stage}关({$config['stage_name']})");
    }
}
