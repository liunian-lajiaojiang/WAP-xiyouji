<?php
/**
 * 货币助手类 - 处理货币物品相关操作
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../models/Item.php';
require_once __DIR__ . '/../models/Character.php';

class MoneyHelper
{
    /**
     * 获取角色的货币（合并表字段和背包物品）
     * @param int $charId 角色ID
     * @return array ['gold' => int, 'silver' => int, 'coin' => int]
     */
    public static function getMoneyInventory(int $charId): array
    {
        // 获取背包中的货币物品
        $sql = "SELECT ci.item_id, ci.quantity 
                FROM character_inventory ci
                WHERE ci.char_id = ? AND ci.item_id IN ('gold', 'silver', 'coin')";
        $items = Database::queryAll($sql, [$charId]);
        
        $money = ['gold' => 0, 'silver' => 0, 'coin' => 0];
        foreach ($items as $item) {
            $money[$item['item_id']] = intval($item['quantity']);
        }
        
        // 获取表字段中的货币
        $char = CharacterModel::find($charId);
        if ($char) {
            $money['gold'] += intval($char['gold'] ?? 0);
            $money['silver'] += intval($char['silver'] ?? 0);
            $money['coin'] += intval($char['copper'] ?? 0);
        }
        
        return $money;
    }
    
    /**
     * 计算角色的总财富（以铜钱为单位）
     * @param int $charId 角色ID
     * @return int 总财富（铜钱）
     */
    public static function getTotalWealth(int $charId): int
    {
        $money = self::getMoneyInventory($charId);
        return $money['gold'] * 10000 + $money['silver'] * 100 + $money['coin'];
    }
    
    /**
     * 检查角色是否有足够的钱
     * @param int $charId 角色ID
     * @param int $amount 需要的金额（铜钱）
     * @return bool
     */
    public static function hasEnoughMoney(int $charId, int $amount): bool
    {
        return self::getTotalWealth($charId) >= $amount;
    }
    
    /**
     * 从角色背包中扣除指定金额的货币
     * 优先扣除铜钱，然后是银子，最后是黄金
     * @param int $charId 角色ID
     * @param int $amount 需要扣除的金额（铜钱）
     * @return bool 是否成功扣除
     */
    public static function deductMoney(int $charId, int $amount): bool
    {
        if (!self::hasEnoughMoney($charId, $amount)) {
            return false;
        }
        
        // 首先将表字段中的货币迁移到背包
        self::migrateTableMoneyToInventory($charId);
        
        $remaining = $amount;
        
        // 1. 先扣铜钱
        $money = self::getMoneyInventory($charId);
        $coinToDeduct = min($money['coin'], $remaining);
        if ($coinToDeduct > 0) {
            ItemModel::removeFromInventory($charId, 'coin', $coinToDeduct);
            $remaining -= $coinToDeduct;
        }
        
        // 2. 再扣银子（1银= 100铜）
        if ($remaining > 0 && $money['silver'] > 0) {
            $silverNeeded = ceil($remaining / 100);
            $silverToDeduct = min($money['silver'], $silverNeeded);
            ItemModel::removeFromInventory($charId, 'silver', $silverToDeduct);
            $remaining -= $silverToDeduct * 100;
        }
        
        // 3. 最后扣黄金（1金= 10000铜= 100银）
        if ($remaining > 0 && $money['gold'] > 0) {
            $goldNeeded = ceil($remaining / 10000);
            $goldToDeduct = min($money['gold'], $goldNeeded);
            ItemModel::removeFromInventory($charId, 'gold', $goldToDeduct);
            $remaining -= $goldToDeduct * 10000;
        }
        
        return true;
    }
    
    /**
     * 给角色添加货币
     * 自动合并为最大面额
     * @param int $charId 角色ID
     * @param int $amount 要添加的金额（铜钱）
     */
    public static function addMoney(int $charId, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        
        // 首先将表字段中的货币迁移到背包
        self::migrateTableMoneyToInventory($charId);
        
        // 转换为最大面额
        $gold = floor($amount / 10000);
        $remaining = $amount % 10000;
        $silver = floor($remaining / 100);
        $coin = $remaining % 100;
        
        // 添加物品到背包
        if ($gold > 0) {
            ItemModel::addToInventory($charId, 'gold', $gold);
        }
        if ($silver > 0) {
            ItemModel::addToInventory($charId, 'silver', $silver);
        }
        if ($coin > 0) {
            ItemModel::addToInventory($charId, 'coin', $coin);
        }
    }
    
    /**
     * 将表字段中的货币迁移到背包
     * @param int $charId 角色ID
     */
    public static function migrateTableMoneyToInventory(int $charId): void
    {
        $char = CharacterModel::find($charId);
        if (!$char) {
            return;
        }
        
        $gold = intval($char['gold'] ?? 0);
        $silver = intval($char['silver'] ?? 0);
        $copper = intval($char['copper'] ?? 0);
        
        // 如果表中有货币，迁移到背包
        if ($gold > 0 || $silver > 0 || $copper > 0) {
            if ($gold > 0) {
                ItemModel::addToInventory($charId, 'gold', $gold);
            }
            if ($silver > 0) {
                ItemModel::addToInventory($charId, 'silver', $silver);
            }
            if ($copper > 0) {
                ItemModel::addToInventory($charId, 'coin', $copper);
            }
            
            // 清空表字段
            $sql = "UPDATE characters SET gold = 0, silver = 0, copper = 0 WHERE id = ?";
            Database::execute($sql, [$charId]);
        }
    }
    
    /**
     * 格式化金钱显示
     * @param int $charId 角色ID
     * @return string 格式化的金钱字符串
     */
    public static function formatMoney(int $charId): string
    {
        $money = self::getMoneyInventory($charId);
        $parts = [];
        
        if ($money['gold'] > 0) {
            $parts[] = "{$money['gold']}两黄金";
        }
        if ($money['silver'] > 0) {
            $parts[] = "{$money['silver']}两银子";
        }
        if ($money['coin'] > 0) {
            $parts[] = "{$money['coin']}铜钱";
        }
        
        return empty($parts) ? '0铜钱' : implode(' ', $parts);
    }
}

