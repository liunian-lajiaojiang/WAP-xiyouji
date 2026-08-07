<?php
/**
 * 销毁物品命令 (dest) - 管理员销毁指定角色的物品
 *
 * 用法: dest <角色名> <item_id> [category]
 * 或:   dest <inventory_id>
 */
require_once __DIR__ . '/../helpers/WizardHelper.php';
require_once __DIR__ . '/../models/User.php';

/**
 * 销毁物品命令入口
 * @param int $charId 操作者角色ID
 * @param string $param 参数
 * @return array
 */
function cmd_dest(int $charId, string $param = ''): array {
    // 获取操作者信息并检查权限
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    $user = UserModel::find($char['user_id']);
    if (!$user || !WizardHelper::canUseCommand($user['id'], 'dest')) {
        return ['success' => false, 'message' => '你没有权限执行此命令'];
    }

    // 解析参数
    $param = trim($param);
    if (empty($param)) {
        return ['success' => false, 'message' => '用法: dest <角色名> <item_id> [category] 或 dest <inventory_id>'];
    }

    $parts = explode(' ', $param);

    // 如果参数是纯数字, 按 inventory_id 删除
    if (count($parts) === 1 && ctype_digit($parts[0])) {
        return destByInventoryId(intval($parts[0]), $char, $user);
    }

    // 否则按角色名+item_id查找
    if (count($parts) < 2) {
        return ['success' => false, 'message' => '用法: dest <角色名> <item_id> [category] 或 dest <inventory_id>'];
    }

    $targetName = $parts[0];
    $itemId = $parts[1];
    $category = $parts[2] ?? null;

    return destByCharAndItem($targetName, $itemId, $category, $char, $user);
}

/**
 * 按 inventory_id 销毁物品
 */
function destByInventoryId(int $inventoryId, array $operatorChar, array $operatorUser): array {
    $inv = Database::queryOne(
        'SELECT ci.*, i.name FROM character_inventory ci LEFT JOIN items i ON ci.item_id = i.item_id AND COALESCE(ci.category, \'\') = COALESCE(i.category, \'\') WHERE ci.id = ?',
        [$inventoryId]
    );

    if (!$inv) {
        return ['success' => false, 'message' => "物品记录不存在 (inventory_id: {$inventoryId})"];
    }

    $itemName = $inv['name'] ?? $inv['item_id'];

    // 如果已装备, 先卸装
    if (!empty($inv['equipped']) && $inv['equipped'] == 1) {
        Database::execute(
            'UPDATE character_inventory SET equipped = 0, equip_slot = \'\' WHERE id = ?',
            [$inventoryId]
        );
    }

    Database::execute('DELETE FROM character_inventory WHERE id = ?', [$inventoryId]);

    log_game('DEST', "{$operatorChar['name']}({$operatorUser['username']}) 销毁物品 [{$itemName}] (inv_id: {$inventoryId})");

    return ['success' => true, 'message' => "已销毁 [{$itemName}]"];
}

/**
 * 按角色名+item_id销毁物品
 */
function destByCharAndItem(string $targetName, string $itemId, ?string $category, array $operatorChar, array $operatorUser): array {
    // 查找目标角色
    $targetChar = Database::queryOne('SELECT id, name FROM characters WHERE LOWER(name) = LOWER(?)', [$targetName]);

    // 角色名找不到时，尝试按用户名查找
    if (!$targetChar) {
        $targetUser = UserModel::findByUsername($targetName);
        if ($targetUser) {
            $targetChar = CharacterModel::getByUserId($targetUser['id']);
        }
    }

    if (!$targetChar) {
        return ['success' => false, 'message' => "角色不存在: {$targetName}"];
    }

    $targetCharId = $targetChar['id'];

    // 查找物品
    if ($category !== null) {
        $inv = Database::queryOne(
            'SELECT ci.*, i.name FROM character_inventory ci LEFT JOIN items i ON ci.item_id = i.item_id AND COALESCE(ci.category, \'\') = COALESCE(i.category, \'\') WHERE ci.char_id = ? AND ci.item_id = ? AND COALESCE(ci.category, \'\') = ?',
            [$targetCharId, $itemId, $category]
        );
    } else {
        $inv = Database::queryOne(
            'SELECT ci.*, i.name FROM character_inventory ci LEFT JOIN items i ON ci.item_id = i.item_id AND COALESCE(ci.category, \'\') = COALESCE(i.category, \'\') WHERE ci.char_id = ? AND ci.item_id = ?',
            [$targetCharId, $itemId]
        );
    }

    if (!$inv) {
        return ['success' => false, 'message' => "未找到该物品: {$itemId}" . ($category ? " (category: {$category})" : '')];
    }

    $itemName = $inv['name'] ?? $itemId;

    // 如果已装备, 先卸装
    if (!empty($inv['equipped']) && $inv['equipped'] == 1) {
        Database::execute(
            'UPDATE character_inventory SET equipped = 0, equip_slot = \'\' WHERE id = ?',
            [$inv['id']]
        );
    }

    Database::execute('DELETE FROM character_inventory WHERE id = ?', [$inv['id']]);

    log_game('DEST', "{$operatorChar['name']}({$operatorUser['username']}) 销毁 {$targetChar['name']} 的物品 [{$itemName}]");

    return ['success' => true, 'message' => "已销毁 [{$itemName}]"];
}
