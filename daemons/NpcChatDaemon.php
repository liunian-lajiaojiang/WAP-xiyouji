<?php
/**
 * NPC日常聊天守护进程
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 通过轮询附带触发（非独立进程）
 *
 * 触发方式：在chat.php的AJAX轮询(action=poll)中以小概率调用pulse()
 * 
 * chat_msg JSON格式:
 * [
 *   "李白低声长吟道：危楼高百尺，手可摘星辰。",
 *   "李白鼓腹而歌：赵客缦湖缨，吴钩霜雪明。",
 *   ["action", "drink", "李白拿起酒壶痛饮一口。"],
 *   ["emote", "smile", "李白微微一笑。"]
 * ]
 */

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/MessageDaemon.php';

class NpcChatDaemon {

    // 最小聊天间隔（秒），防止同一NPC过于频繁聊天
    const MIN_CHAT_INTERVAL = 30;

    /**
     * 心跳脉冲 - 触发所有活跃NPC的聊天判定
     * 在chat.php的轮询中以2%概率触发
     */
    public static function pulse(): void
    {
        try {
            // 检查唐僧是否该从生死轮回处复活（还原原始 call_out 1800秒）
            require_once DAEMON_PATH . 'QujingHandler.php';
            QujingHandler::checkTangSengRevive();

            // 获取所有有chat_msg配置且活跃的NPC
            // 只处理冷却时间已过的NPC
            $sql = "SELECT n.id, n.npc_id, n.name, n.chat_msg, n.chat_chance,
                           n.spawn_area, n.spawn_room, n.last_chat_time
                    FROM npcs n
                    WHERE n.is_active = 1
                    AND n.chat_msg IS NOT NULL
                    AND n.chat_msg != ''
                    AND n.chat_chance > 0
                    AND (n.last_chat_time IS NULL OR n.last_chat_time < DATE_SUB(NOW(), INTERVAL ? SECOND))";

            $npcs = Database::queryAll($sql, [self::MIN_CHAT_INTERVAL]);

            foreach ($npcs as $npc) {
                self::processNpcChat($npc);
            }
        } catch (Exception $e) {
            error_log("NpcChatDaemon::pulse error: " . $e->getMessage());
        }
    }

    /**
     * 处理单个NPC的聊天逻辑
     */
    private static function processNpcChat(array $npc): void
    {
        $chance = intval($npc['chat_chance']);
        if ($chance <= 0) return;

        // 概率判定
        if (random_int(1, 100) > $chance) return;

        // 解析聊天消息数组
        $chatMsgs = json_decode($npc['chat_msg'], true);
        if (empty($chatMsgs) || !is_array($chatMsgs)) return;

        // 随机选择一条消息
        $msg = $chatMsgs[array_rand($chatMsgs)];

        // 处理消息
        $output = self::processMessage($msg, $npc);
        if (empty($output)) return;

        // 广播到NPC所在房间
        $roomArea = $npc['spawn_area'] ?? '';
        $roomId = $npc['spawn_room'] ?? '';
        if (empty($roomId)) return;

        // 广播NPC消息到房间内所有在线玩家
        self::broadcastToNpcRoom($roomArea, $roomId, $output, $npc['name']);

        // 更新最后聊天时间
        Database::execute(
            "UPDATE npcs SET last_chat_time = NOW() WHERE id = ?",
            [$npc['id']]
        );
    }

    /**
     * 处理消息内容（字符串或动作数组）
     */
    private static function processMessage($msg, array $npc): ?string
    {
        if (is_string($msg)) {
            // 纯文本消息，加NPC名字颜色
            return HTML_HIGRN . $npc['name'] . HTML_NOR . '：' . HTML_CYN . $msg . HTML_NOR;
        }

        if (is_array($msg) && count($msg) >= 2) {
            $type = $msg[0];
            $action = $msg[1];
            $description = $msg[2] ?? null;

            switch ($type) {
                case 'action':
                    // 动作类型 - 返回描述文本（不实际执行移动等）
                    $text = $description ?? "{$npc['name']}做了一个动作。";
                    return HTML_HIMAG . $text . HTML_NOR;

                case 'emote':
                    // 表情类型
                    $text = $description ?? "{$npc['name']}{$action}。";
                    return HTML_HICYN . $text . HTML_NOR;

                case 'callable':
                    // 可调用类型 - 执行指定函数（还原LPC的 (: random_move :) 语法）
                    self::executeChatCallable($action, $npc);
                    $text = $description ?? "{$npc['name']}动了一下。";
                    return HTML_HIGRN . $npc['name'] . HTML_NOR . '：' . HTML_CYN . $text . HTML_NOR;

                default:
                    $text = $description ?? null;
                    if ($text === null) return null;
                    return HTML_CYN . $text . HTML_NOR;
            }
        }

        return null;
    }

    /**
     * 执行chat中的可调用函数（还原LPC的 (: function :) 语法）
     * 
     * @param string $action 函数名
     * @param array $npc NPC数据
     * @return void
     */
    private static function executeChatCallable(string $action, array $npc): void {
        switch ($action) {
            case 'random_move':
                // 唐僧随机移动关卡（还原原始 qujingren.c random_move_xuanzang）
                if ($npc['npc_id'] === 'qujing ren') {
                    require_once DAEMON_PATH . 'QujingHandler.php';
                    QujingHandler::randomMoveQujingren();
                }
                break;
            // 其他callable可在此扩展
        }
    }

    /**
     * 广播NPC消息到房间内所有在线玩家
     */
    private static function broadcastToNpcRoom(string $roomArea, string $roomId, string $message, string $npcName): void
    {
        // 检查房间是否有在线玩家（优化：无人房间不广播）
        $players = Database::queryAll(
            "SELECT id FROM characters
             WHERE current_room = ? AND online = 1",
            [$roomId]
        );

        if (empty($players)) return;

        // 为每个玩家插入消息（使用npc_chat类型）
        foreach ($players as $player) {
            MessageDaemon::sendToPlayer(intval($player['id']), $message, 'npc_chat');
        }
    }
}
