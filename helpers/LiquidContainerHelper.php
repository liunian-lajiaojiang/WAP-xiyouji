<?php
/**
 * 液体容器系统助手类
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 液体容器（酒袋、葫芦等）有容量概念，每次喝消耗1份而非整个物品。
 * 可在有水源的房间用 fill 命令灌满。
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../models/Character.php';

class LiquidContainerHelper {

    /**
     * 判断物品是否为液体容器
     * @param array $item 物品数据（需含 max_liquid 字段）
     * @return bool
     */
    public static function isLiquidContainer(array $item): bool {
        return isset($item['max_liquid']) && (int)$item['max_liquid'] > 0;
    }

    /**
     * 判断背包物品是否为已初始化的液体容器（liquid_remaining 已设置）
     * @param array $inventoryItem 背包物品数据
     * @return bool
     */
    public static function isInitializedLiquidContainer(array $inventoryItem): bool {
        // 必须是液体容器（max_liquid > 0）
        if (!self::isLiquidContainer($inventoryItem)) {
            return false;
        }
        // 只要 liquid_remaining 已设置即为"已初始化"
        // 未设置（NULL）时，显示逻辑会用默认液体来兜底
        return isset($inventoryItem['liquid_remaining']) && $inventoryItem['liquid_remaining'] !== null && $inventoryItem['liquid_remaining'] !== '';
    }

    /**
     * 快速判断：物品是否需要在显示时给出液体状态信息（用于 inventory/room 页面）
     * @param array $inventoryItem
     * @return bool
     */
    public static function shouldShowLiquidStatus(array $inventoryItem): bool {
        return self::isLiquidContainer($inventoryItem);
    }

    /**
     * 获取容器液体状态描述
     * 参考原始项目 liquid.c::extra_long()
     * @param int $remaining 剩余份数
     * @param int $max 最大份数
     * @param string $liquidName 液体名称
     * @return string 描述文字
     */
    public static function getStatusText(int $remaining, int $max, string $liquidName = ''): string {
        if ($remaining <= 0) {
            return '已经空了';
        }
        if ($remaining == $max) {
            return "里面装满了{$liquidName}";
        }
        if ($remaining > $max / 2) {
            return "里面装了七、八分满的{$liquidName}";
        }
        if ($remaining >= $max / 3) {
            return "里面装了五、六分满的{$liquidName}";
        }
        return "里面装了少许的{$liquidName}";
    }

    /**
     * 初始化液体容器状态（购买/获取时调用）
     * @param int $charId 角色ID
     * @param int $inventoryId 背包记录ID
     * @param int $maxLiquid 最大份数
     * @param string $liquidType 液体类型
     * @param string $liquidName 液体名称
     * @return bool
     */
    public static function initLiquidState(int $charId, int $inventoryId, int $maxLiquid, string $liquidType, string $liquidName): bool {
        $sql = "UPDATE character_inventory 
                SET liquid_remaining = ?, liquid_type = ?, liquid_name = ?
                WHERE id = ? AND char_id = ?";
        return Database::execute($sql, [$maxLiquid, $liquidType, $liquidName, $inventoryId, $charId]) > 0;
    }

    /**
     * 从液体容器喝一份
     * @param int $charId 角色ID
     * @param array $inventoryItem 背包物品数据
     * @return array ['success' => bool, 'message' => string]
     */
    public static function drinkFromContainer(int $charId, array $inventoryItem): array {
        $remaining = (int)($inventoryItem['liquid_remaining'] ?? 0);
        $maxLiquid = (int)($inventoryItem['max_liquid'] ?? 0);
        $liquidName = $inventoryItem['liquid_name'] ?? '液体';
        $liquidType = $inventoryItem['liquid_type'] ?? 'water';
        $itemName = $inventoryItem['name'] ?? '容器';
        $invId = $inventoryItem['id'] ?? 0;
        $itemId = $inventoryItem['item_id'] ?? '';

        if ($remaining <= 0) {
            if ($liquidName) {
                return ['success' => false, 'message' => "{$itemName}里的{$liquidName}已经被喝得一滴也不剩了。"];
            }
            return ['success' => false, 'message' => "{$itemName}是空的。"];
        }

        // 检查角色饮水上限
        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        $maxWater = (int)($char['max_water'] ?? 300);
        $currentWater = (int)($char['water'] ?? 0);
        if ($currentWater >= $maxWater) {
            return ['success' => false, 'message' => '你已经喝太多了，再也灌不下一滴水了。'];
        }

        // 减少1份液体
        $newRemaining = $remaining - 1;
        $sql = "UPDATE character_inventory SET liquid_remaining = ? WHERE id = ?";
        Database::execute($sql, [$newRemaining, $invId]);

        // 增加饮水值 (+30)
        $newWater = min($maxWater, $currentWater + 30);
        $sql = "UPDATE characters SET water = ? WHERE id = ?";
        Database::execute($sql, [$newWater, $charId]);

        // 检查是否含有迷魂散效果
        $slumberEffect = 0;
        $enchantments = $inventoryItem['enchantments'] ?? '';
        if (!empty($enchantments)) {
            $enchData = json_decode($enchantments, true);
            if ($enchData && isset($enchData['slumber_effect'])) {
                $slumberEffect = (int)$enchData['slumber_effect'];
            }
        }

        // 构建消息
        $msg = "你拿起{$itemName}咕噜噜地喝了几口{$liquidName}。";
        if ($newRemaining <= 0) {
            $msg .= "\n你已经将{$itemName}里的{$liquidName}喝得一滴也不剩了。";
        }

        // 获取饮水状态描述
        require_once __DIR__ . '/FoodWaterHelper.php';
        $statusText = FoodWaterHelper::waterStatusText($newWater, $maxWater);
        $msg .= "\n（饮水 +30）当前状态：{$statusText}";

        // 如果是酒（alcohol类型），增加酒醉值
        if ($liquidType === 'alcohol') {
            require_once __DIR__ . '/StatusEffectHelper.php';
            
            // 获取物品的酒醉值
            $drunkApply = 0;
            if (!empty($itemId)) {
                $itemData = Database::queryOne(
                    "SELECT drunk_apply FROM items WHERE item_id = ? LIMIT 1",
                    [$itemId]
                );
                if ($itemData && !empty($itemData['drunk_apply'])) {
                    $drunkApply = (int)$itemData['drunk_apply'];
                }
            }
            
            // 如果物品没有配置酒醉值，用默认值
            if ($drunkApply <= 0) {
                $drunkApply = 10; // 默认酒醉值
            }
            
            // 增加酒醉状态
            $currentDrunk = StatusEffectHelper::getDrunkLevel($charId);
            $newDrunkValue = $currentDrunk + $drunkApply;
            
            // 计算酒醉上限
            $con = (int)($char['con'] ?? 10);
            $maxForce = (int)($char['max_force'] ?? 0);
            $drunkLimit = $con * 6 + (int)($maxForce / 50);
            
            // 计算持续时间（酒醉值越高，持续时间越长）
            $duration = max(15, (int)($newDrunkValue * 1.5));
            
            // 添加或更新酒醉状态
            StatusEffectHelper::addCondition($charId, StatusEffectHelper::TYPE_DRUNK, [
                'value' => $newDrunkValue,
                'duration' => $duration,
                'source' => 'drink_' . $itemId
            ]);
            
            // 酒醉提示
            if ($newDrunkValue > $drunkLimit) {
                $msg .= "\n\n你感到天旋地转，一头栽倒在地，不省人事！";
            } else if ($newDrunkValue > (int)($drunkLimit / 5)) {
                $msg .= "\n\n你觉得脑中昏昏沉沉，身子轻飘飘地，大概是醉了。";
            } else if ($newDrunkValue > (int)($drunkLimit / 10)) {
                $msg .= "\n\n你感到一阵酒意上冲，眼皮有些沉重了。";
            } else {
                $msg .= "\n\n你微微有些醉意。";
            }
        }

        // 如果含有迷魂散效果，设置睡眠状态
        if ($slumberEffect > 0) {
            $msg .= "\n\n你感到一阵眩晕，眼皮越来越重...\n你迷迷糊糊地睡了过去。";
            
            // 设置睡眠状态（持续时间基于 slumber_effect 值，单位：秒）
            $sleepEndTime = time() + $slumberEffect;
            Database::execute(
                'UPDATE characters SET sleep_state = 1, sleep_end_time = ? WHERE id = ?',
                [$sleepEndTime, $charId]
            );

            // 清除容器中的迷魂散效果
            Database::execute(
                "UPDATE character_inventory SET enchantments = JSON_REMOVE(COALESCE(enchantments, JSON_OBJECT()), '$.slumber_effect') WHERE id = ?",
                [$invId]
            );
        }

        return [
            'success' => true,
            'message' => $msg,
            'skip_queue' => true,
            'remaining' => $newRemaining,
            'water' => $newWater
        ];
    }

    /**
     * 从水源灌满容器
     * @param int $charId 角色ID
     * @param array $inventoryItem 背包物品数据
     * @param string $waterSourceType 水源类型 (water/spring/alcohol)
     * @return array ['success' => bool, 'message' => string]
     */
    public static function fillContainer(int $charId, array $inventoryItem, string $waterSourceType): array {
        $maxLiquid = (int)($inventoryItem['max_liquid'] ?? 0);
        $remaining = (int)($inventoryItem['liquid_remaining'] ?? 0);
        $oldLiquidName = $inventoryItem['liquid_name'] ?? '';
        $itemName = $inventoryItem['name'] ?? '容器';
        $invId = $inventoryItem['id'] ?? 0;

        if ($maxLiquid <= 0) {
            return ['success' => false, 'message' => "{$itemName}不能装液体。"];
        }

        if ($remaining >= $maxLiquid) {
            return ['success' => false, 'message' => "{$itemName}已经满了。"];
        }

        // 确定新液体类型和名称
        $liquidType = $waterSourceType;
        switch ($waterSourceType) {
            case 'spring':  $liquidName = '泉水'; break;
            case 'alcohol': $liquidName = '女儿红'; break;
            default:        $liquidName = '清水'; break;
        }

        $messages = [];

        // 如果还有剩余，先倒掉
        if ($remaining > 0 && $oldLiquidName) {
            $messages[] = "你将{$itemName}里剩下的{$oldLiquidName}倒掉。";
        }

        // 灌满
        $sql = "UPDATE character_inventory 
                SET liquid_remaining = ?, liquid_type = ?, liquid_name = ?
                WHERE id = ?";
        Database::execute($sql, [$maxLiquid, $liquidType, $liquidName, $invId]);

        $messages[] = "你将{$itemName}装满{$liquidName}。";

        return [
            'success' => true,
            'message' => implode("\n", $messages),
            'skip_queue' => true,
            'remaining' => $maxLiquid,
            'liquid_name' => $liquidName
        ];
    }

    /**
     * 获取当前房间的水源类型
     * @param string $area 区域
     * @param string $roomId 房间ID
     * @return string|null 水源类型或null
     */
    public static function getRoomWaterSource(string $area, string $roomId): ?string {
        $fullRoomId = "{$area}/{$roomId}";
        $sql = "SELECT has_water_source FROM rooms WHERE room_id = ? OR room_id = ? LIMIT 1";
        $result = Database::queryOne($sql, [$fullRoomId, $roomId]);
        return $result ? ($result['has_water_source'] ?? null) : null;
    }

    /**
     * 获取液体容器的默认液体配置
     * @param string $itemId 物品ID
     * @return array ['type' => string, 'name' => string]
     */
    public static function getDefaultLiquid(string $itemId): array {
        $defaults = [
            'jiudai'          => ['type' => 'alcohol', 'name' => '米酒'],
            'guihua-jiudai'   => ['type' => 'alcohol', 'name' => '桂花酒'],
            'huadiao-jiudai'  => ['type' => 'alcohol', 'name' => '花雕酒'],
            'niupi-jiudai'    => ['type' => 'alcohol', 'name' => '米酒'],
            'hdjiudai'        => ['type' => 'alcohol', 'name' => '花雕酒'],
            'jiunang'         => ['type' => 'alcohol', 'name' => '羊奶酒'],
            'hulu'            => ['type' => 'water',   'name' => '清水'],
            'baijiu'          => ['type' => 'alcohol', 'name' => '白酒'],
            'jiuhulu'         => ['type' => 'alcohol', 'name' => '烈酒'],
            'jiuhu'           => ['type' => 'alcohol', 'name' => '白烧酒'],
        ];
        return $defaults[$itemId] ?? ['type' => 'water', 'name' => '清水'];
    }
}
