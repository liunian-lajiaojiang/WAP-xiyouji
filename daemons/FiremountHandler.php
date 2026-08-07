<?php
require_once __DIR__ . '/../includes/db.php';

class FiremountHandler {
    
    const BURNING_ROOM = 'qujing/firemount/huoyan';
    const SHANBIAN_ROOM = 'qujing/firemount/shanbian';
    const SHANWAI_ROOM = 'qujing/firemount/shanwai';
    
    public static function isBurning(): bool {
        $var = Database::queryOne("SELECT value FROM variables WHERE var_key = 'firemount_burning'");
        return $var && $var['value'] == '1';
    }
    
    public static function setBurning(bool $burning): void {
        Database::execute(
            "INSERT INTO variables (var_key, value) VALUES ('firemount_burning', ?) 
             ON DUPLICATE KEY UPDATE value = ?",
            [$burning ? '1' : '0', $burning ? '1' : '0']
        );
    }
    
    public static function getPlayerState(int $charId): array {
        $state = Database::queryOne(
            "SELECT * FROM character_temp_states WHERE char_id = ? AND state_key LIKE 'firemount_%'",
            [$charId]
        );
        
        if (!$state) {
            return [
                'burnt_in_mount' => 0,
                'fanned_in_mount' => 0,
                'fan_times_in_mount' => 0,
                'fainted_in_mount' => 0
            ];
        }
        
        $data = json_decode($state['state_value'], true) ?: [];
        return array_merge([
            'burnt_in_mount' => 0,
            'fanned_in_mount' => 0,
            'fan_times_in_mount' => 0,
            'fainted_in_mount' => 0
        ], $data);
    }
    
    public static function setPlayerState(int $charId, array $state): void {
        $existing = Database::queryOne(
            "SELECT id FROM character_temp_states WHERE char_id = ? AND state_key = 'firemount_state'",
            [$charId]
        );
        
        $value = json_encode($state);
        
        if ($existing) {
            Database::execute(
                "UPDATE character_temp_states SET state_value = ?, updated_at = NOW() 
                 WHERE char_id = ? AND state_key = 'firemount_state'",
                [$value, $charId]
            );
        } else {
            Database::execute(
                "INSERT INTO character_temp_states (char_id, state_key, state_value) 
                 VALUES (?, 'firemount_state', ?)",
                [$charId, $value]
            );
        }
    }
    
    public static function getLastDamageTime(int $charId): int {
        $state = self::getPlayerState($charId);
        return intval($state['last_damage_time'] ?? 0);
    }
    
    public static function setLastDamageTime(int $charId, int $time): void {
        $state = self::getPlayerState($charId);
        $state['last_damage_time'] = $time;
        self::setPlayerState($charId, $state);
    }
    
    public static function checkFlameDamage(int $charId, string $currentRoom): string {
        if ($currentRoom !== self::BURNING_ROOM || !self::isBurning()) {
            return '';
        }
        
        $lastDamage = self::getLastDamageTime($charId);
        $now = time();
        
        if ($now - $lastDamage < 10) {
            return '';
        }
        
        self::setLastDamageTime($charId, $now);
        
        $state = self::getPlayerState($charId);
        
        if ($state['burnt_in_mount']) {
            return '';
        }
        
        $rand = mt_rand(0, 7);
        if ($rand == 0) {
            $state['burnt_in_mount'] = 0;
            $state['fanned_in_mount'] = 0;
            $state['fan_times_in_mount'] = 0;
            $state['fainted_in_mount'] = 1;
            self::setPlayerState($charId, $state);
            
            self::teleportPlayer($charId, self::SHANBIAN_ROOM);
            
            return "一阵狂风烈火将{$charId}卷起一阵，{$charId}昏了过去...\n{$charId}从浓烟中醒来，发现自己躺在山边。";
        }
        
        $rand2 = mt_rand(0, 1);
        if ($rand2 == 0) {
            $destroyed = self::destroyRandomItem($charId);
            if ($destroyed) {
                return "只听见噗的一声{$charId}的{$destroyed}顿时化为灰烬。";
            }
        }
        
        $damageMsg = self::getDamageMessage();
        $damage = mt_rand(5, 14);
        
        self::damagePlayer($charId, $damage);
        
        return $damageMsg;
    }
    
    public static function getDamageMessage(): string {
        $messages = [
            '你觉得头上一阵剧痛。',
            '你感到一阵灼热！',
            '你觉得浑身都冒出火来！',
            '你闻到一阵焦味。'
        ];
        return $messages[mt_rand(0, count($messages) - 1)];
    }
    
    public static function destroyRandomItem(int $charId): ?string {
        $items = Database::queryAll(
            "SELECT id, item_id, name FROM character_inventory WHERE char_id = ? AND item_id != 'tieshan'",
            [$charId]
        );
        
        if (empty($items)) {
            return null;
        }
        
        $item = $items[mt_rand(0, count($items) - 1)];
        
        Database::execute(
            "DELETE FROM character_inventory WHERE id = ?",
            [$item['id']]
        );
        
        return $item['name'];
    }
    
    public static function damagePlayer(int $charId, int $damage): void {
        Database::execute(
            "UPDATE characters SET kee = GREATEST(1, kee - ?), sen = GREATEST(1, sen - ?) WHERE id = ?",
            [$damage, $damage, $charId]
        );
    }
    
    public static function teleportPlayer(int $charId, string $room): void {
        Database::execute(
            "UPDATE characters SET current_room = ? WHERE id = ?",
            [$room, $charId]
        );
    }
    
    public static function extinguishFire(int $charId): array {
        if (!self::isBurning()) {
            return [
                'success' => false,
                'message' => '火焰山已经没有火了，你是不是热糊涂了？'
            ];
        }
        
        $hasIronFan = Database::queryOne(
            "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = 'tieshan'",
            [$charId]
        );
        
        if (!$hasIronFan) {
            return [
                'success' => false,
                'message' => '你没有铁扇，无法灭火！'
            ];
        }
        
        $state = self::getPlayerState($charId);
        
        if ($state['fanned_in_mount']) {
            return [
                'success' => false,
                'message' => '呼哧呼哧地扇了半天，什么动静也没有发生。'
            ];
        }
        
        $state['fanned_in_mount'] = 1;
        $state['fan_times_in_mount'] = $state['fan_times_in_mount'] + 1;
        self::setPlayerState($charId, $state);
        
        self::setLastDamageTime($charId, time());
        
        $success = (mt_rand(0, 4) == 0) && 
                   $state['fainted_in_mount'] && 
                   $state['fan_times_in_mount'] >= 4;
        
        if ($success) {
            return self::successQuest($charId);
        }
        
        $state['fanned_in_mount'] = 0;
        self::setPlayerState($charId, $state);
        
        return [
            'success' => true,
            'message' => '一阵狂风吹过卷起漫天火焰，火势更加凶猛了！'
        ];
    }
    
    public static function successQuest(int $charId): array {
        self::setBurning(false);
        
        $char = Database::queryOne("SELECT name FROM characters WHERE id = ?", [$charId]);
        $charName = $char['name'] ?? '玩家';
        
        $message = "\n铁扇公主出现在山巅！一阵清风过后，火焰山的火势渐渐平息了...\n";
        $message .= "{$charName}站稳了脚跟，发现四周的火焰已经渐渐熄灭了。\n";
        $message .= "天空中出现了一座平顶山，清风徐来，鸟语花香。";
        
        $state = self::getPlayerState($charId);
        $state['fainted_in_mount'] = 0;
        $state['fan_times_in_mount'] = 0;
        $state['fanned_in_mount'] = 0;
        $state['burnt_in_mount'] = 0;
        self::setPlayerState($charId, $state);
        
        Database::execute(
            "UPDATE characters SET obstacle_firemount = 'done' WHERE id = ?",
            [$charId]
        );
        
        Database::execute(
            "UPDATE character_quests SET status = 'completed', complete_time = NOW() 
             WHERE char_id = ? AND quest_type = 'qujing_escort' AND quest_id = 'firemount'",
            [$charId]
        );
        
        $reward = 18000;
        Database::execute(
            "UPDATE characters SET daoxing = daoxing + ? WHERE id = ?",
            [$reward, $charId]
        );
        
        require_once __DIR__ . '/MessageDaemon.php';
        MessageDaemon::broadcastToAll("恭喜{$charName}成功熄灭火焰山！获得{$reward}点道行奖励！");
        
        return [
            'success' => true,
            'message' => $message,
            'reward' => $reward
        ];
    }
    
    public static function resetFire(): void {
        self::setBurning(true);
        
        $state = Database::queryOne("SELECT value FROM variables WHERE var_key = 'firemount_reset_time'");
        if (!$state) {
            Database::execute(
                "INSERT INTO variables (var_key, value) VALUES ('firemount_reset_time', ?)",
                [time()]
            );
        } else {
            Database::execute(
                "UPDATE variables SET value = ? WHERE var_key = 'firemount_reset_time'",
                [time()]
            );
        }
    }
    
    public static function checkReset(): void {
        $resetTime = Database::queryOne("SELECT value FROM variables WHERE var_key = 'firemount_reset_time'");
        if (!$resetTime || !self::isBurning()) {
            return;
        }
        
        $lastReset = intval($resetTime['value']);
        if (time() - $lastReset >= 1800) {
            self::resetFire();
        }
    }
    
    public static function getRoomExits(string $room): ?array {
        if ($room !== self::BURNING_ROOM) {
            return null;
        }
        
        if (self::isBurning()) {
            $huoyanRoom = Database::queryOne("SELECT name FROM rooms WHERE room_id = ?", [self::BURNING_ROOM]);
            $targetName = $huoyanRoom['name'] ?? '火焰山';
            return [
                [
                    'direction' => 'westdown',
                    'target_area' => 'qujing',
                    'target_room' => 'qujing/firemount/huoyan',
                    'door_name' => null,
                    'door_closed' => 0,
                    'target_name' => $targetName,
                    'is_dynamic' => true
                ],
                [
                    'direction' => 'eastdown',
                    'target_area' => 'qujing',
                    'target_room' => 'qujing/firemount/huoyan',
                    'door_name' => null,
                    'door_closed' => 0,
                    'target_name' => $targetName,
                    'is_dynamic' => true
                ],
                [
                    'direction' => 'northup',
                    'target_area' => 'qujing',
                    'target_room' => 'qujing/firemount/huoyan',
                    'door_name' => null,
                    'door_closed' => 0,
                    'target_name' => $targetName,
                    'is_dynamic' => true
                ],
                [
                    'direction' => 'southup',
                    'target_area' => 'qujing',
                    'target_room' => 'qujing/firemount/huoyan',
                    'door_name' => null,
                    'door_closed' => 0,
                    'target_name' => $targetName,
                    'is_dynamic' => true
                ]
            ];
        }
        
        $shanwaiRoom = Database::queryOne("SELECT name FROM rooms WHERE room_id = ?", [self::SHANWAI_ROOM]);
        $shanbianRoom = Database::queryOne("SELECT name FROM rooms WHERE room_id = ?", [self::SHANBIAN_ROOM]);
        
        return [
            [
                'direction' => 'northwest',
                'target_area' => 'qujing',
                'target_room' => self::SHANWAI_ROOM,
                'door_name' => null,
                'door_closed' => 0,
                'target_name' => $shanwaiRoom['name'] ?? '山外',
                'is_dynamic' => true
            ],
            [
                'direction' => 'eastdown',
                'target_area' => 'qujing',
                'target_room' => self::SHANBIAN_ROOM,
                'door_name' => null,
                'door_closed' => 0,
                'target_name' => $shanbianRoom['name'] ?? '山边',
                'is_dynamic' => true
            ]
        ];
    }
    
    public static function handleCommand(int $charId, string $command, string $arg): ?string {
        if (in_array($command, ['fan', 'extinguish', 'shan'])) {
            $char = Database::queryOne("SELECT current_room FROM characters WHERE id = ?", [$charId]);
            if (!$char || $char['current_room'] !== self::BURNING_ROOM) {
                return '你不在火焰山燃烧的地方，无法使用铁扇！';
            }
            $result = self::extinguishFire($charId);
            return $result['message'];
        }
        
        if ($command === 'search') {
            return self::searchBone($charId, $arg);
        }
        
        return null;
    }
    
    public static function searchBone(int $charId, string $arg): ?string {
        if ($arg !== 'bone') {
            return null;
        }
        
        $char = Database::queryOne(
            "SELECT current_room, combat_exp FROM characters WHERE id = ?",
            [$charId]
        );
        
        if (!$char) {
            return null;
        }
        
        $validRooms = ['qujing/firemount/cuiyun3', 'qujing/firemount/cuiyun4', 'qujing/firemount/cuiyun5'];
        if (!in_array($char['current_room'], $validRooms)) {
            return '这里没有什么可以搜索的。';
        }
        
        $state = self::getPlayerState($charId);
        if (!isset($state['know_palm_bone']) || $state['know_palm_bone'] != 1) {
            return '你不知道该去哪里找芭蕉骨。';
        }
        
        if ($char['combat_exp'] < 4000) {
            return '你的经验太低了，连爬铁树的力气都没有。';
        }
        
        $roomBusy = Database::queryOne(
            "SELECT value FROM room_states WHERE room_path = ? AND state_key = 'busy'",
            [$char['current_room']]
        );
        
        if ($roomBusy && time() - intval($roomBusy['value']) < 5) {
            Database::execute(
                "UPDATE room_states SET value = ? WHERE room_path = ? AND state_key = 'busy'",
                [time() + mt_rand(5, 10), $char['current_room']]
            );
            return '你在铁树上爬来爬去。';
        }
        
        $noBone = Database::queryOne(
            "SELECT value FROM room_states WHERE room_path = ? AND state_key = 'no_bone'",
            [$char['current_room']]
        );
        
        if ($noBone && time() - intval($noBone['value']) < 3600) {
            return '你仔细搜索了铁树，但是什么也没有找到。';
        }
        
        if (mt_rand(0, 9) == 0) {
            Database::execute(
                "INSERT INTO character_inventory (char_id, item_id, category, quantity) 
                 VALUES (?, 'bajiaogu', 'misc', 1)",
                [$charId]
            );
            
            $state['bone_count'] = ($state['bone_count'] ?? 0) + 1;
            self::setPlayerState($charId, $state);
            
            Database::execute(
                "INSERT INTO room_states (room_path, state_key, value) 
                 VALUES (?, 'no_bone', ?)
                 ON DUPLICATE KEY UPDATE value = ?",
                [$char['current_room'], time(), time()]
            );
            
            $count = $state['bone_count'];
            $msg = "你发现了一根芭蕉骨！";
            if ($count >= 10) {
                $msg .= "\n你已经收集了{$count}根芭蕉骨，可以去找云里雾或雾里云了！";
            } else {
                $msg .= "\n你已经收集了{$count}根芭蕉骨（还需要" . (10 - $count) . "根）。";
            }
            return $msg;
        }
        
        Database::execute(
            "INSERT INTO room_states (room_path, state_key, value) 
             VALUES (?, 'busy', ?)
             ON DUPLICATE KEY UPDATE value = ?",
            [$char['current_room'], time() + mt_rand(5, 10), time() + mt_rand(5, 10)]
        );
        
        return '你在铁树上爬来爬去寻找芭蕉骨。';
    }
    
    public static function getBoneCount(int $charId): int {
        $state = self::getPlayerState($charId);
        return intval($state['bone_count'] ?? 0);
    }
    
    public static function teachBoneLocation(int $charId): string {
        $state = self::getPlayerState($charId);
        $state['know_palm_bone'] = 1;
        self::setPlayerState($charId, $state);
        
        return '土地公告诉你：火焰山翠云山的铁树林里藏有芭蕉骨，你可以去那里搜索收集。';
    }
    
    public static function introduceToPrincess(int $charId): array {
        $state = self::getPlayerState($charId);
        $boneCount = intval($state['bone_count'] ?? 0);
        
        if ($boneCount < 10) {
            return [
                'success' => false,
                'message' => "你才收集了{$boneCount}根芭蕉骨，还不够！再去收集一些吧。"
            ];
        }
        
        $hasFan = Database::queryOne(
            "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = 'tieshan'",
            [$charId]
        );
        
        if ($hasFan) {
            return [
                'success' => false,
                'message' => '你已经有铁扇了，不用再找铁扇公主了。'
            ];
        }
        
        Database::execute(
            "INSERT INTO character_inventory (char_id, item_id, category, quantity) 
             VALUES (?, 'tieshan', 'weapon', 1)",
            [$charId]
        );
        
        $state['bone_count'] = 0;
        self::setPlayerState($charId, $state);
        
        return [
            'success' => true,
            'message' => '云里雾带你见到了铁扇公主！铁扇公主见你诚心，赐予你一把铁扇！'
        ];
    }
    
    public static function getIronFan(int $charId): array {
        $hasFan = Database::queryOne(
            "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = 'tieshan'",
            [$charId]
        );
        
        if ($hasFan) {
            return [
                'success' => false,
                'message' => '你已经有铁扇了！'
            ];
        }
        
        $char = Database::queryOne("SELECT combat_exp FROM characters WHERE id = ?", [$charId]);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        if ($char['combat_exp'] >= 60000) {
            Database::execute(
                "INSERT INTO character_inventory (char_id, item_id, category, quantity) 
                 VALUES (?, 'tieshan', 'weapon', 1)",
                [$charId]
            );
            return [
                'success' => true,
                'message' => '铁扇公主见你武艺高强，慷慨地赐予你一把铁扇！'
            ];
        }
        
        $state = self::getPlayerState($charId);
        $boneCount = intval($state['bone_count'] ?? 0);
        
        if ($boneCount >= 10) {
            Database::execute(
                "INSERT INTO character_inventory (char_id, item_id, category, quantity) 
                 VALUES (?, 'tieshan', 'weapon', 1)",
                [$charId]
            );
            $state['bone_count'] = 0;
            self::setPlayerState($charId, $state);
            return [
                'success' => true,
                'message' => '铁扇公主见你收集了足够的芭蕉骨，赐予你一把铁扇！'
            ];
        }
        
        return [
            'success' => false,
            'message' => '铁扇公主说道：你要么拿出足够的诚意（收集10根芭蕉骨），要么展示你的实力（60000经验以上）。'
        ];
    }
    
    public static function getShimenStone(int $charId): array {
        $char = Database::queryOne("SELECT name FROM characters WHERE id = ?", [$charId]);
        $charName = $char['name'] ?? '玩家';
        
        Database::execute(
            "INSERT INTO character_inventory (char_id, item_id, category, quantity) 
             VALUES (?, 'stone-firemount', 'weapon', 1)
             ON DUPLICATE KEY UPDATE quantity = quantity + 1",
            [$charId]
        );
        
        return [
            'success' => true,
            'message' => "你从乱石堆中拿到一块石块。",
            'skip_queue' => true,
            'skip_flash' => true
        ];
    }
    
    public static function hitShimen(int $charId): array {
        $char = Database::queryOne("SELECT name, current_room FROM characters WHERE id = ?", [$charId]);
        $charName = $char['name'] ?? '玩家';
        
        if ($char['current_room'] !== 'qujing/firemount/shimen') {
            return ['success' => false, 'message' => '你不在石门旁边！', 'skip_queue' => true, 'skip_flash' => true];
        }
        
        $hasStone = Database::queryOne(
            "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = 'stone-firemount' AND quantity > 0",
            [$charId]
        );
        
        if (!$hasStone) {
            return ['success' => false, 'message' => '你没有石块！先在石门旁捡一块吧。', 'skip_queue' => true, 'skip_flash' => true];
        }
        
        $state = self::getPlayerState($charId);
        $hitCount = intval($state['firemount_hit_door'] ?? 0) + 1;
        $state['firemount_hit_door'] = $hitCount;
        self::setPlayerState($charId, $state);
        
        if ($hitCount > 10) {
            return [
                'success' => true,
                'message' => "你用石块砸了一下石门，不小心打到了自己的脚。",
                'skip_queue' => true,
                'skip_flash' => true
            ];
        }
        
        Database::execute(
            "UPDATE character_inventory SET quantity = quantity - 1 WHERE id = ?",
            [$hasStone['id']]
        );
        
        if (mt_rand(0, 4) == 0) {
            self::cloudOrFogAppear($charId);
            $state['firemount_hit_door'] = 0;
            self::setPlayerState($charId, $state);
        }
        
        return [
            'success' => true,
            'message' => "你用石块砸向石门，发出咚的一声。",
            'skip_queue' => true,
            'skip_flash' => true
        ];
    }
    
    public static function cloudOrFogAppear(int $charId): void {
        $char = Database::queryOne("SELECT current_room FROM characters WHERE id = ?", [$charId]);
        if (!$char) return;
        
        $roomId = $char['current_room'];
        
        $cloudExists = Database::queryOne(
            "SELECT id FROM npcs n 
             INNER JOIN npc_temp nt ON n.id = nt.npc_id 
             WHERE nt.temp_key = 'current_location' 
               AND nt.temp_value = ? 
               AND n.npc_id = 'cloud'",
            [json_encode(['area' => 'qujing', 'room' => $roomId])]
        );
        
        $fogExists = Database::queryOne(
            "SELECT id FROM npcs n 
             INNER JOIN npc_temp nt ON n.id = nt.npc_id 
             WHERE nt.temp_key = 'current_location' 
               AND nt.temp_value = ? 
               AND n.npc_id = 'fog'",
            [json_encode(['area' => 'qujing', 'room' => $roomId])]
        );
        
        require_once __DIR__ . '/MessageDaemon.php';
        
        if (!$cloudExists && mt_rand(0, 1) == 0) {
            $npc = Database::queryOne("SELECT * FROM npcs WHERE npc_id = 'cloud' LIMIT 1");
            if ($npc) {
                $locationJson = json_encode(['area' => 'qujing', 'room' => $roomId]);
                Database::execute(
                    "INSERT INTO npc_temp (npc_id, temp_key, temp_value) 
                     VALUES (?, 'current_location', ?)
                     ON DUPLICATE KEY UPDATE temp_value = ?, temp_key = 'current_location'",
                    [$npc['id'], $locationJson, $locationJson]
                );
                Database::execute(
                    "INSERT INTO npc_temp (npc_id, temp_key, temp_value) 
                     VALUES (?, 'expire_time', ?)
                     ON DUPLICATE KEY UPDATE temp_value = ?",
                    [$npc['id'], strval(time() + 20), strval(time() + 20)]
                );
                MessageDaemon::broadcastToRoom(
                    $roomId,
                    "一阵风平地而起，云里雾出现了！",
                    $charId
                );
            }
        }
        
        if (!$fogExists && mt_rand(0, 1) == 0) {
            $npc = Database::queryOne("SELECT * FROM npcs WHERE npc_id = 'fog' LIMIT 1");
            if ($npc) {
                $locationJson = json_encode(['area' => 'qujing', 'room' => $roomId]);
                Database::execute(
                    "INSERT INTO npc_temp (npc_id, temp_key, temp_value) 
                     VALUES (?, 'current_location', ?)
                     ON DUPLICATE KEY UPDATE temp_value = ?, temp_key = 'current_location'",
                    [$npc['id'], $locationJson, $locationJson]
                );
                Database::execute(
                    "INSERT INTO npc_temp (npc_id, temp_key, temp_value) 
                     VALUES (?, 'expire_time', ?)
                     ON DUPLICATE KEY UPDATE temp_value = ?",
                    [$npc['id'], strval(time() + 20), strval(time() + 20)]
                );
                MessageDaemon::broadcastToRoom(
                    $roomId,
                    "一阵迷雾升起，雾里云出现了！",
                    $charId
                );
            }
        }
    }
}
