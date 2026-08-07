<?php
/**
 * Movement Action Handler
 * 
 * 特殊移动动作处理器
 * 处理跳墙等需要条件检查的移动动作
 * 通过 config JSON 配置目标房间和路由信息
 * 
 * 从 ActionRouter::handleLegacyAction 的 tiaqiang 分支迁移
 */

require_once __DIR__ . '/ActionHandler.php';

class MovementActionHandler extends ActionHandler {
    
    /**
     * 执行特殊移动动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在', 'data' => null];
            }
            
            // 解析配置
            $config = $this->parseConfig($action);
            $routes = $config['routes'] ?? [];
            $defaultFailMessage = $config['default_fail_message'] ?? '这里没有可以移动的地方。';
            
            // 获取当前房间
            $currentRoom = $character['current_room'];
            
            // 查找匹配的路由
            $matchedRoute = null;
            foreach ($routes as $route) {
                if ($route['from_room'] === $currentRoom) {
                    $matchedRoute = $route;
                    break;
                }
            }
            
            if (!$matchedRoute) {
                return ['success' => false, 'message' => $defaultFailMessage];
            }
            
            $toArea = $matchedRoute['to_area'];
            $toRoom = $matchedRoute['to_room'];
            $selfMessage = $matchedRoute['self_message'] ?? '你移动到了新的地方。';
            $broadcastTemplate = $matchedRoute['broadcast_message_template'] ?? '';
            
            // 更新角色位置
            require_once __DIR__ . '/../includes/db.php';
            Database::execute(
                'UPDATE characters SET current_area = ?, current_room = ? WHERE id = ?',
                [$toArea, $toRoom, $charId]
            );
            
            // 广播消息到离开的房间
            if (!empty($broadcastTemplate)) {
                $broadcastMessage = HIY . str_replace('{name}', $character['name'], $broadcastTemplate) . NOR;
                $this->broadcastToRoom($currentRoom, $broadcastMessage, intval($charId));
            }
            
            // 生成重定向URL
            $redirectUrl = room_url($toArea, $toRoom);
            
            return [
                'success' => true,
                'message' => $selfMessage,
                'redirect' => $redirectUrl
            ];
            
        } catch (\Exception $e) {
            error_log("MovementActionHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '移动动作执行失败', 'data' => null];
        }
    }
}

