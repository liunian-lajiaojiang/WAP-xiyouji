<?php
/**
 * 购买命令 (buy) - 从NPC商店购买物品
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 用法: buy <物品> from <商人>
 */
require_once HELPER_PATH . 'MoneyHelper.php';
require_once MODEL_PATH . 'Item.php';
require_once MODEL_PATH . 'Shop.php';

function cmd_buy(int $charId, string $param = ''): array {
    if (empty($param)) {
        return ['success' => false, 'message' => '指令格式：buy <某物> from <某人>'];
    }
    
    // 解析参数: buy 宝剑 from 铁匠
    if (!preg_match('/^(.+?)\s+from\s+(.+)$/i', $param, $matches)) {
        return ['success' => false, 'message' => '指令格式：buy <某物> from <某人>'];
    }
    
    $itemName = trim($matches[1]);
    $sellerName = trim($matches[2]);
    
    // 获取角色信息
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    $userId = intval($char['user_id']);
    $isBlocked = Database::queryOne('SELECT id FROM user_blocks WHERE user_id = ? AND block_type = ?', [$userId, 'trade']);
    if ($isBlocked) {
        return ['success' => false, 'message' => '你的交易功能已被封禁'];
    }
    
    // is_busy() 检查（统一使用 is_player_busy）
    if (is_player_busy($charId)) {
        return ['success' => false, 'message' => '你现在正忙着呢。'];
    }
    
    // 获取当前房间
    $roomId = $char['room_id'] ?? $char['current_room'] ?? '';
    if (empty($roomId)) {
        return ['success' => false, 'message' => '你不在任何房间中。'];
    }
    
    // 获取房间中的NPC列表
    $npcs = NpcModel::getByRoom($roomId);
    
    // 查找商人NPC
    $seller = null;
    foreach ($npcs as $npc) {
        if (stripos($npc['name'], $sellerName) !== false || 
            stripos($npc['npc_id'], $sellerName) !== false) {
            $seller = $npc;
            break;
        }
    }
    
    if (!$seller) {
        return ['success' => false, 'message' => '你要跟谁买东西？'];
    }
    
    // 检查是否为商人(有merchant标记)
    if (!isset($seller['merchant']) || !$seller['merchant']) {
        return ['success' => false, 'message' => "{$seller['name']}不是商人，不卖东西。"];
    }
    
    // 从shop_items表获取商店物品
    $shopItems = ShopModel::getShopItems($seller['id']);
    
    // 查找要购买的物品
    $targetItem = null;
    
    foreach ($shopItems as $item) {
        if (stripos($item['item_name'] ?? $item['name'] ?? '', $itemName) !== false || 
            stripos($item['item_id'], $itemName) !== false) {
            $targetItem = $item;
            break;
        }
    }
    
    if (!$targetItem) {
        return ['success' => false, 'message' => "{$seller['name']}这里没有卖这个东西。"];
    }
    
    // 使用ShopModel购买物品
    $result = ShopModel::buyItem($charId, $seller['id'], $targetItem['item_id'], 1);
    
    if ($result['success']) {
        log_game('BUY', "{$char['name']} 从 {$seller['name']} 处购买 {$targetItem['item_name']}");
    }
    
    return $result;
}

