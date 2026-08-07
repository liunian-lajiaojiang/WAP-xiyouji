<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 尸体页面 - 查看尸体和拾取物品
 */

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once MODEL_PATH . 'Corpse.php';
require_once MODEL_PATH . 'Item.php';
require_once HELPER_PATH . 'MoneyHelper.php';
require_once __DIR__ . '/../helpers/WeightHelper.php';
require_once DAEMON_PATH . 'MessageDaemon.php';

require_login();

$charId = get_char_id();
$char = CharacterModel::getFullInfo($charId);

// 获取尸体ID
$corpseId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$corpseId) {
    redirect('room.php');
}

// 获取尸体信息
$corpse = Corpse::find($corpseId);
if (!$corpse) {
    $_SESSION['flash_message'] = ['content' => '尸体不存在或已经腐烂。', 'timestamp' => time()];
    redirect('room.php');
}

// 访问控制：检查玩家是否在同一房间或正在背着该尸体
$currentArea = $char['current_area'] ?? '';
$currentRoom = $char['current_room'] ?? '';
$isCarriedByMe = Corpse::isCarriedBy($corpseId, $charId);
$isInSameRoom = (intval($corpse['carried']) === 0 
    && $corpse['room_area'] === $currentArea 
    && $corpse['room_id'] === $currentRoom);

if (!$isCarriedByMe && !$isInSameRoom) {
    $_SESSION['flash_message'] = ['content' => '你不在尸体旁边。', 'timestamp' => time()];
    redirect('room.php');
}

// 检查是否在荒坟堆（只有荒坟堆才能埋葬）
$isInGraveyard = ($currentArea === 'changan' && $currentRoom === 'changan/fendui');

// 获取尸体物品
$corpseItems = Corpse::getItems($corpseId);

// 处理物品拾取请求
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'loot') {
    $corpseItemId = isset($_POST['corpse_item_id']) ? intval($_POST['corpse_item_id']) : 0;
    
    // 查找对应的尸体物品
    $targetItem = null;
    foreach ($corpseItems as $item) {
        if ($item['id'] === $corpseItemId) {
            $targetItem = $item;
            break;
        }
    }
    
    if ($targetItem) {
        // 防重复拾取：检查物品是否仍在尸体中
        if (!Corpse::itemStillInCorpse($corpseId, $corpseItemId)) {
            $_SESSION['flash_message'] = ['content' => '该物品已经被拾取！', 'timestamp' => time()];
            redirect("corpse.php?id={$corpseId}");
        }
        
        // 处理货币拾取（兼容 'coin' 和 'copper'，以及 item_type='currency'）
        $itemId = $targetItem['item_id'];
        $isCurrency = in_array($itemId, ['gold', 'silver', 'coin', 'copper']) 
            || (isset($targetItem['item_type']) && $targetItem['item_type'] === 'currency');
        
        if ($isCurrency) {
            $amount = $targetItem['quantity'];
            
            // 使用货币系统添加 - 'copper' 和 'coin' 都是铜钱
            if ($itemId === 'gold') {
                MoneyHelper::addMoney($charId, $amount * 10000);
            } elseif ($itemId === 'silver') {
                MoneyHelper::addMoney($charId, $amount * 100);
            } else {
                // coin 和 copper 都是铜钱
                MoneyHelper::addMoney($charId, $amount);
            }
            
            $currencyNames = ['gold' => '两黄金', 'silver' => '两白银', 'coin' => '铜钱', 'copper' => '铜钱'];
            $currencyName = $currencyNames[$itemId] ?? '铜钱';
            $message = sprintf(HTML_HIGRN . '你获得了 %d %s！' . HTML_NOR, $amount, $currencyName);
            MessageDaemon::queueMessageToSelf($charId, $message, 'self_event');
            
            // 删除尸体物品
            Corpse::removeItem($corpseItemId);
            
            // 标记尸体已被搜刮
            Corpse::markLooted($corpseId);
            
            // 刷新页面
            redirect("corpse.php?id={$corpseId}");
        } else {
            // 普通物品，需要添加到玩家背包
            // 检查玩家背包容量（排除货币物品）
            $sql = "SELECT COALESCE(SUM(quantity), 0) as total_qty FROM character_inventory WHERE char_id = ? AND item_id NOT IN ('gold', 'silver', 'coin', 'copper')";
            $inventoryCount = Database::queryOne($sql, [$charId]);
            $currentCount = $inventoryCount['total_qty'] ?? 0;
            
            if ($currentCount >= 30) {
                $_SESSION['flash_message'] = ['content' => '你的背包已满！', 'timestamp' => time()];
                redirect("corpse.php?id={$corpseId}");
            }
            
            // 负重检查 - 对不存在于 items 表的物品放行（按0重量处理）
            $itemRow = Database::queryOne("SELECT weight FROM items WHERE item_id = ?", [$itemId]);
            if ($itemRow) {
                $canPickUp = WeightHelper::canPickUp($charId, $itemId, $targetItem['quantity'] ?? 1);
                if (!$canPickUp['success']) {
                    $_SESSION['flash_message'] = ['content' => $canPickUp['message'], 'timestamp' => time()];
                    redirect("corpse.php?id={$corpseId}");
                }
            }
            
            // 添加物品到背包（液体容器会自动拆分为独立行）
            // 保留物品原始category（从NPC装备掉落时需要保留）
            ItemModel::addToInventory($charId, $targetItem['item_id'], $targetItem['quantity'], $targetItem['category'] ?? '');
            
            // 删除尸体物品
            Corpse::removeItem($corpseItemId);
            
            // 标记尸体已被搜刮
            Corpse::markLooted($corpseId);
            
            $message = sprintf(HTML_HIGRN . '你拾取了 %s！' . HTML_NOR, $targetItem['item_name']);
            MessageDaemon::queueMessageToSelf($charId, $message, 'self_event');
            
            // 刷新页面
            redirect("corpse.php?id={$corpseId}");
        }
    }
}

// 处理全部拾取
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'loot_all') {
    // 检查玩家背包容量（排除货币物品）
    $sql = "SELECT COALESCE(SUM(quantity), 0) as total_qty FROM character_inventory WHERE char_id = ? AND item_id NOT IN ('gold', 'silver', 'coin', 'copper')";
    $inventoryCount = Database::queryOne($sql, [$charId]);
    $currentCount = $inventoryCount['total_qty'] ?? 0;
    
    if ($currentCount >= 30) {
        $_SESSION['flash_message'] = ['content' => '你的背包已满！', 'timestamp' => time()];
        redirect("corpse.php?id={$corpseId}");
    }
    
    // 获取当前负重和最大负重（用于累计检查）
    $currentWeight = WeightHelper::getCurrentCarryWeight($charId);
    $charInfo = CharacterModel::find($charId);
    $maxWeight = $charInfo ? WeightHelper::getMaxCarryWeight($charInfo) : 40000;
    $lootedWeight = 0; // 已拾取物品的总重量
    $lootedCount = 0;
    $lootFailed = false;
    
    foreach ($corpseItems as $item) {
        // 防重复拾取：检查物品是否仍在尸体中
        if (!Corpse::itemStillInCorpse($corpseId, $item['id'])) {
            continue; // 该物品已被拾取，跳过
        }
        
        // 检查背包容量
        if ($currentCount + $lootedCount >= 30) {
            $lootFailed = true;
            break;
        }
        
        $itemId = $item['item_id'];
        $quantity = $item['quantity'] ?? 1;
        // 兼容 'coin' 和 'copper'，以及 item_type='currency'
        $isCurrency = in_array($itemId, ['gold', 'silver', 'coin', 'copper']) 
            || (isset($item['item_type']) && $item['item_type'] === 'currency');
        
        if ($isCurrency) {
            // 处理货币（货币不计入负重和物品数量）
            $amount = $quantity;
            if ($itemId === 'gold') {
                MoneyHelper::addMoney($charId, $amount * 10000);
            } elseif ($itemId === 'silver') {
                MoneyHelper::addMoney($charId, $amount * 100);
            } else {
                MoneyHelper::addMoney($charId, $amount);
            }
        } else {
            // 普通物品 - 检查负重（累计已拾取的重量）
            $itemRow = Database::queryOne("SELECT weight FROM items WHERE item_id = ? ORDER BY CASE WHEN category != '' THEN 0 ELSE 1 END LIMIT 1", [$itemId]);
            $itemWeight = $itemRow ? intval($itemRow['weight'] ?? 0) : 0;
            $addedWeight = $itemWeight * $quantity;
            
            // 累计检查负重
            if ($currentWeight + $lootedWeight + $addedWeight > $maxWeight) {
                $lootFailed = true;
                continue;
            }
            
            // 添加物品到背包（液体容器会自动拆分为独立行）
            // 保留物品原始category（从NPC装备掉落时需要保留）
            ItemModel::addToInventory($charId, $item['item_id'], $quantity, $item['category'] ?? '');
            $lootedWeight += $addedWeight;
        }
        Corpse::removeItem($item['id']);
        $lootedCount++;
    }
    
    Corpse::markLooted($corpseId);
    
    if ($lootFailed) {
        $message = HTML_HIGRN . '背包已满或负重超限，剩余物品无法拾取！' . HTML_NOR;
    } else {
        $message = HTML_HIGRN . '你搜刮了所有物品！' . HTML_NOR;
    }
    MessageDaemon::queueMessageToSelf($charId, $message, 'self_event');
    redirect("corpse.php?id={$corpseId}");
}

// 处理背起尸体
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bei') {
    // 检查是否已经背着尸体
    $carriedCorpses = Corpse::getCarriedCorpses($charId);
    if (!empty($carriedCorpses)) {
        $_SESSION['flash_message'] = ['content' => '你身上已经背着一具尸体了。', 'timestamp' => time()];
        redirect("corpse.php?id={$corpseId}");
    }
    
    // 检查尸体是否在房间中
    if (intval($corpse['carried']) !== 0) {
        $_SESSION['flash_message'] = ['content' => '这具尸体不在地上。', 'timestamp' => time()];
        redirect("corpse.php?id={$corpseId}");
    }
    
    // 背起尸体
    Corpse::carryCorpse($corpseId, $charId);
    $corpseName = Corpse::getCorpseDisplayName($corpse);
    MessageDaemon::queueMessageToSelf($charId, '你将' . $corpseName . '扶了起来背在背上。', 'self_event');
    $_SESSION['flash_message'] = ['content' => '你将' . $corpse['owner_name'] . '的尸体背在了背上。', 'timestamp' => time()];
    redirect('room.php');
}

// 处理埋葬尸体
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mai') {
    // 二次校验：重新获取尸体数据，防止竞态条件
    $freshCorpse = Corpse::find($corpseId);
    if (!$freshCorpse) {
        $_SESSION['flash_message'] = ['content' => '尸体不存在或已经腐烂。', 'timestamp' => time()];
        redirect('room.php');
    }
    
    // 二次校验权限
    $freshIsCarriedByMe = Corpse::isCarriedBy($corpseId, $charId);
    $freshIsInSameRoom = (intval($freshCorpse['carried']) === 0 
        && $freshCorpse['room_area'] === $char['current_area'] 
        && $freshCorpse['room_id'] === $char['current_room']);
    
    if (!$freshIsCarriedByMe && !$freshIsInSameRoom) {
        $_SESSION['flash_message'] = ['content' => '你不在尸体旁边。', 'timestamp' => time()];
        redirect('room.php');
    }
    
    // 检查是否在荒坟堆
    $currentArea = $char['current_area'] ?? '';
    $currentRoom = $char['current_room'] ?? '';
    $isInGraveyard = ($currentArea === 'changan' && $currentRoom === 'changan/fendui');
    
    if (!$isInGraveyard) {
        $_SESSION['flash_message'] = ['content' => '这里不是埋葬的地方，埋在此处冤魂无法安息。', 'timestamp' => time()];
        redirect('room.php');
    }
    
    $corpseName = $freshCorpse['owner_name'] . '的尸体';
    
    // 埋葬尸体（销毁尸体和物品）
    Corpse::buryCorpse($corpseId);
    
    $selfMsg = '你找了个地方，将' . $corpseName . '好好埋葬了。愿逝者安息。';
    MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'self_event');
    $_SESSION['flash_message'] = ['content' => $selfMsg, 'timestamp' => time()];
    redirect('room.php');
}

// 处理放下尸体
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fang') {
    // 检查尸体是否被当前玩家背着
    if (!Corpse::isCarriedBy($corpseId, $charId)) {
        $_SESSION['flash_message'] = ['content' => '你没有背着这具尸体。', 'timestamp' => time()];
        redirect("corpse.php?id={$corpseId}");
    }
    
    // 放下尸体
    $roomArea = $char['current_area'] ?? '';
    $roomIdStr = $char['current_room'] ?? '';
    Corpse::dropCorpse($corpseId, $roomArea, $roomIdStr);
    
    $corpseName = Corpse::getCorpseDisplayName($corpse);
    MessageDaemon::queueMessageToSelf($charId, '你将' . $corpseName . '轻轻放了下来。', 'self_event');
    $_SESSION['flash_message'] = ['content' => '你放下了' . $corpse['owner_name'] . '的尸体。', 'timestamp' => time()];
    redirect('room.php');
}

// 处理化尸
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dissolve') {
    // 二次校验：重新获取尸体数据，防止竞态条件
    $freshCorpse = Corpse::find($corpseId);
    if (!$freshCorpse) {
        $_SESSION['flash_message'] = ['content' => '尸体不存在或已经腐烂。', 'timestamp' => time()];
        redirect('room.php');
    }
    
    // 二次校验权限
    $freshIsCarriedByMe = Corpse::isCarriedBy($corpseId, $charId);
    $freshIsInSameRoom = (intval($freshCorpse['carried']) === 0 
        && $freshCorpse['room_area'] === $char['current_area'] 
        && $freshCorpse['room_id'] === $char['current_room']);
    
    if (!$freshIsCarriedByMe && !$freshIsInSameRoom) {
        $_SESSION['flash_message'] = ['content' => '你不在尸体旁边。', 'timestamp' => time()];
        redirect('room.php');
    }
    
    // 检查玩家背包是否有化尸粉
    $dustItem = Database::queryOne(
        "SELECT * FROM character_inventory WHERE char_id = ? AND item_id = 'dust' LIMIT 1",
        [$charId]
    );
    
    if (!$dustItem || intval($dustItem['quantity'] ?? 0) < 1) {
        $_SESSION['flash_message'] = ['content' => '你没有化尸粉，无法化尸。', 'timestamp' => time()];
        redirect("corpse.php?id={$corpseId}");
    }
    
    $roomArea = $corpse['room_area'] ?? ($char['current_area'] ?? '');
    $roomIdStr = $corpse['room_id'] ?? ($char['current_room'] ?? '');
    
    // 化尸：销毁尸体，物品散落到房间
    Corpse::dissolveCorpse($corpseId, $roomArea, $roomIdStr);
    
    // 消耗1份化尸粉
    ItemModel::removeFromInventory($charId, 'dust', 1);
    
    $selfMsg = '你用指甲挑了一点化尸粉在' . $corpse['owner_name'] . '的尸体上，只听见一阵「嗤嗤」声响带着一股可怕的恶臭，尸体只剩下一滩黄水。';
    MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'self_event');
    $_SESSION['flash_message'] = ['content' => $selfMsg, 'timestamp' => time()];
    redirect('room.php');
}

// 重新获取尸体物品（可能已经被拾取了）
$corpseItems = Corpse::getItems($corpseId);

// 计算腐烂剩余时间
$decayTime = strtotime($corpse['decay_time']);
$remainingSeconds = max(0, $decayTime - time());
$remainingMinutes = ceil($remainingSeconds / 60);

// 根据腐烂阶段获取显示名称和描述
$pageTitle = Corpse::getCorpseDisplayName($corpse);
$corpseDesc = Corpse::getCorpseDescription($corpse);

// 检查玩家是否背着这具尸体
$isCarriedByMe = Corpse::isCarriedBy($corpseId, $charId);
// 检查玩家是否背着任何尸体
$myCarriedCorpses = Corpse::getCarriedCorpses($charId);
$isCarryingAny = !empty($myCarriedCorpses);
// 检查玩家背包是否有化尸粉
$hasDust = Database::queryOne(
    "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = 'dust' LIMIT 1",
    [$charId]
);
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= $pageTitle ?></title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">

    <style>
        .decay-info { color: #8B0000;}
    </style>
</head>
<body>
<p style="font-size: 20px;"><?= h($pageTitle) ?></p>
<?= h($corpseDesc) ?>
<hr>
<div class="corpse-actions" style="margin: 8px 0;">
    <?php if (intval($corpse['carried']) === 0): ?>
        <?php if (!$isCarryingAny): ?>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="bei">
            <button type="submit" style="margin-right:8px;">背起</button>
        </form>
        <?php else: ?>
        <span style="color:#888;margin-right:8px;">（你已背着尸体）</span>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($isCarriedByMe): ?>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="fang">
            <button type="submit" style="margin-right:8px;">放下</button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
    <form method="POST" style="display:inline;">
        <input type="hidden" name="action" value="mai">
        <button type="submit" style="margin-right:8px;">埋葬</button>
    </form>
    <?php if ($hasDust): ?>
    <form method="POST" style="display:inline;">
        <input type="hidden" name="action" value="dissolve">
        <button type="submit" style="margin-right:8px;">化尸</button>
    </form>
    <?php endif; ?>
</div>
<?php
// 显示临时消息（如背包已满、拾取成功等）
if (isset($_SESSION['flash_message']) && time() - ($_SESSION['flash_message']['timestamp'] ?? 0) < 10):
    $flashContent = h($_SESSION['flash_message']['content'] ?? '');
    echo '<p style="color: #FFD700; font-weight: bold;">' . $flashContent . '</p>';
    unset($_SESSION['flash_message']);
endif;
?>
    <?php if (empty($corpseItems)): ?>
    <p class="empty-message">尸体上已经没有什么值得拿的了。</p>
    <?php else: ?>
    <?php foreach ($corpseItems as $item): ?>
    <div class="item-row">
        <span class="item-name">
            <?= h($item['item_name']) ?><?= $item['quantity'] > 1 ? ' x' . $item['quantity'] : '' ?>
        </span>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="loot">&ensp;
            <input type="hidden" name="corpse_item_id" value="<?= intval($item['id']) ?>">
            <button type="submit" class="loot-btn">拾取</button>
        </form>
    </div>
    <?php endforeach; ?>
    <div class="actions">
        <?php if (!empty($corpseItems)): ?>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="loot_all">
            <button type="submit" class="loot-all-btn">全部拾取</button>
        </form>
        <?php endif; ?>
        <br>
        <br>
        <a href="room.php" class="back-btn">返回</a>
    </div>
    <?php endif; ?>
    
    <?php if (empty($corpseItems)): ?>
    <div class="actions">
        <a href="room.php" class="back-btn">返回</a>
    </div>
    <?php endif; ?>
</div>

</body>
</html>

