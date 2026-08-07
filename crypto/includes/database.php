<?php
/**
 * 数据库配置和连接管理
 */

class Database {
    private static ?PDO $instance = null;
    private static ?array $config = null;
    
    public static function getInstance(): PDO {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }
        return self::$instance;
    }
    
    private static function getConfig(): array {
        if (self::$config === null) {
            $configFile = dirname(__DIR__, 2) . '/config/database.php';
            if (!file_exists($configFile)) {
                throw new Exception('数据库配置文件不存在');
            }
            self::$config = require $configFile;
        }
        return self::$config;
    }
    
    private static function createConnection(): PDO {
        $config = self::getConfig();
        
        $dsn = sprintf(
            "mysql:host=%s;port=%d;dbname=%s;charset=utf8",
            $config['host'],
            $config['port'],
            $config['database']
        );
        
        return new PDO($dsn, $config['username'], $config['password'], $config['options']);
    }
    
    public static function getTrades(int $charId, string $type = 'open', int $limit = 10): array {
        $pdo = self::getInstance();
        
        if ($type === 'open') {
            $sql = "SELECT * FROM trades WHERE char_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT {$limit}";
        } else {
            $sql = "SELECT * FROM trades WHERE char_id = ? AND status IN ('win', 'lose') ORDER BY settled_at DESC LIMIT {$limit}";
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$charId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public static function settleTrades(int $charId): void {
        $pdo = self::getInstance();
        
        // 查询所有待结算且已到期的订单
        $stmt = $pdo->prepare("SELECT * FROM trades WHERE char_id = ? AND status = 'pending' AND expire_time <= NOW()");
        $stmt->execute([$charId]);
        $pendingTrades = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($pendingTrades as $trade) {
            $win = random_int(0, 1) === 1;
            $profit = $win ? (int)($trade['amount'] * 0.8) : -$trade['amount'];
            
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("UPDATE trades SET status = ?, profit = ?, settled_at = NOW() WHERE id = ?");
                $stmt->execute([$win ? 'win' : 'lose', $profit, $trade['id']]);
                
                if ($profit > 0) {
                    $stmt = $pdo->prepare("UPDATE character_inventory SET quantity = quantity + ? WHERE char_id = ? AND item_id = 'gold'");
                    $stmt->execute([$trade['amount'] + $profit, $charId]);
                }
                
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        }
    }
    
    public static function placeTrade(int $charId, string $pair, string $direction, int $amount, int $intervalMinutes): array {
        $pdo = self::getInstance();
        
        // 检查余额
        $stmt = $pdo->prepare("SELECT quantity FROM character_inventory WHERE char_id = ? AND item_id = 'gold'");
        $stmt->execute([$charId]);
        $coin = $stmt->fetch();
        
        if (!$coin || $coin['quantity'] < $amount) {
            return ['success' => false, 'error' => '余额不足'];
        }
        
        try {
            $pdo->beginTransaction();
            
            // 扣除余额
            $stmt = $pdo->prepare("UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = 'gold' AND quantity >= ?");
            $stmt->execute([$amount, $charId, $amount]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('余额不足或余额已变更');
            }
            
            // 创建订单
            $expireTime = date('Y-m-d H:i:s', time() + $intervalMinutes * 60);
            $stmt = $pdo->prepare("INSERT INTO trades (char_id, pair, direction, amount, interval_minutes, expire_time, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([$charId, $pair, $direction, $amount, $intervalMinutes, $expireTime]);
            $tradeId = $pdo->lastInsertId();
            
            $pdo->commit();
            
            // 获取最新余额
            $stmt = $pdo->prepare("SELECT quantity FROM character_inventory WHERE char_id = ? AND item_id = 'gold'");
            $stmt->execute([$charId]);
            $newBalance = $stmt->fetch()['quantity'];
            
            return [
                'success' => true,
                'trade_id' => $tradeId,
                'balance' => $newBalance,
                'expire_time' => $expireTime
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public static function getTradeStats(int $charId, string $filter = 'today'): array {
        $pdo = self::getInstance();
        
        $conditions = [
            'today' => "AND DATE(settled_at) = CURDATE()",
            'yesterday' => "AND DATE(settled_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)",
            'month' => "AND settled_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)",
            'all' => ""
        ];
        
        $condition = $conditions[$filter] ?? $conditions['today'];
        
        $stmt = $pdo->prepare("SELECT * FROM trades WHERE char_id = ? AND status IN ('win', 'lose') $condition ORDER BY settled_at DESC LIMIT 100");
        $stmt->execute([$charId]);
        $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stats = ['profit' => 0, 'win' => 0, 'lose' => 0, 'win_count' => 0, 'lose_count' => 0, 'amount' => 0];
        
        foreach ($trades as $trade) {
            $profit = (int)$trade['profit'];
            $stats['profit'] += $profit;
            $stats['amount'] += (int)$trade['amount'];
            
            if ($profit > 0) {
                $stats['win'] += $profit;
                $stats['win_count']++;
            } else {
                $stats['lose'] += abs($profit);
                $stats['lose_count']++;
            }
        }
        
        $totalCount = $stats['win_count'] + $stats['lose_count'];
        $stats['win_rate'] = $totalCount > 0 ? round(($stats['win_count'] / $totalCount) * 100, 1) : 0;
        $stats['total_count'] = $totalCount;
        
        return $stats;
    }
    
    public static function getCharacter(int $charId): ?array {
        $pdo = self::getInstance();
        
        $stmt = $pdo->prepare("SELECT id, name FROM characters WHERE id = ?");
        $stmt->execute([$charId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    public static function getBalance(int $charId): int {
        $pdo = self::getInstance();
        
        $stmt = $pdo->prepare("SELECT quantity FROM character_inventory WHERE char_id = ? AND item_id = 'gold'");
        $stmt->execute([$charId]);
        $coin = $stmt->fetch();
        
        return $coin ? (int)$coin['quantity'] : 0;
    }
    
    // 以下为与项目数据库类兼容的静态方法
    public static function beginTransaction(): bool {
        return self::getInstance()->beginTransaction();
    }
    
    public static function commit(): bool {
        return self::getInstance()->commit();
    }
    
    public static function rollBack(): bool {
        return self::getInstance()->rollBack();
    }
    
    public static function inTransaction(): bool {
        return self::getInstance()->inTransaction();
    }
    
    public static function lastInsertId(): string {
        return self::getInstance()->lastInsertId();
    }
    
    public static function query(string $sql, array $params = []) {
        $pdo = self::getInstance();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    public static function queryOne(string $sql, array $params = []) {
        $stmt = self::query($sql, $params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    public static function queryAll(string $sql, array $params = []): array {
        $stmt = self::query($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public static function execute(string $sql, array $params = []): int {
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }
}
