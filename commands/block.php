<?php
/**
 * 功能级封禁命令 (block) - 管理员对用户进行功能级封禁/解封
 *
 * 用法: block <username> <feature> [reason]  -- 封锁某功能
 *       block unblock <username> <feature>    -- 解封某功能
 *       block list [username]                 -- 列出封锁状态
 *
 * feature 可选值: chat, pk, trade, move
 */
require_once __DIR__ . '/../helpers/WizardHelper.php';

/**
 * 功能级封禁命令入口
 * @param int $charId 操作者角色ID
 * @param string $param 参数
 * @return array
 */
function cmd_block(int $charId, string $param = ''): array {
    // 获取操作者信息并检查权限
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    $user = UserModel::find($char['user_id']);
    if (!$user || !WizardHelper::canUseCommand($user['id'], 'block')) {
        return ['success' => false, 'message' => '你没有权限执行此命令'];
    }

    $param = trim($param);
    if (empty($param)) {
        return showBlockHelp();
    }

    $parts = explode(' ', $param);
    $action = $parts[0];

    switch ($action) {
        case 'list':
            return handleBlockList($parts);
        case 'unblock':
            return handleUnblock($parts, $char, $user);
        default:
            return handleBlock($parts, $char, $user);
    }
}

/**
 * 封锁用户某功能
 */
function handleBlock(array $parts, array $operatorChar, array $operatorUser): array {
    if (count($parts) < 2) {
        return ['success' => false, 'message' => '用法: block <username> <feature> [reason]'];
    }

    $username = $parts[0];
    $feature = strtolower($parts[1]);
    $reason = count($parts) > 2 ? implode(' ', array_slice($parts, 2)) : '违规操作';

    // 验证 feature 值
    $validFeatures = ['chat', 'pk', 'trade', 'move'];
    if (!in_array($feature, $validFeatures)) {
        return ['success' => false, 'message' => "无效的功能类型: {$feature}，可选值: " . implode(', ', $validFeatures)];
    }

    // 查找目标用户
    $targetUser = UserModel::findByUsername($username);
    if (!$targetUser) {
        return ['success' => false, 'message' => "用户不存在: {$username}"];
    }

    $targetUserId = $targetUser['id'];

    try {
        // 检查是否已存在
        $existing = Database::queryOne(
            'SELECT id FROM user_blocks WHERE user_id = ? AND block_type = ?',
            [$targetUserId, $feature]
        );

        if ($existing) {
            return ['success' => false, 'message' => "用户 {$username} 的 {$feature} 功能已被封锁，如需修改请先 unblock"];
        }

        Database::execute(
            'INSERT INTO user_blocks (user_id, block_type, blocked_by, reason, created_at) VALUES (?, ?, ?, ?, NOW())',
            [$targetUserId, $feature, $operatorUser['id'], $reason]
        );

        log_game('BLOCK', "{$operatorChar['name']}({$operatorUser['username']}) 封锁 {$username} 的 {$feature} 功能, 原因: {$reason}");

        $featureNames = ['chat' => '聊天', 'pk' => 'PK', 'trade' => '交易', 'move' => '移动'];
        $featureName = $featureNames[$feature] ?? $feature;

        return ['success' => true, 'message' => "已封锁 {$username} 的{$featureName}功能，原因: {$reason}"];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => '操作失败，user_blocks 表可能不存在或结构异常: ' . $e->getMessage()];
    }
}

/**
 * 解封用户某功能
 */
function handleUnblock(array $parts, array $operatorChar, array $operatorUser): array {
    if (count($parts) < 3) {
        return ['success' => false, 'message' => '用法: block unblock <username> <feature>'];
    }

    $username = $parts[1];
    $feature = strtolower($parts[2]);

    // 验证 feature 值
    $validFeatures = ['chat', 'pk', 'trade', 'move'];
    if (!in_array($feature, $validFeatures)) {
        return ['success' => false, 'message' => "无效的功能类型: {$feature}，可选值: " . implode(', ', $validFeatures)];
    }

    // 查找目标用户
    $targetUser = UserModel::findByUsername($username);
    if (!$targetUser) {
        return ['success' => false, 'message' => "用户不存在: {$username}"];
    }

    $targetUserId = $targetUser['id'];

    try {
        $existing = Database::queryOne(
            'SELECT id FROM user_blocks WHERE user_id = ? AND block_type = ?',
            [$targetUserId, $feature]
        );

        if (!$existing) {
            return ['success' => false, 'message' => "用户 {$username} 的 {$feature} 功能未被封锁"];
        }

        Database::execute(
            'DELETE FROM user_blocks WHERE user_id = ? AND block_type = ?',
            [$targetUserId, $feature]
        );

        log_game('BLOCK', "{$operatorChar['name']}({$operatorUser['username']}) 解封 {$username} 的 {$feature} 功能");

        $featureNames = ['chat' => '聊天', 'pk' => 'PK', 'trade' => '交易', 'move' => '移动'];
        $featureName = $featureNames[$feature] ?? $feature;

        return ['success' => true, 'message' => "已解封 {$username} 的{$featureName}功能"];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => '操作失败，user_blocks 表可能不存在或结构异常: ' . $e->getMessage()];
    }
}

/**
 * 列出封锁状态
 */
function handleBlockList(array $parts): array {
    $username = $parts[1] ?? null;

    try {
        if ($username !== null) {
            // 查看指定用户的封锁状态
            $targetUser = UserModel::findByUsername($username);
            if (!$targetUser) {
                return ['success' => false, 'message' => "用户不存在: {$username}"];
            }

            $blocks = Database::queryAll(
                'SELECT ub.*, u.username FROM user_blocks ub JOIN users u ON ub.user_id = u.id WHERE ub.user_id = ?',
                [$targetUser['id']]
            );

            if (empty($blocks)) {
                return ['success' => true, 'message' => "{$username} 当前无任何功能封锁"];
            }

            $output = "=== {$username} 的封锁状态 ===\n";
        } else {
            // 列出所有被封锁用户
            $blocks = Database::queryAll(
                'SELECT ub.*, u.username FROM user_blocks ub JOIN users u ON ub.user_id = u.id ORDER BY ub.created_at DESC'
            );

            if (empty($blocks)) {
                return ['success' => true, 'message' => '当前无任何功能封锁记录'];
            }

            $output = "=== 功能封锁列表 ===\n";
        }

        $featureNames = ['chat' => '聊天', 'pk' => 'PK', 'trade' => '交易', 'move' => '移动'];

        foreach ($blocks as $block) {
            $featureName = $featureNames[$block['block_type']] ?? $block['block_type'];
            $output .= "  用户: {$block['username']}, 功能: {$featureName}";
            if (!empty($block['reason'])) {
                $output .= ", 原因: {$block['reason']}";
            }
            if (!empty($block['created_at'])) {
                $output .= ", 时间: {$block['created_at']}";
            }
            $output .= "\n";
        }

        return ['success' => true, 'message' => $output];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => '查询失败，user_blocks 表可能不存在或结构异常: ' . $e->getMessage()];
    }
}

/**
 * 显示帮助信息
 */
function showBlockHelp(): array {
    $help = "=== 功能级封禁命令 ===\n";
    $help .= "block <username> <feature> [reason] - 封锁功能\n";
    $help .= "block unblock <username> <feature>  - 解封功能\n";
    $help .= "block list [username]               - 查看封锁列表\n";
    $help .= "\nfeature 可选值: chat(聊天), pk(PK), trade(交易), move(移动)";

    return ['success' => true, 'message' => $help];
}
