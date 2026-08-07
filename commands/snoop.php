<?php
/**
 * 消息日志查看命令 (snoop) - 管理员查看指定角色的消息队列
 *
 * 用法: snoop <角色名> [条数]
 * 或:   snoop -none
 */
require_once __DIR__ . '/../helpers/WizardHelper.php';
require_once __DIR__ . '/../models/User.php';

/**
 * 消息日志查看命令入口
 * @param int $charId 操作者角色ID
 * @param string $param 参数
 * @return array
 */
function cmd_snoop(int $charId, string $param = ''): array {
    // 获取操作者信息并检查权限
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    $user = UserModel::find($char['user_id']);
    if (!$user || !WizardHelper::canUseCommand($user['id'], 'snoop')) {
        return ['success' => false, 'message' => '你没有权限执行此命令'];
    }

    $param = trim($param);

    // 停止查看
    if ($param === '-none') {
        return ['success' => true, 'message' => '已停止查看'];
    }

    if (empty($param)) {
        return ['success' => false, 'message' => '用法: snoop <角色名> [条数] 或 snoop -none'];
    }

    // 解析参数
    $parts = explode(' ', $param);
    $targetName = $parts[0];
    $limit = isset($parts[1]) && ctype_digit($parts[1]) ? intval($parts[1]) : 50;

    // 限制最大条数
    if ($limit > 100) {
        $limit = 100;
    }
    if ($limit < 1) {
        $limit = 1;
    }

    // 查找目标角色
    $targetChar = Database::queryOne('SELECT id, name, user_id FROM characters WHERE LOWER(name) = LOWER(?)', [$targetName]);

    // 角色名找不到时，尝试按用户名查找
    if (!$targetChar) {
        $targetUser = UserModel::findByUsername($targetName);
        if ($targetUser) {
            $targetChar = CharacterModel::getByUserId($targetUser['id']);
        }
    }

    if (!$targetChar) {
        return ['success' => false, 'message' => "角色不存在: {$targetName}"];
    }

    // 检查 snoop 权限
    $targetUserId = $targetChar['user_id'];
    if (!WizardHelper::canSnoop($user['id'], $targetUserId)) {
        return ['success' => false, 'message' => '你没有权限查看该角色的消息'];
    }

    // 查询消息队列
    $messages = Database::queryAll(
        'SELECT * FROM message_queue WHERE char_id = ? ORDER BY created_at DESC LIMIT ' . intval($limit),
        [$targetChar['id']]
    );

    if (empty($messages)) {
        log_game('SNOOP', "{$char['name']}({$user['username']}) 查看 {$targetChar['name']} 的消息记录 - 无记录");
        return ['success' => true, 'message' => "{$targetChar['name']} 的消息记录为空"];
    }

    // 格式化消息列表
    $output = "=== {$targetChar['name']} 的消息记录 (最近{$limit}条) ===\n";
    $output .= str_repeat('-', 50) . "\n";

    foreach ($messages as $msg) {
        $time = $msg['created_at'] ?? '未知时间';
        $type = $msg['type'] ?? 'unknown';
        $content = $msg['message'] ?? '';
        $fromCharId = $msg['from_char_id'] ?? '';
        $readStatus = !empty($msg['read_at']) ? '已读' : '未读';

        $output .= "[{$time}] [{$type}] [{$readStatus}]";
        if (!empty($fromCharId)) {
            $output .= " 来自:{$fromCharId}";
        }
        $output .= "\n  {$content}\n";
    }

    $output .= str_repeat('-', 50) . "\n";
    $output .= "共 " . count($messages) . " 条消息";

    // 记录审计日志
    log_game('SNOOP', "{$char['name']}({$user['username']}) 查看 {$targetChar['name']} 的消息记录, 共" . count($messages) . "条");

    return ['success' => true, 'message' => $output];
}
