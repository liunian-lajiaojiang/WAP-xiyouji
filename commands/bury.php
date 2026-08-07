<?php
/**
 * 埋尸体命令 (bury / mai) - 埋葬尸体
 * 用法：bury 或 bury <尸体名称>
 * @param int $charId 角色ID
 * @param string $arg 命令参数
 */
function cmd_bury(int $charId, string $arg = ''): array {
    require_once __DIR__ . '/../models/Corpse.php';
    
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    $arg = trim($arg);
    
    // 检查是否在荒坟堆
    $currentArea = $char['current_area'] ?? '';
    $currentRoom = $char['current_room'] ?? '';
    $isInGraveyard = ($currentArea === 'changan' && $currentRoom === 'changan/fendui');
    
    if (!$isInGraveyard) {
        return ['success' => false, 'message' => '这里不是埋葬的地方，你需要去荒坟堆才能埋葬尸体。'];
    }
    
    if (empty($arg) || $arg === 'corpse' || $arg === '尸体') {
        // 没有指定，尝试埋自己携带的尸体
        return bury_carried_corpse($charId, $char);
    } else {
        // 指定了名称，埋房间中的尸体
        return bury_room_corpse($charId, $char, $arg);
    }
}

/**
 * 埋葬自己携带的尸体
 */
function bury_carried_corpse(int $charId, array $char): array {
    // 检查是否在荒坟堆
    $currentArea = $char['current_area'] ?? '';
    $currentRoom = $char['current_room'] ?? '';
    $isInGraveyard = ($currentArea === 'changan' && $currentRoom === 'changan/fendui');
    
    if (!$isInGraveyard) {
        return ['success' => false, 'message' => '这里不是埋葬的地方，你需要去荒坟堆才能埋葬尸体。'];
    }
    
    $carriedCorpses = Corpse::getCarriedCorpses($charId);
    
    if (empty($carriedCorpses)) {
        return ['success' => false, 'message' => '你身上没有背着尸体。'];
    }
    
    $corpse = $carriedCorpses[0];
    $corpseName = $corpse['owner_name'] . '的尸体';
    
    // 埋葬尸体
    Corpse::buryCorpse($corpse['id']);
    
    $selfMessage = "你找了个地方，将" . $corpseName . "好好埋葬了。愿逝者安息。\n";
    $broadcastMessage = $char['name'] . "将" . $corpseName . "埋葬了。\n";
    
    return [
        'success' => true,
        'message' => $selfMessage,
        'broadcast_message' => $broadcastMessage
    ];
}

/**
 * 埋葬房间中的尸体
 */
function bury_room_corpse(int $charId, array $char, string $targetName): array {
    $currentArea = $char['current_area'] ?? '';
    $currentRoom = $char['current_room'] ?? '';
    
    // 检查是否在荒坟堆
    $isInGraveyard = ($currentArea === 'changan' && $currentRoom === 'changan/fendui');
    
    if (!$isInGraveyard) {
        return ['success' => false, 'message' => '这里不是埋葬的地方，你需要去荒坟堆才能埋葬尸体。'];
    }
    
    // 获取房间中的尸体
    $corpses = Corpse::getCorpsesInRoom($currentArea, $currentRoom);
    
    if (empty($corpses)) {
        return ['success' => false, 'message' => '这里没有尸体可以埋葬。'];
    }
    
    // 查找匹配的尸体
    $targetCorpse = null;
    
    // 先尝试按ID查找
    if (is_numeric($targetName)) {
        foreach ($corpses as $corpse) {
            if ($corpse['id'] == intval($targetName)) {
                $targetCorpse = $corpse;
                break;
            }
        }
    }
    
    // 再尝试按名称查找
    if (!$targetCorpse) {
        foreach ($corpses as $corpse) {
            if (mb_strpos($corpse['owner_name'], $targetName) !== false) {
                $targetCorpse = $corpse;
                break;
            }
        }
    }
    
    if (!$targetCorpse) {
        return ['success' => false, 'message' => '这里没有叫 ' . $targetName . ' 的尸体。'];
    }
    
    $corpseName = $targetCorpse['owner_name'] . '的尸体';
    
    // 埋葬尸体
    Corpse::buryCorpse($targetCorpse['id']);
    
    $selfMessage = "你找了个地方，将" . $corpseName . "好好埋葬了。愿逝者安息。\n";
    $broadcastMessage = $char['name'] . "将" . $corpseName . "埋葬了。\n";
    
    return [
        'success' => true,
        'message' => $selfMessage,
        'broadcast_message' => $broadcastMessage
    ];
}
