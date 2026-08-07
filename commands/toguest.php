<?php
/**
 * toguest 命令 - 欢迎室管理
 * 
 * 用法:
 *   toguest <用户名> [天数] [原因]   - 将玩家送入欢迎室
 *   toguest approve <用户名>          - 批准欢迎室玩家进入正常游戏
 *   toguest list                      - 查看欢迎室玩家列表
 * 
 * 权限: wizard (等级4) 及以上
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../helpers/WizardHelper.php';
require_once __DIR__ . '/../helpers/BanHelper.php';
require_once MODEL_PATH . 'User.php';
require_once MODEL_PATH . 'Character.php';

/**
 * toguest 命令入口
 * @param int $charId 执行者角色ID
 * @param string $param 参数字符串
 * @return array
 */
function cmd_toguest(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    $userId = intval($char['user_id']);
    
    // 权限检查
    if (!WizardHelper::canUseCommand($userId, 'toguest')) {
        return ['success' => false, 'message' => '你的巫师等级不够，无法使用这个命令。'];
    }
    
    $parts = preg_split('/\s+/', trim($param), -1, PREG_SPLIT_NO_EMPTY);
    
    if (empty($parts)) {
        return toguestShowHelp();
    }
    
    $action = $parts[0];
    
    switch ($action) {
        case 'approve':
            return toguestHandleApprove($userId, $parts);
        case 'list':
            return toguestHandleList();
        default:
            // 默认行为: 送入欢迎室
            return toguestHandleSend($userId, $parts);
    }
}

/**
 * 显示帮助
 */
function toguestShowHelp(): array {
    $help = "欢迎室管理命令用法:\n";
    $help .= "  toguest <用户名> [天数] [原因]   - 将玩家送入欢迎室\n";
    $help .= "  toguest approve <用户名>          - 批准玩家进入正常游戏\n";
    $help .= "  toguest list                      - 查看欢迎室玩家列表\n";
    return ['success' => true, 'message' => $help];
}

/**
 * 将玩家送入欢迎室
 * 参数: username [days] [reason_words...]
 */
function toguestHandleSend(int $userId, array $parts): array {
    if (count($parts) < 1) {
        return ['success' => false, 'message' => '用法: toguest <用户名> [天数] [原因]'];
    }
    
    $username = $parts[0];
    $days = 2; // 默认2天
    $reason = '等待审核';
    
    // 解析可选参数: 数字当天数，其余文字当原因
    $reasonParts = [];
    for ($i = 1; $i < count($parts); $i++) {
        if (is_numeric($parts[$i]) && $days === 2) {
            $days = intval($parts[$i]);
        } else {
            $reasonParts[] = $parts[$i];
        }
    }
    if (!empty($reasonParts)) {
        $reason = implode(' ', $reasonParts);
    }
    
    // 天数限制
    if ($days < 1) $days = 1;
    if ($days > 30) $days = 30;
    
    // 查找目标用户
    $targetUser = UserModel::findByUsername($username);
    if (!$targetUser) {
        return ['success' => false, 'message' => "找不到用户: {$username}"];
    }
    
    // 检查是否可以操作目标用户
    if (!WizardHelper::canOperateOn($userId, $targetUser['id'])) {
        return ['success' => false, 'message' => '你没有权限操作这个用户。'];
    }
    
    // 获取目标角色
    $char = CharacterModel::getByUserId($targetUser['id']);
    if (!$char) {
        return ['success' => false, 'message' => "用户 {$username} 没有在线角色。"];
    }
    
    // 计算释放时间
    $releaseTime = date('Y-m-d H:i:s', time() + ($days * 86400));
    
    // 保存原位置信息
    $originalRoom = $char['current_room'];
    $originalArea = $char['current_area'];
    
    // 更新角色位置到欢迎室
    CharacterModel::updatePosition($char['id'], 'wiz', 'wiz/guest');
    
    // 记录到欢迎室配置表
    $sql = "INSERT INTO guest_room_config (user_id, char_id, days, reason, status) VALUES (?, ?, ?, ?, 1)";
    Database::execute($sql, [$targetUser['id'], $char['id'], $days, $reason]);
    
    // 发送消息
    $msgSql = "INSERT INTO message_queue (char_id, message, type, from_char_id) VALUES (?, ?, 'private', 0)";
    Database::execute($msgSql, [$char['id'], "[系统] 你已被送入欢迎室！原因: {$reason}。等待 {$days} 天后释放。"]);
    
    // 设置目标用户的session标记（如果在线）
    if (!empty($targetUser['session_id'])) {
        $currentSession = session_id();
        session_write_close();
        session_id($targetUser['session_id']);
        session_start();
        $_SESSION['in_guest_room'] = true;
        $_SESSION['guest_room_until'] = $releaseTime;
        $_SESSION['guest_room_original_room'] = $originalRoom;
        $_SESSION['guest_room_original_area'] = $originalArea;
        session_write_close();
        // 恢复当前session
        session_id($currentSession);
        session_start();
    }
    
    $operatorUser = UserModel::find($userId);
    $operatorName = $operatorUser['username'] ?? '巫师';
    log_game('TOGUEST', "巫师 {$operatorName} 将 {$username} 送入欢迎室，{$days}天，原因: {$reason}");
    
    return [
        'success' => true, 
        'message' => "已将 {$username} 送入欢迎室，等待 {$days} 天后释放。原因: {$reason}"
    ];
}

/**
 * 批准欢迎室玩家进入正常游戏
 * 参数: approve username
 */
function toguestHandleApprove(int $userId, array $parts): array {
    if (count($parts) < 2) {
        return ['success' => false, 'message' => '用法: toguest approve <用户名>'];
    }
    
    $username = $parts[1];
    
    // 查找目标用户
    $targetUser = UserModel::findByUsername($username);
    if (!$targetUser) {
        return ['success' => false, 'message' => "找不到用户: {$username}"];
    }
    
    // 更新欢迎室配置状态
    $sql = "UPDATE guest_room_config SET status = 2, release_time = NOW(), release_by = ? WHERE user_id = ? AND status = 1";
    $affected = Database::execute($sql, [$userId, $targetUser['id']]);
    
    // 获取角色信息
    $char = CharacterModel::getByUserId($targetUser['id']);
    if ($char) {
        CharacterModel::updatePosition($char['id'], 'city', 'city/kezhan');
        sendGuestMessage($char['id'], '[系统] 你已被批准进入正常游戏，已移回客栈。');
    }
    
    if ($affected > 0 || $char) {
        return ['success' => true, 'message' => "已释放 {$username}，允许其进入正常游戏。"];
    } else {
        return ['success' => false, 'message' => "找不到用户 {$username} 的欢迎室记录或角色信息。"];
    }
}

/**
 * 查看欢迎室玩家列表
 */
function toguestHandleList(): array {
    $players = getGuestRoomPlayers();
    
    $message = "【欢迎室玩家列表】\n";
    if (empty($players)) {
        $message .= "  无\n";
    } else {
        foreach ($players as $p) {
            $username = $p['username'] ?? '未知';
            $charName = $p['char_name'] ?? '未知';
            $days = $p['days'] ?? 0;
            $reason = $p['reason'] ?? '无';
            $enterTime = $p['enter_time'] ?? '未知';
            $message .= "  {$username} ({$charName}) - {$days}天 - 原因: {$reason} - 进入时间: {$enterTime}\n";
        }
    }
    
    return ['success' => true, 'message' => $message];
}

/**
 * 发送欢迎室消息
 */
function sendGuestMessage(int $charId, string $message): void {
    $sql = "INSERT INTO message_queue (char_id, message, type, from_char_id) VALUES (?, ?, 'private', 0)";
    Database::execute($sql, [$charId, $message]);
}

/**
 * 检查玩家是否在欢迎室
 * @param int $userId 用户ID
 * @return bool
 */
function isInGuestRoom(int $userId): bool {
    $sql = "SELECT id FROM guest_room_config WHERE user_id = ? AND status = 1";
    $result = Database::queryOne($sql, [$userId]);
    if ($result) {
        return true;
    }
    
    $sql2 = "SELECT id FROM characters WHERE user_id = ? AND current_room LIKE '%guest%'";
    $result2 = Database::queryOne($sql2, [$userId]);
    if ($result2) {
        return true;
    }
    
    return false;
}

/**
 * 获取欢迎室中的玩家列表
 * @return array
 */
function getGuestRoomPlayers(): array {
    $players = [];
    
    $sql = "SELECT g.*, u.username, c.name as char_name 
            FROM guest_room_config g 
            LEFT JOIN users u ON g.user_id = u.id 
            LEFT JOIN characters c ON g.char_id = c.id 
            WHERE g.status = 1 
            ORDER BY g.enter_time DESC";
    $fromConfig = Database::queryAll($sql) ?: [];
    
    $sql2 = "SELECT c.id as char_id, c.user_id, c.name as char_name, c.current_room, c.current_area,
                    u.username, NOW() as enter_time
             FROM characters c 
             LEFT JOIN users u ON c.user_id = u.id 
             WHERE c.current_room LIKE '%guest%'
               AND c.online = 1";
    $fromChars = Database::queryAll($sql2) ?: [];
    
    $existingUserIds = [];
    foreach ($fromConfig as $p) {
        $players[] = $p;
        $existingUserIds[] = $p['user_id'];
    }
    
    foreach ($fromChars as $p) {
        if (!in_array($p['user_id'], $existingUserIds)) {
            $p['id'] = $p['char_id'];
            $p['days'] = 0;
            $p['reason'] = '从角色位置检测';
            $p['status'] = 1;
            $players[] = $p;
        }
    }
    
    return $players;
}
