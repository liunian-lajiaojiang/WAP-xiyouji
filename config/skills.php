<?php
/**
 * 技能与命令参数配置
 * 
 * 从 Commands/ 目录中的硬编码数值提取。
 * 包括技能消耗、冷却时间、busy时间、修炼公式等。
 * 
 * 使用方式: $skillConfig = require __DIR__ . '/skills.php';
 */

return [
    // === 招式消耗 (perform.php) ===
    'performs' => [
        '三板斧/sanban'    => ['force_cost' => 30, 'mana_cost' => 0,  'min_level' => 30],
        '霸王枪/qiangjian' => ['force_cost' => 40, 'mana_cost' => 0,  'min_level' => 40],
        '解难指/storm'     => ['force_cost' => 50, 'mana_cost' => 0,  'min_level' => 50],
        '轮回杖/qifei'     => ['force_cost' => 35, 'mana_cost' => 0,  'min_level' => 35],
        '火云枪/qifei'     => ['force_cost' => 30, 'mana_cost' => 0,  'min_level' => 30],
        '火云枪/fire'      => ['force_cost' => 0,  'mana_cost' => 50, 'min_level' => 50],
        '千钧棒/pili'      => ['force_cost' => 60, 'mana_cost' => 0,  'min_level' => 60],
        '千钧棒/qiankun'   => ['force_cost' => 80, 'mana_cost' => 0,  'min_level' => 80],
        '枯骨刀/pozhan'    => ['force_cost' => 40, 'mana_cost' => 0,  'min_level' => 40],
        '摩云手/zhangxinlei'=> ['force_cost' => 0,  'mana_cost' => 50, 'min_level' => 50],
        '月牙铲/feicha'    => ['force_cost' => 35, 'mana_cost' => 0,  'min_level' => 35],
        '百花掌/flower'    => ['force_cost' => 0,  'mana_cost' => 40, 'min_level' => 40],
        '雪山剑法/diezhang'=> ['force_cost' => 30, 'mana_cost' => 0,  'min_level' => 30],
        '雪山剑法/huifeng' => ['force_cost' => 50, 'mana_cost' => 0,  'min_level' => 50],
        '雪山剑法/wuxue'   => ['force_cost' => 80, 'mana_cost' => 0,  'min_level' => 80],
        '龙形搏斗/sheshen' => ['force_cost' => 50, 'mana_cost' => 0,  'min_level' => 50],
        '地狱火鞭/three'   => ['force_cost' => 0,  'mana_cost' => 60, 'min_level' => 60],
    ],

    // === 法术消耗 (cast.php) ===
    'spells' => [
        '雷咒/thunder'      => ['mana' => 100, 'sen' => 50, 'busy' => 2],
        '光明咒/light'      => ['mana' => 80,  'sen' => 30, 'busy' => 2],
        '定神术/dingshen'   => ['mana' => 60,  'sen' => 40, 'busy' => 3],
        '真气传送/transfer' => ['mana' => 200, 'sen' => 0,  'busy' => 2],
        '大力锤/bighammer'  => ['mana' => 120, 'sen' => 60, 'busy' => 2],
    ],

    // === 法术伤害公式参数 ===
    'spell_damage' => [
        'base_multiplier'   => 1.5,  // 基础伤害 = 法术等级 * 此值
        'rand_min'          => 1,    // 随机伤害最小值
        'rand_max'          => 10,   // 随机伤害最大值
        'thunder_qi_ratio'  => 0.6,  // 雷咒气伤比例
        'light_shen_ratio'  => 0.8,  // 光明咒神伤比例
        'hammer_qi_ratio'   => 0.8,  // 大力锤气伤比例
        'hammer_bonus_ratio'=> 0.17, // 大力锤伤害加成比例
        'eff_cap'           => 300,  // 法力效率上限
        'dingshen_stun_min' => 10,   // 定神术眩晕最小秒数
        'dingshen_stun_max' => 40,   // 定神术眩晕最大秒数
    ],

    // === 招式伤害公式参数 ===
    'perform_damage' => [
        'base_multiplier'   => 1.5,  // 基础伤害系数
        'level_bonus'       => 0.2,  // 等级伤害加成系数
        'dodge_mod'         => 0.5,  // 闪避修正系数
        'parry_mod'         => 0.5,  // 招架修正系数
    ],

    // === 内功消耗 (exert.php) ===
    'exerts' => [
        '静心诀/jingxin' => [
            'force_cost'    => 100,
            'dodge_bonus_div'=> 5,   // dodgeBonus = skillLevel / 5
            'busy'          => 3,
        ],
        '化功/huagong' => [
            'force_cost'        => 100,
            'sen_cost'          => 100,
            'bellicosity_reduce_base'=> 50,
            'bellicosity_reduce_div' => 3, // bellicosityReduce = 50 + skillLevel / 3
            'busy'              => 4,
            'dizzy_busy'        => 5,
        ],
        '疗伤/heal' => [
            'formula_coef'  => 50,   // diff * 50 / skillLevel
            'min_cost'      => 20,
            'busy'          => 1,
        ],
        '精神恢复' => [
            'formula_coef'  => 40,   // diff * 40 / skillLevel
            'min_cost'      => 20,
            'busy'          => 1,
        ],
        '治疗' => [
            'force_cost'    => 50,
            'sen_cost'      => 30,
            'heal_mult'     => 5,    // healAmount = skillLevel * 5
            'sen_ratio'     => 0.3,  // + sen * 0.3
            'busy'          => 3,
        ],
        '蓄力/powerup' => [
            'force_cost'    => 100,
            'attack_div'    => 3,    // attackBonus = skillLevel / 3
            'attack_bonus'  => 5,    // + 5
            'defense_div'   => 4,    // defenseBonus = skillLevel / 4
            'defense_bonus' => 3,    // + 3
            'duration_mult' => 2,    // duration = skillLevel * 2
            'busy'          => 2,
        ],
        '再生/regenerate' => [
            'formula_coef'  => 60,   // diff * 60 / skillLevel
            'min_cost'      => 30,
            'busy'          => 2,
        ],
        '摄气诀' => [
            'excess_div'    => 2,    // 传送 excessForce / 2
            'busy'          => 4,
        ],
    ],

    // === 修炼公式参数 ===
    'cultivation' => [
        'exercise' => [
            'min_kee_cost'      => 20,   // 最少消耗气血
            'kee_per_round'     => 20,   // 每N气=1轮
            'gain_div'          => 10,   // skillLevel / 10
            'con_div'           => 3,    // con / 3
            'rand_min'          => 0,
            'rand_max'          => 2,
            'gain_min'          => 5,    // 最小增长
            'gain_max'          => 40,   // 最大增长
            'gain_mult'         => 2,    // 双倍
            'max_force_inc'     => 1,    // 最大内力每次+1
        ],
        'meditate' => [
            'min_sen_cost'      => 20,   // 最少消耗精神
            'sen_per_round'     => 20,   // 每N精神=1轮
            'gain_div'          => 10,   // spellsLevel / 10
            'spi_div'           => 3,    // spi / 3
            'rand_min'          => 0,
            'rand_max'          => 2,
            'gain_min'          => 5,
            'gain_max'          => 40,
            'gain_mult'         => 2,
            'max_mana_inc'      => 1,    // 最大法力每次+1
        ],
        'practice' => [
            'exp_formula_pow'   => 3,    // pow(level, 3)
            'exp_formula_div'   => 10,   // / 10
            'potential_div'     => 150,  // 150 / int（learn的50%）
        ],
        'study' => [
            'max_skill'         => 60,   // 读书技能上限
        ],
    ],

    // === 训练门槛 ===
    'train' => [
        'required_exp'      => 100000, // 所需经验
        'required_daoxing'  => 50000,  // 所需道行
        'base_chance'       => 30,     // 基础成功率(%)
        'exp_step'          => 50000,  // 每N经验+10%
        'daoxing_step'      => 50000,  // 每N道行+10%
        'chance_increment'  => 10,     // 每档增加成功率(%)
        'max_chance'        => 90,     // 最大成功率(%)
    ],

    // === 抓取经人 ===
    'shou' => [
        'min_combat_exp'    => 500000, // 最低经验要求
        'fail_cooldown'     => 86400,  // 失败冷却(秒) = 24小时
        'open_door_delay'   => 120,    // 开门延迟(秒)
    ],

    // === 密室系统 (mo.php) ===
    'secret_room' => [
        'exp_required'      => 300,    // 修为门槛
        'global_cooldown_base'=> 1200, // 全局冷却基础(秒) = 20分钟
        'global_cooldown_rand'=> 1200, // 全局冷却随机(秒)
        'key_cd_base'       => 36000,  // 钥匙生成冷却基础(秒) = 10小时
        'key_cd_rand_factor'=> 360,    // 钥匙冷却随机因子(秒/每roll)
        'key_cd_rand_max'   => 99,     // 随机roll最大值
        'key_expire_base'   => 1200,   // 钥匙过期基础(秒)
        'key_expire_rand'   => 600,    // 钥匙过期随机(秒)
        'book_expire'       => 18000,  // 太乙真经过期(秒) = 5小时
    ],

    // === 各种冷却/时效 ===
    'cooldowns' => [
        'carry_kee_cost'    => 30,     // 背尸体体力消耗
        'corpse_expire'     => 3600,   // 尸体过期(秒) = 1小时
        'daze_default'      => 30,     // 发呆默认时长(秒)
        'daze_min'          => 10,
        'daze_max'          => 300,
        'hell_stun'         => 30,     // 地狱眩晕(秒)
        'hell_enter_wait'   => 60,     // 地狱进入等待(秒)
        'tianmo_door_wait'  => 120,    // 天魔区域开门等待(秒)
        'blocker_wait_min'  => 30,     // blocker 最小等待(秒)
        'blocker_wait_max'  => 120,    // blocker 最大等待(秒)
        'jicun_fee_per_hour'=> 100,    // 寄存费每小时(铜钱)
        'pickup_fee'        => 1000,   // 拾取费用(铜钱)
        'repair_cost_per'   => 10,     // 修理费每点耐久(银两)
        'surrender_reject_cd'=> 30,    // NPC拒绝再战冷却(秒)
        'surrender_spar_reject'=> 10,  // 切磋拒绝概率(/90)
        'surrender_kill_reject'=> 90,  // 击杀拒绝概率(/90)
        'escape_chance_div' => 5,      // 挣脱概率: kar/5
        'escape_chance_max' => 20,     // 挣脱概率上限(%)
        'give_expire_24h'   => 86400,  // 24小时(通用)
        'invite_timeout'    => 60,     // 组队邀请超时(秒)
        'borrow_timeout'    => 3600,   // 天魔剑借用期限(秒)
    ],

    // === 任务奖励 (give.php / ask.php) ===
    'quest_rewards' => [
        'gao_yuanwai' => [
            'silver'    => 50,
            'exp'       => 5000,
            'daoxing'   => 1000,
        ],
        'yuan_shoucheng' => [
            'silver'    => 1,
        ],
        'song_fan' => [
            'silver'    => 500,
            'exp'       => 200,
        ],
        'moral_reward'  => 1,  // 完成任务品德值奖励
    ],

    // === 开封请赏 (kaifeng_reward.php) ===
    'kaifeng_reward' => [
        'ministers' => [
            0 => ['weight' => 30, 'max_potential' => 200, 'max_daoxing' => 25,  'max_skill_exp' => 1000, 'max_talent' => 1, 'max_silver' => 500],
            1 => ['weight' => 25, 'max_potential' => 200, 'max_daoxing' => 25,  'max_skill_exp' => 1000, 'max_talent' => 1, 'max_silver' => 500],
            2 => ['weight' => 20, 'max_potential' => 200, 'max_daoxing' => 25,  'max_skill_exp' => 1000, 'max_talent' => 1, 'max_silver' => 500],
            3 => ['weight' => 15, 'max_potential' => 200, 'max_daoxing' => 25,  'max_skill_exp' => 1000, 'max_talent' => 1, 'max_silver' => 500],
            4 => ['weight' => 10, 'max_potential' => 200, 'max_daoxing' => 25,  'max_skill_exp' => 1000, 'max_talent' => 1, 'max_silver' => 500],
        ],
        'min_potential' => 50,
        'min_daoxing'   => 5,
        'min_skill_exp' => 200,
        'min_talent'    => 1,
        'min_silver'    => 100,
    ],

    // === 读书/物品概率 ===
    'read_rewards' => [
        'book_threshold_1'  => 10,    // 读书奖励概率判定阈值1
        'book_threshold_2'  => 20,    // 读书奖励概率判定阈值2
        'renshen_kar_pow'   => 2,     // 人参果概率: kar^2 / 100000
        'renshen_div'       => 100000,
        'mihoutao_div'      => 1000,  // 猕猴桃概率: kar / 1000
        'jinding_mult'      => 0.5,   // 金锭概率: kar * 0.5 / 100
        'jinding_div'       => 100,
    ],

    // === 食物/饮水 ===
    'food_water' => [
        'exp_gain'          => 3000,  // 吃特定食物道行增加
        'default_drunk'     => 10,    // 默认酒醉值
    ],

    // === 睡眠 ===
    'sleep' => [
        'hp_threshold'      => 50,    // 睡眠血量阈值(%)
        'double_charm_mult' => 2,     // 双人睡眠魅力判定: per*2%
        'single_base_min'   => 10,    // 单人睡眠基础最小时长
    ],

    // === 真气传送 (cast.php exert.php) ===
    'transfer' => [
        'exp_mult'          => 10,    // expReward = spellsLevel * 10
        'potential_mult'    => 2,     // potentialReward = spellsLevel * 2
        'busy_base'         => 2,
        'busy_rand'         => 3,     // busy = 2 + rand(0, 3)
    ],
];
