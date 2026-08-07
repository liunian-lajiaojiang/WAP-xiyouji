<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 赌大小游戏 - 整合版主游戏
 * 使用角色铜钱(coin)作为赌注货币（从背包表操作）
 */

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once HELPER_PATH . 'MoneyHelper.php';

// 游戏常量
define('ODDS', 2);               // 赔率 1:1（赢=下注×2）
define('COMMISSION_RATE', 0.05); // 赢钱手续费 5%

// 要求登录
require_login();
$charId = get_char_id();
$char = CharacterModel::getFullInfo($charId);
if (!$char) {
    redirect('character_select.php');
}

$error = '';
$success = '';
$result = null;

/**
 * 掷骰子（1-6）
 */
function rollDice(): int {
    return mt_rand(1, 6);
}

/**
 * 判断大小
 */
function getDiceCategory(int $dice): string {
    return $dice >= 4 ? '大' : '小';
}

/**
 * 获取角色铜钱余额
 */
function getCoinBalance(int $charId): int {
    $money = MoneyHelper::getMoneyInventory($charId);
    return intval($money['coin']);
}

/**
 * 扣除铜钱
 */
function deductCoin(int $charId, int $amount): bool {
    return MoneyHelper::deductMoney($charId, $amount);
}

/**
 * 添加铜钱
 */
function addCoin(int $charId, int $amount): void {
    MoneyHelper::addMoney($charId, $amount);
}

/**
 * 记录下注历史
 */
function recordBet(int $charId, int $betAmount, string $choice, int $dice, bool $isWin, int $winAmount, int $commission, int $coinAfter): void {
    Database::execute(
        "INSERT INTO dudaxiao_history (char_id, bet_amount, bet_choice, dice_result, is_win, win_amount, commission, gold_after) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        [$charId, $betAmount, $choice, $dice, $isWin ? 1 : 0, $winAmount, $commission, $coinAfter]
    );
}

/**
 * 获取下注历史
 */
function getBetHistory(int $charId, int $limit = 10): array {
    return Database::queryAll(
        "SELECT * FROM dudaxiao_history WHERE char_id = ? ORDER BY created_at DESC LIMIT {$limit}",
        [$charId]
    );
}

// 处理下注请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bet'])) {
    $betAmount = intval($_POST['bet_amount'] ?? 0);
    $betChoice = $_POST['bet_choice'] ?? '';
    $coinBalance = getCoinBalance($charId);

    if ($betAmount <= 0) {
        $error = '请输入有效的下注金额';
    } elseif ($betAmount > $coinBalance) {
        $error = '铜钱不足，当前余额: ' . $coinBalance . ' 文';
    } elseif (!in_array($betChoice, ['大', '小'])) {
        $error = '请选择大或小';
    } else {
        // 扣除赌注
        deductCoin($charId, $betAmount);

        // 掷骰子
        $diceResult = rollDice();
        $diceCategory = getDiceCategory($diceResult);
        $isWin = ($betChoice === $diceCategory);

        // 计算输赢
        if ($isWin) {
            $winAmount = $betAmount * ODDS;
            $commission = (int)($winAmount * COMMISSION_RATE);
            $netWin = $winAmount - $commission;
            addCoin($charId, $netWin);
        } else {
            $winAmount = 0;
            $commission = 0;
            $netWin = 0;
        }

        // 获取结算后余额
        $newCoin = getCoinBalance($charId);

        // 记录历史
        recordBet($charId, $betAmount, $betChoice, $diceResult, $isWin, $winAmount, $commission, $newCoin);

        // 结果消息
        if ($isWin) {
            $success = "🎲 掷出: <strong>{$diceResult}点({$diceCategory})</strong><br>" .
                "✅ 恭喜! 您押了【{$betChoice}】，中了!<br>" .
                "赢得: {$winAmount}文铜钱 | 手续费: {$commission}文<br>" .
                "实际获得: <strong>+{$netWin}文铜钱</strong>";
        } else {
            $error = "🎲 掷出: <strong>{$diceResult}点({$diceCategory})</strong><br>" .
                "❌ 可惜! 您押了【{$betChoice}】，输了<br>" .
                "-{$betAmount}文铜钱";
        }

        $result = [
            'dice' => $diceResult,
            'category' => $diceCategory,
            'choice' => $betChoice,
            'is_win' => $isWin
        ];
    }
}

// 获取最新余额和历史
$coinBalance = getCoinBalance($charId);
$history = getBetHistory($charId, 10);
?>
<!DOCTYPE html>
<html lang="zh-cn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>赌大小_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
</head>
<body>
<p>
    <a href="javascript:location.reload();">🎲赌大小</a>&ensp;
    <a href="../functions/room.php">返回游戏</a>&ensp;
    <a href="dudaxiao_rule.php">规则</a>&ensp;
    <a href="dudaxiao_history.php">下注历史</a>
</p>
<p>💰铜钱: <span id="coin"><?= $coinBalance ?>文</span></p>
<p id="countdown" style="color:#dc3545;font-weight:bold;display:none;"></p>

<?php if ($success): ?>
    <p style="color:#00FF00;"><?= $success ?></p>
<?php endif; ?>

<?php if ($error): ?>
    <p style="color:#FF6666;"><?= $error ?></p>
<?php endif; ?>

<form method="POST" id="betForm">
    <input type="hidden" name="bet" value="1">
    <br>
    <span style="font-weight: bold;">选择押注</span>
    <br>
    <label><input type="radio" name="bet_choice" value="大" required>大(4-6点)</label>
    <label><input type="radio" name="bet_choice" value="小">小(1-3点)</label>
    <br><br>
    <span style="font-weight: bold;">下注金额(铜钱)</span>
    <br>
    <button type="button" onclick="setAmount(100)">100</button>
    <button type="button" onclick="setAmount(500)">500</button>
    <button type="button" onclick="setAmount(1000)">1000</button>
    <button type="button" onclick="setAmount(<?= max(1, $coinBalance) ?>)">全押</button>
    <br><br>
    <input type="number" name="bet_amount" id="betAmount"
        placeholder="输入铜钱数" min="1" max="<?= $coinBalance ?>" required>
    <button type="submit" id="betBtn" <?= $coinBalance <= 0 ? 'disabled' : '' ?>>
        🎲 开始下注
    </button>
    <p>赔率 1:1 | 手续费 <?= (COMMISSION_RATE * 100) ?>%</p>
</form>

<?php if (!empty($history)): ?>
<br>
<h4>最近下注</h4>
<table border="1" style="width:100%;font-size:12px;">
    <tr><th>时间</th><th>押</th><th>骰子</th><th>结果</th><th>盈亏</th></tr>
    <?php foreach (array_slice($history, 0, 5) as $r): ?>
    <tr>
        <td><?= date('H:i', strtotime($r['created_at'])) ?></td>
        <td><?= h($r['bet_choice']) ?></td>
        <td><?= $r['dice_result'] ?>点</td>
        <td><?= $r['is_win'] ? '赢' : '输' ?></td>
        <td style="color:<?= $r['is_win'] ? '#00FF00' : '#FF6666' ?>">
            <?= $r['is_win'] ? '+' . ($r['win_amount'] - $r['commission']) : '-' . $r['bet_amount'] ?>文
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<br>
<script>
const WAIT_TIME = 8;
function setAmount(amount) {
    document.getElementById('betAmount').value = amount;
}
document.getElementById('betForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const betBtn = document.getElementById('betBtn');
    betBtn.disabled = true;
    betBtn.textContent = '🎲 等待开骰子...';
    startCountdown(WAIT_TIME);
});
function startCountdown(seconds) {
    const countdownEl = document.getElementById('countdown');
    countdownEl.style.display = 'block';
    updateCountdown(seconds);
}
function updateCountdown(remaining) {
    const countdownEl = document.getElementById('countdown');
    if (remaining > 0) {
        countdownEl.innerHTML = '🎲 <strong style="font-size:1.2em;">' + remaining + '</strong> 秒后开骰子...';
        setTimeout(() => updateCountdown(remaining - 1), 1000);
    } else {
        countdownEl.textContent = '🎲 结果揭晓中...';
        setTimeout(() => document.getElementById('betForm').submit(), 500);
    }
}
</script>
<br>
<a href="#" onclick="javascript:history.back(-1);">返回</a>
<hr>
<a href="../functions/room.php">返回游戏</a>
</body>
</html>
