<?php
/**
 * 玩具物品互动命令
 * 处理击打娃娃（buwawa）的捏、耍命令
 * 处理镇妖锤（mallet）的砸命令
 */

/**
 * 捏娃娃
 * 用法：action.php?action=niedoll&param=<item_id>&category=<category>
 */
function cmd_niedoll(int $charId, string $param = ''): array {
    return cmd_play_toy($charId, $param, 'nie');
}

/**
 * 耍娃娃
 * 用法：action.php?action=shuadoll&param=<item_id>:<target_name>&category=<category>
 */
function cmd_shuadoll(int $charId, string $param = ''): array {
    return cmd_play_toy($charId, $param, 'shua');
}

/**
 * 通用玩具互动处理
 */
function cmd_play_toy(int $charId, string $param, string $mode): array {
    if (empty($param)) {
        return ['success' => false, 'message' => '你要玩什么？'];
    }

    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    //  busy check
    if (function_exists('is_player_busy') && is_player_busy($charId)) {
        return ['success' => false, 'message' => '你现在正忙着呢。'];
    }

    // 解析 item_id、category 和 target_name
    // param 格式: item_id 或 item_id:category
    // target_name 通过 GET 参数 target 传递（来自 JavaScript prompt）
    $parts = explode(':', $param, 2);
    $itemId = $parts[0];
    $category = $parts[1] ?? $_GET['category'] ?? $_POST['category'] ?? 'obj';
    
    // 目标名称通过 target 参数传递（来自 JavaScript prompt）
    $targetName = trim($_GET['target'] ?? $_POST['target'] ?? '');

    // 检查背包中是否有该物品
    $sql = "SELECT ci.id, ci.item_id, ci.category, ci.quantity, gi.name, gi.unit, gi.description
            FROM character_inventory ci
            JOIN items gi ON ci.item_id = gi.item_id AND ci.category = gi.category
            WHERE ci.char_id = ? AND ci.item_id = ? AND ci.category = ?";
    $invItem = Database::queryOne($sql, [$charId, $itemId, $category]);

    if (!$invItem) {
        return ['success' => false, 'message' => '你身上没有这个东西。'];
    }

    // 仅允许 buwawa
    if ($itemId !== 'buwawa') {
        return ['success' => false, 'message' => '这好像不是娃娃。'];
    }

    $roomId = $char['current_room'] ?? '';
    $area = $char['current_area'] ?? '';
    $charName = $char['name'] ?? '某人';

    if ($mode === 'nie') {
        // 捏娃娃动作库
        $dos = [
            '伸出食指轻轻点一下',
            '用手掌拍一下',
            '伸出拇指按一下',
            '伸手揪一下',
            '伸手摸一下',
            '伸手拧一下',
            '伸手戳一下',
        ];
        $bodyParts = [
            '脑袋', '头发', '眼睛', '鼻子', '耳朵', '小嘴', '脸蛋',
            '额头', '下巴', '脖子', '左手', '右手', '左脚', '右脚',
            '小肚子', '后背', '屁股', '小指头', '小脚丫',
        ];
        $reactions = [
            '咧开小嘴吱吱地笑个不停。',
            '张开嘴巴啊了一声。',
            '皱了皱眉头。',
            '眨巴眨巴眼睛，一脸无辜。',
            '晃了晃小脑袋。',
            '扭扭身子躲开了。',
            '小手一挥拍了回来。',
            '小脚一蹬，在地上打了个滚。',
            '咿咿呀呀地叫了几声。',
            '鼓起腮帮子，假装生气了。',
        ];

        $action = $dos[array_rand($dos)];
        $part = $bodyParts[array_rand($bodyParts)];
        $reaction = $reactions[array_rand($reactions)];

        $selfMsg = "你{$action}{$invItem['name']}的{$part}。\n";
        $selfMsg .= "{$invItem['name']}{$reaction}";
        $broadcastMsg = "{$charName}{$action}{$invItem['name']}的{$part}。\n";
        $broadcastMsg .= "{$invItem['name']}{$reaction}";

    } else {
        // 耍娃娃：需要目标玩家（param 格式: item_id:target_name）
        if (empty($targetName)) {
            return ['success' => false, 'message' => '你要耍谁？'];
        }

        // 查找目标玩家（不考虑在线状态）
        $sql = "SELECT id, name, race, gender, level 
                FROM characters 
                WHERE current_area = ? AND current_room = ? AND id != ?";
        $roomPlayers = Database::queryAll($sql, [$area, $roomId, $charId]);
        $target = null;
        foreach ($roomPlayers as $p) {
            if (stripos($p['name'], $targetName) !== false || strval($p['id']) === $targetName) {
                $target = $p;
                break;
            }
        }

        // 如果没找到玩家，查找NPC
        if (!$target) {
            require_once MODEL_PATH . 'Room.php';
            $roomNpcs = RoomModel::getNpcsInRoom($area, $roomId);
            foreach ($roomNpcs as $npc) {
                if (stripos($npc['name'], $targetName) !== false || strval($npc['id']) === $targetName) {
                    $target = $npc;
                    break;
                }
            }
        }

        if (!$target) {
            return ['success' => false, 'message' => '这里没有这个人。'];
        }

        $dos = [
            "从{$charName}手上蹦蹦跳跳地跳到{$target['name']}的",
            "一下子从{$charName}手上窜到{$target['name']}的",
            "连滚带爬地爬到{$target['name']}的",
        ];
        $tparts = ['脑袋上', '肩膀上', '头顶上', '手心里', '膝盖上'];
        $reactions = [
            "然后迅速后退一小步。",
            "张开小嘴就咬了一口。",
            "用小手挠了挠。",
            "然后转了一圈。",
        ];
        $returns = [
            "然后又回到{$charName}手上。",
            "然后乖乖地回到{$charName}手上。",
            "然后一蹦一跳地回到{$charName}手上。",
        ];

        $action = $dos[array_rand($dos)];
        $part = $tparts[array_rand($tparts)];
        $reaction = $reactions[array_rand($reactions)];
        $returnAction = $returns[array_rand($returns)];

        $selfMsg = "你把{$invItem['name']}耍向{$target['name']}。\n";
        $selfMsg .= "{$invItem['name']}{$action}{$part}，{$reaction}{$returnAction}";
        $broadcastMsg = "{$charName}把{$invItem['name']}耍向{$target['name']}。\n";
        $broadcastMsg .= "{$invItem['name']}{$action}{$part}，{$reaction}{$returnAction}";
        $targetMsg = "{$charName}把{$invItem['name']}耍向你！{$invItem['name']}{$action}{$part}，{$reaction}{$returnAction}";

        // 给目标玩家发消息
        MessageDaemon::queueMessageToSelf(intval($target['id']), HTML_HIYEL . $targetMsg . HTML_NOR, 'room_event');
    }

    // 广播到房间（排除自己，让其他玩家看到）
    if (!empty($roomId)) {
        MessageDaemon::broadcastToRoom($roomId, HTML_HIYEL . $broadcastMsg . HTML_NOR, $charId);
    }

    // 将自己的动作消息加入消息队列（用于聊天页面显示）
    MessageDaemon::queueMessageToSelf($charId, HTML_HIYEL . $selfMsg . HTML_NOR, 'room_event');

    return [
        'success' => true,
        'message' => HTML_HIYEL . $selfMsg . HTML_NOR,
        'skip_queue' => true,
    ];
}

/**
 * 砸（镇妖锤）
 * 用法：action.php?action=hammer&param=<item_id>:<target_name>&category=<category>
 */
/**
 * 砸（镇妖锤）
 * 用法：action.php?action=hammer&param=<item_id>:<target_name>&category=<category>
 */
function cmd_hammer(int $charId, string $param = ''): array {
    // 砸锤子逻辑
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    // 解析 item_id 和 category
    $parts = explode(':', $param, 2);
    $itemId = $parts[0];
    $category = $parts[1] ?? $_GET['category'] ?? $_POST['category'] ?? 'obj';
    
    // 目标名称通过 target 参数传递（来自 JavaScript prompt）
    $targetName = trim($_GET['target'] ?? $_POST['target'] ?? '');

    // 检查是否持有镇妖锤
    $sql = "SELECT * FROM character_inventory ci JOIN items gi 
            ON ci.item_id = gi.item_id AND ci.category = gi.category
            WHERE ci.char_id = ? AND ci.item_id = ? AND ci.category = ?";
    $mallet = Database::queryOne($sql, [$charId, $itemId, $category]);
    if (!$mallet) {
        return ['success' => false, 'message' => '你身上没有镇妖锤。'];
    }

    // 需要目标玩家
    if (empty($targetName)) {
        return ['success' => false, 'message' => '你要砸谁？'];
    }

    // 查找目标玩家（不考虑在线状态）
    $sql = "SELECT id, name, race, gender, level 
            FROM characters 
            WHERE current_area = ? AND current_room = ? AND id != ?";
    $roomPlayers = Database::queryAll($sql, [$char['current_area'], $char['current_room'], $charId]);
    $target = null;
    foreach ($roomPlayers as $p) {
        if (stripos($p['name'], $targetName) !== false || strval($p['id']) === $targetName) {
            $target = $p;
            break;
        }
    }

    // 如果没找到玩家，查找NPC
    if (!$target) {
        require_once MODEL_PATH . 'Room.php';
        $roomNpcs = RoomModel::getNpcsInRoom($char['current_area'], $char['current_room']);
        foreach ($roomNpcs as $npc) {
            if (stripos($npc['name'], $targetName) !== false || strval($npc['id']) === $targetName) {
                $target = $npc;
                break;
            }
        }
    }

    if (!$target) {
        return ['success' => false, 'message' => '这里没有这个人。'];
    }

    $charName = $char['name'];
    
    $hits = [
        "\n{$charName}双手抓着锤子狠狠地砸下！\n\n",
        "\n{$charName}举起锤子，狠狠地砸下！\n\n",
        "\n{$charName}拼命地举起重锤，然后轰然砸下！\n\n",
        "\n{$charName}突然举起一个巨锤，然后轰然砸在地上！\n\n",
    ];

    $selfMsg = "{$charName}高高举起一把巨大锤子向{$target['name']}狠狠地砸下！\n";
    $selfMsg .= "\n只听见哐！哐！哐！\n\n一阵惊天动地的巨响。\n";
    $selfMsg .= $hits[array_rand($hits)];
    
    $broadcastMsg = "{$charName}高高举起一把巨大锤子向{$target['name']}狠狠地砸下！\n";
    $broadcastMsg .= "\n只听见哐！哐！哐！\n\n一阵惊天动地的巨响。\n";
    $broadcastMsg .= $hits[array_rand($hits)];
    
    $targetMsg = "{$charName}高高举起一把巨大锤子向你狠狠地砸下！\n";
    $targetMsg .= "\n只听见哐！哐！哐！\n\n一阵惊天动地的巨响。\n";
    $targetMsg .= $hits[array_rand($hits)];

    // 给目标玩家发消息
    MessageDaemon::queueMessageToSelf(intval($target['id']), HTML_HIYEL . $targetMsg . HTML_NOR, 'room_event');

    // 广播到房间（排除自己，让其他玩家看到）
    if (!empty($char['current_room'])) {
        MessageDaemon::broadcastToRoom($char['current_room'], HTML_HIYEL . $broadcastMsg . HTML_NOR, $charId);
    }

    // 将自己的动作消息加入消息队列（用于聊天页面显示）
    MessageDaemon::queueMessageToSelf($charId, HTML_HIYEL . $selfMsg . HTML_NOR, 'room_event');

    return [
        'success' => true,
        'message' => HTML_HIYEL . $selfMsg . HTML_NOR,
        'skip_queue' => true,
    ];
}

/**
 * 迷魂散
 * 用法：action.php?action=pour&param=<item_id>:<target_item_id>&category=<category>
 */
/**
 * 拍照（相机）
 * 用法：action.php?action=shoot&param=<item_id>&category=<category>
 */
function cmd_shoot(int $charId, string $param = ''): array {
    // 拍照逻辑：生成照片物品
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    // 解析 item_id 和 category
    $parts = explode(':', $param, 2);
    $itemId = $parts[0];
    $category = $parts[1] ?? $_GET['category'] ?? $_POST['category'] ?? 'obj';

    // 检查是否持有相机
    $sql = "SELECT * FROM character_inventory ci JOIN items gi 
            ON ci.item_id = gi.item_id AND ci.category = gi.category
            WHERE ci.char_id = ? AND ci.item_id = ? AND ci.category = ?";
    $camera = Database::queryOne($sql, [$charId, $itemId, $category]);
    if (!$camera) {
        return ['success' => false, 'message' => '你身上没有相机。'];
    }

    // 生成照片描述（模拟相机功能）
    $room = RoomModel::load($char['current_area'], $char['current_room']);
    if (!$room) {
        return ['success' => false, 'message' => '无法获取当前房间信息'];
    }
    
    $roomName = $room['name'] ?? '未知房间';
    $roomDesc = $room['description'] ?? '';
    $desc = "{$roomName}照片\n\n{$roomDesc}\n";

    // 获取房间内玩家
    $players = CharacterModel::getRoomPlayers($char['current_area'], $char['current_room'], $charId);
    foreach ($players as $p) {
        $desc .= "  {$p['name']}";
        $expressions = [
            '正微笑着', '抬头看着', '低头思考着', '正四处张望',
            '正在聊天', '显得很疲惫', '精神抖擞地'
        ];
        $desc .= $expressions[array_rand($expressions)] . "\n";
    }
    
    // 获取房间内NPC
    $npcs = RoomModel::getNpcsInRoom($char['current_area'], $char['current_room']);
    foreach ($npcs as $npc) {
        $desc .= "  {$npc['name']}";
        $npcExpressions = [
            '站在一旁', '神情严肃', '面无表情', '目光呆滞',
            '来回踱步', '闭目养神', '四处打量', '低声念叨着'
        ];
        $desc .= $npcExpressions[array_rand($npcExpressions)] . "\n";
    }

    // 创建照片物品到背包（使用房间名作为 category）
    ItemModel::addToInventory($charId, 'photo', 1, $roomName);
    
    // 将动态描述写入 enchantments JSON
    $enchantments = json_encode(['photo_desc' => $desc]);
    $sql = "UPDATE character_inventory 
            SET enchantments = ? 
            WHERE char_id = ? AND item_id = 'photo' AND category = ? 
            ORDER BY id DESC LIMIT 1";
    Database::execute($sql, [$enchantments, $charId, $roomName]);

    // 返回成功消息
    $broadcastMsg = "{$char['name']}举起相机咔嚓一声拍了张照片！";
    MessageDaemon::broadcastToRoom($char['current_room'], HTML_HIYEL . $broadcastMsg . HTML_NOR, $charId);

    return [
        'success' => true,
        'message' => HTML_HIYEL . '你拍了一张照片，已存入背包！' . HTML_NOR,
        'skip_queue' => true
    ];
}

/**
 * 迷魂散下药（倒入液体容器）
 * 用法：action.php?action=pour&param=蒙汗药 in 酒盏
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 适用物品：slumber_drug（蒙汗药）、poison_dust（极乐逍遥散）
 * 效果：写入目标容器的 enchantments JSON $.slumber_effect = 100
 */
function cmd_poison_pour(int $charId, string $param = ''): array {
    require_once __DIR__ . '/../helpers/LiquidContainerHelper.php';

    if (empty($param) || strpos($param, ' in ') === false) {
        return ['success' => false, 'message' => "指令格式：pour <药> in <容器>\n例如：pour 蒙汗药 in 酒盏"];
    }

    [$drugName, $targetName] = array_map('trim', explode(' in ', $param, 2));

    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    $inventory = ItemModel::getCharacterItems($charId);

    // 1. 查找药品（必须是可下药的 drug/misc 物品）
    $drugIds = ['slumber_drug', 'poison_dust'];
    $drug = null;
    foreach ($inventory as $inv) {
        if (!in_array($inv['item_id'], $drugIds, true)) {
            continue;
        }
        if ($inv['name'] === $drugName || strpos($inv['name'], $drugName) !== false) {
            $drug = $inv;
            break;
        }
    }
    if (!$drug) {
        return ['success' => false, 'message' => "你身上没有{$drugName}。"];
    }

    // 2. 查找目标容器（必须在背包中）
    $target = null;
    foreach ($inventory as $inv) {
        if ($inv['id'] === $drug['id']) {
            continue; // 排除药品本身
        }
        if ($inv['name'] === $targetName || strpos($inv['name'], $targetName) !== false) {
            $target = $inv;
            break;
        }
    }
    if (!$target) {
        return ['success' => false, 'message' => "你身上没有{$targetName}这种东西。"];
    }

    // 3. 必须是液体容器
    if (!LiquidContainerHelper::isLiquidContainer($target)) {
        return ['success' => false, 'message' => "{$target['name']}不是液体容器，没法下药。"];
    }

    // 4. 容器里必须有液体（对应 LPC: if( !ob->query("liquid/remaining") )）
    if (!isset($target['liquid_remaining']) || (int)$target['liquid_remaining'] <= 0) {
        return ['success' => false, 'message' => "{$target['name']}里什么也没有，不能下药。"];
    }

    // 5. 在容器 enchantments JSON 中写入 slumber_effect = 100
    $sql = "UPDATE character_inventory
            SET enchantments = JSON_SET(IF(TRIM(enchantments) = '', JSON_OBJECT(), COALESCE(enchantments, JSON_OBJECT())), '$.slumber_effect', 100)
            WHERE id = ? AND char_id = ?";
    Database::execute($sql, [$target['id'], $charId]);

    // 6. 消耗 1 单位药品
    ItemModel::removeFromInventory($charId, $drug['item_id'], 1, $drug['category']);

    // 7. 广播房间消息（参考 LPC: message_vision）
    $drugDisplay = $drug['name'] ?? $drugName;
    $broadcastMsg = "{$char['name']}将一些{$drugDisplay}倒入{$target['name']}中，摇了摇。";
    MessageDaemon::broadcastToRoom($char['current_room'], $broadcastMsg, $charId);

    return [
        'success' => true,
        'message' => "你将一些{$drugDisplay}倒入{$target['name']}中。",
        'skip_queue' => true
    ];
}
