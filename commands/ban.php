<?php
/**
 * 管理员封禁命令
 * 用于封禁/解封用户和IP
 */
require_once __DIR__ . '/../helpers/BanHelper.php';
require_once __DIR__ . '/../helpers/WizardHelper.php';

/**
 * 封禁命令入口
 * @param int $charId 角色ID
 * @param string $param 参数
 * @return array
 */
function cmd_ban(int $charId, string $param = ''): array {
    // 检查权限
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    $user = UserModel::find($char['user_id']);
    if (!$user || !WizardHelper::canUseCommand($user['id'], 'ban')) {
        return ['success' => false, 'message' => '你没有权限执行此命令'];
    }
    
    $params = explode(' ', trim($param));
    $action = $params[0] ?? '';
    
    switch ($action) {
        case 'ip':
            return handleBanIp($params);
        case 'unbanip':
            return handleUnbanIp($params);
        case 'user':
            return handleBanUser($params);
        case 'unban':
            return handleUnbanUser($params);
        case 'imprison':
            return handleImprison($params);
        case 'release':
            return handleRelease($params);
        case 'list':
            return handleList();
        default:
            return showHelp();
    }
}

/**
 * 封禁IP
 */
function handleBanIp(array $params): array {
    if (count($params) < 2) {
        return ['success' => false, 'message' => '用法: ban ip <IP模式> [原因]'];
    }
    
    $ipPattern = $params[1];
    $reason = implode(' ', array_slice($params, 2)) ?: '违规操作';
    
    if (BanHelper::banIp($ipPattern, $reason)) {
        return ['success' => true, 'message' => "已封禁IP: {$ipPattern}"];
    }
    
    return ['success' => false, 'message' => '封禁失败'];
}

/**
 * 解封IP
 */
function handleUnbanIp(array $params): array {
    if (count($params) < 2) {
        return ['success' => false, 'message' => '用法: ban unbanip <IP模式>'];
    }
    
    $ipPattern = $params[1];
    
    if (BanHelper::unbanIp($ipPattern)) {
        return ['success' => true, 'message' => "已解封IP: {$ipPattern}"];
    }
    
    return ['success' => false, 'message' => '解封失败'];
}

/**
 * 封禁用户
 */
function handleBanUser(array $params): array {
    if (count($params) < 2) {
        return ['success' => false, 'message' => '用法: ban user <用户名>'];
    }
    
    $username = $params[1];
    $user = UserModel::findByUsername($username);
    
    if (!$user) {
        return ['success' => false, 'message' => "用户不存在: {$username}"];
    }
    
    if (BanHelper::banUser($user['id'])) {
        return ['success' => true, 'message' => "已封禁用户: {$username}"];
    }
    
    return ['success' => false, 'message' => '封禁失败'];
}

/**
 * 解封用户
 */
function handleUnbanUser(array $params): array {
    if (count($params) < 2) {
        return ['success' => false, 'message' => '用法: ban unban <用户名>'];
    }
    
    $username = $params[1];
    $user = UserModel::findByUsername($username);
    
    if (!$user) {
        return ['success' => false, 'message' => "用户不存在: {$username}"];
    }
    
    if (BanHelper::unbanUser($user['id'])) {
        return ['success' => true, 'message' => "已解封用户: {$username}"];
    }
    
    return ['success' => false, 'message' => '解封失败'];
}

/**
 * 监禁用户
 */
function handleImprison(array $params): array {
    if (count($params) < 2) {
        return ['success' => false, 'message' => '用法: ban imprison <用户名>'];
    }
    
    $username = $params[1];
    $user = UserModel::findByUsername($username);
    
    if (!$user) {
        return ['success' => false, 'message' => "用户不存在: {$username}"];
    }
    
    if (BanHelper::imprisonUser($user['id'])) {
        return ['success' => true, 'message' => "已监禁用户: {$username}，角色已被移至监禁房间"];
    }
    
    return ['success' => false, 'message' => '监禁失败'];
}

/**
 * 释放用户
 */
function handleRelease(array $params): array {
    if (count($params) < 2) {
        return ['success' => false, 'message' => '用法: ban release <用户名>'];
    }
    
    $username = $params[1];
    $user = UserModel::findByUsername($username);
    
    if (!$user) {
        return ['success' => false, 'message' => "用户不存在: {$username}"];
    }
    
    if (BanHelper::releaseUser($user['id'])) {
        return ['success' => true, 'message' => "已释放用户: {$username}，角色已被移至起始房间"];
    }
    
    return ['success' => false, 'message' => '释放失败'];
}

/**
 * 显示封禁列表
 */
function handleList(): array {
    $output = "=== 封禁IP列表 ===\n";
    $bannedIps = BanHelper::getBannedIps();
    if (empty($bannedIps)) {
        $output .= "无\n";
    } else {
        foreach ($bannedIps as $ban) {
            $output .= "{$ban['ip_pattern']} - {$ban['reason']}\n";
        }
    }
    
    $output .= "\n=== 封禁/监禁用户列表 ===\n";
    $bannedUsers = BanHelper::getBannedUsers();
    if (empty($bannedUsers)) {
        $output .= "无\n";
    } else {
        foreach ($bannedUsers as $u) {
            $statusText = $u['status'] == BanHelper::STATUS_BANNED ? '封禁' : '监禁';
            $output .= "{$u['username']} [{$statusText}]\n";
        }
    }
    
    return ['success' => true, 'message' => $output];
}

/**
 * 显示帮助
 */
function showHelp(): array {
    $help = "=== 封禁管理命令 ===\n";
    $help .= "ban ip <IP模式> [原因] - 封禁IP\n";
    $help .= "ban unbanip <IP模式> - 解封IP\n";
    $help .= "ban user <用户名> - 封禁用户\n";
    $help .= "ban unban <用户名> - 解封用户\n";
    $help .= "ban imprison <用户名> - 监禁用户（移至监禁房间）\n";
    $help .= "ban release <用户名> - 释放用户\n";
    $help .= "ban list - 显示封禁列表\n";
    
    return ['success' => true, 'message' => $help];
}