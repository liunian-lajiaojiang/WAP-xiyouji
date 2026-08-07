<?php
/**
 * 放弃技能命令 (abandon)
 * 参考 xyj2000-php/cmds/std/Abandon.php 重构
 * xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 功能：放弃一项已学习的技能
 * 用法：
 *   abandon <技能ID>                  — 完全删除技能（从0开始）
 *   abandon <技能ID> level=<等级数>    — 放弃指定等级（等级>0则降低，=0则完全删除）
 * 
 * 黄风捕获概率：1/8000
 */

require_once __DIR__ . '/../helpers/SkillManager.php';

function cmd_abandon(int $charId, string $param = ''): array {
    $me = CharacterModel::find($charId);
    
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 检查是否昏迷
    if (isset($_SESSION["unconscious_{$charId}"])) {
        $unconscious = $_SESSION["unconscious_{$charId}"];
        $elapsed = time() - $unconscious['timestamp'];
        $duration = $unconscious['duration'] ?? 30;
        if ($elapsed < $duration) {
            return [
                'success' => false,
                'message' => HTML_HIRED . '你昏迷中，无法做任何事！' . HTML_NOR,
                'skip_queue' => true,
            ];
        }
        unset($_SESSION["unconscious_{$charId}"]);
        Database::execute('UPDATE characters SET unconscious_state = 0, unconscious_end_time = 0 WHERE id = ?', [$charId]);
    }
    
    // 检查忙碌状态
    if (is_player_busy($charId)) {
        return ['success' => false, 'message' => '你现在正忙着呢。'];
    }
    
    // 检查战斗中
    if (CombatDaemon::isInCombat($charId)) {
        return ['success' => false, 'message' => '战斗中无法放弃技能。'];
    }
    
    // 参数不能为空
    if (empty($param)) {
        return ['success' => false, 'message' => '你要放弃哪一项技能？'];
    }
    
    // 解析参数：支持 "skill_id level=N" 格式
    $abandonLevel = 0; // 0 = 完全删除
    $skillParam = $param;
    if (preg_match('/^(.+?)\s+level\s*=\s*(\d+)$/i', $param, $matches)) {
        $skillParam = trim($matches[1]);
        $abandonLevel = intval($matches[2]);
    }
    
    // 也从 GET/POST 中读取 level 参数（来自页面表单）
    $getLevel = $_GET['level'] ?? $_POST['level'] ?? null;
    if ($getLevel !== null && $getLevel !== '') {
        $abandonLevel = intval($getLevel);
    }
    
    // 查找技能（支持 skill_id 或中文名称）
    $skillId = resolveSkillId($charId, trim($skillParam));
    if (!$skillId) {
        return ['success' => false, 'message' => '你并没有学过这项技能。'];
    }
    
    // 获取技能等级
    $skillLevel = SkillManager::getSkillLevel($charId, $skillId);
    $skillChinese = SkillManager::getSkillChineseName($skillId);
    
    // 如果指定了放弃等级数，验证合法性
    if ($abandonLevel > 0) {
        if ($abandonLevel >= $skillLevel) {
            // 放弃等级 >= 当前等级，等同于完全删除
            $abandonLevel = 0;
        }
    }
    
    // 等级 >= 10 时需要二次确认
    $needConfirm = ($abandonLevel == 0 && $skillLevel >= 10) || ($abandonLevel > 0 && $abandonLevel >= 10);
    if ($needConfirm) {
        // 检查是否是确认操作
        $confirm = $_GET['confirm'] ?? $_POST['confirm'] ?? '';
        if (strtolower($confirm) !== 'yes' && strtolower($confirm) !== 'y') {
            // 存储待确认信息到 session
            $_SESSION["abandon_confirm_{$charId}"] = [
                'skill_id' => $skillId,
                'skill_name' => $skillChinese,
                'skill_level' => $skillLevel,
                'abandon_level' => $abandonLevel,
            ];
            
            if ($abandonLevel > 0) {
                $msg = "你确定要放弃「{$skillChinese}」的 {$abandonLevel} 级（当前等级{$skillLevel}，将降至" . ($skillLevel - $abandonLevel) . "）？此操作不可撤销！\n\n输入 abandon {$skillId} level={$abandonLevel} confirm=yes 确认放弃。";
            } else {
                $msg = "你确定要放弃「{$skillChinese}」(等级{$skillLevel})？此操作不可撤销！\n\n输入 abandon {$skillId} confirm=yes 确认放弃。";
            }
            return [
                'success' => true,
                'message' => $msg,
                'confirm_required' => true,
                'skill_id' => $skillId,
                'skill_name' => $skillChinese,
                'skill_level' => $skillLevel,
                'abandon_level' => $abandonLevel,
            ];
        }
    }
    
    // 清除确认状态
    unset($_SESSION["abandon_confirm_{$charId}"]);
    
    if ($abandonLevel > 0) {
        // 部分放弃：降低等级
        $newLevel = $skillLevel - $abandonLevel;
        Database::execute(
            "UPDATE character_skills SET level = ?, exp = 0 WHERE char_id = ? AND skill_id = ?",
            [$newLevel, $charId, $skillId]
        );
        $message = "你决定放弃「{$skillChinese}」的 {$abandonLevel} 级（从{$skillLevel}级降至{$newLevel}级）。";
    } else {
        // 完全放弃：删除记录
        $deleted = Database::execute(
            "DELETE FROM character_skills WHERE char_id = ? AND skill_id = ?",
            [$charId, $skillId]
        );
        
        if (!$deleted) {
            return ['success' => false, 'message' => '你没有学过这项技能。'];
        }
        
        $message = "你决定放弃继续学习「{$skillChinese}」。";
    }
    
    // 黄风捕获：放弃技能时 1/8000 概率触发
    // 参考 xyj2000-php/cmds/std/Abandon.php 第55行: MISC_D->random_capture($me, 8000, 0)
    require_once __DIR__ . '/../daemons/Miscd.php';
    $captured = Miscd::randomCapture($charId, 8000);
    if ($captured) {
        $captureMsg = Miscd::getCaptureMessage($charId);
        return [
            'success' => true,
            'message' => $message . "\n" . ($captureMsg ?? '你被一阵黄风卷走了！'),
            'redirect' => 'room.php?area=qujing&room=baihuling/jail',
        ];
    }
    
    return [
        'success' => true,
        'message' => $message,
    ];
}

/**
 * 解析技能ID（支持 skill_id 或中文名称）
 * @param int $charId 角色ID
 * @param string $input 用户输入（skill_id 或中文名）
 * @return string|null 找到的 skill_id，未找到返回 null
 */
function resolveSkillId(int $charId, string $input): ?string {
    $allSkills = SkillManager::getAllSkills($charId);
    
    // 先尝试精确匹配 skill_id
    foreach ($allSkills as $skill) {
        if (strtolower($skill['skill_id']) === strtolower($input)) {
            return $skill['skill_id'];
        }
    }
    
    // 再尝试模糊匹配 skill_id
    foreach ($allSkills as $skill) {
        if (stripos($skill['skill_id'], $input) !== false) {
            return $skill['skill_id'];
        }
    }
    
    // 尝试匹配中文名称
    foreach ($allSkills as $skill) {
        $chineseName = SkillManager::getSkillChineseName($skill['skill_id']);
        if (mb_stripos($chineseName, $input) !== false) {
            return $skill['skill_id'];
        }
    }
    
    // 尝试匹配英文名称
    foreach ($allSkills as $skill) {
        $engName = $skill['name'] ?? '';
        if (stripos($engName, $input) !== false) {
            return $skill['skill_id'];
        }
    }
    
    return null;
}
