<?php
/**
 * Default Action Handler
 * 
 * 默认动作处理器，处理简单的移动和消息显示
 * 适用于不需要复杂逻辑的动作
 */

require_once __DIR__ . '/ActionHandler.php';

class DefaultActionHandler extends ActionHandler {
    
    /**
     * 执行默认动作
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
            
            $actionType = $action['action_type'] ?? 'special';
            $description = $action['description'] ?? '';
            $config = $this->parseConfig($action);
            
            // 根据动作类型处理
            switch ($actionType) {
                case 'movement':
                    return $this->handleMovement($charId, $action, $character, $config);
                
                case 'special':
                    return $this->handleSpecial($charId, $action, $character, $config);
                
                default:
                    return [
                        'success' => false,
                        'message' => '未知的动作类型: ' . $actionType,
                        'data' => null
                    ];
            }
            
        } catch (\Exception $e) {
            error_log("DefaultActionHandler error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '动作执行失败',
                'data' => null
            ];
        }
    }
    
    /**
     * 处理移动动作
     */
    private function handleMovement(int $charId, array $action, array $character, array $config): array {
        $roomId = $character['current_room'];
        $description = $action['description'] ?? '你执行了移动。';
        
        // 检查是否有自定义消息配置
        if (isset($config['show_message']) && $config['show_message']) {
            $message = $config['message'] ?? $description;
            
            // 替换变量（如 $N 替换为角色名）
            $message = str_replace('$N', $character['name'], $message);
            
            // 广播消息到房间
            $this->broadcastToRoom($roomId, $message, $charId);
            
            return [
                'success' => true,
                'message' => $message,
                'data' => ['type' => 'movement_with_message']
            ];
        }
        
        // 默认移动消息
        return [
            'success' => true,
            'message' => $description,
            'data' => ['type' => 'simple_movement']
        ];
    }
    
    /**
     * 处理特殊动作
     */
    private function handleSpecial(int $charId, array $action, array $character, array $config): array {
        $description = $action['description'] ?? '你执行了一个特殊动作。';
        
        // 简单的特殊动作直接返回描述
        return [
            'success' => true,
            'message' => $description,
            'data' => ['type' => 'special_action']
        ];
    }
}

