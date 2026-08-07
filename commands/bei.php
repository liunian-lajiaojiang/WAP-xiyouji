<?php
/**
 * 背尸体命令 (bei) - 背起房间中的尸体
 * 用法：bei <尸体名称> 或 bei <尸体ID>
 * @param int $charId 角色ID
 * @param string $arg 命令参数
 */
function cmd_bei(int $charId, string $arg = ''): array {
    require_once __DIR__ . '/../models/Corpse.php';
    
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 检查参数
    if (empty($arg)) {
        return ['success' => false, 'message' => '你要背起谁的尸体？用法：bei <尸体名称>'];
    }
    
    // 检查是否已经携带了尸体
    $carriedCorpses = Corpse::getCarriedCorpses($charId);
    if (!empty($carriedCorpses)) {
        return ['success' => false, 'message' => '你身上已经背着一具尸体了。'];
    }
    
    $currentArea = $char['current_area'] ?? '';
    $currentRoom = $char['current_room'] ?? '';
    
    // 获取房间中的尸体
    $corpses = Corpse::getCorpsesInRoom($currentArea, $currentRoom);
    
    if (empty($corpses)) {
        return ['success' => false, 'message' => '这里没有尸体可以背。'];
    }
    
    // 查找匹配的尸体
    $targetCorpse = null;
    
    // 先尝试按ID查找
    if (is_numeric($arg)) {
        foreach ($corpses as $corpse) {
            if ($corpse['id'] == intval($arg)) {
                $targetCorpse = $corpse;
                break;
            }
        }
    }
    
    // 再尝试按名称查找
    if (!$targetCorpse) {
        foreach ($corpses as $corpse) {
            if (mb_strpos($corpse['owner_name'], $arg) !== false) {
                $targetCorpse = $corpse;
                break;
            }
        }
    }
    
    if (!$targetCorpse) {
        return ['success' => false, 'message' => '这里没有叫 ' . $arg . ' 的尸体。'];
    }
    
    // 背起尸体
    Corpse::carryCorpse($targetCorpse['id'], $charId);
    
    $corpseName = $targetCorpse['owner_name'] . '的尸体';
    
    $selfMessage = "你将" . $corpseName . "扶了起来背在背上。\n";
    $broadcastMessage = $char['name'] . "将" . $corpseName . "扶了起来背在背上。\n";
    
    return [
        'success' => true,
        'message' => $selfMessage,
        'broadcast_message' => $broadcastMessage
    ];
}
