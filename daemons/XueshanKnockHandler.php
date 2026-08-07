<?php
/**
 * 雪山敲墙开通道处理器
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 动作逻辑：
 * - 在雪山迷宫房间中可以使用qiang命令敲墙
 * - 有概率打开暗门传送到未访问的房间
 */

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/MessageDaemon.php';

class XueshanKnockHandler extends ActionHandler {
    /**
     * 执行敲墙动作
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
                return ['success' => false, 'message' => '这里没有墙可以敲', 'data' => null];
            }
            
            // 调用雪山迷宫处理器的敲墙逻辑
            $result = SnowMazeHandler::handleKnockWall($charId, $character['current_room']);
            
            return [
                'success' => $result['success'],
                'message' => $result['message'] ?? '敲墙动作执行失败',
                'data' => [
                    'redirect' => $result['redirect'] ?? null
                ]
            ];
            
        } catch (\Exception $e) {
            error_log("XueshanKnockHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '敲墙动作执行失败', 'data' => null];
        }
    }
}