<?php
session_save_path(__DIR__ . '/../sessions');
session_start();
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../helpers/MoneyHelper.php';
require_once MODEL_PATH . 'Item.php';
require_login();

$charId = get_char_id();
$char = Database::queryOne('SELECT * FROM characters WHERE id = ?', [$charId]);
$message = '';
$messageType = '';

// 获取玩家的 silver-money 数量
$silverMoneyItem = Database::queryOne(
    "SELECT quantity FROM character_inventory WHERE char_id = ? AND item_id = ?",
    [$charId, 'silver-money']
);
$silverMoneyCount = $silverMoneyItem ? intval($silverMoneyItem['quantity']) : 0;

// 商城商品配置
$shopItems = [
    [
        'id' => 1,
        'item_id' => 'gold',
        'name' => '黄金',
        'desc' => '100 两黄金',
        'price' => 1,
        'amount' => 100,
        'type' => 'gold'
    ],
    [
        'id' => 2,
        'item_id' => 'crystalball',
        'name' => '水晶球',
        'desc' => '神秘的水晶球，蕴含强大灵力',
        'price' => 1,
        'amount' => 1,
        'type' => 'item'
    ],
    [
        'id' => 3,
        'item_id' => 'tianwang_coat',
        'name' => '天王披风',
        'desc' => '李天王的披风，有特殊妙用。',
        'price' => 1,
        'amount' => 1,
        'type' => 'item'
    ],
];

// 处理兑换请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['item_id'])) {
    $itemId = intval($_POST['item_id']);
    $shopItem = null;
    
    foreach ($shopItems as $item) {
        if ($item['id'] === $itemId) {
            $shopItem = $item;
            break;
        }
    }
    
    if (!$shopItem) {
        $message = '商品不存在！';
        $messageType = 'error';
    } elseif ($silverMoneyCount < $shopItem['price']) {
        $message = '银票不足！需要 ' . $shopItem['price'] . ' 张银票';
        $messageType = 'error';
    } else {
        // 扣除银票
        ItemModel::removeFromInventory($charId, 'silver-money', $shopItem['price']);
        
        // 发放奖励
        if ($shopItem['type'] === 'gold') {
            // 黄金
            $goldCopper = $shopItem['amount'] * 10000;
            MoneyHelper::addMoney($charId, $goldCopper);
            $rewardDesc = "获得 {$shopItem['amount']} 两黄金";
        } elseif ($shopItem['type'] === 'silver') {
            // 白银
            $silverCopper = $shopItem['amount'] * 100;
            MoneyHelper::addMoney($charId, $silverCopper);
            $rewardDesc = "获得 {$shopItem['amount']} 两白银";
        } else {
            // 物品
            ItemModel::addToInventory($charId, $shopItem['item_id'], $shopItem['amount']);
            $rewardDesc = "获得 {$shopItem['name']} x{$shopItem['amount']}";
        }
        
        // 重新获取银票数量
        $silverMoneyItem = Database::queryOne(
            "SELECT quantity FROM character_inventory WHERE char_id = ? AND item_id = ?",
            [$charId, 'silver-money']
        );
        $silverMoneyCount = $silverMoneyItem ? intval($silverMoneyItem['quantity']) : 0;
        
        $message = "兑换成功！{$rewardDesc}";
        $messageType = 'success';
    }
}

// 获取兑换记录（如果有）
// $exchangeHistory = Database::queryAll("SELECT * FROM shop_logs WHERE char_id = ? ORDER BY exchange_time DESC LIMIT 20", [$charId]);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>商城</title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
    <link rel="stylesheet" href="../assets/css/shop.css">
</head>
<body>
<div class="npc-content">
    <span>商城</span>
    <?php if ($message): ?>
        <div class="message <?php echo $messageType; ?>">
            <?php echo h($message); ?>
        </div>
    <?php endif; ?>

    <div class="npc-info">
        <p>当前银票：<?= $silverMoneyCount ?> 张</p>
        <p class="shop-hint">使用银票可以兑换各种物品和货币</p>
    </div>

    <div class="npc-info">
        <p>商品列表：</p>
        <table class="shop-table">
            <thead>
                <tr>
                    <th>物品</th>
                    <th>描述</th>
                    <th>数量</th>
                    <th>价格</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($shopItems as $item): ?>
                <tr>
                    <td><?= $item['name'] ?></td>
                    <td class="item-desc"><?= $item['desc'] ?></td>
                    <td class="item-amount">x<?= $item['amount'] ?></td>
                    <td class="item-price"><?= $item['price'] ?> 银票</td>
                    <td class="item-action">
                        <form method="POST" action="" class="shop-form">
                            <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                            <button type="submit" class="action-btn" onclick="return confirm('确定要兑换「<?= $item['name'] ?>」吗？需要 <?= $item['price'] ?> 张银票')">
                                兑换
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="npc-info">
        <p>说明：</p>
        <p class="shop-desc">1. 银票可通过充值或活动获得</p>
        <p class="shop-desc">2. 兑换后物品直接放入背包</p>
        <p class="shop-desc">3. 货币类兑换自动转换为最大面额</p>
        <p class="shop-desc">4. 如遇问题请联系客服</p>
    </div>

    <div class="npc-actions">
        <a href="room.php">返回游戏</a>
    </div>
</div>
</body>
</html>
