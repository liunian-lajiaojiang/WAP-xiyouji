<?php
/**
 * 查看消息历史命令 (history)
 * 查看最近的私聊或聊天历史
 */

function cmd_history(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 解析参数：tell（私聊）、chat（聊天）、all（全部）
    $type = strtolower(trim($param)) ?: 'all';
    
    $limit = 20; // 默认显示20条
    
    switch ($type) {
        case 'tell':
        case 't':
            // 查看私聊历史
            $sql = "SELECT m.*, c1.name as from_name, c2.name as to_name
                    FROM messages m
                    LEFT JOIN characters c1 ON m.from_char_id = c1.id
                    LEFT JOIN characters c2 ON m.to_char_id = c2.id
                    WHERE (m.from_char_id = ? OR m.to_char_id = ?)
                      AND m.type = 'tell'
                    ORDER BY m.timestamp DESC
                    LIMIT {$limit}";
            
            $messages = Database::queryAll($sql, [$charId, $charId]);
            $title = '私聊历史';
            break;
            
        case 'chat':
        case 'c':
            // 查看聊天历史
            $sql = "SELECT m.*, c.name as from_name
                    FROM messages m
                    LEFT JOIN characters c ON m.from_char_id = c.id
                    WHERE m.type = 'chat'
                    ORDER BY m.timestamp DESC
                    LIMIT {$limit}";
            
            $messages = Database::queryAll($sql);
            $title = '聊天历史';
            break;
            
        default:
            // 查看所有消息
            $sql = "SELECT m.*, c1.name as from_name, c2.name as to_name
                    FROM messages m
                    LEFT JOIN characters c1 ON m.from_char_id = c1.id
                    LEFT JOIN characters c2 ON m.to_char_id = c2.id
                    WHERE m.from_char_id = ? OR m.to_char_id = ? OR m.to_char_id IS NULL
                    ORDER BY m.timestamp DESC
                    LIMIT {$limit}";
            
            $messages = Database::queryAll($sql, [$charId, $charId]);
            $title = '消息历史';
            break;
    }
    
    if (empty($messages)) {
        return [
            'success' => true,
            'type' => 'history',
            'output' => '没有找到消息记录'
        ];
    }
    
    // 构建输出
    $output = [];
    $output[] = HICYN . "=== {$title} ===" . NOR;
    $output[] = '';
    
    // 反转数组，按时间正序显示
    $messages = array_reverse($messages);
    
    foreach ($messages as $msg) {
        $time = date('H:i:s', strtotime($msg['timestamp']));
        
        switch ($msg['type']) {
            case 'say':
                $output[] = "[{$time}] " . HICYN . $msg['from_name'] . NOR . '说：' . HIYEL . h($msg['message']) . NOR;
                break;
                
            case 'chat':
                $output[] = "[{$time}] " . MAG . '【聊天】' . HICYN . $msg['from_name'] . NOR . '：' . HIYEL . h($msg['message']) . NOR;
                break;
                
            case 'tell':
                if ($msg['from_char_id'] == $charId) {
                    $output[] = "[{$time}] " . HICYN . '你对' . $msg['to_name'] . '说道：' . HIYEL . h($msg['message']) . NOR;
                } else {
                    $output[] = "[{$time}] " . HICYN . $msg['from_name'] . '对你说道：' . HIYEL . h($msg['message']) . NOR;
                }
                break;
                
            case 'emote':
                $output[] = "[{$time}] " . HIGRN . $msg['from_name'] . NOR . ' ' . HIYEL . h($msg['message']) . NOR;
                break;
        }
    }
    
    return [
        'success' => true,
        'type' => 'history',
        'output' => implode("\n", $output)
    ];
}

// 别名：hist
if (!function_exists('cmd_hist')) {
    function cmd_hist(int $charId, string $param = ''): array {
        return cmd_history($charId, $param);
    }
}

