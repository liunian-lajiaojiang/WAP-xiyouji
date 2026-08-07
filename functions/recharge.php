<?php
session_save_path(__DIR__ . '/../sessions');
session_start();
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../helpers/MoneyHelper.php';
require_login();

$charId = get_char_id();
$char = Database::queryOne('SELECT * FROM characters WHERE id = ?', [$charId]);
$message = '';
$messageType = '';

// 获取当前货币
$moneyInventory = MoneyHelper::getMoneyInventory($charId);
$gold = $moneyInventory['gold'];
$silver = $moneyInventory['silver'];

// 获取银票数量
$silverMoneyItem = Database::queryOne(
    "SELECT quantity FROM character_inventory WHERE char_id = ? AND item_id = ?",
    [$charId, 'silver-money']
);
$silverMoneyCount = $silverMoneyItem ? intval($silverMoneyItem['quantity']) : 0;

// 充值套餐配置
$rechargePackages = [
    [
        'id' => 1,
        'name' => '新手礼包',
        'price' => 6,
        'silver-money' => 6,
        'bonus' => '赠送 60 两黄金',
        'tag' => '热卖'
    ],
    [
        'id' => 2,
        'name' => '侠客礼包',
        'price' => 30,
        'silver-money' => 30,
        'bonus' => '赠送 300 两黄金',
        'tag' => '推荐'
    ],
    [
        'id' => 3,
        'name' => '武林盟主',
        'price' => 68,
        'silver-money' => 68,
        'bonus' => '赠送 680 两黄金',
        'tag' => ''
    ],
    [
        'id' => 4,
        'name' => '一代宗师',
        'price' => 128,
        'silver-money' => 128,
        'bonus' => '赠送 1280 两黄金',
        'tag' => ''
    ],
    [
        'id' => 5,
        'name' => '武林至尊',
        'price' => 328,
        'silver-money' => 328,
        'bonus' => '赠送 3280 两黄金',
        'tag' => ''
    ],
    [
        'id' => 6,
        'name' => '天下第一',
        'price' => 648,
        'silver-money' => 648,
        'bonus' => '赠送 6480 两黄金',
        'tag' => '豪华'
    ],
];

// 处理充值请求（模拟）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['package_id'])) {
    $packageId = intval($_POST['package_id']);
    $package = null;
    
    foreach ($rechargePackages as $pkg) {
        if ($pkg['id'] === $packageId) {
            $package = $pkg;
            break;
        }
    }
    
    if (!$package) {
        $message = '充值套餐不存在！';
        $messageType = 'error';
    } else {
        // 模拟充值成功
        $goldAmount = isset($package['gold']) ? $package['gold'] : 0;
        $silverMoneyAmount = isset($package['silver-money']) ? $package['silver-money'] : 0;
        $goldBonus = 0;
        if (preg_match('/赠送 (\d+) 两黄金/', $package['bonus'], $matches)) {
            $goldBonus = intval($matches[1]);
        }
        
        // 增加黄金
        $totalGold = $goldAmount + $goldBonus;
        if ($totalGold > 0) {
            $goldCopper = $totalGold * 10000;
            MoneyHelper::addMoney($charId, $goldCopper);
        }
        
        // 增加银票（作为物品发放到背包）
        if ($silverMoneyAmount > 0) {
            ItemModel::addToInventory($charId, 'silver-money', $silverMoneyAmount);
        }
        
        // 记录充值日志（表不存在时跳过）
        try {
            Database::execute(
                "INSERT INTO recharge_logs (char_id, package_id, package_name, price, gold, silver_bonus, status) VALUES (?, ?, ?, ?, ?, ?, 'success')",
                [$charId, $packageId, $package['name'], $package['price'], $silverMoneyAmount, $goldBonus]
            );
        } catch (Throwable $e) {
            // 表不存在时忽略错误
        }
        
        // 重新获取货币信息
        $moneyInventory = MoneyHelper::getMoneyInventory($charId);
        $gold = $moneyInventory['gold'];
        $silver = $moneyInventory['silver'];
        
        // 重新获取银票数量
        $silverMoneyItem = Database::queryOne(
            "SELECT quantity FROM character_inventory WHERE char_id = ? AND item_id = ?",
            [$charId, 'silver-money']
        );
        $silverMoneyCount = $silverMoneyItem ? intval($silverMoneyItem['quantity']) : 0;
        
        $message = "充值成功！";
        if ($goldAmount > 0) {
            $message .= "获得 {$goldAmount} 两黄金";
        }
        if ($silverMoneyAmount > 0) {
            $message .= "获得 {$silverMoneyAmount} 张银票";
        }
        if ($goldBonus > 0) {
            $message .= "，赠送 {$goldBonus} 两黄金";
        }
        $messageType = 'success';
    }
}

// 获取充值记录（表不存在时返回空数组）
$rechargeHistory = [];
try {
    $rechargeHistory = Database::queryAll("SELECT * FROM recharge_logs WHERE char_id = ? ORDER BY recharge_time DESC LIMIT 20", [$charId]);
} catch (Throwable $e) {
    // 表不存在时忽略错误
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>充值中心</title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
</head>
<body>
<div class="npc-content">
    <span>💰 充值中心</span>
    
    <?php if ($message): ?>
        <div class="message <?php echo $messageType; ?>">
            <?php echo h($message); ?>
        </div>
    <?php endif; ?>

    <div class="npc-info">
        <p style="color:red;font-size:16px;font-weight:bold;">暂时关闭，谢谢！</p>
    </div>

    <!-- 暂时关闭
    <div class="npc-info">
        <p>当前余额：<span><?= $silverMoneyCount ?> 张银票</span> / <span><?= $gold ?> 两黄金</span> / <span><?= $silver ?> 两白银</span></p>
    </div>

    <div class="npc-info">
        <p>📢 充值说明：</p>
        <p style="margin:5px 0;color:#aaa;font-size:12px;">1. 充值后银票立即到账，赠送的黄金也会同时发放</p>
        <p style="margin:5px 0;color:#aaa;font-size:12px;">2. 银票可在商城兑换各种道具、黄金、白银等</p>
        <p style="margin:5px 0;color:#aaa;font-size:12px;">3. 1 银票 = 100 两黄金</p>
        <p style="margin:5px 0;color:#aaa;font-size:12px;">4. 如遇充值问题，请联系客服处理</p>
    </div>

    <div class="npc-info">
        <p style="color:#ffd700;">选择充值套餐：</p>
        <table style="width:100%;border-collapse:collapse;margin-top:10px;">
            <thead>
                <tr style="border-bottom:1px solid #333;">
                    <th style="text-align:left;padding:8px;">套餐</th>
                    <th style="text-align:center;padding:8px;">银票</th>
                    <th style="text-align:center;padding:8px;">赠送</th>
                    <th style="text-align:right;padding:8px;">价格</th>
                    <th style="text-align:right;padding:8px;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rechargePackages as $package): ?>
                <tr style="border-bottom:1px solid #222;">
                    <td style="padding:8px;">
                        <?= $package['name'] ?>
                        <?php if ($package['tag']): ?>
                        <span style="background:#e94560;color:#fff;padding:1px 6px;border-radius:8px;font-size:10px;margin-left:5px;">
                            <?= $package['tag'] ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;padding:8px;color:#ffd700;">
                        <?php if (isset($package['gold'])): ?>
                            <?= $package['gold'] ?> 两黄金
                        <?php elseif (isset($package['silver-money'])): ?>
                            <?= $package['silver-money'] ?> 张银票
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;padding:8px;color:#4ecdc4;"><?= $package['bonus'] ?></td>
                    <td style="text-align:right;padding:8px;color:#e94560;font-weight:bold;">¥<?= $package['price'] ?></td>
                    <td style="text-align:right;padding:8px;">
                        <form method="POST" action="" style="display:inline;">
                            <input type="hidden" name="package_id" value="<?= $package['id'] ?>">
                            <button type="submit" class="action-btn" style="padding:5px 12px;font-size:12px;" onclick="return confirm('确定要购买「<?= $package['name'] ?>」吗？')">
                                充值
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (!empty($rechargeHistory)): ?>
    <div class="npc-info">
        <p>充值记录：</p>
        <?php foreach ($rechargeHistory as $log): ?>
        <div style="padding:8px 0;border-bottom:1px solid #222;">
            <p style="margin:0;color:#ffd700;"><?= h($log['package_name']) ?></p>
            <p style="margin:3px 0;color:#aaa;font-size:12px;">
                支付: ¥<?= $log['price'] ?> | 
                获得: <?= $log['gold'] ?> 张银票
                <?php if ($log['silver_bonus'] > 0): ?>
                | 赠送: <?= $log['silver_bonus'] ?> 两黄金
                <?php endif; ?>
            </p>
            <p style="margin:3px 0;color:#666;font-size:11px;"><?= h($log['recharge_time']) ?></p>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    暂时关闭 -->

    <div class="npc-actions">
        <a href="room.php">返回游戏</a>
    </div>
</div>
</body>
</html>
