<?php
/**
 * Commerce Handler
 * 
 * 商业操作处理器
 * 处理寄存、取回、购买等商业动作
 */

require_once __DIR__ . '/ActionHandler.php';

class CommerceHandler extends ActionHandler {
    
    /**
     * 执行商业动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            
            if (!$character) {
                return [
                    'success' => false,
                    'message' => '角色不存在',
                    'data' => null
                ];
            }
            
            // 解析配置
            $config = $this->parseConfig($action);
            $type = $config['type'] ?? '';
            
            // 根据类型分发到不同的处理方法
            switch ($type) {
                case 'deposit':
                    return $this->handleDeposit($charId, $character, $config, $params);
                
                case 'withdraw':
                    return $this->handleWithdraw($charId, $character, $config, $params);
                
                case 'purchase_expired':
                    return $this->handlePurchaseExpired($charId, $character, $config, $params);
                
                default:
                    return [
                        'success' => false,
                        'message' => '未知的商业操作类型: ' . $type,
                        'data' => null
                    ];
            }
            
        } catch (\Exception $e) {
            error_log("CommerceHandler error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '商业操作执行失败',
                'data' => null
            ];
        }
    }
    
    /**
     * 处理存款
     */
    private function handleDeposit(int $charId, array $character, array $config, array $params): array {
        // 1. 检查参数（金额）
        $amount = intval($params['amount'] ?? 0);
        
        if ($amount <= 0) {
            return [
                'success' => false,
                'message' => '你想存多少银两？',
                'data' => null
            ];
        }
        
        // 2. 验证金额范围
        $minAmount = $config['min_amount'] ?? 1;
        $maxAmount = $config['max_amount'] ?? 1000000;
        
        if ($amount < $minAmount || $amount > $maxAmount) {
            return [
                'success' => false,
                'message' => "存款金额必须在{$minAmount}到{$maxAmount}之间。",
                'data' => null
            ];
        }
        
        // 3. 验证余额
        $currentMoney = $character['money'] ?? 0;
        if ($currentMoney < $amount) {
            return [
                'success' => false,
                'message' => '你的银两不够。',
                'data' => null
            ];
        }
        
        // 4. 扣除银两
        require_once __DIR__ . '/../includes/db.php';
        Database::execute(
            'UPDATE characters SET money = money - ? WHERE id = ?',
            [$amount, $charId]
        );
        
        // 5. 记录存款（使用 INSERT ... ON DUPLICATE KEY UPDATE）
        Database::execute(
            'INSERT INTO bank_deposits (char_id, amount, deposit_time) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount)',
            [$charId, $amount]
        );
        
        // 6. 记录交易日志
        $newBalance = $this->getDepositBalance($charId);
        $this->logTransaction($charId, 'deposit', $amount, $newBalance, "存入{$amount}两银子");
        
        // 7. 返回结果
        return [
            'success' => true,
            'message' => "你将{$amount}两银子存入了钱庄。当前存款余额：{$newBalance}两。",
            'data' => [
                'type' => 'deposit_success',
                'amount' => $amount,
                'balance' => $newBalance
            ]
        ];
    }
    
    /**
     * 处理取款
     */
    private function handleWithdraw(int $charId, array $character, array $config, array $params): array {
        // 1. 检查参数
        $amount = intval($params['amount'] ?? 0);
        
        if ($amount <= 0) {
            return [
                'success' => false,
                'message' => '你想取多少银两？',
                'data' => null
            ];
        }
        
        // 2. 验证最小金额
        $minAmount = $config['min_amount'] ?? 1;
        if ($amount < $minAmount) {
            return [
                'success' => false,
                'message' => "取款金额必须至少为{$minAmount}两。",
                'data' => null
            ];
        }
        
        // 3. 查询存款余额
        $depositBalance = $this->getDepositBalance($charId);
        
        if ($depositBalance < $amount) {
            return [
                'success' => false,
                'message' => '你的存款不足。',
                'data' => null
            ];
        }
        
        // 4. 扣除存款
        require_once __DIR__ . '/../includes/db.php';
        Database::execute(
            'UPDATE bank_deposits SET amount = amount - ?, last_withdraw_time = NOW() WHERE char_id = ?',
            [$amount, $charId]
        );
        
        // 5. 增加银两
        Database::execute(
            'UPDATE characters SET money = money + ? WHERE id = ?',
            [$amount, $charId]
        );
        
        // 6. 记录交易日志
        $newBalance = $this->getDepositBalance($charId);
        $this->logTransaction($charId, 'withdraw', $amount, $newBalance, "取出{$amount}两银子");
        
        // 7. 返回结果
        return [
            'success' => true,
            'message' => "你从钱庄取出了{$amount}两银子。剩余存款：{$newBalance}两。",
            'data' => [
                'type' => 'withdraw_success',
                'amount' => $amount,
                'balance' => $newBalance
            ]
        ];
    }
    
    /**
     * 处理购买过期物品
     */
    private function handlePurchaseExpired(int $charId, array $character, array $config, array $params): array {
        // TODO: 实现购买过期物品逻辑
        // 这个功能需要结合物品寄存系统
        
        return [
            'success' => true,
            'message' => '购买过期物品功能开发中...',
            'data' => ['type' => 'purchase_expired']
        ];
    }
    
    /**
     * 获取存款余额
     */
    private function getDepositBalance(int $charId): int {
        require_once __DIR__ . '/../includes/db.php';
        $result = Database::queryOne(
            'SELECT amount FROM bank_deposits WHERE char_id = ? AND is_active = 1',
            [$charId]
        );
        
        return $result ? intval($result['amount']) : 0;
    }
    
    /**
     * 记录交易日志
     */
    private function logTransaction(int $charId, string $type, int $amount, int $balanceAfter, string $description): void {
        require_once __DIR__ . '/../includes/db.php';
        Database::execute(
            'INSERT INTO bank_transactions (char_id, transaction_type, amount, balance_after, description) VALUES (?, ?, ?, ?, ?)',
            [$charId, $type, $amount, $balanceAfter, $description]
        );
    }
}

