<?php
/**
 * Moon Actions Handler
 *
 * 月宫玉女峰顶特殊动作处理器
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */

require_once __DIR__ . '/ActionHandler.php';

class MoonActionsHandler extends ActionHandler {

    /**
     * 默认配置
     */
    public function getDefaultConfig(): array {
        return [
            'kee_threshold_div' => 3,       // 气血阈值除数 (max_kee / N)
            'damage_percent' => 20,          // 摔伤百分比
            'kee_cost_percent' => 10,        // 消耗气血百分比
            'dodge_threshold' => 40,         // 闪避技能门槛
            'moondance_threshold' => 80,     // 月舞技能门槛
            'climb_skill_gain' => 20,        // 攀爬训练技能增量
            'unarmed_threshold' => 50,       // 拳脚技能门槛
        ];
    }

    /**
     * 获取配置（带缓存）
     */
    private function getMoonConfig(array $action): array {
        static $cache = null;
        if ($cache === null) {
            $cache = $this->mergeConfig($this->getDefaultConfig(), $this->parseConfig($action));
        }
        return $cache;
    }

    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在', 'data' => null];
            }

            $cmd = $action['action_cmd'] ?? '';

            $cfg = $this->getMoonConfig($action);

            switch ($cmd) {
                case 'climb tree':
                    return $this->climbTree($charId, $character, $cfg);
                case 'chop tree':
                    return $this->chopTree($charId, $character, $cfg);
                default:
                    return ['success' => false, 'message' => '未知的动作: ' . $cmd, 'data' => null];
            }
        } catch (\Exception $e) {
            error_log("MoonActionsHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '动作执行失败', 'data' => null];
        }
    }

    /**
     * 爬桂树
     * 原始逻辑: 检查气血、轻功/月舞技能，技能足够则移到 tree1，否则训练闪避
     */
    private function climbTree(int $charId, array $character, array $cfg): array {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../helpers/SkillManager.php';

        $kee = intval($character['kee'] ?? 0);
        $maxKee = intval($character['max_kee'] ?? 100);

        // 气血检查: 需要 > max_kee / N
        if ($kee <= intval($maxKee / $cfg['kee_threshold_div'])) {
            // 气血不足，扣血并失败
            $damage = intval($maxKee * $cfg['damage_percent'] / 100);
            Database::execute('UPDATE characters SET kee = GREATEST(1, kee - ?) WHERE id = ?', [$damage, $charId]);
            return ['success' => false, 'message' => '你身子发虚，一头栽了下来，哎呀！', 'data' => null];
        }

        // 吴刚检查：非月宫弟子且吴刚在场会被拦
        $wugangBlock = $this->checkWugangBlock($charId, $character);
        if ($wugangBlock) {
            return ['success' => false, 'message' => '吴刚拦道：此间并非戏耍之处，请勿骚扰我仙家清修。', 'data' => null];
        }

        // 查询闪避和月舞技能等级
        $dodgeLevel = $this->getSkillLevelDirect($charId, 'dodge');
        $moondanceLevel = $this->getSkillLevelDirect($charId, 'moondance');

        // 消耗 N% 最大气血
        $keeCost = intval($maxKee * $cfg['kee_cost_percent'] / 100);
        Database::execute('UPDATE characters SET kee = GREATEST(1, kee - ?) WHERE id = ?', [$keeCost, $charId]);

        // 技能不足: 训练闪避，留在原地
        if ($dodgeLevel < $cfg['dodge_threshold'] && $moondanceLevel < $cfg['moondance_threshold']) {
            $gain = $cfg['climb_skill_gain'];
            $this->trainSkill($charId, 'dodge', $gain);
            return [
                'success' => true,
                'message' => '你小心翼翼的往上爬了一点，觉得头晕眼花，就赶紧爬了下来。你领悟出一些基本轻功方面的窍门。',
                'data' => ['type' => 'climb_train'],
            ];
        }

        // 技能足够: 移动到 moon/tree1（桂树叶间）
        Database::execute(
            'UPDATE characters SET current_room = ?, current_area = ? WHERE id = ?',
            ['moon/tree1', 'moon', $charId]
        );

        return [
            'success' => true,
            'message' => '你纵身往桂树上一跳，接着爬入树丛中不见了。',
            'redirect' => 'room.php?area=moon&room=' . urlencode('moon/tree1'),
            'data' => ['type' => 'climb_move'],
        ];
    }

    /**
     * 砍桂树
     * 原始逻辑: 需要斧头，检查气血，训练拳脚技能
     */
    private function chopTree(int $charId, array $character, array $cfg): array {
        require_once __DIR__ . '/../includes/db.php';

        $kee = intval($character['kee'] ?? 0);
        $maxKee = intval($character['max_kee'] ?? 100);

        // 检查装备的武器是否为斧头
        $weapon = $this->getEquippedWeaponItem($charId);
        if (!$weapon) {
            return ['success' => false, 'message' => '先去找把斧头吧！', 'data' => null];
        }
        $weaponId = strtolower($weapon['item_id'] ?? '');
        $weaponType = strtolower($weapon['type'] ?? '');
        // 检查是否为斧头类型：item_id 包含 axe 或 type 为 axe
        if (strpos($weaponId, 'axe') === false && strpos($weaponId, 'fu') === false && $weaponType !== 'axe') {
            return ['success' => false, 'message' => '先去找把斧头吧！', 'data' => null];
        }

        // 气血检查
        if ($kee <= intval($maxKee / $cfg['kee_threshold_div'])) {
            return ['success' => false, 'message' => '再砍下去手都要磨破了！', 'data' => null];
        }

        // 吴刚检查
        $wugangBlock = $this->checkWugangBlock($charId, $character);
        if ($wugangBlock) {
            return ['success' => false, 'message' => '吴刚拦道：此间并非戏耍之处，请勿骚扰我仙家清修！', 'data' => null];
        }

        // 消耗 N% 最大气血
        $keeCost = intval($maxKee * $cfg['kee_cost_percent'] / 100);
        Database::execute('UPDATE characters SET kee = GREATEST(1, kee - ?) WHERE id = ?', [$keeCost, $charId]);

        // 查询拳脚技能等级
        $unarmedLevel = $this->getSkillLevelDirect($charId, 'unarmed');

        if ($unarmedLevel < $cfg['unarmed_threshold']) {
            // 训练拳脚，增加 str 点经验
            $gain = intval($character['str'] ?? 10);
            $this->trainSkill($charId, 'unarmed', $gain);
            return [
                'success' => true,
                'message' => '你朝桂树使劲儿砍了几下。累了个臭死，你总算领悟出一些运劲使力方面的窍门。',
                'data' => ['type' => 'chop_train'],
            ];
        }

        return [
            'success' => true,
            'message' => '你朝桂树使劲儿砍了几下。你试着砍了几下，不明白为什么有人会做这种傻事。',
            'data' => ['type' => 'chop_no_gain'],
        ];
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    /**
     * 检查吴刚是否阻挡（非月宫弟子且吴刚在房间内）
     */
    private function checkWugangBlock(int $charId, array $character): bool {
        require_once __DIR__ . '/../includes/db.php';

        // 月宫弟子不受限制
        $family = $character['family'] ?? '';
        if ($family === 'moon') {
            return false;
        }

        // 检查吴刚 NPC 是否在当前房间（使用 spawn_room 字段）
        $currentRoom = $character['current_room'] ?? '';
        $wugang = Database::queryOne(
            "SELECT id FROM npcs WHERE npc_id = 'wugang' AND spawn_room = ?",
            [$currentRoom]
        );

        if (!$wugang) {
            return false;
        }

        // 检查吴刚是否已死亡（通过 session 标记 + 全局 npc_respawn 表）
        $deathKey = 'npc_dead_' . $wugang['id'];
        if (isset($_SESSION[$deathKey]) && $_SESSION[$deathKey] >= time()) {
            return false;
        }
        // 全局检查：其他玩家击杀的NPC对所有人不可见
        require_once __DIR__ . '/../models/NpcRespawn.php';
        if (\NpcRespawn::isInRespawnCooldown($wugang['id'])) {
            return false;
        }

        return true;
    }

    /**
     * 直接获取技能等级（不经过 SkillManager 的映射计算）
     */
    private function getSkillLevelDirect(int $charId, string $skillId): int {
        require_once __DIR__ . '/../includes/db.php';

        $result = Database::queryOne(
            'SELECT level FROM character_skills WHERE char_id = ? AND skill_id = ? LIMIT 1',
            [$charId, $skillId]
        );
        return $result ? intval($result['level']) : 0;
    }

    /**
     * 训练技能（直接增加等级）
     */
    private function trainSkill(int $charId, string $skillId, int $gain): void {
        require_once __DIR__ . '/../includes/db.php';

        $existing = Database::queryOne(
            'SELECT id FROM character_skills WHERE char_id = ? AND skill_id = ?',
            [$charId, $skillId]
        );

        if ($existing) {
            Database::execute(
                'UPDATE character_skills SET level = level + ? WHERE char_id = ? AND skill_id = ?',
                [$gain, $charId, $skillId]
            );
        } else {
            Database::execute(
                'INSERT INTO character_skills (char_id, skill_id, level, exp) VALUES (?, ?, ?, 0)',
                [$charId, $skillId, $gain]
            );
        }
    }

    /**
     * 获取装备中的武器数据
     */
    private function getEquippedWeaponItem(int $charId): ?array {
        require_once __DIR__ . '/../includes/db.php';

        // 查 character_inventory 中 equipped=1 且 equip_slot 为 'main' 或 'weapon' 的物品
        // WeaponHelper 使用 'main'/'secondary'，旧代码可能用 'weapon'
        $inv = Database::queryOne(
            "SELECT item_id FROM character_inventory WHERE char_id = ? AND equipped = 1 AND equip_slot IN ('main', 'weapon') LIMIT 1",
            [$charId]
        );

        if (!$inv) {
            return null;
        }

        return Database::queryOne(
            'SELECT * FROM items WHERE item_id = ?',
            [$inv['item_id']]
        );
    }
}
