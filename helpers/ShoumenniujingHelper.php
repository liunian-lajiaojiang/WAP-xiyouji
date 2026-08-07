<?php
/**
 * 守门牛精(shoumenniujing) 助手类
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 负责：
 * - 玩家给予装满油的油瓶时收下并放行
 * - 玩家试图通过青龙山玄英洞通道时的拦路检查
 */

require_once __DIR__ . '/../includes/db.php';

class ShoumenniujingHelper {
    
    const NPC_ID = 1744;
    const NPC_NAME = '守门牛精';
    const NPC_ID_STR = 'shoumenniujing';
    const ROOM_ID = 'qujing/qinglong/tongdao1';
    const HAS_PAID_KEY = 'niujing_has_paid'; // 已给油放行
    
    /**
     * 处理玩家给予油瓶
     * @param int $charId 角色ID
     * @param array $item 物品数据
     * @return array|null ['success' => bool, 'message' => string, 'consume_item' => bool]
     */
    public static function handleGive(int $charId, array $item): ?array {
        $itemId = $item['item_id'] ?? '';
        $itemName = $item['item_name'] ?? $item['name'] ?? '';
        
        // 检查是否是油瓶
        if ($itemId !== 'youping') {
            // 不是油瓶，不收
            return [
                'success' => false,
                'message' => self::NPC_NAME . "不耐烦地挥挥手：俺只要油瓶，别的东西不要！",
                'consume_item' => false
            ];
        }
        
        // 严格检查油瓶是否装有油
        // 必须同时满足：liquid_remaining > 0 且 liquid_type 明确是油（不能是水、酒等）
        $liquidRemaining = intval($item['liquid_remaining'] ?? 0);
        $liquidType = trim($item['liquid_type'] ?? '');
        
        // 明确的非油类型黑名单
        $nonOilTypes = ['water', 'wine', 'alcohol', 'horse_urine', 'tea', 'soup', 'juice'];
        
        if ($liquidRemaining <= 0) {
            // 空的，拒绝
            return [
                'success' => false,
                'message' => self::NPC_NAME . "接过油瓶晃了晃，怒道：空的？你耍俺老牛呢？！",
                'consume_item' => false
            ];
        }
        
        if (empty($liquidType)) {
            // liquid_type 为空，无法判断是什么液体，拒绝
            return [
                'success' => false,
                'message' => self::NPC_NAME . "接过油瓶闻了闻，疑惑道：这瓶子里装的啥？俺可只要酥合香油！",
                'consume_item' => false
            ];
        }
        
        // 检查是否是水、酒等非油液体
        if (in_array(strtolower($liquidType), $nonOilTypes)) {
            $typeNames = [
                'water' => '水',
                'wine' => '酒',
                'alcohol' => '酒',
                'horse_urine' => '马尿',
                'tea' => '茶',
                'soup' => '汤',
                'juice' => '果汁',
            ];
            $name = $typeNames[strtolower($liquidType)] ?? $liquidType;
            return [
                'success' => false,
                'message' => self::NPC_NAME . "接过油瓶闻了闻，怒道：这分明是{$name}！俺要的是酥合香油，不是{$name}！",
                'consume_item' => false
            ];
        }
        
        // 检查是否明确是油：liquid_type 必须等于 'oil' 或包含"油"字
        $isOil = ($liquidType === 'oil' || mb_stripos($liquidType, '油') !== false);
        
        if (!$isOil) {
            // liquid_type 无法识别为油，拒绝
            return [
                'success' => false,
                'message' => self::NPC_NAME . "接过油瓶闻了闻，皱眉道：这是酥合香油吗？俺怎么闻着不太对劲……",
                'consume_item' => false
            ];
        }
        
        // 收下油瓶，消耗物品
        $invId = $item['id'] ?? 0;
        if ($invId > 0) {
            Database::execute("DELETE FROM character_inventory WHERE id = ?", [$invId]);
        } else {
            Database::execute(
                "DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND COALESCE(category, '') = ?",
                [$charId, $itemId, $item['category'] ?? '']
            );
        }
        
        // 设置放行状态
        self::setTempState($charId, self::HAS_PAID_KEY, '1', time() + 86400); // 24小时有效
        
        return [
            'success' => true,
            'message' => self::NPC_NAME . "接过油瓶，凑到鼻边闻了闻，满意地点点头：嗯，好油！好油！\n" .
                         self::NPC_NAME . "侧身让开道路：进去吧，别惹事！",
            'consume_item' => true
        ];
    }
    
    /**
     * 检查玩家是否已经给过油（已放行）
     * @param int $charId 角色ID
     * @return bool
     */
    public static function hasPaid(int $charId): bool {
        $val = self::getTempState($charId, self::HAS_PAID_KEY);
        return $val && intval($val) > 0;
    }
    
    /**
     * 设置临时状态
     */
    private static function setTempState(int $charId, string $key, string $value, int $expireAt): void {
        $existing = Database::queryOne(
            "SELECT id FROM character_temp_states WHERE char_id = ? AND state_key = ?",
            [$charId, $key]
        );
        
        if ($existing) {
            Database::execute(
                "UPDATE character_temp_states SET state_value = ?, expire_time = FROM_UNIXTIME(?) WHERE id = ?",
                [$value, $expireAt, $existing['id']]
            );
        } else {
            Database::execute(
                "INSERT INTO character_temp_states (char_id, state_key, state_value, expire_time) VALUES (?, ?, ?, FROM_UNIXTIME(?))",
                [$charId, $key, $value, $expireAt]
            );
        }
    }
    
    /**
     * 获取临时状态
     */
    private static function getTempState(int $charId, string $key): ?string {
        $row = Database::queryOne(
            "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ? AND (expire_time IS NULL OR expire_time > NOW())",
            [$charId, $key]
        );
        return $row ? $row['state_value'] : null;
    }
}
