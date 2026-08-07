<?php
/**
 * 购买过期寄存物品命令
 * 格式: pick box
 */

if (!defined('IN_GAME')) {
    die('Access Denied');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../helpers/MoneyHelper.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../models/Item.php';

function cmd_pick(int $charId, string $param = ''): array {
    // 获取角色信息
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 获取参数
    $arg = trim($param);
    
    if ($arg !== 'box') {
        return [
            'success' => false,
            'message' => "格式：pick box\n你将在公开出售的物品中随机选购一个。\n"
        ];
    }
    
    // 查找所有过期的寄存物品
    $expiredItems = Database::queryAll(
        'SELECT * FROM deposit_storage WHERE expire_time < ? ORDER BY expire_time ASC',
        [time()]
    );
    
    if (empty($expiredItems)) {
        return [
            'success' => false,
            'message' => "目前没有公开出售的过期物品。\n"
        ];
    }
    
    // 费用：10两银子 = 1000铜钱
    $fee = 1000;
    
    // 检查金钱
    if (!MoneyHelper::hasEnoughMoney($charId, $fee)) {
        return [
            'success' => false,
            'message' => "你没有足够的钱（需要10两银子）。\n"
        ];
    }
    
    // 随机选择一个
    $randomIndex = array_rand($expiredItems);
    $selectedItem = $expiredItems[$randomIndex];
    
    // 扣除费用
    MoneyHelper::deductMoney($charId, $fee);
    
    // 恢复物品到背包
    $itemData = json_decode($selectedItem['item_data'], true);
    
    if ($itemData) {
        ItemModel::addToInventory($charId, $itemData['item_id'], $itemData['quantity'] ?? 1);
    }
    
    // 从寄存表中删除
    Database::execute('DELETE FROM deposit_storage WHERE id = ?', [$selectedItem['id']]);
    
    log_game('PICK', "{$char['name']} 购买过期箱子 (原主人ID: {$selectedItem['char_id']})");
    
    return [
        'success' => true,
        'message' => sprintf(
            "%s购买了一个箱子。\n",
            $char['name']
        )
    ];
}