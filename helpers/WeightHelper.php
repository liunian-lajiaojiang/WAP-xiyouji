<?php
/**
 * 负重系统工具类 - 处理角色负重相关计算与检查
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../models/Character.php';

class WeightHelper
{
    /**
     * 计算角色最大负重（还原原始LPC项目逻辑）
     * 公式: BASE_WEIGHT(40000) + (str - 10) * 2000
     * 物品重量单位为克，基础负重约40公斤
     * @param array $char 角色数据（需包含str字段）
     * @return int 最大负重值
     */
    public static function getMaxCarryWeight(array $char): int
    {
        $BASE_WEIGHT = 40000;
        $str = intval($char['str'] ?? 10);
        return $BASE_WEIGHT + ($str - 10) * 2000;
    }

    /** 被困者基础体重（克）：约50公斤 */
    const VICTIM_BODY_WEIGHT = 50000;

    /**
     * 计算角色当前携带重量（实时从数据库计算）
     * 包含：背包物品重量 + 法宝内被困者的重量（身体+其背包物品）
     * 货币物品（gold, silver, coin）不计入负重（还原原始LPC项目逻辑）
     * @param int $charId 角色ID
     * @return int 当前携带总重量
     */
    public static function getCurrentCarryWeight(int $charId): int
    {
        // 1. 背包物品重量
        $sql = "SELECT COALESCE(SUM(
                    COALESCE((SELECT i.weight FROM items i 
                     WHERE i.item_id = ci.item_id 
                     LIMIT 1), 0) * ci.quantity
                ), 0) AS total_weight
                FROM character_inventory ci
                WHERE ci.char_id = ? AND ci.quantity > 0 
                AND ci.item_id NOT IN ('gold', 'silver', 'coin', 'copper')";
        $row = Database::queryOne($sql, [$charId]);
        $inventoryWeight = intval($row['total_weight'] ?? 0);

        // 2. 法宝内被困者的重量
        $victimsWeight = self::getTrappedVictimsWeight($charId);

        return $inventoryWeight + $victimsWeight;
    }

    /**
     * 计算角色法宝中所有被困者的总重量
     * 仅计入 trap 类型（吸入型法宝），bind 类型不增加负重（受害者仍在原地）
     * @param int $trapperId 捕手角色ID
     * @return int 被困者总重量（克）
     */
    public static function getTrappedVictimsWeight(int $trapperId): int
    {
        $traps = Database::queryAll(
            "SELECT victim_id, trap_type FROM fabao_trap_state 
             WHERE trapper_id = ? AND is_released = 0 AND trap_type = 'trap'",
            [$trapperId]
        );

        if (empty($traps)) {
            return 0;
        }

        $totalWeight = 0;
        foreach ($traps as $trap) {
            $victimId = intval($trap['victim_id']);

            // 检查是否是玩家（characters表）
            $char = Database::queryOne(
                "SELECT id FROM characters WHERE id = ?",
                [$victimId]
            );

            if ($char) {
                // 玩家受害者：身体重量 + 其背包物品重量
                $totalWeight += self::VICTIM_BODY_WEIGHT;
                $totalWeight += self::getCurrentCarryWeight($victimId);
            } else {
                // 检查是否是NPC（npcs表）
                $npc = Database::queryOne(
                    "SELECT id FROM npcs WHERE id = ?",
                    [$victimId]
                );
                if ($npc) {
                    // NPC受害者：身体重量（NPC无背包）
                    $totalWeight += self::VICTIM_BODY_WEIGHT;
                }
                // 既不是玩家也不是已知NPC，不计入重量（安全兜底）
            }
        }

        return $totalWeight;
    }

    /**
     * 检查是否可以拾取指定物品
     * @param int    $charId   角色ID
     * @param string $itemId   物品item_id
     * @param int    $quantity 数量
     * @return array ['success' => bool, 'message' => string]
     */
    public static function canPickUp(int $charId, string $itemId, int $quantity = 1): array
    {
        // 查询物品单件重量（优先匹配非空category）
        $itemRow = Database::queryOne(
            "SELECT weight FROM items WHERE item_id = ? ORDER BY CASE WHEN category != '' THEN 0 ELSE 1 END LIMIT 1",
            [$itemId]
        );
        if (!$itemRow) {
            // 物品不存在于 items 表，按 0 重量处理（允许拾取）
            return ['success' => true, 'message' => ''];
        }
        $itemWeight = intval($itemRow['weight'] ?? 0);

        // 计算拾取后的总重量
        $currentWeight = self::getCurrentCarryWeight($charId);
        $addedWeight   = $itemWeight * $quantity;
        $newWeight     = $currentWeight + $addedWeight;

        // 获取角色信息，计算最大负重
        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在。'];
        }
        $maxWeight = self::getMaxCarryWeight($char);

        // 检查物品个数硬性上限（30），排除货币物品
        $countRow = Database::queryOne(
            "SELECT COALESCE(SUM(quantity), 0) AS total_qty
             FROM character_inventory
             WHERE char_id = ? AND item_id NOT IN ('gold', 'silver', 'coin', 'copper')",
            [$charId]
        );
        $currentQty = intval($countRow['total_qty'] ?? 0);
        if ($currentQty + $quantity > 30) {
            return [
                'success' => false,
                'message' => '背包物品数量已达上限（30件），无法继续拾取。',
            ];
        }

        // 检查负重上限
        if ($newWeight > $maxWeight) {
            return [
                'success' => false,
                'message' => sprintf(
                    '负重超限！当前负重 %d，最大负重 %d，该物品重量 %d。',
                    $currentWeight,
                    $maxWeight,
                    $addedWeight
                ),
            ];
        }

        return ['success' => true, 'message' => ''];
    }

    /**
     * 获取负重状态信息
     * @param int $charId 角色ID
     * @return array ['current' => int, 'max' => int, 'percent' => float, 'status' => string, 'display_current' => string, 'display_max' => string]
     */
    public static function getWeightStatus(int $charId): array
    {
        $current = self::getCurrentCarryWeight($charId);

        $char = CharacterModel::find($charId);
        $max  = $char ? self::getMaxCarryWeight($char) : 40000;

        $percent = $max > 0 ? round($current / $max * 100, 1) : 0.0;

        if ($percent > 100) {
            $status = '超重';
        } elseif ($percent >= 80) {
            $status = '不堪重负';
        } elseif ($percent >= 50) {
            $status = '有些沉重';
        } else {
            $status = '轻松';
        }

        return [
            'current' => $current,
            'max'     => $max,
            'percent' => $percent,
            'status'  => $status,
            'display_current' => round($current / 1000, 1) . 'kg',
            'display_max' => round($max / 1000, 1) . 'kg',
        ];
    }
}
