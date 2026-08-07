<?php
/**
 * 传送命令 (goto)
 *
 * 用法: goto <area> <room_id>     -- 传送到指定房间
 *       goto <角色名>             -- 传送到指定玩家所在位置
 * 权限: elder (等级1) 及以上
 */

require_once __DIR__ . '/../helpers/WizardHelper.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/User.php';

/**
 * 执行 goto 命令
 * @param int $charId 操作者角色ID
 * @param string $param 参数: "<area> <room_id>" 或 "<角色名>"
 * @return array
 */
function cmd_goto(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    $userId = intval($char['user_id']);
    if (!WizardHelper::canUseCommand($userId, 'goto')) {
        return ['success' => false, 'message' => '你的巫师等级不够，无法使用 goto 命令。需要长老(elder)以上权限。'];
    }

    $parts = preg_split('/\s+/', trim($param));
    if (empty($parts[0])) {
        return ['success' => false, 'message' => "用法:\n  goto <area> <room_id>  -- 传送到指定房间\n  goto <角色名>          -- 传送到玩家身边"];
    }

    // 两个参数: goto <area> <room_id>
    if (count($parts) >= 2) {
        $targetArea = $parts[0];
        $targetRoom = $parts[1];

        // 验证房间存在
        $room = Database::queryOne(
            "SELECT id, name FROM rooms WHERE area = ? AND room_id = ?",
            [$targetArea, $targetRoom]
        );
        if (!$room) {
            return ['success' => false, 'message' => "找不到房间: {$targetArea}/{$targetRoom}"];
        }

        $oldRoom = $char['current_room'];
        CharacterModel::updatePosition($charId, $targetArea, $targetRoom);
        log_game('GOTO', "巫师 {$char['name']} 从 {$oldRoom} 传送到 {$targetArea}/{$targetRoom}");
        return ['success' => true, 'message' => "已传送到 {$room['name']} ({$targetArea}/{$targetRoom})"];
    }

    // 一个参数: goto <角色名>
    $targetName = $parts[0];
    $targetChar = CharacterModel::findByName($targetName);

    // 角色名找不到时，尝试按用户名查找
    if (!$targetChar) {
        $targetUser = UserModel::findByUsername($targetName);
        if ($targetUser) {
            $targetChar = CharacterModel::getByUserId($targetUser['id']);
        }
    }

    if (!$targetChar) {
        // 尝试当房间ID查找
        $room = Database::queryOne("SELECT id, name, area, room_id FROM rooms WHERE room_id = ?", [$targetName]);
        if ($room) {
            $oldRoom = $char['current_room'];
            CharacterModel::updatePosition($charId, $room['area'], $room['room_id']);
            log_game('GOTO', "巫师 {$char['name']} 传送到房间 {$room['name']}");
            return ['success' => true, 'message' => "已传送到 {$room['name']} ({$room['area']}/{$room['room_id']})"];
        }
        return ['success' => false, 'message' => "找不到角色或房间: {$targetName}"];
    }

    if (!$targetChar['online']) {
        return ['success' => false, 'message' => "{$targetName} 当前不在线。"];
    }

    $targetArea = $targetChar['current_area'];
    $targetRoom = $targetChar['current_room'];
    $roomInfo = Database::queryOne("SELECT name FROM rooms WHERE area = ? AND room_id = ?", [$targetArea, $targetRoom]);
    $roomName = $roomInfo['name'] ?? $targetRoom;

    CharacterModel::updatePosition($charId, $targetArea, $targetRoom);
    log_game('GOTO', "巫师 {$char['name']} 传送到 {$targetName} 所在位置 {$targetArea}/{$targetRoom}");
    return ['success' => true, 'message' => "已传送到 {$targetName} 所在位置: {$roomName} ({$targetArea}/{$targetRoom})"];
}
