<?php
/**
 * 广羲子助手类
 * 处理广羲子的借书、还书、松果等特殊逻辑
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */

class GuangxiHelper {
    
    /**
     * 处理给广羲子物品的逻辑
     * 
     * @param int $charId 玩家ID
     * @param array $npc NPC信息
     * @param array $item 物品信息
     * @param int $quantity 数量
     * @return array 处理结果
     */
    public static function handleGive(int $charId, array $npc, array $item, int $quantity = 1): array {
        $itemId = $item['item_id'] ?? '';
        $itemName = $item['name'] ?? '物品';
        $itemCategory = $item['category'] ?? '';
        $npcId = intval($npc['id'] ?? 0);
        $npcName = $npc['name'] ?? '广羲子';
        
        // 检查玩家是否有未还的书
        $pendingBook = Database::queryOne(
            'SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?',
            [$charId, 'pending/book']
        );
        $hasPendingBook = !empty($pendingBook) && !empty($pendingBook['state_value']);
        $pendingBookId = $pendingBook['state_value'] ?? '';
        
        // 处理不同物品
        switch ($itemId) {
            case 'qian':
            case 'zhu-qian-wen':
                return self::handleReturnBook($charId, $npcId, $npcName, $itemId, $itemName, $itemCategory, $quantity, '千字文', 'qian', $hasPendingBook, $pendingBookId);
                
            case 'daode':
            case 'daodejing':
                return self::handleReturnBook($charId, $npcId, $npcName, $itemId, $itemName, $itemCategory, $quantity, '道德经', 'daode', $hasPendingBook, $pendingBookId);
                
            case 'songguo':
            case 'guo':
                return self::handleSongguo($charId, $npcId, $npcName, $itemId, $itemName, $itemCategory, $quantity, $hasPendingBook);
                
            default:
                return [
                    'success' => false,
                    'message' => "{$npcName}不要你的{$itemName}。",
                    'consume_item' => false
                ];
        }
    }
    
    /**
     * 处理还书
     */
    private static function handleReturnBook(
        int $charId, 
        int $npcId, 
        string $npcName, 
        string $itemId, 
        string $itemName, 
        string $itemCategory, 
        int $quantity,
        string $bookDisplayName,
        string $bookKey,
        bool $hasPendingBook,
        string $pendingBookId
    ): array {
        // 扣除物品
        self::removeItem($charId, $itemId, $itemCategory, $quantity);
        
        // 如果有未还的书，且还的是对应的书
        if ($hasPendingBook && $pendingBookId === $bookKey) {
            // 清除玩家借书标记
            Database::execute(
                'DELETE FROM character_temp_states WHERE char_id = ? AND state_key = ?',
                [$charId, 'pending/book']
            );
            
            // 清除NPC借书标记
            $npcBookStateKey = "npc_book_{$bookKey}";
            Database::execute(
                'DELETE FROM character_temp_states WHERE char_id = ? AND state_key = ?',
                [$npcId, $npcBookStateKey]
            );
            
            return [
                'success' => true,
                'message' => "{$npcName}哈哈笑了几声，好借好还，再借不难！",
                'consume_item' => true
            ];
        } else {
            // 没有借书记录，单纯收下书
            return [
                'success' => true,
                'message' => "{$npcName}笑道：多谢，多谢！",
                'consume_item' => true
            ];
        }
    }
    
    /**
     * 处理松果
     */
    private static function handleSongguo(
        int $charId,
        int $npcId,
        string $npcName,
        string $itemId,
        string $itemName,
        string $itemCategory,
        int $quantity,
        bool $hasPendingBook
    ): array {
        // 扣除物品
        self::removeItem($charId, $itemId, $itemCategory, $quantity);
        
        // 松果的特殊效果：清除借书标记
        if ($hasPendingBook) {
            // 获取借的是什么书
            $pendingBook = Database::queryOne(
                'SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?',
                [$charId, 'pending/book']
            );
            $bookKey = $pendingBook['state_value'] ?? '';
            
            // 清除玩家借书标记
            Database::execute(
                'DELETE FROM character_temp_states WHERE char_id = ? AND state_key = ?',
                [$charId, 'pending/book']
            );
            
            // 清除NPC借书标记
            if (!empty($bookKey)) {
                $npcBookStateKey = "npc_book_{$bookKey}";
                Database::execute(
                    'DELETE FROM character_temp_states WHERE char_id = ? AND state_key = ?',
                    [$npcId, $npcBookStateKey]
                );
            }
        }
        
        return [
            'success' => true,
            'message' => "{$npcName}笑道：多谢，多谢！我最爱吃了！",
            'consume_item' => true
        ];
    }
    
    /**
     * 从玩家背包扣除物品
     */
    private static function removeItem(int $charId, string $itemId, string $category, int $quantity): void {
        $removeQty = min($quantity, 1); // 一次只收一个
        
        if (!empty($category)) {
            Database::execute(
                'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND category = ?',
                [$removeQty, $charId, $itemId, $category]
            );
            Database::execute(
                'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ? AND quantity <= 0',
                [$charId, $itemId, $category]
            );
        } else {
            Database::execute(
                'UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\')',
                [$removeQty, $charId, $itemId]
            );
            Database::execute(
                'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND (category IS NULL OR category = \'\') AND quantity <= 0',
                [$charId, $itemId]
            );
        }
    }
}
