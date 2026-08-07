<?php
/**
 * 修理命令 - 花费银两修复装备耐久
 * 用法: repair / repair all / repair <物品名>
 */

function cmd_repair(int $charId, string $param = ''): array {
    // 获取角色信息
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    $subCmd = trim($param) ?: 'all';
    
    if ($subCmd === 'all') {
        // 修理所有装备（durability 字段满值=100）
        $items = Database::queryAll(
            "SELECT ci.*, i.name, i.type 
             FROM character_inventory ci
             JOIN items i ON ci.item_id = i.item_id AND ci.category = i.category
             WHERE ci.char_id = ? AND ci.durability < 100 
             AND i.type IN ('weapon','armor','helmet','boots','shield')",
            [$charId]
        );
        
        if (empty($items)) {
            return ['success' => false, 'message' => '你的装备都不需要修理。'];
        }
        
        $totalCost = 0;
        
        foreach ($items as $item) {
            $repairAmount = 100 - $item['durability'];
            $cost = $repairAmount * 10; // 每点耐久10银两
            $totalCost += $cost;
        }
        
        // 检查银两
        if (($char['silver'] ?? 0) < $totalCost) {
            return ['success' => false, 'message' => "修理所有装备需要{$totalCost}银两，你的银两不足。"];
        }
        
        // 执行修理
        Database::execute(
            "UPDATE character_inventory SET durability = 100 WHERE char_id = ? AND durability < 100 AND item_id IN (SELECT item_id FROM items WHERE type IN ('weapon','armor','helmet','boots','shield'))",
            [$charId]
        );
        Database::execute(
            "UPDATE characters SET silver = silver - ? WHERE id = ?",
            [$totalCost, $charId]
        );
        
        $repairedCount = count($items);
        return ['success' => true, 'message' => "你花费{$totalCost}银两修理了{$repairedCount}件装备。所有装备已恢复完好。"];
    } else {
        // 修理指定物品
        $item = Database::queryOne(
            "SELECT ci.*, i.name, i.type 
             FROM character_inventory ci
             JOIN items i ON ci.item_id = i.item_id AND ci.category = i.category
             WHERE ci.char_id = ? AND i.name LIKE ? 
             AND i.type IN ('weapon','armor','helmet','boots','shield') 
             LIMIT 1",
            [$charId, "%{$subCmd}%"]
        );
        
        if (!$item) {
            return ['success' => false, 'message' => "找不到名为「{$subCmd}」的装备。"];
        }
        
        if ($item['durability'] >= 100) {
            return ['success' => false, 'message' => "{$item['name']}不需要修理。"];
        }
        
        $repairAmount = 100 - $item['durability'];
        $cost = $repairAmount * 10;
        
        if (($char['silver'] ?? 0) < $cost) {
            return ['success' => false, 'message' => "修理{$item['name']}需要{$cost}银两，你的银两不足。"];
        }
        
        Database::execute("UPDATE character_inventory SET durability = 100 WHERE id = ?", [$item['id']]);
        Database::execute("UPDATE characters SET silver = silver - ? WHERE id = ?", [$cost, $charId]);
        
        return ['success' => true, 'message' => "你花费{$cost}银两修理了{$item['name']}。耐久已恢复至100。"];
    }
}
