<?php
require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
$charId = getCurrentUserId();

switch ($method) {
    case 'GET':
        $stmt = $db->prepare("
            SELECT 
                p.id,
                p.type,
                p.quantity,
                p.avg_price,
                p.created_at,
                s.id as stock_id,
                s.symbol,
                s.name,
                s.price as current_price,
                CASE 
                    WHEN p.type = 'long' THEN ROUND((s.price - p.avg_price) * p.quantity, 2)
                    ELSE ROUND((p.avg_price - s.price) * p.quantity, 2)
                END as unrealized_pnl,
                CASE 
                    WHEN p.type = 'long' THEN ROUND((s.price - p.avg_price) / p.avg_price * 100, 2)
                    ELSE ROUND((p.avg_price - s.price) / p.avg_price * 100, 2)
                END as pnl_percent
            FROM positions p
            JOIN stocks s ON p.stock_id = s.id
            WHERE p.user_id = ?
            ORDER BY p.type, s.symbol
        ");
        $stmt->execute([$charId]);
        $positions = $stmt->fetchAll();
        
        $longPositions = [];
        $shortPositions = [];
        
        foreach ($positions as $pos) {
            if ($pos['type'] === 'long') {
                $longPositions[] = $pos;
            } else {
                $shortPositions[] = $pos;
            }
        }
        
        success([
            'positions' => $positions,
            'long' => $longPositions,
            'short' => $shortPositions,
            'summary' => [
                'long_count' => count($longPositions),
                'short_count' => count($shortPositions),
                'total_long_pnl' => array_sum(array_column($longPositions, 'unrealized_pnl')),
                'total_short_pnl' => array_sum(array_column($shortPositions, 'unrealized_pnl'))
            ]
        ]);
        break;
        
    default:
        error('不支持的请求方法', 405);
}
