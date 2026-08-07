<?php
/**
 * 停功命令 (stop)
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 功能：停止当前的修炼（打坐、冥思或练功）
 * 用法：stop
 */

function cmd_stop(int $charId, string $param = ''): array {
    // 检查各种修炼状态
    $isExercising = !empty($_SESSION['pending_exercising']);
    $isMeditating = !empty($_SESSION['pending_meditating']);
    $isPracticing = !empty($_SESSION['pending_practicing']);
    $isXiudao = !empty($_SESSION['doing_xiudao']) || !empty($_SESSION['pending_xiudao']);
    $isChanting = !empty($_SESSION['pending_chanting']);
    
    // 构建状态描述
    $states = [];
    if ($isExercising) {
        $states[] = '打坐';
    }
    if ($isMeditating) {
        $states[] = '冥思';
    }
    if ($isPracticing) {
        $states[] = '练功';
    }
    if ($isXiudao) {
        $states[] = '修道';
    }
    if ($isChanting) {
        $states[] = '诵经';
    }
    
    // 如果没有在修炼中
    if (empty($states)) {
        return [
            'success' => false, 
            'message' => '你并没有在修炼中。',
            'skip_queue' => true,
        ];
    }
    
    // 清除所有修炼状态标记
    unset($_SESSION['pending_exercising']);
    unset($_SESSION['pending_meditating']);
    unset($_SESSION['pending_practicing']);
    unset($_SESSION['doing_xiudao']);
    unset($_SESSION['pending_xiudao']);
    unset($_SESSION['pending_chanting']);
    
    // 清除忙碌状态
    set_player_busy($charId, 0);
    
    $stateStr = implode('、', $states);
    $message = "你停止了{$stateStr}。";
    
    return [
        'success' => true,
        'message' => $message,
        'stopped_states' => $states
    ];
}
