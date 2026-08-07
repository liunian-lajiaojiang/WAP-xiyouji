<?php
/**
 * 设置技能命令 (setskill)
 *
 * 用法: setskill <角色名> <技能ID> <等级>
 *       setskill <角色名> <技能ID> remove   -- 移除技能
 *       setskill list <角色名>              -- 列出角色已有技能
 * 权限: arch (等级5) 及以上
 */

require_once __DIR__ . '/../helpers/WizardHelper.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/User.php';

/**
 * 执行 setskill 命令
 * @param int $charId 操作者角色ID
 * @param string $param 参数
 * @return array
 */
function cmd_setskill(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    $userId = intval($char['user_id']);
    $parts = preg_split('/\s+/', trim($param));
    
    if (count($parts) < 2) {
        return ['success' => false, 'message' => "用法:\n  setskill <角色名> <技能ID> <等级>\n  setskill <角色名> <技能ID> remove\n  setskill list <角色名>"];
    }

    if (strtolower($parts[0]) === 'list') {
        if (!WizardHelper::canUseCommand($userId, 'where')) {
            return ['success' => false, 'message' => '你的巫师等级不够，无法使用此命令。'];
        }
        
        $targetName = $parts[1];
        $targetChar = CharacterModel::findByName($targetName);

        // 角色名找不到时，尝试按用户名查找
        if (!$targetChar) {
            $targetUser = UserModel::findByUsername($targetName);
            if ($targetUser) {
                $targetChar = CharacterModel::getByUserId($targetUser['id']);
            }
        }

        if (!$targetChar) {
            return ['success' => false, 'message' => "找不到角色: {$targetName}"];
        }
        $skills = Database::queryAll(
            "SELECT cs.skill_id, s.name, cs.level FROM character_skills cs LEFT JOIN skills s ON cs.skill_id = s.id WHERE cs.char_id = ? ORDER BY s.name",
            [$targetChar['id']]
        );
        $msg = "{$targetName} 的技能列表:\n";
        if (empty($skills)) {
            $msg .= "  (无技能)";
        } else {
            foreach ($skills as $s) {
                $msg .= "  ID:{$s['skill_id']} {$s['name']} - 等级 {$s['level']}\n";
            }
        }
        return ['success' => true, 'message' => rtrim($msg)];
    }

    if (!WizardHelper::canUseCommand($userId, 'setskill')) {
        return ['success' => false, 'message' => '你的巫师等级不够，无法使用 setskill 命令。需要大巫师(arch)以上权限。'];
    }

    // 设置/移除技能: setskill <角色名> <技能ID> <等级|remove>
    if (count($parts) < 3) {
        return ['success' => false, 'message' => '用法: setskill <角色名> <技能ID> <等级|remove>'];
    }

    $targetName = $parts[0];
    $skillId    = intval($parts[1]);
    $action     = strtolower($parts[2]);

    $targetChar = CharacterModel::findByName($targetName);

    // 角色名找不到时，尝试按用户名查找
    if (!$targetChar) {
        $targetUser = UserModel::findByUsername($targetName);
        if ($targetUser) {
            $targetChar = CharacterModel::getByUserId($targetUser['id']);
        }
    }

    if (!$targetChar) {
        return ['success' => false, 'message' => "找不到角色: {$targetName}"];
    }

    // 验证技能存在
    $skill = Database::queryOne("SELECT id, name FROM skills WHERE id = ?", [$skillId]);
    if (!$skill) {
        return ['success' => false, 'message' => "找不到技能ID: {$skillId}"];
    }

    // 移除
    if ($action === 'remove') {
        $affected = Database::execute(
            "DELETE FROM character_skills WHERE char_id = ? AND skill_id = ?",
            [$targetChar['id'], $skillId]
        );
        log_game('SETSKILL', "巫师 {$char['name']} 移除了 {$targetName} 的技能 {$skill['name']}(ID:{$skillId})");
        return ['success' => true, 'message' => $affected > 0
            ? "已移除 {$targetName} 的技能: {$skill['name']}"
            : "{$targetName} 并未学会技能 {$skill['name']}"];
    }

    // 设置等级
    $level = intval($action);
    if ($level < 0) $level = 0;
    if ($level > 100) $level = 100;

    $existing = Database::queryOne(
        "SELECT id FROM character_skills WHERE char_id = ? AND skill_id = ?",
        [$targetChar['id'], $skillId]
    );

    if ($existing) {
        Database::execute(
            "UPDATE character_skills SET level = ? WHERE char_id = ? AND skill_id = ?",
            [$level, $targetChar['id'], $skillId]
        );
    } else {
        Database::execute(
            "INSERT INTO character_skills (char_id, skill_id, level) VALUES (?, ?, ?)",
            [$targetChar['id'], $skillId, $level]
        );
    }

    log_game('SETSKILL', "巫师 {$char['name']} 设置 {$targetName} 的技能 {$skill['name']}(ID:{$skillId}) 等级为 {$level}");
    return ['success' => true, 'message' => "已设置 {$targetName} 的技能 {$skill['name']} 等级为 {$level}。"];
}
