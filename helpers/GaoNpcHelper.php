<?php
/**
 * 高老庄NPC特殊行为辅助类
 * 基于原始LPC项目逻辑还原
 * 
 * 包含：
 * - 高员外物品交互逻辑
 * - 夏鹏展问候语和战斗触发
 * - 高婆特殊对话内容
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/game.php';

class GaoNpcHelper {
    
    /**
     * 获取高员外的声望称谓
     */
    private static function getRespectTitle(array $char): string {
        // 简单的称谓逻辑：根据道行或等级返回称谓
        $daoxing = intval($char['daoxing'] ?? 0);
        if ($daoxing >= 100000) return '大侠';
        if ($daoxing >= 50000) return '高手';
        if ($daoxing >= 10000) return '少侠';
        return '朋友';
    }
    
    /**
     * 处理高员外的物品交互
     * 原始LPC逻辑: 接受"mmmmmm"物品触发事件，给予pa_book
     * 扩展: 接受玉佩(xiaojie)触发高翠兰任务奖励
     * 
     * @param array $npc NPC数据
     * @param array $char 玩家数据
     * @param string $itemId 物品ID
     * @return array ['success' => bool, 'message' => string]
     */
    public static function handleGaoItemInteraction(array $npc, array $char, string $itemId): array {
        // 高员外(npc_id=206)的特殊物品交互
        if ($npc['id'] !== 206) {
            return ['success' => false, 'message' => null];
        }
        
        // 处理玉佩（高翠兰任务）
        if ($itemId === 'xiaojie' || $itemId === 'tong-pai') {
            // 检查玩家是否已经完成过这个任务
            $questCompleted = Database::queryOne(
                "SELECT 1 FROM character_temp_states WHERE char_id = ? AND state_key = 'gao_yupei_quest'",
                [$char['id']]
            );
            
            if ($questCompleted) {
                return ['success' => false, 'message' => '高员外摇了摇头：这玉佩你已经送回来了，多谢你的好意。'];
            }
            
            // 检查玩家是否拥有该物品
            $inventory = Database::queryAll(
                "SELECT quantity FROM character_inventory WHERE char_id = ? AND item_id = ?",
                [$char['id'], $itemId]
            );
            
            if (empty($inventory) || $inventory[0]['quantity'] <= 0) {
                return ['success' => false, 'message' => '你没有这个物品。'];
            }
            
            // 移除玩家的玉佩
            Database::execute(
                "UPDATE character_inventory SET quantity = quantity - 1 WHERE char_id = ? AND item_id = ?",
                [$char['id'], $itemId]
            );
            
            // 清理零数量的物品记录
            Database::execute(
                "DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND quantity <= 0",
                [$char['id'], $itemId]
            );
            
            // 标记任务完成
            Database::execute(
                "INSERT INTO character_temp_states (char_id, state_key, state_value) VALUES (?, 'gao_yupei_quest', '1')
                 ON DUPLICATE KEY UPDATE state_value = '1'",
                [$char['id']]
            );
            
            // 给予奖励
            $silverReward = 50; // 50两银子
            $expReward = 5000; // 5000经验
            $daoxingReward = 1000; // 1000道行
            
            Database::execute(
                "UPDATE characters SET silver = silver + ?, combat_exp = combat_exp + ?, daoxing = daoxing + ? WHERE id = ?",
                [$silverReward, $expReward, $daoxingReward, $char['id']]
            );
            
            $respectTitle = self::getRespectTitle($char);
            $message = "高员外接过玉佩，双手颤抖，老泪纵横：\n";
            $message .= "「这……这是翠兰的玉佩！怎么会在你手里？」\n";
            $message .= "你告诉高员外，玉佩是在清风寨内室找到的。\n";
            $message .= "高员外听完，扑通一声跪倒在地：\n";
            $message .= "「多谢{$respectTitle}！多谢{$respectTitle}救了小女！\n";
            $message .= "  小老儿无以为报，这点心意，请您务必收下！」\n";
            $message .= "（你获得了：白银 {$silverReward} 两，经验 {$expReward}，道行 {$daoxingReward}）";
            
            return ['success' => true, 'message' => $message];
        }
        
        // 处理mmmmmm物品（原始LPC逻辑）
        if ($itemId === 'mmmmmm') {
            // 检查玩家是否拥有该物品
            $inventory = Database::queryAll(
                "SELECT quantity FROM character_inventory WHERE char_id = ? AND item_id = ?",
                [$char['id'], $itemId]
            );
            
            if (empty($inventory) || $inventory[0]['quantity'] <= 0) {
                return ['success' => false, 'message' => '你没有这个物品。'];
            }
            
            // 执行物品交互逻辑
            // 1. 移除玩家的物品
            Database::execute(
                "UPDATE character_inventory SET quantity = quantity - 1 WHERE char_id = ? AND item_id = ?",
                [$char['id'], $itemId]
            );
            
            // 2. 清理零数量的物品记录
            Database::execute(
                "DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND quantity <= 0",
                [$char['id'], $itemId]
            );
            
            // 3. 给予玩家pa_book（格斗秘诀）
            Database::execute(
                "INSERT INTO character_inventory (char_id, item_id, category, quantity) VALUES (?, ?, 'obj', 1)",
                [$char['id'], 'pa_book']
            );
            
            $respectTitle = self::getRespectTitle($char);
            $message = "高庄主笑道：多谢{$respectTitle}，老夫这厢有礼了．\n";
            $message .= "高庄主说道：这是以前一名高士留下的，也许对您有用．\n";
            
            return ['success' => true, 'message' => $message];
        }
        
        // 其他物品，高员外不要
        return ['success' => false, 'message' => '高员外摇了摇头：此物我不需要。'];
    }
    
    /**
     * 获取夏鹏展的问候语
     * 原始LPC逻辑: 进入房间时主动问候，显示敌意
     * 
     * @param array $npc NPC数据
     * @param array $char 玩家数据（可选）
     * @return string 问候语
     */
    public static function getHeadGreeting(array $npc, array $char = []): string {
        // 夏鹏展(npc_id=210)的问候语
        if ($npc['id'] !== 210) {
            return '';
        }
        
        // 如果有玩家数据，使用称谓
        if (!empty($char)) {
            $rudeTitle = '朋友'; // 简化的称谓
            return "{$npc['name']}喝道：那里来的{$rudeTitle}，我看你不想活了！";
        }
        
        $greetings = [
            "{$npc['name']}喝道：来者何人，报上名来！",
            "{$npc['name']}冷眼打量着你，手按在刀柄上，哼了一声。",
            "{$npc['name']}目光凶狠地盯着你，嘴角露出一丝冷笑。",
        ];
        
        return $greetings[array_rand($greetings)];
    }
    
    /**
     * 处理夏鹏展的战斗触发
     * 原始LPC逻辑: 主动攻击玩家，有特定战斗行为
     * 
     * @param array $npc NPC数据
     * @param array $char 玩家数据
     * @return array ['accept' => bool, 'message' => string]
     */
    public static function handleHeadFight(array $npc, array $char): array {
        // 夏鹏展(npc_id=210)的战斗触发逻辑
        if ($npc['id'] !== 210) {
            return ['accept' => false, 'message' => null];
        }
        
        $messages = [
            "{$npc['name']}大喝一声：今日让你见识见识清风寨的厉害！",
            "{$npc['name']}阴森一笑：既然来了，就别想活着出去！",
            "{$npc['name']}拔出雁云刀：看招！",
        ];
        
        return ['accept' => true, 'message' => $messages[array_rand($messages)]];
    }
    
    /**
     * 获取高婆的特殊对话内容
     * 原始LPC逻辑: 有特定的对话内容，包括关于女儿的信息
     * 
     * @param array $npc NPC数据
     * @param string $topic 话题
     * @return string 对话内容
     */
    public static function getGaopoDialogue(array $npc, string $topic): string {
        // 高婆(npc_id=207)的特殊对话
        if ($npc['id'] !== 207) {
            return '';
        }
        
        switch (strtolower($topic)) {
            case '女儿':
            case '小姐':
            case '闺女':
                return "高婆叹了口气，眼中含泪：我那可怜的女儿被土匪抢走了...求求你，若见到她，一定要救她回来啊！";
                
            case '土匪':
            case '强盗':
                return "高婆咬牙切齿：那些可恶的土匪，抢了我家小姐！听说是从后面的小树林进来的...";
                
            case '高员外':
            case '老爷':
                return "高婆低声说：老爷最近愁得吃不下饭，都是因为女儿的事...";
                
            case '家丁':
            case '仆人':
                return "高婆说道：家丁们都在四处寻找小姐的下落，可惜至今没有消息...";
                
            default:
                $defaultDialogues = [
                    "高婆忧心忡忡地说：这日子过得真难啊...",
                    "高婆叹道：但愿女儿平安无事...",
                    "高婆望着窗外：也不知道小姐现在怎么样了...",
                ];
                return $defaultDialogues[array_rand($defaultDialogues)];
        }
    }
    
    /**
     * 检查NPC是否为高老庄区域的特殊NPC
     * 
     * @param int $npcId NPC ID
     * @return bool
     */
    public static function isGaoSpecialNpc(int $npcId): bool {
        return in_array($npcId, [206, 207, 210]); // 高员外、高婆、夏鹏展
    }
}