<?php
/**
 * 拾取命令 (get)
 * 用法：
 *   get <物品名称> - 从地上捡东西
 *   get <物品名称> from <容器名称> - 从容器中取东西
 */
require_once __DIR__ . '/../helpers/WeightHelper.php';
function cmd_get(int $charId, string $itemName = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    if (empty($itemName)) {
        return ['success' => false, 'message' => '你要捡什么？'];
    }
    
    // 检查是否是从容器中取东西：get <物品> from <容器>
    if (preg_match('/^(.+?)\s+(?:from|从|从...里|从...中)\s+(.+)$/i', $itemName, $matches)) {
        return get_from_container($charId, $char, trim($matches[1]), trim($matches[2]));
    }
    
    // 获取当前房间
    $room = RoomModel::getFullInfo($char['current_area'], $char['current_room']);
    
    if (!$room) {
        return ['success' => false, 'message' => '当前位置无效'];
    }
    
    // 查找物品
    $targetItem = null;
    foreach ($room['items'] as $item) {
        if (stripos($item['item_name'], $itemName) !== false || 
            stripos($item['item_id'], $itemName) !== false) {
            $targetItem = $item;
            break;
        }
    }
    
    if (!$targetItem) {
        // ★ 火焰山石门特殊处理：可以拾取地上的乱石
        if ($room['room_id'] === 'qujing/firemount/shimen' && 
            (strpos($itemName, 'stone') !== false || strpos($itemName, '石') !== false || strpos($itemName, '乱石') !== false)) {
            require_once __DIR__ . '/../daemons/FiremountHandler.php';
            return FiremountHandler::getShimenStone($charId);
        }
        return ['success' => false, 'message' => '这里没有这个物品'];
    }
    
    // 检查是否是容器物品
    $isContainer = false;
    $oldRoomContainerId = 0;
    $itemCat = $targetItem['category'] ?? '';
    if ($itemCat !== '') {
        $itemInfo = Database::queryOne("SELECT is_container FROM items WHERE item_id = ? AND category = ?", [$targetItem['item_id'], $itemCat]);
    } else {
        $itemInfo = Database::queryOne("SELECT is_container FROM items WHERE item_id = ? LIMIT 1", [$targetItem['item_id']]);
    }
    if ($itemInfo && intval($itemInfo['is_container'] ?? 0) > 0) {
        $isContainer = true;
        // 记录旧的 room_items 中的容器ID
        $oldRoomContainerId = intval($targetItem['id']);
    }
    
    // 货币物品直接加钱，不走负重检查
    $isCurrency = in_array($targetItem['item_id'], ['gold', 'silver', 'coin', 'copper']);
    if ($isCurrency) {
        require_once __DIR__ . '/../helpers/MoneyHelper.php';
        $amount = $targetItem['quantity'] ?? 1;
        if ($targetItem['item_id'] === 'gold') {
            MoneyHelper::addMoney($charId, $amount * 10000);
        } elseif ($targetItem['item_id'] === 'silver') {
            MoneyHelper::addMoney($charId, $amount * 100);
        } else {
            MoneyHelper::addMoney($charId, $amount);
        }
    } else {
        // 负重检查（非货币物品）
        $canPickUp = WeightHelper::canPickUp($charId, $targetItem['item_id'], $targetItem['quantity'] ?? 1);
        if (!$canPickUp['success']) {
            return ['success' => false, 'message' => $canPickUp['message']];
        }
    }

    // 添加到背包（包含液体状态）
    $liquidRemaining = (int)($targetItem['liquid_remaining'] ?? 0);
    $liquidType = $targetItem['liquid_type'] ?? '';
    $liquidName = $targetItem['liquid_name'] ?? '';
    ItemModel::addToInventory($charId, $targetItem['item_id'], $targetItem['quantity'], $targetItem['category'] ?? '', $targetItem['enchantments'] ?? '', $liquidRemaining, $liquidType, $liquidName);
    
    // 如果是容器物品，转移容器里的物品
    if ($isContainer && $oldRoomContainerId > 0) {
        // 查询玩家背包中新创建的容器记录ID
        if ($itemCat !== '') {
            $newInvItem = Database::queryOne(
                "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ? ORDER BY id DESC LIMIT 1",
                [$charId, $targetItem['item_id'], $itemCat]
            );
        } else {
            $newInvItem = Database::queryOne(
                "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = '') ORDER BY id DESC LIMIT 1",
                [$charId, $targetItem['item_id']]
            );
        }
        
        if ($newInvItem && !empty($newInvItem['id'])) {
            $newContainerId = intval($newInvItem['id']);
            // 更新 container_items 表，把容器里的物品转移到新容器
            Database::execute(
                "UPDATE container_items SET container_type = 'character_inventory', container_id = ? WHERE container_type = 'room_items' AND container_id = ?",
                [$newContainerId, $oldRoomContainerId]
            );
        }
    }
    
    // 从房间移除物品
    require_once MODEL_PATH . 'Room.php';
    RoomModel::removeItemFromRoom($char['current_area'], $char['current_room'], $targetItem['item_id'], $targetItem['quantity'] ?? 0, $targetItem['category'] ?? '');
    
    // 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
    $unit = $targetItem['unit'] ?? '个'; // 物品单位
    $pickupMessage = "{$char['name']}捡起{$targetItem['quantity']}{$unit}{$targetItem['item_name']}。\n";
    
    log_game('GET', "{$char['name']} 捡起 {$targetItem['item_name']}");
    
    return [
        'success' => true,
        'message' => "你捡起{$targetItem['item_name']}。\n",
        'broadcast_message' => $pickupMessage,  // 广播给房间内其他人
        'item' => $targetItem
    ];
}

/**
 * 从容器中取东西
 */
function get_from_container(int $charId, array $char, string $itemName, string $containerName): array {
    require_once __DIR__ . '/../models/Item.php';
    require_once __DIR__ . '/../models/ContainerModel.php';
    
    // 1. 查找玩家背包中的容器
    $inventory = ItemModel::getCharacterItems($charId);
    $containerItem = null;
    
    foreach ($inventory as $item) {
        if (empty($item['is_container']) || intval($item['is_container']) <= 0) {
            continue;
        }
        // 匹配容器名称
        if (stripos($item['name'], $containerName) !== false || 
            stripos($item['item_id'], $containerName) !== false) {
            $containerItem = $item;
            break;
        }
    }
    
    if (!$containerItem) {
        return ['success' => false, 'message' => "你身上没有{$containerName}这样东西。"];
    }
    
    // 2. 查找容器中的物品
    $containerType = 'character_inventory';
    $containerId = intval($containerItem['id']);
    $containerItems = ContainerModel::getContainerItems($containerType, $containerId);
    
    $targetItem = null;
    $quantity = 1;
    
    // 检查是否指定了数量（如 "3 apple"）
    if (preg_match('/^(\d+)\s+(.+)$/', $itemName, $qtyMatches)) {
        $quantity = intval($qtyMatches[1]);
        $itemName = trim($qtyMatches[2]);
    }
    
    // 检查是否是 all
    if (strtolower($itemName) === 'all' || $itemName === '全部') {
        // 先不实现 all，简化处理
        return ['success' => false, 'message' => '暂不支持全部取出。'];
    }
    
    foreach ($containerItems as $item) {
        // 匹配物品名称
        if (stripos($item['name'], $itemName) !== false || 
            stripos($item['item_id'], $itemName) !== false) {
            $targetItem = $item;
            break;
        }
    }
    
    if (!$targetItem) {
        return ['success' => false, 'message' => "{$containerItem['name']}里没有这样东西。"];
    }
    
    // 3. 确定实际取出数量
    $actualQuantity = min($quantity, intval($targetItem['quantity']));
    
    // 4. 负重检查
    require_once __DIR__ . '/../helpers/WeightHelper.php';
    $canPickUp = WeightHelper::canPickUp($charId, $targetItem['item_id'], $actualQuantity);
    if (!$canPickUp['success']) {
        return ['success' => false, 'message' => $canPickUp['message']];
    }
    
    // 5. 从容器移除物品
    ContainerModel::removeItem(intval($targetItem['id']), $actualQuantity);
    
    // 6. 添加到背包
    $liquidRemaining = isset($targetItem['liquid_remaining']) ? intval($targetItem['liquid_remaining']) : 0;
    $liquidType = $targetItem['liquid_type'] ?? '';
    $liquidName = $targetItem['liquid_name'] ?? '';
    
    ItemModel::addToInventory(
        $charId, 
        $targetItem['item_id'], 
        $actualQuantity, 
        $targetItem['category'] ?? '', 
        $targetItem['enchantments'] ?? '', 
        $liquidRemaining, 
        $liquidType, 
        $liquidName
    );
    
    // 7. 构建消息
    $itemDisplayName = $targetItem['name'] ?? $targetItem['item_id'];
    $unit = $targetItem['unit'] ?? '个';
    $containerDisplayName = $containerItem['name'] ?? $containerItem['item_id'];
    
    if ($actualQuantity > 1) {
        $quantityText = "{$actualQuantity}{$unit}";
    } else {
        $quantityText = "一{$unit}";
    }
    
    $selfMessage = "你从{$containerDisplayName}中拿出{$quantityText}{$itemDisplayName}。\n";
    $broadcastMessage = "{$char['name']}从{$containerDisplayName}中拿出一些{$itemDisplayName}。\n";
    
    log_game('GET_FROM_CONTAINER', "{$char['name']} 从 {$containerDisplayName} 中取出 {$itemDisplayName} x{$actualQuantity}");
    
    return [
        'success' => true,
        'message' => $selfMessage,
        'broadcast_message' => $broadcastMessage,
        'container' => $containerItem,
        'item' => $targetItem,
        'quantity' => $actualQuantity
    ];
}

