<?php
/**
 * 用户详细资料命令 (whois)
 *
 * 用法: whois <username|角色名>
 * 功能: 查看指定用户的账号及角色完整信息
 * 权限: immortal (等级2) 及以上
 */

require_once __DIR__ . '/../helpers/WizardHelper.php';

/**
 * 执行 whois 命令
 * @param int $charId 操作者角色ID
 * @param string $param 用户名或角色名
 * @return array
 */
function cmd_whois(int $charId, string $param = ''): array {
    // 获取操作者信息
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在。'];
    }

    $userId = $char['user_id'];

    // 权限检查
    if (!WizardHelper::canUseCommand($userId, 'whois')) {
        return ['success' => false, 'message' => '你没有权限执行此命令。'];
    }

    $param = trim($param);
    if (empty($param)) {
        return ['success' => false, 'message' => "用法: whois <用户名|角色名>\n查看指定用户的详细资料。"];
    }

    // 先按用户名搜索
    $targetUser = UserModel::findByUsername($param);

    // 如果按用户名未找到，按角色名搜索
    if (!$targetUser) {
        $targetChar = Database::queryOne(
            'SELECT user_id FROM characters WHERE name = ?',
            [$param]
        );
        if ($targetChar) {
            $targetUser = UserModel::find(intval($targetChar['user_id']));
        }
    }

    if (!$targetUser) {
        return ['success' => false, 'message' => "找不到匹配 \"{$param}\" 的用户或角色。"];
    }

    // 获取该用户的所有角色
    $characters = Database::queryAll(
        'SELECT c.*, r.name AS room_name
         FROM characters c
         LEFT JOIN rooms r ON c.current_area = r.area AND c.current_room = r.room_id
         WHERE c.user_id = ?',
        [$targetUser['id']]
    );

    // 状态描述
    $statusMap = [
        0 => '已禁用',
        1 => '正常',
        2 => '已封禁',
        3 => '已监禁',
        4 => '欢迎室',
    ];
    $statusText = $statusMap[intval($targetUser['status'])] ?? '未知(' . $targetUser['status'] . ')';

    // 巫师等级
    $wizLevel = intval($targetUser['wizard_level'] ?? 0);
    $wizLevelName = WizardHelper::getLevelName($wizLevel);
    $wizLevelTitle = WizardHelper::getLevelTitle($wizLevel);

    // 格式化输出
    $lines = [];
    $lines[] = '========================================';
    $lines[] = '         用户详细资料';
    $lines[] = '========================================';
    $lines[] = '';
    $lines[] = '--- 账号信息 ---';
    $lines[] = '  用户名:     ' . ($targetUser['username'] ?? '-');
    $lines[] = '  用户ID:     ' . ($targetUser['id'] ?? '-');
    $lines[] = '  注册时间:   ' . ($targetUser['register_time'] ?? '-');
    $lines[] = '  最后登录:   ' . ($targetUser['last_login'] ?? '-');
    $lines[] = '  最后IP:     ' . ($targetUser['last_ip'] ?? '-');
    $lines[] = '  账号状态:   ' . $statusText;
    $lines[] = '  VIP等级:    ' . ($targetUser['vip_level'] ?? 0);
    $lines[] = '  巫师等级:   ' . $wizLevelName . ' ' . $wizLevelTitle;
    $lines[] = '';

    // 角色信息
    if (empty($characters)) {
        $lines[] = '--- 角色信息 ---';
        $lines[] = '  该用户没有角色。';
    } else {
        foreach ($characters as $idx => $c) {
            $lines[] = '--- 角色信息 ' . (count($characters) > 1 ? '#' . ($idx + 1) : '') . ' ---';
            $lines[] = '  角色名:     ' . ($c['name'] ?? '-');
            $lines[] = '  角色ID:     ' . ($c['id'] ?? '-');
            $lines[] = '  等级:       ' . ($c['level'] ?? 0);
            $lines[] = '  门派:       ' . ($c['family'] ?: '无');
            $lines[] = '  师傅:       ' . ($c['master_name'] ?: '无');
            $lines[] = '  辈分:       ' . ($c['generation'] ?? 0) . '代';
            $lines[] = '  位置:       ' . ($c['current_area'] ?? '-') . '/' . ($c['current_room'] ?? '-');
            $roomName = $c['room_name'] ?? '';
            if ($roomName) {
                $lines[] = '  房间名:     ' . $roomName;
            }
            $lines[] = '  在线状态:   ' . (intval($c['online'] ?? 0) ? '在线' : '离线');
            $lines[] = '  经验:       ' . ($c['experience'] ?? 0);
            $lines[] = '  道行:       ' . ($c['daoxing'] ?? 0);
            $lines[] = '  Combat Exp: ' . ($c['combat_exp'] ?? 0);
            $lines[] = '';
            $lines[] = '  --- 属性 ---';
            $lines[] = '  精(gin):    ' . ($c['gin'] ?? 0);
            $lines[] = '  气(kee):    ' . ($c['kee'] ?? 0);
            $lines[] = '  神(sen):    ' . ($c['sen'] ?? 0);
            $lines[] = '  力量(str):  ' . ($c['str'] ?? 0);
            $lines[] = '  智力(int):  ' . ($c['int'] ?? 0);
            $lines[] = '  体质(con):  ' . ($c['con'] ?? 0);
            $lines[] = '  敏捷(dex):  ' . ($c['dex'] ?? 0);
            $lines[] = '';
            $lines[] = '  --- 财物 ---';
            $lines[] = '  金:         ' . ($c['gold'] ?? 0);
            $lines[] = '  银:         ' . ($c['silver'] ?? 0);
            $lines[] = '  铜:         ' . ($c['copper'] ?? 0);
            $lines[] = '  存款:       ' . ($c['balance'] ?? 0);
            $lines[] = '  食物:       ' . ($c['food'] ?? 0);
            $lines[] = '  饮水:       ' . ($c['water'] ?? 0);
            $lines[] = '';
        }
    }

    $lines[] = '========================================';

    return ['success' => true, 'message' => implode("\n", $lines)];
}
