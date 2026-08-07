<?php
require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

switch ($method) {
    case 'GET':
        $charId = getCurrentUserId();
        
        // 获取黄金余额
        $gold = getGoldBalance($charId);

        // 计算总资产
        $stmt = $db->prepare("
            SELECT SUM(p.quantity * s.price) as long_value
            FROM positions p
            JOIN stocks s ON p.stock_id = s.id
            WHERE p.user_id = ? AND p.type = 'long'
        ");
        $stmt->execute([$charId]);
        $longValue = floatval($stmt->fetch()['long_value'] ?? 0);

        // 做空盈亏计算
        $stmt = $db->prepare("
            SELECT p.*, s.price as current_price
            FROM positions p
            JOIN stocks s ON p.stock_id = s.id
            WHERE p.user_id = ? AND p.type = 'short'
        ");
        $stmt->execute([$charId]);
        $shortPositions = $stmt->fetchAll();

        $shortValue = 0;
        foreach ($shortPositions as $pos) {
            $shortValue += ($pos['avg_price'] - $pos['current_price']) * $pos['quantity'];
        }

        $user = [
            'id' => $charId,
            'balance' => round($gold, 2),
            'total_assets' => round($gold + $longValue + $shortValue, 2)
        ];
        success(['user' => $user]);
        break;
        
    default:
        error('不支持的请求方法', 405);
}
