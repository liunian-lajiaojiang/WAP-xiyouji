<?php
/**
 * 雪山跳石壁动作处理器
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 动作逻辑：
 * - 在雪山迷宫房间中可以使用jump命令跳石壁
 * - 跳石壁会传送到荷塘中(inpool)，重置迷宫进度
 */

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/MessageDaemon.php';

class XueshanJumpHandler extends ActionHandler {
    /**
     * 执行跳石壁动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在', 'data' => null];
            }
            
            // 检查是否在雪山迷宫房间
            require_once __DIR__ . '/SnowMazeHandler.php';
            if (!SnowMazeHandler::isMazeRoom($character['current_room'])) {
                return ['success' => false, 'message' => '这里不能跳石壁', 'data' => null];
            }
            
            // 调用雪山迷宫处理器的跳桥逻辑
            $result = SnowMazeHandler::handleJumpBridge($charId, $character['current_room']);
            
            return [
                'success' => $result['success'],
                'message' => $result['message'] ?? '跳石壁动作执行失败',
                'data' => [
                    'redirect' => $result['redirect'] ?? null
                ]
            ];
            
        } catch (\Exception $e) {
            error_log("XueshanJumpHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '跳石壁动作执行失败', 'data' => null];
        }
    }
}