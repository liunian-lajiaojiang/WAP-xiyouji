<?php
/**
 * 丢弃命令 (drop) - 丢弃背包中的物品或放下携带的尸体
 * @param int $charId 角色ID
 * @param string $itemName 物品名称或ID
 * @param int $quantity 丢弃数量（0表示全部）
 */
function cmd_drop(int $charId, string $itemName = '', int $quantity = 0): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    if (empty($itemName)) {
        return ['success' => false, 'message' => '你要丢什么？'];
    }
    
    // 检查是否是放下尸体
    if ($itemName === 'corpse' || $itemName === '尸体' || $itemName === 'shi ti') {
        return drop_corpse($charId, $char);
    }
    
    $category = $_GET['category'] ?? $_POST['category'] ?? '';
    $invId = intval($_GET['inv_id'] ?? $_POST['inv_id'] ?? 0);
    
    require_once __DIR__ . '/../models/Item.php';
    
    $targetItem = null;
    if ($invId > 0) {
        $found = ItemModel::findInInventoryById($invId);
        if ($found && $found['char_id'] == $charId) {
            $targetItem = $found;
        }
    }
    
    if (!$targetItem) {
        // fallback：遍历背包按名称/category 匹配
        $inventory = ItemModel::getCharacterItems($charId);
        foreach ($inventory as $item) {
            $matchName = stripos($item['name'], $itemName) !== false || 
                         stripos($item['item_id'], $itemName) !== false;
            $matchCategory = empty($category) || ($item['category'] ?? '') === $category;
            if ($matchName && $matchCategory) {
                $targetItem = $item;
                break;
            }
        }
    }
    
    if (!$targetItem) {
        return ['success' => false, 'message' => '你没有这个物品'];
    }
    
    if (!empty($targetItem['no_drop'])) {
        return ['success' => false, 'message' => '这样东西不能随意丢弃。'];
    }
    
    // 检查是否装备中
    if ($targetItem['equipped']) {
        return ['success' => false, 'message' => '请先卸下装备再丢弃'];
    }
    
    // 确定丢弃数量
    if ($quantity <= 0) {
        // 默认为全部数量
        $dropQuantity = $targetItem['quantity'];
    } else {
        // 不能超过背包中的数量
        $dropQuantity = min($quantity, $targetItem['quantity']);
    }
    
    // 从背包移除指定数量
    $oldContainerId = 0;
    $isContainer = !empty($targetItem['is_container']) && intval($targetItem['is_container']) > 0;
    if ($isContainer && $dropQuantity == ($targetItem['quantity'] ?? 1)) {
        // 整个容器丢弃，记录旧的容器ID
        $oldContainerId = intval($targetItem['id']);
    }
    
    if ($invId > 0) {
        ItemModel::removeFromInventoryById($charId, $invId, $dropQuantity);
    } else {
        ItemModel::removeFromInventory($charId, $targetItem['item_id'], $dropQuantity, $targetItem['category'] ?? '');
    }
    
    // 如果是在"废品回收中心"(recycle 房间)，物品直接销毁，不再放入房间
    $isRecycleRoom = (strpos($char['current_room'] ?? '', 'recycle') !== false);
    
    if (!$isRecycleRoom) {
        // 添加到房间物品
        require_once MODEL_PATH . 'Room.php';
        $liquidRemaining = (int)($targetItem['liquid_remaining'] ?? 0);
        $liquidType = $targetItem['liquid_type'] ?? '';
        $liquidName = $targetItem['liquid_name'] ?? '';
        RoomModel::addItemToRoom($char['current_area'], $char['current_room'], $targetItem['item_id'], $dropQuantity, $targetItem['category'] ?? '', $targetItem['enchantments'] ?? '', $liquidRemaining, $liquidType, $liquidName);
        
        // 如果是容器并且是整个丢弃，转移容器里的物品
        if ($isContainer && $oldContainerId > 0) {
            // 查询房间中新创建的容器记录ID
            $room = RoomModel::load($char['current_area'], $char['current_room']);
            if ($room) {
                $itemCat = $targetItem['category'] ?? '';
                if ($itemCat !== '') {
                    $newRoomItem = Database::queryOne(
                        "SELECT id FROM room_items WHERE room_id = ? AND item_id = ? AND category = ? ORDER BY id DESC LIMIT 1",
                        [$room['id'], $targetItem['item_id'], $itemCat]
                    );
                } else {
                    $newRoomItem = Database::queryOne(
                        "SELECT id FROM room_items WHERE room_id = ? AND item_id = ? AND (category IS NULL OR category = '') ORDER BY id DESC LIMIT 1",
                        [$room['id'], $targetItem['item_id']]
                    );
                }
                
                if ($newRoomItem && !empty($newRoomItem['id'])) {
                    $newContainerId = intval($newRoomItem['id']);
                    // 更新 container_items 表，把容器里的物品转移到新容器
                    Database::execute(
                        "UPDATE container_items SET container_type = 'room_items', container_id = ? WHERE container_type = 'character_inventory' AND container_id = ?",
                        [$newContainerId, $oldContainerId]
                    );
                }
            }
        }
    }
    
    $itemName = $targetItem['name'] ?? $targetItem['item_id'];
    $unit = $targetItem['unit'] ?? '个';
    $quantityText = $dropQuantity > 1 ? "{$dropQuantity}{$unit}" : "一{$unit}";
    
    if ($isRecycleRoom) {
        $broadcastMessage = "{$char['name']}把一些{$itemName}丢进了垃圾堆。\n";
        $selfMessage = "你把{$quantityText}{$itemName}丢进了垃圾堆，它们很快就被埋没了。\n";
        log_game('DROP', "{$char['name']} 在回收中心销毁 {$itemName} x{$dropQuantity}");
    } else {
        $broadcastMessage = "{$char['name']}丢掉一些{$itemName}。\n";
        $selfMessage = "你丢掉了{$quantityText}{$itemName}。\n";
        log_game('DROP', "{$char['name']} 丢弃 {$itemName} x{$dropQuantity}");
    }
    
    return [
        'success' => true,
        'message' => $selfMessage,
        'broadcast_message' => $broadcastMessage,
        'item' => $targetItem,
        'dropped_quantity' => $dropQuantity
    ];
}

/**
 * 放下携带的尸体
 * 如果在三花堂密室，并且是玩家尸体且有悬赏，自动领取赏金
 */
function drop_corpse(int $charId, array $char): array {
    require_once __DIR__ . '/../models/Corpse.php';
    
    // 检查是否携带了尸体
    $carriedCorpses = Corpse::getCarriedCorpses($charId);
    
    if (empty($carriedCorpses)) {
        return ['success' => false, 'message' => '你身上没有背着尸体。'];
    }
    
    $corpse = $carriedCorpses[0]; // 一次只能背一具
    $corpseName = $corpse['owner_name'] . '的尸体';
    
    $currentArea = $char['current_area'] ?? '';
    $currentRoom = $char['current_room'] ?? '';
    
    // 检查是否在三花堂密室
    $isSanhuaMishi = ($currentRoom === 'city/sanhua-mishi');
    
    if ($isSanhuaMishi && $corpse['owner_type'] === 'player') {
        // 在三花堂密室，并且是玩家尸体，尝试领取赏金
        require_once __DIR__ . '/../models/SanhuaBounty.php';
        
        $targetId = intval($corpse['owner_id']);
        $killerId = intval($corpse['killer_id'] ?? 0);
        
        // 检查是否有悬赏
        $bounty = SanhuaBounty::getBountyByTargetId($targetId);
        
        if ($bounty && $killerId === $charId) {
            // 有悬赏，并且当前玩家是击杀者，领取赏金
            $amount = $bounty['amount'];
            $targetName = $corpse['owner_name'];
            
            // 领取赏金
            $claimed = SanhuaBounty::claimBounty($targetId, $charId, $char['name'] ?? '');
            
            if ($claimed) {
                // 存入玩家帐户
                $amountInCoin = $amount * 10000;
                $balance = intval($char['balance'] ?? 0);
                $newBalance = $balance + $amountInCoin;
                $sql = "UPDATE characters SET balance = ? WHERE id = ?";
                Database::execute($sql, [$newBalance, $charId]);
                
                // 销毁尸体
                Corpse::buryCorpse($corpse['id']);
                
                $selfMessage = "你将身上背着的" . $corpseName . "往地上一摔，三花堂打手急忙趋前细看，不禁面露喜色。\n";
                $selfMessage .= "三花堂打手对你小声嘀咕道：可真有你的，居然把" . $targetName . "给弄死了！这" . $amount . "两金子小的就帮您存钱庄啦！\n";
                $selfMessage .= "赏金已存入你的帐户。\n";
                
                $broadcastMessage = $char['name'] . "从三花堂领取了" . $targetName . "的赏金。\n";
                
                return [
                    'success' => true,
                    'message' => $selfMessage,
                    'broadcast_message' => $broadcastMessage
                ];
            }
        }
    }
    
    // 普通放下尸体
    Corpse::dropCorpse($corpse['id'], $currentArea, $currentRoom);
    
    $selfMessage = "你将身上背着的" . $corpseName . "放了下来。\n";
    $broadcastMessage = $char['name'] . "将身上背着的" . $corpseName . "放了下来。\n";
    
    return [
        'success' => true,
        'message' => $selfMessage,
        'broadcast_message' => $broadcastMessage
    ];
}
