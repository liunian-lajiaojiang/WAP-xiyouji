<?php
/**
 * 雪山特殊动作处理器
 * 处理滑冰、炼制等雪山区域的特殊动作
 */

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/MessageDaemon.php';

class XueshanActionsHandler extends ActionHandler {
    
    /**
     * 执行雪山特殊动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在', 'data' => null];
            }
            
            // 获取动作类型
            $actionType = $action['action_type'] ?? '';
            
            switch ($actionType) {
                case 'skate':
                    return $this->handleSkate($charId, $character, $action);
                case 'craft':
                    return $this->handleCraft($charId, $character, $action);
                case 'jump':
                    return $this->handleJump($charId, $character, $action);
                case 'knock':
                    return $this->handleKnock($charId, $character, $action);
                default:
                    return ['success' => false, 'message' => '未知的雪山动作', 'data' => null];
            }
            
        } catch (\Exception $e) {
            error_log("XueshanActionsHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '雪山动作执行失败', 'data' => null];
        }
    }
    
    /**
     * 处理滑冰动作
     */
    private function handleSkate(int $charId, array $character, array $action): array {
        require_once __DIR__ . '/XueshanSkateHandler.php';
        $handler = new XueshanSkateHandler();
        return $handler->execute($charId, $action, []);
    }
    
    /**
     * 处理炼制动作
     */
    private function handleCraft(int $charId, array $character, array $action): array {
        require_once __DIR__ . '/XueshanCraftingHandler.php';
        $handler = new XueshanCraftingHandler();
        return $handler->execute($charId, $action, []);
    }
    
    /**
     * 处理跳石壁动作
     */
    private function handleJump(int $charId, array $character, array $action): array {
        require_once __DIR__ . '/XueshanJumpHandler.php';
        $handler = new XueshanJumpHandler();
        return $handler->execute($charId, $action, []);
    }
    
    /**
     * 处理敲墙动作
     */
    private function handleKnock(int $charId, array $character, array $action): array {
        require_once __DIR__ . '/SnowMazeHandler.php';
        return SnowMazeHandler::handleKnockWall($charId, $character['current_room']);
    }
}