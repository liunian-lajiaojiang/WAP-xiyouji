<?php
/**
 * 维护模式命令 (wizlock)
 *
 * 用法: wizlock [on|off|status]
 * 功能:
 *   - status 或无参数: 查看当前维护模式状态
 *   - on: 开启维护模式（非巫师玩家将无法登录）
 *   - off: 关闭维护模式
 * 权限: arch (等级5) 及以上
 */

require_once __DIR__ . '/../helpers/WizardHelper.php';
require_once __DIR__ . '/../includes/functions.php';

/**
 * 执行 wizlock 命令
 * @param int $charId 操作者角色ID
 * @param string $param 操作: on|off|status
 * @return array
 */
function cmd_wizlock(int $charId, string $param = ''): array {
    // 获取操作者信息
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在。'];
    }

    $userId = $char['user_id'];

    // 权限检查
    if (!WizardHelper::canUseCommand($userId, 'wizlock')) {
        return ['success' => false, 'message' => '你没有权限执行此命令。'];
    }

    $action = strtolower(trim($param));

    // 无参数或 status: 查询当前状态
    if ($action === '' || $action === 'status') {
        $row = Database::queryOne(
            "SELECT value FROM variables WHERE var_key = 'wizlock_status'"
        );
        $isOn = $row && $row['value'] === '1';

        $lines = [];
        $lines[] = '=== 维护模式状态 ===';
        $lines[] = '当前状态: ' . ($isOn ? '已开启 (非巫师玩家无法登录)' : '已关闭 (正常登录)');
        $lines[] = '';
        $lines[] = '操作: wizlock on  — 开启维护模式';
        $lines[] = '      wizlock off — 关闭维护模式';

        return ['success' => true, 'message' => implode("\n", $lines)];
    }

    // on: 开启维护模式
    if ($action === 'on') {
        $sql = "INSERT INTO variables (var_key, value) VALUES ('wizlock_status', '1')
                ON DUPLICATE KEY UPDATE value = '1'";
        Database::execute($sql);

        // 记录日志
        $operatorUser = UserModel::find($userId);
        $operatorName = $operatorUser ? $operatorUser['username'] : "char#{$charId}";
        log_game('wizlock', "大巫师 {$operatorName} 开启了维护模式");

        return [
            'success' => true,
            'message' => "维护模式已开启，非巫师玩家将无法登录。\n使用 wizlock off 关闭维护模式。"
        ];
    }

    // off: 关闭维护模式
    if ($action === 'off') {
        Database::execute(
            "UPDATE variables SET value = '0' WHERE var_key = 'wizlock_status'"
        );

        // 记录日志
        $operatorUser = UserModel::find($userId);
        $operatorName = $operatorUser ? $operatorUser['username'] : "char#{$charId}";
        log_game('wizlock', "大巫师 {$operatorName} 关闭了维护模式");

        return [
            'success' => true,
            'message' => "维护模式已关闭，所有玩家均可正常登录。"
        ];
    }

    // 未知参数
    return [
        'success' => false,
        'message' => "用法: wizlock [on|off|status]\n"
                   . "  on     — 开启维护模式\n"
                   . "  off    — 关闭维护模式\n"
                   . "  status — 查看当前状态（默认）"
    ];
}
