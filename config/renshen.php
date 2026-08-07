<?php
/**
 * 人参果事件配置
 * 
 * 从 RenshenEventHandler.php 中提取的可调参数。
 * 修改此文件即可调整人参果事件行为，无需改动核心代码。
 */

return [
    // === 事件阶段时间（秒）===
    'phase1_delay'    => 180,    // 第一次广播后进入第二阶段
    'phase2_delay'    => 180,    // 第二次广播后可以分发果实
    'cooldown'        => 600,    // 事件结束后冷却时间（10分钟）
    'max_recipients'  => 3,      // 最多分发人数

    // === NPC 信息 ===
    'zhenyuan_name'  => '镇元大仙',
    'zhenyuan_title' => '五庄观观主',

    // === 人参果效果 ===
    'force_gain'      => 20,     // 最大内力增加量
    'mana_gain'       => 20,     // 最大法力增加量
    'live_forever_threshold' => 36,  // 累计吃够此数量触发长生不老
];
