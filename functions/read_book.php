<?php
/**
 * 阅读书本页面 - 支持分页浏览
 * 还原原始项目书籍机制：阅读奖励、书籍销毁
 */
session_save_path(__DIR__ . '/../sessions');
session_start();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
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

if (!isset($_SESSION['user_id'])) {
    die('请先登录');
}

$charId = get_char_id();
$itemId = $_GET['item_id'] ?? $_GET['param'] ?? '';
$page = intval($_GET['page'] ?? 0);

if (!$itemId) {
    die('缺少物品参数');
}

$char = CharacterModel::find($charId);
if (!$char) {
    die('角色不存在');
}

$items = ItemModel::getCharacterItems($charId);
$foundItem = null;
foreach ($items as $item) {
    if ($item['item_id'] === $itemId) {
        $foundItem = $item;
        break;
    }
}

if (!$foundItem) {
    die('你没有这样东西。');
}

$itemName = $foundItem['name'];
$category = $foundItem['category'] ?? '';

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

$readCount = getReadCount($charId, $itemId, $category);
if ($readCount >= MAX_READ_COUNT) {
    clearReadCount($charId, $itemId, $category);
    ItemModel::removeFromInventory($charId, $itemId, 1, $category);
    die("<h2>{$itemName}已经翻得破烂不堪，化作了一堆纸屑消失了。</h2><p>你已经读过{$readCount}次了，该换一本新的了。</p><a href='room.php'>返回房间</a>");
}

$qujingQuests = [];
$isQujingBook = false;
if ($itemId === 'book_qujing') {
    $isQujingBook = true;
    $qujingQuests = Database::queryAll("SELECT * FROM qujing_quests ORDER BY min_daoxing ASC");
}

$pageCount = count($qujingQuests);
$totalPages = $isQujingBook ? $pageCount : 1;

$currentPage = max(0, min($page, $totalPages));

function getQujingChapterContent($quest): string {
    $rawContent = $quest['content'] ?? '';
    
    // 如果有完整的原始内容，直接使用
    if (!empty($rawContent)) {
        return $rawContent;
    }
    
    // 否则使用结构化字段拼接（兼容旧数据）
    $chapterName = $quest['name'] ?? '';
    $location = $quest['location'] ?? '';
    $description = $quest['description'] ?? '';
    $questSteps = $quest['quest_steps'] ?? '';
    $npcList = $quest['npc_list'] ?? '';
    $importantItems = $quest['important_items'] ?? '';
    $weapons = $quest['weapons'] ?? '';
    $food = $quest['food'] ?? '';
    $otherItems = $quest['other_items'] ?? '';
    $daoxingReq = intval($quest['min_daoxing'] ?? 0);
    $reward = intval($quest['reward'] ?? 0);
    
    $content = "【{$chapterName}／{$location}】\n\n";
    $content .= "－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－\n";
    if ($description) {
        $content .= "〖故事背景〗\n\n{$description}\n\n";
    }
    $content .= "－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－\n";
    if ($questSteps) {
        $content .= "〖破迷要领〗\n\n{$questSteps}\n\n";
    }
    $content .= "－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－\n";
    if ($npcList) {
        $content .= "〖主要人物〗\n\n{$npcList}\n\n";
    }
    if ($importantItems) {
        $content .= "－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－\n";
        $content .= "〖重要物品〗\n\n{$importantItems}\n\n";
    }
    if ($weapons) {
        $content .= "－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－\n";
        $content .= "〖武器装备〗\n\n{$weapons}\n\n";
    }
    if ($food) {
        $content .= "－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－\n";
        $content .= "〖饮食物品〗\n\n{$food}\n\n";
    }
    if ($otherItems) {
        $content .= "－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－\n";
        $content .= "〖其他〗\n\n{$otherItems}\n\n";
    }
    if ($daoxingReq > 0) {
        $content .= "－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－－\n";
        $content .= "〖道行要求〗" . intval($daoxingReq / 10000) . "万年\n";
    }
    if ($reward > 0) {
        $content .= "〖通关奖励〗" . intval($reward / 10000) . "万年道行\n";
    }
    return $content;
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>阅读 - <?php echo htmlspecialchars($itemName); ?></title>
    <link rel="stylesheet" href="../assets/css/read_book.css">
</head>
<body>
    <div class="container">
        <div class="title">📖 <?php echo htmlspecialchars($itemName); ?></div>
        
        <?php if ($isQujingBook): ?>
            <?php if ($currentPage === 0): ?>
                <div class="content">
                    西游记之西天取经指南

使用方法：
- read 1 ~ read <?php echo $pageCount; ?> 阅读各关卡详细信息

取经关卡列表：
<div style="margin-top: 10px;">
<?php
foreach ($qujingQuests as $i => $quest) {
    $num = $i + 1;
    $name = $quest['name'] ?? '';
    $daoxingReq = intval($quest['min_daoxing'] ?? 0);
    $reqStr = $daoxingReq > 0 ? '（' . intval($daoxingReq / 10000) . '万年）' : '';
    echo "<a href='read_book.php?item_id={$itemId}&page={$num}' class='chapter-link'>{$num}. {$name}{$reqStr}</a>";
}
?>
</div>
                </div>
            <?php else: ?>
                <?php
                $quest = $qujingQuests[$currentPage - 1] ?? null;
                if ($quest):
                ?>
                    <div class="content">
                        <?php echo htmlspecialchars(getQujingChapterContent($quest)); ?>
                    </div>
                <?php else: ?>
                    <div class="content">该章节不存在</div>
                <?php endif; ?>
            <?php endif; ?>
            
            <div class="pagination">
                <?php if ($currentPage > 0): ?>
                    <a href="read_book.php?item_id=<?php echo urlencode($itemId); ?>&page=0" class="page-btn">目录</a>
                <?php endif; ?>
                
                <?php if ($currentPage > 1): ?>
                    <a href="read_book.php?item_id=<?php echo urlencode($itemId); ?>&page=<?php echo $currentPage - 1; ?>" class="page-btn">上一章</a>
                <?php endif; ?>
                
                <div class="page-info">
                    <?php if ($currentPage === 0): ?>
                        目录
                    <?php else: ?>
                        第 <?php echo $currentPage; ?> / <?php echo $pageCount; ?> 章
                    <?php endif; ?>
                </div>
                
                <?php if ($currentPage > 0 && $currentPage < $pageCount): ?>
                    <a href="read_book.php?item_id=<?php echo urlencode($itemId); ?>&page=<?php echo $currentPage + 1; ?>" class="page-btn">下一章</a>
                <?php endif; ?>
                
                <?php if ($currentPage > 0): ?>
                    <a href="read_book.php?item_id=<?php echo urlencode($itemId); ?>&page=0" class="page-btn">返回目录</a>
                <?php endif; ?>
            </div>
            
        <?php else: ?>
            <div class="content">
                <?php
                $content = trim($foundItem['description'] ?? '');
                echo $content !== '' ? htmlspecialchars($content) : '上面什么也没有写。';
                ?>
            </div>
        <?php endif; ?>
        
        <a href="room.php" class="back-link">← 返回房间</a>
    </div>
    
    <?php
    $newCount = updateReadCount($charId, $itemId, $category);
    
    $kar = intval($char['kar'] ?? 10);
    $chance = random_int(1, 1000);
    $karSquared = $kar * $kar;
    
    if ($chance <= 10 && random_int(1, 100000) <= $karSquared) {
        clearReadCount($charId, $itemId, $category);
        ItemModel::addToInventory($charId, 'renshen-guo', 1, 'obj');
        ItemModel::removeFromInventory($charId, $itemId, 1, $category);
        echo "<script>alert('恭喜！你在{$itemName}中发现了一枚人参果！书籍已化作纸屑消失。'); setTimeout(function(){window.location.href='room.php';}, 1000);</script>";
    } elseif ($chance <= 10 && random_int(1, 1000) <= $kar) {
        clearReadCount($charId, $itemId, $category);
        ItemModel::addToInventory($charId, 'mihoutao', 1, 'obj');
        ItemModel::removeFromInventory($charId, $itemId, 1, $category);
        echo "<script>alert('恭喜！你在{$itemName}中发现了一颗猕猴桃！书籍已化作纸屑消失。'); setTimeout(function(){window.location.href='room.php';}, 1000);</script>";
    } elseif ($chance <= 20 && random_int(1, 100) <= $kar * 0.5) {
        clearReadCount($charId, $itemId, $category);
        ItemModel::addToInventory($charId, 'gold', 1, 'obj');
        ItemModel::removeFromInventory($charId, $itemId, 1, $category);
        echo "<script>alert('恭喜！你在{$itemName}中发现了一块金锭！书籍已化作纸屑消失。'); setTimeout(function(){window.location.href='room.php';}, 1000);</script>";
    } elseif ($newCount >= MAX_READ_COUNT) {
        clearReadCount($charId, $itemId, $category);
        ItemModel::removeFromInventory($charId, $itemId, 1, $category);
        echo "<script>alert('{$itemName}已经翻得破烂不堪，化作了一堆纸屑消失了。'); setTimeout(function(){window.location.href='room.php';}, 1000);</script>";
    }
    ?>
</body>
</html>