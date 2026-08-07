<?php
/**
 * 取回寄存物品命令
 * 格式: qu <记号>
 */

if (!defined('IN_GAME')) {
    die('Access Denied');
}

// 获取参数
$mark = trim($input ?? '');

if (empty($mark)) {
    return [
        'success' => false,
        'message' => "格式：qu <记号>\n"
    ];
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../models/Item.php';

// 查找寄存的物品
$deposit = Database::queryOne(
    'SELECT * FROM deposit_storage WHERE char_id = ? AND mark = ?',
    [$charId, $mark]
);

if (!$deposit) {
    return [
        'success' => false,
        'message' => "你没有寄存记号为「{$mark}」的物品，或者已经取回了。\n"
    ];
}

// 检查是否过期
if ($deposit['expire_time'] < time()) {
    // 已过期，从存储表中删除
    Database::execute('DELETE FROM deposit_storage WHERE id = ?', [$deposit['id']]);
    
    return [
        'success' => false,
        'message' => "你的寄存已过期，物品已被没收。\n"
    ];
}

// 恢复物品到背包
$itemData = json_decode($deposit['item_data'], true);

if (!$itemData) {
    return [
        'success' => false,
        'message' => "物品数据损坏，无法取回。\n"
    ];
}

// 重新插入到 character_inventory（液体容器会自动拆分为独立行）
ItemModel::addToInventory($charId, $itemData['item_id'], $itemData['quantity'] ?? 1);

// 从寄存表中删除
Database::execute('DELETE FROM deposit_storage WHERE id = ?', [$deposit['id']]);

log_game('QU', "{$char['name']} 取回寄存物品 (记号: {$mark})");

return [
    'success' => true,
    'message' => sprintf(
        "%s取回了一只箱子。\n",
        $char['name']
    )
];

