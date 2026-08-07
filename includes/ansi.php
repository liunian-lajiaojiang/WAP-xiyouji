<?php
/**
 * ANSI 颜色代码常量定义
 * 本项目为 H5 Web 版，ANSI 转义码通过 ansi_to_html() 函数转换为 HTML 颜色标签输出。
 * 
 * 所有颜色代码从 config/ansi.php 统一配置加载，实现单一数据源。
 */

$__ansi_cfg = require __DIR__ . '/../config/ansi.php';

if (!defined('ESC')) {
    define('ESC', $__ansi_cfg['esc']);
}

/* 前景色（普通） */
defined('BLK')   || define('BLK',   $__ansi_cfg['blk']);   // Black
defined('RED')   || define('RED',   $__ansi_cfg['red']);   // Red
defined('GRN')   || define('GRN',   $__ansi_cfg['grn']);   // Green
defined('YEL')   || define('YEL',   $__ansi_cfg['yel']);   // Yellow
defined('BLU')   || define('BLU',   $__ansi_cfg['blu']);   // Blue
defined('MAG')   || define('MAG',   $__ansi_cfg['mag']);   // Magenta
defined('CYN')   || define('CYN',   $__ansi_cfg['cyn']);   // Cyan
defined('WHT')   || define('WHT',   $__ansi_cfg['wht']);   // White

/* 高亮前景色 */
defined('HIR')   || define('HIR',   $__ansi_cfg['hir']);   // Hi Red
defined('HIG')   || define('HIG',   $__ansi_cfg['hig']);   // Hi Green
defined('HIY')   || define('HIY',   $__ansi_cfg['hiy']);   // Hi Yellow
defined('HIB')   || define('HIB',   $__ansi_cfg['hib']);   // Hi Blue
defined('HIM')   || define('HIM',   $__ansi_cfg['him']);   // Hi Magenta
defined('HIC')   || define('HIC',   $__ansi_cfg['hic']);   // Hi Cyan
defined('HIW')   || define('HIW',   $__ansi_cfg['hiw']);   // Hi White

/* 常用别名（部分代码使用全称） */
defined('HICYN') || define('HICYN', $__ansi_cfg['hic']);   // Hi Cyan（同 HIC）
defined('HIYEL') || define('HIYEL', $__ansi_cfg['hiy']);   // Hi Yellow（同 HIY）
defined('HIRED') || define('HIRED', $__ansi_cfg['hir']);   // Hi Red（同 HIR）
defined('HIGRN') || define('HIGRN', $__ansi_cfg['hig']);   // Hi Green（同 HIG）
defined('HIBLU') || define('HIBLU', $__ansi_cfg['hib']);   // Hi Blue（同 HIB）
defined('HIMAG') || define('HIMAG', $__ansi_cfg['him']);   // Hi Magenta（同 HIM）
defined('HIWHT') || define('HIWHT', $__ansi_cfg['hiw']);   // Hi White（同 HIW）

/* 重置/恢复 */
defined('NOR')   || define('NOR',   $__ansi_cfg['nor']);   // Normal / Reset

/* 其他常用 */
defined('BOLD')  || define('BOLD',  $__ansi_cfg['bold']);  // Bold
defined('BLINK') || define('BLINK', $__ansi_cfg['blink']); // Blink
defined('REV')   || define('REV',   $__ansi_cfg['rev']);   // Reverse video
defined('U')     || define('U',     $__ansi_cfg['u']);     // Underscore

// 清理临时变量，避免污染全局作用域
unset($__ansi_cfg);
