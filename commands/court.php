<?php
/**
 * court 命令 - 公堂审判系统
 * 
 * 用法:
 *   court arrest <用户名> <原因>    - 逮捕嫌疑人
 *   court try <嫌疑人ID>            - 开始审理案件
 *   court verdict <案件ID> <类型> [天数] [说明] - 宣判
 *   court list                      - 查看待审案件
 *   court history [用户名]          - 查看审判历史
 *   court release <嫌疑人ID>        - 释放嫌疑人
 * 
 * 权限: arch (等级5) 及以上
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../helpers/WizardHelper.php';
require_once __DIR__ . '/../helpers/BanHelper.php';
require_once MODEL_PATH . 'User.php';
require_once MODEL_PATH . 'Character.php';

/**
 * court 命令入口
 * @param int $charId 执行者角色ID
 * @param string $param 参数字符串
 * @return array
 */
function cmd_court(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    $userId = intval($char['user_id']);
    
    // 权限检查
    if (!WizardHelper::canUseCommand($userId, 'court')) {
        return ['success' => false, 'message' => '你的巫师等级不够，无法使用公堂命令。需要大巫师(arch)以上权限。'];
    }
    
    $parts = preg_split('/\s+/', trim($param), -1, PREG_SPLIT_NO_EMPTY);
    $action = $parts[0] ?? '';
    
    switch ($action) {
        case 'arrest':
            return courtHandleArrest($userId, $parts);
        case 'try':
            return courtHandleTry($userId, $parts);
        case 'verdict':
            return courtHandleVerdict($userId, $parts);
        case 'list':
            return courtHandleList();
        case 'history':
            return courtHandleHistory($parts);
        case 'release':
            return courtHandleRelease($userId, $parts);
        default:
            return courtShowHelp();
    }
}

/**
 * 显示帮助信息
 */
function courtShowHelp(): array {
    $help = "公堂命令用法:\n";
    $help .= "  court arrest <用户名> <原因>              - 逮捕嫌疑人\n";
    $help .= "  court try <嫌疑人ID>                      - 开始审理案件\n";
    $help .= "  court verdict <案件ID> <类型> [天数] [说明] - 宣判\n";
    $help .= "  court list                                - 查看待审案件\n";
    $help .= "  court history [用户名]                    - 查看审判历史\n";
    $help .= "  court release <嫌疑人ID>                  - 释放嫌疑人\n";
    $help .= "\n判决类型: 1-无罪释放 2-警告 3-监禁 4-封禁";
    return ['success' => true, 'message' => $help];
}

/**
 * 逮捕嫌疑人
 * 参数: arrest username reason_words...
 */
function courtHandleArrest(int $userId, array $parts): array {
    // parts: [arrest, username, reason...]
    if (count($parts) < 3) {
        return ['success' => false, 'message' => '用法: court arrest <用户名> <原因>'];
    }
    
    $username = $parts[1];
    $reason = implode(' ', array_slice($parts, 2));
    
    // 查找目标用户
    $targetUser = UserModel::findByUsername($username);
    if (!$targetUser) {
        return ['success' => false, 'message' => "找不到用户: {$username}"];
    }
    
    // 检查是否可以操作
    if (!WizardHelper::canOperateOn($userId, $targetUser['id'])) {
        return ['success' => false, 'message' => '你没有权限逮捕这个用户。'];
    }
    
    // 获取角色
    $char = CharacterModel::getByUserId($targetUser['id']);
    if (!$char) {
        return ['success' => false, 'message' => "用户 {$username} 没有在线角色。"];
    }
    
    // 检查是否已在嫌疑人列表
    $existing = Database::queryOne(
        "SELECT id FROM court_suspects WHERE user_id = ? AND status IN (1, 2)", 
        [$targetUser['id']]
    );
    if ($existing) {
        return ['success' => false, 'message' => "{$username} 已经在嫌疑人列表中。"];
    }
    
    // 添加到嫌疑人列表
    $sql = "INSERT INTO court_suspects (user_id, char_id, arrested_by, reason) VALUES (?, ?, ?, ?)";
    Database::execute($sql, [$targetUser['id'], $char['id'], $userId, $reason]);
    
    // 移动到公堂
    CharacterModel::updatePosition($char['id'], 'wiz', 'wiz/gongtang');
    
    // 发送消息
    $arrestUser = UserModel::find($userId);
    $arrestName = $arrestUser['username'] ?? '巫师';
    sendCourtMessage($char['id'], "[公堂] 你已被 {$arrestName} 逮捕！原因: {$reason}。请等待巫师审理。");
    
    log_game('COURT', "巫师 {$arrestName} 逮捕了 {$username}，原因: {$reason}");
    
    return ['success' => true, 'message' => "已逮捕 {$username}，原因: {$reason}。嫌疑人已被带往公堂。"];
}

/**
 * 开始审理案件
 * 参数: try suspectId
 */
function courtHandleTry(int $userId, array $parts): array {
    if (count($parts) < 2) {
        return ['success' => false, 'message' => '用法: court try <嫌疑人ID>'];
    }
    
    $suspectId = intval($parts[1]);
    
    // 查找嫌疑人
    $suspect = Database::queryOne(
        "SELECT cs.*, u.username, c.name as char_name 
         FROM court_suspects cs 
         LEFT JOIN users u ON cs.user_id = u.id 
         LEFT JOIN characters c ON cs.char_id = c.id 
         WHERE cs.id = ? AND cs.status = 1", 
        [$suspectId]
    );
    
    if (!$suspect) {
        return ['success' => false, 'message' => '找不到该嫌疑人或已不在待审状态。'];
    }
    
    // 创建案件
    $sql = "INSERT INTO court_cases (defendant_id, judge_id, charge, status) VALUES (?, ?, ?, 2)";
    Database::execute($sql, [$suspect['user_id'], $userId, $suspect['reason']]);
    $caseId = Database::lastInsertId();
    
    // 更新嫌疑人状态
    Database::execute("UPDATE court_suspects SET status = 2 WHERE id = ?", [$suspectId]);
    
    // 通知被告
    sendCourtMessage($suspect['char_id'], "[公堂] 你的案件 #{$caseId} 已开始审理。罪名: {$suspect['reason']}。请等待宣判。");
    
    return [
        'success' => true, 
        'message' => "开始审理案件 #{$caseId}\n被告: {$suspect['username']} ({$suspect['char_name']})\n罪名: {$suspect['reason']}\n\n请使用 court verdict {$caseId} <判决类型> [天数] [说明] 来宣判。"
    ];
}

/**
 * 宣判
 * 参数: verdict caseId verdictType [days] [notes...]
 */
function courtHandleVerdict(int $userId, array $parts): array {
    if (count($parts) < 3) {
        return ['success' => false, 'message' => '用法: court verdict <案件ID> <判决类型> [天数] [说明]'];
    }
    
    $caseId = intval($parts[1]);
    $verdict = intval($parts[2]);
    $days = isset($parts[3]) ? intval($parts[3]) : 0;
    $notes = isset($parts[4]) ? implode(' ', array_slice($parts, 4)) : '';
    
    // 判决类型验证
    if ($verdict < 1 || $verdict > 4) {
        return ['success' => false, 'message' => '判决类型无效。1-无罪释放 2-警告 3-监禁 4-封禁'];
    }
    
    // 查找案件
    $case = Database::queryOne(
        "SELECT * FROM court_cases WHERE id = ? AND status = 2", 
        [$caseId]
    );
    
    if (!$case) {
        return ['success' => false, 'message' => '找不到该案件或已不在审理状态。'];
    }
    
    // 更新案件
    $sql = "UPDATE court_cases SET verdict = ?, sentence_days = ?, sentence_notes = ?, status = 3, judged_at = NOW() WHERE id = ?";
    Database::execute($sql, [$verdict, $days, $notes, $caseId]);
    
    // 执行判决
    $result = courtExecuteVerdict($case['defendant_id'], $verdict, $days);
    
    // 更新案件状态
    Database::execute("UPDATE court_cases SET status = 4, executed_at = NOW() WHERE id = ?", [$caseId]);
    
    $verdictNames = [1 => '无罪释放', 2 => '警告', 3 => '监禁', 4 => '封禁'];
    $verdictName = $verdictNames[$verdict] ?? '未知';
    
    log_game('COURT', "案件 #{$caseId} 宣判: {$verdictName}" . ($days > 0 ? " {$days}天" : ''));
    
    return [
        'success' => true, 
        'message' => "案件 #{$caseId} 已判决。\n判决: {$verdictName}" . 
                     ($days > 0 ? " ({$days}天)" : '') . 
                     ($notes ? "\n说明: {$notes}" : '') . 
                     "\n{$result}"
    ];
}

/**
 * 执行判决
 */
function courtExecuteVerdict(int $defendantId, int $verdict, int $days): string {
    $char = CharacterModel::getByUserId($defendantId);
    
    switch ($verdict) {
        case 1: // 无罪释放
            if ($char) {
                CharacterModel::updatePosition($char['id'], 'city', 'city/kezhan');
                sendCourtMessage($char['id'], '[公堂] 你已被无罪释放，已移回客栈。');
            }
            Database::execute("UPDATE court_suspects SET status = 3 WHERE user_id = ? AND status IN (1, 2)", [$defendantId]);
            return '被告已被无罪释放。';
            
        case 2: // 警告
            if ($char) {
                CharacterModel::updatePosition($char['id'], 'city', 'city/kezhan');
                sendCourtMessage($char['id'], '[公堂] 你已被警告并释放，请遵守规则。已移回客栈。');
            }
            Database::execute("UPDATE court_suspects SET status = 3 WHERE user_id = ? AND status IN (1, 2)", [$defendantId]);
            return '已对被告发出警告并释放。';
            
        case 3: // 监禁
            if ($days <= 0) $days = 3;
            BanHelper::imprisonUser($defendantId, $days);
            if ($char) {
                sendCourtMessage($char['id'], "[公堂] 你已被判监禁 {$days} 天。");
            }
            Database::execute("UPDATE court_suspects SET status = 4 WHERE user_id = ? AND status IN (1, 2)", [$defendantId]);
            return "被告已被监禁 {$days} 天。";
            
        case 4: // 封禁
            BanHelper::banUser($defendantId);
            if ($char) {
                sendCourtMessage($char['id'], '[公堂] 你已被封禁。');
            }
            Database::execute("UPDATE court_suspects SET status = 4 WHERE user_id = ? AND status IN (1, 2)", [$defendantId]);
            return '被告已被封禁。';
            
        default:
            return '未知判决类型。';
    }
}

/**
 * 发送公堂消息给玩家
 */
function sendCourtMessage(int $charId, string $message): void {
    $sql = "INSERT INTO message_queue (char_id, message, type, from_char_id) VALUES (?, ?, 'private', 0)";
    Database::execute($sql, [$charId, $message]);
}

/**
 * 查看待审案件
 */
function courtHandleList(): array {
    $suspects = Database::queryAll(
        "SELECT cs.*, u.username, c.name as char_name, u2.username as arrested_by_name
         FROM court_suspects cs 
         LEFT JOIN users u ON cs.user_id = u.id 
         LEFT JOIN characters c ON cs.char_id = c.id 
         LEFT JOIN users u2 ON cs.arrested_by = u2.id 
         WHERE cs.status = 1 
         ORDER BY cs.arrest_time DESC"
    );
    
    $cases = Database::queryAll(
        "SELECT cc.*, u.username as defendant_name, u2.username as judge_name
         FROM court_cases cc 
         LEFT JOIN users u ON cc.defendant_id = u.id 
         LEFT JOIN users u2 ON cc.judge_id = u2.id 
         WHERE cc.status = 2 
         ORDER BY cc.created_at DESC"
    );
    
    $message = "【待审嫌疑人】\n";
    if (empty($suspects)) {
        $message .= "  无\n";
    } else {
        foreach ($suspects as $s) {
            $message .= "  ID:{$s['id']} {$s['username']} ({$s['char_name']}) - {$s['reason']}\n";
            $message .= "    逮捕者: {$s['arrested_by_name']} 时间: {$s['arrest_time']}\n";
        }
    }
    
    $message .= "\n【审理中的案件】\n";
    if (empty($cases)) {
        $message .= "  无\n";
    } else {
        foreach ($cases as $c) {
            $message .= "  案件#{$c['id']} 被告:{$c['defendant_name']} 审判官:{$c['judge_name']}\n";
            $message .= "    罪名: {$c['charge']} 创建时间: {$c['created_at']}\n";
        }
    }
    
    return ['success' => true, 'message' => $message];
}

/**
 * 查看审判历史
 */
function courtHandleHistory(array $parts): array {
    $username = $parts[1] ?? null;
    
    $sql = "SELECT cc.*, u.username as defendant_name, u2.username as judge_name
            FROM court_cases cc 
            LEFT JOIN users u ON cc.defendant_id = u.id 
            LEFT JOIN users u2 ON cc.judge_id = u2.id 
            WHERE cc.status = 3";
    
    $queryParams = [];
    if ($username) {
        $sql .= " AND u.username = ?";
        $queryParams[] = $username;
    }
    
    $sql .= " ORDER BY cc.judged_at DESC LIMIT 20";
    
    $cases = Database::queryAll($sql, $queryParams) ?: [];
    
    $verdictNames = [1 => '无罪释放', 2 => '警告', 3 => '监禁', 4 => '封禁'];
    
    $message = "【审判历史" . ($username ? " - {$username}" : '') . "】\n";
    if (empty($cases)) {
        $message .= "  无记录\n";
    } else {
        foreach ($cases as $c) {
            $verdict = $verdictNames[$c['verdict']] ?? '未知';
            $message .= "  案件#{$c['id']} 被告:{$c['defendant_name']} 审判官:{$c['judge_name']}\n";
            $message .= "    罪名: {$c['charge']}\n";
            $message .= "    判决: {$verdict}" . ($c['sentence_days'] > 0 ? " ({$c['sentence_days']}天)" : '') . "\n";
            $message .= "    判决时间: {$c['judged_at']}\n";
        }
    }
    
    return ['success' => true, 'message' => $message];
}

/**
 * 释放嫌疑人
 * 参数: release suspectId
 */
function courtHandleRelease(int $userId, array $parts): array {
    if (count($parts) < 2) {
        return ['success' => false, 'message' => '用法: court release <嫌疑人ID>'];
    }
    
    $suspectId = intval($parts[1]);
    
    $suspect = Database::queryOne(
        "SELECT cs.*, u.username 
         FROM court_suspects cs 
         LEFT JOIN users u ON cs.user_id = u.id 
         WHERE cs.id = ? AND cs.status IN (1, 2)", 
        [$suspectId]
    );
    
    if (!$suspect) {
        return ['success' => false, 'message' => '找不到该嫌疑人或已不在待审状态。'];
    }
    
    Database::execute("UPDATE court_suspects SET status = 3 WHERE id = ?", [$suspectId]);
    
    $char = CharacterModel::getByUserId($suspect['user_id']);
    if ($char) {
        CharacterModel::updatePosition($char['id'], 'city', 'city/kezhan');
        sendCourtMessage($char['id'], '[公堂] 你已被释放，已移回客栈。');
    }
    
    return ['success' => true, 'message' => "已释放嫌疑人 {$suspect['username']}。"];
}
