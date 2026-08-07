<?php
/**
 * 技能施展（exert）配置文件
 * 
 * 所有 exert 命令的资源消耗、冷却时间、技能阈值等参数
 * 从此文件集中读取，替代 Commands/exert.php 中的硬编码魔法数字。
 */
return [
    // ============================
    // 静心诀 (jingxin)
    // ============================
    'jingxin' => [
        'force_min'        => 200,   // 最低内力需求
        'force_cost'       => 100,   // 内力消耗
        'kill_reduce_div'  => 2,     // 杀气减少 = skillLevel / 此值
        'dodge_bonus_div'  => 5,     // 闪避加成 = skillLevel / 此值
        'combat_busy'      => 3,     // 战斗中 busy 秒数
    ],

    // ============================
    // 化功 (powerfade)
    // ============================
    'powerfade' => [
        'force_min'        => 100,   // 最低内力需求
        'sen_min'          => 100,   // 最低精神需求
        'force_cost'       => 100,   // 内力消耗
        'sen_cost'         => 100,   // 精神消耗
        'kill_reduce_base' => 50,    // 杀气减少基础值
        'kill_reduce_div'  => 3,     // 杀气减少 = base + skillLevel/此值
        'default_cps'      => 10,    // 默认 cps 值
        'stun_seconds'     => 5,     // 昏迷持续秒数
        'combat_busy'      => 4,     // 战斗中 busy 秒数
    ],

    // ============================
    // 恢复 (recover)
    // ============================
    'recover' => [
        'force_min'        => 20,    // 最低内力需求
        'recover_mult'     => 10,    // 恢复量乘数 = skillLevel * 此值
        'force_cost_mult'  => 50,    // 消耗内力乘数 = diff * 此值 / skillLevel
        'actual_mult'      => 50,    // 实际恢复除数 = forceCost * skillLevel / 此值
        'combat_busy'      => 1,     // 战斗中 busy 秒数
    ],

    // ============================
    // 提神 (refresh)
    // ============================
    'refresh' => [
        'force_min'        => 20,    // 最低内力需求
        'recover_mult'     => 8,     // 恢复量乘数 = skillLevel * 此值
        'force_cost_mult'  => 40,    // 消耗内力乘数 = diff * 此值 / skillLevel
        'actual_div'       => 40,    // 实际恢复除数 = forceCost * skillLevel / 此值
        'combat_busy'      => 1,     // 战斗中 busy 秒数
    ],

    // ============================
    // 疗伤 (heal)
    // ============================
    'heal' => [
        'force_min'        => 50,    // 最低内力需求
        'sen_min'          => 30,    // 最低精神需求
        'force_cost'       => 50,    // 内力消耗
        'sen_cost'         => 30,    // 精神消耗
        'heal_skill_mult'  => 5,     // 疗伤量 = skillLevel * 此值 + sen * sen_mult
        'heal_sen_mult'    => 0.3,   // 疗伤量 sen 乘数
        'combat_busy'      => 3,     // 战斗中 busy 秒数
    ],

    // ============================
    // 蓄力 (powerup)
    // ============================
    'powerup' => [
        'force_min'        => 100,   // 最低内力需求
        'force_cost'       => 100,   // 内力消耗
        'attack_div'       => 3,     // 攻击加成 = skillLevel/此值 + attack_base
        'attack_base'      => 5,     // 攻击基础加成
        'defense_div'      => 4,     // 防御加成 = skillLevel/此值 + defense_base
        'defense_base'     => 3,     // 防御基础加成
        'duration_mult'    => 2,     // 持续时间 = skillLevel * 此值
        'effect_dur_div'   => 3,     // StatusEffect duration = duration/此值
        'combat_busy'      => 2,     // 战斗中 busy 秒数
    ],

    // ============================
    // 再生 (regenerate)
    // ============================
    'regenerate' => [
        'force_min'        => 30,    // 最低内力需求
        'regen_mult'       => 5,     // 再生量 = skillLevel * 此值
        'force_cost_mult'  => 60,    // 消耗内力乘数 = diff * 此值 / skillLevel
        'actual_div'       => 60,    // 实际再生除数 = forceCost * skillLevel / 此值
        'combat_busy'      => 2,     // 战斗中 busy 秒数
    ],

    // ============================
    // 传送 (transfer)
    // ============================
    'transfer' => [
        'excess_div'       => 2,     // 多余内力 / 此值
        'chance_div'       => 3,     // 传送几率 = forceCost / 此值
        'target_receive_div'=> 6,    // 目标获得 forceCost/此值
        'fail_busy_base'   => 2,     // 失败 busy 基础秒数
        'fail_busy_rand'   => 3,     // 失败 busy 随机范围 (0~此值)
        'success_busy_base'=> 2,     // 成功 busy 基础秒数
        'success_busy_rand'=> 3,     // 成功 busy 随机范围 (0~此值)
    ],

    // ============================
    // 舍气 (sheqi)
    // ============================
    'sheqi' => [
        'skill_min'        => 30,    // 所需最低摄气诀等级
        'skill_cap'        => 200,   // 熟练度提升等级上限
        'skill_rand_max'   => 2,     // 熟练度随机范围上限
        'kee_threshold_mult'=> 1.5,  // 气血 > max_kee * 此值 判定
        'absorb_div'       => 5,     // 吸取量 = targetKee / 此值
        'absorb_min'       => 5,     // 吸取量最低阈值
        'ap_exp_div'       => 10,    // ap = skillLevel^3 / 此值 + combat_exp
        'mana_check_mult'  => 2,     // 法力判定: myMaxMana * 此值
        'actual_div'       => 2,     // 实际扣除量随机范围 / 此值
        'kee_cap_mult'     => 2,     // 气血上限 = max_kee * 此值
        'combat_busy'      => 4,     // 战斗中 busy 秒数
    ],

    // ============================
    // 月圆 (yuanyue) - 月宫圆月心法
    // 解除目标月毒 + 治疗气血，双方须非战斗
    // ============================
    'yuanyue' => [
        'force_min'        => 600,   // 内力须超出上限此值
        'skill_min'        => 80,    // moonforce 最低等级
        'heal_mult'        => 5,     // 治疗量 = skillLevel * 此值
        'force_cost'       => 600,   // 内力消耗
        'busy_base'        => 2,     // 施法后 busy 基础秒数
        'busy_rand'        => 3,     // 施法后 busy 随机范围
    ],

    // ============================
    // 生命治疗 (lifeheal) - 莲花心法
    // 治疗目标气血(kee)，双方须非战斗
    // ============================
    'lifeheal' => [
        'force_min'        => 150,   // 内力须超出上限此值
        'heal_mult'        => 4,     // 治疗量 = skillLevel * 此值
        'force_cost'       => 150,   // 内力消耗
        'target_kee_ratio' => 5,     // 目标 eff_kee 须 >= max_kee/此值
        'busy_base'        => 2,     // 施法后 busy 基础秒数
        'busy_rand'        => 3,     // 施法后 busy 随机范围
    ],
];
