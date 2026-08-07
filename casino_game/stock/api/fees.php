<?php
require_once __DIR__ . '/../config.php';

// 持仓费率（每分钟万分之一）
define('POSITION_FEE_RATE', 0.0001);

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

switch ($method) {
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? '';
        
        if ($action === 'charge_position_fees') {
            $result = chargePositionFees($db);
            success($result);
        } else {
            error('无效的操作');
        }
        break;
        
    case 'GET':
        $charId = getCurrentUserId();
        $limit = intval($_GET['limit'] ?? 50);
        
        $stmt = $db->prepare("
            SELECT pf.*, s.symbol, s.name
            FROM position_fees pf
            JOIN stocks s ON pf.stock_id = s.id
            WHERE pf.user_id = ?
            ORDER BY pf.created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$charId]);
        $fees = $stmt->fetchAll();
        
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(fee_amount), 0) as total_fees
            FROM position_fees
            WHERE user_id = ? AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute([$charId]);
        $todayPositionFees = floatval($stmt->fetch()['total_fees']);
        
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(fee), 0) as total_fees
            FROM transactions
            WHERE user_id = ? AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute([$charId]);
        $todayTradeFees = floatval($stmt->fetch()['total_fees']);
        
        $stmt = $db->prepare("SELECT COALESCE(SUM(fee_amount), 0) as total_fees FROM position_fees WHERE user_id = ?");
        $stmt->execute([$charId]);
        $totalPositionFees = floatval($stmt->fetch()['total_fees']);
        
        $stmt = $db->prepare("SELECT COALESCE(SUM(fee), 0) as total_fees FROM transactions WHERE user_id = ?");
        $stmt->execute([$charId]);
        $totalTradeFees = floatval($stmt->fetch()['total_fees']);
        
        success([
            'fees' => $fees,
            'today_position_fees' => round($todayPositionFees, 2),
            'today_trade_fees' => round($todayTradeFees, 2),
            'today_total_fees' => round($todayPositionFees + $todayTradeFees, 2),
            'total_position_fees' => round($totalPositionFees, 2),
            'total_trade_fees' => round($totalTradeFees, 2),
            'total_fees' => round($totalPositionFees + $totalTradeFees, 2)
        ]);
        break;
        
    default:
        error('不支持的请求方法', 405);
}

// 收取持仓手续费
function chargePositionFees($db) {
    $totalCharged = 0;
    $affectedPositions = 0;
    
    $db->beginTransaction();
    
    try {
        $stmt = $db->query("
            SELECT p.id, p.user_id, p.stock_id, p.type, p.quantity, s.price
            FROM positions p
            JOIN stocks s ON p.stock_id = s.id
            FOR UPDATE
        ");
        $positions = $stmt->fetchAll();
        
        foreach ($positions as $position) {
            $positionValue = $position['quantity'] * $position['price'];
            $fee = round($positionValue * POSITION_FEE_RATE, 2);
            
            $gold = getGoldBalance($position['user_id']);
            if ($gold >= $fee) {
                deductGold($position['user_id'], $fee);
                
                $stmt = $db->prepare("
                    INSERT INTO position_fees (user_id, stock_id, position_type, position_value, fee_amount, fee_rate)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $position['user_id'], $position['stock_id'], $position['type'],
                    $positionValue, $fee, POSITION_FEE_RATE
                ]);
                
                $totalCharged += $fee;
                $affectedPositions++;
            }
        }
        
        $db->commit();
        
        return [
            'message' => '持仓手续费收取完成',
            'positions_count' => $affectedPositions,
            'total_fees' => round($totalCharged, 2)
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        error('收取手续费失败: ' . $e->getMessage());
    }
}
