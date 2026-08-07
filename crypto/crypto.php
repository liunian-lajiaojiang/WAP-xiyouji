<?php
/**
 * 币安U本位合约实时价格 - Web显示版本
 * 整合交易界面和实时价格数据
 */
session_save_path(__DIR__ . '/../sessions');
session_start();

// 检查是否登录
if (!isset($_SESSION['char_id']) || empty($_SESSION['char_id'])) {
    header('Location: login.php');
    exit;
}

// BTC数据源配置(按优先级排序,自动故障切换)
$btcApiSources = [
    [
        'name' => 'Gate.io',
        'url' => 'https://api.gateio.ws/api/v4/futures/usdt/tickers?contract=BTC_USDT',
        'parser' => function($data) {
            return isset($data[0]['last']) ? (float)$data[0]['last'] : null;
        }
    ],
    [
        'name' => 'OKX',
        'url' => 'https://www.okx.com/api/v5/market/ticker?instId=BTC-USDT-SWAP',
        'parser' => function($data) {
            return isset($data['data'][0]['last']) ? (float)$data['data'][0]['last'] : null;
        }
    ],
    [
        'name' => 'Binance',
        'url' => 'https://fapi.binance.me/fapi/v2/ticker/price?symbol=BTCUSDT',
        'parser' => function($data) {
            return isset($data['price']) ? (float)$data['price'] : null;
        }
    ]
];

// ETH数据源配置(按优先级排序,自动故障切换)
$ethApiSources = [
    [
        'name' => 'Gate.io',
        'url' => 'https://api.gateio.ws/api/v4/futures/usdt/tickers?contract=ETH_USDT',
        'parser' => function($data) {
            return isset($data[0]['last']) ? (float)$data[0]['last'] : null;
        }
    ],
    [
        'name' => 'OKX',
        'url' => 'https://www.okx.com/api/v5/market/ticker?instId=ETH-USDT-SWAP',
        'parser' => function($data) {
            return isset($data['data'][0]['last']) ? (float)$data['data'][0]['last'] : null;
        }
    ],
    [
        'name' => 'Binance',
        'url' => 'https://fapi.binance.me/fapi/v2/ticker/price?symbol=ETHUSDT',
        'parser' => function($data) {
            return isset($data['price']) ? (float)$data['price'] : null;
        }
    ]
];

// 加载项目配置和模型
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Character.php';

// 数据库连接（使用项目的 Database 类）
$pdo = Database::getInstance();

// 获取用户信息 - 使用项目的模型
$userId = $_SESSION['user_id'];
$charId = $_SESSION['char_id'];
$user = UserModel::find($userId);
$char = CharacterModel::find($charId);
$username = $user['username'] ?? '';
$charName = $char['name'] ?? '';

// 获取gold余额
$coinBalance = 0;
$stmt = $pdo->prepare("SELECT quantity FROM character_inventory WHERE char_id = ? AND item_id = 'gold'");
$stmt->execute([$charId]);
$coinItem = $stmt->fetch();
$coinBalance = $coinItem ? $coinItem['quantity'] : 0;

// 查询订单 - 直接在页面加载，同时检查结算
$pageOpenOrders = [];
$pageClosedOrders = [];
$pageOpenCount = 0;
$pageClosedCount = 0;
try {
    // 先查询开仓订单总数
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM trades WHERE char_id = ? AND status = 'pending'");
    $stmt->execute([$charId]);
    $pageOpenCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // 查询已平仓订单总数
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM trades WHERE char_id = ? AND status IN ('win', 'lose')");
    $stmt->execute([$charId]);
    $pageClosedCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // 先查询开仓订单
    $stmt = $pdo->prepare("SELECT * FROM trades WHERE char_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$charId]);
    $tempOpenOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 检查并结算到期的订单
    foreach ($tempOpenOrders as $order) {
        $expireTime = strtotime($order['expire_time']);
        if ($expireTime && $expireTime <= time()) {
            // 到期了，进行结算
            // 获取当前价格作为平仓价
            $closePrice = $order['open_price']; // 默认使用开仓价
            try {
                $sources = $order['pair'] === 'ETH' ? $ethApiSources : $btcApiSources;
                foreach ($sources as $api) {
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $api['url'],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 2,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => false,
                    ]);
                    $response = curl_exec($ch);
                    curl_close($ch);
                    $data = json_decode($response, true);
                    $price = $api['parser']($data);
                    if ($price) {
                        $closePrice = $price;
                        break;
                    }
                }
            } catch (Exception $e) {
                // 获取失败，继续使用开仓价
            }
            
            // 根据真实价格涨跌来判断胜负
            $openPrice = floatval($order['open_price']);
            $closePrice = floatval($closePrice);
            
            if ($order['direction'] === 'up') {
                // 买涨：价格涨了才算赢
                $win = $closePrice > $openPrice;
            } else {
                // 买跌：价格跌了才算赢
                $win = $closePrice < $openPrice;
            }
            
            // 如果价格相等，退本金（不亏不赢）
            if ($closePrice == $openPrice) {
                $profit = 0;
            } else {
                $profit = $win ? (int)($order['amount'] * 0.8) : -$order['amount'];
            }
            
            try {
                $pdo->beginTransaction();
                
                // 更新订单状态和平仓价
                $status = $profit > 0 ? 'win' : ($profit < 0 ? 'lose' : 'draw');
                $stmt2 = $pdo->prepare("UPDATE trades SET status = ?, profit = ?, close_price = ?, settled_at = NOW() WHERE id = ?");
                $stmt2->execute([$status, $profit, $closePrice, $order['id']]);
                
                // 如果赢了，添加金币（本金+利润）
                if ($profit > 0) {
                    $stmt2 = $pdo->prepare("UPDATE character_inventory SET quantity = quantity + ? WHERE char_id = ? AND item_id = 'gold'");
                    $stmt2->execute([$order['amount'] + $profit, $charId]);
                } elseif ($profit == 0) {
                    // 如果是平局，退本金
                    $stmt2 = $pdo->prepare("UPDATE character_inventory SET quantity = quantity + ? WHERE char_id = ? AND item_id = 'gold'");
                    $stmt2->execute([$order['amount'], $charId]);
                }
                
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        }
    }

    // 重新查询订单
    $stmt = $pdo->prepare("SELECT * FROM trades WHERE char_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$charId]);
    $pageOpenOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 重新查询开仓订单总数（因为可能有订单被结算了）
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM trades WHERE char_id = ? AND status = 'pending'");
    $stmt->execute([$charId]);
    $pageOpenCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $pdo->prepare("SELECT * FROM trades WHERE char_id = ? AND status IN ('win', 'lose') ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$charId]);
    $pageClosedOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 重新查询已平仓订单总数（因为可能有订单被结算了）
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM trades WHERE char_id = ? AND status IN ('win', 'lose')");
    $stmt->execute([$charId]);
    $pageClosedCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch (Exception $e) {
    // 不影响页面
}

// AJAX请求处理
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $ajaxPair = isset($_GET['pair']) ? strtoupper($_GET['pair']) : 'BTC';
    $ajaxInterval = isset($_GET['interval']) ? $_GET['interval'] : '10m';

    if ($_GET['ajax'] === 'price') {
        $sources = $ajaxPair === 'ETH' ? $ethApiSources : $btcApiSources;
        foreach ($sources as $api) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $api['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($response, true);
            $price = $api['parser']($data);
            if ($price) {
                echo json_encode(['price' => number_format($price, 2), 'source' => $api['name']]);
                exit;
            }
        }
        echo json_encode(['price' => null, 'source' => '']);
        exit;
    }

    if ($_GET['ajax'] === 'kline') {
        $ajaxPair = isset($_GET['pair']) ? strtoupper($_GET['pair']) : 'BTC';
        $ajaxInterval = isset($_GET['interval']) ? $_GET['interval'] : '1m';
        
        // 转换交易对格式
        $gatePair = $ajaxPair === 'ETH' ? 'ETH_USDT' : 'BTC_USDT';
        
        // Gate.io现货API的时间周期映射
        $intervalMapGate = [
            '1m' => '1m', '5m' => '5m', '10m' => '5m', '15m' => '15m',
            '30m' => '30m', '1H' => '1h', '4H' => '4h', '1D' => '1d'
        ];
        $gateInterval = $intervalMapGate[$ajaxInterval] ?? '1m';
        
        $klineUrl = "https://api.gateio.ws/api/v4/spot/candlesticks?currency_pair={$gatePair}&interval={$gateInterval}&limit=100";
        
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $klineUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => ['Accept: application/json']
            ]);
            $klineResponse = curl_exec($ch);
            $klineHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $result = [];
            if ($klineHttpCode == 200) {
                $klineJson = json_decode($klineResponse, true);
                if ($klineJson && is_array($klineJson)) {
                    // Gate.io返回的数据是倒序的，需要反转
                    $gateData = array_reverse($klineJson);
                    foreach ($gateData as $k) {
                        if (isset($k['t']) && isset($k['o']) && isset($k['h']) && isset($k['l']) && isset($k['c'])) {
                            $result[] = [
                                'time' => intval($k['t']),
                                'open' => floatval($k['o']),
                                'high' => floatval($k['h']),
                                'low' => floatval($k['l']),
                                'close' => floatval($k['c'])
                            ];
                        }
                    }
                }
            }
            
            // 如果获取失败，返回空数组（前端会使用模拟数据）
            ob_clean();  // 清理输出缓冲区
            echo json_encode($result);
        } catch (Exception $e) {
            ob_clean();
            echo json_encode([]);
        }
        exit;
    }

    // 独立的 orders AJAX 端点
    if ($_GET['ajax'] === 'orders') {
        if (!$pdo) {
            ob_clean();
            echo json_encode(['error' => 'Database connection failed']);
            exit;
        }
        
        $charId = $_SESSION['char_id'] ?? 21;
        $type = $_GET['type'] ?? 'open';
        
        try {
            // 查询总数
            if ($type === 'open') {
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM trades WHERE char_id = ? AND status = 'pending'");
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM trades WHERE char_id = ? AND status IN ('win', 'lose')");
            }
            $stmt->execute([$charId]);
            $totalCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // 查询订单记录（最多10条）
            if ($type === 'open') {
                $stmt = $pdo->prepare("SELECT * FROM trades WHERE char_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 10");
            } else {
                $stmt = $pdo->prepare("SELECT * FROM trades WHERE char_id = ? AND status IN ('win', 'lose') ORDER BY created_at DESC LIMIT 10");
            }
            $stmt->execute([$charId]);
            $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ob_clean();
            echo json_encode(['orders' => $trades, 'total' => $totalCount]);
        } catch (Exception $e) {
            ob_clean();
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['ajax'] === 'trade' && isset($_GET['action'])) {
        if (!$pdo) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => '数据库连接失败']);
            exit;
        }

        $action = $_GET['action'];
        $charId = $_SESSION['char_id'] ?? 21;

        if ($action === 'place') {
            $direction = $_GET['direction'];
            $amount = (int)$_GET['amount'];
            $pair = $_GET['pair'];
            $interval = (int)$_GET['interval'];

            // 验证输入
            if (!in_array($direction, ['up', 'down'])) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => '无效的交易方向']);
                exit;
            }
            if ($amount < 5) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => '最低下注金额为5黄金']);
                exit;
            }

            // 检查余额
            $stmt = $pdo->prepare("SELECT quantity FROM character_inventory WHERE char_id = ? AND item_id = 'gold'");
            $stmt->execute([$charId]);
            $char = $stmt->fetch();
            if (!$char || $char['quantity'] < $amount) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => '余额不足']);
                exit;
            }

            // 扣除黄金和创建订单
            $pdo->beginTransaction();
            try {
                // 扣除余额
                $stmt = $pdo->prepare("UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = 'gold' AND quantity >= ?");
                $stmt->execute([$amount, $charId, $amount]);
                if ($stmt->rowCount() == 0) {
                    throw new Exception('余额不足或余额已变更');
                }

                // 获取当前开仓价
                $openPrice = null;
                $sources = $pair === 'ETH' ? $ethApiSources : $btcApiSources;
                foreach ($sources as $api) {
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $api['url'],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 5,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => false,
                    ]);
                    $response = curl_exec($ch);
                    curl_close($ch);
                    $data = json_decode($response, true);
                    $price = $api['parser']($data);
                    if ($price) {
                        $openPrice = $price;
                        break;
                    }
                }

                // 创建订单
                $expireTime = date('Y-m-d H:i:s', time() + $interval * 60);
                $stmt = $pdo->prepare("INSERT INTO trades (char_id, pair, direction, amount, interval_minutes, open_price, expire_time, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
                $stmt->execute([$charId, $pair, $direction, $amount, $interval, $openPrice, $expireTime]);
                $tradeId = $pdo->lastInsertId();
                
                $pdo->commit();

                // 获取最新余额
                $stmt = $pdo->prepare("SELECT quantity FROM character_inventory WHERE char_id = ? AND item_id = 'gold'");
                $stmt->execute([$charId]);
                $newBalance = $stmt->fetch()['quantity'];

                ob_clean();
                echo json_encode([
                    'success' => true,
                    'trade_id' => $tradeId,
                    'balance' => $newBalance,
                    'expire_time' => $expireTime
                ]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                ob_clean();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
        }

        if ($action === 'result') {
            $tradeId = (int)$_GET['trade_id'];
            $stmt = $pdo->prepare("SELECT * FROM trades WHERE id = ? AND char_id = ?");
            $stmt->execute([$tradeId, $charId]);
            $trade = $stmt->fetch();

            if (!$trade) {
                echo json_encode(['success' => false, 'error' => '订单不存在']);
                exit;
            }

            // 判断是否到期
            if ($trade['status'] === 'pending' && strtotime($trade['expire_time']) <= time()) {
                $win = mt_rand(0, 1) == 1;
                $pdo->beginTransaction();
                try {
                    // 获取当前平仓价
                    $closePrice = null;
                    $sources = $trade['pair'] === 'ETH' ? $ethApiSources : $btcApiSources;
                    foreach ($sources as $api) {
                        $ch = curl_init();
                        curl_setopt_array($ch, [
                            CURLOPT_URL => $api['url'],
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT => 5,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_SSL_VERIFYHOST => false,
                        ]);
                        $response = curl_exec($ch);
                        curl_close($ch);
                        $data = json_decode($response, true);
                        $price = $api['parser']($data);
                        if ($price) {
                            $closePrice = $price;
                            break;
                        }
                    }
                    
                    $profit = $win ? $trade['amount'] * 0.8 : -$trade['amount'];
                    $stmt = $pdo->prepare("UPDATE trades SET status = ?, profit = ?, close_price = ?, settled_at = NOW() WHERE id = ?");
                    $stmt->execute([$win ? 'win' : 'lose', $profit, $closePrice, $tradeId]);

                    if ($profit > 0) {
                        $stmt = $pdo->prepare("UPDATE character_inventory SET quantity = quantity + ? WHERE char_id = ? AND item_id = 'gold'");
                        $stmt->execute([$trade['amount'] + $profit, $charId]);
                    }

                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                }

                $stmt = $pdo->prepare("SELECT * FROM trades WHERE id = ?");
                $stmt->execute([$tradeId]);
                $trade = $stmt->fetch();
            }

            echo json_encode([
                'success' => true,
                'trade' => $trade
            ]);
            exit;
        }
    }
}

// 获取当前交易对参数,默认BTC
$currentPair = isset($_GET['pair']) ? strtoupper($_GET['pair']) : 'BTC';
$symbol = $currentPair === 'ETH' ? 'ETHUSDT' : 'BTCUSDT';
$symbolShort = $currentPair === 'ETH' ? 'ETH' : 'BTC';

// 根据交易对选择API源
$apiSources = $currentPair === 'ETH' ? $ethApiSources : $btcApiSources;
$apiContract = $currentPair === 'ETH' ? 'ETH_USDT' : 'BTC_USDT';

$price = '--';
$error = '';
$sourceName = '';

// 尝试多个API源,直到成功
foreach ($apiSources as $api) {
    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $api['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // 检查HTTP状态码
        if ($httpCode == 200) {
            $data = json_decode($response, true);
            if ($data) {
                $parsedPrice = $api['parser']($data);
                if ($parsedPrice !== null && $parsedPrice > 0) {
                    $price = number_format($parsedPrice, 2);
                    $sourceName = $api['name'];
                    $error = ''; // 清除错误
                    break; // 成功获取,跳出循环
                }
            }
        }
        
        // 记录错误,继续尝试下一个
        $error = $curlError ?: "HTTP {$httpCode}";
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        continue; // 继续尝试下一个API
    }
}

// 获取K线数据 - 使用OKX API
$klineData = [];
$klineError = '';

$intervalMap = [
    '1' => '1m', '5' => '5m', '10' => '10m', '15' => '15m',
    '30' => '30m', '60' => '1H', '240' => '4H', '1d' => '1D'
];
$defaultInterval = '1m';
$interval = isset($_GET['interval']) ? ($intervalMap[$_GET['interval']] ?? '1m') : '1m';

$okxSymbol = $currentPair === 'ETH' ? 'ETH_USDT' : 'BTC_USDT';
// 使用Gate.io现货API
$intervalMapGate = [
    '1m' => '1m', '5m' => '5m', '10m' => '5m', '15m' => '15m',
    '30m' => '30m', '1H' => '1h', '4H' => '4h', '1D' => '1d'
];
$gateInterval = $intervalMapGate[$interval] ?? '1m';
$klineUrl = "https://api.gateio.ws/api/v4/spot/candlesticks?currency_pair={$okxSymbol}&interval={$gateInterval}&limit=100";

try {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $klineUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    $klineResponse = curl_exec($ch);
    $klineHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($klineHttpCode == 200) {
        $klineJson = json_decode($klineResponse, true);
        if ($klineJson && is_array($klineJson)) {
            // Gate.io返回的数据是倒序的（最新的在前），需要反转
            $gateData = array_reverse($klineJson);
            foreach ($gateData as $k) {
                // Gate.io返回的是数字索引数组: [timestamp, volume, open, high, low, close, ...]
                if (is_array($k) && count($k) >= 6) {
                    $ts = intval($k[0]);
                    // 确保时间戳是秒级（如果是毫秒级则除以1000）
                    if ($ts > 1000000000000) {
                        $ts = intval($ts / 1000);
                    }
                    $open = floatval($k[2]);
                    $high = floatval($k[3]);
                    $low = floatval($k[4]);
                    $close = floatval($k[5]);
                    
                    $klineData[] = [
                        'time' => $ts,
                        'open' => $open,
                        'high' => $high,
                        'low' => $low,
                        'close' => $close
                    ];
                }
            }
        }
    } else {
        $klineError = "HTTP {$klineHttpCode}: {$curlError}";
    }
} catch (Exception $e) {
    $klineError = $e->getMessage();
}

// 如果K线为空，使用模拟数据
if (empty($klineData)) {
    // 确保使用真实的价格作为基础
    $basePrice = ($price !== '--' && is_numeric(str_replace(',', '', $price))) 
        ? floatval(str_replace(',', '', $price)) 
        : 70000;
    
    // 使用当前时间作为基准
    $mockTime = time();
    
    // 根据时间周期确定K线间隔（秒）
    $intervalSeconds = [
        '1m' => 60,
        '5m' => 300,
        '10m' => 600,
        '15m' => 900,
        '30m' => 1800,
        '1H' => 3600,
        '4H' => 14400,
        '1D' => 86400
    ];
    $barInterval = $intervalSeconds[$interval] ?? 60;
    
    for ($i = 0; $i < 100; $i++) {
        // 生成更合理的价格波动
        $open = $basePrice + (mt_rand(-500, 500));
        $close = $open + (mt_rand(-300, 300));
        $high = max($open, $close) + mt_rand(0, 200);
        $low = min($open, $close) - mt_rand(0, 200);
        
        // 确保价格不会变成负数或太小
        $open = max($open, $basePrice * 0.9);
        $close = max($close, $basePrice * 0.9);
        $low = max($low, $basePrice * 0.8);
        
        $klineData[] = [
            'time' => $mockTime - (99 - $i) * $barInterval,
            'open' => round($open, 2),
            'high' => round($high, 2),
            'low' => round($low, 2),
            'close' => round($close, 2)
        ];
    }
}

$klineJson = json_encode($klineData);
        
// 如果所有API都失败
if ($price === '--' && empty($error)) {
    $error = '所有数据源不可用';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>事件合约 - BTCUSDT</title>
    <link rel="stylesheet" href="css/trade.css">
    <link rel="stylesheet" href="css/crypto.css">
</head>
<body>
    <div class="app-container">
        <!-- 用户栏 -->
        <div class="user-bar">
            <div class="user-info">
                <span>欢迎，</span>
                <span class="user-name"><?php echo htmlspecialchars($username); ?></span>
                <span style="color: #848E9C; font-size: 12px;">黄金: <?php echo number_format($coinBalance); ?></span>
                <a href="../functions/room.php" class="indicator-btn" style="color: #00BFFF;text-decoration:none;">返回游戏</a>
            </div>
            <div class="user-actions">
                
                <a href="logout.php" class="btn-logout" style="text-decoration:none;">退出登录</a>
            </div>
        </div>
        <!-- 顶部标题 -->
        <div class="header">事件合约</div>

        <!-- 实时价格显示 -->
        <div class="live-price">
            <div class="price-label"><?php echo $symbolShort; ?>USDT 实时价格</div>
            <?php if ($price !== '--'): ?>
                <div class="price-value">$<?php echo $price; ?></div>
                <?php if ($sourceName): ?>
                    <div class="price-source">数据来源: <?php echo $sourceName; ?></div>
                <?php endif; ?>
                <div class="price-time">更新时间: <?php echo date('Y-m-d H:i:s'); ?></div>
                <div style="font-size:12px;color:#888;">K线数量: <?php echo count($klineData); ?> | <?php echo empty($klineError) ? '使用真实数据' : '使用模拟数据: ' . htmlspecialchars($klineError); ?></div>
            <?php else: ?>
                <div class="price-error"><?php echo $error ?: '获取中...'; ?></div>
            <?php endif; ?>
        </div>

        <!-- 交易对和涨跌选项 -->
        <div class="trade-pair-bar">
            <form method="get" style="display:inline;">
                <select name="pair" class="pair-select" onchange="this.form.submit()">
                    <option value="BTC" <?php echo $currentPair === 'BTC' ? 'selected' : ''; ?>>BTCUSDT</option>
                    <option value="ETH" <?php echo $currentPair === 'ETH' ? 'selected' : ''; ?>>ETHUSDT</option>
                </select>
            </form>
            <div class="direction-options">
                <span class="up-option">上涨: 80%</span>
                <span class="down-option">下跌: 80%</span>
            </div>
        </div>

        <!-- 时间周期栏 -->
        <div class="timeframe-bar">
            <a href="?pair=<?php echo $currentPair; ?>&interval=1" class="timeframe-item <?php echo $interval === '1m' ? 'active' : ''; ?>">1分</a>
            <a href="?pair=<?php echo $currentPair; ?>&interval=5" class="timeframe-item <?php echo $interval === '5m' ? 'active' : ''; ?>">5分</a>
            <a href="?pair=<?php echo $currentPair; ?>&interval=10" class="timeframe-item <?php echo $interval === '10m' ? 'active' : ''; ?>">10分</a>
            <a href="?pair=<?php echo $currentPair; ?>&interval=15" class="timeframe-item <?php echo $interval === '15m' ? 'active' : ''; ?>">15分</a>
            <a href="?pair=<?php echo $currentPair; ?>&interval=30" class="timeframe-item <?php echo $interval === '30m' ? 'active' : ''; ?>">30分</a>
            <a href="?pair=<?php echo $currentPair; ?>&interval=60" class="timeframe-item <?php echo $interval === '1H' ? 'active' : ''; ?>">1小时</a>
            <a href="?pair=<?php echo $currentPair; ?>&interval=240" class="timeframe-item <?php echo $interval === '4H' ? 'active' : ''; ?>">4小时</a>
            <span>指数价格</span>
        </div>

        <!-- 图例 -->
        <div class="chart-legend" id="chart_legend">
            <div class="legend-item">
                <div class="legend-dot" style="background: #F0B90B;"></div>
                <span class="legend-label">EMA7:</span>
                <span class="legend-value" id="ema7">--</span>
            </div>
            <div class="legend-item">
                <div class="legend-dot" style="background: #EAECEF;"></div>
                <span class="legend-label">EMA25:</span>
                <span class="legend-value" id="ema25">--</span>
            </div>
            <div class="legend-item">
                <div class="legend-dot" style="background: #A970FF;"></div>
                <span class="legend-label">BOLL:</span>
                <span class="legend-value" id="boll">--</span>
            </div>
            <div class="legend-item">
                <div class="legend-dot" style="background: #4ECDC4;"></div>
                <span class="legend-label">EMA99:</span>
                <span class="legend-value" id="ema99">--</span>
            </div>
        </div>

        <!-- 技术指标开关 -->
        <div class="indicator-controls" style="padding: 10px 15px; background: #0B0E11; border-bottom: 1px solid #1E2329; display: flex; gap: 10px; flex-wrap: wrap;">
            <span style="color: #848E9C; font-size: 12px; margin-right: 5px; line-height: 30px;">指标:</span>
            <button id="toggle-ema7" class="indicator-btn active" style="background: #1E2329; color: #F0B90B; border: 1px solid #2B3139; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;">EMA7</button>
            <button id="toggle-ema25" class="indicator-btn active" style="background: #1E2329; color: #EAECEF; border: 1px solid #2B3139; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;">EMA25</button>
            <button id="toggle-ema99" class="indicator-btn active" style="background: #1E2329; color: #A970FF; border: 1px solid #2B3139; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;">EMA99</button>
            <button id="toggle-boll" class="indicator-btn active" style="background: #1E2329; color: #787B86; border: 1px solid #2B3139; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;">BOLL</button>
            <span style="color: #848E9C; font-size: 12px; margin-left: 15px; margin-right: 5px; line-height: 30px;">标记:</span>
            <button id="toggle-markers" class="indicator-btn active" style="background: #1E2329; color: #4ECDC4; border: 1px solid #2B3139; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;">订单</button>
        </div>

        <!-- K线图区域 -->
        <div class="chart-wrapper">
            <div class="chart-header">
                <span><?php echo $symbol; ?></span>
                <span style="font-size:14px;color:#888;">周期: <?php echo str_replace(['m','H','D'], ['分','小时','天'], $interval); ?></span>
            </div>
            <div id="chart_container" style="height: 350px; width: 100%; min-height: 350px; background: #0B0E11;"></div>
            <div id="vol_container" style="height: 100px; display: none;"></div>
        </div>

        <!-- 使用 v4 版本，兼容性更好 -->
    <script src="https://unpkg.com/lightweight-charts@4.1.0/dist/lightweight-charts.standalone.production.js"></script>
    <script src="js/binance-chart-with-memory.js?v=<?php echo time(); ?>"></script>
        <script>
            console.log('=== 调试信息 ===');
            console.log('LightweightCharts 是否存在:', typeof LightweightCharts !== 'undefined');
            if (typeof LightweightCharts !== 'undefined') {
                console.log('LightweightCharts 版本:', LightweightCharts.version());
                console.log('LightweightCharts 对象:', Object.keys(LightweightCharts));
            }
            console.log('binanceDarkCNTheme 是否存在:', typeof window.binanceDarkCNTheme !== 'undefined');
            
            var chartInstance = null;
            var klineData = <?php echo json_encode($klineData); ?>;
            console.log('K线数据数量:', klineData.length);
            console.log('K线数据示例:', klineData[0]);
            
            // 初始化图表
            function initChart() {
                console.log('开始初始化图表...');
                const chartEl = document.getElementById('chart_container');
                console.log('图表容器:', chartEl);
                console.log('容器宽度:', chartEl.clientWidth);
                console.log('容器高度:', chartEl.clientHeight);
                
                if (!chartEl) {
                    console.error('找不到图表容器!');
                    return;
                }
                
                const volEl = null; // 暂时禁用成交量图
                
                try {
                    chartInstance = window.binanceDarkCNTheme({
                        chartEl: chartEl,
                        volEl: volEl,
                        options: {
                            chartOptions: {
                                width: chartEl.clientWidth,
                                height: 350,
                            }
                        }
                    });
                    
                    console.log('图表创建成功:', chartInstance);
                    
                    // 保存到全局，让订单管理器能访问到
                    window.chartInstance = chartInstance;
                    
                    // 加载初始数据
                    chartInstance.setCandleData(klineData);
                    console.log('K线数据已设置');
                    
                    // 显示滑动提示
                    const scrollHint = document.getElementById('scroll_hint');
                    if (scrollHint && klineData.length > 35) {
                        scrollHint.style.display = 'inline';
                    }
                    
                    // 更新按钮状态
                    updateIndicatorButtonState();
                    
                    updateLegend();
                } catch (e) {
                    console.error('图表初始化失败:', e);
                }
            }
            
            // 更新图例
            function updateLegend(customPrices = null) {
                const prices = customPrices || chartInstance.getCurrentPrices();
                if (!prices) return;
                
                document.getElementById('ema7').textContent = prices.ema7 ? prices.ema7.toFixed(2) : '--';
                document.getElementById('ema25').textContent = prices.ema25 ? prices.ema25.toFixed(2) : '--';
                document.getElementById('ema99').textContent = prices.ema99 ? prices.ema99.toFixed(2) : '--';
                
                // 显示布林带 (上轨/中轨/下轨)
                if (prices.bollUpper && prices.bollMid && prices.bollLower) {
                    document.getElementById('boll').textContent = 
                        prices.bollUpper.toFixed(0) + ' / ' + 
                        prices.bollMid.toFixed(0) + ' / ' + 
                        prices.bollLower.toFixed(0);
                } else {
                    document.getElementById('boll').textContent = '--';
                }
            }
            
            // 更新按钮状态
            function updateIndicatorButtonState() {
                if (!chartInstance) return;
                
                const state = chartInstance.getIndicatorState();
                const buttons = [
                    { id: 'toggle-ema7', indicator: 'ema7' },
                    { id: 'toggle-ema25', indicator: 'ema25' },
                    { id: 'toggle-ema99', indicator: 'ema99' },
                    { id: 'toggle-boll', indicator: 'bollinger' }
                ];
                
                buttons.forEach(({ id, indicator }) => {
                    const btn = document.getElementById(id);
                    if (btn) {
                        const isVisible = state[indicator];
                        btn.classList.toggle('active', isVisible);
                        btn.style.opacity = isVisible ? '1' : '0.5';
                    }
                });
            }
            
            // 指标开关按钮事件处理
            function setupIndicatorButtons() {
                const buttons = [
                    { id: 'toggle-ema7', indicator: 'ema7' },
                    { id: 'toggle-ema25', indicator: 'ema25' },
                    { id: 'toggle-ema99', indicator: 'ema99' },
                    { id: 'toggle-boll', indicator: 'bollinger' }
                ];
                
                buttons.forEach(({ id, indicator }) => {
                    const btn = document.getElementById(id);
                    if (btn) {
                        btn.addEventListener('click', function() {
                            const isVisible = chartInstance.toggleIndicator(indicator);
                            this.classList.toggle('active', isVisible);
                            this.style.opacity = isVisible ? '1' : '0.5';
                        });
                    }
                });
            }
            
            // 页面加载后设置按钮事件
            document.addEventListener('DOMContentLoaded', function() {
                setupIndicatorButtons();
            });

            // 监听十字线移动事件
            window.addEventListener('chartCrosshair', function(e) {
                if (e.detail && e.detail.prices) {
                    updateLegend(e.detail.prices);
                }
            });

            // 每60秒自动刷新价格和K线
            setInterval(async function() {
                var pair = '<?php echo $currentPair; ?>';
                var interval = '<?php echo $interval; ?>';

                try {
                    // 获取实时价格
                    var priceRes = await fetch('?ajax=price&pair=' + pair);
                    var priceData = await priceRes.json();
                    if (priceData.price) {
                        var priceEl = document.querySelector('.price-value');
                        var sourceEl = document.querySelector('.price-source');
                        var timeEl = document.querySelector('.price-time');
                        if (priceEl) priceEl.textContent = '$' + priceData.price;
                        if (sourceEl) sourceEl.textContent = '数据来源: ' + priceData.source;
                        if (timeEl) timeEl.textContent = '更新时间: ' + new Date().toLocaleString('zh-CN');
                    }

                    // 获取新K线数据
            var intervalMap = {
                '1': '1m', '5': '5m', '10': '10m', '15': '15m',
                '30': '30m', '60': '1H', '240': '4H', '1d': '1D'
            };
            var currentUrl = new URL(window.location.href);
            var urlInterval = currentUrl.searchParams.get('interval') || '1';
            var apiInterval = intervalMap[urlInterval] || '1m';
            
            var klineRes = await fetch('?ajax=kline&pair=' + pair + '&interval=' + apiInterval);
                    var newKline = await klineRes.json();
                    if (newKline.length > 0) {
                        klineData = newKline;
                        chartInstance.setCandleData(klineData);
                        updateLegend();
                    }
                } catch (e) {
                    console.log('自动刷新失败', e);
                }
            }, 60000);

            // 页面加载完成后初始化
            document.addEventListener('DOMContentLoaded', function() {
                console.log('DOMContentLoaded 事件触发');
                console.log('当前 document.readyState:', document.readyState);
                initChart();
            });
            
            // 为了保险起见，也在 window.onload 也尝试初始化
            window.addEventListener('load', function() {
                console.log('window.load 事件触发');
                if (!window.chartInstance) {
                    console.log('window.load 中初始化图表');
                    initChart();
                }
            });

            // 窗口大小变化时重绘
            window.addEventListener('resize', function() {
                if (chartInstance) {
                    chartInstance.chart.applyOptions({
                        width: document.getElementById('chart_container').clientWidth
                    });
                }
            });
        </script>

        <!-- 交易设置区 -->
        <div class="trade-settings">
            <div class="row">
                <div>
                    <div class="label">时间单位</div>
                    <select id="trade-interval" class="timeframe-select">
                        <option value="1" selected>1 分钟</option>
                        <option value="5">5 分钟</option>
                        <option value="10">10 分钟</option>
                        <option value="15">15 分钟</option>
                        <option value="30">30 分钟</option>
                        <option value="60">1 小时</option>
                    </select>
                </div>
                <div>
                    <div class="label">黄金 (Gold) <span style="color:#666;font-size:12px;">(最低5)</span></div>
                    <div class="quantity-control">
                        <button class="qty-btn" onclick="adjustcoin(-50)">−</button>
                        <input type="number" id="trade-coin" class="coin-input" value="5" min="5" step="5" onchange="updateTradeInfo()">
                        <button class="qty-btn" onclick="adjustcoin(50)">+</button>
                    </div>
                </div>
            </div>
            

            <div class="rate-row">
                <div class="rate-info">
                    <div class="rate-label">支付率</div>
                    <div class="rate-value">80%</div>
                    <div class="rate-label">下单金额</div>
                    <div id="invest-coin">100 Gold</div>
                </div>
                <div class="rate-info">
                    <div class="rate-label">支付金额</div>
                    <div class="rate-value win" id="predict-profit">9 Gold</div>
                    <div class="rate-label">到期时间</div>
                    <div id="expire-time">--:--</div>
                </div>
            </div>
        </div>

        <!-- 操作按钮 -->
        <div class="action-buttons">
            <button class="btn btn-down" onclick="placeTrade('down')">↘ 下跌</button>
            <button class="btn btn-up" onclick="placeTrade('up')">↗ 上涨</button>
        </div>

        <!-- 持仓记录 -->
        <section class="orders-section">
            <nav class="orders-tabs">
                <span class="orders-tab active" data-tab="open" onclick="switchOrders('open')">
                    已开仓 <span id="open-count">(<?php echo $pageOpenCount > 10 ? '10+' : $pageOpenCount; ?>)</span>
                </span>
                <span class="orders-tab" data-tab="closed" onclick="switchOrders('closed')">
                    已平仓 <span id="closed-count">(<?php echo $pageClosedCount > 10 ? '10+' : $pageClosedCount; ?>)</span>
                </span>
                <a href="crypto_history.php" class="history-link" title="查看交易历史">📋</a>
            </nav>
            <div id="orders-list" class="orders-list"></div>
        </section>

        <!-- 直接嵌入订单数据 -->
        <script>
            window.pageOpenOrders = <?php echo json_encode($pageOpenOrders); ?>;
            window.pageClosedOrders = <?php echo json_encode($pageClosedOrders); ?>;
        </script>
        <script src="js/orders.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                orderManager.initWithData(window.pageOpenOrders, window.pageClosedOrders);
                if (window.chartInstance) {
                    orderManager.setChartInstance(window.chartInstance);
                    // 初始化标记开关按钮状态
                    const markersBtn = document.getElementById('toggle-markers');
                    if (markersBtn) {
                        markersBtn.classList.toggle('active', orderManager.markersVisible);
                        markersBtn.style.opacity = orderManager.markersVisible ? '1' : '0.5';
                        markersBtn.addEventListener('click', () => {
                            orderManager.toggleMarkers();
                        });
                    }
                }
                window.switchOrders = (tab) => orderManager.switchTab(tab);
            });
        </script>

        <script>
            var currentcoin = 5;
            var tradeInterval = 1;
            var usercoinBalance = <?php echo $coinBalance; ?>;

            function adjustcoin(delta) {
                var input = document.getElementById('trade-coin');
                currentcoin = Math.max(5, parseInt(input.value) + delta);
                input.value = currentcoin;
                updateTradeInfo();
            }

            function updateTradeInfo() {
                var input = document.getElementById('trade-coin');
                currentcoin = Math.max(5, parseInt(input.value) || 5);
                input.value = currentcoin;
                var profit = Math.round(currentcoin * 0.8);
                var total = currentcoin + profit; // 下单金+纯获得
                document.getElementById('invest-coin').textContent = currentcoin + ' G';
                document.getElementById('predict-profit').textContent = total + ' G';
                document.getElementById('expire-time').textContent = new Date(Date.now() + tradeInterval * 60000).toLocaleTimeString();
            }

            document.getElementById('trade-interval').addEventListener('change', function() {
                tradeInterval = parseInt(this.value);
                document.getElementById('expire-time').textContent = new Date(Date.now() + tradeInterval * 60000).toLocaleTimeString();
            });

            // 测试函数 - 确认按钮可以点击
            function testPlaceTrade(direction) {
                alert('按钮点击成功！方向: ' + direction + ', 金额: ' + currentcoin);
                console.log('测试点击:', direction);
            }

            async function placeTrade(direction) {
                console.log('========== 开始下单 ==========');
                console.log('方向:', direction);
                console.log('当前金额:', currentcoin);
                console.log('用户余额:', usercoinBalance);
                
                if (currentcoin < 5) {
                    alert('下单金额最低5金!');
                    return;
                }
                if (currentcoin > usercoinBalance) {
                    alert('余额不足！当前: ' + usercoinBalance + ' Gold');
                    return;
                }
                
                // 下单确认
                const directionText = direction === 'up' ? '买涨' : '买跌';
                const confirmResult = confirm(`确认下单？\n${directionText}\n金额: ${currentcoin} Gold`);
                if (!confirmResult) {
                    console.log('用户取消下单');
                    return;
                }
                var amount = currentcoin;
                var pair = '<?php echo $currentPair; ?>';
                var interval = document.getElementById('trade-interval').value;

                console.log('准备发送请求:', { direction, amount, pair, interval });
                
                try {
                    var url = '?ajax=trade&action=place&direction=' + direction + '&amount=' + amount + '&pair=' + pair + '&interval=' + interval;
                    console.log('请求URL:', url);
                    
                    var res = await fetch(url);
                    console.log('响应状态:', res.status);
                    
                    var data = await res.json();
                    console.log('收到响应数据:', data);

                    if (data.success) {
                        usercoinBalance = data.balance;
                        alert(direction === 'up' ? '买涨成功！到期: ' + data.expire_time : '买跌成功！到期: ' + data.expire_time);
                        
                        // 刷新页面来更新订单和余额
                        setTimeout(function() {
                            location.reload();
                        }, 500);
                    } else {
                        alert('下单失败: ' + data.error);
                    }
                } catch (error) {
                    console.error('下单出错:', error);
                    alert('下单出错: ' + error);
                }
            }

            updateTradeInfo();
        </script>
    </div>


    <!-- 自动刷新(每60秒) -->
    <script>
        // 60秒后自动刷新页面
        setTimeout(function() {
            location.reload();
        }, 60000);
    </script>
</body>
</html>
