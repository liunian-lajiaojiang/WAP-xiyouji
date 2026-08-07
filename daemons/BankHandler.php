<?php
/**
 * Bank Handler
 * 
 * 银行系统处理器
 * 处理存款、取款、查询余额等银行业务
 */

require_once __DIR__ . '/ActionHandler.php';

class BankHandler extends ActionHandler {
    
    /**
     * 执行银行动作
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
                
                case 'query_balance':
                    return $this->handleQueryBalance($charId, $character, $config, $params);
                
                default:
                    return [
                        'success' => false,
                        'message' => '未知的银行操作类型: ' . $type,
                        'data' => null
                    ];
            }
            
        } catch (\Exception $e) {
            error_log("BankHandler error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '银行操作执行失败',
                'data' => null
            ];
        }
    }
    
    /**
     * 处理存款
     */
    private function handleDeposit(int $charId, array $character, array $config, array $params): array {
        // 复用CommerceHandler的存款逻辑
        require_once __DIR__ . '/CommerceHandler.php';
        
        // 创建临时的action配置
        $tempAction = [
            'action_name' => 'deposit',
            'config' => json_encode([
                'type' => 'deposit',
                'min_amount' => $config['min_amount'] ?? 1,
                'max_amount' => $config['max_amount'] ?? 1000000
            ])
        ];
        
        $commerceHandler = new CommerceHandler();
        return $commerceHandler->execute($charId, $tempAction, $params);
    }
    
    /**
     * 处理取款
     */
    private function handleWithdraw(int $charId, array $character, array $config, array $params): array {
        // 复用CommerceHandler的取款逻辑
        require_once __DIR__ . '/CommerceHandler.php';
        
        // 创建临时的action配置
        $tempAction = [
            'action_name' => 'withdraw',
            'config' => json_encode([
                'type' => 'withdraw',
                'min_amount' => $config['min_amount'] ?? 1
            ])
        ];
        
        $commerceHandler = new CommerceHandler();
        return $commerceHandler->execute($charId, $tempAction, $params);
    }
    
    /**
     * 处理查询余额
     */
    private function handleQueryBalance(int $charId, array $character, array $config, array $params): array {
        // 复用CommerceHandler的余额查询逻辑
        require_once __DIR__ . '/CommerceHandler.php';
        
        $commerceHandler = new CommerceHandler();
        $balance = $commerceHandler->getDepositBalance($charId);
        
        // 计算利息（可选）
        $interestRate = $config['interest_rate'] ?? 0.01;
        $estimatedInterest = intval($balance * $interestRate);
        
        return [
            'success' => true,
            'message' => "你在钱庄的存款余额为：{$balance}两银子。" . 
                        ($estimatedInterest > 0 ? "预计可获得利息：{$estimatedInterest}两。" : ""),
            'data' => [
                'type' => 'balance_query',
                'balance' => $balance,
                'estimated_interest' => $estimatedInterest
            ]
        ];
    }
    

}

