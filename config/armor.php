<?php
/**
 * 防具配置文件
 *
 * 防具槽位类型及其属性定义。从 ArmorHelper 硬编码中提取出来，
 * 使得数值平衡调整无需修改 PHP 代码。
 */

return [

    // =========================================================
    // 防具槽位配置
    // 每个槽位定义：name(中文名), base_armor[min,max], apply_slots(可能提供的属性加成)
    // =========================================================
    'armor_slots' => [
        'head' => [
            'name' => '头部',
            'base_armor' => [2, 5],
            'apply_slots' => ['int', 'spi'],
        ],
        'neck' => [
            'name' => '颈部',
            'base_armor' => [1, 3],
            'apply_slots' => ['per', 'kar'],
        ],
        'cloth' => [
            'name' => '衣物',
            'base_armor' => [3, 8],
            'apply_slots' => ['dex', 'con'],
        ],
        'armor' => [
            'name' => '铠甲',
            'base_armor' => [8, 20],
            'apply_slots' => ['str', 'con'],
        ],
        'surcoat' => [
            'name' => '外袍',
            'base_armor' => [2, 6],
            'apply_slots' => ['int', 'per'],
        ],
        'waist' => [
            'name' => '腰部',
            'base_armor' => [1, 4],
            'apply_slots' => ['con', 'dex'],
        ],
        'wrists' => [
            'name' => '手腕',
            'base_armor' => [1, 3],
            'apply_slots' => ['dex', 'cps'],
        ],
        'shield' => [
            'name' => '盾牌',
            'base_armor' => [5, 12],
            'apply_slots' => ['con', 'str'],
        ],
        'finger' => [
            'name' => '戒指',
            'base_armor' => [0, 2],
            'apply_slots' => ['int', 'spi', 'kar'],
        ],
        'hands' => [
            'name' => '手套',
            'base_armor' => [1, 4],
            'apply_slots' => ['str', 'dex'],
        ],
        'boots' => [
            'name' => '靴子',
            'base_armor' => [2, 6],
            'apply_slots' => ['dex', 'con'],
        ],
    ],

    // =========================================================
    // 所有装备槽位列表（包括武器）
    // =========================================================
    'all_slots' => [
        'head',
        'neck',
        'cloth',
        'armor',
        'surcoat',
        'waist',
        'wrists',
        'shield',
        'finger',
        'hands',
        'boots',
        'weapon',
    ],

    // =========================================================
    // 防具计算参数
    // =========================================================
    'calc' => [
        'reduction_formula' => '100 / (100 + armor)',  // 减伤公式
        'quality_base'      => 50,                      // 品质基准值
    ],

];
