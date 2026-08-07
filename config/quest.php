<?php
/**
 * 任务与奖励配置
 * 
 * 将 Commands 中的任务奖励、经验、银两等硬编码数值集中管理。
 * 修改此文件即可调整游戏经济平衡，无需改动代码。
 */

return [
    // ========================
    // 任务奖励配置
    // ========================
    'rewards' => [
        // 高员外玉佩任务
        'gao_peiyu' => [
            'silver'  => 50,
            'exp'     => 5000,
            'daoxing' => 1000,
        ],
        // 送饭任务
        'songfan' => [
            'silver'      => 500,
            'combat_exp'  => 200,
        ],
        // 完成任务品德奖励
        'quest_complete' => [
            'moral' => 1,
        ],
    ],

    // ========================
    // 开封请赏配置
    // ========================
    'kaifeng_reward' => [
        'potential_min'    => 50,    // 潜能最低值
        'daoxing_min'      => 5,     // 道行最低值
        'skill_min'        => 200,   // 技能最低值
        'talent_improve'   => 1,     // 天赋改善点数
        'silver_min'       => 100,   // 白银最低值
        'moral_cost'       => 100,   // 消耗品德值
    ],

    // ========================
    // 法术击败奖励公式参数
    // ========================
    'spell_reward' => [
        'exp_multiplier'       => 10,   // 经验 = 法术等级 × 此值
        'potential_multiplier' => 2,    // 潜能 = 法术等级 × 此值
    ],

    // ========================
    // 驯服坐骑配置
    // ========================
    'train' => [
        'required_combat_exp'   => 100000,  // 最低战斗经验
        'required_daoxing'      => 50000,   // 最低道行
        'base_chance'           => 30,      // 基础成功率(%)
        'attack_base_chance'    => 40,      // 基础攻击概率(%)
    ],

    // ========================
    // 取经/护送配置
    // ========================
    'qujing' => [
        'fail_lock_seconds'       => 86400,   // 取经失败锁定24小时
        'guard_enter_seconds'     => 120,     // 护送人进入等待秒数
        'blocker_min_seconds'     => 30,      // blocker阻挡最短秒数
        'blocker_max_seconds'     => 120,     // blocker阻挡最长秒数
        'required_combat_exp'     => 500000,  // 需要战斗经验
    ],

    // ========================
    // 密室/钥匙配置
    // ========================
    'mishi' => [
        'key_cycle_base_seconds'    => 36000,  // 钥匙生成基础周期(10小时)
        'key_cycle_random_seconds'  => 360,    // 钥匙随机增量单位
        'key_cycle_random_count'    => 100,    // 钥匙随机增量数量(0~100×360)
        'mishi_cooldown_base'       => 1200,   // 密室基础冷却(20分钟)
        'mishi_cooldown_random'     => 1200,   // 密室随机冷却
        'zhenjing_expire_seconds'   => 18000,  // 太乙真经过期(5小时)
    ],

    // ========================
    // 物品过期配置
    // ========================
    'expiry' => [
        'carry_default_seconds' => 3600,       // 搬运物品默认1小时过期
        'accept_return_seconds' => 3600,       // 接受任务返回截止1小时
    ],

    // ========================
    // 昏迷/切磋配置
    // ========================
    'faint' => [
        'base_seconds'      => 30,     // 昏迷基础秒数
        'max_seconds'       => 100,    // 昏迷最大秒数
    ],
    'spar' => [
        'invite_expire_seconds'  => 30,    // 切磋邀请过期秒数
        'npc_reject_cooldown'    => 30,    // NPC拒绝后冷却秒数
    ],
];
