<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 寄存页面 - 显示玩家背包物品，选择寄存
 */

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/../config/game.php';

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once MODEL_PATH . 'Item.php';
require_once HELPER_PATH . 'MoneyHelper.php';

// 检查登录
if (!isset($_SESSION['char_id'])) {
    redirect('../index.php');
}

$charId = $_SESSION['char_id'];
$area = $_GET['area'] ?? 'city';
$roomId = $_GET['room'] ?? 'city/jicundian';

// 获取角色信息
$char = CharacterModel::find($charId);
if (!$char) {
    die('角色不存在');
}

// 获取背包物品（排除货币物品）
$allItems = ItemModel::getCharacterItems($charId);
$depositableItems = array_filter($allItems, function($item) {
    // 排除货币物品
    return !in_array($item['item_id'], ['coin', 'silver', 'gold', 'copper']);
});

// 获取已寄存的物品（包括未过期和已过期的）
$depositedItems = Database::queryAll(
    'SELECT * FROM deposit_storage WHERE char_id = ? ORDER BY id DESC',
    [$charId]
);

// 获取所有过期的寄存物品（用于购买）
$expiredItemsForSale = Database::queryAll(
    'SELECT * FROM deposit_storage WHERE expire_time < ? ORDER BY expire_time ASC',
    [time()]
);

// 处理购买请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pick') {
    $depositId = intval($_POST['deposit_id'] ?? 0);
    
    if ($depositId <= 0) {
        $message = '<div class="error">请选择要购买的物品。</div>';
    } else {
        // 查找过期物品
        $deposit = Database::queryOne(
            'SELECT * FROM deposit_storage WHERE id = ? AND expire_time < ?',
            [$depositId, time()]
        );
        
        if (!$deposit) {
            $message = '<div class="error">该物品不存在或未被没收。</div>';
        } else {
            // 费用：10两银子 = 1000铜钱
            $fee = 1000;
            
            // 检查金钱
            if (!MoneyHelper::hasEnoughMoney($charId, $fee)) {
                $message = '<div class="error">你没有足够的钱（需要1000铜钱）。</div>';
            } else {
                // 扣除费用
                MoneyHelper::deductMoney($charId, $fee);
                
                // 恢复物品到背包
                $itemData = json_decode($deposit['item_data'], true);
                
                if (!$itemData) {
                    $message = '<div class="error">物品数据损坏，无法购买。</div>';
                } else {
                    ItemModel::addToInventory($charId, $itemData['item_id'], $itemData['quantity'] ?? 1);
                    
                    // 从寄存表中删除
                    Database::execute('DELETE FROM deposit_storage WHERE id = ?', [$depositId]);
                    
                    log_game('PICK', "{$char['name']} 购买过期箱子 (原主人ID: {$deposit['char_id']})");
                    
                    $message = '<div class="success">成功购买 ' . h($itemData['name']) . '，支付1000铜钱。</div>';
                    
                    // 刷新数据
                    $allItems = ItemModel::getCharacterItems($charId);
                    $depositableItems = array_filter($allItems, function($item) {
                        return !in_array($item['item_id'], ['coin', 'silver', 'gold', 'copper']);
                    });
                    $depositedItems = Database::queryAll(
                        'SELECT * FROM deposit_storage WHERE char_id = ? ORDER BY id DESC',
                        [$charId]
                    );
                    $expiredItemsForSale = Database::queryAll(
                        'SELECT * FROM deposit_storage WHERE expire_time < ? ORDER BY expire_time ASC',
                        [time()]
                    );
                }
            }
        }
    }
}

// 处理取回请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'withdraw') {
    $depositId = intval($_POST['deposit_id'] ?? 0);
    
    if ($depositId <= 0) {
        $message = '<div class="error">请选择要取回的物品。</div>';
    } else {
        // 查找寄存记录
        $deposit = Database::queryOne(
            'SELECT * FROM deposit_storage WHERE id = ? AND char_id = ?',
            [$depositId, $charId]
        );
        
        if (!$deposit) {
            $message = '<div class="error">寄存记录不存在。</div>';
        } elseif ($deposit['expire_time'] <= time()) {
            $message = '<div class="error">寄存已过期，物品已被没收并公开出售。</div>';
            // 不删除记录，保留供 pick 命令购买
        } else {
            // 恢复物品到背包
            $itemData = json_decode($deposit['item_data'], true);
            
            if (!$itemData) {
                $message = '<div class="error">物品数据损坏，无法取回。</div>';
            } else {
                ItemModel::addToInventory($charId, $itemData['item_id'], $itemData['quantity'] ?? 1);
                
                // 从寄存表中删除
                Database::execute('DELETE FROM deposit_storage WHERE id = ?', [$depositId]);
                
                log_game('QU', "{$char['name']} 取回寄存物品 (记号: {$deposit['mark']})");
                
                $message = '<div class="success">成功取回 ' . h($itemData['name']) . '。</div>';
                
                // 刷新数据
                $allItems = ItemModel::getCharacterItems($charId);
                $depositableItems = array_filter($allItems, function($item) {
                    return !in_array($item['item_id'], ['coin', 'silver', 'gold', 'copper']);
                });
                $depositedItems = Database::queryAll(
                    'SELECT * FROM deposit_storage WHERE char_id = ? ORDER BY id DESC',
                    [$charId]
                );
            }
        }
    }
}

// 处理寄存请求
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'deposit') {
    $itemId = $_POST['item_id'] ?? '';
    $hours = intval($_POST['hours'] ?? 0);
    
    if (empty($itemId)) {
        $message = '<div class="error">请选择要寄存的物品。</div>';
    } elseif ($hours < 1 || $hours > 24) {
        $message = '<div class="error">寄存时间必须在1-24小时之间。</div>';
    } else {
        // 查找物品
        $targetItem = null;
        foreach ($allItems as $item) {
            if ($item['item_id'] === $itemId) {
                $targetItem = $item;
                break;
            }
        }
        
        if (!$targetItem) {
            $message = '<div class="error">物品不存在。</div>';
        } elseif (!empty($targetItem['no_store'])) {
            $message = '<div class="error">这样东西不能寄存。</div>';
        } else {
            $isPlayerFabao = false;
            if (!empty($targetItem['series_no'])) {
                $fabaoCheck = Database::queryOne(
                    "SELECT 1 FROM character_fabao WHERE owner_id = ? AND series_no = ? LIMIT 1",
                    [$charId, $targetItem['series_no']]
                );
                if ($fabaoCheck) {
                    $isPlayerFabao = true;
                }
            }
            if ($isPlayerFabao) {
                $message = '<div class="error">你自己炼制的法宝不能寄存。</div>';
            } else {
                // 计算费用
                $fee = $hours * 100; // 每小时100铜钱
                
                // 检查金钱
                if (!MoneyHelper::hasEnoughMoney($charId, $fee)) {
                    $message = '<div class="error">你没有足够的钱（需要' . $fee . '铜钱）。</div>';
                } else {
                    // 扣除费用
                    MoneyHelper::deductMoney($charId, $fee);
                    
                    // 保存物品数据到寄存表
                    $expireTime = time() + ($hours * 3600);
                    $mark = 'box_' . $charId . '_' . time(); // 自动生成记号
                    
                    $itemData = json_encode([
                        'item_id' => $targetItem['item_id'],
                        'name' => $targetItem['name'],
                        'quantity' => $targetItem['quantity'] ?? 1,
                        'mark' => $mark,
                    ], JSON_UNESCAPED_UNICODE);
                    
                    Database::execute(
                        'INSERT INTO deposit_storage (char_id, item_id, item_data, mark, expire_time, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
                        [$charId, $targetItem['id'], $itemData, $mark, $expireTime]
                    );
                    
                    // 从背包中移除物品
                    Database::execute('DELETE FROM character_inventory WHERE id = ?', [$targetItem['id']]);
                    
                    log_game('JICUN', "{$char['name']} 寄存 {$targetItem['name']} (记号: {$mark}) {$hours}小时");
                    
                    $message = '<div class="success">成功寄存 ' . h($targetItem['name']) . ' ' . $hours . '小时。<br>记号：<strong>' . h($mark) . '</strong><br>请使用此记号取回物品。</div>';
                    
                    // 刷新物品列表
                    $allItems = ItemModel::getCharacterItems($charId);
                    $depositableItems = array_filter($allItems, function($item) {
                        return !in_array($item['item_id'], ['coin', 'silver', 'gold', 'copper']);
                    });
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>寄存物品 - 钱庄地下室</title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
    <style>
        .deposit-info {
            font-size: 11px;
            color: #555;
        }
        .item-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 4px 0;
            flex-wrap: nowrap;
        }
        .item-name {
            flex-shrink: 0;
        }
        .item-quantity {
            flex-shrink: 0;
        }
        .deposit-form {
            flex-shrink: 0;
            margin-left: auto;
        }
    </style>
</head>
<body>
<div class="deposit-container">
    <p>欢迎来到钱庄地下室，这里可以寄存你的物品。</p>
    
    <div class="info-box">
        你带着：<span class="money-info"><?= MoneyHelper::formatMoney($charId) ?></span><br>
    </div>
    
    <?php if ($message): ?>
    <?= $message ?>
    <?php endif; ?>
    <br>
    <div class="item-list">
        --可寄存--
        <br>
        <?php if (empty($depositableItems)): ?>
        <div class="empty-message">
            <p>你的背包中没有可寄存的物品。</p>
        </div>
        <?php else: ?>
            <?php foreach ($depositableItems as $item): ?>
            <div class="item-row">
                <div class="item-name"><?= h($item['name']) ?></div>
                <div class="item-quantity">x<?= $item['quantity'] ?? 1 ?></div>
                <div class="deposit-form">
                    <form method="POST" action="" style="display:inline;">
                        <input type="hidden" name="action" value="deposit">
                        <input type="hidden" name="item_id" value="<?= h($item['item_id']) ?>">
                        <select name="hours" required>
                            <option value="">选择时长</option>
                            <option value="1">1小时 (100铜钱)</option>
                            <option value="5">5小时 (500铜钱)</option>
                            <option value="12">12小时 (1200铜钱)</option>
                            <option value="24">24小时 (2400铜钱)</option>
                        </select>
                        <button type="submit">寄存</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <br>
    <div class="item-list">
        --可取回--
        <br>
        <?php 
        // 筛选未过期的物品
        $activeDeposits = array_filter($depositedItems, function($deposit) {
            return $deposit['expire_time'] > time();
        });
        
        if (empty($activeDeposits)): ?>
        <div class="empty-message">
            <p>你没有寄存中的物品。</p>
        </div>
        <?php else: ?>
        <form method="POST" action="">
            <input type="hidden" name="action" value="withdraw">
            
            <?php foreach ($activeDeposits as $deposit): ?>
            <?php 
                $itemData = json_decode($deposit['item_data'], true);
                $remainingTime = $deposit['expire_time'] - time();
                $hours = floor($remainingTime / 3600);
                $minutes = floor(($remainingTime % 3600) / 60);
            ?>
            <div class="item-row">
                <div class="item-name"><?= h($itemData['name']) ?></div>
                <div class="item-quantity">记号: <?= h($deposit['mark']) ?></div>
                <div class="deposit-info" style="color:#999; font-size:10px;">
                    剩余: <?= $hours ?>小时<?= $minutes ?>分
                </div>
                <div class="deposit-form">
                    <input type="hidden" name="deposit_id" value="<?= $deposit['id'] ?>">
                    <button type="submit">取回</button>
                </div>
            </div>
            <?php endforeach; ?>
        </form>
        <?php endif; ?>
    </div>
    
    <br>
    <div class="item-list">
        --已过期（已被没收）--
        <br>
        <?php 
        // 筛选已过期的物品
        $expiredDeposits = array_filter($depositedItems, function($deposit) {
            return $deposit['expire_time'] <= time();
        });
        
        if (empty($expiredDeposits)): ?>
        <div class="empty-message">
            <p>没有已过期的寄存物品。</p>
        </div>
        <?php else: ?>
        <?php foreach ($expiredDeposits as $deposit): ?>
        <?php 
            $itemData = json_decode($deposit['item_data'], true);
            $expireTime = date('Y-m-d H:i', $deposit['expire_time']);
        ?>
        <div class="item-row" style="opacity: 0.6;">
            <div class="item-name"><?= h($itemData['name']) ?></div>
            <div class="item-quantity">记号: <?= h($deposit['mark']) ?></div>
            <div class="deposit-info" style="color:#d9534f; font-size:10px;">
                已过期: <?= $expireTime ?>
            </div>
            <div class="deposit-info" style="color:#d9534f; font-size:10px;">
                已被没收，可被其他玩家购买
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <br>
    <div class="item-list">
        --公开出售的过期物品--
        <br>
        <?php if (empty($expiredItemsForSale)): ?>
        <div class="empty-message">
            <p>目前没有公开出售的过期物品。</p>
        </div>
        <?php else: ?>
        <form method="POST" action="">
            <input type="hidden" name="action" value="pick">
            
            <?php foreach ($expiredItemsForSale as $deposit): ?>
            <?php 
                $itemData = json_decode($deposit['item_data'], true);
                $expireTime = date('Y-m-d H:i', $deposit['expire_time']);
            ?>
            <div class="item-row">
                <div class="item-name"><?= h($itemData['name']) ?></div>
                <div class="item-quantity">原记号: <?= h($deposit['mark']) ?></div>
                <div class="deposit-info" style="color:#999; font-size:10px;">
                    过期时间: <?= $expireTime ?>
                </div>
                <div class="deposit-form">
                    <input type="hidden" name="deposit_id" value="<?= $deposit['id'] ?>">
                    <button type="submit">购买 (1000铜钱)</button>
                </div>
            </div>
            <?php endforeach; ?>
        </form>
        <?php endif; ?>
    </div>
    <div class="deposit-info">
        寄存费用：每小时 1 两银子（100 铜钱）<br>
    最长寄存时间：24 小时<br>
    超时将被没收并公开出售
</div>
    <br>
    <a href="<?= room_url($area, $roomId) ?>" class="back-link">返回房间</a>
</div>
</body>
</html>

