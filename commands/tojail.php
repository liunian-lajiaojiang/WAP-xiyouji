<?php
/**
 * 快速监禁命令 (tojail)
 *
 * 用法: tojail <username> [reason]
 * 功能: 将指定用户监禁（快速操作，无需多步确认）
 * 权限: wizard (等级4) 及以上
 */

require_once __DIR__ . '/../helpers/BanHelper.php';
require_once __DIR__ . '/../helpers/WizardHelper.php';
require_once __DIR__ . '/../includes/functions.php';

/**
 * 执行 tojail 命令
 * @param int $charId 操作者角色ID
 * @param string $param 参数: "username [reason]"
 * @return array
 */
function cmd_tojail(int $charId, string $param = ''): array {
    // 获取操作者信息
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在。'];
    }

    $userId = $char['user_id'];

    // 权限检查
    if (!WizardHelper::canUseCommand($userId, 'tojail')) {
        return ['success' => false, 'message' => '你没有权限执行此命令。'];
    }

    // 解析参数
    $param = trim($param);
    if (empty($param)) {
        return ['success' => false, 'message' => "用法: tojail <用户名> [原因]\n将指定用户快速关入监禁室。"];
    }

    $parts = explode(' ', $param, 2);
    $username = $parts[0];
    $reason = isset($parts[1]) ? trim($parts[1]) : '违规操作';

    // 查找目标用户
    $targetUser = UserModel::findByUsername($username);
    if (!$targetUser) {
        return ['success' => false, 'message' => "找不到用户: {$username}"];
    }

    // 检查操作对象权限
    if (!WizardHelper::canOperateOn($userId, $targetUser['id'])) {
        return ['success' => false, 'message' => '你没有权限对该用户执行监禁操作。'];
    }

    // 执行监禁
    $result = BanHelper::imprisonUser($targetUser['id']);
    if (!$result) {
        return ['success' => false, 'message' => "监禁用户 {$username} 失败。"];
    }

    // 记录日志
    $operatorUser = UserModel::find($userId);
    $operatorName = $operatorUser ? $operatorUser['username'] : "char#{$charId}";
    log_game('tojail', "巫师 {$operatorName} 将用户 {$username} 监禁，原因: {$reason}");

    return [
        'success' => true,
        'message' => "已将用户 {$username} 关入监禁室。\n原因: {$reason}"
    ];
}
