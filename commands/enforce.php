<?php
/**
 * 内力加力命令 (enforce / jiali)
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 功能：设置内力系数（force_factor），影响战斗中的额外伤害
 * force_factor 越高，攻击时附加的内力伤害越大，但消耗内力也越多
 * 
 * 用法：
 *   enforce          # 查看当前内力加力状态
 *   enforce 50       # 设置内力加力为50
 *   enforce 0        # 取消内力加力
 */

require_once HELPER_PATH . 'SkillManager.php';

function cmd_enforce(int $charId, string $param = ''): array {
    $me = CharacterModel::find($charId);
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    $arg = trim($param);

    // 无参数时显示当前状态
    if (empty($arg)) {
        return enforceStatus($charId, $me);
    }

    // 解析加力数值
    if (!is_numeric($arg)) {
        return ['success' => false, 'message' => '请指定加力数值（0-100）。用法：enforce <数值>'];
    }

    $newValue = intval($arg);
    if ($newValue < 0) {
        return ['success' => false, 'message' => '加力数值不能为负数。'];
    }

    // 查询已启用的内功
    $mappedForce = SkillManager::querySkillMapped($charId, 'force');
    if (empty($mappedForce)) {
        return ['success' => false, 'message' => '你必须先用 enable force 选择你要用的内功心法。'];
    }

    // 获取内功等级
    $forceLevel = SkillManager::getSkillLevel($charId, $mappedForce);
    if ($forceLevel < 1) {
        return ['success' => false, 'message' => '你还没有学会内功心法。'];
    }

    // 计算最大可加力值 = 内功等级 / 2 + 内力系数基础值
    // 参考原始项目：max_enforce = force_skill / 2
    $maxEnforce = intval($forceLevel / 2);

    if ($newValue > $maxEnforce) {
        return [
            'success' => false,
            'message' => '你的内功修为不够，最多只能加力 ' . $maxEnforce . ' 点。'
        ];
    }

    // 获取当前内力
    $currentForce = intval($me['force'] ?? 0);
    $maxForce = intval($me['max_force'] ?? 0);

    // 检查内力是否足够支持新的加力值
    // 原始项目：需要内力 >= force_factor * 2
    $requiredForce = $newValue * 2;
    if ($currentForce < $requiredForce && $newValue > 0) {
        return [
            'success' => false,
            'message' => '你的内力不足，至少需要 ' . $requiredForce . ' 点内力才能加力 ' . $newValue . ' 点。'
        ];
    }

    // 更新数据库中的 force_factor
    $oldValue = intval($me['force_factor'] ?? 0);
    Database::execute(
        "UPDATE characters SET force_factor = ? WHERE id = ?",
        [$newValue, $charId]
    );

    // 构建消息
    $output = [];
    if ($newValue === 0) {
        $output[] = HTML_HICYN . '你收回了内力加力。' . HTML_NOR;
    } else {
        $output[] = HTML_HICYN . '你将内力加力设置为 ' . $newValue . ' 点。' . HTML_NOR;
        $output[] = '攻击时将附加 ' . $newValue . ' 点内力伤害，每回合消耗内力 ' . ($newValue * 2) . ' 点。';
    }

    if ($oldValue !== $newValue) {
        $output[] = '（之前：' . $oldValue . ' -> 现在：' . $newValue . '）';
    }

    return [
        'success' => true,
        'message' => implode("\n", $output),
        'skip_queue' => true,
        'force_factor' => $newValue,
    ];
}

/**
 * 显示当前内力加力状态
 */
function enforceStatus(int $charId, array $me): array {
    $forceFactor = intval($me['force_factor'] ?? 0);
    $currentForce = intval($me['force'] ?? 0);
    $maxForce = intval($me['max_force'] ?? 0);

    // 查询已启用的内功
    $mappedForce = SkillManager::querySkillMapped($charId, 'force');
    $forceLevel = 0;
    $maxEnforce = 0;
    if (!empty($mappedForce)) {
        $forceLevel = SkillManager::getSkillLevel($charId, $mappedForce);
        $maxEnforce = intval($forceLevel / 2);
    }

    $output = [];
    $output[] = HTML_HIYEL . '【内力加力状态】' . HTML_NOR;
    $output[] = '当前加力：' . HTML_HICYN . $forceFactor . HTML_NOR . ' 点';
    $output[] = '最大可加力：' . $maxEnforce . ' 点（内功等级 ' . $forceLevel . ' / 2）';
    $output[] = '当前内力：' . $currentForce . ' / ' . $maxForce;
    if ($forceFactor > 0) {
        $output[] = '每回合消耗内力：' . ($forceFactor * 2) . ' 点';
        $output[] = '攻击附加伤害：' . $forceFactor . ' 点';
    }
    $output[] = '';
    $output[] = '用法：enforce <数值>（0-' . $maxEnforce . '）';

    return [
        'success' => false,
        'message' => implode("\n", $output)
    ];
}
