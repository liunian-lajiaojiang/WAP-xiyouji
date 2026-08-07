<?php
/**
 * 玉鼠精惩罚助手类 (YushuPunishHelper)
 * 
 * 参考原始LPC yushu.c 的 punish_player() 逻辑：
 * 当玩家已背叛无底洞门派后，再次向玉鼠精学习技能时触发惩罚：
 *   1. 清空所有随身物品
 *   2. 将玩家打晕
 *   3. 移入无底洞惩罚室 (qujing/wudidong/punish)
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */

require_once __DIR__ . '/../includes/db.php';

class YushuPunishHelper {
    
    const NPC_ID = 652;              // 玉鼠精 NPC ID
    const NPC_NAME = '玉鼠精';
    const NPC_ID_STR = 'yushu';
    const SECT_KEY = 'wudidong';     // 无底洞门派 key
    const PUNISH_ROOM = 'qujing/wudidong/punish'; // 惩罚室
    
    /**
     * 检查玩家是否背叛过无底洞（已离开门派且曾是无底洞弟子）
     * 通过 betrayal_count 和之前的门派记录判断
     * @param int $charId 角色ID
     * @return bool
     */
    public static function hasBetrayedWudidong(int $charId): bool {
        // 检查当前是否不属于任何门派，但之前曾是无底洞弟子
        // 通过 betrayal_count 判断（只有叛出过门派才会增加）
        $char = Database::queryOne(
            "SELECT family, betrayal_count FROM characters WHERE id = ?",
            [$charId]
        );
        
        if (!$char) return false;
        
        $betrayalCount = intval($char['betrayal_count'] ?? 0);
        
        // 如果当前不在任何门派，但有背叛记录，且曾是无底洞的
        if (empty($char['family']) && $betrayalCount > 0) {
            // 检查 sect_members 历史记录中是否曾是无底洞成员
            $oldMember = Database::queryOne(
                "SELECT * FROM sect_members WHERE character_id = ? AND sect_key = ? AND is_active = 0 ORDER BY updated_at DESC LIMIT 1",
                [$charId, self::SECT_KEY]
            );
            if ($oldMember) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 执行惩罚：清空物品 → 打晕 → 移入惩罚室
     * @param int $charId 角色ID
     * @return array ['success' => bool, 'message' => string, 'type' => string]
     */
    public static function executePunishment(int $charId): array {
        $char = Database::queryOne("SELECT name, current_room FROM characters WHERE id = ?", [$charId]);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在。'];
        }
        
        $charName = $char['name'];
        
        // 步骤1：清空所有随身物品
        $deletedCount = Database::execute(
            "DELETE FROM character_inventory WHERE char_id = ?",
            [$charId]
        );
        
        // 步骤2：将玩家打晕（kee=1，设置昏迷状态）
        Database::execute(
            "UPDATE characters SET kee = 1, unconscious_state = 1, unconscious_end_time = ? WHERE id = ?",
            [time() + 60, $charId]
        );
        
        // 设置 session 昏迷状态（兼容现有 go.php 检查）
        $_SESSION["unconscious_{$charId}"] = [
            'timestamp' => time(),
            'duration' => 60,
        ];
        
        // 步骤3：移入无底洞惩罚室
        $targetArea = 'qujing';
        $targetRoom = self::PUNISH_ROOM;
        Database::execute(
            "UPDATE characters SET current_area = ?, current_room = ? WHERE id = ?",
            [$targetArea, $targetRoom, $charId]
        );
        
        // 记录惩罚状态（标记在惩罚室中）
        Database::execute(
            "INSERT INTO character_temp_states (char_id, state_key, state_value, expire_time) 
             VALUES (?, 'wudidong_punished', '1', NULL)
             ON DUPLICATE KEY UPDATE state_value = '1', expire_time = NULL",
            [$charId]
        );
        
        // 生成惩罚消息
        $message = HTML_HIRED . "玉鼠精勃然大怒：好个大胆的弟子，竟敢帮起外人来了！\n" . HTML_NOR;
        $message .= HTML_HIYEL . "玉鼠精冷哼一声：今天要好好教训教训你...\n" . HTML_NOR;
        $message .= HTML_HIYEL . "只见玉鼠精袖中射出一根绳子，把{$charName}捆了起来...\n" . HTML_NOR;
        $message .= HTML_HIRED . "{$charName}被打晕了过去！\n" . HTML_NOR;
        $message .= HTML_HIYEL . "几个小妖抬着{$charName}往后洞走去。\n" . HTML_NOR;
        $message .= HTML_HIRED . "只听见石门轰地一声关上了，{$charName}被关进了暗无天日的惩罚室..." . HTML_NOR;
        
        log_game('YUSHU_PUNISH', "玉鼠精惩罚了 {$charName}(ID:{$charId})，清空了{$deletedCount}件物品，打入惩罚室");
        
        return [
            'success' => true,
            'message' => $message,
            'type' => 'yushu_punish',
        ];
    }
    
    /**
     * 检查玩家是否在无底洞惩罚室中
     * @param int $charId 角色ID
     * @return bool
     */
    public static function isInPunishRoom(int $charId): bool {
        $char = Database::queryOne(
            "SELECT current_room FROM characters WHERE id = ?",
            [$charId]
        );
        return $char && $char['current_room'] === self::PUNISH_ROOM;
    }
    
    /**
     * 尝试从惩罚室逃脱（search + dig 机制）
     * @param int $charId 角色ID
     * @param string $action 动作：'search' 或 'dig'
     * @return array
     */
    public static function handlePunishRoomAction(int $charId, string $action): ?array {
        if (!self::isInPunishRoom($charId)) {
            return null;
        }
        
        switch ($action) {
            case 'search':
                return [
                    'success' => true,
                    'message' => HTML_HICYN . "你在黑暗中摸索着...\n" .
                                "你发现了一处墙壁似乎有些松动！也许可以用手挖开(dig)。" . HTML_NOR
                ];
                
            case 'dig':
                // 检查是否已经 search 过（用 session 标记）
                $hasSearched = $_SESSION["wudidong_search_{$charId}"] ?? false;
                if (!$hasSearched) {
                    return [
                        'success' => false,
                        'message' => HTML_HIYEL . '你在墙上乱挖一通，但不知道该挖哪里。先摸索(search)一下吧。' . HTML_NOR
                    ];
                }
                
                // 随机成功率 50%
                if (rand(1, 100) <= 50) {
                    // 成功逃脱到供室
                    Database::execute(
                        "UPDATE characters SET current_area = 'qujing', current_room = 'qujing/wudidong/gongshi' WHERE id = ?",
                        [$charId]
                    );
                    
                    // 清除惩罚状态
                    Database::execute(
                        "DELETE FROM character_temp_states WHERE char_id = ? AND state_key = 'wudidong_punished'",
                        [$charId]
                    );
                    unset($_SESSION["wudidong_search_{$charId}"]);
                    
                    return [
                        'success' => true,
                        'message' => HTML_HICYN . "你使劲地挖着墙壁...\n" .
                                    "哗啦一声，墙壁被挖开了一个洞！你钻了进去。\n" .
                                    "你成功逃出了惩罚室！" . HTML_NOR,
                        'type' => 'move',
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => HTML_HIYEL . "你使劲地挖着墙壁...\n" .
                                    "挖了许久，墙壁纹丝不动。看来还需要再试试。" . HTML_NOR
                    ];
                }
        }
        
        return null;
    }
    
    /**
     * 标记玩家已搜索过惩罚室
     * @param int $charId 角色ID
     */
    public static function markSearched(int $charId): void {
        $_SESSION["wudidong_search_{$charId}"] = true;
    }
}
