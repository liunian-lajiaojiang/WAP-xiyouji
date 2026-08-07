<?php
/**
 * 阅读命令 - 阅读书籍、指南等可读物品
 * 还原原始项目书籍机制：阅读奖励、书籍销毁
 */

require_once __DIR__ . '/../helpers/SkillManager.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../models/Item.php';

const MAX_READ_COUNT = 10;

function ensureBookReadCountTable() {
    $exists = Database::queryOne("SHOW TABLES LIKE 'book_read_count'");
    if (!$exists) {
        $sql = "CREATE TABLE `book_read_count` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `char_id` int(11) NOT NULL,
            `item_id` varchar(64) NOT NULL,
            `category` varchar(32) DEFAULT '',
            `read_count` int(11) NOT NULL DEFAULT 0,
            `last_read_time` int(11) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `char_item_category` (`char_id`, `item_id`, `category`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='书籍阅读次数记录表'";
        Database::execute($sql);
    }
}

ensureBookReadCountTable();

function isReadableItem($item) {
    $itemType = $item['item_type'] ?? $item['type'] ?? '';
    if ($itemType === 'book') {
        return true;
    }
    $readableKeywords = ['指南', '手册', '图谱', '秘籍', '书', '经', '谱'];
    $name = $item['name'] ?? '';
    foreach ($readableKeywords as $kw) {
        if (mb_strpos($name, $kw) !== false) {
            return true;
        }
    }
    $readableIds = ['zhinan', 'book', 'shu', 'jing'];
    $itemId = $item['item_id'] ?? '';
    foreach ($readableIds as $rid) {
        if (strpos($itemId, $rid) !== false) {
            return true;
        }
    }
    return false;
}

function getReadContent($item): string {
    $itemId = $item['item_id'] ?? '';
    
    $specialContent = [
        'xiaqi-zhinan' => 
            "欢迎到品棋亭来下棋！\n\n" .
            "品棋亭路线：从长安南门→s→e→e→nu→enter\n\n" .
            "在这里您可以下围棋或五子棋，以下步骤告诉您怎样下棋：\n" .
            "一、先找好对手，然后分别用 sit black 和 sit white 入座；\n" .
            "二、使用 new 开始一盘新的棋局：new [-5] [-b(数字)] [-h(数字)]\n" .
            "  其中 -5 代表下五子棋，不选即为下围棋；\n" .
            "  -b 指定所用棋盘的大小；\n" .
            "  -h 指定让子的数目；\n" .
            "  例如：\n" .
            "  围棋：new\n" .
            "  十五乘十五的五子棋：new -5 -b15\n" .
            "  让九子围棋：new -h9\n" .
            "三、使用 play 轮流走棋，例如 play d4 等等。\n" .
            "四、使用 refresh 观看棋盘。\n" .
            "五、使用 undo 悔棋（目前只提供五子棋的悔棋功能）。",
        'book-qujing' =>
            "═══════════════════════════════════════\n" .
            "      《西游记西行求取真经指南》\n" .
            "═══════════════════════════════════════\n\n" .
            "【取经系统简介】\n" .
            "  西天取经是本游戏的核心玩法之一。玩家可以申请成为取经人，\n" .
            "  或者护送取经人一路西行，历经九九八十一难，最终到达西天取得真经。\n\n" .
            "【如何申请取经人】\n" .
            "  找长安城的疥顶小僧申请，需满足一定条件。\n" .
            "  申请成功后，等待其他玩家投票或系统选举产生取经人。\n\n" .
            "【取经关卡一览】\n" .
            "  1. 五庄观／人参果\n" .
            "  2. 宝象国／碗子山\n" .
            "  3. 平顶山／莲花洞\n" .
            "  4. 乌鸡国／宝林寺\n" .
            "  5. 车迟国／三清观\n" .
            "  6. 通天河／陈家庄\n" .
            "  7. 金兜山／金兜洞\n" .
            "  8. 女儿国／解阳山\n" .
            "  9. 毒敌山／琵琶洞\n" .
            " 10. 火焰山／翠云山\n" .
            " 11. 积雷山／摩云洞\n" .
            " 12. 祭赛国／碧波潭\n" .
            " 13. 荆棘岭／木仙庵\n" .
            " 14. 小西天／小雷音寺\n" .
            " 15. 朱紫国／麒麟山\n" .
            " 16. 盘丝岭／盘丝洞\n" .
            " 17. 比丘国／清华庄\n" .
            " 18. 无底洞\n" .
            " 19. 凤仙郡\n" .
            " 20. 玉华州\n" .
            " 21. 金平府\n" .
            " 22. 天竺国\n\n" .
            "【注意事项】\n" .
            "  • 取经路上危险重重，建议组队前行\n" .
            "  • 每个关卡都有不同的妖怪和挑战\n" .
            "  • 护送取经人可以获得丰厚奖励\n" .
            "  • 取经人死亡则取经失败，需重新开始\n\n" .
            "═══════════════════════════════════════\n" .
            "  欲知详情，请亲身经历取经之路！\n" .
            "═══════════════════════════════════════",
    ];
    
    if (isset($specialContent[$itemId])) {
        return $specialContent[$itemId];
    }
    
    $desc = trim($item['description'] ?? '');
    return $desc !== '' ? rtrim($desc) : '';
}

function getReadCount(int $charId, string $itemId, string $category = ''): int {
    $record = Database::queryOne(
        "SELECT read_count FROM book_read_count WHERE char_id = ? AND item_id = ? AND category = ?",
        [$charId, $itemId, $category]
    );
    return $record ? intval($record['read_count']) : 0;
}

function updateReadCount(int $charId, string $itemId, string $category = ''): int {
    $now = time();
    $record = Database::queryOne(
        "SELECT id, read_count FROM book_read_count WHERE char_id = ? AND item_id = ? AND category = ?",
        [$charId, $itemId, $category]
    );
    
    if ($record) {
        $newCount = intval($record['read_count']) + 1;
        Database::execute(
            "UPDATE book_read_count SET read_count = ?, last_read_time = ? WHERE id = ?",
            [$newCount, $now, $record['id']]
        );
        return $newCount;
    } else {
        Database::execute(
            "INSERT INTO book_read_count (char_id, item_id, category, read_count, last_read_time) VALUES (?, ?, ?, 1, ?)",
            [$charId, $itemId, $category, $now]
        );
        return 1;
    }
}

function clearReadCount(int $charId, string $itemId, string $category = ''): void {
    Database::execute(
        "DELETE FROM book_read_count WHERE char_id = ? AND item_id = ? AND category = ?",
        [$charId, $itemId, $category]
    );
}

function checkReadReward(int $charId, int $kar, string $itemName): array {
    $chance = random_int(1, 1000);
    $karSquared = $kar * $kar;
    
    if ($chance <= 10 && random_int(1, 100000) <= $karSquared) {
        return ['type' => 'renshen-guo', 'message' => "你翻阅{$itemName}，只见书页中夹着一枚人参果！这果子金光闪闪，香气扑鼻。你一口吞下，感觉神清气爽！"];
    }
    
    if ($chance <= 10 && random_int(1, 1000) <= $kar) {
        return ['type' => 'mihoutao', 'message' => "你翻阅{$itemName}，只见书页中夹着一颗猕猴桃！这果子翠绿诱人，汁水丰富。你一口吃下，精神大振！"];
    }
    
    if ($chance <= 20 && random_int(1, 100) <= $kar * 0.5) {
        return ['type' => 'gold', 'message' => "你翻阅{$itemName}，只见书页中夹着一块金锭！你将金子收好。"];
    }
    
    return ['type' => null, 'message' => ''];
}

function cmd_read(int $charId, string $param): array {
    global $itemName;
    $param = trim($param);
    if ($param === '') {
        return ['success' => false, 'message' => '你要阅读什么？'];
    }

    if (is_player_busy($charId)) {
        return ['success' => false, 'message' => '你正忙着呢，没空阅读。'];
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

    if (!isReadableItem($foundItem)) {
        return ['success' => false, 'message' => '这样东西没什么可读的。'];
    }

    $itemName = $foundItem['name'];
    $itemId = $foundItem['item_id'];
    $category = $foundItem['category'] ?? '';

    $bookSkill = Database::queryOne("SELECT * FROM book_skills WHERE item_id = ?", [$itemId]);

    // book_skills 表没有记录时，检查物品 extra 字段
    if (!$bookSkill) {
        $extra = $foundItem['extra'] ?? null;
        if (is_string($extra)) {
            $extra = json_decode($extra, true);
        }
        if (is_array($extra) && !empty($extra['skill']['name'])) {
            $bookSkill = [
                'skill_id'  => $extra['skill']['name'],
                'max_skill' => $extra['skill']['max_skill'] ?? 60,
            ];
        }
    }

    if ($bookSkill) {
        $skillName = SkillManager::getSkillChineseName($bookSkill['skill_id']);
        $maxSkill = $bookSkill['max_skill'];
        return ['success' => false, 'message' => "这是一本技能书籍，需要使用 study 命令来学习{$skillName}（最高学到{$maxSkill}级）。\n\n使用方法：study {$itemId}"];
    }

    $readCount = getReadCount($charId, $itemId, $category);
    
    if ($readCount >= MAX_READ_COUNT) {
        clearReadCount($charId, $itemId, $category);
        ItemModel::removeFromInventory($charId, $itemId, 1, $category);
        return ['success' => true, 'message' => "{$itemName}已经翻得破烂不堪，化作了一堆纸屑消失了。你已经读过{$readCount}次了，该换一本新的了。"];
    }

    $content = getReadContent($foundItem);

    $msg = "你仔细阅读了{$itemName}。\n";
    if ($content !== '') {
        $msg .= "\n" . $content;
    } else {
        $msg .= "上面什么也没有写。";
    }

    $char = CharacterModel::find($charId);
    $kar = intval($char['kar'] ?? 10);
    
    $reward = checkReadReward($charId, $kar, $itemName);
    if ($reward['type']) {
        $msg .= "\n\n" . $reward['message'];
        
        switch ($reward['type']) {
            case 'renshen-guo':
                ItemModel::addToInventory($charId, 'renshen-guo', 1, 'obj');
                break;
            case 'mihoutao':
                ItemModel::addToInventory($charId, 'mihoutao', 1, 'obj');
                break;
            case 'gold':
                ItemModel::addToInventory($charId, 'gold', 1, 'obj');
                break;
        }
        
        clearReadCount($charId, $itemId, $category);
        ItemModel::removeFromInventory($charId, $itemId, 1, $category);
        return ['success' => true, 'message' => $msg];
    }

    $newCount = updateReadCount($charId, $itemId, $category);
    if ($newCount >= MAX_READ_COUNT) {
        clearReadCount($charId, $itemId, $category);
        ItemModel::removeFromInventory($charId, $itemId, 1, $category);
        $msg .= "\n\n{$itemName}已经翻得破烂不堪，化作了一堆纸屑消失了。";
    }

    return ['success' => true, 'message' => $msg];
}
?>