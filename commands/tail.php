<?php
/**
 * 日志查看器命令 (tail) - 管理员查看游戏日志文件
 *
 * 用法: tail [日志类型] [行数]
 * 示例: tail 50       -- 查看今日日志最后50行
 *       tail LOGIN    -- 查看今日LOGIN类型日志
 *       tail LOGIN 30 -- 查看今日LOGIN类型日志最后30行
 */
require_once __DIR__ . '/../helpers/WizardHelper.php';

/**
 * 日志查看器命令入口
 * @param int $charId 操作者角色ID
 * @param string $param 参数
 * @return array
 */
function cmd_tail(int $charId, string $param = ''): array {
    // 获取操作者信息并检查权限
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    $user = UserModel::find($char['user_id']);
    if (!$user || !WizardHelper::canUseCommand($user['id'], 'tail')) {
        return ['success' => false, 'message' => '你没有权限执行此命令'];
    }

    // 解析参数
    $param = trim($param);
    $logType = null;
    $lineCount = 50;

    if (!empty($param)) {
        $parts = explode(' ', $param);

        if (count($parts) === 1) {
            // 单个参数: 如果是纯数字当行数, 否则当日志类型
            if (ctype_digit($parts[0])) {
                $lineCount = intval($parts[0]);
            } else {
                $logType = $parts[0];
            }
        } elseif (count($parts) >= 2) {
            // 两个参数: 第一个是日志类型, 第二个是行数
            $logType = $parts[0];
            if (ctype_digit($parts[1])) {
                $lineCount = intval($parts[1]);
            }
        }
    }

    // 限制最大行数
    if ($lineCount > 200) {
        $lineCount = 200;
    }
    if ($lineCount < 1) {
        $lineCount = 1;
    }

    // 构建日志文件路径
    $logDir = __DIR__ . '/../logs/';
    $today = date('Y-m-d');
    $logFile = $logDir . "game-{$today}.log";

    if (!file_exists($logFile)) {
        return ['success' => false, 'message' => "今日日志文件不存在: game-{$today}.log"];
    }

    // 读取文件内容
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return ['success' => false, 'message' => '无法读取日志文件'];
    }

    // 如果指定了日志类型, 过滤行首包含该类型的行
    if ($logType !== null) {
        $filtered = [];
        foreach ($lines as $line) {
            if (stripos($line, $logType) !== false) {
                $filtered[] = $line;
            }
        }
        $lines = $filtered;
    }

    // 取最后 N 行
    $totalLines = count($lines);
    if ($totalLines > $lineCount) {
        $lines = array_slice($lines, -$lineCount);
    }

    // 格式化输出
    $header = "=== 日志查看";
    if ($logType !== null) {
        $header .= " [类型: {$logType}]";
    }
    $header .= " (共{$totalLines}行匹配, 显示最后" . min($lineCount, $totalLines) . "行) ===\n";

    $output = $header;
    $output .= implode("\n", $lines);
    $output .= "\n" . str_repeat('-', 50);

    return ['success' => true, 'message' => $output];
}
