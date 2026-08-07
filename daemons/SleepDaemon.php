<?php

class SleepDaemon {

    public static function pulse(): void {
        self::checkAndWakeupExpiredSleepers();
        self::checkAndWakeupExpiredUnconscious();
        self::checkAndWakeupExpiredDaze();
        self::processDrunkStatus();
    }

    /**
     * 处理酒醉状态（自动降低酒醉值）
     * 参考原始项目 heart_beat 机制
     */
    private static function processDrunkStatus(): void {
        // 找出所有有酒醉状态的在线玩家
        $drunkPlayers = Database::queryAll(
            "SELECT cb.id, cb.char_id, cb.value, cb.duration, c.name, c.con, c.max_force, c.current_room, c.unconscious_state
             FROM character_buffs cb
             JOIN characters c ON cb.char_id = c.id
             WHERE cb.buff_type = 'drunk' 
               AND cb.duration > 0
               AND c.online = 1",
            []
        );

        if (empty($drunkPlayers)) {
            return;
        }

        require_once __DIR__ . '/MessageDaemon.php';

        foreach ($drunkPlayers as $player) {
            $charId = intval($player['char_id']);
            $drunkValue = intval($player['value']);
            $duration = intval($player['duration']);
            $con = intval($player['con'] ?? 10);
            $maxForce = intval($player['max_force'] ?? 0);
            $limit = $con * 6 + intval($maxForce / 50);
            $wasUnconscious = !empty($player['unconscious_state']) && $player['unconscious_state'] == 1;
            $charName = $player['name'];
            $roomId = $player['current_room'];

            // 减少酒醉值和持续时间
            $newValue = max(0, $drunkValue - 1);
            $newDuration = $duration - 1;
            
            if ($newDuration <= 0 || $newValue <= 0) {
                // 酒醉消退了
                Database::execute("DELETE FROM character_buffs WHERE id = ?", [$player['id']]);
                
                // 如果之前是昏迷状态，现在苏醒
                if ($wasUnconscious) {
                    Database::execute(
                        'UPDATE characters SET unconscious_state = 0, unconscious_end_time = NULL WHERE id = ?',
                        [$charId]
                    );
                    
                    // 清除 Session 昏迷标记
                    unset($_SESSION["unconscious_{$charId}"]);

                    $roomMsg = "<span style='color: #00FFFF;'>{$charName}的酒意渐渐退去，神色恢复了正常。</span>";
                    $selfMsg = "<span style='color: #00FFFF;'>你的醉意已经完全消退了。</span>";
                    
                    self::sendRoomMessage($charId, $roomId, $roomMsg);
                    MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'room_event');
                }
            } else {
                // 更新酒醉值和持续时间
                Database::execute(
                    "UPDATE character_buffs SET value = ?, duration = ? WHERE id = ?",
                    [$newValue, $newDuration, $player['id']]
                );

                // 检查是否陷入重度酒醉昏迷
                if (!$wasUnconscious && $newValue > $limit) {
                    // 陷入昏迷
                    $excess = $newValue - $limit;
                    $unconsciousSeconds = max(30, $excess * 5);
                    $unconsciousEndTime = time() + $unconsciousSeconds;
                    
                    Database::execute(
                        'UPDATE characters SET unconscious_state = 1, unconscious_end_time = ? WHERE id = ?',
                        [$unconsciousEndTime, $charId]
                    );

                    // 同步设置 Session 昏迷标记
                    $_SESSION["unconscious_{$charId}"] = [
                        'timestamp' => time(),
                        'duration' => $unconsciousSeconds,
                    ];

                    $roomMsg = "<span style='color: #FF4444;'>只见{$charName}喝得烂醉如泥，一头栽倒在地，不省人事！</span>";
                    $selfMsg = "<span style='color: #FF4444;'>你喝得烂醉如泥，一头栽倒在地，不省人事！</span>";
                    
                    self::sendRoomMessage($charId, $roomId, $roomMsg);
                    MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'room_event');
                }
                // 检查是否从重度酒醉降到了中度以下（苏醒）
                else if ($wasUnconscious && $newValue <= $limit) {
                    // 从昏迷中苏醒
                    Database::execute(
                        'UPDATE characters SET unconscious_state = 0, unconscious_end_time = NULL WHERE id = ?',
                        [$charId]
                    );

                    // 清除 Session 昏迷标记
                    unset($_SESSION["unconscious_{$charId}"]);

                    $roomMsg = "<span style='color: #00FFFF;'>{$charName}从酒醉昏迷中苏醒了过来。</span>";
                    $selfMsg = "<span style='color: #00FFFF;'>你从酒醉昏迷中苏醒了过来。</span>";
                    
                    self::sendRoomMessage($charId, $roomId, $roomMsg);
                    MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'room_event');
                }
            }
        }
    }
    
    /**
     * 发送房间消息
     */
    private static function sendRoomMessage(int $charId, string $roomId, string $message): void {
        if (empty($roomId)) return;
        
        // 获取房间内其他在线玩家
        $otherPlayers = Database::queryAll(
            "SELECT id FROM characters WHERE current_room = ? AND online = 1 AND id != ?",
            [$roomId, $charId]
        );
        
        foreach ($otherPlayers as $player) {
            MessageDaemon::sendToPlayer(intval($player['id']), $message, 'room');
        }
    }

    private static function checkAndWakeupExpiredSleepers(): void {
        $sleepers = Database::queryAll(
            "SELECT id, name, sleep_end_time
             FROM characters
             WHERE sleep_state = 1
               AND sleep_end_time IS NOT NULL
               AND sleep_end_time <= UNIX_TIMESTAMP()
               AND online = 1",
            []
        );

        if (empty($sleepers)) {
            return;
        }

        require_once __DIR__ . '/../commands/sleep.php';

        foreach ($sleepers as $sleeper) {
            $charId = intval($sleeper['id']);
            try {
                wakeup_player($charId);
            } catch (\Exception $e) {
                error_log("[SleepDaemon] Failed to wakeup player {$charId}: " . $e->getMessage());
            }
        }
    }

    private static function checkAndWakeupExpiredUnconscious(): void {
        $unconscious = Database::queryAll(
            "SELECT id, name, kee, unconscious_end_time
             FROM characters
             WHERE unconscious_state = 1
               AND unconscious_end_time IS NOT NULL
               AND unconscious_end_time <= UNIX_TIMESTAMP()
               AND online = 1",
            []
        );

        if (empty($unconscious)) {
            return;
        }

        require_once __DIR__ . '/MessageDaemon.php';

        foreach ($unconscious as $char) {
            $charId = intval($char['id']);
            $currentKee = intval($char['kee'] ?? 0);

            try {
                // 苏醒时：如果气血仍为0，设置濒死标记（二次受伤触发死亡）
                // 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
                $nearDeathTime = ($currentKee <= 0) ? time() : 0;

                Database::execute(
                    'UPDATE characters SET unconscious_state = 0, unconscious_end_time = NULL, near_death_time = ? WHERE id = ?',
                    [$nearDeathTime, $charId]
                );

                // 同步清除 Session 昏迷标记
                unset($_SESSION["unconscious_{$charId}"]);

                $roomMsg = "<span style='color: #FFD700;'>{$char['name']}缓缓睁开眼睛，从昏迷中苏醒了过来。</span>";
                $selfMsg = "<span style='color: #FFD700;'>你缓缓睁开眼睛，从昏迷中苏醒了过来。</span>";

                if ($currentKee <= 0) {
                    $selfMsg .= "\n<span style='color: #FF4444;'>⚠️ 你已处于濒死状态！气血为零，再次受伤将导致真正死亡！请立即恢复气血！</span>";
                }

                MessageDaemon::sendRoomMessage($charId, $roomMsg);
                MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'room_event');
            } catch (\Exception $e) {
                error_log("[SleepDaemon] Failed to wakeup unconscious player {$charId}: " . $e->getMessage());
            }
        }
    }

    private static function checkAndWakeupExpiredDaze(): void {
        $dazed = Database::queryAll(
            "SELECT id, name, daze_end_time
             FROM characters
             WHERE daze_state = 1
               AND daze_end_time IS NOT NULL
               AND daze_end_time <= UNIX_TIMESTAMP()
               AND online = 1",
            []
        );

        if (empty($dazed)) {
            return;
        }

        require_once __DIR__ . '/MessageDaemon.php';

        foreach ($dazed as $char) {
            $charId = intval($char['id']);
            try {
                Database::execute(
                    'UPDATE characters SET daze_state = 0, daze_end_time = NULL WHERE id = ?',
                    [$charId]
                );

                $roomMsg = "<span style='color: #FFD700;'>{$char['name']}从发呆中回过神来。</span>";
                $selfMsg = "<span style='color: #FFD700;'>你从发呆中回过神来。</span>";

                MessageDaemon::sendRoomMessage($charId, $roomMsg);
                MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'room_event');
            } catch (\Exception $e) {
                error_log("[SleepDaemon] Failed to wakeup dazed player {$charId}: " . $e->getMessage());
            }
        }
    }
}

