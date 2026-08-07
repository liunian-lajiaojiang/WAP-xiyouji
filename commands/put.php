<?php
/**
 * 放东西到容器命令 (put)
 * 用法：put <物品名称> in <容器名称>
 * @param int $charId 角色ID
 * @param string $arg 参数
 */
function cmd_put(int $charId, string $arg = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    if (empty($arg)) {
        return ['success' => false, 'message' => '你要把什么放进什么东西里？'];
    }
    
    // 解析参数：put <物品> in <容器>
    if (!preg_match('/^(.+?)\s+(?:in|在|放进|放入|装)\s+(.+)$/i', $arg, $matches)) {
        return ['success' => false, 'message' => '用法：put <物品> in <容器>'];
    }
    
    $itemName = trim($matches[1]);
    $containerName = trim($matches[2]);
    
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
    
    // 2. 查找要放入的物品
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
        return ['success' => false, 'message' => '暂不支持全部放入。'];
    }
    
    foreach ($inventory as $item) {
        // 跳过容器本身
        if (!empty($item['is_container']) && intval($item['is_container']) > 0) {
            continue;
        }
        // 跳过装备中的物品
        if (!empty($item['equipped'])) {
            continue;
        }
        // 匹配物品名称
        if (stripos($item['name'], $itemName) !== false || 
            stripos($item['item_id'], $itemName) !== false) {
            $targetItem = $item;
            break;
        }
    }
    
    if (!$targetItem) {
        return ['success' => false, 'message' => "你身上没有{$itemName}这样东西。"];
    }
    
    // 3. 检查物品是否可以放入（no_drop等）
    if (!empty($targetItem['no_drop']) && intval($targetItem['no_drop']) > 0) {
        return ['success' => false, 'message' => '这个物品不能被移动。'];
    }
    
    // 4. 确定实际放入数量
    $actualQuantity = min($quantity, intval($targetItem['quantity']));
    
    // 5. 检查容器是否还能放
    $containerType = 'character_inventory';
    $containerId = intval($containerItem['id']);
    $itemWeight = intval($targetItem['weight'] ?? 0);
    
    $canPut = ContainerModel::canPutItem($containerItem, $containerType, $containerId, $itemWeight, $actualQuantity);
    if (!$canPut['can_put']) {
        return ['success' => false, 'message' => $canPut['reason']];
    }
    
    // 6. 从背包移除物品
    ItemModel::removeFromInventoryById($charId, intval($targetItem['id']), $actualQuantity);
    
    // 7. 添加到容器
    $liquidRemaining = isset($targetItem['liquid_remaining']) ? intval($targetItem['liquid_remaining']) : null;
    $liquidType = $targetItem['liquid_type'] ?? null;
    $liquidName = $targetItem['liquid_name'] ?? null;
    
    ContainerModel::addItem(
        $containerType,
        $containerId,
        $targetItem['item_id'],
        $targetItem['name'] ?? '',
        $targetItem['category'] ?? '',
        $actualQuantity,
        $targetItem['enchantments'] ?? '',
        $liquidRemaining,
        $liquidType,
        $liquidName
    );
    
    // 8. 银药盏制药逻辑
    $extraMessage = '';
    if ($containerItem['item_id'] === 'yaozhan') {
        require_once __DIR__ . '/../helpers/HerbHelper.php';
        
        // 获取容器内所有物品
        $containerItems = ContainerModel::getContainerItems($containerType, $containerId);
        
        // 获取容器的液体信息（从character_inventory表）
        $containerInfo = Database::queryOne(
            "SELECT liquid_remaining, liquid_type, liquid_name FROM character_inventory WHERE id = ?",
            [$containerId]
        );
        $containerLiquidType = $containerInfo['liquid_type'] ?? null;
        
        // 检查配方
        $prescriptionResult = HerbHelper::checkPrescription($containerItems, $containerLiquidType);
        
        if ($prescriptionResult['can_make']) {
            // 配方完整，开始制药
            // 移除三种药草
            $prescription = HerbHelper::getPrescription();
            foreach ($prescription['herbs'] as $herbId) {
                ContainerModel::removeItemById($containerType, $containerId, $herbId, 'qujing', 1);
            }
            
            // 消耗马尿（消耗50单位）
            if (!empty($containerLiquidType) && $containerLiquidType === 'horse_urine') {
                $currentLiquid = intval($containerInfo['liquid_remaining'] ?? 0);
                $newLiquid = max(0, $currentLiquid - 50);
                Database::execute(
                    "UPDATE character_inventory SET liquid_remaining = ? WHERE id = ?",
                    [$newLiquid, $containerId]
                );
                // 如果液体用完了，清空液体类型
                if ($newLiquid <= 0) {
                    Database::execute(
                        "UPDATE character_inventory SET liquid_type = NULL, liquid_name = NULL WHERE id = ?",
                        [$containerId]
                    );
                }
            }
            
            // 添加乌金丹到容器
            ContainerModel::addItem(
                $containerType,
                $containerId,
                'wujindan',
                '乌金丹',
                'qujing',
                1,
                '',
                null,
                null,
                null
            );
            
            $extraMessage = "药盏中的药材开始发生变化，一股奇异的药香弥漫开来...\n炼制成功！你得到了一颗乌金丹！\n";
        } else if (HerbHelper::isHerb($targetItem['item_id'])) {
            // 放入的是药草，但配方不完整，提示还缺什么
            $extraMessage = $prescriptionResult['reason'] . "\n";
        }
    }
    
    // 9. 构建消息
    $itemDisplayName = $targetItem['name'] ?? $targetItem['item_id'];
    $unit = $targetItem['unit'] ?? '个';
    $containerDisplayName = $containerItem['name'] ?? $containerItem['item_id'];
    
    if ($actualQuantity > 1) {
        $quantityText = "{$actualQuantity}{$unit}";
    } else {
        $quantityText = "一{$unit}";
    }
    
    $selfMessage = "你将{$quantityText}{$itemDisplayName}放进{$containerDisplayName}。\n" . $extraMessage;
    $broadcastMessage = "{$char['name']}将一些{$itemDisplayName}放进{$containerDisplayName}。\n";
    
    log_game('PUT', "{$char['name']} 将 {$itemDisplayName} x{$actualQuantity} 放入 {$containerDisplayName}");
    
    return [
        'success' => true,
        'message' => $selfMessage,
        'broadcast_message' => $broadcastMessage,
        'container' => $containerItem,
        'item' => $targetItem,
        'quantity' => $actualQuantity
    ];
}
