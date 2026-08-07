<?php
/**
 * 同IP多账号检测命令 (sameip)
 *
 * 用法: sameip [ip前缀]
 * 功能:
 *   - 无参数: 列出所有共享同一IP的账号组
 *   - 有参数: 按IP前缀模糊搜索，列出匹配的用户详情
 * 权限: immortal (等级2) 及以上
 */

require_once __DIR__ . '/../helpers/WizardHelper.php';

/**
 * 执行 sameip 命令
 * @param int $charId 操作者角色ID
 * @param string $param IP前缀（可选）
 * @return array
 */
function cmd_sameip(int $charId, string $param = ''): array {
    // 获取操作者信息
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在。'];
    }

    $userId = $char['user_id'];

    // 权限检查
    if (!WizardHelper::canUseCommand($userId, 'sameip')) {
        return ['success' => false, 'message' => '你没有权限执行此命令。'];
    }

    $param = trim($param);

    if (empty($param)) {
        // 无参数: 查找所有共享IP的账号组
        $sql = "SELECT last_ip, GROUP_CONCAT(username ORDER BY username SEPARATOR ', ') AS users, COUNT(*) AS cnt
                FROM users
                WHERE last_ip != '' AND last_ip IS NOT NULL
                GROUP BY last_ip
                HAVING cnt > 1
                ORDER BY cnt DESC";
        $groups = Database::queryAll($sql);

        if (empty($groups)) {
            return ['success' => true, 'message' => '未发现共享IP的账号。'];
        }

        $lines = [];
        $lines[] = sprintf('=== 同IP多账号检测 (%d 组) ===', count($groups));
        $lines[] = str_pad('IP地址', 24) . str_pad('数量', 8) . '关联账号';
        $lines[] = str_repeat('-', 80);

        foreach ($groups as $g) {
            $lines[] = sprintf(
                '%-24s %-8d %s',
                $g['last_ip'],
                $g['cnt'],
                $g['users']
            );
        }

        $lines[] = '';
        $lines[] = '提示: 使用 sameip <IP前缀> 查看指定IP的用户详情。';

        return ['success' => true, 'message' => implode("\n", $lines)];
    }

    // 有参数: 按IP前缀搜索
    $ipPattern = $param . '%';
    $sql = "SELECT username, last_ip, status, wizard_level, last_login
            FROM users
            WHERE last_ip LIKE ?
            ORDER BY last_ip, username";
    $users = Database::queryAll($sql, [$ipPattern]);

    if (empty($users)) {
        return ['success' => true, 'message' => "未找到IP匹配 \"{$param}*\" 的用户。"];
    }

    // 状态映射
    $statusMap = [
        0 => '已禁用',
        1 => '正常',
        2 => '已封禁',
        3 => '已监禁',
        4 => '欢迎室',
    ];

    $lines = [];
    $lines[] = sprintf('=== IP搜索结果: "%s*" (%d 个用户) ===', $param, count($users));
    $lines[] = str_pad('用户名', 18) . str_pad('IP地址', 22) . str_pad('状态', 10) . str_pad('巫师', 12) . '最后登录';
    $lines[] = str_repeat('-', 90);

    foreach ($users as $u) {
        $statusText = $statusMap[intval($u['status'])] ?? '未知';
        $wizLevel = intval($u['wizard_level']);
        $wizName = WizardHelper::getLevelName($wizLevel);

        $lines[] = sprintf(
            '%-18s %-22s %-10s %-12s %s',
            $u['username'],
            $u['last_ip'],
            $statusText,
            $wizName,
            $u['last_login'] ?: '-'
        );
    }

    return ['success' => true, 'message' => implode("\n", $lines)];
}
