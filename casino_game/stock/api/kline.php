<?php
require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

switch ($method) {
    case 'GET':
        $stockId = intval($_GET['stock_id'] ?? 0);
        $period = $_GET['period'] ?? '1min';
        $limit = intval($_GET['limit'] ?? 100);
        
        if ($stockId <= 0) {
            error('无效的股票ID');
        }
        
        // 尝试获取指定周期的数据
        $stmt = $db->prepare("
            SELECT 
                UNIX_TIMESTAMP(created_at) * 1000 as timestamp,
                open_price as open,
                high_price as high,
                low_price as low,
                close_price as close,
                volume
            FROM kline_data
            WHERE stock_id = ? AND period = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$stockId, $period]);
        $kline = $stmt->fetchAll();
        
        // 如果指定周期没有数据，从1分钟数据聚合
        if (count($kline) < 10 && $period !== '1min') {
            $kline = aggregateKline($db, $stockId, $period, $limit);
        } else {
            // 倒序后取limit条
            $kline = array_slice(array_reverse($kline), 0, $limit);
        }
        
        // 如果还是没有数据，生成模拟数据
        if (count($kline) < 10) {
            $kline = generateSimulatedKline($db, $stockId, $period, $limit);
        }
        
        // 计算技术指标
        $indicators = calculateIndicators($kline);
        
        success([
            'kline' => $kline,
            'indicators' => $indicators
        ]);
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (isset($data['generate_history'])) {
            $stockId = intval($data['stock_id'] ?? 0);
            $days = intval($data['days'] ?? 7);
            
            if ($stockId <= 0) {
                error('无效的股票ID');
            }
            
            try {
                $stmt = $db->prepare("CALL GenerateHistoryKline(?, ?)");
                $stmt->execute([$stockId, $days]);
                success(['message' => "已生成 {$days} 天的历史数据"]);
            } catch (PDOException $e) {
                // 如果存储过程不存在，手动生成
                generateKlineManually($db, $stockId, $days);
                success(['message' => "已生成 {$days} 天的历史数据"]);
            }
        } else {
            error('无效的操作');
        }
        break;
        
    default:
        error('不支持的请求方法', 405);
}

// 从1分钟数据聚合其他周期
function aggregateKline($db, $stockId, $period, $limit) {
    // 获取足够的1分钟数据
    $minutes = getPeriodMinutes($period) * $limit;
    $stmt = $db->prepare("
        SELECT 
            created_at,
            open_price,
            high_price,
            low_price,
            close_price,
            volume
        FROM kline_data
        WHERE stock_id = ? AND period = '1min'
        ORDER BY created_at DESC
        LIMIT {$minutes}
    ");
    $stmt->execute([$stockId]);
    $data = array_reverse($stmt->fetchAll());
    
    if (count($data) < getPeriodMinutes($period)) {
        return [];
    }
    
    // 按周期分组聚合
    $interval = getPeriodMinutes($period);
    $aggregated = [];
    $group = [];
    $groupTime = 0;
    
    foreach ($data as $item) {
        $itemTime = strtotime($item['created_at']);
        $bucket = floor($itemTime / ($interval * 60)) * ($interval * 60);
        
        if ($bucket !== $groupTime) {
            if (!empty($group)) {
                $aggregated[] = createAggregatedCandle($group, $groupTime);
            }
            $group = [];
            $groupTime = $bucket;
        }
        $group[] = $item;
    }
    
    if (!empty($group)) {
        $aggregated[] = createAggregatedCandle($group, $groupTime);
    }
    
    // 只保留需要的数量
    return array_slice($aggregated, -$limit);
}

function createAggregatedCandle($group, $timestamp) {
    return [
        'timestamp' => $timestamp * 1000,
        'open' => floatval($group[0]['open_price']),
        'high' => floatval(max(array_column($group, 'high_price'))),
        'low' => floatval(min(array_column($group, 'low_price'))),
        'close' => floatval(end($group)['close_price']),
        'volume' => array_sum(array_column($group, 'volume'))
    ];
}

function getPeriodMinutes($period) {
    switch ($period) {
        case '5min': return 5;
        case '15min': return 15;
        case '30min': return 30;
        case '1h': return 60;
        case '1d': return 1440;
        default: return 1;
    }
}

// 生成模拟K线数据
function generateSimulatedKline($db, $stockId, $period, $limit) {
    // 获取股票当前价格
    $stmt = $db->prepare("SELECT price, volatility FROM stocks WHERE id = ?");
    $stmt->execute([$stockId]);
    $stock = $stmt->fetch();
    
    if (!$stock) {
        return [];
    }
    
    $basePrice = floatval($stock['price']);
    $volatility = floatval($stock['volatility']) * 10;
    $interval = getPeriodMinutes($period);
    $now = time();
    
    $kline = [];
    $price = $basePrice;
    
    for ($i = $limit - 1; $i >= 0; $i--) {
        $timestamp = $now - ($i * $interval * 60);
        $change = (rand(-100, 100) / 100) * $volatility;
        $open = $price;
        $close = $price * (1 + $change);
        $high = max($open, $close) * (1 + rand(0, 50) / 1000);
        $low = min($open, $close) * (1 - rand(0, 50) / 1000);
        $volume = rand(1000, 10000);
        
        $kline[] = [
            'timestamp' => $timestamp * 1000,
            'open' => round($open, 2),
            'high' => round($high, 2),
            'low' => round($low, 2),
            'close' => round($close, 2),
            'volume' => $volume
        ];
        
        $price = $close;
    }
    
    return $kline;
}

// 手动生成K线数据
function generateKlineManually($db, $stockId, $days) {
    $stmt = $db->prepare("SELECT price, volatility FROM stocks WHERE id = ?");
    $stmt->execute([$stockId]);
    $stock = $stmt->fetch();
    
    if (!$stock) return;
    
    $basePrice = floatval($stock['price']);
    $volatility = floatval($stock['volatility']);
    $price = $basePrice;
    
    // 生成每分钟数据
    for ($i = 0; $i < $days * 24 * 60; $i++) {
        $timestamp = date('Y-m-d H:i:s', time() - ($days * 24 * 60 * 60) + ($i * 60));
        $change = (rand(-100, 100) / 100) * $volatility;
        $open = $price;
        $close = $price * (1 + $change);
        $high = max($open, $close) * (1 + rand(0, 30) / 1000);
        $low = min($open, $close) * (1 - rand(0, 30) / 1000);
        
        $stmt = $db->prepare("
            INSERT INTO kline_data (stock_id, period, open_price, high_price, low_price, close_price, volume, created_at)
            VALUES (?, '1min', ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$stockId, $open, $high, $low, $close, rand(1000, 10000), $timestamp]);
        
        $price = $close;
    }
    
    // 更新股票价格
    $stmt = $db->prepare("UPDATE stocks SET price = ? WHERE id = ?");
    $stmt->execute([$price, $stockId]);
}

// 计算技术指标
function calculateIndicators($kline) {
    if (empty($kline)) {
        return [
            'ma' => ['ma5' => [], 'ma10' => [], 'ma20' => [], 'ma60' => []],
            'macd' => ['dif' => [], 'dea' => [], 'macd' => []],
            'rsi' => [],
            'kdj' => ['k' => [], 'd' => [], 'j' => []]
        ];
    }
    
    $closes = array_column($kline, 'close');
    $highs = array_column($kline, 'high');
    $lows = array_column($kline, 'low');
    
    return [
        'ma' => calculateMA($closes),
        'macd' => calculateMACD($closes),
        'rsi' => calculateRSI($closes),
        'kdj' => calculateKDJ($highs, $lows, $closes)
    ];
}

// 计算移动平均线 MA
function calculateMA($closes) {
    $ma5 = [];
    $ma10 = [];
    $ma20 = [];
    $ma60 = [];
    $count = count($closes);
    
    for ($i = 0; $i < $count; $i++) {
        $ma5[] = ($i >= 4) ? round(array_sum(array_slice($closes, $i - 4, 5)) / 5, 2) : null;
        $ma10[] = ($i >= 9) ? round(array_sum(array_slice($closes, $i - 9, 10)) / 10, 2) : null;
        $ma20[] = ($i >= 19) ? round(array_sum(array_slice($closes, $i - 19, 20)) / 20, 2) : null;
        $ma60[] = ($i >= 59) ? round(array_sum(array_slice($closes, $i - 59, 60)) / 60, 2) : null;
    }
    
    return ['ma5' => $ma5, 'ma10' => $ma10, 'ma20' => $ma20, 'ma60' => $ma60];
}

// 计算MACD
function calculateMACD($closes, $fast = 12, $slow = 26, $signal = 9) {
    $emaFast = calculateEMA($closes, $fast);
    $emaSlow = calculateEMA($closes, $slow);
    
    $dif = [];
    for ($i = 0; $i < count($closes); $i++) {
        $dif[] = round($emaFast[$i] - $emaSlow[$i], 4);
    }
    
    $dea = calculateEMA($dif, $signal);
    
    $macd = [];
    for ($i = 0; $i < count($closes); $i++) {
        $macd[] = round(2 * ($dif[$i] - $dea[$i]), 4);
    }
    
    return ['dif' => $dif, 'dea' => $dea, 'macd' => $macd];
}

// 计算EMA
function calculateEMA($data, $period) {
    $ema = [];
    $multiplier = 2 / ($period + 1);
    
    for ($i = 0; $i < count($data); $i++) {
        $ema[] = ($i == 0) ? $data[$i] : round(($data[$i] - $ema[$i - 1]) * $multiplier + $ema[$i - 1], 4);
    }
    
    return $ema;
}

// 计算RSI
function calculateRSI($closes, $period = 14) {
    $rsi = [];
    
    for ($i = 0; $i < count($closes); $i++) {
        if ($i < $period) {
            $rsi[] = null;
            continue;
        }
        
        $gains = 0;
        $losses = 0;
        
        for ($j = $i - $period + 1; $j <= $i; $j++) {
            $change = $closes[$j] - $closes[$j - 1];
            if ($change > 0) $gains += $change;
            else $losses += abs($change);
        }
        
        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;
        
        $rsi[] = ($avgLoss == 0) ? 100 : round(100 - (100 / (1 + $avgGain / $avgLoss)), 2);
    }
    
    return $rsi;
}

// 计算KDJ
function calculateKDJ($highs, $lows, $closes, $n = 9, $m1 = 3, $m2 = 3) {
    $k = [];
    $d = [];
    $j = [];
    $prevK = 50;
    $prevD = 50;
    
    for ($i = 0; $i < count($closes); $i++) {
        if ($i < $n - 1) {
            $k[] = null;
            $d[] = null;
            $j[] = null;
            continue;
        }
        
        $periodHighs = array_slice($highs, $i - $n + 1, $n);
        $periodLows = array_slice($lows, $i - $n + 1, $n);
        $hn = max($periodHighs);
        $ln = min($periodLows);
        
        $rsv = ($hn == $ln) ? 50 : ($closes[$i] - $ln) / ($hn - $ln) * 100;
        
        $currK = round((2 * $prevK + $rsv) / 3, 2);
        $currD = round((2 * $prevD + $currK) / 3, 2);
        $currJ = round(3 * $currK - 2 * $currD, 2);
        
        $k[] = $currK;
        $d[] = $currD;
        $j[] = $currJ;
        
        $prevK = $currK;
        $prevD = $currD;
    }
    
    return ['k' => $k, 'd' => $d, 'j' => $j];
}
