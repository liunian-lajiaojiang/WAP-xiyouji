<?php
/**
 * Suanming Handler (Fortune Telling - Life Span)
 * 
 * 算命系统处理器 - 算寿命
 * 处理袁守诚的算命功能（算寿命）
 */

require_once __DIR__ . '/ActionHandler.php';

class SuanmingHandler extends ActionHandler {
    
    /**
     * 执行算命动作（算寿命）
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
            
            // 1. 检查付费状态
            $paid = $this->checkPaymentStatus($charId, $config['unlock_temp_key'] ?? 'suanming/paid');
            
            if (!$paid) {
                // 未付费，引导玩家获取金色鲤鱼
                $asked = $this->hasAskedBefore($charId, 'suanming/asked');
                
                if ($asked) {
                    return [
                        'success' => false,
                        'message' => '在下正需一条金色鲤鱼，不知人兄能否搞来。',
                        'data' => ['type' => 'payment_required', 'item' => $config['payment_item'] ?? '金色鲤鱼']
                    ];
                } else {
                    // 第一次询问，设置标记
                    $this->setAskedFlag($charId, 'suanming/asked');
                    return [
                        'success' => false,
                        'message' => '这个．．．天机不可泄露啊。',
                        'data' => ['type' => 'hint_payment']
                    ];
                }
            }
            
            // 2. 已付费，计算寿命
            $lifeTime = $this->calculateLifeTime($character);
            
            // 3. 清除临时状态
            $this->clearPaymentStatus($charId, 'suanming');
            
            // 4. 返回算命结果
            $message = $this->formatResult($character['name'], $lifeTime);
            
            return [
                'success' => true,
                'message' => $message,
                'data' => [
                    'type' => 'life_result',
                    'life_time' => $lifeTime
                ]
            ];
            
        } catch (\Exception $e) {
            error_log("SuanmingHandler error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '算命系统执行失败',
                'data' => null
            ];
        }
    }
    
    /**
     * 计算寿命
     * 
     * 基于年龄和根骨推算
     */
    private function calculateLifeTime(array $character): int {
        $age = intval($character['age'] ?? 14);
        $con = intval($character['con'] ?? 10);
        
        // 基础寿命 80 岁，每点根骨增加 1 岁
        $baseLife = 80;
        $lifeBonus = $con;
        $totalLife = $baseLife + $lifeBonus;
        
        // 随机波动 ±5 岁
        $random = mt_rand(-5, 5);
        $lifeTime = $totalLife + $random;
        
        // 确保至少比当前年龄大
        if ($lifeTime <= $age) {
            $lifeTime = $age + 10;
        }
        
        return $lifeTime;
    }
    
    /**
     * 检查付费状态
     */
    private function checkPaymentStatus(int $charId, string $tempKey): bool {
        require_once __DIR__ . '/../includes/db.php';
        
        $result = Database::queryOne(
            'SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?',
            [$charId, $tempKey]
        );
        
        if (!$result) {
            return false;
        }
        
        // 检查是否过期
        $stateData = json_decode($result['state_value'], true);
        if (isset($stateData['expire_time']) && strtotime($stateData['expire_time']) < time()) {
            // 已过期，删除记录
            Database::execute(
                'DELETE FROM character_temp_states WHERE char_id = ? AND state_key = ?',
                [$charId, $tempKey]
            );
            return false;
        }
        
        return true;
    }
    
    /**
     * 检查是否已经询问过
     */
    private function hasAskedBefore(int $charId, string $tempKey): bool {
        require_once __DIR__ . '/../includes/db.php';
        
        $result = Database::queryOne(
            'SELECT id FROM character_temp_states WHERE char_id = ? AND state_key = ?',
            [$charId, $tempKey]
        );
        
        return $result !== null;
    }
    
    /**
     * 设置询问标记
     */
    private function setAskedFlag(int $charId, string $tempKey): void {
        require_once __DIR__ . '/../includes/db.php';
        
        Database::execute(
            'INSERT INTO character_temp_states (char_id, state_key, state_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE updated_at = NOW()',
            [$charId, $tempKey, json_encode(['set' => true])]
        );
    }
    
    /**
     * 清除付费状态
     */
    private function clearPaymentStatus(int $charId, string $tempPrefix): void {
        require_once __DIR__ . '/../includes/db.php';
        
        // 清除所有以该前缀开头的状态
        Database::execute(
            'DELETE FROM character_temp_states WHERE char_id = ? AND state_key LIKE ?',
            [$charId, $tempPrefix . '/%']
        );
    }
    
    /**
     * 设置付费状态（供GiveHandler调用）
     */
    public function setPaymentStatus(int $charId, string $tempKey, int $durationSeconds = 86400): void {
        require_once __DIR__ . '/../includes/db.php';
        
        $expireTime = date('Y-m-d H:i:s', time() + $durationSeconds);
        $stateValue = json_encode([
            'paid' => true,
            'pay_time' => date('Y-m-d H:i:s'),
            'expire_time' => $expireTime
        ]);
        
        Database::execute(
            'INSERT INTO character_temp_states (char_id, state_key, state_value, expire_time) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), expire_time = VALUES(expire_time), updated_at = NOW()',
            [$charId, $tempKey, $stateValue, $expireTime]
        );
    }
    
    /**
     * 格式化结果消息
     */
    private function formatResult(string $playerName, int $lifeTime): string {
        return "袁守诚掐指一算，对{$playerName}说道：你有{$lifeTime}岁的寿命。";
    }
}

