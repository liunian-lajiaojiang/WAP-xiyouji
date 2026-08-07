<?php
/**
 * 战斗系统配置
 * 
 * 从 CombatDaemon.php 中提取的可调参数。
 * 修改此文件即可调整战斗平衡，无需改动核心代码。
 * 
 * 使用方式: $combatConfig = require __DIR__ . '/combat.php';
 */

return [
    // === 身体部位 ===
    'limbs' => ['头部', '颈部', '胸口', '腹部', '腰部', '手臂', '腿部', '肩膀'],

    // === 勇气/气势系统 ===
    'guarding' => [
        'default_cps'       => 10,    // 防守方气势默认值
        'cps_multiplier'    => 3,     // 气势检定的除数乘数
        'default_cor'       => 10,    // 勇气默认值
        'bellicosity_div'   => 50,    // 杀气对勇气的转化除数
        'yaoguai_cor'       => 10,    // 妖怪勇气默认值
    ],

    // === 闪避系统 ===
    'dodge' => [
        'base_mod'          => 100,    // 闪避公式基础值
        'npc_no_skill_div'  => 2,     // NPC 无闪避技能时 DP = combat_exp / 此值
        'busy_div'          => 3,     // 防御者忙碌时闪避威力 / 此值
        'overflow_cap'      => 1000000, // 大数值溢出保护阈值
        'npc_attack_dodge_enabled' => true, // NPC攻击时玩家可闪避（还原LPC双向防御）
    ],

    // === 招架系统 ===
    'parry' => [
        'unarmed_mult'      => 2,     // 攻击者无武器时，招架威力翻倍
        'busy_div'          => 3,     // 防御者忙碌时招架威力 / 此值
        'reduce_min'        => 30,    // 招架成功时减伤比例下限(%)
        'reduce_max'        => 50,    // 招架成功时减伤比例上限(%)
        'npc_attack_parry_enabled' => true, // NPC攻击时玩家可招架（还原LPC双向防御）
    ],

    // === 伤害计算 ===
    'damage' => [
        'default_weapon'    => 10,    // 武器伤害默认值（无武器时）
        'unarmed_str_mult'  => 2,     // 徒手攻击：力量 * 此值 + 等级
        'skill_damage_coef' => 0.5,   // 技能等级伤害加成系数
        'kee_mark_threshold'=> 200,   // 杀气减伤阈值
        'kee_mark_reduce_div'=> 50,   // 杀气减伤除数：(keeMark - threshold) / div
        'kee_mark_reduce_cap'=> 20,   // 杀气减伤上限(%)
        'fluctuation_pct'   => 20,   // 伤害随机波动百分比（±此值）
        'min_damage'        => 1,    // 伤害最小值
    ],

    // === NPC 反击伤害 ===
    'npc_counter' => [
        'default_str'       => 10,    // NPC 力量默认值
        'exp_to_damage_div' => 5000,  // combat_exp 转化为伤害的除数
        'str_div'           => 2,     // 力量的一半计入伤害
        'rand_min'          => 1,     // 随机浮动伤害最小值
        'rand_max'          => 10,    // 随机浮动伤害最大值
    ],

    // === 连击系统 ===
    'combo' => [
        'count_div'         => 3,     // 连击计数除数
        'bonus_per'         => 10,    // 每N连击伤害加成(%)
        'bonus_cap'         => 50,    // 连击伤害加成上限(%)
        'storm_threshold'   => 5,     // 暴风连击所需连击数
        'storm_chance'      => 10,    // 暴风连击触发概率(%)
        'storm_damage_mult' => 0.5,   // 暴风连击额外伤害系数
    ],

    // === 反击系统 ===
    'riposte' => [
        'type_riposte_chance'=> 95,   // 反击类型概率(%)
        'quick_attack_cps'  => 5,     // 快速攻击所需气势
        'quick_attack_chance'=> 5,    // 快速攻击触发概率(%)
        'def_str_rand_min'  => 1,     // 防守方反击浮动伤害最小值
        'def_str_rand_max'  => 10,    // 防守方反击浮动伤害最大值
    ],

    // === NPC guarding 系统（还原 LPC heart_beat 概率攻击机制）===
    // NPC 每回合并非 100% 攻击，而是根据 guarding 状态概率决定
    // 原始公式：NPC攻击概率 = (npcCor + bellicosity/50) / (玩家cps * 3)
    // 当随机数 < (npcCor + bellicosity/50) 时 NPC 攻击，否则进入 guarding（不攻击）
    'npc_guarding' => [
        'cps_multiplier'    => 3,     // 玩家气势的倍数（随机范围上限 = cps * multiplier）
        'bellicosity_div'   => 50,    // 好斗度除数（bellicosity/div 加入NPC判定值）
        'default_npc_cor'   => 10,    // NPC 默认胆识（当 npcs 表无 cor 字段时）
        'default_npc_cps'   => 10,    // 默认气势（当 npcs 表无 cps 字段时）
        'min_attack_chance' => 10,    // NPC 最低攻击概率(%)，防止永远不攻击
        'player_counter_chance' => 70,// 玩家自动还手概率(%)，还原双向heart_beat
        'npc_attack_defense_enabled' => true, // NPC攻击时玩家defense_factor生效
        'defense_reduction_div' => 3,  // defense_factor每次减伤 damage/此值
        'defense_factor_div'    => 2,  // defense_factor每次折半
    ],

    // === 经验/奖励系统 ===
    'rewards' => [
        'exp_roll_threshold' => 150,   // 攻击者经验增长阈值
        'potential_gap'      => 100,   // 潜能增长上限(learned vs potential)
        'newbie_exp_threshold'=> 30000,// 新手经验阈值
        'newbie_exp_bonus'   => 500,   // 新手经验加成
        'pvp_kill_kee_mark'  => 50,    // PVP 击杀杀气奖励
        'pve_kill_kee_mark'  => 5,     // PVE 击杀杀气奖励
        'pvp_bellicosity'    => 20,    // PVP 击杀基础杀气增加
    ],

    // === 死亡惩罚 ===
    'death' => [
        'base_loss_div'     => 40,    // 基础惩罚除数（道行和经验的 1/40 = 2.5%）
        'pvp_loss_half'     => true,  // PVP 死亡惩罚减半
        'pvp_loss_div'      => 2,     // PVP 死亡惩罚除额外除数
        'kar_check_max'     => 99,    // 福缘技能损失判定上限
        'skill_loss_max'    => 3,     // 技能等级损失上限
        'pvp_gain_rate_num' => 8,     // PVP 击杀者奖励分子
        'pvp_gain_rate_den' => 10,    // PVP 击杀者奖励分母（8/10 = 80%）
    ],

    // === 特殊招式触发 ===
    'special_action' => [
        'force_threshold'   => 0.1,   // 内力不足阈值（最大内力的比例）
        'base_chance'       => 20,    // 基础触发概率(%)
        'skill_level_div'   => 10,    // 技能等级除数（每N级+1%）
        'max_chance'        => 50,    // 最大触发概率(%)
    ],

    // === 多目标战斗 ===
    'multi_target' => [
        'exp_to_damage_div' => 1000,  // combat_exp 转化为伤害的除数
        'base_damage'       => 5,     // 多目标战斗基础伤害
        'rand_min'          => 3,     // 随机伤害浮动最小值
        'rand_max'          => 10,    // 随机伤害浮动最大值
        'fluctuation_pct'   => 20,    // 伤害百分比波动(%)
    ],

    // === 技能威力公式 ===
    // power = (level³ / div) × (sen / max_sen) + combat_exp
    'skill_power' => [
        'level_pow'         => 3,     // 等级幂次
        'level_div'         => 3,     // 等级除数
        'overflow_cap'      => 100000, // 大数值溢出保护
    ],

    // === 战斗时间与状态 ===
    'timing' => [
        'npc_attack_interval'=> 5,     // NPC 攻击时间间隔(秒)
        'max_npc_attacks'    => 3,     // 待处理 NPC 攻击最大次数
        'combat_sync_interval'=> 2,    // 战斗状态同步间隔(秒)
        'combat_timeout'     => 1800,  // 战斗超时(秒) = 30分钟
        'default_stun_time'  => 30,    // 默认昏迷持续时间(秒)
        'scatter_item_cd_base'=> 600,  // 散星物品基础冷却(秒)
        'scatter_item_cd_rand'=> 600,  // 散星物品额外随机冷却(秒)
    ],

    // === NPC AI 行为 ===
    'npc_ai' => [
        'flee_hp_threshold' => 30,    // 血量低于此百分比时 NPC 概率逃跑
        'zheng_long_busy'   => 50,    // 蒸笼老人无敌 busy 时间(秒)
    ],

    // === PVP 切磋 ===
    'sparring' => [
        'fluctuation_pct'   => 20,    // 切磋伤害随机波动(%)
        'reject_cooldown'   => 30,    // 切磋拒绝冷却(秒)
        'invite_timeout'    => 30,    // 切磋邀请过期(秒)
    ],
];
