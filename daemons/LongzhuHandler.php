<?php
require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/MessageDaemon.php';
require_once __DIR__ . '/../config/game.php';

/**
 * 龙珠系统 Handler
 * 
 * 功能：
 * 1. touch练功 - 摸龙珠提升内功心法
 * 2. combine合并 - 集齐九颗龙珠合并为九彩云龙珠
 * 3. 绑定机制 - 龙珠绑定首次拾取者，他人无法使用
 * 4. 战斗掉落 - 击杀龙王九子掉落对应龙珠
 */
class LongzhuHandler extends ActionHandler {

    // 九颗龙珠定义（对应long1~long9龙王九子）
    private const DRAGON_PEARLS = [
        'longzhu1' => [
            'name' => '金龙珠',
            'eng_id' => 'jin longzhu',
            'color' => '金',
            'npc_id' => 'long1',     // 蒲牢
            'npc_name' => '蒲牢',
            'desc' => '传说只有得道的真龙才会有。上有八宫之相，也许能合并(combine)其它的龙珠。',
        ],
        'longzhu2' => [
            'name' => '银龙珠',
            'eng_id' => 'yin longzhu',
            'color' => '银',
            'npc_id' => 'long2',     // 狴犴
            'npc_name' => '狴犴',
            'desc' => '一颗斗大的圆润珍珠，隐见内有银色光影游动。',
        ],
        'longzhu3' => [
            'name' => '赤龙珠',
            'eng_id' => 'chi longzhu',
            'color' => '赤',
            'npc_id' => 'long3',     // 坚眦
            'npc_name' => '坚眦',
            'desc' => '一颗斗大的圆润珍珠，隐见内有红色光影游动。',
        ],
        'longzhu4' => [
            'name' => '橙龙珠',
            'eng_id' => 'cheng longzhu',
            'color' => '橙',
            'npc_id' => 'long4',     // 霸下
            'npc_name' => '霸下',
            'desc' => '一颗斗大的圆润珍珠，隐见内有橙色光影游动。',
        ],
        'longzhu5' => [
            'name' => '黄龙珠',
            'eng_id' => 'huang longzhu',
            'color' => '黄',
            'npc_id' => 'long5',     // 螭吻
            'npc_name' => '螭吻',
            'desc' => '一颗斗大的圆润珍珠，隐见内有黄色光影游动。',
        ],
        'longzhu6' => [
            'name' => '绿龙珠',
            'eng_id' => 'lu longzhu',
            'color' => '绿',
            'npc_id' => 'long6',     // 饕餮
            'npc_name' => '饕餮',
            'desc' => '一颗斗大的圆润珍珠，隐见内有绿色光影游动。',
        ],
        'longzhu7' => [
            'name' => '青龙珠',
            'eng_id' => 'qing longzhu',
            'color' => '青',
            'npc_id' => 'long7',     // 蚣蝮
            'npc_name' => '蚣蝮',
            'desc' => '一颗斗大的圆润珍珠，隐见内有青色光影游动。',
        ],
        'longzhu8' => [
            'name' => '蓝龙珠',
            'eng_id' => 'lan longzhu',
            'color' => '蓝',
            'npc_id' => 'long8',     // 金猊
            'npc_name' => '金猊',
            'desc' => '一颗斗大的圆润珍珠，隐见内有蓝色光影游动。',
        ],
        'longzhu9' => [
            'name' => '紫龙珠',
            'eng_id' => 'zi longzhu',
            'color' => '紫',
            'npc_id' => 'long9',     // 椒图
            'npc_name' => '椒图',
            'desc' => '一颗斗大的圆润珍珠，隐见内有紫色光影游动。',
        ],
    ];

    // 九彩云龙珠
    private const REAL_PEARL = [
        'item_id' => 'longzhureal',
        'name' => '九彩云龙珠',
        'desc' => '一颗斗大的圆润珍珠，隐见内有九条光影游动。',
    ];

    // 龙珠绑定数据表名
    private const BINDING_TABLE = 'longzhu_bindings';
    // 龙珠练功进度表
    private const PROGRESS_TABLE = 'longzhu_progress';

    // =========================================================
    // execute 接口实现
    // =========================================================

    public function execute(int $charId, array $action, array $params = []): array {
        $cmd = $params['cmd'] ?? $action['action_cmd'] ?? '';

        switch ($cmd) {
            case 'touch':
                return self::handleTouch($charId, $params);
            case 'combine':
                return self::handleCombine($charId, $params);
            case 'check':
                return self::handleCheck($charId, $params);
            case 'list':
                return self::handleList($charId, $params);
            default:
                return ['success' => false, 'message' => '未知命令。龙珠可用命令：touch、combine、check、list'];
        }
    }

    // =========================================================
    // NPC对话接口
    // =========================================================

    public static function handleInquiry(array $npc, array $char, string $topic, $extraParam = null): ?string {
        $npcName = $npc['name'] ?? '龙女';
        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';

        $topic = strtolower($topic);

        if ($topic === '龙珠' || $topic === '九龙珠') {
            return self::handleAskLongzhu($npcName, $charId, $charName);
        }

        if ($topic === '九彩' || $topic === '合并') {
            return self::handleAskCombine($npcName, $charId, $charName);
        }

        if ($topic === '练功' || $topic === 'touch') {
            return self::handleAskTouch($npcName, $charId, $charName);
        }

        return null;
    }

    // =========================================================
    // NPC对话处理
    // =========================================================

    /**
     * 询问龙珠系统介绍
     */
    private static function handleAskLongzhu(string $npcName, int $charId, string $charName): string {
        $owned = self::getOwnedPearls($charId);
        $ownedCount = count($owned);

        $msg = HTML_HICYN . "{$npcName}微微一笑，说道：" . HTML_NOR . "\n";
        $msg .= "九龙珠乃东海龙宫至宝，分别为金、银、赤、橙、黄、绿、青、蓝、紫九色。\n";
        $msg .= "由龙王九子各持一颗，只有击败龙子才能获得龙珠。\n\n";
        $msg .= HTML_HIYEL . "【龙珠功能】" . HTML_NOR . "\n";
        $msg .= "  " . HTML_HIGRN . "touch 龙珠" . HTML_NOR . " — 摸龙珠修炼内功心法\n";
        $msg .= "  " . HTML_HIGRN . "combine 金龙珠" . HTML_NOR . " — 集齐九颗合并为九彩云龙珠\n\n";

        if ($ownedCount > 0) {
            $msg .= "你当前持有：";
            foreach ($owned as $p) {
                $msg .= HTML_HIYEL . $p['pearl_name'] . HTML_NOR . " ";
            }
            $msg .= "\n（共{$ownedCount}颗）\n";
        } else {
            $msg .= HTML_HIRED . "你尚未获得任何龙珠。去寻找龙王九子吧！" . HTML_NOR . "\n";
        }

        return $msg;
    }

    /**
     * 询问合并方法
     */
    private static function handleAskCombine(string $npcName, int $charId, string $charName): string {
        $msg = HTML_HICYN . "{$npcName}正色道：" . HTML_NOR . "\n";
        $msg .= "集齐九颗龙珠后，使用「combine」命令合并金龙珠，\n";
        $msg .= "便可得到" . HTML_HIYEL . "九彩云龙珠" . HTML_NOR . "！\n\n";
        $msg .= "不过合并需要：\n";
        $msg .= "  " . HTML_HIGRN . "内力 >= 1500" . HTML_NOR . "\n";
        $msg .= "  " . HTML_HIGRN . "法力 >= 1000" . HTML_NOR . "\n";
        $msg .= "  " . HTML_HIGRN . "同时持有全部九颗龙珠" . HTML_NOR;

        return $msg;
    }

    /**
     * 询问练功方法
     */
    private static function handleAskTouch(string $npcName, int $charId, string $charName): string {
        $msg = HTML_HICYN . "{$npcName}说道：" . HTML_NOR . "\n";
        $msg .= "持有龙珠后，使用「touch」命令触摸龙珠，\n";
        $msg .= "可以修炼内功心法，提升force技能。\n\n";
        $msg .= "不过练功需要：\n";
        $msg .= "  " . HTML_HIGRN . "内功 100~150" . HTML_NOR . "\n";
        $msg .= "  " . HTML_HIGRN . "法术 >= 50" . HTML_NOR . "\n";
        $msg .= "  " . HTML_HIGRN . "气血/精神/内力 >= 50" . HTML_NOR . "\n\n";
        $msg .= "龙珠会消耗你的气血和精神，学满后龙珠将化为普通珍珠消失。\n";
        $msg .= "九彩云龙珠的修炼上限更高，是练功的绝佳宝物！";

        return $msg;
    }

    // =========================================================
    // touch 练功机制
    // =========================================================

    /**
     * 处理 touch 命令
     */
    private static function handleTouch(int $charId, array $params): array {
        $char = Database::queryOne(
            "SELECT name, `force`, max_force, mana, max_mana, spi, kar, `int`, kee, sen FROM characters WHERE id = ?",
            [$charId]
        );

        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }

        $charName = $char['name'];
        $kee = intval($char['kee'] ?? 0);
        $sen = intval($char['sen'] ?? 0);
        $force = intval($char['force'] ?? 0);
        $spi = intval($char['spi'] ?? 10);
        $charInt = intval($char['int'] ?? 10);

        // 获取内功心法等级和法术等级
        $forceSkillRow = Database::queryOne(
            "SELECT level FROM character_skills WHERE char_id = ? AND skill_id = 'force' LIMIT 1",
            [$charId]
        );
        $forceSkill = $forceSkillRow ? intval($forceSkillRow['level']) : 0;

        $spellsRow = Database::queryOne(
            "SELECT level FROM character_skills WHERE char_id = ? AND skill_id = 'spells' LIMIT 1",
            [$charId]
        );
        $spells = $spellsRow ? intval($spellsRow['level']) : 0;

        // 前置条件检查
        if ($forceSkill < 100 || $forceSkill > 150) {
            return ['success' => false, 'message' => "你的内功心法需要在100~150之间才能通过龙珠修炼。当前：{$forceSkill}"];
        }

        if ($spells < 50) {
            return ['success' => false, 'message' => "你的法术需要50以上才能参悟龙珠之力。当前：{$spells}"];
        }

        if ($kee < 50) {
            return ['success' => false, 'message' => "你的气血不足50，无法修炼。"];
        }

        if ($sen < 50) {
            return ['success' => false, 'message' => "你的精神不足50，无法修炼。"];
        }

        if ($force < 50) {
            return ['success' => false, 'message' => "你的内力不足50，无法驱动龙珠。"];
        }

        // 确定要练功的龙珠
        $pearlItem = $params['item_id'] ?? '';
        $isRealPearl = ($pearlItem === 'longzhureal');

        // 检查玩家是否持有该龙珠
        if (!empty($pearlItem)) {
            $inv = Database::queryOne(
                "SELECT id, item_id, series_no FROM character_inventory WHERE char_id = ? AND item_id = ? AND equipped = 0 LIMIT 1",
                [$charId, $pearlItem]
            );
            if (!$inv) {
                return ['success' => false, 'message' => "你没有持有这颗龙珠。"];
            }
            // 检查绑定（基于背包实例ID）
            $binding = Database::queryOne(
                "SELECT * FROM " . self::BINDING_TABLE . " WHERE inv_id = ?",
                [$inv['id']]
            );
            if (!$binding) {
                // 首次使用，自动绑定
                self::bindPearl($charId, $inv['id'], $pearlItem);
            }
        } else {
            // 自动选择一颗已绑定的龙珠
            $inv = Database::queryOne(
                "SELECT ci.id, ci.item_id, ci.series_no FROM character_inventory ci " .
                "INNER JOIN " . self::BINDING_TABLE . " lb ON lb.inv_id = ci.id AND lb.char_id = ci.char_id " .
                "WHERE ci.char_id = ? AND (ci.item_id LIKE 'longzhu%' OR ci.item_id = 'longzhureal') AND ci.equipped = 0 LIMIT 1",
                [$charId]
            );
            if (!$inv) {
                // 尝试找未绑定的龙珠自动绑定
                $inv = Database::queryOne(
                    "SELECT id, item_id, series_no FROM character_inventory ci " .
                    "WHERE ci.char_id = ? AND (ci.item_id LIKE 'longzhu%' OR ci.item_id = 'longzhureal') AND ci.equipped = 0 " .
                    "AND ci.id NOT IN (SELECT inv_id FROM " . self::BINDING_TABLE . ") LIMIT 1",
                    [$charId]
                );
                if (!$inv) {
                    return ['success' => false, 'message' => "你没有持有任何龙珠。"];
                }
                self::bindPearl($charId, $inv['id'], $inv['item_id']);
            }
            $pearlItem = $inv['item_id'];
            $isRealPearl = ($pearlItem === 'longzhureal');
        }

        // 获取或创建练功进度
        $progress = Database::queryOne(
            "SELECT * FROM " . self::PROGRESS_TABLE . " WHERE char_id = ? AND item_id = ?",
            [$charId, $pearlItem]
        );

        if (!$progress) {
            // 创建练功进度
            Database::execute(
                "INSERT INTO " . self::PROGRESS_TABLE . " (char_id, item_id, learned, max_learned) VALUES (?, ?, 0, ?)",
                [$charId, $pearlItem, 0]
            );
            $progress = [
                'learned' => 0,
                'max_learned' => 0,
            ];
        }

        $learned = intval($progress['learned']);

        // 计算上限（参考原始项目，属性值通常较小：int~20, spi~30）
        // 当前项目属性值可能很大（百万级），需要压缩
        // 目标：普通珠约30次学满，九彩约200~300次学满
        if ($isRealPearl) {
            $kar = intval($char['kar'] ?? 20);
            $maxLearned = min($kar * $spi * 2, 1000);
            // 九彩每次提升量固定+1，但force提升量 int/3+spi/3 较小
            $gainBase = max(1, intval(sqrt($charInt + $spi) / 15));
            $learnedGain = 1;
        } else {
            // 普通龙珠上限 = spi，限制最大200
            $maxLearned = min($spi, 200);
            // 普通珠提升量 int/2+spi/2 较大
            $gainBase = max(1, intval(sqrt($charInt + $spi) / 8));
            $learnedGain = 1 + mt_rand(0, 2);
        }

        if ($learned >= $maxLearned) {
            // 学满，龙珠化为珍珠
            self::depletePearl($charId, $pearlItem, $inv['id'], $inv['series_no'] ?? '');
            $pearlName = $isRealPearl ? '九彩云龙珠' : (self::DRAGON_PEARLS[$pearlItem]['name'] ?? $pearlItem);
            $msg = HTML_HICYN . "你再次触摸{$pearlName}，" . HTML_NOR . "\n";
            $msg .= "忽然一道" . ($isRealPearl ? '九彩' : '淡淡') . "霞光闪过，龙珠灵力耗尽，\n";
            $msg .= "化作一颗普通珍珠落在你手中。\n";
            $msg .= HTML_HIGRN . "你的内功心法已无法再从此珠获益。" . HTML_NOR;
            return ['success' => true, 'message' => $msg];
        }

        // 提升内功
        $gain = max(1, $gainBase);
        $newLearned = min($learned + $learnedGain, $maxLearned);

        Database::execute(
            "UPDATE " . self::PROGRESS_TABLE . " SET learned = ?, max_learned = ? WHERE char_id = ? AND item_id = ?",
            [$newLearned, $maxLearned, $charId, $pearlItem]
        );

        // 提升内功心法技能 + 消耗内力/气血/精神
        Database::execute(
            "UPDATE characters SET kee = GREATEST(kee - 30, 1), sen = GREATEST(sen - 30, 1), `force` = GREATEST(`force` - 10, 1) WHERE id = ?",
            [$charId]
        );
        // 提升内功心法等级（两种龙珠都限制在150以内，与原始项目一致）
        Database::execute(
            "UPDATE character_skills SET level = LEAST(level + ?, 150) WHERE char_id = ? AND skill_id = 'force'",
            [$gain, $charId]
        );

        // 重新查询更新后的force技能
        $updated = Database::queryOne("SELECT level FROM character_skills WHERE char_id = ? AND skill_id = 'force' LIMIT 1", [$charId]);
        $newForce = $updated ? intval($updated['level']) : $forceSkill;

        $pearlName = $isRealPearl ? '九彩云龙珠' : (self::DRAGON_PEARLS[$pearlItem]['name'] ?? $pearlItem);

        $msg = HTML_HICYN . "你伸手触摸{$pearlName}，" . HTML_NOR . "\n";
        $msg .= "一丝淡淡霞光顺着指尖流入体内，内功心法有所精进。\n";
        $msg .= "内功心法：{$forceSkill} → " . HTML_HIGRN . "{$newForce}" . HTML_NOR . "\n";
        $msg .= "修炼进度：{$learned}/{$maxLearned} → " . HTML_HIYEL . "{$newLearned}/{$maxLearned}" . HTML_NOR;

        return ['success' => true, 'message' => $msg];
    }

    // =========================================================
    // combine 合并机制
    // =========================================================

    /**
     * 处理 combine 命令
     */
    private static function handleCombine(int $charId, array $params): array {
        $char = Database::queryOne(
            "SELECT name, `force`, max_force, mana, max_mana, spi, kar, `int` FROM characters WHERE id = ?",
            [$charId]
        );

        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }

        $charName = $char['name'];
        $maxForce = intval($char['max_force'] ?? 0);
        $maxMana = intval($char['max_mana'] ?? 0);

        // 前置条件检查
        if ($maxForce < 1500) {
            return ['success' => false, 'message' => "合并龙珠需要内力1500以上。当前最大内力：{$maxForce}"];
        }

        if ($maxMana < 1000) {
            return ['success' => false, 'message' => "合并龙珠需要法力1000以上。当前最大法力：{$maxMana}"];
        }

        // 检查是否持有金龙珠
        $jinZhu = Database::queryOne(
            "SELECT id, series_no FROM character_inventory WHERE char_id = ? AND item_id = 'longzhu1' LIMIT 1",
            [$charId]
        );
        if (!$jinZhu) {
            return ['success' => false, 'message' => "你没有持有金龙珠，无法进行合并。"];
        }

        // 检查其余8颗龙珠
        $requiredPearls = ['longzhu2', 'longzhu3', 'longzhu4', 'longzhu5', 'longzhu6', 'longzhu7', 'longzhu8', 'longzhu9'];
        $missing = [];
        foreach ($requiredPearls as $pid) {
            $has = Database::queryOne(
                "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = ? LIMIT 1",
                [$charId, $pid]
            );
            if (!$has) {
                $missing[] = self::DRAGON_PEARLS[$pid]['name'];
            }
        }

        if (!empty($missing)) {
            $msg = "你还缺少以下龙珠，无法合并：\n";
            foreach ($missing as $name) {
                $msg .= "  " . HTML_HIRED . $name . HTML_NOR . "\n";
            }
            return ['success' => false, 'message' => $msg];
        }

        // 集齐9颗！执行合并
        // 1. 收集9颗龙珠的背包实例ID
        $allPearls = array_merge(['longzhu1'], $requiredPearls);
        $invIds = [];
        foreach ($allPearls as $pid) {
            $inv = Database::queryOne(
                "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = ? LIMIT 1",
                [$charId, $pid]
            );
            if ($inv) {
                $invIds[] = intval($inv['id']);
            }
        }

        // 2. 删除9颗单色龙珠的背包记录
        foreach ($invIds as $iid) {
            Database::execute(
                "DELETE FROM character_inventory WHERE char_id = ? AND id = ?",
                [$charId, $iid]
            );
        }

        // 3. 删除绑定记录（基于inv_id）
        if (!empty($invIds)) {
            $placeholders = implode(',', array_fill(0, count($invIds), '?'));
            Database::execute(
                "DELETE FROM " . self::BINDING_TABLE . " WHERE inv_id IN ({$placeholders})",
                $invIds
            );
        }

        // 4. 删除练功进度
        Database::execute(
            "DELETE FROM " . self::PROGRESS_TABLE . " WHERE char_id = ? AND item_id IN ('" . implode("','", $allPearls) . "')",
            [$charId]
        );

        // 5. 给玩家九彩云龙珠
        Database::execute(
            "INSERT INTO character_inventory (char_id, item_id, category, quantity, equipped, durability) VALUES (?, ?, 'sea', 1, 0, 100)",
            [$charId, 'longzhureal']
        );

        // 获取新插入的九彩云龙珠实例ID并绑定
        $newInv = Database::queryOne(
            "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = 'longzhureal' ORDER BY id DESC LIMIT 1",
            [$charId]
        );
        if ($newInv) {
            self::bindPearl($charId, intval($newInv['id']), 'longzhureal');
        }

        // 6. 广播
        $msg = HTML_HICYN . "【龙珠合一】" . HTML_NOR . "\n";
        $msg .= HTML_HIGRN . "{$charName}" . HTML_NOR . "集齐金、银、赤、橙、黄、绿、青、蓝、紫九颗龙珠！\n";
        $msg .= "九道霞光汇聚，龙珠融为" . HTML_HIYEL . "九彩云龙珠" . HTML_NOR . "！\n";
        $msg .= HTML_HIGRN . "{$charName}" . HTML_NOR . "获得" . HTML_HIYEL . "九彩云龙珠" . HTML_NOR . "！";

        MessageDaemon::broadcastToAll($msg);

        $personalMsg = HTML_HICYN . "你将九颗龙珠合在一起，" . HTML_NOR . "\n";
        $personalMsg .= "忽然九道霞光冲天而起，龙珠融为一颗！\n";
        $personalMsg .= "你获得了" . HTML_HIYEL . "九彩云龙珠" . HTML_NOR . "！\n";
        $personalMsg .= "使用「touch 九彩云龙珠」可以修炼内功。";

        return ['success' => true, 'message' => $personalMsg];
    }

    // =========================================================
    // 战斗掉落回调
    // =========================================================

    /**
     * 击杀龙王九子后掉落龙珠
     * 由 CombatDaemon::triggerCombatCallback 调用
     * 
     * @param int $winnerId 胜利者（玩家）ID
     * @param int $loserId 被击杀的NPC ID
     * @param string $dragonId 龙王九子标识（long1~long9）
     */
    public static function onDragonKill(int $winnerId, int $loserId, string $dragonId): void {
        log_game('LONGZHU_CALLBACK', "onDragonKill called: winnerId={$winnerId}, loserId={$loserId}, dragonId={$dragonId}");

        // 查找对应的龙珠
        $pearlId = 'longzhu' . substr($dragonId, -1);
        $pearlInfo = self::DRAGON_PEARLS[$pearlId] ?? null;

        if (!$pearlInfo) {
            log_game('LONGZHU_CALLBACK', "Unknown dragon ID: {$dragonId}");
            return;
        }

        // 检查玩家是否已拥有该龙珠
        $existing = Database::queryOne(
            "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = ? LIMIT 1",
            [$winnerId, $pearlId]
        );

        if ($existing) {
            log_game('LONGZHU_CALLBACK', "Player already has {$pearlId}");
            return;
        }

        // 给玩家龙珠
        Database::execute(
            "INSERT INTO character_inventory (char_id, item_id, category, quantity, equipped, durability) VALUES (?, ?, 'sea', 1, 0, 100)",
            [$winnerId, $pearlId]
        );

        // 获取新插入的背包实例ID
        $newInv = Database::queryOne(
            "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = ? ORDER BY id DESC LIMIT 1",
            [$winnerId, $pearlId]
        );

        // 绑定龙珠
        if ($newInv) {
            self::bindPearl($winnerId, intval($newInv['id']), $pearlId);
        }

        // 获取玩家和NPC名字
        $char = Database::queryOne("SELECT name FROM characters WHERE id = ?", [$winnerId]);
        $npc = Database::queryOne("SELECT name FROM npcs WHERE id = ?", [$loserId]);
        $charName = $char['name'] ?? '勇者';
        $npcName = $npc['name'] ?? '龙王九子';

        // 发消息给玩家
        $msg = HTML_HICYN . "你击败了{$npcName}！" . HTML_NOR . "\n";
        $msg .= "一道霞光闪过，" . HTML_HIYEL . "{$pearlInfo['name']}" . HTML_NOR . "从龙体内飞出，落入你手中！\n";
        $msg .= HTML_HIGRN . "提示：使用「touch 龙珠」可以修炼内功。" . HTML_NOR;

        MessageDaemon::queueMessageToSelf($winnerId, $msg, 'self_event');

        // 全服公告
        $broadcastMsg = HTML_HICYN . "【龙珠出世】" . HTML_NOR . " ";
        $broadcastMsg .= HTML_HIGRN . "{$charName}" . HTML_NOR;
        $broadcastMsg .= "击败{$npcName}，获得" . HTML_HIYEL . "{$pearlInfo['name']}" . HTML_NOR . "！";

        MessageDaemon::broadcastToAll($broadcastMsg);
    }

    // =========================================================
    // 辅助方法
    // =========================================================

    /**
     * 绑定龙珠到玩家（基于背包实例ID）
     */
    private static function bindPearl(int $charId, int $invId, string $itemId): void {
        // 先删除该实例的旧绑定
        Database::execute(
            "DELETE FROM " . self::BINDING_TABLE . " WHERE inv_id = ?",
            [$invId]
        );

        Database::execute(
            "INSERT INTO " . self::BINDING_TABLE . " (char_id, inv_id, item_id, bound_at) VALUES (?, ?, ?, NOW())",
            [$charId, $invId, $itemId]
        );
    }

    /**
     * 龙珠耗尽化为珍珠
     */
    private static function depletePearl(int $charId, string $pearlItem, int $invId, string $seriesNo): void {
        // 删除龙珠背包记录
        Database::execute(
            "DELETE FROM character_inventory WHERE id = ?",
            [$invId]
        );

        // 删除绑定记录（基于inv_id）
        Database::execute(
            "DELETE FROM " . self::BINDING_TABLE . " WHERE inv_id = ?",
            [$invId]
        );

        // 删除练功进度
        Database::execute(
            "DELETE FROM " . self::PROGRESS_TABLE . " WHERE char_id = ? AND item_id = ?",
            [$charId, $pearlItem]
        );

        // 给一颗普通珍珠
        Database::execute(
            "INSERT INTO character_inventory (char_id, item_id, category, quantity, equipped, durability) VALUES (?, 'pearl', 'sea', 1, 0, 100)",
            [$charId]
        );
    }

    /**
     * 获取玩家持有的龙珠列表
     */
    private static function getOwnedPearls(int $charId): array {
        $rows = Database::queryAll(
            "SELECT ci.item_id, ci.id AS inv_id FROM character_inventory ci " .
            "INNER JOIN " . self::BINDING_TABLE . " lb ON lb.inv_id = ci.id AND lb.char_id = ci.char_id " .
            "WHERE ci.char_id = ? AND (ci.item_id LIKE 'longzhu%' OR ci.item_id = 'longzhureal')",
            [$charId]
        );

        $result = [];
        foreach ($rows as $row) {
            $pid = $row['item_id'];
            if (isset(self::DRAGON_PEARLS[$pid])) {
                $result[] = ['item_id' => $pid, 'pearl_name' => self::DRAGON_PEARLS[$pid]['name']];
            } elseif ($pid === 'longzhureal') {
                $result[] = ['item_id' => $pid, 'pearl_name' => '九彩云龙珠'];
            }
        }
        return $result;
    }

    /**
     * check 命令 - 查看龙珠收集进度
     */
    private static function handleCheck(int $charId, array $params): array {
        $owned = self::getOwnedPearls($charId);
        $count = count($owned);

        $msg = HTML_HIYEL . "【龙珠收集进度】" . HTML_NOR . "\n";
        $msg .= "已收集：{$count}/9\n\n";

        foreach (self::DRAGON_PEARLS as $key => $pearl) {
            $has = false;
            foreach ($owned as $o) {
                if ($o['item_id'] === $key) {
                    $has = true;
                    break;
                }
            }
            $status = $has ? HTML_HIGRN . "✓ {$pearl['name']}" . HTML_NOR : HTML_HIRED . "✗ {$pearl['name']}（{$pearl['npc_name']}）" . HTML_NOR;
            $msg .= "  {$status}\n";
        }

        // 检查是否持有九彩云龙珠
        $hasReal = Database::queryOne(
            "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = 'longzhureal' LIMIT 1",
            [$charId]
        );
        if ($hasReal) {
            $msg .= "\n" . HTML_HIYEL . "★ 九彩云龙珠 ✓" . HTML_NOR . "\n";
        }

        return ['success' => true, 'message' => $msg];
    }

    /**
     * list 命令 - 列出九颗龙珠及持有者
     */
    private static function handleList(int $charId, array $params): array {
        $msg = HTML_HIYEL . "【九龙珠分布】" . HTML_NOR . "\n\n";

        foreach (self::DRAGON_PEARLS as $key => $pearl) {
            // 查找持有者（通过背包+绑定关联）
            $binding = Database::queryOne(
                "SELECT lb.char_id, c.name FROM " . self::BINDING_TABLE . " lb " .
                "LEFT JOIN characters c ON c.id = lb.char_id " .
                "WHERE lb.item_id = ? AND lb.char_id = ? LIMIT 1",
                [$key, $charId]
            );
            // 如果自己没有，查找是否有其他玩家持有（通过背包）
            if (!$binding) {
                $binding = Database::queryOne(
                    "SELECT ci.char_id, c.name FROM character_inventory ci " .
                    "LEFT JOIN characters c ON c.id = ci.char_id " .
                    "WHERE ci.item_id = ? AND ci.char_id != ? LIMIT 1",
                    [$key, $charId]
                );
            }

            $owner = $binding && $binding['name'] ? HTML_HIGRN . $binding['name'] . HTML_NOR : HTML_HIRED . '无人持有' . HTML_NOR;
            $msg .= "  " . HTML_CYN . $pearl['color'] . "色" . HTML_NOR . " — ";
            $msg .= $pearl['name'] . "（{$pearl['npc_name']}）— {$owner}\n";
        }

        return ['success' => true, 'message' => $msg];
    }
}
