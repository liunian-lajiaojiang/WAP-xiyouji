<?php
/**
 * 门派信息命令 (family)
 * 
 * 用法:
 *   family          - 显示自己的门派信息（门派名、等级、师父、入门时间）
 *   family tree     - 显示师徒谱系
 *   family info     - 显示门派详细介绍
 *   family members  - 显示门派成员列表
 * 
 * 别名: 门派 | menpai
 */

require_once DAEMON_PATH . 'ApprenticeHandler.php';
require_once HELPER_PATH . 'SectHelper.php';

function cmd_family(int $charId, string $param = ''): array {
    $me = CharacterModel::find($charId);
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在。'];
    }

    $subCmd = strtolower(trim($param));

    // -------------------------------------------------------
    // family tree - 显示师徒谱系
    // -------------------------------------------------------
    if ($subCmd === 'tree') {
        $treeResult = ApprenticeHandler::getApprenticeTree($charId);
        if (!$treeResult['success']) {
            return ['success' => false, 'message' => $treeResult['message']];
        }

        $data         = $treeResult['data'] ?? [];
        $self         = $data['self'] ?? [];
        $masterChain  = $data['master_chain'] ?? [];
        $apprentices  = $data['apprentices'] ?? [];

        $lines = [];
        $lines[] = '=== 师徒谱系 ===';

        // 祖师链（向上追溯）
        if (!empty($masterChain)) {
            $lines[] = '【祖师链】';
            foreach ($masterChain as $idx => $master) {
                $prefix = str_repeat('  ', $idx) . '┣━ ';
                $lines[] = $prefix . $master['name'] . '（第' . $master['generation'] . '代）';
            }
        }

        // 自己
        $selfPrefix = str_repeat('  ', count($masterChain)) . '┗━ ';
        $sectInfo   = SectHelper::getCharacterSect($charId);
        $sectRank   = $sectInfo['sect_rank'] ?? '弟子';
        $lines[] = $selfPrefix . '【你】' . $me['name'] . '（' . $sectRank . '，第' . ($self['generation'] ?? 0) . '代）';

        // 弟子树（递归显示徒孙）
        if (!empty($apprentices)) {
            $lines[] = str_repeat('  ', count($masterChain) + 1) . '【弟子】';
            _family_render_apprentice_tree($apprentices, count($masterChain) + 2, $lines);
        } else {
            $lines[] = str_repeat('  ', count($masterChain) + 1) . '（暂无弟子）';
        }

        return ['success' => true, 'message' => implode("\n", $lines)];
    }

    // -------------------------------------------------------
    // family info - 显示门派详细介绍
    // -------------------------------------------------------
    if ($subCmd === 'info') {
        if (empty($me['family'])) {
            return ['success' => false, 'message' => '你目前不属于任何门派。'];
        }

        $sectConf = SectHelper::getSectConfig($me['family']);
        if (!$sectConf) {
            return ['success' => false, 'message' => '无法获取门派信息。'];
        }

        $allSects = SectHelper::getAllSects();
        $sectData = null;
        foreach ($allSects as $s) {
            if ($s['key'] === $me['family']) {
                $sectData = $s;
                break;
            }
        }

        $lines   = [];
        $lines[] = '=== 门派详情：' . ($sectConf['name'] ?? $me['family']) . ' ===';

        if (!empty($sectConf['description'])) {
            $lines[] = $sectConf['description'];
        }

        $lines[] = '';
        $lines[] = '门派简称：' . ($sectConf['short_name'] ?? '无');
        $lines[] = '阵营：'     . _family_alignment_name($sectConf['alignment'] ?? 0);

        if (!empty($sectConf['location'])) {
            $lines[] = '所在地：' . $sectConf['location'];
        }

        if (!empty($sectConf['master_npc'])) {
            $lines[] = '掌门：' . $sectConf['master_npc'];
        }

        if (!empty($sectConf['ranks'])) {
            $lines[] = '门内等级（低→高）：' . implode(' → ', $sectConf['ranks']);
        }

        // 门派专属技能
        $sectSkills = SectHelper::getSectSkills($me['family']);
        $exclusive  = $sectSkills['exclusive'] ?? [];
        if (!empty($exclusive)) {
            $skillNames = array_values($exclusive);
            $lines[] = '专属技能：' . implode('、', $skillNames);
        }

        return ['success' => true, 'message' => implode("\n", $lines)];
    }

    // -------------------------------------------------------
    // family members - 显示门派成员列表
    // -------------------------------------------------------
    if ($subCmd === 'members') {
        if (empty($me['family'])) {
            return ['success' => false, 'message' => '你目前不属于任何门派。'];
        }

        $membersResult = ApprenticeHandler::getSectMembers($me['family']);
        if (!$membersResult['success']) {
            return ['success' => false, 'message' => $membersResult['message']];
        }

        $members = $membersResult['data'] ?? [];
        if (empty($members)) {
            return ['success' => true, 'message' => '门派中暂无成员记录。'];
        }

        $sectConf = SectHelper::getSectConfig($me['family']);
        $sectName = $sectConf['name'] ?? $me['family'];

        $lines   = [];
        $lines[] = '=== ' . $sectName . ' 门派成员 ===';
        $lines[] = sprintf('%-12s %-8s %-6s %-6s', '名称', '职位', '代数', '贡献');
        $lines[] = str_repeat('─', 40);

        foreach ($members as $member) {
            $isMe   = (intval($member['character_id']) === $charId) ? '【你】' : '';
            $lines[] = sprintf(
                '%-12s %-8s 第%-4s代 %d贡献 %s',
                $member['name'],
                $member['sect_rank'] ?? '弟子',
                $member['generation'] ?? '?',
                intval($member['contribution'] ?? 0),
                $isMe
            );
        }

        $lines[] = '共 ' . count($members) . ' 名成员。';

        return ['success' => true, 'message' => implode("\n", $lines)];
    }

    // -------------------------------------------------------
    // family（无参数）- 显示自己的门派信息
    // -------------------------------------------------------
    if (empty($me['family'])) {
        return ['success' => true, 'message' => "你目前不属于任何门派。\n你可以向各地的掌门NPC使用 apprentice 指令拜师入门。"];
    }

    $sectInfo = SectHelper::getCharacterSect($charId);
    if (!$sectInfo) {
        return ['success' => false, 'message' => '无法获取门派信息。'];
    }

    $masterResult = ApprenticeHandler::getMasterInfo($charId);
    $masterData   = $masterResult['data'] ?? null;
    $masterName   = $masterData ? $masterData['name'] : ($me['master_name'] ?? '（无）');

    $apprenticesResult = ApprenticeHandler::getApprentices($charId);
    $apprenticeCount   = count($apprenticesResult['data'] ?? []);

    $enterTime = $sectInfo['enter_time'] ?? '';
    if ($enterTime) {
        $enterTime = date('Y-m-d', strtotime($enterTime));
    }

    $lines   = [];
    $lines[] = '=== 门派信息 ===';
    $lines[] = '门派：'     . $sectInfo['sect_name'];
    $lines[] = '职位：'     . $sectInfo['sect_rank'];
    $lines[] = '代数：第'   . ($sectInfo['generation'] ?? 0) . '代';
    $lines[] = '师父：'     . $masterName;
    $lines[] = '弟子数量：' . $apprenticeCount . ' 人';
    $lines[] = '入门时间：' . ($enterTime ?: '未知');
    $lines[] = '贡献值：'   . $sectInfo['contribution'];
    $lines[] = '';
    $lines[] = '可用子命令：family tree | family info | family members';

    return ['success' => true, 'message' => implode("\n", $lines)];
}

/**
 * 内部辅助：将阵营数值转为文字
 */
function _family_alignment_name(int $alignment): string {
    if ($alignment > 0)  return '正派（' . $alignment . '）';
    if ($alignment < 0)  return '邪派（' . $alignment . '）';
    return '中立';
}

/**
 * 递归渲染师徒树（支持多层级徒孙显示）
 * 还原原始项目 look 命令中按 family_enter_time 排序、显示师兄/师弟关系的逻辑
 *
 * @param array $nodes  弟子节点数组（含 sub_apprentices 字段）
 * @param int   $depth  当前缩进深度
 * @param array $lines  输出行数组（引用传递）
 */
function _family_render_apprentice_tree(array $nodes, int $depth, array &$lines): void {
    if (empty($nodes)) return;

    // 按 enter_time 排序（先入门为师兄），空值排最后
    usort($nodes, function ($a, $b) {
        $ta = $a['enter_time'] ?? null;
        $tb = $b['enter_time'] ?? null;
        if ($ta === null && $tb === null) return strcmp($a['name'] ?? '', $b['name'] ?? '');
        if ($ta === null) return 1;
        if ($tb === null) return -1;
        return strcmp($ta, $tb);
    });

    $indent = str_repeat('  ', $depth);
    $total  = count($nodes);
    $idx    = 0;

    foreach ($nodes as $ap) {
        $idx++;
        $isLast   = ($idx === $total);
        $branch   = $isLast ? '┗━ ' : '┣━ ';
        $apRank   = $ap['sect_rank'] ?? '弟子';
        $enterStr = '';

        // 显示入门时间（用于判断师兄/师弟关系）
        if (!empty($ap['enter_time'])) {
            $ts = strtotime($ap['enter_time']);
            if ($ts !== false) {
                $enterStr = '  入门: ' . date('Y-m-d', $ts);
            }
        }

        $lines[] = $indent . $branch . $ap['name'] . '（' . $apRank . '，第' . ($ap['generation'] ?? 0) . '代）'
                 . (intval($ap['level'] ?? 0) > 0 ? '  等级: ' . $ap['level'] : '')
                 . $enterStr;

        // 递归渲染徒孙
        if (!empty($ap['sub_apprentices'])) {
            $childIndent = $indent . ($isLast ? '  ' : '┃ ');
            $lines[] = $childIndent . '【弟子】';
            _family_render_apprentice_tree($ap['sub_apprentices'], $depth + 1, $lines);
        }
    }
}

