<?php
/**
 * 查找玩家位置命令 (where)
 *
 * 用法: where [username|角色名]
 * 功能:
 *   - 无参数: 列出所有在线玩家及其位置
 *   - 有参数: 按用户名或角色名模糊搜索
 * 权限: elder (等级1) 及以上
 */

require_once __DIR__ . '/../helpers/WizardHelper.php';

/**
 * 执行 where 命令
 * @param int $charId 操作者角色ID
 * @param string $param 搜索关键字（可选）
 * @return array
 */
function cmd_where(int $charId, string $param = ''): array {
    // 获取操作者信息
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在。'];
    }

    $userId = $char['user_id'];

    // 权限检查
    if (!WizardHelper::canUseCommand($userId, 'where')) {
        return ['success' => false, 'message' => '你没有权限执行此命令。'];
    }

    $param = trim($param);

    if (empty($param)) {
        // 无参数: 列出所有在线玩家
        $sql = "SELECT c.name, c.current_area, c.current_room, r.name AS room_name, c.online
                FROM characters c
                LEFT JOIN rooms r ON c.current_area = r.area AND c.current_room = r.room_id
                WHERE c.online = 1
                ORDER BY c.name";
        $players = Database::queryAll($sql);

        if (empty($players)) {
            return ['success' => true, 'message' => '当前没有在线玩家。'];
        }

        $lines = [];
        $lines[] = sprintf('=== 在线玩家列表 (%d 人) ===', count($players));
        $lines[] = str_pad('角色名', 20) . str_pad('位置', 30) . '房间';
        $lines[] = str_repeat('-', 70);

        foreach ($players as $p) {
            $roomName = $p['room_name'] ?: ($p['current_area'] . '/' . $p['current_room']);
            $lines[] = sprintf(
                '%-20s %-30s %s',
                $p['name'],
                $p['current_area'] . '/' . $p['current_room'],
                $roomName
            );
        }

        return ['success' => true, 'message' => implode("\n", $lines)];
    }

    // 有参数: 模糊搜索
    $keyword = '%' . $param . '%';
    $sql = "SELECT c.name, c.current_area, c.current_room, r.name AS room_name, c.online,
                   u.username
            FROM characters c
            LEFT JOIN rooms r ON c.current_area = r.area AND c.current_room = r.room_id
            LEFT JOIN users u ON c.user_id = u.id
            WHERE c.name LIKE ? OR u.username LIKE ?
            ORDER BY c.online DESC, c.name";
    $players = Database::queryAll($sql, [$keyword, $keyword]);

    if (empty($players)) {
        return ['success' => true, 'message' => "未找到匹配 \"{$param}\" 的玩家。"];
    }

    $lines = [];
    $lines[] = sprintf('=== 搜索结果: "%s" (%d 条) ===', $param, count($players));
    $lines[] = str_pad('角色名', 16) . str_pad('账号', 16) . str_pad('状态', 8) . str_pad('位置', 30) . '房间';
    $lines[] = str_repeat('-', 90);

    foreach ($players as $p) {
        $statusText = $p['online'] ? '在线' : '离线';
        $roomName = $p['room_name'] ?: ($p['current_area'] . '/' . $p['current_room']);
        $lines[] = sprintf(
            '%-16s %-16s %-8s %-30s %s',
            $p['name'],
            $p['username'] ?: '-',
            $statusText,
            $p['current_area'] . '/' . $p['current_room'],
            $roomName
        );
    }

    return ['success' => true, 'message' => implode("\n", $lines)];
}
