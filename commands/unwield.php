<?php
/**
 * 放下武器命令 (unwield)
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
require_once HELPER_PATH . 'WeaponHelper.php';
require_once __DIR__ . '/wield.php';

function cmd_unwield(int $charId, string $itemName = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    if (empty($itemName)) {
        return ['success' => false, 'message' => '你要放下什么武器？'];
    }
    
    // 读取 inv_id 参数，优先用精确 ID 定位物品
    $invId = intval($_GET['inv_id'] ?? $_POST['inv_id'] ?? 0);
    
    // 查找要放下的物品
    $targetItem = null;
    if ($invId > 0) {
        require_once __DIR__ . '/../models/Item.php';
        $found = ItemModel::findInInventoryById($invId);
        if ($found && $found['char_id'] == $charId) {
            $targetItem = $found;
        }
    }
    
    if (!$targetItem) {
        // fallback：遍历已装备武器按名称匹配
        $inventory = CharacterModel::getInventory($charId);
        foreach ($inventory as $item) {
            if (!$item['equipped']) {
                continue;
            }
            
            // 只查找武器类型（getInventory 返回 item_type 字段）
            $isWeapon = ($item['item_type'] ?? '') === 'weapon';
            if (!$isWeapon && ($item['item_type'] ?? '') === 'misc') {
                $isWeapon = isWeaponItem($item['item_id'], $item['item_name']);
            }
            if (!$isWeapon) {
                continue;
            }
            
            $itemDisplayName = $item['item_name'] ?? $item['name'] ?? $item['item_id'];
            if (stripos($itemDisplayName, $itemName) !== false || 
                stripos($item['item_id'], $itemName) !== false) {
                $targetItem = $item;
                break;
            }
        }
    }
    
    if (!$targetItem) {
        return ['success' => false, 'message' => '你并没有装备这样东西作为武器。'];
    }
    
    // 确定是主手还是副手武器
    $mainWeapon = WeaponHelper::getEquippedWeapon($charId);
    $secondaryWeapon = WeaponHelper::getEquippedSecondaryWeapon($charId);
    
    $slot = 'main';
    if ($invId > 0) {
        // 通过 inv_id 判断槽位
        if ($secondaryWeapon && ($secondaryWeapon['id'] ?? $secondaryWeapon['inv_id'] ?? 0) == $invId) {
            $slot = 'secondary';
        } else {
            $slot = 'main';
        }
    } elseif ($secondaryWeapon && ($secondaryWeapon['item_id'] ?? '') === $targetItem['item_id']) {
        $slot = 'secondary';
    } elseif ($mainWeapon && ($mainWeapon['item_id'] ?? '') === $targetItem['item_id']) {
        $slot = 'main';
    } else {
        // Session 中没有该武器数据（可能丢失），直接从数据库卸下
        if ($invId > 0) {
            // 尝试获取属性加成用于清理 char_apply
            $invItem = Database::queryOne(
                "SELECT ci.*, i.str_bonus, i.int_bonus, i.con_bonus, i.dex_bonus,
                        i.spi_bonus, i.dodge_bonus, i.parry_bonus
                 FROM character_inventory ci
                 LEFT JOIN items i ON ci.item_id = i.item_id AND ci.category = i.category
                 WHERE ci.id = ?",
                [$invId]
            );
            if ($invItem) {
                WeaponHelper::removeWeaponProperties($charId, $invItem);
            }
            Database::execute(
                "UPDATE character_inventory SET equipped = 0, equip_slot = '' WHERE id = ?",
                [$invId]
            );
        } else {
            // 尝试获取属性加成用于清理 char_apply
            $invItem = Database::queryOne(
                "SELECT ci.*, i.str_bonus, i.int_bonus, i.con_bonus, i.dex_bonus,
                        i.spi_bonus, i.dodge_bonus, i.parry_bonus
                 FROM character_inventory ci
                 LEFT JOIN items i ON ci.item_id = i.item_id AND ci.category = i.category
                 WHERE ci.char_id = ? AND ci.item_id = ? AND ci.equipped = 1",
                [$charId, $targetItem['item_id']]
            );
            if ($invItem) {
                WeaponHelper::removeWeaponProperties($charId, $invItem);
            }
            Database::execute(
                "UPDATE character_inventory SET equipped = 0, equip_slot = '' WHERE char_id = ? AND item_id = ?",
                [$charId, $targetItem['item_id']]
            );
        }
        $itemName = $targetItem['item_name'] ?? $targetItem['name'] ?? $targetItem['item_id'];
        $message = "你放下手中的{$itemName}。";
        log_game('UNWIELD', "{$char['name']} 放下 {$itemName}");
        return ['success' => true, 'message' => $message, 'item' => $targetItem];
    }
    
    // 卸下武器
    if ($invId > 0) {
        $result = WeaponHelper::unequipWeaponById($charId, $invId);
    } else {
        $result = WeaponHelper::unequipWeapon($charId, $slot);
    }
    
    if (!$result) {
        return ['success' => false, 'message' => '放下失败。'];
    }
    
    // 生成消息
    $itemName = $targetItem['item_name'] ?? $targetItem['name'] ?? $targetItem['item_id'];
    $message = "你放下手中的{$itemName}。";
    
    // 如果副手武器切换到主手,显示提示
    if ($slot === 'main' && $secondaryWeapon) {
        $secName = $secondaryWeapon['item_name'] ?? $secondaryWeapon['name'] ?? $secondaryWeapon['item_id'] ?? '';
        $message .= "\n你的{$secName}自动换到主手。";
    }
    
    log_game('UNWIELD', "{$char['name']} 放下 {$itemName}");
    
    return [
        'success' => true,
        'message' => $message,
        'item' => $targetItem
    ];
}



