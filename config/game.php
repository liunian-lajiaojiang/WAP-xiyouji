<?php
/**
 * 游戏配置
 * 
 * 使用方式: $gameConfig = require __DIR__ . '/game.php';
 * 
 * 改造说明:
 * - 原 define() 常量保留（向后兼容），同时提供 return [] 数组
 * - 新增常量请在数组中添加，define() 仅用于旧代码兼容
 */

// 设置时区（必须与数据库时区一致）
date_default_timezone_set('Asia/Shanghai');

$gameConfig = [
    // === 服务器配置 ===
    'server' => [
        'name'    => 'WAP西游记2012',
        'version' => '1.0.0',
        'max_online' => 500,
    ],

    // === 经验倍率 ===
    'rates' => [
        'exp'    => 1.0,
        'drop'   => 1.0,
        'money'  => 1.0,
    ],

    // === 超时设置（秒） ===
    'timeout' => [
        'idle'    => 300,
        'session' => 3600,
    ],

    // === 战斗相关 ===
    'combat' => [
        'round_time' => 5,
    ],

    // === VIP等级 ===
    'vip_levels' => [
        0 => '普通玩家',
        1 => 'VIP1',
        2 => 'VIP2',
        3 => 'VIP3',
        4 => 'VIP4',
        5 => 'VIP5',
    ],

    // === 巫师等级 ===
    'wiz_levels' => [
        0 => '玩家',
        1 => '长老',
        2 => '神仙',
        3 => '学徒巫师',
        4 => '巫师',
        5 => '大巫师',
        6 => '管理员',
    ],

    // === 性别 ===
    'gender' => [
        'male'    => 'male',
        'female'  => 'female',
        'unknown' => 'unknown',
    ],

    // === 种族 ===
    'race' => [
        'human'   => 'human',
        'demon'   => 'demon',
        'god'     => 'god',
        'monster' => 'monster',
    ],

    // === 物品类型 ===
    'item_type' => [
        'weapon'   => 'weapon',
        'armor'    => 'armor',
        'food'     => 'food',
        'medicine' => 'medicine',
        'treasure' => 'treasure',
        'material' => 'material',
    ],

    // === 技能类型 ===
    'skill_type' => [
        'martial' => 'martial',
        'magic'   => 'magic',
        'force'   => 'force',
        'dodge'   => 'dodge',
        'parry'   => 'parry',
    ],

    // === 任务类型 ===
    'quest_type' => [
        'main'  => 'main',
        'side'  => 'side',
        'daily' => 'daily',
        'event' => 'event',
    ],

    // === 房间类型 ===
    'room_type' => [
        'normal'  => 'normal',
        'shop'    => 'shop',
        'home'    => 'home',
        'dungeon' => 'dungeon',
    ],

    // === 路径配置 ===
    'paths' => [
        'cmd'      => __DIR__ . '/../commands/',
        'daemon'   => __DIR__ . '/../daemons/',
        'helper'   => __DIR__ . '/../helpers/',
        'model'    => __DIR__ . '/../models/',
        'template' => __DIR__ . '/../templates/',
    ],
];

// ============================================================
// 以下 define() 常量保留用于向后兼容（旧代码可能直接引用）
// 新增代码请使用上方的 $gameConfig 数组
// ============================================================

// 服务器配置
define('SERVER_NAME', 'WAP西游记2012');
define('SERVER_VERSION', '1.0.0');
define('MAX_ONLINE', 500);

// 经验倍率
define('EXP_RATE', 1.0);
define('DROP_RATE', 1.0);
define('MONEY_RATE', 1.0);

// 超时设置（秒）
define('IDLE_TIMEOUT', 300);
define('SESSION_TIMEOUT', 3600);

// 战斗相关
define('COMBAT_ROUND_TIME', 5);

// VIP等级权限
define('VIP_LEVELS', [
    0 => '普通玩家',
    1 => 'VIP1',
    2 => 'VIP2',
    3 => 'VIP3',
    4 => 'VIP4',
    5 => 'VIP5',
]);

// 巫师等级
define('WIZ_LEVELS', [
    0 => '玩家',
    1 => '长老',
    2 => '神仙',
    3 => '学徒巫师',
    4 => '巫师',
    5 => '大巫师',
    6 => '管理员',
]);

// 性别
define('GENDER_MALE', 'male');
define('GENDER_FEMALE', 'female');
define('GENDER_UNKNOWN', 'unknown');

// 种族
define('RACE_HUMAN', 'human');
define('RACE_DEMON', 'demon');
define('RACE_GOD', 'god');
define('RACE_MONSTER', 'monster');

// 物品类型
define('ITEM_TYPE_WEAPON', 'weapon');
define('ITEM_TYPE_ARMOR', 'armor');
define('ITEM_TYPE_FOOD', 'food');
define('ITEM_TYPE_MEDICINE', 'medicine');
define('ITEM_TYPE_TREASURE', 'treasure');
define('ITEM_TYPE_MATERIAL', 'material');

// 技能类型
define('SKILL_TYPE_MARTIAL', 'martial');
define('SKILL_TYPE_MAGIC', 'magic');
define('SKILL_TYPE_FORCE', 'force');
define('SKILL_TYPE_DODGE', 'dodge');
define('SKILL_TYPE_PARRY', 'parry');

// 任务类型
define('QUEST_TYPE_MAIN', 'main');
define('QUEST_TYPE_SIDE', 'side');
define('QUEST_TYPE_DAILY', 'daily');
define('QUEST_TYPE_EVENT', 'event');

// 房间类型
define('ROOM_TYPE_NORMAL', 'normal');
define('ROOM_TYPE_SHOP', 'shop');
define('ROOM_TYPE_HOME', 'home');
define('ROOM_TYPE_DUNGEON', 'dungeon');

// 命令路径
define('CMD_PATH', __DIR__ . '/../commands/');
define('DAEMON_PATH', __DIR__ . '/../daemons/');
define('HELPER_PATH', __DIR__ . '/../helpers/');
define('MODEL_PATH', __DIR__ . '/../models/');
define('TEMPLATE_PATH', __DIR__ . '/../templates/');

// ANSI/HTML 颜色代码 —— 从 config/ansi.php 统一配置加载
$_ansi_cfg = require __DIR__ . '/ansi.php';

if (!defined('CLR')) define('CLR', $_ansi_cfg['nor']);
if (!defined('RED')) define('RED', $_ansi_cfg['red']);
if (!defined('HIR')) define('HIR', $_ansi_cfg['hir']);
if (!defined('HIRED')) define('HIRED', $_ansi_cfg['hir']);
if (!defined('GRN')) define('GRN', $_ansi_cfg['grn']);
if (!defined('HIGRN')) define('HIGRN', $_ansi_cfg['hig']);
if (!defined('YEL')) define('YEL', $_ansi_cfg['yel']);
if (!defined('HIY')) define('HIY', $_ansi_cfg['hiy']);
if (!defined('HIYEL')) define('HIYEL', $_ansi_cfg['hiy']);
if (!defined('BLU')) define('BLU', $_ansi_cfg['blu']);
if (!defined('HIBLU')) define('HIBLU', $_ansi_cfg['hib']);
if (!defined('MAG')) define('MAG', $_ansi_cfg['mag']);
if (!defined('HIMAG')) define('HIMAG', $_ansi_cfg['him']);
if (!defined('CYN')) define('CYN', $_ansi_cfg['cyn']);
if (!defined('HICYN')) define('HICYN', $_ansi_cfg['hic']);
if (!defined('WHT')) define('WHT', $_ansi_cfg['wht']);
if (!defined('HIWHT')) define('HIWHT', $_ansi_cfg['hiw']);
if (!defined('BOLD')) define('BOLD', $_ansi_cfg['bold']);
if (!defined('NOR')) define('NOR', $_ansi_cfg['nor']);

// HTML 颜色常量（用于替换 ANSI）
if (!defined('HTML_HIRED')) define('HTML_HIRED', $_ansi_cfg['html_hired']);
if (!defined('HTML_GRN')) define('HTML_GRN', $_ansi_cfg['html_grn']);
if (!defined('HTML_HIGRN')) define('HTML_HIGRN', $_ansi_cfg['html_higrn']);
if (!defined('HTML_YEL')) define('HTML_YEL', $_ansi_cfg['html_yel']);
if (!defined('HTML_HIYEL')) define('HTML_HIYEL', $_ansi_cfg['html_hiyel']);
if (!defined('HTML_BLU')) define('HTML_BLU', $_ansi_cfg['html_blu']);
if (!defined('HTML_HIBLU')) define('HTML_HIBLU', $_ansi_cfg['html_hiblu']);
if (!defined('HTML_MAG')) define('HTML_MAG', $_ansi_cfg['html_mag']);
if (!defined('HTML_HIMAG')) define('HTML_HIMAG', $_ansi_cfg['html_himag']);
if (!defined('HTML_CYN')) define('HTML_CYN', $_ansi_cfg['html_cyn']);
if (!defined('HTML_HICYN')) define('HTML_HICYN', $_ansi_cfg['html_hicyn']);
if (!defined('HTML_WHT')) define('HTML_WHT', $_ansi_cfg['html_wht']);
if (!defined('HTML_HIWHT')) define('HTML_HIWHT', $_ansi_cfg['html_hiwht']);
if (!defined('HTML_NOR')) define('HTML_NOR', $_ansi_cfg['html_nor']);
if (!defined('HTML_BOLD')) define('HTML_BOLD', $_ansi_cfg['html_bold']);

unset($_ansi_cfg);

// 返回配置数组（供 $config = require 'config/game.php' 方式使用）
return $gameConfig;
