<?php
/**
 * 战斗系统辅助配置
 * 
 * 所有 CombatSystemHelper 中的公式参数、阈值、系数
 * 从此文件集中读取，替代硬编码魔法数字。
 */
return [
    // ============================
    // 技能威力计算 (skillPower)
    // ============================
    'skill_power' => [
        'tier3_threshold'     => 300,   // 第三档等级阈值
        'tier3_base_bonus'    => 35,    // 第三档基础加成
        'tier3_ratio'         => 0.4,   // 第三档比例系数 (2/5)
        'tier2_threshold'     => 200,   // 第二档等级阈值
        'tier2_base_bonus'    => 15,    // 第二档基础加成
        'tier2_ratio'         => 0.2,   // 第二档比例系数 (1/5)
        'tier1_threshold'     => 100,   // 第一档等级阈值
        'tier1_base_bonus'    => 5,     // 第一档基础加成
        'tier1_ratio'         => 0.1,   // 第一档比例系数 (1/10)
        'base_ratio'          => 0.05,  // 基础比例系数 (1/20)
        'mage_penalty_div'    => 10,    // 法师技能惩罚除数
        'warrior_spell_div'   => 10,    // 战士法术惩罚除数
        'level_cube_div'      => 3,     // 基础威力 = level^3 / 此值
        'default_max_sen'     => 100,   // maxSen 默认值
        'precision_threshold' => 100000, // 威力精度阈值
    ],

    // ============================
    // 伤害计算 (calculateDamage)
    // ============================
    'damage_calc' => [
        'random_fluctuation_div' => 2,    // 随机波动均值 / 此值
        'action_damage_div'      => 100,  // 动作伤害百分比除数
        'force_bonus_div'        => 10,   // 内功加成系数 / 此值
        'force_percent_div'      => 100,  // 动作内力加成百分比除数
        'unarmed_skill_mult'     => 0.75, // 徒手技能伤害系数 (3/4)
        'bonus_avg_div'          => 2,    // 伤害加成均值 / 此值
    ],

    // ============================
    // 防御减免 (applyDefenseReduction)
    // ============================
    'defense' => [
        'reduction_div'  => 3,     // 每次减 damage/此值
        'factor_div'     => 2,     // 防御因子每次减半
    ],

    // ============================
    // 闪避 (checkDodge)
    // ============================
    'dodge' => [
        'mount_dodge_div'      => 100,    // 坐骑闪避百分比除数
        'precision_threshold'  => 1000000, // dodgePower 精度阈值
        'high_precision_div'   => 100,    // 高精度模式修正
        'low_precision_div'    => 100,    // 低精度模式修正
    ],

    // ============================
    // 招架 (checkParry)
    // ============================
    'parry_calc' => [
        'unarmed_bonus_mult'   => 2,     // 徒手招架加成 *此值
        'precision_threshold'  => 1000000, // parryPower 精度阈值
        'high_precision_div'   => 100,    // 高精度模式修正
        'low_precision_div'    => 100,    // 低精度模式修正
        'reduce_min'           => 30,     // 招架减伤下限(%)
        'reduce_max'           => 50,     // 招架减伤上限(%)
    ],

    // ============================
    // 暴击 (checkCritical)
    // ============================
    'critical' => [
        'base_rate'       => 5,     // 基础暴击率(%)
        'exp_div'         => 10000, // 暴击率经验换算除数
        'rate_cap'        => 15,    // 暴击率上限(%)
    ],

    // ============================
    // 伤害消息阈值 (getDamageMessage)
    // ============================
    'damage_msg_thresholds' => [10, 20, 40, 80, 160],

    // ============================
    // 经验计算 (calculateExp)
    // ============================
    'exp_calc' => [
        'level_diff_mult' => 0.1,    // 等级差经验倍率
    ],

    // ============================
    // 统计显示 (getStats/getRanking)
    // ============================
    'stats' => [
        'win_rate_mult'      => 100,  // 胜率百分比乘数
        'default_rank_count' => 10,   // 默认排行榜数量
    ],
];
