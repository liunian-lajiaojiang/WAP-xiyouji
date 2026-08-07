<?php
/**
 * 寄存物品命令
 * 格式: jicun <物品> for <小时数>
 */

if (!defined('IN_GAME')) {
    die('Access Denied');
}

// 获取参数
$arg = trim($input ?? '');

if (empty($arg)) {
    return [
        'success' => false,
        'message' => "格式：jicun <物品> for <时间>\n时间以小时为单位，最长不超过二十四小时。\n"
    ];
}

// 解析参数
if (!preg_match('/^(.+)\s+for\s+(\d+)$/', $arg, $matches)) {
    return [
        'success' => false,
        'message' => "格式：jicun <物品> for <时间>\n时间以小时为单位，最长不超过二十四小时。\n"
    ];
}

$itemName = trim($matches[1]);
$hours = intval($matches[2]);

// 验证时间
if ($hours < 1 || $hours > 24) {
    return [
        'success' => false,
        'message' => "寄存时间一至二十四小时。\n"
    ];
}

// 查找物品
require_once __DIR__ . '/../models/Item.php';
$inventory = ItemModel::getCharacterInventory($charId);

$targetItem = null;
foreach ($inventory as $item) {
    if ($item['name'] == $itemName || $item['item_id'] == $itemName) {
        $targetItem = $item;
        break;
    }
}

if (!$targetItem) {
    return [
        'success' => false,
        'message' => "你没有这个物品。\n"
    ];
}

// 检查是否是deposit box（需要先mark）
if ($targetItem['item_id'] !== 'deposit_box') {
    return [
        'success' => false,
        'message' => "为了安全起见，请将寄存物品先放入箱子中。\n"
    ];
}

// 检查是否有记号
$mark = $targetItem['mark'] ?? '';
if (empty($mark)) {
    return [
        'success' => false,
        'message' => "你的箱子还没有做记号(mark)。\n"
    ];
}

// 计算费用（每小时1两银子 = 100铜钱）
$fee = $hours * 100;

// 检查金钱
require_once __DIR__ . '/../helpers/MoneyHelper.php';
if (!MoneyHelper::hasEnoughMoney($charId, $fee)) {
    return [
        'success' => false,
        'message' => "你没有足够的钱。\n"
    ];
}

// 扣除费用
MoneyHelper::deductMoney($charId, $fee);

// 保存物品数据到寄存表
require_once __DIR__ . '/../includes/db.php';

$expireTime = time() + ($hours * 3600);
$itemData = json_encode([
    'item_id' => $targetItem['item_id'],
    'name' => $targetItem['name'],
    'quantity' => $targetItem['quantity'] ?? 1,
    'mark' => $mark,
], JSON_UNESCAPED_UNICODE);

Database::execute(
    'INSERT INTO deposit_storage (char_id, item_id, item_data, mark, expire_time, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
    [$charId, $targetItem['id'], $itemData, $mark, $expireTime]
);

// 从背包中移除物品
Database::execute('DELETE FROM character_inventory WHERE id = ?', [$targetItem['id']]);

log_game('JICUN', "{$char['name']} 寄存 {$targetItem['name']} (记号: {$mark}) {$hours}小时");

return [
    'success' => true,
    'message' => sprintf(
        "%s寄存了一只箱子%s小时。\n规定期限内，使用「qu %s」即可取回。\n",
        $char['name'],
        chinese_number($hours),
        $mark
    )
];

