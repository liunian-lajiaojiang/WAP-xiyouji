<?php
/**
 * Dig Handler
 * 
 * 采集动作处理器
 * 处理挖蚯蚓、采药等采集类动作
 */

require_once __DIR__ . '/ActionHandler.php';

class DigHandler extends ActionHandler {
    
    /**
     * 执行采集动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            
            if (!$character) {
                return [
                    'success' => false,
                    'message' => '角色不存在',
                    'data' => null
                ];
            }
            
            // 解析配置
            $config = $this->parseConfig($action);
            
            // 1. 验证参数
            $arg = $params['arg'] ?? '';
            // 注意：如果 action_cmd 已经包含参数（如 "wa qiuyin"），则不需要额外验证
            // 这里简化处理，不强制要求参数
            
            // 2. 检查工具（如果需要）
            if (isset($config['tool_required']) && $config['tool_required']) {
                $hasTool = $this->checkTool($character, $config['tool_types'] ?? []);
                if (!$hasTool) {
                    return [
                        'success' => false,
                        'message' => '找个好锋利的家伙来挖吧。',
                        'data' => null
                    ];
                }
            }
            
            // 3. 消耗体力
            $costKee = $config['cost_kee'] ?? 50;
            if ($character['kee'] < $costKee) {
                return [
                    'success' => false,
                    'message' => '你太累了，先歇会儿再挖吧。',
                    'data' => null
                ];
            }
            
            // 扣除体力
            $this->deductKee($charId, $costKee);
            
            // 4. 随机掉落逻辑
            $dropRate = $config['drop_rate'] ?? 0.3;
            $maxResource = $config['max_resource'] ?? 10;
            
            // 模拟随机（实际应该从数据库读取当前资源量）
            $currentResource = $this->getCurrentResource($action['room_id'] ?? '');
            
            if (rand(1, 100) > ($dropRate * 100) || $currentResource <= 0) {
                return [
                    'success' => true,
                    'message' => '你在泥巴中翻来翻去，结果什么也没找到。',
                    'data' => ['type' => 'dig_failed']
                ];
            } else {
                // 成功采集
                $rewardItem = $config['reward_item'] ?? '';
                $rewardCategory = $config['reward_category'] ?? '';
                
                // 给予物品到玩家背包
                $this->giveItem($charId, $rewardItem, $rewardCategory);
                
                // 减少资源并设置再生
                $this->decreaseResource($action['room_id'] ?? '', 1);
                
                // 获取物品名称
                $itemName = $this->getItemName($rewardItem);
                $message = "你从泥巴里找到了一只{$itemName}！";
                
                return [
                    'success' => true,
                    'message' => $message,
                    'data' => [
                        'type' => 'dig_success',
                        'item' => $rewardItem,
                        'item_name' => $itemName
                    ]
                ];
            }
            
        } catch (\Exception $e) {
            error_log("DigHandler error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '采集动作执行失败',
                'data' => null
            ];
        }
    }
    
    /**
     * 检查玩家是否有合适的工具
     */
    private function checkTool(array $character, array $toolTypes): bool {
        // TODO: 检查玩家的武器或背包中是否有指定类型的工具
        // 这里简化处理，假设玩家有工具
        return true;
    }
    
    /**
     * 给予物品到玩家背包
     */
    private function giveItem(int $charId, string $itemId, string $category = ''): void {
        require_once __DIR__ . '/../models/Item.php';
        ItemModel::addToInventory($charId, $itemId, 1, $category);
    }
    
    /**
     * 扣除体力
     */
    private function deductKee(int $charId, int $amount): void {
        require_once __DIR__ . '/../includes/db.php';
        Database::execute(
            'UPDATE characters SET kee = kee - ? WHERE id = ?',
            [$amount, $charId]
        );
    }
    
    /**
     * 获取当前资源量
     */
    private function getCurrentResource(string $roomId): int {
        require_once __DIR__ . '/../includes/db.php';
        
        // 从 room_resources 表读取资源量
        $result = Database::queryOne(
            'SELECT resource_count FROM room_resources WHERE room_id = ? AND is_active = 1',
            [$roomId]
        );
        
        // 如果没有记录，返回默认值
        return $result ? intval($result['resource_count']) : 10;
    }
    
    /**
     * 减少资源量
     */
    private function decreaseResource(string $roomId, int $amount): void {
        require_once __DIR__ . '/../includes/db.php';
        
        // 减少资源量
        Database::execute(
            'UPDATE room_resources SET resource_count = resource_count - ?, last_harvest_time = NOW() WHERE room_id = ?',
            [$amount, $roomId]
        );
        
        // 如果资源耗尽，设置再生时间
        $currentResource = $this->getCurrentResource($roomId);
        if ($currentResource <= 0) {
            $this->scheduleRegeneration($roomId);
        }
    }
    
    /**
     * 安排资源再生
     */
    private function scheduleRegeneration(string $roomId): void {
        require_once __DIR__ . '/../includes/db.php';
        
        // 设置再生时间和目标数量
        Database::execute(
            'UPDATE room_resources SET regenerate_time = DATE_ADD(NOW(), INTERVAL 300 SECOND), target_count = 10 WHERE room_id = ?',
            [$roomId]
        );
        
        // TODO: 可以添加定时任务来实际执行再生
        // 或者使用 call_out 机制
    }
    
    /**
     * 获取物品名称
     */
    private function getItemName(string $itemId): string {
        require_once __DIR__ . '/../includes/db.php';
        
        // 从物品表中查询名称
        $item = Database::queryOne(
            'SELECT name FROM items WHERE item_id = ?',
            [$itemId]
        );
        
        return $item ? $item['name'] : '物品';
    }
}

