<?php
/**
 * 天魔茧助手类
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 功能:
 * - 天魔茧自动销毁机制（资格不够时消失）
 * - 天魔茧属性检查
 */

class TianmojianHelper {
    
    /**
     * 检查并执行天魔茧自动销毁
     * 如果持有者是取经人 或 战斗经验<50万，天魔茧自动销毁
     * 
     * @param int $charId 玩家ID
     * @return array ['destroyed' => bool, 'message' => string]
     */
    public static function checkAutoDestroy(int $charId): array {
        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['destroyed' => false, 'message' => '角色不存在'];
        }
        
        // 检查玩家是否有天魔茧
        $tianmojian = Database::queryOne(
            "SELECT * FROM character_inventory 
             WHERE char_id = ? AND item_id = 'tianmojian' AND quantity > 0
             LIMIT 1",
            [$charId]
        );
        
        if (!$tianmojian) {
            return ['destroyed' => false, 'message' => '没有天魔茧'];
        }
        
        // 检查是否满足销毁条件
        $isQujingren = ($char['obstacle/qujing'] ?? '') === 'ren';
        $combatExp = intval($char['combat_exp'] ?? 0);
        $expNotEnough = $combatExp < 500000;
        
        if (!$isQujingren && !$expNotEnough) {
            return ['destroyed' => false, 'message' => '资格足够，无需销毁'];
        }
        
        // 执行销毁
        Database::beginTransaction();
        
        try {
            // 1. 从玩家背包移除天魔茧
            $quantity = intval($tianmojian['quantity'] ?? 1);
            if ($quantity <= 1) {
                Database::execute(
                    "DELETE FROM character_inventory WHERE id = ?",
                    [$tianmojian['id']]
                );
            } else {
                Database::execute(
                    "UPDATE character_inventory SET quantity = quantity - 1 WHERE id = ?",
                    [$tianmojian['id']]
                );
            }
            
            // 2. 清除 obstacled.last_jie_id（如果是这个玩家借的）
            $obstacled = Database::queryOne("SELECT last_jie_id FROM obstacled WHERE id = 1");
            if ($obstacled && intval($obstacled['last_jie_id'] ?? 0) === $charId) {
                Database::execute("UPDATE obstacled SET last_jie_id = NULL WHERE id = 1");
            }
            
            // 3. 发送消息给玩家
            $message = "空中一声大吼，无用的家伙，还我天魔茧来！\n只见一道黑光从天而降，天魔茧被收走了！";
            
            if (!defined('DAEMON_PATH')) {
                define('DAEMON_PATH', __DIR__ . '/../daemons/');
            }
            require_once DAEMON_PATH . 'MessageDaemon.php';
            MessageDaemon::sendToPlayer($charId, $message, 'system');
            
            // 4. 全服广播（可选，原始LPC是message("sound", ...)给环境）
            $charName = $char['name'] ?? '某人';
            $broadcastMsg = "【天魔劫难】{$charName}道行不够，天魔茧被蒸笼老人收回了！";
            // MessageDaemon::broadcastToAll($broadcastMsg); // 暂时不全服广播，只给玩家发消息
            
            Database::commit();
            
            if (function_exists('log_game')) {
                log_game('TIANMOJIAN_DESTROY', "玩家 {$charName}({$charId}) 的天魔茧被自动销毁，原因: " . ($isQujingren ? '是取经人' : '经验不足'));
            }
            
            return [
                'destroyed' => true,
                'message' => $message
            ];
            
        } catch (\Exception $e) {
            Database::rollBack();
            error_log("TianmojianHelper::checkAutoDestroy error: " . $e->getMessage());
            return ['destroyed' => false, 'message' => '销毁失败: ' . $e->getMessage()];
        }
    }
    
    /**
     * 给玩家添加天魔茧（带完整属性）
     * 
     * @param int $charId 玩家ID
     * @return bool
     */
    public static function giveTianmojian(int $charId): bool {
        // 生成法宝注册号
        $seriesNo = 'TMJ-' . time() . '-' . rand(1000, 9999);
        
        // 检查是否已有
        $existing = Database::queryOne(
            "SELECT id FROM character_inventory 
             WHERE char_id = ? AND item_id = 'tianmojian' AND category = 'qujing'",
            [$charId]
        );
        
        if ($existing) {
            // 已有，增加数量
            return Database::execute(
                "UPDATE character_inventory SET quantity = quantity + 1 WHERE id = ?",
                [$existing['id']]
            ) > 0;
        } else {
            // 新增，带完整属性
            return Database::execute(
                "INSERT INTO character_inventory 
                 (char_id, item_id, category, quantity, series_no, liquid_remaining)
                 VALUES (?, 'tianmojian', 'qujing', 1, ?, 15)",
                [$charId, $seriesNo]
            ) > 0;
        }
    }
    
    /**
     * 检查玩家是否有资格持有天魔茧
     * 
     * @param array $char 角色数据
     * @return array ['qualified' => bool, 'reason' => string]
     */
    public static function checkQualification(array $char): array {
        // 检查是否是取经人
        if (($char['obstacle/qujing'] ?? '') === 'ren') {
            return ['qualified' => false, 'reason' => '取经人不能使用天魔茧'];
        }
        
        // 检查战斗经验
        $combatExp = intval($char['combat_exp'] ?? 0);
        if ($combatExp < 500000) {
            return ['qualified' => false, 'reason' => '道行太浅（需50万以上战斗经验），驾驭不了天魔茧'];
        }
        
        // 检查是否有no_qujing标记（入过魔道的不能再取经，但可以借宝？）
        // 原始LPC中no_sell是"你找死啊"，说明是特殊物品
        // 从NpcInquiryHelper看，有no_qujing标记的需要等24小时冷却
        
        return ['qualified' => true, 'reason' => ''];
    }
}
