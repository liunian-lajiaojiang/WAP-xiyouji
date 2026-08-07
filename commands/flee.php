<?php
/**
 * 逃跑命令 (flee)
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
require_once DAEMON_PATH . 'CombatDaemon.php';

function cmd_flee(int $charId, string $param = ''): array {
    $me = CharacterModel::find($charId);
    
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 调用CombatDaemon执行逃跑
    $result = CombatDaemon::flee($charId);
    
    if (!$result['success']) {
        return $result;
    }
    
    // 传递所有字段，包括leave_message/arrive_message供action.php广播
    return [
        'success' => true,
        'type' => $result['type'] ?? ($result['fled'] ? 'flee_success' : 'flee_failed'),
        'output' => $result['message'],
        'fled' => $result['fled'] ?? false,
        'new_room_id' => $result['new_room_id'] ?? null,
        'direction' => $result['direction'] ?? null,
        'old_room' => $result['old_room'] ?? null,
        'new_room' => $result['new_room'] ?? null,
        'leave_message' => $result['leave_message'] ?? null,
        'arrive_message' => $result['arrive_message'] ?? null
    ];
}

