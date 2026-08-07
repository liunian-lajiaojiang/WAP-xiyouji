<?php
/**
 * 卸下命令 (remove) - 卸下防具/法宝
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
require_once HELPER_PATH . 'ArmorHelper.php';
require_once HELPER_PATH . 'FabaoHelper.php';
require_once DAEMON_PATH . 'MessageDaemon.php';

function cmd_remove(int $charId, string $itemName = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    if (empty($itemName)) {
        return ['success' => false, 'message' => '你要脱掉什么？'];
    }
    
    // 支持 remove all
    if ($itemName === 'all') {
        return cmd_remove_all($charId);
    }
    
    // 读取 inv_id 参数，优先用精确 ID 定位物品
    $invId = intval($_GET['inv_id'] ?? $_POST['inv_id'] ?? 0);
    
    // 查找要卸下的物品
    $targetItem = null;
    if ($invId > 0) {
        require_once __DIR__ . '/../models/Item.php';
        $found = ItemModel::findInInventoryById($invId);
        if ($found && $found['char_id'] == $charId) {
            $targetItem = $found;
        }
    }
    
    if (!$targetItem) {
        // fallback：遍历已装备物品按名称匹配
        $inventory = CharacterModel::getInventory($charId);
        foreach ($inventory as $item) {
            if (!$item['equipped']) {
                continue;
            }
            
            if (stripos($item['item_name'], $itemName) !== false || 
                stripos($item['item_id'], $itemName) !== false) {
                $targetItem = $item;
                break;
            }
        }
    }
    
    if (!$targetItem) {
        return ['success' => false, 'message' => '你并没有装备这样东西。'];
    }
    
    // ★ 统一字段名：findInInventoryById 返回 name，getInventory 返回 item_name
    if (!isset($targetItem['item_name']) && isset($targetItem['name'])) {
        $targetItem['item_name'] = $targetItem['name'];
    }
    
    // 检查是否为防具类型(根据type字段)
    if ($targetItem['item_type'] === 'armor') {
        // 卸下防具
        $armorType = $targetItem['armor_type'] ?? $targetItem['equip_slot'] ?? null;
        if (!$armorType) {
            return ['success' => false, 'message' => '无法识别装备部位。'];
        }
        
        if ($invId > 0) {
            $result = ArmorHelper::unequipItemById($charId, $invId);
            $result = $result ? ['success' => true] : ['success' => false, 'message' => '卸下失败。'];
        } else {
            $result = ArmorHelper::unequipItem($charId, $armorType);
        }
        
        if (!$result || !$result['success']) {
            $msg = $result['message'] ?? '卸下失败。';
            return ['success' => false, 'message' => $msg];
        }
        
        // 生成卸下消息
        $message = generateRemoveMessage($targetItem, $armorType);
        
        log_game('REMOVE', "{$char['name']} 卸下 {$targetItem['item_name']}");
        
        // 广播给房间内的其他玩家
        $roomMessage = "{$char['name']}将{$targetItem['item_name']}脱了下来。";
        MessageDaemon::sendRoomMessage($charId, $roomMessage);
        
        return [
            'success' => true,
            'message' => $message,
            'item' => $targetItem
        ];
    }
    
    // 检查是否为武器类型(根据type字段)
    if ($targetItem['item_type'] === 'weapon') {
        return ['success' => false, 'message' => '这是武器，请使用 unwield 命令放下。'];
    }
    
    // 检查是否为法宝
    if (FabaoHelper::isFabao($targetItem)) {
        // 卸下法宝
        if ($invId > 0) {
            Database::execute(
                "UPDATE character_inventory SET equipped = 0 WHERE id = ?",
                [$invId]
            );
        } else {
            Database::execute(
                "UPDATE character_inventory SET equipped = 0 WHERE char_id = ? AND item_id = ?",
                [$charId, $targetItem['item_id']]
            );
        }
        
        $message = "你将{$targetItem['item_name']}收了起来。";
        
        log_game('REMOVE_FABAO', "{$char['name']} 卸下法宝 {$targetItem['item_name']}");
        
        return [
            'success' => true,
            'message' => $message,
            'item' => $targetItem
        ];
    }
    
    return ['success' => false, 'message' => '这个物品不能卸下。'];
}

/**
 * 卸下所有防具
 */
function cmd_remove_all(int $charId): array {
    $inventory = CharacterModel::getInventory($charId);
    $count = 0;
    $messages = [];
    
    foreach ($inventory as $item) {
        // 只处理已装备的防具
        if (!$item['equipped'] || $item['item_type'] !== 'armor') {
            continue;
        }
        
        $armorType = $item['armor_type'] ?? $item['equip_slot'] ?? null;
        if (!$armorType) {
            continue;
        }
        
        $result = ArmorHelper::unequipItem($charId, $armorType);
        if ($result && $result['success']) {
            $count++;
            $messages[] = generateRemoveMessage($item, $armorType);
        }
    }
    
    if ($count === 0) {
        return ['success' => false, 'message' => '你没有穿戴任何防具。'];
    }
    
    return [
        'success' => true,
        'message' => implode("\n", $messages) . "\nOk.\n",
        'count' => $count
    ];
}

/**
 * 生成卸下消息
 */
function generateRemoveMessage(array $item, string $armorType): string {
    $itemName = $item['item_name'];
    
    // 根据部位选择动词
    switch ($armorType) {
        case 'cloth':
        case 'armor':
        case 'surcoat':
        case 'boots':
            return "你将{$itemName}脱了下来。";
        
        case 'bandage':
            return "你将{$itemName}从伤口处拆了下来。";
        
        case 'head':
        case 'neck':
        case 'wrists':
        case 'finger':
        case 'hands':
            return "你摘下{$itemName}。";
        
        case 'waist':
            return "你解下{$itemName}。";
        
        default:
            return "你卸除{$itemName}的装备。";
    }
}

// 别名支持
if (!function_exists('cmd_unwear')) {
    function cmd_unwear(int $charId, string $param = ''): array {
        return cmd_remove($charId, $param);
    }
}

