<?php
/**
 * 背包命令 (inventory)
 */
function cmd_inventory(int $charId, string $param = ''): array {
    $char = CharacterModel::getFullInfo($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    $inventory = $char['inventory'];
    
    if (empty($inventory)) {
        return [
            'success' => true,
            'message' => '你现在身上没有任何东西。',
            'items' => []
        ];
    }
    
    $output = [];
    $output[] = HIMAG . '你身上的物品：' . NOR;
    $output[] = '';
    
    foreach ($inventory as $item) {
        $equipMark = $item['equipped'] ? HICYN . ' [装备中]' . NOR : '';
        $quantity = $item['quantity'] > 1 ? " x{$item['quantity']}" : '';
        $output[] = '  ' . $item['item_name'] . $quantity . $equipMark;
    }
    
    return [
        'success' => true,
        'type' => 'inventory_display',
        'output' => implode("\n", $output),
        'items' => $inventory
    ];
}

// 别名支持
if (!function_exists('cmd_i')) {
    function cmd_i(int $charId, string $param = ''): array {
        return cmd_inventory($charId, $param);
    }
}

