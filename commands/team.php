<?php
/**
 * 组队命令 (Team)
 * 队伍管理：邀请、解散、离开、聊天、查看
 *
 * 用法：
 *   team                - 显示当前队伍成员
 *   team list           - 显示当前队伍成员
 *   team with <玩家名>  - 邀请玩家加入队伍
 *   team accept         - 接受组队邀请
 *   team reject         - 拒绝组队邀请
 *   team dismiss        - 解散队伍（队长操作）
 *   team leave          - 离开队伍
 *   team talk <消息>    - 队伍聊天
 */
require_once DAEMON_PATH . 'MessageDaemon.php';
require_once __DIR__ . '/../models/Character.php';

// 队伍最大人数
defined('TEAM_MAX_MEMBERS') || define('TEAM_MAX_MEMBERS', 5);
// 邀请超时时间（秒）
defined('TEAM_INVITE_TIMEOUT') || define('TEAM_INVITE_TIMEOUT', 60);

/**
 * 组队命令主入口
 */
function cmd_team(int $charId, string $param = ''): array {
    $param = trim($param);

    if (empty($param)) {
        return teamList($charId);
    }

    $parts = preg_split('/\s+/', $param, 2);
    $subCmd = strtolower($parts[0]);
    $arg = $parts[1] ?? '';

    switch ($subCmd) {
        case 'list':
            return teamList($charId);
        case 'with':
            return teamWith($charId, $arg);
        case 'accept':
            return teamAccept($charId);
        case 'reject':
            return teamReject($charId);
        case 'dismiss':
            return teamDismiss($charId);
        case 'leave':
            return teamLeave($charId);
        case 'talk':
            return teamTalk($charId, $arg);
        default:
            return ['success' => false, 'message' => '未知的队伍命令，输入 team 查看帮助'];
    }
}

/**
 * 显示当前队伍成员
 */
function teamList(int $charId): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    $members = getTeamMembers($charId);

    if (empty($members)) {
        return ['success' => false, 'message' => '你现在并没有参加任何队伍。'];
    }

    $isLeader = isTeamLeader($charId);
    $lines = [];
    $lines[] = HTML_HIWHT . '【队伍】你当前的队伍成员：' . HTML_NOR;

    foreach ($members as $i => $member) {
        $online = $member['online'] ? '（在线）' : '（离线）';
        $role = ($member['id'] == $member['leader_id']) ? ' [队长]' : '';
        $lines[] = '  ' . ($i + 1) . '. ' . HTML_HICYN . $member['name'] . HTML_NOR . $role . $online;
    }

    $lines[] = '共 ' . count($members) . ' / ' . TEAM_MAX_MEMBERS . ' 人';

    return [
        'success' => true,
        'type' => 'team',
        'output' => implode("\n", $lines)
    ];
}

/**
 * 邀请玩家加入队伍
 */
function teamWith(int $charId, string $targetName): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    if (empty($targetName)) {
        return ['success' => false, 'message' => '你想和谁成为伙伴？用法：team with <玩家名>'];
    }

    // 不能邀请自己
    if ($targetName === $char['name']) {
        return ['success' => false, 'message' => '你不能邀请自己加入队伍。'];
    }

    // 查找目标玩家（在线）
    $target = CharacterModel::findByName($targetName);
    if (!$target || !$target['online']) {
        return ['success' => false, 'message' => "找不到名为 {$targetName} 的在线玩家。"];
    }

    // 检查自己是否已有队伍
    $myTeam = getTeamRecord($charId);

    if ($myTeam) {
        // 已有队伍，必须是队长才能邀请
        if (!isTeamLeader($charId)) {
            return ['success' => false, 'message' => '只有队长可以邀请别人加入队伍。'];
        }

        $leaderId = $charId;
    } else {
        // 没有队伍，创建新队伍，自己成为队长
        $leaderId = $charId;
        // 将自己加入队伍
        Database::execute(
            "INSERT INTO character_teams (leader_id, member_id, status, created_at) VALUES (?, ?, 'joined', NOW())",
            [$leaderId, $charId]
        );
    }

    // 检查队伍人数是否已满
    $memberCount = count(getTeamMembers($charId));
    if ($memberCount >= TEAM_MAX_MEMBERS) {
        return ['success' => false, 'message' => '你的队伍已经满 ' . TEAM_MAX_MEMBERS . ' 人了，无法再邀请新成员。'];
    }

    // 检查目标是否已在自己的队伍中
    $existingMember = Database::queryOne(
        "SELECT * FROM character_teams WHERE leader_id = ? AND member_id = ? AND status = 'joined'",
        [$leaderId, $target['id']]
    );
    if ($existingMember) {
        return ['success' => false, 'message' => HTML_HICYN . $targetName . HTML_NOR . ' 已经在你的队伍中了。'];
    }

    // 检查目标是否已有未过期的邀请
    $existingInvite = Database::queryOne(
        "SELECT * FROM character_teams WHERE leader_id = ? AND member_id = ? AND status = 'invited' AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
        [$leaderId, $target['id'], TEAM_INVITE_TIMEOUT]
    );
    if ($existingInvite) {
        return ['success' => false, 'message' => '你已经邀请过 ' . HTML_HICYN . $targetName . HTML_NOR . ' 了，请等待对方回应。'];
    }

    // 检查目标是否在其他队伍中
    $targetTeam = getTeamRecord($target['id']);
    if ($targetTeam) {
        return ['success' => false, 'message' => HTML_HICYN . $targetName . HTML_NOR . ' 已经在其他队伍中了。'];
    }

    // 清除旧的过期邀请
    Database::execute(
        "DELETE FROM character_teams WHERE leader_id = ? AND member_id = ? AND status = 'invited'",
        [$leaderId, $target['id']]
    );

    // 创建邀请记录
    Database::execute(
        "INSERT INTO character_teams (leader_id, member_id, status, created_at) VALUES (?, ?, 'invited', NOW())",
        [$leaderId, $target['id']]
    );

    // 通知邀请者
    $output = '你邀请 ' . HTML_HICYN . $targetName . HTML_NOR . ' 加入你的队伍。';

    // 通知被邀请者
    $inviteMsg = HTML_HIWHT . '【队伍】' . HTML_NOR
        . HTML_HICYN . $char['name'] . HTML_NOR
        . ' 邀请你加入队伍，请用 ' . HTML_HIYEL . 'team accept' . HTML_NOR . ' 接受，'
        . HTML_HIYEL . 'team reject' . HTML_NOR . ' 拒绝。'
        . '（' . TEAM_INVITE_TIMEOUT . '秒内有效）';
    MessageDaemon::sendToPlayer($target['id'], $inviteMsg, 'team');

    // 同房间广播
    if ($char['current_room'] === $target['current_room']) {
        $roomMsg = HTML_HICYN . $char['name'] . HTML_NOR . ' 邀请 ' . HTML_HICYN . $targetName . HTML_NOR . ' 加入队伍。';
        MessageDaemon::broadcastToRoom($char['current_room'], $roomMsg, $charId, 'team');
    }

    log_game('TEAM', "{$char['name']} 邀请 {$targetName} 加入队伍");

    return [
        'success' => true,
        'type' => 'team',
        'output' => $output
    ];
}

/**
 * 接受组队邀请
 */
function teamAccept(int $charId): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    // 查找未过期的邀请
    $invite = Database::queryOne(
        "SELECT * FROM character_teams WHERE member_id = ? AND status = 'invited' AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND) ORDER BY created_at DESC LIMIT 1",
        [$charId, TEAM_INVITE_TIMEOUT]
    );

    if (!$invite) {
        return ['success' => false, 'message' => '你没有收到任何组队邀请，或邀请已过期。'];
    }

    $leaderId = $invite['leader_id'];

    // 检查自己是否已在其他队伍中
    $myTeam = getTeamRecord($charId);
    if ($myTeam && $myTeam['leader_id'] != $leaderId) {
        return ['success' => false, 'message' => '你已经在其他队伍中了，请先离开当前队伍。'];
    }

    // 检查队伍是否已满
    $memberCount = count(getTeamMembersByLeader($leaderId));
    if ($memberCount >= TEAM_MAX_MEMBERS) {
        // 队伍已满，删除邀请
        Database::execute(
            "DELETE FROM character_teams WHERE leader_id = ? AND member_id = ? AND status = 'invited'",
            [$leaderId, $charId]
        );
        return ['success' => false, 'message' => '队伍已经满 ' . TEAM_MAX_MEMBERS . ' 人了，无法加入。'];
    }

    // 接受邀请，更新状态
    Database::execute(
        "UPDATE character_teams SET status = 'joined' WHERE leader_id = ? AND member_id = ? AND status = 'invited'",
        [$leaderId, $charId]
    );

    // 获取队长信息
    $leader = CharacterModel::find($leaderId);
    $leaderName = $leader ? $leader['name'] : '未知';

    // 通知新成员
    $output = '你加入了 ' . HTML_HICYN . $leaderName . HTML_NOR . ' 的队伍。';

    // 通知队伍其他成员
    $joinMsg = HTML_HIWHT . '【队伍】' . HTML_NOR
        . HTML_HICYN . $char['name'] . HTML_NOR . ' 加入了队伍。';
    notifyTeamMembers($leaderId, $joinMsg, $charId);

    log_game('TEAM', "{$char['name']} 加入了 {$leaderName} 的队伍");

    return [
        'success' => true,
        'type' => 'team',
        'output' => $output
    ];
}

/**
 * 拒绝组队邀请
 */
function teamReject(int $charId): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    // 查找未过期的邀请
    $invite = Database::queryOne(
        "SELECT * FROM character_teams WHERE member_id = ? AND status = 'invited' AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND) ORDER BY created_at DESC LIMIT 1",
        [$charId, TEAM_INVITE_TIMEOUT]
    );

    if (!$invite) {
        return ['success' => false, 'message' => '你没有收到任何组队邀请，或邀请已过期。'];
    }

    $leaderId = $invite['leader_id'];

    // 删除邀请记录
    Database::execute(
        "DELETE FROM character_teams WHERE leader_id = ? AND member_id = ? AND status = 'invited'",
        [$leaderId, $charId]
    );

    // 获取队长信息
    $leader = CharacterModel::find($leaderId);
    $leaderName = $leader ? $leader['name'] : '未知';

    // 通知队长
    if ($leader && $leader['online']) {
        $rejectMsg = HTML_HIWHT . '【队伍】' . HTML_NOR
            . HTML_HICYN . $char['name'] . HTML_NOR . ' 拒绝了你的组队邀请。';
        MessageDaemon::sendToPlayer($leaderId, $rejectMsg, 'team');
    }

    log_game('TEAM', "{$char['name']} 拒绝了 {$leaderName} 的组队邀请");

    return [
        'success' => true,
        'type' => 'team',
        'output' => '你拒绝了 ' . HTML_HICYN . $leaderName . HTML_NOR . ' 的组队邀请。'
    ];
}

/**
 * 解散队伍（队长操作）
 */
function teamDismiss(int $charId): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    $members = getTeamMembers($charId);
    if (empty($members)) {
        return ['success' => false, 'message' => '你现在并没有参加任何队伍。'];
    }

    if (!isTeamLeader($charId)) {
        return ['success' => false, 'message' => '只有队长才能解散队伍。'];
    }

    // 通知所有队员
    $dismissMsg = HTML_HIWHT . '【队伍】' . HTML_NOR
        . HTML_HICYN . $char['name'] . HTML_NOR . ' 将队伍解散了。';
    notifyTeamMembers($charId, $dismissMsg, $charId);

    // 删除整个队伍
    Database::execute(
        "DELETE FROM character_teams WHERE leader_id = ?",
        [$charId]
    );

    log_game('TEAM', "{$char['name']} 解散了队伍");

    return [
        'success' => true,
        'type' => 'team',
        'output' => '你将队伍解散了。'
    ];
}

/**
 * 离开队伍
 */
function teamLeave(int $charId): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    $myRecord = getTeamRecord($charId);
    if (!$myRecord) {
        return ['success' => false, 'message' => '你现在并没有参加任何队伍。'];
    }

    $leaderId = $myRecord['leader_id'];

    // 队长不能直接离开，必须用 dismiss
    if ($leaderId == $charId) {
        return ['success' => false, 'message' => '你是队长，不能离开队伍。请使用 team dismiss 解散队伍，或将队长转让后再离开。'];
    }

    // 通知其他队员
    $leaveMsg = HTML_HIWHT . '【队伍】' . HTML_NOR
        . HTML_HICYN . $char['name'] . HTML_NOR . ' 离开了队伍。';
    notifyTeamMembers($leaderId, $leaveMsg, $charId);

    // 删除该成员记录
    Database::execute(
        "DELETE FROM character_teams WHERE leader_id = ? AND member_id = ?",
        [$leaderId, $charId]
    );

    log_game('TEAM', "{$char['name']} 离开了队伍");

    return [
        'success' => true,
        'type' => 'team',
        'output' => '你离开了队伍。'
    ];
}

/**
 * 队伍聊天
 */
function teamTalk(int $charId, string $message): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    if (empty($message)) {
        return ['success' => false, 'message' => '你要对队伍说什么？用法：team talk <消息>'];
    }

    $members = getTeamMembers($charId);
    if (empty($members)) {
        return ['success' => false, 'message' => '你现在并没有和别人组成队伍。'];
    }

    // 构建队伍聊天消息
    $talkMsg = HTML_HIWHT . '【队伍】' . HTML_NOR
        . HTML_HICYN . $char['name'] . HTML_NOR . '：'
        . HTML_HIWHT . $message . HTML_NOR;

    // 发送给队伍中除自己以外的所有在线成员
    foreach ($members as $member) {
        if ($member['id'] != $charId && $member['online']) {
            MessageDaemon::sendToPlayer($member['id'], $talkMsg, 'team');
        }
    }

    log_game('TEAM_TALK', "{$char['name']} 队伍聊天: {$message}");

    return [
        'success' => true,
        'type' => 'team',
        'output' => $talkMsg,
        'broadcast' => true,
        'channel' => 'team'
    ];
}

// ========== 辅助方法 ==========

/**
 * 获取队伍成员列表（根据自己的角色ID查找所属队伍）
 */
function getTeamMembers(int $charId): array {
    $record = getTeamRecord($charId);
    if (!$record) {
        return [];
    }

    return getTeamMembersByLeader($record['leader_id']);
}

/**
 * 根据队长ID获取队伍所有已加入成员
 */
function getTeamMembersByLeader(int $leaderId): array {
    $sql = "SELECT ct.*, c.name, c.online, c.current_room
            FROM character_teams ct
            JOIN characters c ON ct.member_id = c.id
            WHERE ct.leader_id = ? AND ct.status = 'joined'
            ORDER BY ct.created_at ASC";
    return Database::queryAll($sql, [$leaderId]);
}

/**
 * 获取角色的队伍记录（已加入状态）
 */
function getTeamRecord(int $charId): ?array {
    return Database::queryOne(
        "SELECT * FROM character_teams WHERE member_id = ? AND status = 'joined' LIMIT 1",
        [$charId]
    );
}

/**
 * 检查是否为队长
 */
function isTeamLeader(int $charId): bool {
    $record = getTeamRecord($charId);
    return $record && $record['leader_id'] == $charId;
}

/**
 * 向队伍成员发送消息（排除指定角色）
 */
function notifyTeamMembers(int $leaderId, string $message, int $excludeCharId = 0): void {
    $members = getTeamMembersByLeader($leaderId);
    foreach ($members as $member) {
        if ($member['id'] != $excludeCharId && $member['online']) {
            MessageDaemon::sendToPlayer($member['id'], $message, 'team');
        }
    }
}

/**
 * 队伍命令帮助信息
 */
function cmd_team_help(): string {
    return HTML_HIWHT . '队伍指令使用方法：' . HTML_NOR . "\n\n"
        . HTML_HIYEL . 'team' . HTML_NOR . '                - 显示当前队伍成员' . "\n"
        . HTML_HIYEL . 'team list' . HTML_NOR . '           - 显示当前队伍成员' . "\n"
        . HTML_HIYEL . 'team with <玩家名>' . HTML_NOR . '  - 邀请玩家加入队伍（需要对方接受）' . "\n"
        . HTML_HIYEL . 'team accept' . HTML_NOR . '        - 接受组队邀请' . "\n"
        . HTML_HIYEL . 'team reject' . HTML_NOR . '        - 拒绝组队邀请' . "\n"
        . HTML_HIYEL . 'team dismiss' . HTML_NOR . '       - 解散队伍（仅队长）' . "\n"
        . HTML_HIYEL . 'team leave' . HTML_NOR . '         - 离开当前队伍' . "\n"
        . HTML_HIYEL . 'team talk <消息>' . HTML_NOR . '   - 向队伍成员发送消息' . "\n\n"
        . '注：每个队伍最多 ' . TEAM_MAX_MEMBERS . ' 人，邀请 ' . TEAM_INVITE_TIMEOUT . ' 秒内有效。';
}
