<?php
/**
 * 种族系统配置文件
 *
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 人类(human.c)、野兽(beast.c)、妖魔(monster.c)
 */

return [

    // =========================================================
    // 种族配置
    // =========================================================
    'races' => [

        // ---------------------------------------------------------
        // 1. 人类
        // ---------------------------------------------------------
        '人类' => [
            'label'          => '人类',
            'unit'           => '位',
            'default_gender' => 'male',
            'base_weight'    => 40000,          // 40kg，单位：克
            'attributes'     => [
                'str'        => ['min' => 10, 'max' => 30],
                'int'        => ['min' => 10, 'max' => 30],
                'spi'        => ['min' => 10, 'max' => 30],
                'con'        => ['min' => 10, 'max' => 30],
                'per'        => ['min' => 10, 'max' => 30],
                'dex'        => ['min' => 10, 'max' => 30],
            ],
            'combat_actions' => [
                ['name' => '挥拳', 'damage_type' => '瘀伤'],
                ['name' => '抓',   'damage_type' => '抓伤'],
                ['name' => '踢',   'damage_type' => '瘀伤'],
                ['name' => '捶',   'damage_type' => '砸伤'],
            ],
            'body_parts'     => [
                '头部', '颈部', '胸部', '腹部', '腰部',
                '左臂', '右臂', '左手', '右手',
                '左大腿', '右大腿', '左小腿', '右小腿',
                '左脚', '右脚', '后背',
            ],
            'age_formula'    => [
                ['max_age' => 14, 'value' => 100],
                ['max_age' => 30, 'base' => 100, 'per_year' => 1, 'stat' => 'spi', 'subtract_age' => 14],
                ['max_age' => 50, 'base' => 100, 'stat_multiplier' => 16, 'stat' => 'spi'],
                ['max_age' => null, 'base' => 100, 'stat_multiplier' => 16, 'stat' => 'spi', 'per_year' => -5, 'subtract_age' => 50],
            ],
            'exp_multiplier' => 1.0,
        ],

        // ---------------------------------------------------------
        // 2. 野兽
        // ---------------------------------------------------------
        '野兽' => [
            'label'          => '野兽',
            'unit'           => '只',
            'default_gender' => 'male',
            'base_weight'    => 20000,          // 20kg，单位：克
            'attributes'     => [
                'str'        => ['min' => 5, 'max' => 45],
                'int'        => ['min' => 5, 'max' => 15],
                'spi'        => ['min' => 0, 'max' => 0],
                'con'        => ['min' => 5, 'max' => 45],
                'per'        => ['min' => 5, 'max' => 35],
                'dex'        => ['min' => 5, 'max' => 15],
            ],
            'combat_actions' => [
                ['name' => '蹄踢', 'damage_type' => '瘀伤'],
                ['name' => '撕咬', 'damage_type' => '撕裂'],
                ['name' => '利爪', 'damage_type' => '抓伤'],
                ['name' => '扑击', 'damage_type' => '砸伤'],
            ],
            'body_parts'     => [
                '头部', '身体', '前腿', '后腿', '尾巴',
            ],
            'age_formula'    => [
                ['max_age' => 3, 'value' => 50],
                ['max_age' => 10, 'base' => 50, 'per_year' => 20, 'subtract_age' => 3],
                ['max_age' => 30, 'base' => 190, 'per_year' => 5, 'subtract_age' => 10],
                ['max_age' => null, 'base' => 290, 'per_year' => 1, 'subtract_age' => 30],
            ],
            'exp_multiplier' => 0.8,
        ],

        // ---------------------------------------------------------
        // 3. 妖魔
        // ---------------------------------------------------------
        '妖魔' => [
            'label'          => '妖魔',
            'unit'           => '只',
            'default_gender' => 'male',
            'base_weight'    => 40000,          // 40kg，单位：克
            'attributes'     => [
                'str'        => ['min' => 10, 'max' => 50],
                'int'        => ['min' => 10, 'max' => 50],
                'spi'        => ['min' => 10, 'max' => 30],
                'con'        => ['min' => 10, 'max' => 50],
                'per'        => ['min' => 10, 'max' => 20],
                'dex'        => ['min' => 10, 'max' => 30],
            ],
            'combat_actions' => [
                ['name' => '妖气冲击', 'damage_type' => '内伤'],
                ['name' => '暗影袭击', 'damage_type' => '暗伤'],
                ['name' => '爪击',     'damage_type' => '抓伤'],
                ['name' => '法术攻击', 'damage_type' => '内伤'],
            ],
            'body_parts'     => [
                '头部', '躯干', '左臂', '右臂', '双腿',
            ],
            'age_formula'    => [
                ['max_age' => 3, 'value' => 50],
                ['max_age' => 10, 'base' => 50, 'per_year' => 30, 'subtract_age' => 3],
                ['max_age' => 60, 'base' => 260, 'per_year' => 5, 'subtract_age' => 10],
                ['max_age' => null, 'base' => 510, 'per_year' => 1, 'subtract_age' => 60],
            ],
            'exp_multiplier' => 1.2,
        ],

    ], // end of races

];

