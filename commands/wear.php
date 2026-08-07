<?php
/**
 * 装备命令 (wear) - 穿戴防具
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
require_once HELPER_PATH . 'ArmorHelper.php';
require_once HELPER_PATH . 'FabaoHelper.php';
require_once DAEMON_PATH . 'MessageDaemon.php';

function cmd_wear(int $charId, string $itemName = '', string $category = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    if (empty($itemName)) {
        return ['success' => false, 'message' => '你要穿戴什么？'];
    }
    
    // 支持 wear all
    if ($itemName === 'all') {
        return cmd_wear_all($charId);
    }
    
    // 读取 inv_id 参数，优先用精确 ID 定位物品
    $invId = intval($_GET['inv_id'] ?? $_POST['inv_id'] ?? 0);
    
    // 查找要装备的物品
    $targetItem = null;
    if ($invId > 0) {
        require_once __DIR__ . '/../models/Item.php';
        $found = ItemModel::findInInventoryById($invId);
        if ($found && $found['char_id'] == $charId) {
            $targetItem = $found;
        }
    }
    
    if (!$targetItem) {
        // fallback：遍历背包按名称/category 匹配
        $inventory = CharacterModel::getInventory($charId);
        foreach ($inventory as $item) {
            $matchId = stripos($item['item_id'], $itemName) !== false;
            $matchName = stripos($item['item_name'], $itemName) !== false;
            $matchCategory = empty($category) || ($item['category'] ?? '') === $category;
            
            if (($matchId || $matchName) && $matchCategory) {
                $targetItem = $item;
                break;
            }
        }
    }
    
    if (!$targetItem) {
        return ['success' => false, 'message' => '你身上没有这样东西。'];
    }
    
    // 检查是否已装备
    if ($targetItem['equipped']) {
        return ['success' => false, 'message' => '你已经装备着了。'];
    }
    
    // 检查是否为防具类型(根据type字段)
    if ($targetItem['item_type'] !== 'armor') {
        // 检查是否为武器,如果是则提示使用wield
        if ($targetItem['item_type'] === 'weapon') {
            return ['success' => false, 'message' => '这是武器，请使用 wield 命令装备。'];
        }
        
        // 检查是否为法宝
        if (FabaoHelper::isFabao($targetItem)) {
            // 检查是否可以装备
            $checkResult = FabaoHelper::canEquipFabao($targetItem);
            if (!$checkResult['can_equip']) {
                return ['success' => false, 'message' => $checkResult['reason']];
            }
            
            // 生成序列号(如果没有)
            if (empty($targetItem['series_no'])) {
                $seriesNo = FabaoHelper::generateSeriesNo($targetItem['item_id']);
                if ($invId > 0) {
                    Database::execute(
                        "UPDATE character_inventory SET series_no = ? WHERE id = ?",
                        [$seriesNo, $invId]
                    );
                } else {
                    Database::execute(
                        "UPDATE character_inventory SET series_no = ? WHERE char_id = ? AND item_id = ? AND category = ?",
                        [$seriesNo, $charId, $targetItem['item_id'], $targetItem['category'] ?? '']
                    );
                }
            }
            
            // 装备法宝
            if ($invId > 0) {
                Database::execute(
                    "UPDATE character_inventory SET equipped = 1 WHERE id = ?",
                    [$invId]
                );
            } else {
                Database::execute(
                    "UPDATE character_inventory SET equipped = 1 WHERE char_id = ? AND item_id = ? AND category = ?",
                    [$charId, $targetItem['item_id'], $targetItem['category'] ?? '']
                );
            }
            
            $message = "你将{$targetItem['item_name']}祭起，霞光流转！";
            
            log_game('WEAR_FABAO', "{$char['name']} 装备法宝 {$targetItem['item_name']}");
            
            return [
                'success' => true,
                'message' => $message,
                'item' => $targetItem,
                'type' => 'fabao'
            ];
        }
        
        return ['success' => false, 'message' => '你只能穿戴可当作护具的东西。'];
    }
    
    // 确定装备部位
    $armorType = $targetItem['armor_type'] ?? guessArmorType($targetItem['item_name'], $targetItem['item_id']);
    if (!$armorType) {
        $armorType = 'cloth'; // 默认使用cloth部位
    }
    
    // 检查该部位是否已装备
    $currentEquip = ArmorHelper::getEquippedItem($charId, $armorType);
    if ($currentEquip) {
        return ['success' => false, 'message' => "你已经穿戴了同类型的护具了。"];
    }
    
    // 检查性别限制
    if (isset($targetItem['female_only']) && $targetItem['female_only'] && $char['gender'] !== 'female') {
        return ['success' => false, 'message' => '这是女人的衣衫，你一个大男人也想穿，羞也不羞？'];
    }
    
    // 执行穿戴
    if ($invId > 0) {
        $result = ArmorHelper::equipItemById($charId, $invId, $armorType);
        // equipItemById 返回 bool，统一为数组格式
        $result = $result ? ['success' => true] : ['success' => false, 'message' => '穿戴失败。'];
    } else {
        $result = ArmorHelper::equipItem($charId, $targetItem['item_id'], $armorType, $targetItem['category'] ?? '');
    }
    
    if (!$result || !$result['success']) {
        $msg = $result['message'] ?? '穿戴失败。';
        return ['success' => false, 'message' => $msg];
    }
    
    // 生成穿戴消息
    $message = generateWearMessage($targetItem, $armorType);
    
    log_game('WEAR', "{$char['name']} 穿戴 {$targetItem['item_name']}");
    
    // 广播给房间内的其他玩家
    $roomMessage = "{$char['name']}穿上了{$targetItem['item_name']}。";
    MessageDaemon::sendRoomMessage($charId, $roomMessage);
    
    return [
        'success' => true,
        'message' => $message,
        'item' => $targetItem,
        'slot' => $armorType
    ];
}

/**
 * 穿戴所有可装备的物品
 */
function cmd_wear_all(int $charId): array {
    $inventory = CharacterModel::getInventory($charId);
    $count = 0;
    $messages = [];
    
    foreach ($inventory as $item) {
        // 跳过已装备的物品
        if ($item['equipped']) {
            continue;
        }
        
        // 只处理防具类型
        if ($item['item_type'] !== 'armor') {
            continue;
        }
        
        $armorType = $item['armor_type'] ?? null;
        if (!$armorType) {
            continue;
        }
        
        // 检查该部位是否已装备
        $currentEquip = ArmorHelper::getEquippedItem($charId, $armorType);
        if ($currentEquip) {
            continue;
        }
        
        // 执行穿戴
        $result = ArmorHelper::equipItem($charId, $item['item_id'], $armorType, $item['category'] ?? '');
        if ($result) {
            $count++;
            $messages[] = generateWearMessage($item, $armorType);
        }
    }
    
    if ($count === 0) {
        return ['success' => false, 'message' => '没有可以穿戴的物品。'];
    }
    
    return [
        'success' => true,
        'message' => implode("\n", $messages) . "\nOk.\n",
        'count' => $count
    ];
}

/**
 * 生成穿戴消息
 */
function generateWearMessage(array $item, string $armorType): string {
    $itemName = $item['item_name'];
    $unit = $item['unit'] ?? '件';
    
    // 根据部位选择动词
    switch ($armorType) {
        case 'cloth':
        case 'armor':
        case 'boots':
        case 'surcoat':
            return "你穿上{$unit}{$itemName}。";
        
        case 'head':
        case 'neck':
        case 'wrists':
        case 'finger':
        case 'hands':
            return "你戴上{$unit}{$itemName}。";
        
        case 'waist':
            return "你佩上{$unit}{$itemName}。";
        
        default:
            return "你装备{$itemName}。";
    }
}

function guessArmorType(string $itemName, string $itemId): ?string {
    $itemName = strtolower($itemName);
    $itemId = strtolower($itemId);
    
    if (strpos($itemName, '鞋') !== false || strpos($itemId, 'shoe') !== false || strpos($itemId, 'boot') !== false) {
        return 'boots';
    }
    if (strpos($itemName, '帽') !== false || strpos($itemName, '盔') !== false || strpos($itemId, 'helmet') !== false) {
        return 'head';
    }
    if (strpos($itemName, '衣') !== false || strpos($itemName, '袍') !== false || strpos($itemName, '甲') !== false) {
        return 'cloth';
    }
    if (strpos($itemName, '护腕') !== false || strpos($itemName, '腕') !== false || strpos($itemId, 'wrist') !== false) {
        return 'wrists';
    }
    if (strpos($itemName, '项链') !== false || strpos($itemName, '链') !== false || strpos($itemId, 'neck') !== false) {
        return 'neck';
    }
    if (strpos($itemName, '戒指') !== false || strpos($itemId, 'ring') !== false || strpos($itemId, 'finger') !== false) {
        return 'finger';
    }
    if (strpos($itemName, '腰带') !== false || strpos($itemName, '带') !== false || strpos($itemId, 'waist') !== false) {
        return 'waist';
    }
    if (strpos($itemName, '手套') !== false || strpos($itemName, '手') !== false || strpos($itemId, 'hand') !== false) {
        return 'hands';
    }
    
    return null;
}

