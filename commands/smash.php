<?php
/**
 * 销毁物品命令 (smash)
 *
 * 用法: smash <inv_id>              -- 销毁角色背包中的物品
 *       smash room <room_item_id>   -- 销毁房间中的物品
 * 权限: immortal (等级2) 及以上
 */

require_once __DIR__ . '/../helpers/WizardHelper.php';
require_once __DIR__ . '/../includes/functions.php';

/**
 * 执行 smash 命令
 * @param int $charId 操作者角色ID
 * @param string $param 参数: "<inv_id>" 或 "room <room_item_id>"
 * @return array
 */
function cmd_smash(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    $userId = intval($char['user_id']);
    if (!WizardHelper::canUseCommand($userId, 'smash')) {
        return ['success' => false, 'message' => '你的巫师等级不够，无法使用 smash 命令。需要神仙(immortal)以上权限。'];
    }

    $parts = preg_split('/\s+/', trim($param));
    if (empty($parts[0])) {
        return ['success' => false, 'message' => "用法:\n  smash <inv_id>            -- 销毁背包物品\n  smash room <room_item_id> -- 销毁房间物品"];
    }

    // 销毁房间物品
    if (strtolower($parts[0]) === 'room') {
        if (!isset($parts[1])) {
            return ['success' => false, 'message' => '用法: smash room <room_item_id>'];
        }
        $roomItemId = intval($parts[1]);
        $item = Database::queryOne("SELECT ri.*, i.name FROM room_items ri LEFT JOIN items i ON ri.item_id = i.item_id WHERE ri.id = ?", [$roomItemId]);
        if (!$item) {
            return ['success' => false, 'message' => "找不到房间物品ID: {$roomItemId}"];
        }
        Database::execute("DELETE FROM room_items WHERE id = ?", [$roomItemId]);
        log_game('SMASH', "巫师 {$char['name']} 销毁了房间物品 {$item['name']}(room_item_id:{$roomItemId})");
        return ['success' => true, 'message' => "已销毁房间物品: {$item['name']} (ID:{$roomItemId})"];
    }

    // 销毁背包物品
    $invId = intval($parts[0]);
    $inv = Database::queryOne(
        "SELECT ci.*, i.name FROM character_inventory ci LEFT JOIN items i ON ci.item_id = i.item_id WHERE ci.id = ?",
        [$invId]
    );
    if (!$inv) {
        return ['success' => false, 'message' => "找不到背包物品ID: {$invId}"];
    }

    // 安全检查：只能销毁自己背包中的物品（或immortal及以上可销毁任何人的）
    $wizLevel = WizardHelper::getWizardLevel($userId);
    if ($inv['char_id'] != $charId && $wizLevel < WizardHelper::LEVEL_IMMORTAL) {
        return ['success' => false, 'message' => '你只能销毁自己背包中的物品。神仙(immortal)及以上可销毁任何物品。'];
    }

    Database::execute("DELETE FROM character_inventory WHERE id = ?", [$invId]);
    log_game('SMASH', "巫师 {$char['name']} 销毁了物品 {$inv['name']}(inv_id:{$invId}, 所属角色ID:{$inv['char_id']})");
    return ['success' => true, 'message' => "已销毁物品: {$inv['name']} (ID:{$invId})"];
}
