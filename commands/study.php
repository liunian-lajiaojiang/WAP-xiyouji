<?php
/**
 * 学习命令 - study
 * 用于从技能类书籍中学习技能
 */

require_once __DIR__ . '/../helpers/SkillManager.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../models/Item.php';

function isStudyableItem($item) {
    // 查 book_skills 表
    $bookSkill = Database::queryOne("SELECT * FROM book_skills WHERE item_id = ?", [$item['item_id']]);
    if (!empty($bookSkill)) {
        return true;
    }
    
    // 检查 items.effects 字段中的技能配置
    $effects = $item['effects'] ?? $item['extra'] ?? null;
    if (is_string($effects)) {
        $effects = json_decode($effects, true);
    }
    if (is_array($effects) && !empty($effects['skill']['name'])) {
        return true;
    }
    
    return false;
}

function cmd_study(int $charId, string $param): array {
    $param = trim($param);
    if ($param === '') {
        return ['success' => false, 'message' => '你要研读什么？'];
    }

    if (is_player_busy($charId)) {
        return ['success' => false, 'message' => '你正忙着呢，没空研读。'];
    }

    $items = ItemModel::getCharacterItems($charId);
    $foundItem = null;
    foreach ($items as $item) {
        if ($item['item_id'] === $param) {
            $foundItem = $item;
            break;
        }
    }

    if (!$foundItem) {
        return ['success' => false, 'message' => '你没有这样东西。'];
    }

    if (!isStudyableItem($foundItem)) {
        return ['success' => false, 'message' => '这样东西不能用来学习技能。'];
    }

    $itemName = $foundItem['name'];
    $bookSkill = Database::queryOne("SELECT * FROM book_skills WHERE item_id = ?", [$foundItem['item_id']]);

    // 如果 book_skills 表没有记录，尝试从 effects 字段获取技能配置
    if (!$bookSkill) {
        $effects = $foundItem['effects'] ?? $foundItem['extra'] ?? null;
        if (is_string($effects)) {
            $effects = json_decode($effects, true);
        }
        if (is_array($effects) && !empty($effects['skill']['name'])) {
            $bookSkill = [
                'skill_id'       => $effects['skill']['name'],
                'max_skill'      => $effects['skill']['max_skill'] ?? 60,
                'min_skill'      => $effects['skill']['min_skill'] ?? 0,
                'difficulty'     => $effects['skill']['difficulty'] ?? 30,
                'sen_cost'       => $effects['skill']['sen_cost'] ?? 25,
                'exp_required'   => $effects['skill']['exp_required'] ?? $effects['skill']['dx_required'] ?? 0,
                'description'    => '你似乎有所领悟。',
            ];
        }
    }

    if (!$bookSkill) {
        return ['success' => false, 'message' => '这本书无法用于学习技能。'];
    }

    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在。'];
    }

    require_once DAEMON_PATH . 'CombatDaemon.php';
    if (CombatDaemon::isInCombat($charId)) {
        return ['success' => false, 'message' => '你正在战斗中，哪有心思学习！'];
    }

    // 原始项目：所有物品都要求 literate（读书识字）
    $literateLevel = SkillManager::querySkill($charId, 'literate', true);
    if ($literateLevel < 1) {
        return ['success' => false, 'message' => '你是个文盲，先学学读书识字(literate)吧。'];
    }

    $skillId = $bookSkill['skill_id'];
    $currentLevel = SkillManager::querySkill($charId, $skillId, true);

    if ($currentLevel < ($bookSkill['min_skill'] ?? 0)) {
        return ['success' => false, 'message' => '你的基础太弱，还看不懂这本书。'];
    }

    if ($currentLevel >= ($bookSkill['max_skill'] ?? 100)) {
        return ['success' => false, 'message' => '你研读了一会儿，但是发现上面所说的对你而言都太浅了，没有学到任何东西。', 'skip_queue' => true];
    }

    // 原始项目：martial 类型检查 combat_exp，magic 类型检查 daoxing
    // skill³/10 > combat_exp/daoxing 时禁止学习
    $difficulty = intval($bookSkill['difficulty'] ?? 30);
    $expRequired = intval($bookSkill['exp_required'] ?? 0);
    $dxRequired = intval($bookSkill['dx_required'] ?? 0);

    if ($currentLevel * $currentLevel * $currentLevel / 10 > intval($char['combat_exp'])) {
        return ['success' => false, 'message' => '你的武学修为还没到这个境界，光读是没用的。'];
    }
    if ($expRequired > 0 && intval($char['combat_exp']) < $expRequired) {
        return ['success' => false, 'message' => '你的武学修为还没到这个境界，光读是没用的。'];
    }
    if ($dxRequired > 0) {
        $charDaoxing = intval($char['daoxing'] ?? 0);
        if ($currentLevel * $currentLevel * $currentLevel / 10 > $charDaoxing) {
            return ['success' => false, 'message' => '你的道行还没到这个境界，光读是没用的。'];
        }
        if ($charDaoxing < $dxRequired) {
            return ['success' => false, 'message' => '你的道行还没到这个境界，光读是没用的。'];
        }
    }

    // 原始项目消耗公式：sen_cost + sen_cost * (difficulty - int) / 20，最低5
    $senCostBase = intval($bookSkill['sen_cost'] ?? 25);
    $senCost = $senCostBase + intval($senCostBase * max(0, $difficulty - intval($char['int'])) / 20);
    if ($senCost < 5) $senCost = 5;

    if ($char['sen'] < $senCost) {
        return ['success' => false, 'message' => '你现在头晕脑胀，该休息休息了。'];
    }

    CharacterModel::update($charId, ['sen' => $char['sen'] - $senCost]);

    // 原始项目：提升量 = literate/5 + 1（与读书识字等级挂钩）
    $gain = max(1, intval($literateLevel / 5) + 1);

    // 确保技能记录存在（首次学习时自动创建）
    $existing = Database::queryOne(
        "SELECT level, exp FROM character_skills WHERE char_id = ? AND skill_id = ? LIMIT 1",
        [$charId, $skillId]
    );
    if (!$existing) {
        Database::execute(
            "INSERT INTO character_skills (char_id, skill_id, level, exp) VALUES (?, ?, 1, 0)",
            [$charId, $skillId]
        );
    }

    // 增加技能经验
    Database::execute(
        "UPDATE character_skills SET exp = exp + ? WHERE char_id = ? AND skill_id = ?",
        [$gain, $charId, $skillId]
    );

    // 尝试提升技能等级
    $result = SkillManager::improveSkill($charId, $skillId);

    $description = $bookSkill['description'] ?? '';
    $msg = "你仔细研读了{$itemName}。\n";
    $msg .= $description !== '' ? $description : '你似乎有所领悟。';

    if ($result['success']) {
        $skillName = SkillManager::getSkillChineseName($skillId);
        $msg .= "\n你的" . $skillName . '提升了！';
    } else {
        $skillName = SkillManager::getSkillChineseName($skillId);
        $msg .= "\n（" . $skillName . "经验+" . $gain . "）";
    }

    require_once DAEMON_PATH . 'MessageDaemon.php';
    $roomMsg = "{$char['name']}正在认真研读一本{$itemName}。";
    MessageDaemon::broadcastToRoom($char['current_room'], $roomMsg, $charId);

    return ['success' => true, 'message' => $msg, 'skip_queue' => true];
}