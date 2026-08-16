<?php
/**
 * 守门牛精(shoumenniujing) 助手类
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 *
 * 负责：
 * - 玩家给予装满酥合香油的油葫芦时收下并放行
 * - 玩家试图通过青龙山玄英洞通道时的拦路检查
 * 对照原始 LPC: xyj2000/d/qujing/qinglong/npc/xiniu.c accept_object()
 */

require_once __DIR__ . '/../includes/db.php';

class ShoumenniujingHelper {
    
    const NPC_ID = 1744;
    const NPC_NAME = '守门牛精';
    const NPC_ID_STR = 'shoumenniujing';
    const ROOM_ID = 'qujing/qinglong/tongdao1';
    const HAS_PAID_KEY = 'niujing_has_paid'; // 已给油放行
    
    /**
     * 处理玩家给予油葫芦
     * 对照原始 LPC: xiniu.c accept_object() - 检查物品名称是否为"油葫芦"，且有油
     * @param int $charId 角色ID
     * @param array $item 物品数据
     * @return array|null ['success' => bool, 'message' => string, 'consume_item' => bool]
     */
    public static function handleGive(int $charId, array $item): ?array {
        $itemId = $item['item_id'] ?? '';
        $itemName = $item['item_name'] ?? $item['name'] ?? '';

        // 检查是否是油葫芦/油瓶（对照LPC原版: ob->query("name") != "油葫芦"）
        // items表有三种油容器: hulu(油葫芦,qujing)、youhulu(油葫芦,food)、youping(油瓶,city/obj)
        $isHulu = ($itemName === '油葫芦' ||
                   $itemName === '油瓶' ||
                   $itemId === 'hulu' ||
                   $itemId === 'youhulu' ||
                   $itemId === 'youping');

        if (!$isHulu) {
            // 不是油葫芦/油瓶，不收
            return [
                'success' => false,
                'message' => self::NPC_NAME . "摇了摇头说：俺不要你的" . $itemName . "。",
                'consume_item' => false
            ];
        }

        // 检查油葫芦是否装有酥合香油（对照LPC原版: ob->query("liquid/remaining") == 0）
        $liquidRemaining = intval($item['liquid_remaining'] ?? 0);
        $liquidType = trim($item['liquid_type'] ?? '');
        $liquidName = trim($item['liquid_name'] ?? '');

        if ($liquidRemaining <= 0) {
            // 空的，拒绝（对照LPC: "油葫芦是空的。"）
            return [
                'success' => false,
                'message' => self::NPC_NAME . "摇了摇头说：这" . $itemName . "是空的。",
                'consume_item' => false
            ];
        }

        // 检查液体是否是酥合香油
        $isSuhuOil = ($liquidType === 'oil' ||
                      mb_stripos($liquidName, '酥合') !== false ||
                      mb_stripos($liquidName, '香油') !== false ||
                      mb_stripos($liquidType, '油') !== false);

        if (!$isSuhuOil) {
            return [
                'success' => false,
                'message' => self::NPC_NAME . "闻了闻，皱眉道：这不是酥合香油，俺不要！",
                'consume_item' => false
            ];
        }

        // 收下油葫芦，消耗物品（对照LPC: call_out("destruct_me",1,ob)）
        $invId = $item['id'] ?? 0;
        if ($invId > 0) {
            Database::execute("DELETE FROM character_inventory WHERE id = ?", [$invId]);
        } else {
            Database::execute(
                "DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND COALESCE(category, '') = ?",
                [$charId, $itemId, $item['category'] ?? '']
            );
        }

        // 设置放行状态（对照LPC: who->set_temp("obstacle/jinping_give_hulu",1)）
        self::setTempState($charId, self::HAS_PAID_KEY, '1', time() + 86400); // 24小时有效

        return [
            'success' => true,
            'message' => self::NPC_NAME . "说了声：谢谢。\n" .
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
