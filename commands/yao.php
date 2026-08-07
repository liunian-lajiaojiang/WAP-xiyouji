<?php
/**
 * 要吃的命令 (yao) - 根据所在房间和NPC给予不同食物
 * - 在灵台厨房 (lingtai/inside4)：向晚风要包子
 * - 在天宫后苑 (dntg/hgs/zhaiyuan)：向老头要斋饭
 * 
 * @param int $charId 角色ID
 * @param string $param 可选参数（如 "zhaifan" 表示要斋饭）
 * @return array 结果数组
 */
function cmd_yao(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    $roomId = $char['current_room'] ?? '';
    $area = $char['current_area'] ?? '';
    
    // 获取房间NPC
    $room = RoomModel::getFullInfo($area, $roomId);
    $npcs = $room['npcs'] ?? [];

    // 场景0：天宫起风亭 (dntg/sky/tgqs3) - 风婆给风灵符
    // 玩家需先向风婆询问「起风/刮风/wind」(handleFengpoAskWind) 获得 fengpo/need_fengfu 标记
    if ($roomId === 'dntg/sky/tgqs3') {
        $fengpo = null;
        foreach ($npcs as $n) {
            if (($n['npc_id'] ?? '') === 'fengpo' || ($n['name'] ?? '') === '风婆') {
                $fengpo = $n;
                break;
            }
        }
        if ($fengpo) {
            $charId = intval($char['id'] ?? 0);
            $mark = Database::queryOne(
                "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = 'fengpo/need_fengfu'",
                [$charId]
            );
            if ($mark && ($mark['state_value'] ?? '') === '1') {
                // 已持有风灵符则不再给
                $inventory = CharacterModel::getInventory($charId);
                foreach ($inventory as $it) {
                    if (($it['item_id'] ?? '') === 'fenglingfu' || ($it['item_name'] ?? '') === '风灵符') {
                        return ['success' => true, 'message' => '风婆笑道：你不是已经有风灵符了么？', 'redirect' => room_url($area, $roomId)];
                    }
                }
                // 给予风灵符
                require_once __DIR__ . '/../models/Item.php';
                ItemModel::addToInventory($charId, 'fenglingfu', 1, 'dntg');
                // 清除索取标记
                Database::execute(
                    "DELETE FROM character_temp_states WHERE char_id = ? AND state_key = 'fengpo/need_fengfu'",
                    [$charId]
                );
                // 授予呼风唤雨许可（对应原始 LPC dntg/bmw=allow；本项目未实现玉皇册封链路，此处就地授予）
                Database::execute(
                    'INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) '
                    . 'VALUES (?, ?, ?, NOW(), NOW()) '
                    . 'ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = NOW()',
                    [$charId, 'dntg/bmw', 'allow']
                );
                $charName = $char['name'] ?? '你';
                MessageDaemon::broadcastToRoom($roomId, HTML_HIYEL . "风婆交给{$charName}一张风灵符。" . HTML_NOR, $charId);
                return [
                    'success' => true,
                    'message' => HTML_HIGRN . "风婆笑道：好吧！你来试试看这玩意灵不灵。\n风婆交给你一张风灵符。" . HTML_NOR,
                    'redirect' => room_url($area, $roomId)
                ];
            } else {
                // 未先向风婆打听起风法门就点「要风灵符」：给出友好提示
                return [
                    'success' => true,
                    'message' => HTML_HIYEL . '风婆含笑望着你：想讨风灵符？先向我打听打听「起风」的门道吧。' . HTML_NOR,
                    'redirect' => room_url($area, $roomId)
                ];
            }
        }
    }
    
    // 查找当前房间中可以给食物的NPC
    $targetNpc = null;
    $foodItem = '';
    $foodName = '';
    $foodQuantity = 0;
    
    // 场景1：灵台厨房 - 晚风给包子
    if ($roomId === 'lingtai/inside4') {
        foreach ($npcs as $npc) {
            if ($npc['npc_id'] === 'wanfeng' || $npc['name'] === '晚风') {
                $targetNpc = $npc;
                $foodItem = 'baozi';
                $foodName = '包子';
                $foodQuantity = 2;
                break;
            }
        }
        if (!$targetNpc) {
            return ['success' => false, 'message' => '晚风不在这里。', 'redirect' => room_url($area, $roomId)];
        }
    }
    // 场景2：天宫后苑 - 老头给斋饭（通过NPC动作或zhaifan参数触发）
    elseif ($param === 'zhaifan' || $roomId === 'dntg/hgs/zhaiyuan') {
        foreach ($npcs as $npc) {
            if ($npc['npc_id'] === 'zhaiyuan_laotou' || $npc['name'] === '老头') {
                $targetNpc = $npc;
                $foodItem = 'zhaifan';
                $foodName = '斋饭';
                $foodQuantity = 1;
                break;
            }
        }
        if (!$targetNpc) {
            return ['success' => false, 'message' => '老头不在这里。', 'redirect' => room_url($area, $roomId)];
        }
    }
    // 通用：检查房间中是否有NPC带有"yao"类型的动作
    else {
        foreach ($npcs as $npc) {
            $npcActions = !empty($npc['actions']) ? json_decode($npc['actions'], true) : [];
            foreach ($npcActions as $action) {
                $actionCmd = $action['action_cmd'] ?? '';
                if (strpos($actionCmd, 'yao') === 0) {
                    $targetNpc = $npc;
                    // 根据动作命令确定食物类型
                    if (strpos($actionCmd, 'zhaifan') !== false) {
                        $foodItem = 'zhaifan';
                        $foodName = '斋饭';
                        $foodQuantity = 1;
                    } else {
                        $foodItem = 'baozi';
                        $foodName = '包子';
                        $foodQuantity = 1;
                    }
                    break 2;
                }
            }
        }
        if (!$targetNpc) {
            return ['success' => false, 'message' => '这里没有人可以给你吃的。', 'redirect' => room_url($area, $roomId)];
        }
    }
    
    $npcName = $targetNpc['name'] ?? '他';
    $charName = $char['name'] ?? '你';
    
    // 获取玩家背包
    $inventory = CharacterModel::getInventory($charId);
    
    // 检查玩家是否已经有相同的食物
    $hasFood = false;
    foreach ($inventory as $item) {
        $itemId = $item['item_id'] ?? '';
        $itemName = $item['item_name'] ?? '';
        if (strpos($itemId, $foodItem) !== false || strpos($itemName, $foodName) !== false) {
            $hasFood = true;
            break;
        }
    }
    
    if ($hasFood) {
        return ['success' => true, 'message' => "{$npcName}看了看你，说道：你不是已经有{$foodName}了吗？先吃完再说。", 'redirect' => room_url($area, $roomId)];
    }
    
    // 检查房间中是否已有食物
    $roomItems = $room['items'] ?? [];
    $roomHasFood = false;
    foreach ($roomItems as $item) {
        $itemId = $item['item_id'] ?? $item['id'] ?? '';
        $itemName = $item['name'] ?? $item['item_name'] ?? '';
        if (strpos($itemId, $foodItem) !== false || strpos($itemName, $foodName) !== false) {
            $roomHasFood = true;
            break;
        }
    }
    
    if ($roomHasFood) {
        return ['success' => true, 'message' => "{$npcName}看了看地上，说道：那里不是有{$foodName}吗？你先把它捡起来吧。", 'redirect' => room_url($area, $roomId)];
    }
    
    // 给玩家食物
    addBaoziToInventory($charId, $foodItem, $foodQuantity);
    
    // 根据NPC类型生成不同的消息
    if ($foodItem === 'baozi') {
        $broadcastMsg = HTML_HIYEL . "{$npcName}从蒸笼里拿出{$foodQuantity}个热腾腾的{$foodName}递给{$charName}。" . HTML_NOR;
        $selfMsg = HTML_HIGRN . "{$npcName}笑吟吟地拿出{$foodQuantity}个热腾腾的大{$foodName}递给你，快拿去吃吧！" . HTML_NOR;
    } else {
        $broadcastMsg = HTML_HIYEL . "{$npcName}从灶上端来一碗{$foodName}递给{$charName}。" . HTML_NOR;
        $selfMsg = HTML_HIGRN . "{$npcName}笑呵呵地端来一碗热乎的{$foodName}递给你，慢用。" . HTML_NOR;
    }
    
    MessageDaemon::broadcastToRoom($roomId, $broadcastMsg, $charId);
    
    return [
        'success' => true,
        'message' => $selfMsg,
        'redirect' => room_url($area, $roomId)
    ];
}

/**
 * 添加食物到玩家背包
 */
function addBaoziToInventory(int $charId, string $itemId, int $quantity): void {
    // 检查是否已有该物品
    $existing = Database::queryOne(
        "SELECT id, quantity FROM character_inventory WHERE char_id = ? AND item_id = ? LIMIT 1",
        [$charId, $itemId]
    );
    
    if ($existing) {
        // 增加数量
        Database::execute(
            "UPDATE character_inventory SET quantity = quantity + ? WHERE id = ?",
            [$quantity, $existing['id']]
        );
    } else {
        // 插入新记录
        Database::execute(
            "INSERT INTO character_inventory (char_id, item_id, quantity, equipped) VALUES (?, ?, ?, 0)",
            [$charId, $itemId, $quantity]
        );
    }
}
