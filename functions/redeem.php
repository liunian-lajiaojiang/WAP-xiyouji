<?php
session_save_path(__DIR__ . '/../sessions');
session_start();
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Item.php';
require_login();
$charId = get_char_id();
$char = Database::queryOne('SELECT * FROM characters WHERE id = ?', [$charId]);
$message = '';
$messageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code'])) {
    $code = strtoupper(trim($_POST['code']));
    if (empty($code)) {
        $message = '请输入兑换码！';
        $messageType = 'error';
    } else {
        $redeemCode = Database::queryOne("SELECT * FROM redeem_codes WHERE code = ? AND is_active = 1", [$code]);
        if (!$redeemCode) {
            $message = '兑换码不存在或已失效！';
            $messageType = 'error';
        } elseif ($redeemCode['expire_at'] && strtotime($redeemCode['expire_at']) < time()) {
            $message = '该兑换码已过期！';
            $messageType = 'error';
        } elseif ($redeemCode['max_uses'] > 0 && $redeemCode['used_count'] >= $redeemCode['max_uses']) {
            $message = '该兑换码已被使用完毕！';
            $messageType = 'error';
        } else {
            $alreadyRedeemed = Database::queryOne("SELECT * FROM redeem_logs WHERE char_id = ? AND code_id = ?", [$charId, $redeemCode['id']]);
            if ($alreadyRedeemed) {
                $message = '你已经兑换过此兑换码！';
                $messageType = 'error';
            } else {
                $rewardData = json_decode($redeemCode['reward_data'], true);
                $result = distributeReward($charId, $char, $redeemCode['type'], $rewardData);
                if (!$result['success']) {
                    $message = $result['description'];
                    $messageType = 'error';
                } else {
                    Database::execute("UPDATE redeem_codes SET used_count = used_count + 1 WHERE id = ?", [$redeemCode['id']]);
                    Database::execute("INSERT INTO redeem_logs (char_id, code_id, code, reward_type, reward_desc) VALUES (?, ?, ?, ?, ?)", [$charId, $redeemCode['id'], $code, $redeemCode['type'], $result['description']]);
                    $message = '兑换成功！' . $result['description'];
                    $messageType = 'success';
                }
            }
        }
    }
}
function addItemToInventory($charId, $itemId, $amount) {
    // 使用统一的 addToInventory，自动处理液体容器不堆叠
    ItemModel::addToInventory($charId, $itemId, $amount);
}
function distributeReward($charId, $me, $type, $rewardData) {
    switch ($type) {
        case 'exp':
            $exp = intval($rewardData['amount'] ?? 0);
            if ($exp <= 0) return ['success' => false, 'description' => '奖励数据错误！'];
            Database::execute("UPDATE characters SET combat_exp = combat_exp + ? WHERE id = ?", [$exp, $charId]);
            return ['success' => true, 'description' => "获得经验值：{$exp} 点"];
        case 'potential':
            $potential = intval($rewardData['amount'] ?? 0);
            if ($potential <= 0) return ['success' => false, 'description' => '奖励数据错误！'];
            Database::execute("UPDATE characters SET potential = potential + ? WHERE id = ?", [$potential, $charId]);
            return ['success' => true, 'description' => "获得潜能：{$potential} 点"];
        case 'gold':
            $gold = intval($rewardData['amount'] ?? 0);
            if ($gold <= 0) return ['success' => false, 'description' => '奖励数据错误！'];
            Database::execute("UPDATE characters SET gold = gold + ? WHERE id = ?", [$gold, $charId]);
            return ['success' => true, 'description' => "获得黄金：{$gold} 两"];
        case 'silver':
            $silver = intval($rewardData['amount'] ?? 0);
            if ($silver <= 0) return ['success' => false, 'description' => '奖励数据错误！'];
            Database::execute("UPDATE characters SET silver = silver + ? WHERE id = ?", [$silver, $charId]);
            return ['success' => true, 'description' => "获得银两：{$silver} 两"];
        case 'item':
            $itemId = strval($rewardData['item_id'] ?? '');
            $amount = intval($rewardData['amount'] ?? 1);
            if (empty($itemId)) return ['success' => false, 'description' => '奖励数据错误！'];
            $item = Database::queryOne("SELECT * FROM items WHERE item_id = ?", [$itemId]);
            if (!$item) return ['success' => false, 'description' => '奖励物品不存在！'];
            addItemToInventory($charId, $itemId, $amount);
            return ['success' => true, 'description' => "获得物品：{$item['name']} x{$amount}"];
        case 'custom':
            $description = $rewardData['description'] ?? '获得神秘奖励';
            $customCombatExp = intval($rewardData['combat_exp'] ?? 0);
            $customPotential = intval($rewardData['potential'] ?? 0);
            $customGold = intval($rewardData['gold'] ?? 0);
            $customSilver = intval($rewardData['silver'] ?? 0);
            if ($customCombatExp > 0) Database::execute("UPDATE characters SET combat_exp = combat_exp + ? WHERE id = ?", [$customCombatExp, $charId]);
            if ($customPotential > 0) Database::execute("UPDATE characters SET potential = potential + ? WHERE id = ?", [$customPotential, $charId]);
            if ($customGold > 0) Database::execute("UPDATE characters SET gold = gold + ? WHERE id = ?", [$customGold, $charId]);
            if ($customSilver > 0) Database::execute("UPDATE characters SET silver = silver + ? WHERE id = ?", [$customSilver, $charId]);
            // 发放物品奖励（如 crystalball）
            $itemRewards = $rewardData['items'] ?? [];
            // 兼容旧格式：如果 reward_data 中有 crystalball 等字段直接发放
            $knownItems = ['crystalball'];
            foreach ($knownItems as $knownItem) {
                $qty = intval($rewardData[$knownItem] ?? 0);
                if ($qty > 0) {
                    $itemRewards[] = ['item_id' => $knownItem, 'amount' => $qty];
                }
            }
            foreach ($itemRewards as $itemReward) {
                $iid = strval($itemReward['item_id'] ?? '');
                $qty = intval($itemReward['amount'] ?? 1);
                if (!empty($iid) && $qty > 0) {
                    addItemToInventory($charId, $iid, $qty);
                }
            }
            return ['success' => true, 'description' => $description];
        default:
            return ['success' => false, 'description' => '未知的奖励类型！'];
    }
}
$redeemHistory = Database::queryAll("SELECT * FROM redeem_logs WHERE char_id = ? ORDER BY redeemed_at DESC LIMIT 20", [$charId]);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>兑换码</title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
    </head>
<body>
<div class="npc-content">
    <h3 style="color:#ff69b4;">🎁 兑换码</h3>

    <?php if ($message): ?>
        <div class="message <?php echo $messageType; ?>">
            <?php echo h($message); ?>
        </div>
    <?php endif; ?>

    <div class="npc-info">
        <p>请输入兑换码以获取奖励：</p>
    </div>

    <div class="npc-actions">
        <form method="POST" action="">
            <div >
                <input type="text" name="code" placeholder="请输入兑换码" required autocomplete="off">
            </div>
            <button type="submit" class="action-btn">兑换</button>
        </form>
    </div>

    <?php if (!empty($redeemHistory)): ?>
        <div class="npc-info">
            <p style="color:#ffd700;">兑换历史：</p>
            <?php foreach ($redeemHistory as $log): ?>
                <div >
                    <p style="margin:5px 0;">使用礼包码：<?php echo h($log['code']); ?>，获得：<?php echo h($log['reward_desc']); ?></p>
                    <p style="margin:5px 0;color:#999;font-size:12px;"><?php echo h($log['redeemed_at']); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="npc-actions">
        <a href="room.php">返回游戏</a>
    </div>
</div>
</body>
</html>


