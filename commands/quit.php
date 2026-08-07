<?php
/**
 * 退出命令 (quit) - 退出游戏
 */
function cmd_quit(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 更新在线状态
    CharacterModel::updateOnlineStatus($charId, false);
    
    log_game('QUIT', "{$char['name']} 退出游戏");
    
    // 清除会话
    unset($_SESSION['char_id']);
    unset($_SESSION['char_name']);
    
    $message = "感谢游玩" . SERVER_NAME . "！\n";
    $message .= "欢迎下次再来！\n";
    
    return [
        'success' => true,
        'type' => 'quit',
        'message' => $message,
        'redirect' => '/login.php'
    ];
}

// 别名支持
if (!function_exists('cmd_q')) {
    function cmd_q(int $charId, string $param = ''): array {
        return cmd_quit($charId, $param);
    }
}

