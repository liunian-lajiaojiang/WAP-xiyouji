<?php
/**
 * 克隆物品命令 (clone) - 管理员克隆物品给指定角色
 *
 * 用法: clone <角色名> <item_id> [category]
 */
require_once __DIR__ . '/../helpers/WizardHelper.php';
require_once __DIR__ . '/../models/User.php';

/**
 * 克隆物品命令入口
 * @param int $charId 操作者角色ID
 * @param string $param 参数: "角色名 item_id [category]"
 * @return array
 */
function cmd_clone(int $charId, string $param = ''): array {
    // 获取操作者信息并检查权限
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    $user = UserModel::find($char['user_id']);
    if (!$user || !WizardHelper::canUseCommand($user['id'], 'clone')) {
        return ['success' => false, 'message' => '你没有权限执行此命令'];
    }

    // 解析参数
    $param = trim($param);
    if (empty($param)) {
        return ['success' => false, 'message' => '用法: clone <角色名> <item_id> [category]'];
    }

    $parts = explode(' ', $param);
    if (count($parts) < 2) {
        return ['success' => false, 'message' => '用法: clone <角色名> <item_id> [category]'];
    }

    $targetName = $parts[0];
    $itemId = $parts[1];
    $category = $parts[2] ?? null;

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

    // 查找物品模板
    if ($category !== null) {
        $itemTemplate = Database::queryOne(
            'SELECT * FROM items WHERE item_id = ? AND category = ?',
            [$itemId, $category]
        );
    } else {
        $itemTemplate = Database::queryOne(
            'SELECT * FROM items WHERE item_id = ?',
            [$itemId]
        );
    }

    if (!$itemTemplate) {
        return ['success' => false, 'message' => "物品模板不存在: {$itemId}" . ($category ? " (category: {$category})" : '')];
    }

    $itemName = $itemTemplate['name'] ?? $itemId;
    $targetCharId = $targetChar['id'];
    $itemCategory = $itemTemplate['category'] ?? '';

    // 处理堆叠逻辑
    if (!empty($itemTemplate['stackable']) && $itemTemplate['stackable'] == 1) {
        $maxStack = intval($itemTemplate['max_stack'] ?? 99);

        // 查找已有记录
        $existing = Database::queryOne(
            'SELECT id, quantity FROM character_inventory WHERE char_id = ? AND item_id = ? AND COALESCE(category, \'\') = ?',
            [$targetCharId, $itemId, $itemCategory]
        );

        if ($existing) {
            $newQty = intval($existing['quantity']) + 1;
            if ($newQty > $maxStack) {
                return ['success' => false, 'message' => "堆叠已达上限 ({$maxStack})，无法继续克隆"];
            }
            Database::execute(
                'UPDATE character_inventory SET quantity = ? WHERE id = ?',
                [$newQty, $existing['id']]
            );
        } else {
            Database::execute(
                'INSERT INTO character_inventory (char_id, item_id, category, quantity) VALUES (?, ?, ?, 1)',
                [$targetCharId, $itemId, $itemCategory]
            );
        }
    } else {
        // 不可堆叠, 直接插入新记录
        Database::execute(
            'INSERT INTO character_inventory (char_id, item_id, category, quantity) VALUES (?, ?, ?, 1)',
            [$targetCharId, $itemId, $itemCategory]
        );
    }

    log_game('CLONE', "{$char['name']}({$user['username']}) 克隆 [{$itemName}] x1 给 {$targetChar['name']}");

    return ['success' => true, 'message' => "已克隆 [{$itemName}] x1 给 {$targetChar['name']}"];
}
