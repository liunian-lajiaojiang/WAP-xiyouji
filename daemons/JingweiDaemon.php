<?php
/**
 * 精卫填海任务守护进程
 * 基于原始项目 xyj2000/d/changan/npc/jingwei.c
 *
 * 核心机制：
 * 1. 精卫在东海之滨(changan/eastseashore)和东海海滩(changan/beach)之间循环飞行
 * 2. 仅夜晚才飞行，白天停留
 * 3. 玩家可跟随精卫飞行（follow 命令）
 * 4. 在海滩可执行 fill sea 填海获得潜能
 *
 * 采用 variables 表 + 时间戳懒计算方案（参考 QiongcaoHandler 模式）
 * 精卫位置通过 npc_temp 表的 current_location 记录（Room 模型已支持动态位置过滤）
 * 玩家跟随标记存储在 variables 表（避免占用 npc_temp 的 NPC 外键）
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/NatureDaemon.php';

class JingweiDaemon
{
    private const NPC_ID = 26;

    private const ROOM_EASTSEASHORE = 'changan/eastseashore';
    private const ROOM_BEACH = 'changan/beach';

    private const STATE_KEY = 'jingwei_state';

    private const FLY_INTERVAL_MIN = 10;
    private const FLY_INTERVAL_MAX = 19;

    /**
     * 获取精卫当前状态
     * @return array {location: string, last_action_time: int, last_action: string}
     */
    public static function getState(): array
    {
        $row = Database::queryOne(
            "SELECT value FROM variables WHERE var_key = ?",
            [self::STATE_KEY]
        );

        if ($row) {
            $state = json_decode($row['value'], true);
            if (is_array($state) && isset($state['location'])) {
                return $state;
            }
        }

        $state = [
            'location' => self::ROOM_EASTSEASHORE,
            'last_action_time' => time(),
            'last_action' => 'idle'
        ];
        self::saveState($state);
        return $state;
    }

    private static function saveState(array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE);
        Database::execute(
            "INSERT INTO variables (var_key, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [self::STATE_KEY, $json]
        );
    }

    /**
     * 更新精卫状态（懒计算，在玩家访问相关房间时调用）
     */
    public static function updateState(): array
    {
        $state = self::getState();
        $now = time();
        $elapsed = $now - intval($state['last_action_time']);

        $interval = rand(self::FLY_INTERVAL_MIN, self::FLY_INTERVAL_MAX);

        if ($elapsed < $interval) {
            return $state;
        }

        if (!NatureDaemon::isNight()) {
            $state['last_action_time'] = $now;
            $state['last_action'] = 'idle';
            self::saveState($state);
            return $state;
        }

        if (rand(1, 2) === 1) {
            $action = ($state['location'] === self::ROOM_EASTSEASHORE) ? 'pick' : 'drop';
            $state['last_action'] = $action;
            $state['last_action_time'] = $now;
            self::saveState($state);
        } else {
            $oldLocation = $state['location'];
            $newLocation = ($oldLocation === self::ROOM_EASTSEASHORE)
                ? self::ROOM_BEACH
                : self::ROOM_EASTSEASHORE;

            $state['location'] = $newLocation;
            $state['last_action'] = 'fly';
            $state['last_action_time'] = $now;
            self::saveState($state);

            self::updateNpcLocation($newLocation);
            self::transportFollowingPlayers($oldLocation, $newLocation);
        }

        return $state;
    }

    /**
     * 更新精卫 NPC 在 npc_temp 表中的动态位置
     */
    private static function updateNpcLocation(string $room): void
    {
        $locationJson = json_encode([
            'area' => 'changan',
            'room' => $room
        ], JSON_UNESCAPED_UNICODE);

        $existing = Database::queryOne(
            "SELECT temp_key FROM npc_temp WHERE npc_id = ? AND temp_key = 'current_location'",
            [self::NPC_ID]
        );

        if ($existing) {
            Database::execute(
                "UPDATE npc_temp SET temp_value = ?, updated_at = ? WHERE npc_id = ? AND temp_key = 'current_location'",
                [$locationJson, time(), self::NPC_ID]
            );
        } else {
            Database::execute(
                "INSERT INTO npc_temp (npc_id, temp_key, temp_value, updated_at) VALUES (?, 'current_location', ?, ?)",
                [self::NPC_ID, $locationJson, time()]
            );
        }
    }

    /**
     * 获取玩家跟随标记的 variables key
     */
    private static function getFollowKey(int $charId): string
    {
        return 'jingwei_follow_' . $charId;
    }

    /**
     * 检查玩家是否在跟随精卫
     */
    public static function isFollowing(int $charId): bool
    {
        $row = Database::queryOne(
            "SELECT value FROM variables WHERE var_key = ?",
            [self::getFollowKey($charId)]
        );
        return !empty($row) && $row['value'] === '1';
    }

    /**
     * 设置玩家跟随状态
     */
    private static function setFollowing(int $charId, bool $following): void
    {
        $key = self::getFollowKey($charId);
        if ($following) {
            Database::execute(
                "INSERT INTO variables (var_key, value) VALUES (?, '1')
                 ON DUPLICATE KEY UPDATE value = '1'",
                [$key]
            );
        } else {
            Database::execute(
                "DELETE FROM variables WHERE var_key = ?",
                [$key]
            );
        }
    }

    /**
     * 传送跟随精卫的玩家
     */
    private static function transportFollowingPlayers(string $fromRoom, string $toRoom): void
    {
        $allFollowers = Database::queryAll(
            "SELECT var_key FROM variables WHERE var_key LIKE 'jingwei_follow_%' AND value = '1'"
        );
        if (empty($allFollowers)) {
            return;
        }

        $parts = explode('/', $toRoom);
        $targetArea = $parts[0] ?? 'changan';
        $targetRoom = $toRoom;

        foreach ($allFollowers as $follower) {
            $key = $follower['var_key'];
            $charId = intval(str_replace('jingwei_follow_', '', $key));
            if ($charId <= 0) {
                continue;
            }

            $char = Database::queryOne(
                "SELECT current_area, current_room FROM characters WHERE id = ?",
                [$charId]
            );
            if (!$char) {
                continue;
            }

            $followerRoom = self::normalizeRoom(
                $char['current_area'] ?? '',
                $char['current_room'] ?? ''
            );

            if ($followerRoom !== $fromRoom) {
                continue;
            }

            Database::execute(
                "UPDATE characters SET current_area = ?, current_room = ? WHERE id = ?",
                [$targetArea, $targetRoom, $charId]
            );

            self::setFollowing($charId, false);
        }
    }

    private static function normalizeRoom(string $area, string $room): string
    {
        $room = trim($room);
        if (strpos($room, '/') !== false) {
            return $room;
        }
        return $area . '/' . $room;
    }

    /**
     * 玩家跟随精卫
     */
    public static function followJingwei(int $charId): array
    {
        if (!NatureDaemon::isNight()) {
            return [
                'success' => false,
                'message' => '精卫说：天还太亮了，等天黑再来吧。'
            ];
        }

        $char = Database::queryOne(
            "SELECT current_area, current_room FROM characters WHERE id = ?",
            [$charId]
        );

        if (!$char) {
            return ['success' => false, 'message' => '角色不存在。'];
        }

        $charRoom = self::normalizeRoom($char['current_area'] ?? '', $char['current_room'] ?? '');

        $state = self::updateState();

        if ($charRoom !== $state['location']) {
            return [
                'success' => false,
                'message' => '精卫不在这里，无法跟随。'
            ];
        }

        if (self::isFollowing($charId)) {
            return ['success' => false, 'message' => '你已经在跟着精卫了。'];
        }

        self::setFollowing($charId, true);

        return [
            'success' => true,
            'message' => '你决定跟着精卫一起去填海。'
        ];
    }

    /**
     * 填海命令
     */
    public static function fillSea(int $charId): array
    {
        $char = Database::queryOne(
            "SELECT * FROM characters WHERE id = ?",
            [$charId]
        );

        if (!$char) {
            return ['success' => false, 'message' => '角色不存在。'];
        }

        $charRoom = self::normalizeRoom($char['current_area'] ?? '', $char['current_room'] ?? '');
        if ($charRoom !== self::ROOM_BEACH) {
            return ['success' => false, 'message' => '你要在哪里填海？这里又不是海边。'];
        }

        if (!NatureDaemon::isNight()) {
            return ['success' => false, 'message' => '白天填海没什么效果，等天黑再来吧。'];
        }

        if (is_player_busy($charId)) {
            return ['success' => false, 'message' => '你正忙着呢。'];
        }

        require_once DAEMON_PATH . 'CombatDaemon.php';
        if (CombatDaemon::isInCombat($charId)) {
            return ['success' => false, 'message' => '你正打着呢，哪有空填海！'];
        }

        $kee = intval($char['kee'] ?? 0);
        $sen = intval($char['sen'] ?? 0);
        $mana = intval($char['mana'] ?? 0);
        $force = intval($char['force'] ?? 0);

        $missingStats = [];
        if ($kee < 500) $missingStats[] = "气血不足500";
        if ($sen < 500) $missingStats[] = "精神不足500";
        if ($mana < 1000) $missingStats[] = "内力不足1000";
        if ($force < 1000) $missingStats[] = "武力不足1000";

        if (!empty($missingStats)) {
            return ['success' => false, 'message' => '你太累了，歇会儿吧。(' . implode('，', $missingStats) . ')'];
        }

        require_once MODEL_PATH . 'Item.php';
        $inventory = ItemModel::getCharacterItems($charId);
        $stoneItem = null;
        foreach ($inventory as $item) {
            if ($item['item_id'] === 'shi') {
                $stoneItem = $item;
                break;
            }
        }

        if (!$stoneItem || intval($stoneItem['quantity']) <= 0) {
            return ['success' => false, 'message' => '你拿什么填海？'];
        }

        ItemModel::removeFromInventory($charId, 'shi', 1, 'weapon');

        require_once HELPER_PATH . 'SkillManager.php';
        $spellsLevel = SkillManager::getSkillLevel($charId, 'spells');
        $potentialGain = max(1, intval($spellsLevel / 10));

        Database::execute(
            "UPDATE characters SET potential = potential + ? WHERE id = ?",
            [$potentialGain, $charId]
        );

        $busyTime = 3 + rand(0, 2);
        set_player_busy($charId, $busyTime);

        return [
            'success' => true,
            'message' => "你从怀里掏出一块石头投入海中……\n辛勤的劳动让你有所领悟，获得 {$potentialGain} 点潜能。"
        ];
    }

    /**
     * 涨潮机制
     */
    public static function checkFlood(int $charId): ?array
    {
        if (rand(1, 10) !== 1) {
            return null;
        }

        $char = Database::queryOne(
            "SELECT kee, max_kee, sen, max_sen FROM characters WHERE id = ?",
            [$charId]
        );

        if (!$char) {
            return null;
        }

        $dodgeChance = 30;
        if (rand(1, 100) <= $dodgeChance) {
            return [
                'type' => 'dodge',
                'message' => '一个巨浪打来，你急忙跳上礁石，躲过了一劫。'
            ];
        }

        $keeLoss = rand(100, 300);
        $senLoss = rand(50, 200);

        $newKee = max(0, intval($char['kee']) - $keeLoss);
        $newSen = max(0, intval($char['sen']) - $senLoss);

        Database::execute(
            "UPDATE characters SET kee = ?, sen = ? WHERE id = ?",
            [$newKee, $newSen, $charId]
        );

        if ($newKee <= 0) {
            Database::execute(
                "UPDATE characters SET current_area = 'changan', current_room = ? WHERE id = ?",
                [self::ROOM_EASTSEASHORE, $charId]
            );
            return [
                'type' => 'sweep',
                'message' => "一个大浪把你卷入了海中！你被海水冲得晕头转向，失去了意识……\n等你醒来时，发现自己被冲回了东海之滨。损失气血 {$keeLoss}，精神 {$senLoss}。"
            ];
        }

        return [
            'type' => 'hit',
            'message' => "一个大浪突然打来，把你浇了个透心凉！损失气血 {$keeLoss}，精神 {$senLoss}。"
        ];
    }

    public static function getCurrentLocation(): string
    {
        $state = self::getState();
        return $state['location'];
    }

    public static function getRecentActionMessage(): ?string
    {
        $state = self::getState();

        switch ($state['last_action']) {
            case 'pick':
                return '精卫从地上衔起一些石砾。';
            case 'drop':
                return '精卫不知从哪儿叼的石子投入海中。';
            case 'fly':
                return '精卫拍着翅膀飞走了。';
            default:
                return null;
        }
    }
}
