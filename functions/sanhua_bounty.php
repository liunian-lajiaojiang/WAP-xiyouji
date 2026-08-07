<?php
session_save_path(__DIR__ . '/../sessions');
session_start();
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'SanhuaBounty.php';
require_once MODEL_PATH . 'Corpse.php';
require_once HELPER_PATH . 'MoneyHelper.php';

require_login();
$charId = get_char_id();
$char = Database::queryOne('SELECT * FROM characters WHERE id = ?', [$charId]);

$message = '';
$messageType = '';

// 处理发起悬赏请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pay') {
    $targetName = trim($_POST['target_name'] ?? '');
    $amount = intval($_POST['amount'] ?? 0);
    
    if (empty($targetName)) {
        $message = '请输入被悬赏玩家的名称或ID！';
        $messageType = 'error';
    } elseif ($amount < 1 || $amount > 1000) {
        $message = '悬赏金额必须在1-1000两黄金之间！';
        $messageType = 'error';
    } else {
        // 查找被悬赏玩家
        $target = null;
        
        // 先尝试按ID查找
        if (is_numeric($targetName)) {
            $target = Database::queryOne("SELECT * FROM characters WHERE id = ?", [intval($targetName)]);
        }
        
        // 再尝试按user_id查找
        if (!$target && is_numeric($targetName)) {
            $target = Database::queryOne("SELECT * FROM characters WHERE user_id = ?", [intval($targetName)]);
        }
        
        // 尝试按姓名完全匹配查找
        if (!$target) {
            $target = Database::queryOne("SELECT * FROM characters WHERE name = ? LIMIT 1", [$targetName]);
        }
        
        // 最后尝试按姓名模糊查找
        if (!$target) {
            $target = Database::queryOne("SELECT * FROM characters WHERE name LIKE ? LIMIT 1", ['%' . $targetName . '%']);
        }
        
        if (!$target) {
            $message = '找不到名为 ' . $targetName . ' 的玩家！';
            $messageType = 'error';
        } elseif ($target['id'] == $charId) {
            $message = '你不能悬赏自己！';
            $messageType = 'error';
        } else {
            // 检查余额（balance 单位是文，1两黄金 = 10000文）
            $balance = intval($char['balance'] ?? 0);
            $amountInCoin = $amount * 10000;
            
            if ($balance < $amountInCoin) {
                $message = '你的钱庄存款不足！需要 ' . $amount . ' 两黄金（' . $amountInCoin . ' 文）。';
                $messageType = 'error';
            } else {
                // 检查悬赏数量限制
                $bountyCount = SanhuaBounty::getBountyCount();
                if ($bountyCount >= 2000) {
                    $message = '悬赏名单已满，请稍后再试！';
                    $messageType = 'error';
                } else {
                    // 扣除余额
                    $newBalance = $balance - $amountInCoin;
                    Database::execute("UPDATE characters SET balance = ? WHERE id = ?", [$newBalance, $charId]);
                    
                    // 添加悬赏
                    SanhuaBounty::addBounty(
                        $target['id'],
                        $target['name'],
                        $amount,
                        $charId,
                        $char['name'] ?? ''
                    );
                    
                    // 触发过期检查
                    SanhuaBounty::checkExpiredBounties();
                    
                    // 刷新角色信息
                    $char = Database::queryOne('SELECT * FROM characters WHERE id = ?', [$charId]);
                    
                    $message = '成功悬赏 ' . $target['name'] . '，赏金 ' . $amount . ' 两黄金。赏金已从你的钱庄帐户中扣除。';
                    $messageType = 'success';
                    
                    log_game('SANHUA_PAY', "{$char['name']} 悬赏 {$target['name']} {$amount}两黄金");
                }
            }
        }
    }
}

// 处理领取赏金请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'claim') {
    // 调用 claim 命令逻辑
    require_once __DIR__ . '/../commands/claim.php';
    $result = cmd_claim($charId, '');
    
    if ($result['success']) {
        $message = $result['message'];
        $messageType = 'success';
        // 刷新角色信息
        $char = Database::queryOne('SELECT * FROM characters WHERE id = ?', [$charId]);
    } else {
        $message = $result['message'];
        $messageType = 'error';
    }
}

// 获取悬赏列表
$bounties = SanhuaBounty::getAllBounties(50, 0);
$bountyCount = SanhuaBounty::getBountyCount();

// 触发过期检查
SanhuaBounty::checkExpiredBounties();

// 获取玩家携带的尸体
$carriedCorpses = Corpse::getCarriedCorpses($charId);

// 获取房间中的尸体（默认三花堂密室）
$roomCorpses = Corpse::getCorpsesInRoom('city', 'city/sanhua-mishi');

// 计算可领取的悬赏数量
$claimableCount = 0;
foreach (array_merge($carriedCorpses, $roomCorpses) as $corpse) {
    if ($corpse['owner_type'] === 'player' && $corpse['killer_id'] == $charId) {
        $bounty = SanhuaBounty::getBountyByTargetId($corpse['owner_id']);
        if ($bounty) {
            $claimableCount++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>三花堂悬赏</title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
</head>
<body>
<div class="npc-content">
    <h3 style="color:#ff69b4;">⚔️ 三花堂悬赏</h3>
    
    <?php if ($message): ?>
        <div class="message <?php echo $messageType; ?>">
            <?php echo nl2br(h($message)); ?>
        </div>
    <?php endif; ?>
    
    <div class="npc-info">
        <p>钱庄存款：<span style="color:#ffd700;"><?php echo number_format($char['balance'] ?? 0); ?> 文</span>
        （约 <?php echo floor(($char['balance'] ?? 0) / 10000); ?> 两黄金）</p>
    </div>
    
    <!-- 发起悬赏 -->
    <div class="npc-info">
        <p style="color:#ffd700;">发起悬赏：</p>
    </div>
    <div class="npc-actions">
        <form method="POST" action="">
            <input type="hidden" name="action" value="pay">
            <div>
                <input type="text" name="target_name" placeholder="输入玩家名称或ID" required autocomplete="off">
            </div>
            <div style="margin-top:10px;">
                <select name="amount" required>
                    <option value="">选择悬赏金额（两黄金）</option>
                    <option value="1">1 两</option>
                    <option value="5">5 两</option>
                    <option value="10">10 两</option>
                    <option value="20">20 两</option>
                    <option value="50">50 两</option>
                    <option value="100">100 两</option>
                    <option value="200">200 两</option>
                    <option value="500">500 两</option>
                    <option value="1000">1000 两</option>
                </select>
            </div>
            <button type="submit" class="action-btn" style="margin-top:10px;">发起悬赏</button>
        </form>
    </div>
    
    <!-- 领取赏金 -->
    <div class="npc-info">
        <p style="color:#ffd700;">领取赏金：</p>
        <?php if ($claimableCount > 0): ?>
            <p style="color:#5cb85c;">你有 <?php echo $claimableCount; ?> 具尸体可以领取赏金！</p>
        <?php endif; ?>
        <p style="color:#999;font-size:12px;">
            你背着 <?php echo count($carriedCorpses); ?> 具尸体，房间中有 <?php echo count($roomCorpses); ?> 具尸体
        </p>
    </div>
    <div class="npc-actions">
        <form method="POST" action="">
            <input type="hidden" name="action" value="claim">
            <button type="submit" class="action-btn">领取所有赏金</button>
        </form>
    </div>
    
    <!-- 悬赏名单 -->
    <div class="npc-info">
        <p style="color:#ffd700;">悬赏名单（共 <?php echo $bountyCount; ?> 个）：</p>
    </div>
    
    <?php if (empty($bounties)): ?>
        <div class="npc-info">
            <p style="color:#999;">目前没有悬赏。</p>
        </div>
    <?php else: ?>
        <div class="npc-info">
            <?php foreach ($bounties as $bounty): ?>
            <?php
            $timePassed = time() - $bounty['last_add_time'];
            $days = floor($timePassed / 86400);
            $hours = floor(($timePassed % 86400) / 3600);
            $timeText = '';
            if ($days > 0) {
                $timeText = $days . '天' . $hours . '小时前';
            } else {
                $timeText = $hours . '小时前';
            }
            ?>
            <div style="padding:5px 0;border-bottom:1px solid #333;display:flex;justify-content:space-between;">
                <span><?php echo h($bounty['target_name']); ?></span>
                <span style="color:#ffd700;"><?php echo $bounty['amount']; ?> 两</span>
            </div>
            <div style="color:#999;font-size:12px;margin-bottom:5px;">
                距上次追加：<?php echo $timeText; ?>
            </div>
            <?php endforeach; ?>
            
            <?php if ($bountyCount > 50): ?>
            <div style="text-align:center;color:#999;font-size:12px;margin-top:10px;">
                仅显示前 50 个悬赏，共 <?php echo $bountyCount; ?> 个
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <div class="npc-info" style="color:#999;font-size:12px;">
        <p>说明：</p>
        <p>• 最少悬赏 1 两黄金，最多 1000 两</p>
        <p>• 可多次追加赏金，但不能取回</p>
        <p>• 一周内无人追加赏金，将收取总额三成的保管费</p>
        <p>• 任何人可凭被悬赏玩家的尸体来领取赏金</p>
    </div>
    
    <div class="npc-actions">
        <a href="room.php">返回游戏</a>
    </div>
</div>
</body>
</html>
