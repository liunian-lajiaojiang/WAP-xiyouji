<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

// 检查是否登录
if (!isset($_SESSION['char_id']) || empty($_SESSION['char_id'])) {
    header('Location: login.php');
    exit;
}

// 加载项目配置和模型
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Character.php';

// 获取用户信息
$userId = $_SESSION['user_id'];
$charId = $_SESSION['char_id'];
$user = UserModel::find($userId);
$char = CharacterModel::find($charId);
$username = $user['username'] ?? '';
$charName = $char['name'] ?? '';

// 获取统计数据（基于全部交易记录）
$pdo = Database::getInstance();

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM trades WHERE char_id = ? AND status IN ('win', 'lose')");
$stmt->execute([$charId]);
$totalCount = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT SUM(profit) as total_profit, SUM(amount) as total_amount, SUM(CASE WHEN status = 'win' THEN profit ELSE 0 END) as total_win, SUM(CASE WHEN status = 'lose' THEN profit ELSE 0 END) as total_lose, SUM(CASE WHEN status = 'win' THEN 1 ELSE 0 END) as win_count, SUM(CASE WHEN status = 'lose' THEN 1 ELSE 0 END) as lose_count FROM trades WHERE char_id = ? AND status IN ('win', 'lose')");
$stmt->execute([$charId]);
$stats = $stmt->fetch();

$totalProfit = $stats['total_profit'] ?? 0;
$totalAmount = $stats['total_amount'] ?? 0;
$totalWin = $stats['total_win'] ?? 0;
$totalLose = $stats['total_lose'] ?? 0;
$winCount = $stats['win_count'] ?? 0;
$loseCount = $stats['lose_count'] ?? 0;
$winRate = $totalCount > 0 ? round(($winCount / $totalCount) * 100, 1) : 0;

// 获取历史交易列表（分页显示）
$stmt = $pdo->prepare("SELECT * FROM trades WHERE char_id = ? AND status IN ('win', 'lose') ORDER BY created_at DESC");
$stmt->execute([$charId]);
$trades = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>交易历史</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            background-color: #1a202c;
            color: #ffffff;
        }
        .container {
            background-color: #1a202c;
        }
        .header {
            display: flex;
            padding: 10px;
        }
        .back-arrow {
            font-size: 22px;
            color: #ffffff;
            text-decoration: none;
        }
        .title {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            font-size: 20px;
            font-weight: 600;
        }
        .total-profit-section {
            padding: 5px 10px;
            border-bottom: 1px solid #2d3748;
        }
        .total-profit-label {
            color: #e2e8f0;
            margin-bottom: 10px;
        }
        .total-profit-value {
            font-size: 32px;
            font-weight: 700;
            display: flex;
            align-items: baseline;
            color: <?php echo $totalProfit >= 0 ? '#0ECB81' : '#F6465D'; ?>;
        }
        .total-profit-unit {
            margin-left: 8px;
            color: #cbd5e0;
        }
        .tab-bar {
            display: flex;
            padding: 15px;
            gap: 15px;
        }
        .tab-item {
            color: #a0aec0;
            padding: 4px 6px;
            border-radius: 8px;
        }
        .tab-item.active {
            background-color: #2d3748;
            color: #ffffff;
        }
        .note {
            padding: 0 10px 10px;
            font-size: 14px;
            color: #a0aec0;
        }
        .stats-section {
            padding: 0 10px 10px;
            border-bottom: 1px solid #2d3748;
        }
        .stat-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
        }
        .stat-label {
            color: #a0aec0;
        }
        .stat-value {
            color: #ffffff;
        }
        .no-record-section {
            padding: 40px 20px;
            text-align: center;
        }
        .no-record-icon {
            width: 120px;
            height: 120px;
            margin: 0 auto 20px;
            opacity: 0.6;
        }
        .no-record-text {
            font-size: 18px;
            color: #a0aec0;
        }
        .closed-position-title {
            padding: 15px 10px 10px;
            font-size: 20px;
            font-weight: 600;
        }
        .trade-item {
            padding: 13px;
            border-bottom: 1px solid #2d3748;
        }
        .trade-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .trade-direction {
            gap: 8px;
        }
        .direction-up {
            color: #0ECB81;
        }
        .direction-down {
            color: #F6465D;
        }
        .trade-pair {
            font-size: 18px;
            font-weight: 600;
        }
        .trade-amount {
            font-size: 18px;
            font-weight: 600;
        }
        .trade-details {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
        }
        .trade-detail {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .detail-label {
            color: #a0aec0;
        }
        .detail-value {
            color: #ffffff;
        }
        .profit-win {
            color: #0ECB81;
        }
        .profit-lose {
            color: #F6465D;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- 顶部标题栏 -->
        <div class="header">
            <a href="crypto.php" class="back-arrow">←</a>
            <div class="title">交易历史</div>
        </div>

        <!-- 总盈亏区域 -->
        <div class="total-profit-section">
            <div class="total-profit-label">总盈亏</div>
            <div class="total-profit-value" id="total-profit">
                <?php echo $totalProfit >= 0 ? '+' : ''; ?><?php echo number_format($totalProfit); ?>
                <span class="total-profit-unit">Gold</span>
            </div>
        </div>

        <!-- 时间标签栏 -->
        <div class="tab-bar">
            <div class="tab-item active" data-period="today">今天</div>
            <div class="tab-item" data-period="yesterday">昨天</div>
            <div class="tab-item" data-period="month">1个月</div>
            <div class="tab-item" data-period="all">全部</div>
        </div>

        <!-- 备注说明 -->
        <div class="note">
            * 本版块的所有数据均按照 UTC+0 时区计算。
        </div>

        <!-- 统计数据区域 -->
        <div class="stats-section">
            <div class="stat-row">
                <div class="stat-label">合约张数</div>
                <div class="stat-value" id="stat-count"><?php echo $totalCount; ?></div>
            </div>
            <div class="stat-row">
                <div class="stat-label">胜率</div>
                <div class="stat-value" id="stat-winrate"><?php echo $winRate; ?>%</div>
            </div>
            <div class="stat-row">
                <div class="stat-label">合约金额</div>
                <div class="stat-value" id="stat-amount"><?php echo number_format($totalAmount); ?> Gold</div>
            </div>
            <div class="stat-row">
                <div class="stat-label">总收益</div>
                <div class="stat-value profit-win" id="stat-win"><?php echo number_format($totalWin); ?> Gold</div>
            </div>
            <div class="stat-row">
                <div class="stat-label">总亏损</div>
                <div class="stat-value profit-lose" id="stat-lose"><?php echo number_format($totalLose); ?> Gold</div>
            </div>
        </div>

        <!-- 已平仓标题 -->
        <div class="closed-position-title">已平仓</div>

        <?php if (empty($trades)): ?>
            <!-- 暂无记录区域 -->
            <div class="no-record-section">
                <svg class="no-record-icon" viewBox="0 0 24 24" fill="none" stroke="#a0aec0" stroke-width="1">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                    <polyline points="10 9 9 9 8 9"></polyline>
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="11" y1="8" x2="11" y2="12"></line>
                    <line x1="11" y1="16" x2="11.01" y2="16"></line>
                </svg>
                <div class="no-record-text">暂无记录</div>
            </div>
        <?php else: ?>
            <!-- 交易记录列表 -->
            <?php foreach ($trades as $trade): ?>
                    <div class="trade-item" data-date="<?php echo date('Y-m-d', strtotime($trade['created_at'])); ?>" data-profit="<?php echo $trade['profit']; ?>" data-amount="<?php echo $trade['amount']; ?>" data-status="<?php echo $trade['status']; ?>">
                        <div class="trade-header">
                            <div class="trade-direction">
                                <span class="direction-<?php echo $trade['direction']; ?>">
                                    <?php echo $trade['direction'] === 'up' ? '↗' : '↘'; ?>
                                </span>
                                <span class="trade-pair"><?php echo htmlspecialchars($trade['pair']); ?>/USDT</span>
                            </div>
                            <div class="trade-amount <?php echo $trade['status'] === 'win' ? 'profit-win' : ''; ?>">
                                <?php if ($trade['status'] === 'win'): ?>
                                    +<?php echo number_format($trade['amount'] + $trade['profit']); ?> Gold
                                <?php else: ?>
                                    0 Gold
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="trade-details">
                            <p class="trade-detail">
                                <span class="detail-label">金额(Gold)</span>
                                <span class="detail-value"><?php echo number_format($trade['amount']); ?></span>
                            </p>
                            <p class="trade-detail">
                                <span class="detail-label">开盘价</span>
                                <span class="detail-value"><?php echo number_format($trade['open_price'], 2); ?></span>
                            </p>
                            <p class="trade-detail">
                                <span class="detail-label">开盘时间</span>
                                <span class="detail-value"><?php echo date('m-d H:i:s', strtotime($trade['created_at'])); ?></span>
                            </p>
                            <p class="trade-detail">
                                <span class="detail-label">支付比率</span>
                                <span class="detail-value" style="color: #F6465D;">80%</span>
                            </p>
                            <p class="trade-detail">
                                <span class="detail-label">平仓价</span>
                                <span class="detail-value"><?php echo number_format($trade['close_price'], 2); ?></span>
                            </p>
                            <p class="trade-detail">
                                <span class="detail-label">收盘时间</span>
                                <span class="detail-value"><?php echo $trade['settled_at'] ? date('m-d H:i:s', strtotime($trade['settled_at'])) : '--'; ?></span>
                            </p>
                        </div>
                    </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        function numberFormat(n) {
            return Number(n).toLocaleString('en-US');
        }

        function updateStats(visibleItems) {
            let count = 0, winCount = 0, loseCount = 0;
            let totalProfit = 0, totalAmount = 0, totalWin = 0, totalLose = 0;
            visibleItems.forEach(item => {
                const profit = parseFloat(item.dataset.profit) || 0;
                const amount = parseFloat(item.dataset.amount) || 0;
                const status = item.dataset.status;
                count++; totalAmount += amount; totalProfit += profit;
                if (status === 'win') { winCount++; totalWin += profit; }
                else { loseCount++; totalLose += profit; }
            });
            const winRate = count > 0 ? Math.round((winCount / count) * 1000) / 10 : 0;
            document.getElementById('stat-count').textContent = count;
            document.getElementById('stat-winrate').textContent = winRate + '%';
            document.getElementById('stat-amount').textContent = numberFormat(totalAmount) + ' Gold';
            document.getElementById('stat-win').textContent = numberFormat(totalWin) + ' Gold';
            document.getElementById('stat-lose').textContent = numberFormat(totalLose) + ' Gold';
            const profitEl = document.getElementById('total-profit');
            const prefix = totalProfit >= 0 ? '+' : '';
            profitEl.innerHTML = prefix + numberFormat(totalProfit) + '<span class="total-profit-unit">Gold</span>'; profitEl.style.color = totalProfit >= 0 ? '#0ECB81' : '#F6465D';
        }

        document.addEventListener('DOMContentLoaded', function() {
            const tabItems = document.querySelectorAll('.tab-item');
            const tradeItems = document.querySelectorAll('.trade-item');
            const today = '<?php echo date('Y-m-d'); ?>';
            const yesterday = '<?php echo date('Y-m-d', strtotime('-1 day')); ?>';
            const monthAgo = '<?php echo date('Y-m-d', strtotime('-30 day')); ?>';

            // 默认显示今天
            const initialVisible = [];
            tradeItems.forEach(item => {
                if (item.dataset.date === today) {
                    initialVisible.push(item);
                } else {
                    item.style.display = 'none';
                }
            });
            updateStats(initialVisible);

            tabItems.forEach(tab => {
                tab.addEventListener('click', function() {
                    tabItems.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    const period = this.dataset.period;
                    const visible = [];
                    tradeItems.forEach(item => {
                        const itemDate = item.dataset.date;
                        let shouldShow = false;
                        switch (period) {
                            case 'all': shouldShow = true; break;
                            case 'today': shouldShow = itemDate === today; break;
                            case 'yesterday': shouldShow = itemDate === yesterday; break;
                            case 'month': shouldShow = itemDate >= monthAgo; break;
                        }
                        item.style.display = shouldShow ? 'block' : 'none';
                        if (shouldShow) visible.push(item);
                    });
                    updateStats(visible);
                });
            });
        });
    </script>
</body>
</html>
