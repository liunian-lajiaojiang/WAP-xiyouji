<?php
/**
 * 商店模型 - 处理当铺、钱庄、商贩相关业务逻辑
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../helpers/MoneyHelper.php';

class ShopModel
{
    private static function ensureShopRefreshTable() {
        $exists = Database::queryOne("SHOW TABLES LIKE 'shop_refresh'");
        if (!$exists) {
            $sql = "CREATE TABLE `shop_refresh` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `shop_id` int(11) NOT NULL,
                `is_bookstore` tinyint(1) NOT NULL DEFAULT 0,
                `max_books` int(11) NOT NULL DEFAULT 30,
                `book_count` int(11) NOT NULL DEFAULT 0,
                `last_refresh_time` int(11) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `shop_id` (`shop_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='书店库存限制表'";
            Database::execute($sql);
            
            Database::execute("INSERT INTO shop_refresh (shop_id, is_bookstore, max_books, book_count) VALUES (37, 1, 30, 0)");
        }
    }

    /**
     * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
     */
    private static $sellMessages = [
        '破破烂烂，一文不值',
        '质地低劣，难看之极',
        '又脏又破，臭气熏天',
    ];
    /**
     * 获取商人的物品列表
     * @param int $npcId NPC ID
     * @return array 物品列表
     */
    public static function getShopItems(int $npcId): array
    {
        // 每次查询时顺带清理过期玩家出售物品（sold_at > 3天）
        self::cleanupExpiredItems();
        
        $sql = "SELECT si.*, 
                       COALESCE(gi.name, si.item_id) as item_name,
                       gi.description as item_desc,
                       gi.type as item_type,
                       gi.weight as item_weight,
                       gi.max_liquid as max_liquid
                FROM shop_items si
                LEFT JOIN items gi ON gi.id = (
                    SELECT id FROM items 
                    WHERE item_id = si.item_id
                    ORDER BY 
                        CASE WHEN category = si.category THEN 0 
                             WHEN category = '' THEN 1 
                             ELSE 2 
                        END,
                        CASE WHEN name IS NOT NULL AND name != '' THEN 0 ELSE 1 END,
                        id
                    LIMIT 1
                )
                WHERE si.shop_id = ? AND (si.stock > 0 OR si.stock = -1) AND (si.price > 0 OR si.price IS NULL)
                ORDER BY si.price";
        return Database::queryAll($sql, [$npcId]);
    }

    /**
     * 获取单个商店物品信息
     * @param int $npcId NPC ID
     * @param string $itemId 物品ID
     * @return array|null 物品信息
     */
    public static function getShopItem(int $npcId, string $itemId, string $category = ''): ?array
    {
        $sql = "SELECT si.*, 
                       COALESCE(gi.name, si.item_id) as item_name,
                       gi.description as item_desc,
                       gi.type as item_type,
                       gi.weight as item_weight,
                       gi.max_liquid as max_liquid
                FROM shop_items si
                LEFT JOIN items gi ON gi.id = (
                    SELECT id FROM items 
                    WHERE item_id = si.item_id
                    ORDER BY 
                        CASE WHEN category = si.category THEN 0 
                             WHEN category = '' THEN 1 
                             ELSE 2 
                        END,
                        CASE WHEN name IS NOT NULL AND name != '' THEN 0 ELSE 1 END,
                        id
                    LIMIT 1
                )
                WHERE si.shop_id = ? AND si.item_id = ? AND si.category = ?";
        return Database::queryOne($sql, [$npcId, $itemId, $category]);
    }

    /**
     * 查找满足类型的商店NPC
     * @param string $type 商店类型，如 bookstore、medicine
     * @param string $area 可选区域限制
     * @return array NPC 列表
     */
    public static function findShopNpcsByType(string $type, string $area = ''): array
    {
        $query = "SELECT * FROM npcs WHERE merchant = 1";
        $params = [];

        if ($type === 'bookstore') {
            $query .= " AND (shop_type = 'bookstore' OR spawn_room LIKE '%bookstore%')";
        } else {
            $query .= " AND shop_type = ?";
            $params[] = $type;
        }

        if (!empty($area)) {
            $query .= " AND (spawn_area = ? OR spawn_room LIKE ? OR spawn_room LIKE ? )";
            $params[] = $area;
            $params[] = "%{$area}%";
            $params[] = "%{$area}%";
        }

        $query .= " ORDER BY spawn_area = ? DESC, id ASC";
        $params[] = $area;

        return Database::queryAll($query, $params);
    }

    /**
     * 查找出售指定物品的商店位置
     * @param string $itemId 物品ID
     * @return array 商店列表
     */
    public static function findShopItemLocations(string $itemId): array
    {
        $sql = "SELECT si.*, n.id as npc_db_id, n.spawn_area, n.spawn_room, n.name as npc_name, n.shop_type 
                FROM shop_items si 
                INNER JOIN npcs n ON n.id = si.shop_id 
                WHERE si.item_id = ? AND (si.stock > 0 OR si.stock = -1) 
                ORDER BY n.spawn_area ASC, si.price ASC";
        return Database::queryAll($sql, [$itemId]);
    }

    /**
     * 购买物品
     * @param int $charId 角色ID
     * @param int $npcId NPC ID
     * @param string $itemId 物品ID
     * @param int $quantity 数量
     * @return array ['success' => bool, 'message' => string]
     */
    public static function buyItem(int $charId, int $npcId, string $itemId, int $quantity = 1, string $category = ''): array
    {
        // 获取物品信息
        $shopItem = self::getShopItem($npcId, $itemId, $category);
        if (!$shopItem) {
            return ['success' => false, 'message' => '该物品不在商店中。'];
        }

        // 检查库存
        if ($shopItem['stock'] != -1 && $shopItem['stock'] < $quantity) {
            return ['success' => false, 'message' => '库存不足。'];
        }

        // 计算总价
        $totalPrice = $shopItem['price'] * $quantity;

        // 检查角色金钱（使用货币物品）
        if (!MoneyHelper::hasEnoughMoney($charId, $totalPrice)) {
            return ['success' => false, 'message' => '你的钱不够。'];
        }

        // 扣除金钱（使用货币物品）
        MoneyHelper::deductMoney($charId, $totalPrice);

        // 添加物品到背包
        require_once __DIR__ . '/Item.php';
        
        // 药草特殊处理：购买yao（药草包）时随机获得一种具体药草
        $actualItemId = $itemId;
        $actualItemName = $shopItem['item_name'] ?? $itemId;
        if ($itemId === 'yao' && $category === 'qujing') {
            require_once __DIR__ . '/../helpers/HerbHelper.php';
            $randomHerb = HerbHelper::getRandomHerb();
            $actualItemId = $randomHerb['item_id'];
            $actualItemName = $randomHerb['name'];
        }
        
        ItemModel::addToInventory($charId, $actualItemId, $quantity, $category);

        // 液体容器：购买后初始化液体状态（按购买数量给每个容器装满默认液体）
        $maxLiquid = (int)($shopItem['max_liquid'] ?? 0);
        if ($maxLiquid > 0) {
            require_once __DIR__ . '/../helpers/LiquidContainerHelper.php';
            $defaults = LiquidContainerHelper::getDefaultLiquid($itemId);
            $liquidType = $defaults['type'];
            $liquidName = $defaults['name'];

            // 找到新添加的背包记录，逐个初始化
            $newItems = Database::queryAll(
                "SELECT id FROM character_inventory 
                 WHERE char_id = ? AND item_id = ? 
                 AND (liquid_remaining IS NULL OR liquid_remaining = 0)
                 ORDER BY id DESC LIMIT " . intval($quantity),
                [$charId, $itemId]
            );

            foreach ($newItems as $ni) {
                LiquidContainerHelper::initLiquidState(
                    $charId, (int)$ni['id'], $maxLiquid, $liquidType, $liquidName
                );
            }
        }

        // 更新库存
        if ($shopItem['stock'] != -1) {
            $sql = "UPDATE shop_items SET stock = stock - ? WHERE id = ?";
            Database::execute($sql, [$quantity, $shopItem['id']]);
        }

        return [
            'success' => true, 
            'message' => "你花费了 {$totalPrice} 铜钱购买了 {$quantity} 个 {$actualItemName}。"
        ];
    }

    /**
     * 出售物品给商店
     * @param int $charId 角色ID
     * @param int $npcId NPC ID
     * @param int $inventoryId 背包物品ID
     * @return array ['success' => bool, 'message' => string, 'sell_value' => int]
     */
    public static function sellItem(int $charId, int $npcId, int $inventoryId): array
    {
        // 获取背包物品
        $sql = "SELECT ci.*, 
                       COALESCE(gi.name, ci.item_id) as name,
                       COALESCE(gi.value, 0) as value,
                       COALESCE(gi.no_sell, 0) as no_sell
                FROM character_inventory ci
                LEFT JOIN items gi ON gi.id = (
                    SELECT id FROM items 
                    WHERE item_id = ci.item_id
                    ORDER BY 
                        CASE WHEN category = ci.category THEN 0 
                             WHEN category = '' THEN 1 
                             ELSE 2 
                        END,
                        CASE WHEN name IS NOT NULL AND name != '' THEN 0 ELSE 1 END,
                        id
                    LIMIT 1
                )
                WHERE ci.id = ? AND ci.char_id = ?";
        $item = Database::queryOne($sql, [$inventoryId, $charId]);

        if (!$item) {
            return ['success' => false, 'message' => '你没有这个物品。'];
        }

        // 检查是否是货币
        if (in_array($item['item_id'], ['gold', 'silver', 'coin'])) {
            return ['success' => false, 'message' => '不能出售货币。'];
        }

        // 检查是否已装备
        if (!empty($item['equipped'])) {
            return ['success' => false, 'message' => '装备中的物品不能出售。'];
        }

        // 检查是否不可出售（包括玩家自制法宝）
        if (!empty($item['no_sell'])) {
            return ['success' => false, 'message' => '这样东西不能出售。'];
        }

        // 检查是否为玩家自制法宝
        if (!empty($item['series_no'])) {
            $isPlayerFabao = Database::queryOne(
                "SELECT 1 FROM character_fabao WHERE owner_id = ? AND series_no = ? LIMIT 1",
                [$charId, $item['series_no']]
            );
            if ($isPlayerFabao) {
                return ['success' => false, 'message' => '你自己炼制的法宝不能出售。'];
            }
        }

        // 计算出售价值（物品价值的80%，至少1铜钱）
        $sellValue = max(1, intval($item['value'] * 0.8));
        
        // 计算商店售价（使用原价值，但至少1铜钱）
        $shopPrice = max(1, $item['value']);

        // 确保书店库存限制表存在
        self::ensureShopRefreshTable();
        
        // 书店限制检查：书店最多只能有30本书
        $shopRefresh = Database::queryOne("SELECT is_bookstore, max_books, book_count FROM shop_refresh WHERE shop_id = ?", [$npcId]);
        if ($shopRefresh && $shopRefresh['is_bookstore']) {
            $bookCount = intval($shopRefresh['book_count'] ?? 0);
            $maxBooks = intval($shopRefresh['max_books'] ?? 30);
            if ($bookCount >= $maxBooks) {
                return ['success' => false, 'message' => '书店已经摆满了书籍，掌柜的暂时不收书了。'];
            }
        }

        // 将物品添加到商店库存，成功后再从背包移除
        try {
            $existingItem = Database::queryOne(
                "SELECT id, stock FROM shop_items WHERE shop_id = ? AND item_id = ? AND category = ?",
                [$npcId, $item['item_id'], $item['category'] ?? '']
            );

            if ($existingItem) {
                // 已存在，增加库存，重置sold_at（重新计算3天期限）
                $newStock = ($existingItem['stock'] == -1) ? -1 : ($existingItem['stock'] + 1);
                Database::execute(
                    "UPDATE shop_items SET stock = ?, sold_at = NOW() WHERE id = ?",
                    [$newStock, $existingItem['id']]
                );
            } else {
                // 不存在，添加新记录（使用合理的商店售价，记录sold_at）
                Database::execute(
                    "INSERT INTO shop_items (shop_id, item_id, category, price, stock, sold_at) VALUES (?, ?, ?, ?, 1, NOW())",
                    [$npcId, $item['item_id'], $item['category'] ?? '', $shopPrice]
                );
            }

            // shop_items写入成功，从背包移除物品
            $sql = "DELETE FROM character_inventory WHERE id = ?";
            Database::execute($sql, [$inventoryId]);
            
            // 更新书店书籍数量统计
            if ($shopRefresh && $shopRefresh['is_bookstore']) {
                Database::execute("UPDATE shop_refresh SET book_count = book_count + 1 WHERE shop_id = ?", [$npcId]);
            }
        } catch (Exception $e) {
            error_log("Shop inventory update failed: " . $e->getMessage());
            return ['success' => false, 'message' => '出售失败，商店库存更新出错：' . $e->getMessage()];
        }

        // 给予金钱（使用货币物品）
        MoneyHelper::addMoney($charId, $sellValue);

        // 随机选择一条出售消息
        $msg = self::$sellMessages[array_rand(self::$sellMessages)];

        return [
            'success' => true,
            'message' => "掌柜的唱道：{$msg}{$item['name']}一件，{$sellValue}铜钱。",
            'sell_value' => $sellValue
        ];
    }

    /**
     * 清理过期的玩家出售物品（入库超过3天自动删除）
     * 仅影响 sold_at IS NOT NULL 且 stock != -1 的记录，不影响商店固有商品
     */
    public static function cleanupExpiredItems(): void
    {
        try {
            Database::execute(
                "DELETE FROM shop_items WHERE sold_at IS NOT NULL AND stock != -1 AND sold_at < DATE_SUB(NOW(), INTERVAL 3 DAY)"
            );
        } catch (Exception $e) {
            error_log("Shop cleanup failed: " . $e->getMessage());
        }
    }

    /**
     * 估价物品
     * @param int $charId 角色ID
     * @param int $npcId NPC ID
     * @param int $inventoryId 背包物品ID
     * @return array ['success' => bool, 'message' => string, 'value' => int, 'sell_value' => int]
     */
    public static function valueItem(int $charId, int $npcId, int $inventoryId): array
    {
        // 获取背包物品
        $sql = "SELECT ci.*, 
                       COALESCE(gi.name, ci.item_id) as name,
                       COALESCE(gi.value, 0) as value
                FROM character_inventory ci
                LEFT JOIN items gi ON gi.id = (
                    SELECT id FROM items 
                    WHERE item_id = ci.item_id
                    ORDER BY 
                        CASE WHEN category = ci.category THEN 0 
                             WHEN category = '' THEN 1 
                             ELSE 2 
                        END,
                        CASE WHEN name IS NOT NULL AND name != '' THEN 0 ELSE 1 END,
                        id
                    LIMIT 1
                )
                WHERE ci.id = ? AND ci.char_id = ?";
        $item = Database::queryOne($sql, [$inventoryId, $charId]);

        if (!$item) {
            return ['success' => false, 'message' => '你没有这个物品。'];
        }

        $value = $item['value'];
        $sellValue = max(1, intval($value * 0.8));

        return [
            'success' => true,
            'message' => "{$item['name']} 值 {$value} 铜钱，出售可得 {$sellValue} 铜钱。",
            'value' => $value,
            'sell_value' => $sellValue
        ];
    }

    /**
     * 钱庄存款
     * @param int $charId 角色ID
     * @param int $amount 存款金额
     * @param string $currencyType 货币类型（coin/silver/gold）
     * @return array ['success' => bool, 'message' => string]
     */
    public static function deposit(int $charId, int $amount, string $currencyType = 'coin'): array
    {
        if ($amount <= 0) {
            return ['success' => false, 'message' => '存款金额必须大于0。'];
        }

        // 验证货币类型
        if (!in_array($currencyType, ['coin', 'silver', 'gold'])) {
            return ['success' => false, 'message' => '无效的货币类型。'];
        }

        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在。'];
        }

        // 转换为铜钱计算
        $amountInCoin = self::convertToCoin($amount, $currencyType);
        
        // 检查角色金钱（使用货币物品）
        if (!MoneyHelper::hasEnoughMoney($charId, $amountInCoin)) {
            return ['success' => false, 'message' => '你的钱不够。'];
        }

        // 智能扣款：先尝试直接扣除，如果不够则自动兑换
        $money = MoneyHelper::getMoneyInventory($charId);
        
        // 计算需要扣除的各种货币数量
        $goldToDeduct = 0;
        $silverToDeduct = 0;
        $coinToDeduct = 0;
        
        $remaining = $amountInCoin;
        
        // 1. 先扣铜钱
        $coinToDeduct = min($money['coin'], $remaining);
        $remaining -= $coinToDeduct;
        
        // 2. 再扣银子
        if ($remaining > 0) {
            $silverNeeded = ceil($remaining / 100);
            $silverToDeduct = min($money['silver'], $silverNeeded);
            $remaining -= $silverToDeduct * 100;
        }
        
        // 3. 最后扣黄金
        if ($remaining > 0) {
            $goldNeeded = ceil($remaining / 10000);
            $goldToDeduct = min($money['gold'], $goldNeeded);
            $remaining -= $goldToDeduct * 10000;
        }
        
        // 执行扣除
        if ($goldToDeduct > 0) {
            ItemModel::removeFromInventory($charId, 'gold', $goldToDeduct);
        }
        if ($silverToDeduct > 0) {
            ItemModel::removeFromInventory($charId, 'silver', $silverToDeduct);
        }
        if ($coinToDeduct > 0) {
            ItemModel::removeFromInventory($charId, 'coin', $coinToDeduct);
        }
        
        // 如果有剩余（多扣了），需要找零
        if ($remaining < 0) {
            $change = -$remaining;  // 找零金额（铜钱）
            MoneyHelper::addMoney($charId, $change);
        }

        // 增加存款（以铜钱为单位存储）
        $newBalance = ($char['balance'] ?? 0) + $amountInCoin;
        $sql = "UPDATE characters SET balance = ? WHERE id = ?";
        Database::execute($sql, [$newBalance, $charId]);

        // 获取货币名称
        $currencyName = self::getCurrencyName($currencyType);

        return [
            'success' => true,
            'message' => "你存入 {$amount} {$currencyName}。"
        ];
    }

    /**
     * 钱庄取款
     * @param int $charId 角色ID
     * @param int $amount 取款金额
     * @param string $currencyType 取款货币类型（coin/silver/gold）
     * @return array ['success' => bool, 'message' => string]
     */
    public static function withdraw(int $charId, int $amount, string $currencyType = 'coin'): array
    {
        if ($amount <= 0) {
            return ['success' => false, 'message' => '取款金额必须大于0。'];
        }

        // 验证货币类型
        if (!in_array($currencyType, ['coin', 'silver', 'gold'])) {
            return ['success' => false, 'message' => '无效的货币类型。'];
        }

        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在。'];
        }

        // 转换为铜钱计算
        $amountInCoin = self::convertToCoin($amount, $currencyType);
        
        $balance = $char['balance'] ?? 0;
        if ($balance < $amountInCoin) {
            return ['success' => false, 'message' => '你的存款不足。'];
        }

        // 减少存款
        $newBalance = $balance - $amountInCoin;
        $sql = "UPDATE characters SET balance = ? WHERE id = ?";
        Database::execute($sql, [$newBalance, $charId]);

        // 根据选择的货币类型，给予对应的货币物品
        ItemModel::addToInventory($charId, $currencyType, $amount);

        // 获取货币名称
        $currencyName = self::getCurrencyName($currencyType);

        return [
            'success' => true,
            'message' => "你取出 {$amount} {$currencyName}。"
        ];
    }

    /**
     * 查询账户余额
     * @param int $charId 角色ID
     * @return array ['success' => bool, 'message' => string, 'balance' => int]
     */
    public static function checkAccount(int $charId): array
    {
        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在。'];
        }

        $balance = $char['balance'] ?? 0;

        if ($balance <= 0) {
            return [
                'success' => true,
                'message' => '你在钱庄没有存款。',
                'balance' => 0
            ];
        }

        return [
            'success' => true,
            'message' => '你在钱庄的存款为：' . self::formatMoney($balance),
            'balance' => $balance
        ];
    }

    /**
     * 货币兑换
     * @param int $charId 角色ID
     * @param string $fromType 源货币类型：gold/silver/coin
     * @param string $toType 目标货币类型：gold/silver/coin
     * @param int $amount 兑换数量
     * @return array ['success' => bool, 'message' => string]
     */
    public static function convertMoney(int $charId, string $fromType, string $toType, int $amount): array
    {
        if ($amount <= 0) {
            return ['success' => false, 'message' => '兑换数量必须大于0。'];
        }

        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在。'];
        }

        // 汇率：1金=100银=10000铜
        $rates = [
            'gold' => 10000,
            'silver' => 100,
            'coin' => 1
        ];

        if (!isset($rates[$fromType]) || !isset($rates[$toType])) {
            return ['success' => false, 'message' => '无效的货币类型。'];
        }

        // 检查是否有足够的源货币（使用货币物品）
        $money = MoneyHelper::getMoneyInventory($charId);
        if ($fromType === 'gold' && $money['gold'] < $amount) {
            return ['success' => false, 'message' => "你的黄金不足。"];
        } elseif ($fromType === 'silver' && $money['silver'] < $amount) {
            return ['success' => false, 'message' => "你的银子不足。"];
        } elseif ($fromType === 'coin' && $money['coin'] < $amount) {
            return ['success' => false, 'message' => "你的铜钱不足。"];
        }

        // 计算兑换后的目标货币数量
        $totalValue = $amount * $rates[$fromType];
        $toAmount = intval($totalValue / $rates[$toType]);

        if ($toAmount <= 0) {
            return ['success' => false, 'message' => '兑换数量太小。'];
        }

        // 扣除源货币（使用货币物品）
        ItemModel::removeFromInventory($charId, $fromType, $amount);

        // 增加目标货币（使用货币物品）
        ItemModel::addToInventory($charId, $toType, $toAmount);

        return [
            'success' => true,
            'message' => "你将 {$amount} {$fromType} 兑换成 {$toAmount} {$toType}。"
        ];
    }

    /**
     * 将指定货币类型转换为铜钱
     * @param int $amount 金额
     * @param string $currencyType 货币类型（coin/silver/gold）
     * @return int 转换后的铜钱数量
     */
    private static function convertToCoin(int $amount, string $currencyType): int
    {
        switch ($currencyType) {
            case 'gold':
                return $amount * 10000;  // 1黄金 = 10000铜钱
            case 'silver':
                return $amount * 100;    // 1银子 = 100铜钱
            case 'coin':
            default:
                return $amount;          // 铜钱不变
        }
    }

    /**
     * 获取货币类型的中文名称
     * @param string $currencyType 货币类型（coin/silver/gold）
     * @return string 货币名称
     */
    private static function getCurrencyName(string $currencyType): string
    {
        switch ($currencyType) {
            case 'gold':
                return '两黄金';
            case 'silver':
                return '两银子';
            case 'coin':
            default:
                return '铜钱';
        }
    }

    /**
     * 格式化金钱显示
     * @param int $amount 金额（铜钱）
     * @return string 格式化后的字符串
     */
    public static function formatMoney(int $amount): string
    {
        if ($amount <= 0) {
            return '0 铜钱';
        }

        $gold = intval($amount / 10000);
        $silver = intval(($amount % 10000) / 100);
        $coin = $amount % 100;

        $parts = [];
        if ($gold > 0) {
            $parts[] = "{$gold} 两黄金";
        }
        if ($silver > 0) {
            $parts[] = "{$silver} 两银子";
        }
        if ($coin > 0) {
            $parts[] = "{$coin} 铜钱";
        }

        return implode(' ', $parts);
    }
}

