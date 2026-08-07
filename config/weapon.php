<?php
/**
 * 武器配置文件
 *
 * 武器类型及其属性定义。从 WeaponHelper 硬编码中提取出来，
 * 使得数值平衡调整无需修改 PHP 代码。
 */

return [

    // =========================================================
    // 武器类型配置
    // 每种武器定义：name(中文名), damage_type(伤害类型), flags(属性标志), base_damage[min,max]
    //
    // 伤害类型：
    //   slash  - 挥砍
    //   blunt  - 钝击
    //   pierce - 穿刺
    //
    // 属性标志（可组合）：
    //   1  - 双手持握
    //   2  - 副手武器
    //   4  - 刃类(刀剑等)
    //   8  - 穿刺类(枪刺等)
    //   16 - 长柄武器
    // =========================================================
    'weapon_types' => [
        'sword' => [
            'name' => '剑',
            'damage_type' => 'slash',
            'flags' => 4,           // FLAG_EDGED
            'base_damage' => [5, 15],
        ],
        'blade' => [
            'name' => '刀',
            'damage_type' => 'slash',
            'flags' => 4,           // FLAG_EDGED
            'base_damage' => [8, 18],
        ],
        'staff' => [
            'name' => '棍',
            'damage_type' => 'blunt',
            'flags' => 16,          // FLAG_LONG
            'base_damage' => [4, 12],
        ],
        'hammer' => [
            'name' => '锤',
            'damage_type' => 'blunt',
            'flags' => 1,           // FLAG_TWO_HANDED
            'base_damage' => [10, 25],
        ],
        'axe' => [
            'name' => '斧',
            'damage_type' => 'slash',
            'flags' => 5,           // FLAG_EDGED | FLAG_TWO_HANDED
            'base_damage' => [12, 28],
        ],
        'spear' => [
            'name' => '矛',
            'damage_type' => 'pierce',
            'flags' => 24,          // FLAG_POINTED | FLAG_LONG
            'base_damage' => [8, 20],
        ],
        'whip' => [
            'name' => '鞭',
            'damage_type' => 'blunt',
            'flags' => 16,          // FLAG_LONG
            'base_damage' => [3, 10],
        ],
        'dagger' => [
            'name' => '匕首',
            'damage_type' => 'pierce',
            'flags' => 10,          // FLAG_POINTED | FLAG_SECONDARY
            'base_damage' => [3, 8],
        ],
        'fork' => [
            'name' => '叉',
            'damage_type' => 'pierce',
            'flags' => 8,           // FLAG_POINTED
            'base_damage' => [6, 15],
        ],
        'mace' => [
            'name' => '短锤',
            'damage_type' => 'blunt',
            'flags' => 2,           // FLAG_SECONDARY
            'base_damage' => [5, 12],
        ],
        'rake' => [
            'name' => '耙',
            'damage_type' => 'pierce',
            'flags' => 24,          // FLAG_POINTED | FLAG_LONG
            'base_damage' => [7, 18],
        ],
        'stick' => [
            'name' => '短棍',
            'damage_type' => 'blunt',
            'flags' => 16,          // FLAG_LONG
            'base_damage' => [4, 14],
        ],
        'archery' => [
            'name' => '弓箭',
            'damage_type' => 'pierce',
            'flags' => 1,           // FLAG_TWO_HANDED
            'base_damage' => [6, 16],
        ],
        'throwing' => [
            'name' => '暗器',
            'damage_type' => 'pierce',
            'flags' => 2,           // FLAG_SECONDARY
            'base_damage' => [2, 6],
        ],
    ],

    // =========================================================
    // 武器属性标志常量定义（文档参考）
    // =========================================================
    'flags' => [
        'FLAG_TWO_HANDED' => 1,
        'FLAG_SECONDARY'  => 2,
        'FLAG_EDGED'      => 4,
        'FLAG_POINTED'    => 8,
        'FLAG_LONG'       => 16,
    ],

    // =========================================================
    // 伤害计算参数
    // =========================================================
    'damage_calc' => [
        'skill_multiplier'   => 0.5,   // 技能加成倍率
        'random_min'         => -20,   // 随机波动最小值(%)
        'random_max'         => 20,    // 随机波动最大值(%)
        'quality_base'       => 50,    // 品质基准值
    ],

];
