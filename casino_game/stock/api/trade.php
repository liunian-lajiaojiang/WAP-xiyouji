<?php
require_once __DIR__ . '/../config.php';

// 手续费配置
define('TRADE_FEE_RATE', 0.001); // 交易手续费率 0.1%

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
$charId = getCurrentUserId();

switch ($method) {
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? '';
        $stockId = $data['stock_id'] ?? 0;
        $quantity = intval($data['quantity'] ?? 0);
        
        if ($stockId <= 0 || $quantity <= 0) {
            error('无效的股票ID或数量');
        }
        
        // 获取股票信息
        $stmt = $db->prepare("SELECT * FROM stocks WHERE id = ?");
        $stmt->execute([$stockId]);
        $stock = $stmt->fetch();
        
        if (!$stock) {
            error('股票不存在');
        }
        
        $price = floatval($stock['price']);
        $totalAmount = $price * $quantity;
        
        $db->beginTransaction();
        
        try {
            switch ($action) {
                case 'buy':
                    // 做多买入
                    $fee = round($totalAmount * TRADE_FEE_RATE, 2);
                    $totalCost = $totalAmount + $fee;
                    
                    $gold = getGoldBalance($charId);
                    if ($gold < $totalCost) {
                        throw new Exception('💸 黄金不足! 需要 ' . round($totalCost, 2) . '两黄金（含手续费 ' . $fee . '两）');
                    }
                    
                    // 扣除黄金
                    if (!deductGold($charId, $totalCost)) {
                        throw new Exception('扣除黄金失败');
                    }
                    
                    // 更新或创建持仓
                    $stmt = $db->prepare("
                        INSERT INTO positions (user_id, stock_id, type, quantity, avg_price)
                        VALUES (?, ?, 'long', ?, ?)
                        ON DUPLICATE KEY UPDATE
                        avg_price = (avg_price * quantity + ? * ?) / (quantity + ?),
                        quantity = quantity + ?
                    ");
                    $stmt->execute([$charId, $stockId, $quantity, $price, $price, $quantity, $quantity, $quantity]);
                    
                    // 记录交易
                    $stmt = $db->prepare("
                        INSERT INTO transactions (user_id, stock_id, type, quantity, price, total_amount, fee)
                        VALUES (?, ?, 'buy', ?, ?, ?, ?)
                    ");
                    $stmt->execute([$charId, $stockId, $quantity, $price, $totalAmount, $fee]);
                    
                    $db->commit();
                    success(['message' => '买入成功（手续费 ' . $fee . '两）', 'type' => '做多', 'fee' => $fee]);
                    break;
                    
                case 'sell':
                    // 卖出做多持仓
                    $stmt = $db->prepare("
                        SELECT * FROM positions 
                        WHERE user_id = ? AND stock_id = ? AND type = 'long' FOR UPDATE
                    ");
                    $stmt->execute([$charId, $stockId]);
                    $position = $stmt->fetch();
                    
                    if (!$position || $position['quantity'] < $quantity) {
                        throw new Exception('持仓不足');
                    }
                    
                    $profitLoss = ($price - $position['avg_price']) * $quantity;
                    $totalAmount = $price * $quantity;
                    $fee = round($totalAmount * TRADE_FEE_RATE, 2);
                    $netAmount = $totalAmount - $fee;
                    
                    // 添加黄金
                    addGold($charId, $netAmount);
                    
                    // 更新持仓
                    if ($position['quantity'] == $quantity) {
                        $stmt = $db->prepare("DELETE FROM positions WHERE id = ?");
                        $stmt->execute([$position['id']]);
                    } else {
                        $stmt = $db->prepare("UPDATE positions SET quantity = quantity - ? WHERE id = ?");
                        $stmt->execute([$quantity, $position['id']]);
                    }
                    
                    $stmt = $db->prepare("
                        INSERT INTO transactions (user_id, stock_id, type, quantity, price, total_amount, profit_loss, fee)
                        VALUES (?, ?, 'sell', ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$charId, $stockId, $quantity, $price, $totalAmount, $profitLoss, $fee]);
                    
                    $db->commit();
                    success(['message' => '卖出成功（手续费 ' . $fee . '两）', 'profit_loss' => round($profitLoss, 2), 'fee' => $fee]);
                    break;
                    
                case 'short':
                    // 做空（50%保证金）
                    $margin = $totalAmount * 0.5;
                    $fee = round($totalAmount * TRADE_FEE_RATE, 2);
                    $totalCost = $margin + $fee;
                    
                    $gold = getGoldBalance($charId);
                    if ($gold < $totalCost) {
                        throw new Exception('💸 黄金不足! 需要50%保证金 + 手续费 ' . $fee . '两');
                    }
                    
                    if (!deductGold($charId, $totalCost)) {
                        throw new Exception('扣除黄金失败');
                    }
                    
                    $stmt = $db->prepare("
                        INSERT INTO positions (user_id, stock_id, type, quantity, avg_price)
                        VALUES (?, ?, 'short', ?, ?)
                        ON DUPLICATE KEY UPDATE
                        avg_price = (avg_price * quantity + ? * ?) / (quantity + ?),
                        quantity = quantity + ?
                    ");
                    $stmt->execute([$charId, $stockId, $quantity, $price, $price, $quantity, $quantity, $quantity]);
                    
                    $stmt = $db->prepare("
                        INSERT INTO transactions (user_id, stock_id, type, quantity, price, total_amount, fee)
                        VALUES (?, ?, 'short', ?, ?, ?, ?)
                    ");
                    $stmt->execute([$charId, $stockId, $quantity, $price, $totalAmount, $fee]);
                    
                    $db->commit();
                    success(['message' => '做空成功（手续费 ' . $fee . '两）', 'type' => '做空', 'margin' => round($margin, 2), 'fee' => $fee]);
                    break;
                    
                case 'cover':
                    // 平仓（买回股票归还）
                    $stmt = $db->prepare("
                        SELECT * FROM positions 
                        WHERE user_id = ? AND stock_id = ? AND type = 'short' FOR UPDATE
                    ");
                    $stmt->execute([$charId, $stockId]);
                    $position = $stmt->fetch();
                    
                    if (!$position || $position['quantity'] < $quantity) {
                        throw new Exception('做空持仓不足');
                    }
                    
                    $profitLoss = ($position['avg_price'] - $price) * $quantity;
                    $coverAmount = $price * $quantity;
                    $fee = round($coverAmount * TRADE_FEE_RATE, 2);
                    $originalMargin = $position['avg_price'] * $quantity * 0.5;
                    
                    // 返还保证金 + 盈亏 - 手续费
                    $returnAmount = $originalMargin + $profitLoss - $fee;
                    addGold($charId, $returnAmount);
                    
                    if ($position['quantity'] == $quantity) {
                        $stmt = $db->prepare("DELETE FROM positions WHERE id = ?");
                        $stmt->execute([$position['id']]);
                    } else {
                        $stmt = $db->prepare("UPDATE positions SET quantity = quantity - ? WHERE id = ?");
                        $stmt->execute([$quantity, $position['id']]);
                    }
                    
                    $stmt = $db->prepare("
                        INSERT INTO transactions (user_id, stock_id, type, quantity, price, total_amount, profit_loss, fee)
                        VALUES (?, ?, 'cover', ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$charId, $stockId, $quantity, $price, $coverAmount, $profitLoss, $fee]);
                    
                    $db->commit();
                    success(['message' => '平仓成功（手续费 ' . $fee . '两）', 'profit_loss' => round($profitLoss, 2), 'fee' => $fee]);
                    break;
                    
                default:
                    throw new Exception('未知的交易类型');
            }
        } catch (Exception $e) {
            $db->rollBack();
            error($e->getMessage());
        }
        break;
        
    default:
        error('不支持的请求方法', 405);
}
