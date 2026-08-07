<?php
/**
 * 南海木筏处理器
 * 
 * 实现南海小岛 ↔ 南海之滨的木筏传送：
 * - nanhai/island（小岛）：zuo mufa → 划到南海之滨
 * - changan/southseashore（南海之滨）：zuo mufa → 划到小岛
 * 
 * 简化版：即时传送（不需要东海木筏那样的循环状态机）
 */

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/MessageDaemon.php';

class NanhaiMufaHandler extends ActionHandler {
    
    /**
     * 执行木筏动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在', 'data' => null];
            }
            
            $config = $this->parseConfig($action);
            $currentRoom = $character['current_room'];
            
            // 根据当前房间决定目标
            if ($currentRoom === 'nanhai/island') {
                return $this->handleBoardFromIsland($charId, $character, $config);
            } elseif ($currentRoom === 'changan/southseashore') {
                return $this->handleBoardFromSouthseashore($charId, $character, $config);
            }
            
            return ['success' => false, 'message' => '这里没有木筏可以乘坐。'];
            
        } catch (\Exception $e) {
            error_log("NanhaiMufaHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '木筏功能执行失败', 'data' => null];
        }
    }
    
    /**
     * 从小岛上木筏 → 划到南海之滨
     */
    private function handleBoardFromIsland(int $charId, array $character, array $config): array {
        $successSelfMessage = $config['success_self_message'] ?? '你跳上木筏，奋力向大陆划去。';
        
        // 广播消息到小岛
        $broadcastTemplate = $config['success_broadcast_template'] ?? '{name}跳上木筏，向大陆划去。';
        $broadcastMessage = str_replace('{name}', $character['name'], $broadcastTemplate);
        $broadcastMessage = (defined('HIY') ? HIY : '') . $broadcastMessage . (defined('NOR') ? NOR : '');
        MessageDaemon::broadcastToRoom('nanhai/island', $broadcastMessage, intval($charId));
        
        // 移动到南海之滨
        Database::execute(
            'UPDATE characters SET current_area = ?, current_room = ? WHERE id = ?',
            ['changan', 'changan/southseashore', $charId]
        );
        
        // 广播到达消息到南海之滨
        $arriveTemplate = $config['arrive_broadcast_template'] ?? '{name}乘着木筏从小岛方向划了过来。';
        $arriveMessage = str_replace('{name}', $character['name'], $arriveTemplate);
        $arriveMessage = (defined('HIY') ? HIY : '') . $arriveMessage . (defined('NOR') ? NOR : '');
        MessageDaemon::broadcastToRoom('changan/southseashore', $arriveMessage, intval($charId));
        
        // 扣减气力（划船消耗）
        $keeCost = 10;
        $senCost = 10;
        Database::execute(
            'UPDATE characters SET kee = GREATEST(0, kee - ?), sen = GREATEST(0, sen - ?) WHERE id = ?',
            [$keeCost, $senCost, $charId]
        );
        
        $redirectUrl = room_url('changan', 'changan/southseashore');
        
        return [
            'success' => true,
            'message' => $successSelfMessage . "\n\n你划了半天，终于到了大陆岸边。",
            'redirect' => $redirectUrl
        ];
    }
    
    /**
     * 从南海之滨上木筏 → 划到小岛
     */
    private function handleBoardFromSouthseashore(int $charId, array $character, array $config): array {
        $successSelfMessage = $config['success_self_message'] ?? '你跳上木筏，向海中孤岛划去。';
        
        // 广播消息到南海之滨
        $broadcastTemplate = $config['success_broadcast_template'] ?? '{name}跳上木筏，向海中孤岛划去。';
        $broadcastMessage = str_replace('{name}', $character['name'], $broadcastTemplate);
        $broadcastMessage = (defined('HIY') ? HIY : '') . $broadcastMessage . (defined('NOR') ? NOR : '');
        MessageDaemon::broadcastToRoom('changan/southseashore', $broadcastMessage, intval($charId));
        
        // 移动到小岛
        Database::execute(
            'UPDATE characters SET current_area = ?, current_room = ? WHERE id = ?',
            ['nanhai', 'nanhai/island', $charId]
        );
        
        // 广播到达消息到小岛
        $arriveTemplate = $config['arrive_broadcast_template'] ?? '{name}乘着木筏从大陆方向划了过来。';
        $arriveMessage = str_replace('{name}', $character['name'], $arriveTemplate);
        $arriveMessage = (defined('HIY') ? HIY : '') . $arriveMessage . (defined('NOR') ? NOR : '');
        MessageDaemon::broadcastToRoom('nanhai/island', $arriveMessage, intval($charId));
        
        // 扣减气力（划船消耗）
        $keeCost = 10;
        $senCost = 10;
        Database::execute(
            'UPDATE characters SET kee = GREATEST(0, kee - ?), sen = GREATEST(0, sen - ?) WHERE id = ?',
            [$keeCost, $senCost, $charId]
        );
        
        $redirectUrl = room_url('nanhai', 'nanhai/island');
        
        return [
            'success' => true,
            'message' => $successSelfMessage . "\n\n你划了半天，终于到达了南海小岛。",
            'redirect' => $redirectUrl
        ];
    }
    
}
