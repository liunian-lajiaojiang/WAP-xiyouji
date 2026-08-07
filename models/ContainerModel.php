<?php
/**
 * 容器模型
 * 处理容器（布袋、箱子等）的物品管理
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/Item.php';

class ContainerModel {
    
    /**
     * 获取容器内的所有物品
     * @param string $containerType 容器类型
     * @param int $containerId 容器ID
     * @return array
     */
    public static function getContainerItems(string $containerType, int $containerId): array {
        $sql = "SELECT ci.*, 
                       COALESCE(NULLIF(ci.item_name, ''), gi.name, ci.item_id) as name,
                       gi.description, gi.type, gi.unit, gi.weight, gi.value,
                       gi.is_container, gi.max_items, gi.max_encumbrance,
                       gi.stackable, gi.max_stack
                FROM container_items ci
                LEFT JOIN items gi ON ci.item_id = gi.item_id AND (ci.category = gi.category OR (ci.category = '' AND gi.category != ''))
                WHERE ci.container_type = ? AND ci.container_id = ?
                ORDER BY ci.id";
        return Database::queryAll($sql, [$containerType, $containerId]);
    }
    
    /**
     * 获取容器内物品数量
     * @param string $containerType
     * @param int $containerId
     * @return int
     */
    public static function getItemCount(string $containerType, int $containerId): int {
        $sql = "SELECT COUNT(*) as cnt FROM container_items WHERE container_type = ? AND container_id = ?";
        $result = Database::queryOne($sql, [$containerType, $containerId]);
        return intval($result['cnt'] ?? 0);
    }
    
    /**
     * 获取容器当前总负重
     * @param string $containerType
     * @param int $containerId
     * @return int
     */
    public static function getCurrentEncumbrance(string $containerType, int $containerId): int {
        $sql = "SELECT SUM(ci.quantity * COALESCE(gi.weight, 0)) as total_weight
                FROM container_items ci
                LEFT JOIN items gi ON ci.item_id = gi.item_id
                WHERE ci.container_type = ? AND ci.container_id = ?";
        $result = Database::queryOne($sql, [$containerType, $containerId]);
        return intval($result['total_weight'] ?? 0);
    }
    
    /**
     * 检查容器是否还能放入物品
     * @param array $containerItem 容器物品数据（需含 max_items, max_encumbrance）
     * @param string $containerType
     * @param int $containerId
     * @param int $itemWeight 要放入物品的重量
     * @param int $quantity 数量
     * @return array ['can_put' => bool, 'reason' => string]
     */
    public static function canPutItem(array $containerItem, string $containerType, int $containerId, int $itemWeight, int $quantity = 1): array {
        $maxItems = intval($containerItem['max_items'] ?? 10);
        $maxEncumbrance = intval($containerItem['max_encumbrance'] ?? 0);
        $containerName = $containerItem['name'] ?? '容器';
        
        // 检查物品数量限制
        $currentCount = self::getItemCount($containerType, $containerId);
        if ($currentCount >= $maxItems) {
            return ['can_put' => false, 'reason' => "{$containerName}里再也装不下任何东西了。"];
        }
        
        // 检查重量限制
        if ($maxEncumbrance > 0) {
            $currentWeight = self::getCurrentEncumbrance($containerType, $containerId);
            $newWeight = $itemWeight * $quantity;
            if ($currentWeight + $newWeight > $maxEncumbrance) {
                return ['can_put' => false, 'reason' => "物品对{$containerName}而言太重了。"];
            }
        }
        
        return ['can_put' => true, 'reason' => ''];
    }
    
    /**
     * 向容器添加物品
     * @param string $containerType
     * @param int $containerId
     * @param string $itemId
     * @param string $itemName
     * @param string $category
     * @param int $quantity
     * @param string $enchantments
     * @param int|null $liquidRemaining
     * @param string|null $liquidType
     * @param string|null $liquidName
     * @return bool
     */
    public static function addItem(
        string $containerType,
        int $containerId,
        string $itemId,
        string $itemName = '',
        string $category = '',
        int $quantity = 1,
        string $enchantments = '',
        ?int $liquidRemaining = null,
        ?string $liquidType = null,
        ?string $liquidName = null
    ): bool {
        // 检查是否可堆叠且已存在
        $itemInfo = ItemModel::findByItemId($itemId, $category);
        $isStackable = $itemInfo && intval($itemInfo['stackable'] ?? 0) > 0;
        
        if ($isStackable && empty($enchantments) && $liquidRemaining === null) {
            // 可堆叠物品：检查是否已存在，合并数量
            $sql = "SELECT * FROM container_items 
                    WHERE container_type = ? AND container_id = ? AND item_id = ? AND category = ?";
            $existing = Database::queryOne($sql, [$containerType, $containerId, $itemId, $category]);
            
            if ($existing) {
                $newQuantity = $existing['quantity'] + $quantity;
                $sql = "UPDATE container_items SET quantity = ? WHERE id = ?";
                return Database::execute($sql, [$newQuantity, $existing['id']]) > 0;
            }
        }
        
        // 如果没有传入名称，尝试从物品表获取
        if (empty($itemName) && $itemInfo) {
            $itemName = $itemInfo['name'] ?? '';
        }
        
        // 不可堆叠或不存在：新增记录
        $sql = "INSERT INTO container_items (container_type, container_id, item_id, item_name, category, quantity, enchantments, liquid_remaining, liquid_type, liquid_name) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        return Database::execute($sql, [
            $containerType, $containerId, $itemId, $itemName, $category, $quantity, 
            $enchantments, $liquidRemaining, $liquidType, $liquidName
        ]) > 0;
    }
    
    /**
     * 从容器移除物品
     * @param int $containerItemId 容器物品记录ID
     * @param int $quantity
     * @return bool
     */
    public static function removeItem(int $containerItemId, int $quantity = 1): bool {
        $existing = Database::queryOne("SELECT * FROM container_items WHERE id = ?", [$containerItemId]);
        if (!$existing) {
            return false;
        }
        
        if ($existing['quantity'] <= $quantity) {
            // 删除记录
            $sql = "DELETE FROM container_items WHERE id = ?";
            return Database::execute($sql, [$containerItemId]) > 0;
        } else {
            // 减少数量
            $newQuantity = $existing['quantity'] - $quantity;
            $sql = "UPDATE container_items SET quantity = ? WHERE id = ?";
            return Database::execute($sql, [$newQuantity, $containerItemId]) > 0;
        }
    }
    
    /**
     * 根据物品ID从容器移除物品
     * @param string $containerType
     * @param int $containerId
     * @param string $itemId
     * @param string $category
     * @param int $quantity
     * @return bool
     */
    public static function removeItemById(string $containerType, int $containerId, string $itemId, string $category = '', int $quantity = 1): bool {
        $sql = "SELECT * FROM container_items 
                WHERE container_type = ? AND container_id = ? AND item_id = ? AND category = ?
                LIMIT 1";
        $existing = Database::queryOne($sql, [$containerType, $containerId, $itemId, $category]);
        if (!$existing) {
            return false;
        }
        
        return self::removeItem(intval($existing['id']), $quantity);
    }
    
    /**
     * 查找容器内的物品
     * @param string $containerType
     * @param int $containerId
     * @param string $itemName 物品名称或ID
     * @return array|null
     */
    public static function findItemByName(string $containerType, int $containerId, string $itemName): ?array {
        $items = self::getContainerItems($containerType, $containerId);
        
        foreach ($items as $item) {
            // 精确匹配名称
            if ($item['name'] === $itemName || $item['item_id'] === $itemName) {
                return $item;
            }
        }
        
        // 模糊匹配
        foreach ($items as $item) {
            if (mb_strpos($item['name'], $itemName) !== false || mb_strpos($item['item_id'], $itemName) !== false) {
                return $item;
            }
        }
        
        return null;
    }
    
    /**
     * 检查容器内是否有某物品
     * @param string $containerType
     * @param int $containerId
     * @param string $itemId
     * @param string $category
     * @return bool
     */
    public static function hasItem(string $containerType, int $containerId, string $itemId, string $category = ''): bool {
        $sql = "SELECT COUNT(*) as cnt FROM container_items 
                WHERE container_type = ? AND container_id = ? AND item_id = ? AND category = ?";
        $result = Database::queryOne($sql, [$containerType, $containerId, $itemId, $category]);
        return intval($result['cnt'] ?? 0) > 0;
    }
}
