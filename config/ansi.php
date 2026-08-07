<?php
/**
 * ANSI 颜色代码统一配置
 * 
 * 本文件是项目中所有 ANSI 颜色代码的单一数据源。
 * 被 includes/ansi.php 和 config/game.php 共同引用。
 */

return [
    // ==================== 基础转义码 ====================
    'esc' => "\e",

    // ==================== 普通前景色 ====================
    'blk'   => "\e[30m",   // Black
    'red'   => "\e[31m",   // Red
    'grn'   => "\e[32m",   // Green
    'yel'   => "\e[33m",   // Yellow
    'blu'   => "\e[34m",   // Blue
    'mag'   => "\e[35m",   // Magenta
    'cyn'   => "\e[36m",   // Cyan
    'wht'   => "\e[37m",   // White

    // ==================== 高亮前景色 ====================
    'hir'   => "\e[1;31m", // Hi Red
    'hig'   => "\e[1;32m", // Hi Green
    'hiy'   => "\e[1;33m", // Hi Yellow
    'hib'   => "\e[1;34m", // Hi Blue
    'him'   => "\e[1;35m", // Hi Magenta
    'hic'   => "\e[1;36m", // Hi Cyan
    'hiw'   => "\e[1;37m", // Hi White

    // ==================== 控制码 ====================
    'nor'   => "\e[0m",    // Normal / Reset
    'bold'  => "\e[1m",    // Bold
    'blink' => "\e[5m",    // Blink
    'rev'   => "\e[7m",    // Reverse video
    'u'     => "\e[4m",    // Underscore

    // ==================== HTML 颜色常量 ====================
    'html_hired'  => '<span style="color:#FF0000;font-weight:bold">',
    'html_grn'    => '<span style="color:#00FF00">',
    'html_higrn'  => '<span style="color:#00FF00;font-weight:bold">',
    'html_yel'    => '<span style="color:#FFFF00">',
    'html_hiyel'  => '<span style="color:#FFFF00;font-weight:bold">',
    'html_blu'    => '<span style="color:#0000FF">',
    'html_hiblu'  => '<span style="color:#0000FF;font-weight:bold">',
    'html_mag'    => '<span style="color:#FF00FF">',
    'html_himag'  => '<span style="color:#FF00FF;font-weight:bold">',
    'html_cyn'    => '<span style="color:#00FFFF">',
    'html_hicyn'  => '<span style="color:#00FFFF;font-weight:bold">',
    'html_wht'    => '<span style="color:#CCCCCC">',
    'html_hiwht'  => '<span style="color:#FFFFFF;font-weight:bold">',
    'html_nor'    => '</span>',
    'html_bold'   => '<span style="font-weight:bold">',
];
