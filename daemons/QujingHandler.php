<?php
/**
 * 取经任务处理器
 * 基于原始LPC项目逻辑还原
 * 
 * 核心功能：
 * - 取经人产生机制（每5天一次）
 * - 护送玩家选择（1天参选时间）
 * - 关卡挑战系统
 * - 蒸笼老人法宝借用系统
 * - 取经失败惩罚机制
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/game.php';

class QujingHandler {
    
    const FIREMOUNT_BURNING_ROOM = '/d/qujing/firemount/huoyan';
    
    // 取经相关常量
    const QJR_INTERVAL = 432000;  // 5天产生一个取经人 (秒)
    const CHOOSE_INTERVAL = 86400; // 1天参选时间 (秒)
    const MIN_DAOXING = 20000;     // 最低道行要求
    const MAX_TEAM_SIZE = 1;       // 最大护送人数
    
    /**
     * 检查是否可以产生取经人
     * 
     * @return bool
     */
    public static function canSpawnQujingren(): bool {
        $obstacled = Database::queryOne(
            "SELECT haved_qujingren, choose_qjr, this_qj_time 
             FROM obstacled WHERE id = 1 LIMIT 1"
        );
        
        if (!$obstacled) {
            // 首次初始化
            return true;
        }
        
        // 检查是否已有取经人
        if ($obstacled['haved_qujingren'] == 1) {
            return false;
        }
        
        // 检查是否在选择期
        if ($obstacled['choose_qjr'] == 1) {
            return false;
        }
        
        // 检查时间间隔
        $lastTime = $obstacled['this_qj_time'] ?? 0;
        return (time() - $lastTime) >= self::QJR_INTERVAL;
    }
    
    /**
     * 产生取经人
     * 原始LPC逻辑：在皇帝大殿产生，皇帝下旨招募护送武士
     */
    public static function spawnQujingren(): array {
        try {
            // 初始化或更新obstacled记录
            $exists = Database::queryOne(
                "SELECT id FROM obstacled WHERE id = 1 LIMIT 1"
            );
            
            if (!$exists) {
                Database::execute(
                    "INSERT INTO obstacled (id, haved_qujingren, choose_qjr, this_qj_time, number, min_time, guan) 
                     VALUES (1, 0, 0, 0, 0, 0, 'yingchou')"
                );
            } else {
                Database::execute(
                    "UPDATE obstacled SET haved_qujingren = 0, choose_qjr = 0, 
                     this_qj_time = 0, number = 0, min_time = 0, guan = 'yingchou' 
                     WHERE id = 1"
                );
            }
            
            // 在皇帝大殿产生取经人NPC
            $npcId = self::createQujingrenNpc();
            
            // 皇帝下旨
            $message = HTML_HIYEL . "奉天承运，皇帝诏曰：兹寻护送前往西天取经高僧之卫士，" .
                      "望天下勇士前来参选，选中必有重赏。钦此" . HTML_NOR;
            
            // 广播消息
            require_once __DIR__ . '/MessageDaemon.php';
            MessageDaemon::broadcastToAll($message);
            
            return [
                'success' => true,
                'message' => $message,
                'npc_id' => $npcId
            ];
            
        } catch (\Exception $e) {
            error_log('QujingHandler::spawnQujingren error: ' . $e->getMessage());
            return ['success' => false, 'message' => '系统错误，请稍后再试'];
        }
    }
    
    /**
     * 创建取经人NPC到数据库
     * 
     * @return int NPC ID
     */
    private static function createQujingrenNpc(): int {
        // 获取最大的NPC ID
        $maxId = Database::queryOne("SELECT COALESCE(MAX(id), 0) as next_id FROM npcs");
        $npcId = $maxId['next_id'] + 1;
        
        // 插入取经人NPC
        Database::execute(
            "INSERT INTO npcs (id, npc_id, name, title, gender, age, description, class,
             combat_exp, attitude, inquiry, current_room, spawn_room, spawn_area, max_kee, kee, max_sen, sen,
             chat_chance, chat_msg)
             VALUES (?, 'qujing ren', '陈玄奘', '三藏法师', '男', 36, ?, 'monk',
             0, 'peaceful', ?, 'dadian', 'dadian', 'city', 500, 500, 500, 500,
             20, ?)",
            [
                $npcId,
                "灵通本讳号金蝉，只为无心听佛讲，转托尘凡苦受磨，降生世俗遭罗网。" .
                "投胎落地就逢凶，未出之前临恶党。父是海州陈状元，外公总管当朝长。" .
                "出身命犯落江星，顺水随波逐浪泱。海岛金山有大缘，迁安和尚将他养。" .
                "年方十八认亲娘，特赴京都求外长，总管开山调大军，洪州剿寇诛凶党。" .
                "状元光蕊脱天罗，子父相逢堪贺奖。复谒当今受主恩，凌烟阁上贤名响。" .
                "恩官不受愿为僧，洪福沙门将道访。小字江流古佛儿，法名换做陈玄奘。",
                json_encode([
                    'name' => '贫僧东土大唐人士，奉我皇太宗旨意前去西天取经。',
                    'qujing' => ['callable' => 'qujing_ask_for_help'],
                    '取经' => ['callable' => 'qujing_ask_for_help']
                ]),
                // chat_msg: 还原原始 set("chat_msg", ({ (: random_move :) }) )
                json_encode([
                    ["callable", "random_move", "唐僧左右张望，叹了口气继续赶路。"]
                ])
            ]
        );
        
        // 给唐僧装备护法袈裟（还原原始项目 carry_object hufa-jiasha）
        Database::execute(
            "INSERT INTO npc_equipment (npc_id, item_id, category, equip_slot, worn) 
             VALUES (?, 'jiasha', 'armor', 'body', 1)",
            [$npcId]
        );
        
        return $npcId;
    }
    
    /**
     * 开始选择护送玩家
     * 原始LPC逻辑：给1天时间让玩家参选
     */
    public static function startChoosePeriod(): array {
        try {
            Database::execute(
                "UPDATE obstacled SET choose_qjr = 1, number = 0 WHERE id = 1"
            );
            
            $message = HTML_HIYEL . "取经人已经开始招募护送武士，请勇士们尽快前往皇帝大殿参选！" . HTML_NOR;
            
            require_once __DIR__ . '/MessageDaemon.php';
            MessageDaemon::broadcastToAll($message);
            
            return ['success' => true, 'message' => $message];
            
        } catch (\Exception $e) {
            error_log('QujingHandler::startChoosePeriod error: ' . $e->getMessage());
            return ['success' => false, 'message' => '系统错误'];
        }
    }
    
    /**
     * 玩家参选护送
     * 
     * @param int $charId 玩家ID
     * @param int $npcId NPC ID
     * @return array
     */
    public static function applyForEscort(int $charId, int $npcId): array {
        try {
            // 获取玩家信息
            $char = CharacterModel::find($charId);
            if (!$char) {
                return ['success' => false, 'message' => '角色不存在'];
            }
            
            // 检查是否在选择期
            $obstacled = Database::queryOne(
                "SELECT choose_qjr, number FROM obstacled WHERE id = 1 LIMIT 1"
            );
            
            if (!$obstacled || $obstacled['choose_qjr'] != 1) {
                return ['success' => false, 'message' => '陈玄奘说道：现在不是参选时间。'];
            }
            
            // 检查道行要求
            $daoxing = intval($char['daoxing'] ?? 0);
            if ($daoxing < self::MIN_DAOXING) {
                return [
                    'success' => false, 
                    'message' => "陈玄奘说道：多谢施主的好意，只是这路途艰险，妖魔众多，" .
                               "恐怕贫僧连累了施主。"
                ];
            }
            
            // 检查是否已有护送者
            $existingEscort = Database::queryOne(
                "SELECT id FROM character_quests WHERE char_id = ? AND quest_type = 'qujing_escort' AND status = 'active'",
                [$charId]
            );
            
            if ($existingEscort) {
                return ['success' => false, 'message' => '陈玄奘说道：事不疑迟，咱们还是赶紧赶路吧。'];
            }
            
            // 注册参选
            $number = $obstacled['number'] + 1;
            Database::execute(
                "UPDATE obstacled SET number = ? WHERE id = 1",
                [$number]
            );
            
            // 记录参选玩家
            Database::execute(
                "INSERT INTO qujing_applicants (char_id, char_name, apply_time, sequence) 
                 VALUES (?, ?, NOW(), ?)",
                [$charId, $char['name'], $number]
            );
            
            return [
                'success' => true,
                'message' => "陈玄奘向{$char['name']}一拱手：多谢{$char['name']}，" .
                           "你是否能助贫僧一臂之力？"
            ];
            
        } catch (\Exception $e) {
            error_log('QujingHandler::applyForEscort error: ' . $e->getMessage());
            return ['success' => false, 'message' => '系统错误'];
        }
    }
    
    /**
     * 结束选择期，选护送者
     * 原始LPC逻辑：根据失败次数随机选择，较少失败的人有更多机会
     */
    public static function endChoosePeriod(): array {
        try {
            // 获取所有参选者
            $applicants = Database::queryAll(
                "SELECT char_id, char_name, fail_count, daoxing FROM qujing_applicants 
                 WHERE status = 'pending' ORDER BY daoxing DESC, fail_count ASC, sequence ASC"
            );
            
            if (empty($applicants)) {
                $message = HTML_HIRED . "想我堂堂大唐，竟然无一英雄！" . HTML_NOR;
                require_once __DIR__ . '/MessageDaemon.php';
                MessageDaemon::broadcastToAll($message);
                
                // 5天后重新招募
                Database::execute(
                    "UPDATE obstacled SET choose_qjr = 0, this_qj_time = ? WHERE id = 1",
                    [time()]
                );
                
                return ['success' => true, 'message' => $message];
            }
            
            // 只有一个参选者时直接选择
            if (count($applicants) == 1) {
                $chosen = $applicants[0];
            } else {
                // 根据失败次数随机选择（失败少的权重高）
                $totalWeight = 0;
                $weights = [];
                
                foreach ($applicants as $index => $applicant) {
                    $weight = max(1, 10 - intval($applicant['fail_count']));
                    $weights[$index] = $weight;
                    $totalWeight += $weight;
                }
                
                $random = mt_rand(1, $totalWeight);
                $cumulative = 0;
                $chosenIndex = 0;
                
                foreach ($weights as $index => $weight) {
                    $cumulative += $weight;
                    if ($random <= $cumulative) {
                        $chosenIndex = $index;
                        break;
                    }
                }
                
                $chosen = $applicants[$chosenIndex];
            }
            
            // 设置护送者
            Database::execute(
                "UPDATE obstacled SET husong = ?, choose_qjr = 0, haved_qujingren = 1 WHERE id = 1",
                [$chosen['char_id']]
            );
            
            // 给玩家添加取经任务
            Database::execute(
                "INSERT INTO character_quests (char_id, quest_type, quest_id, status, start_time) 
                 VALUES (?, 'qujing_escort', 'yingchou', 'active', NOW())
                 ON DUPLICATE KEY UPDATE status = 'active', start_time = NOW()",
                [$chosen['char_id']]
            );
            
            $message = HTML_HIYEL . "封{$chosen['char_name']}为护国法师，护送取经人前往西天取经！" . HTML_NOR;
            
            require_once __DIR__ . '/MessageDaemon.php';
            MessageDaemon::broadcastToAll($message);
            
            return ['success' => true, 'message' => $message];
            
        } catch (\Exception $e) {
            error_log('QujingHandler::endChoosePeriod error: ' . $e->getMessage());
            return ['success' => false, 'message' => '系统错误'];
        }
    }
    
    /**
     * 处理取经人询问"取经"话题
     * 原始LPC逻辑：ask_for_help() 函数
     */
    public static function handleAskForHelp(array $npc, array $char, string $topic): ?string {
        // 检查是否为妖魔
        if ($char['class'] === 'yaomo') {
            return "陈玄奘对{$char['name']}道：施主满脸妖气，不知来此是何居心，" .
                   "苦海无边，回头是岸，我劝施主还是好自为之吧。";
        }
        
        // 检查道行
        $daoxing = intval($char['daoxing'] ?? 0);
        if ($daoxing < self::MIN_DAOXING) {
            return "陈玄奘对{$char['name']}说到：多谢施主的好意，只是这路途艰险，" .
                   "妖魔众多，恐怕贫僧连累了施主。";
        }
        
        // 检查是否已是护送者
        $existingQuest = Database::queryOne(
            "SELECT id FROM character_quests WHERE char_id = ? AND quest_type = 'qujing_escort' AND status = 'active' LIMIT 1",
            [$char['id']]
        );
        
        if ($existingQuest) {
            return "陈玄奘对{$char['name']}说到：事不疑迟，咱们还是赶紧赶路吧。";
        }
        
        // 检查是否已有护送者
        $obstacled = Database::queryOne(
            "SELECT husong FROM obstacled WHERE id = 1 LIMIT 1"
        );
        
        if ($obstacled && $obstacled['husong'] && $obstacled['husong'] != $char['id']) {
            return "陈玄奘对{$char['name']}一抱拳：多谢{$char['name']}的好意，已有人帮助贫僧了。";
        }
        
        // 邀请护送
        return "陈玄奘向{$char['name']}一拱手：行至此地，见路途艰难，" .
               "{$char['name']}能否助贫僧一臂之力？";
    }
    
    /**
     * 完成关卡
     * 
     * @param int $charId 玩家ID
     * @param string $questId 关卡ID
     * @return array
     */
    public static function completeQuest(int $charId, string $questId): array {
        try {
            // 获取关卡信息
            $questDef = self::getQuestDefinition($questId);
            if (!$questDef) {
                return ['success' => false, 'message' => '关卡不存在'];
            }
            
            $reward = intval($questDef['reward']);
            
            // 给予奖励
            Database::execute(
                "UPDATE characters SET daoxing = daoxing + ? WHERE id = ?",
                [$reward, $charId]
            );
            
            // 更新任务状态
            Database::execute(
                "UPDATE character_quests SET status = 'completed', complete_time = NOW() 
                 WHERE char_id = ? AND quest_id = ? AND status = 'active'",
                [$charId, $questId]
            );
            
            // 记录历史
            Database::execute(
                "INSERT INTO qujing_history (char_id, quest_id, complete_time, reward) 
                 VALUES (?, ?, NOW(), ?)",
                [$charId, $questId, $reward]
            );
            
            $message = "恭喜！{$questDef['name']}关卡完成，获得{$reward}点道行奖励！";
            
            if ($questId === 'lingshan') {
                $message = self::giveFinalRewards($charId);
            }
            
            return ['success' => true, 'message' => $message, 'reward' => $reward];
            
        } catch (\Exception $e) {
            error_log('QujingHandler::completeQuest error: ' . $e->getMessage());
            return ['success' => false, 'message' => '系统错误'];
        }
    }
    
    private static function giveFinalRewards(int $charId): string {
        require_once MODEL_PATH . 'Item.php';
        
        $rewards = [];
        
        $potential = rand(10000, 30000);
        Database::execute(
            "UPDATE characters SET potential = potential + ? WHERE id = ?",
            [$potential, $charId]
        );
        $rewards[] = "潜能点 +{$potential}";
        
        Database::execute(
            "UPDATE character_skills SET level = level + 1 WHERE char_id = ?",
            [$charId]
        );
        $rewards[] = "所有技能 +1级";
        
        $hairTypes = ['amberhair', 'blackhair', 'bluehair', 'brownhair', 'greenhair', 
                      'indigohair', 'magentahair', 'maroonhair', 'orangehair', 'pinkhair',
                      'redhair', 'scarlethair', 'violethair', 'whitehair', 'yellowhair'];
        
        for ($i = 0; $i < 3; $i++) {
            $hairName = $hairTypes[array_rand($hairTypes)];
            ItemModel::addToInventory($charId, $hairName, 1);
        }
        $rewards[] = "毫毛 x3";
        
        return HTML_HIYEL . "恭喜你完成西天取经全部关卡！如来佛祖赐予你以下奖励：\n" . 
               implode("\n", $rewards) . "\n\n" . HTML_NOR . 
               "你现在可以使用 usehair 命令来使用毫毛变化物品了！";
    }
    
    /**
     * 获取关卡定义（从数据库读取）
     * 
     * @param string $questId 关卡ID
     * @return array|null
     */
    public static function getQuestDefinition(string $questId): ?array {
        $quest = Database::queryOne(
            "SELECT id, name, min_daoxing, init_room, description, success_msg, time_limit, reward 
             FROM qujing_quests WHERE id = ? LIMIT 1",
            [$questId]
        );
        if (!$quest) {
            return null;
        }
        return [
            'name' => $quest['name'],
            'min_daoxing' => intval($quest['min_daoxing']),
            'init_room' => $quest['init_room'],
            'description' => $quest['description'],
            'success_msg' => $quest['success_msg'],
            'time_limit' => intval($quest['time_limit']),
            'reward' => intval($quest['reward']),
        ];
    }
    
    /**
     * 获取所有关卡列表（从数据库读取）
     * 
     * @return array
     */
    public static function getAllQuests(): array {
        $quests = Database::queryAll(
            "SELECT id, name, min_daoxing FROM qujing_quests ORDER BY quest_order ASC"
        );
        return array_map(function($q) {
            return [
                'id' => $q['id'],
                'name' => $q['name'],
                'min_daoxing' => intval($q['min_daoxing']),
            ];
        }, $quests);
    }
    
    /**
     * 获取取经关卡顺序（从数据库读取）
     * 
     * @return array
     */
    public static function getQuestOrder(): array {
        $quests = Database::queryAll(
            "SELECT id FROM qujing_quests ORDER BY quest_order ASC"
        );
        return array_column($quests, 'id');
    }
    public static function getNextQuest(string $currentQuest): ?string {
        $order = self::getQuestOrder();
        $index = array_search($currentQuest, $order);
        if ($index === false || $index >= count($order) - 1) {
            return null;
        }
        return $order[$index + 1];
    }
    
    /**
     * 唐僧死亡处理（还原原始项目 qujingren.c die()）
     * 死亡后移到生死轮回处，1800秒(30分钟)后复活
     * 
     * @param int $npcId 唐僧NPC的数字ID
     * @return void
     */
    public static function handleTangSengDeath(int $npcId): void {
        // 1. 恢复气血神气（原始：set eff_kee/eff_sen/kee/sen 都为500）
        Database::execute(
            "UPDATE npcs SET kee = 500, max_kee = 500, sen = 500, max_sen = 500 
             WHERE id = ?",
            [$npcId]
        );
        
        // 2. 移动到生死轮回房间
        Database::execute(
            "UPDATE npcs SET current_room = 'qujing/qujingren/shengsilunlui', spawn_room = 'qujing/qujingren/shengsilunlui' 
             WHERE id = ?",
            [$npcId]
        );
        
        // 3. 记录死亡时间到npc_temp，用于30分钟后复活判断
        Database::execute(
            "INSERT INTO npc_temp (npc_id, temp_key, temp_value, expires_at) 
             VALUES (?, 'tang_seng_death', ?, NOW() + INTERVAL 1800 SECOND)
             ON DUPLICATE KEY UPDATE temp_value = ?, expires_at = NOW() + INTERVAL 1800 SECOND",
            [$npcId, time(), time()]
        );
        
        // 4. 取经状态标记失败
        Database::execute("UPDATE obstacled SET obstacle_fail = 1 WHERE id = 1");
        
        log_game('TANGSENG_DEATH', "唐僧(ID:{$npcId})死亡，移至生死轮回处，30分钟后复活");
    }
    
    /**
     * 检查唐僧是否该复活了（轮询调用）
     * 原始：call_out("random_move_xuanzang", 1800)
     * 
     * @return bool 是否触发了复活
     */
    public static function checkTangSengRevive(): bool {
        // 找到唐僧NPC
        $tangSeng = Database::queryOne(
            "SELECT n.id, n.current_room FROM npcs n 
             WHERE n.npc_id = 'qujing ren' LIMIT 1"
        );
        if (!$tangSeng) {
            return false;
        }
        
        // 检查是否在生死轮回房间
        if ($tangSeng['current_room'] !== 'qujing/qujingren/shengsilunlui') {
            return false;
        }
        
        // 检查死亡时间是否超过1800秒
        $deathRecord = Database::queryOne(
            "SELECT temp_value FROM npc_temp 
             WHERE npc_id = ? AND temp_key = 'tang_seng_death' LIMIT 1",
            [$tangSeng['id']]
        );
        
        if (!$deathRecord) {
            return false;
        }
        
        $deathTime = intval($deathRecord['temp_value']);
        if (time() - $deathTime < 1800) {
            return false;
        }
        
        // 时间到，复活：随机移动到新关卡
        self::randomMoveQujingren();
        
        // 清理死亡记录
        Database::execute(
            "DELETE FROM npc_temp WHERE npc_id = ? AND temp_key = 'tang_seng_death'",
            [$tangSeng['id']]
        );
        
        log_game('TANGSENG_REVIVE', "唐僧从生死轮回处复活，随机移动到新关卡");
        return true;
    }
    
    /**
     * 检查取经人是否可以随机移动
     * 原始LPC逻辑：每隔6分钟如果没人过关，自动移动到新的关卡
     */
    public static function canRandomMove(): bool {
        $quest = Database::queryOne(
            "SELECT start_time FROM character_quests 
             WHERE quest_type = 'qujing_escort' AND status = 'active' 
             ORDER BY start_time DESC LIMIT 1"
        );
        
        if (!$quest) {
            return true;
        }
        
        $startTime = strtotime($quest['start_time']);
        return (time() - startTime) >= 360; // 6分钟
    }
    
    /**
     * 取经人随机移动到新关卡
     */
    public static function randomMoveQujingren(): array {
        try {
            $allQuests = self::getQuestOrder();
            $randomQuest = $allQuests[array_rand($allQuests)];
            
            // 更新取经人位置
            Database::execute(
                "UPDATE obstacled SET guan = ? WHERE id = 1",
                [$randomQuest]
            );
            
            return ['success' => true, 'new_quest' => $randomQuest];
            
        } catch (\Exception $e) {
            error_log('QujingHandler::randomMoveQujingren error: ' . $e->getMessage());
            return ['success' => false, 'message' => '系统错误'];
        }
    }
    
    /**
     * 取经失败惩罚
     * 原始LPC逻辑：取经人被吃后24小时没救出来就算失败
     */
    public static function handleQujingFail(int $charId): array {
        try {
            // 标记失败
            Database::execute(
                "UPDATE obstacled SET obstacle_fail = 1 WHERE id = 1"
            );
            
            // 惩罚护送者
            Database::execute(
                "DELETE FROM character_quests WHERE char_id = ? AND quest_type = 'qujing_escort'",
                [$charId]
            );
            
            // 记录失败历史
            Database::execute(
                "INSERT INTO qujing_failures (char_id, fail_time, quest_id) 
                 VALUES (?, NOW(), 'qujing_escort')",
                [$charId]
            );
            
            return ['success' => true, 'message' => '取经失败...'];

        } catch (\Exception $e) {
            error_log('QujingHandler::handleQujingFail error: ' . $e->getMessage());
            return ['success' => false, 'message' => '系统错误'];
        }
    }

    /**
     * 开始护送取经人任务
     * 唐僧NPC对话触发
     */
    public static function startEscortQuest(int $charId): array {
        try {
            // 检查角色状态
            $char = Database::queryOne(
                "SELECT c.*, o.haved_qujingren, o.guan 
                 FROM characters c 
                 LEFT JOIN obstacled o ON 1=1 
                 WHERE c.id = ? LIMIT 1",
                [$charId]
            );

            if (!$char) {
                return ['success' => false, 'message' => '角色不存在。'];
            }

            // 检查是否已有护送任务
            $activeQuest = Database::queryOne(
                "SELECT * FROM character_quests WHERE char_id = ? AND quest_type = 'qujing_escort' AND status = 'active' LIMIT 1",
                [$charId]
            );

            if ($activeQuest) {
                $questId = $activeQuest['quest_id'] ?? 'yingchou';
                $nextQuest = self::getQuestDefinition($questId);
                $questName = $nextQuest['name'] ?? $questId;
                return [
                    'success' => true,
                    'message' => "你已经护送取经人了！当前关卡：「{$questName}」。\n" .
                               "使用 escort 命令继续护送取经人。"
                ];
            }

            // 检查是否已有取经人
            if (empty($char['haved_qujingren'])) {
                return [
                    'success' => true,
                    'message' => "目前还没有取经人产生，请等待系统分配。"
                ];
            }

            // 获取当前关卡
            $currentQuestId = $char['guan'] ?? 'yingchou';
            $questDef = self::getQuestDefinition($currentQuestId);

            if (!$questDef) {
                $currentQuestId = 'yingchou';
                $questDef = self::getQuestDefinition('yingchou');
            }

            // 检查道行要求
            $daoxing = intval($char['daoxing'] ?? 0);
            $minDaoxing = $questDef['min_daoxing'] ?? 20000;

            if ($daoxing < $minDaoxing) {
                $required = intval($minDaoxing / 10000);
                $current = intval($daoxing / 10000);
                return [
                    'success' => false,
                    'message' => "你的道行不足！当前关卡「{$questDef['name']}」需要至少{$required}万年道行，你只有{$current}万年。"
                ];
            }

            // 创建护送任务
            $nextQuestId = self::getNextQuest($currentQuestId);
            $nextQuestName = '灵山';
            if ($nextQuestId) {
                $nextDef = self::getQuestDefinition($nextQuestId);
                $nextQuestName = $nextDef['name'] ?? '下一关';
            }

            Database::execute(
                "INSERT INTO character_quests (char_id, quest_type, quest_id, current_stage, next_location, status, start_time) 
                 VALUES (?, 'qujing_escort', ?, 1, ?, 'active', NOW())",
                [$charId, $currentQuestId, $nextQuestName]
            );

            return [
                'success' => true,
                'message' => "你开始护送取经人唐僧！\n" .
                           HTML_HIYEL . "当前关卡：{$questDef['name']}" . HTML_NOR . "\n" .
                           "下一站：{$nextQuestName}\n" .
                           "使用 escort 命令开始护送！"
            ];

        } catch (\Exception $e) {
            error_log('QujingHandler::startEscortQuest error: ' . $e->getMessage());
            return ['success' => false, 'message' => '系统错误：' . $e->getMessage()];
        }
    }

    /**
     * 选出取经人
     * 竞选期结束后，从候选人中选出取经人
     */
    public static function selectQujingren(): array {
        try {
            // 获取候选人列表（按道行排序）
            $candidates = Database::queryAll(
                "SELECT * FROM qujing_applicants WHERE status = 'pending' ORDER BY daoxing DESC LIMIT 10"
            );

            if (empty($candidates)) {
                // 没有候选人，关闭申请期
                Database::execute(
                    "UPDATE obstacled SET choose_qjr = 0, haved_qujingren = 0 WHERE id = 1"
                );
                return ['success' => false, 'message' => '没有候选人申请'];
            }

            // 选出取经人（道行最高者）
            $winner = $candidates[0];
            $winnerId = intval($winner['char_id']);
            $winnerName = $winner['char_name'] ?? '未知';

            // 更新取经人状态
            Database::execute(
                "UPDATE obstacled SET 
                    haved_qujingren = 1,
                    choose_qjr = 0,
                    current_qujingren_id = ?,
                    guan = 'yingchou',
                    this_qj_time = UNIX_TIMESTAMP()
                 WHERE id = 1",
                [$winnerId]
            );

            // 更新候选人状态
            Database::execute(
                "UPDATE qujing_applicants SET status = 'selected' WHERE char_id = ?",
                [$winnerId]
            );

            // 其他候选人标记为失败
            Database::execute(
                "UPDATE qujing_applicants SET status = 'failed' WHERE status = 'pending' AND char_id != ?",
                [$winnerId]
            );

            // 广播消息
            $broadcastMsg = HTML_HIYEL . "恭喜{$winnerName}成为本轮取经人！请前往长安城门口找唐僧开始取经之旅。" . HTML_NOR;
            MessageDaemon::broadcastToRoom('city/zhuque-s1', $broadcastMsg, 0);

            return [
                'success' => true,
                'message' => "{$winnerName}被选为取经人",
                'winner_id' => $winnerId,
                'winner_name' => $winnerName
            ];

        } catch (\Exception $e) {
            error_log('QujingHandler::selectQujingren error: ' . $e->getMessage());
            return ['success' => false, 'message' => '系统错误'];
        }
    }

    /**
     * 检查并执行取经人选举
     * 定时调用此方法检查竞选期是否结束
     */
    public static function checkAndSelectQujingren(): array {
        $obstacled = Database::queryOne("SELECT * FROM obstacled WHERE id = 1 LIMIT 1");

        if (!$obstacled || $obstacled['choose_qjr'] != 1) {
            return ['success' => false, 'message' => '不在竞选期'];
        }

        $applyTime = intval($obstacled['this_qj_time'] ?? 0);
        $elapsed = time() - $applyTime;

        // 竞选期结束（1天）
        if ($elapsed >= self::CHOOSE_INTERVAL) {
            return self::selectQujingren();
        }

        return ['success' => false, 'message' => '竞选期尚未结束'];
    }

    /**
     * 检查并开放新的申请期
     * 定时调用此方法检查是否需要开放新的申请期
     */
    public static function checkAndOpenApplyPeriod(): array {
        $obstacled = Database::queryOne("SELECT * FROM obstacled WHERE id = 1 LIMIT 1");

        if (!$obstacled) {
            // 初始化
            Database::execute(
                "INSERT INTO obstacled (id, haved_qujingren, choose_qjr, this_qj_time) 
                 VALUES (1, 0, 1, UNIX_TIMESTAMP()) ON DUPLICATE KEY UPDATE choose_qjr = 1"
            );
            return ['success' => true, 'message' => '申请期已开放'];
        }

        // 已有取经人，不开放
        if ($obstacled['haved_qujingren'] == 1) {
            return ['success' => false, 'message' => '已有取经人'];
        }

        // 已在申请期
        if ($obstacled['choose_qjr'] == 1) {
            return ['success' => false, 'message' => '已在申请期'];
        }

        // 检查距离上一轮结束的时间
        $lastTime = intval($obstacled['this_qj_time'] ?? 0);
        $elapsed = time() - $lastTime;

        // 5天后开放新申请期
        if ($elapsed >= self::QJR_INTERVAL) {
            Database::execute(
                "UPDATE obstacled SET choose_qjr = 1, this_qj_time = UNIX_TIMESTAMP() WHERE id = 1"
            );
            return ['success' => true, 'message' => '新申请期已开放'];
        }

        return ['success' => false, 'message' => '等待下一轮申请期'];
    }
    
    /**
     * 检查取经是否超时失败
     * 在每次请求时调用，检查是否到了24小时失败时间
     */
    public static function checkQujingFail(): void {
        // 检查是否有失败时间记录
        $failTimeVar = Database::queryOne(
            "SELECT value FROM variables WHERE var_key = 'qujing_fail_time'"
        );
        
        if (!$failTimeVar || empty($failTimeVar['value'])) {
            return;
        }
        
        $failTime = intval($failTimeVar['value']);
        
        if (time() < $failTime) {
            return;
        }
        
        // 已到失败时间，执行失败逻辑
        $obstacled = Database::queryOne("SELECT husong, obstacle_fail FROM obstacled WHERE id = 1");
        
        if ($obstacled && intval($obstacled['obstacle_fail']) === 0) {
            $husongId = intval($obstacled['husong'] ?? 0);
            self::handleQujingFail($husongId);
        }
        
        // 不清除失败时间记录，因为玩家需要掀蒸笼拿肉
    }
    
    /**
     * 检查天魔茧是否需要收回（1小时后自动收回）
     */
    public static function checkTianmojianReturn(): void {
        $obstacled = Database::queryOne("SELECT last_jie_id FROM obstacled WHERE id = 1");
        
        if (!$obstacled || empty($obstacled['last_jie_id'])) {
            return;
        }
        
        $jieId = intval($obstacled['last_jie_id']);
        
        // 检查借宝时间
        $borrowRecord = Database::queryOne(
            "SELECT borrow_time FROM tianmojian_borrows 
             WHERE char_id = ? AND is_returned = 0 
             ORDER BY borrow_time DESC LIMIT 1",
            [$jieId]
        );
        
        if (!$borrowRecord) {
            return;
        }
        
        $borrowTime = strtotime($borrowRecord['borrow_time']);
        
        // 1小时后收回
        if (time() - $borrowTime >= 3600) {
            self::returnTianmojian($jieId);
        }
    }
    
    /**
     * 收回天魔茧
     */
    public static function returnTianmojian(int $charId): void {
        try {
            // 从玩家背包中移除天魔茧
            Database::execute(
                "DELETE FROM character_inventory WHERE char_id = ? AND item_id = 'tianmojian'",
                [$charId]
            );
            
            // 更新借用记录
            Database::execute(
                "UPDATE tianmojian_borrows SET is_returned = 1, return_time = NOW() 
                 WHERE char_id = ? AND is_returned = 0",
                [$charId]
            );
            
            // 清除 obstacled 中的记录
            Database::execute(
                "UPDATE obstacled SET last_jie_id = NULL WHERE id = 1"
            );
            
            // 发送消息给玩家
            require_once __DIR__ . '/MessageDaemon.php';
            if (method_exists('MessageDaemon', 'sendMessageToPlayer')) {
                $message = "天魔茧时间已到，自动收回了。";
                MessageDaemon::sendMessageToPlayer($charId, $message);
            }
            
        } catch (\Exception $e) {
            error_log('QujingHandler::returnTianmojian error: ' . $e->getMessage());
        }
    }
    
    /**
     * 运行所有定时检查
     * 在每次请求时调用
     */
    public static function runTimedChecks(): void {
        self::checkQujingFail();
        self::checkTianmojianReturn();
        self::checkFiremountDamage();
        self::checkRandomMoveQujingren();
    }
    
    /**
     * 检查唐僧是否该随机移动了（还原原始 call_out 300秒）
     * 原始：random_move_xuanzang 每300秒触发一次
     */
    public static function checkRandomMoveQujingren(): void {
        // 检查是否有取经人
        $obstacled = Database::queryOne("SELECT haved_qujingren FROM obstacled WHERE id = 1");
        if (!$obstacled || intval($obstacled['haved_qujingren']) !== 1) {
            return;
        }

        // 查找取经人NPC ID
        $tangSeng = Database::queryOne(
            "SELECT id FROM npcs WHERE npc_id = 'qujing ren' LIMIT 1"
        );
        if (!$tangSeng) {
            return;
        }
        $npcId = $tangSeng['id'];

        // 检查上次随机移动时间
        $lastMove = Database::queryOne(
            "SELECT temp_value FROM npc_temp 
             WHERE npc_id = ? AND temp_key = 'qujing_last_move' LIMIT 1",
            [$npcId]
        );

        $lastMoveTime = $lastMove ? intval($lastMove['temp_value']) : 0;

        // 超过300秒（5分钟）才移动
        if (time() - $lastMoveTime < 300) {
            return;
        }

        // 执行随机移动
        self::randomMoveQujingren();

        // 更新上次移动时间
        Database::execute(
            "INSERT INTO npc_temp (npc_id, temp_key, temp_value, expires_at) 
             VALUES (?, 'qujing_last_move', ?, NOW() + INTERVAL 3600 SECOND)
             ON DUPLICATE KEY UPDATE temp_value = ?, expires_at = NOW() + INTERVAL 3600 SECOND",
            [$npcId, time(), time()]
        );
    }
    
    /**
     * 检查火焰山伤害
     * 遍历所有在火焰山燃烧房间的玩家，检查是否需要造成伤害
     */
    public static function checkFiremountDamage(): void {
        try {
            require_once __DIR__ . '/FiremountHandler.php';
            
            FiremountHandler::checkReset();
            
            $players = Database::queryAll(
                "SELECT id, current_room FROM characters 
                 WHERE current_room = ?",
                [self::FIREMOUNT_BURNING_ROOM]
            );
            
            foreach ($players as $player) {
                $damageMsg = FiremountHandler::checkFlameDamage(
                    intval($player['id']), 
                    $player['current_room']
                );
                
                if (!empty($damageMsg)) {
                    require_once __DIR__ . '/MessageDaemon.php';
                    if (method_exists('MessageDaemon', 'sendMessageToPlayer')) {
                        MessageDaemon::sendMessageToPlayer($player['id'], $damageMsg);
                    }
                }
            }
            
        } catch (\Exception $e) {
            error_log('QujingHandler::checkFiremountDamage error: ' . $e->getMessage());
        }
    }
    
    /**
     * 火焰山灭火处理
     * 
     * @param int $charId 玩家ID
     * @return array
     */
    public static function handleFiremountExtinguish(int $charId): array {
        try {
            require_once __DIR__ . '/FiremountHandler.php';
            
            $char = Database::queryOne("SELECT current_room FROM characters WHERE id = ?", [$charId]);
            if (!$char || $char['current_room'] !== self::FIREMOUNT_BURNING_ROOM) {
                return ['success' => false, 'message' => '你不在火焰山燃烧的地方，无法使用铁扇！'];
            }
            
            $result = FiremountHandler::extinguishFire($charId);
            
            if ($result['success'] && isset($result['reward'])) {
                $charInfo = Database::queryOne("SELECT name FROM characters WHERE id = ?", [$charId]);
                $charName = $charInfo['name'] ?? '玩家';
                
                require_once __DIR__ . '/MessageDaemon.php';
                MessageDaemon::broadcastToAll("恭喜{$charName}成功熄灭火焰山！获得{$result['reward']}点道行奖励！");
            }
            
            return $result;
            
        } catch (\Exception $e) {
            error_log('QujingHandler::handleFiremountExtinguish error: ' . $e->getMessage());
            return ['success' => false, 'message' => '系统错误'];
        }
    }
}
