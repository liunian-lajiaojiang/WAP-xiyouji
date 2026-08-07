<?php
/**
 * 大闹天宫特殊交互命令
 * 
 * dntg_pick_peach  - 偷桃（蟠桃园）
 * dntg_disturb     - 搅乱蟠桃会（瑶池）
 * dntg_take_pill   - 盗取仙丹（兜率宫）
 */

require_once DAEMON_PATH . 'DntgQuestHandler.php';

/**
 * 偷桃命令
 */
function cmd_dntg_pick_peach(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    $roomId = $char['current_room'] ?? '';
    $dntgResult = DntgQuestHandler::checkInteraction($charId, 'pick_peach', $roomId);
    
    if ($dntgResult && !empty($dntgResult['success'])) {
        $message = HTML_HIGRN . '【大闹天宫】' . HTML_NOR . ' 你悄悄摘下一颗九千年一熟的蟠桃，三口两口吃了下去，顿觉神清气爽！';
        if (!empty($dntgResult['message'])) {
            $message .= "\n" . $dntgResult['message'];
        }
        return [
            'success' => true,
            'message' => $message,
            'output'  => $message,
        ];
    }
    
    return ['success' => false, 'message' => '这里没有什么桃可偷。'];
}

/**
 * 搅乱蟠桃会命令
 */
function cmd_dntg_disturb(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    $roomId = $char['current_room'] ?? '';
    $dntgResult = DntgQuestHandler::checkInteraction($charId, 'disturb', $roomId);
    
    if ($dntgResult && !empty($dntgResult['success'])) {
        $message = HTML_HIRED . '【大闹天宫】' . HTML_NOR . ' 你施展神通，将瑶池上的琼浆玉液打翻在地，仙果佳肴滚落一地，众仙惊慌失措，蟠桃会变成了一场闹剧！';
        if (!empty($dntgResult['message'])) {
            $message .= "\n" . $dntgResult['message'];
        }
        return [
            'success' => true,
            'message' => $message,
            'output'  => $message,
        ];
    }
    
    return ['success' => false, 'message' => '这里没什么可搅乱的。'];
}

/**
 * 盗取仙丹命令
 */
function cmd_dntg_take_pill(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    $roomId = $char['current_room'] ?? '';
    $dntgResult = DntgQuestHandler::checkInteraction($charId, 'take_pill', $roomId);
    
    if ($dntgResult && !empty($dntgResult['success'])) {
        $message = HTML_HIGRN . '【大闹天宫】' . HTML_NOR . ' 你趁太上老君不在，将葫芦里的仙丹一股脑儿倒入口中，顿觉体内热气翻涌，修为大涨！';
        if (!empty($dntgResult['message'])) {
            $message .= "\n" . $dntgResult['message'];
        }
        return [
            'success' => true,
            'message' => $message,
            'output'  => $message,
        ];
    }
    
    return ['success' => false, 'message' => '这里没什么仙丹可盗。'];
}
