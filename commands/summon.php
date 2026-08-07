<?php
/**
 * 召唤命令 (summon)
 *
 * 用法: summon <角色名>     -- 将目标玩家召唤到巫师当前所在位置
 *       summon <角色名> <area> <room_id>  -- 将目标玩家送到指定房间
 * 权限: immortal (等级2) 及以上
 */

require_once __DIR__ . '/../helpers/WizardHelper.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/User.php';

/**
 * 执行 summon 命令
 * @param int $charId 操作者角色ID
 * @param string $param 参数: "<角色名>" 或 "<角色名> <area> <room_id>"
 * @return array
 */
function cmd_summon(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    $userId = intval($char['user_id']);
    if (!WizardHelper::canUseCommand($userId, 'summon')) {
        return ['success' => false, 'message' => '你的巫师等级不够，无法使用 summon 命令。需要神仙(immortal)以上权限。'];
    }

    $parts = preg_split('/\s+/', trim($param));
    if (empty($parts[0])) {
        return ['success' => false, 'message' => "用法:\n  summon <角色名>                    -- 召唤到身边\n  summon <角色名> <area> <room_id>   -- 送到指定房间"];
    }

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
        return ['success' => false, 'message' => "找不到角色: {$targetName}"];
    }

    if (!$targetChar['online']) {
        return ['success' => false, 'message' => "{$targetName} 当前不在线，无法召唤。"];
    }

    // 权限检查：可以召唤同级或更低级的巫师，但不能召唤更高级的
    $targetUserId = intval($targetChar['user_id']);
    $operatorLevel = WizardHelper::getWizardLevel($userId);
    $targetLevel = WizardHelper::getWizardLevel($targetUserId);
    if ($operatorLevel < $targetLevel) {
        return ['success' => false, 'message' => '你没有权限召唤该玩家（对方巫师等级高于你）。'];
    }

    $oldRoom = $targetChar['current_room'];

    // 三个参数: summon <角色名> <area> <room_id>
    if (count($parts) >= 3) {
        $destArea = $parts[1];
        $destRoom = $parts[2];
        $room = Database::queryOne("SELECT name FROM rooms WHERE area = ? AND room_id = ?", [$destArea, $destRoom]);
        if (!$room) {
            return ['success' => false, 'message' => "找不到目标房间: {$destArea}/{$destRoom}"];
        }
        CharacterModel::updatePosition($targetChar['id'], $destArea, $destRoom);
        // 通知目标玩家
        $msgSql = "INSERT INTO message_queue (char_id, message, type, from_char_id) VALUES (?, ?, 'private', 0)";
        Database::execute($msgSql, [$targetChar['id'], "[系统] 巫师 {$char['name']} 将你送到了 {$room['name']}。"]);
        log_game('SUMMON', "巫师 {$char['name']} 将 {$targetName} 从 {$oldRoom} 送到 {$destArea}/{$destRoom}");
        return ['success' => true, 'message' => "已将 {$targetName} 送到 {$room['name']} ({$destArea}/{$destRoom})"];
    }

    // 默认: 召唤到巫师所在位置
    $wizArea = $char['current_area'];
    $wizRoom = $char['current_room'];
    $roomInfo = Database::queryOne("SELECT name FROM rooms WHERE area = ? AND room_id = ?", [$wizArea, $wizRoom]);
    $roomName = $roomInfo['name'] ?? $wizRoom;

    CharacterModel::updatePosition($targetChar['id'], $wizArea, $wizRoom);

    // 通知目标玩家
    $msgSql = "INSERT INTO message_queue (char_id, message, type, from_char_id) VALUES (?, ?, 'private', 0)";
    Database::execute($msgSql, [$targetChar['id'], "[系统] 巫师 {$char['name']} 将你召唤到了 {$roomName}。"]);

    log_game('SUMMON', "巫师 {$char['name']} 将 {$targetName} 从 {$oldRoom} 召唤到 {$wizArea}/{$wizRoom}");
    return ['success' => true, 'message' => "已将 {$targetName} 召唤到你的位置: {$roomName} ({$wizArea}/{$wizRoom})"];
}
