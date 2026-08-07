<?php
/**
 * 敲墙命令 - 用于雪山迷宫的敲墙动作
 * 格式：qiang
 */

if (!defined('IN_GAME')) {
    die('Access Denied');
}

require_once __DIR__ . '/../daemons/SnowMazeHandler.php';
require_once __DIR__ . '/../models/Character.php';

function cmd_qiang(int $charId, string $param = ''): array {
    // 获取角色信息
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 检查当前房间是否为雪山迷宫房间
    if (SnowMazeHandler::isMazeRoom($char['current_room'])) {
        // 调用雪山迷宫处理器的敲墙逻辑
        $result = SnowMazeHandler::handleKnockWall($charId, $char['current_room']);
        
        if ($result['success']) {
            return [
                'success' => true,
                'message' => $result['message'],
                'redirect' => $result['redirect'] ?? null
            ];
        } else {
            return [
                'success' => false,
                'message' => $result['message']
            ];
        }
    } else {
        return [
            'success' => false,
            'message' => "这里没有墙可以敲。\n"
        ];
    }
}