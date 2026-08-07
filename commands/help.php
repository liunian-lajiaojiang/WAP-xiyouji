<?php
/**
 * 帮助命令 (help) - 显示游戏帮助信息
 */
function cmd_help(int $charId, string $param = ''): array {
    $param = trim($param);
    
    // 如果有参数，查询具体帮助主题
    if (!empty($param)) {
        return showTopicHelp($param);
    }
    
    $output = [];
    $output[] = HICYN . '╔══════════════════════════════════════╗' . NOR;
    $output[] = HICYN . '║          ' . HIWHT . '游 戏 帮 助' . HICYN . '          ║' . NOR;
    $output[] = HICYN . '╚══════════════════════════════════════╝' . NOR;
    $output[] = '';
    
    // 基本命令
    $output[] = HIGRN . '【基本命令】' . NOR;
    $output[] = '  look        - 查看当前房间';
    $output[] = '  look <对象>  - 查看特定对象（NPC/物品/方向）';
    $output[] = '  go <方向>    - 移动到指定方向';
    $output[] = '  n/s/e/w/u/d  - 方向缩写（北/南/东/西/上/下）';
    $output[] = '';
    
    // 物品命令
    $output[] = HIGRN . '【物品命令】' . NOR;
    $output[] = '  get <物品>   - 捡起物品';
    $output[] = '  drop <物品>  - 丢弃物品';
    $output[] = '  inventory   - 查看背包（别名: i）';
    $output[] = '  wear <物品>  - 装备物品';
    $output[] = '  remove <物品> - 卸下装备（别名: unwear）';
    $output[] = '';
    
    // 状态命令
    $output[] = HIGRN . '【状态命令】' . NOR;
    $output[] = '  score       - 查看角色状态（别名: hp）';
    $output[] = '';
    
    // 社交命令
    $output[] = HIGRN . '【社交命令】' . NOR;
    $output[] = '  say <内容>   - 说话';
    $output[] = '  chat <内容>  - 聊天频道';
    $output[] = '  emote <动作> - 表情动作';
    $output[] = '';
    
    // 战斗命令
    $output[] = HIGRN . '【战斗命令】' . NOR;
    $output[] = '  kill <目标>  - 攻击目标';
    $output[] = '  flee        - 逃跑';
    $output[] = '';
    
    // 门派与师徒命令
    $output[] = HIGRN . '【门派与师徒】' . NOR;
    $output[] = '  apprentice  - 向NPC或玩家拜师';
    $output[] = '  recruit     - 接受/拒绝弟子拜师请求';
    $output[] = '  expell      - 逐出弟子或门派成员';
    $output[] = '  family      - 查看门派成员与师徒谱系';
    $output[] = CYN . '  (help menpai 查看门派系统详细说明)' . NOR;
    $output[] = '';
    
    // 其他命令
    $output[] = HIGRN . '【其他命令】' . NOR;
    $output[] = '  help        - 显示此帮助信息';
    $output[] = '  help <主题>  - 查看具体主题帮助';
    $output[] = '  quit        - 退出游戏';
    $output[] = '';
    
    $output[] = HICYN . '══════════════════════════════════════' . NOR;
    $output[] = YEL . '提示：输入 help <命令名> 可查看该命令的详细说明' . NOR;
    
    return [
        'success' => true,
        'type' => 'help_display',
        'output' => implode("\n", $output)
    ];
}

/**
 * 显示具体主题帮助
 */
function showTopicHelp(string $topic): array {
    $topic = strtolower(trim($topic));
    $output = [];
    
    switch ($topic) {
        case 'menpai':
        case '门派':
            $output[] = HICYN . '══════ 门派系统概述 ══════' . NOR;
            $output[] = '';
            $output[] = HIWHT . '《门派介绍》' . NOR;
            $output[] = '游戏共有10个门派，分为仙、人、妖三个阵营。';
            $output[] = '入门后可学习门派独占技能并获得属性加成。';
            $output[] = '';
            $output[] = HIWHT . '《如何加入门派》' . NOR;
            $output[] = '  1. 前往各门派所在地，使用 apprentice <NPC名> 向对应掌门NPC拜师入门。';
            $output[] = '  2. 也可向已入门的玩家发起拜师，使用 apprentice <玩家名> 发起请求。';
            $output[] = '';
            $output[] = HIWHT . '《各门派列表》' . NOR;
            $output[] = '  灵台方寸山  - 菩提祖师门下，仙阵营，以柔克刚，变化莫测';
            $output[] = '  花果山水帘洞 - 妖阵营，棒法称雄，千变万化';
            $output[] = '  东海龙宫    - 人阵营，攻守兼备，水系之道';
            $output[] = '  南海普陀山  - 仙阵营，慈悲为怀，普渡众生';
            $output[] = '  月宫        - 人阵营，轻灵见长，月夜加成';
            $output[] = '  五庄观      - 仙阵营，道家武学，人参果长生';
            $output[] = '  阎罗地府    - 人阵营，阴鬼之道，杀气积聚';
            $output[] = '  将军府      - 武将之道，刚猛无敌';
            $output[] = '  火云洞      - 妖阵营，三昧真火，火焰之力';
            $output[] = '  大雪山      - 妖阵营，冰雪之道，凌厉无比';
            $output[] = '';
            $output[] = YEL . '小技巧：使用 score 可查看当前门派归属与师徒信息。' . NOR;
            break;
        
        case 'apprentice':
        case '拜师':
            $output[] = HICYN . '══════ apprentice 命令帮助 ══════' . NOR;
            $output[] = '';
            $output[] = HIWHT . '用法：' . NOR;
            $output[] = '  apprentice <NPC名>    - 向门派掌门NPC拜师，直接入门';
            $output[] = '  apprentice <玩家名>  - 向已入门玩家发起拜师请求';
            $output[] = '';
            $output[] = HIWHT . '说明：' . NOR;
            $output[] = '  - 拜师NPC时可直接入门，拜师玩家需对方确认。';
            $output[] = '  - 已有门派时换派会被计为背叛，连带惩罚。';
            $output[] = '  - 重复背叛次数过多则永久不得入门。';
            break;
        
        case 'recruit':
        case '收徒':
            $output[] = HICYN . '══════ recruit 命令帮助 ══════' . NOR;
            $output[] = '';
            $output[] = HIWHT . '用法：' . NOR;
            $output[] = '  recruit accept <玩家名> - 接受对方的拜师请求，收其为徒';
            $output[] = '  recruit reject <玩家名> - 拒绝对方的拜师请求';
            $output[] = '  recruit list             - 查看待处理的拜师请求列表';
            $output[] = '';
            $output[] = HIWHT . '说明：' . NOR;
            $output[] = '  - 收徒后弟子将属同一门派，师父为第N代，弟子则为第N+1代。';
            $output[] = '  - 师父需在线且在同一房间才能接受请求。';
            break;
        
        case 'expell':
        case '逐出':
            $output[] = HICYN . '══════ expell 命令帮助 ══════' . NOR;
            $output[] = '';
            $output[] = HIWHT . '用法：' . NOR;
            $output[] = '  expell <弟子名>          - 将自己的弟子逐出师门';
            $output[] = '  expell <弟子名> <原因>   - 逐出师门（附加原因）';
            $output[] = '';
            $output[] = HIWHT . '说明：' . NOR;
            $output[] = '  - 掌门可逐出任意门派成员，普通师父仅可逐出自己的直接弟子。';
            $output[] = '  - 被逐出者将失去门派成员资格。';
            break;
        
        case 'family':
        case '谱系':
        case '师徒':
            $output[] = HICYN . '══════ family 命令帮助 ══════' . NOR;
            $output[] = '';
            $output[] = HIWHT . '用法：' . NOR;
            $output[] = '  family         - 查看自己的师徒谱系（师父与弟子）';
            $output[] = '  family members - 查看门派所有成员';
            $output[] = '  family tree    - 查看完整祖师谱系';
            $output[] = '';
            $output[] = HIWHT . '说明：' . NOR;
            $output[] = '  - 显示师父信息、直接弟子列表以及门派信息。';
            break;
        
        default:
            // 未知主题，返回错误提示
            return [
                'success' => false,
                'message' => '未知的帮助主题「' . $topic . '」。输入 help 查看所有可用主题。',
            ];
    }
    
    return [
        'success' => true,
        'type'    => 'help_display',
        'output'  => implode("\n", $output),
    ];
}

// 别名支持
if (!function_exists('cmd_h')) {
    function cmd_h(int $charId, string $param = ''): array {
        return cmd_help($charId, $param);
    }
}

