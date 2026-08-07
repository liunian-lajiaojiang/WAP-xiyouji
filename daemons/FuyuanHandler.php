<?php
/**
 * Fuyuan Handler (Fortune Level Calculation)
 * 
 * 福缘测算处理器
 * 处理袁守诚的福缘等级测算功能
 */

require_once __DIR__ . '/ActionHandler.php';

class FuyuanHandler extends ActionHandler {
    
    /**
     * 中文福缘等级描述
     */
    private $levelDescriptions = [
        0 => '薄福之人',
        1 => '下福之人',
        2 => '中福之人',
        3 => '上福之人',
        4 => '厚福之人',
        5 => '鸿福之人',
        6 => '洪福齐天'
    ];
    
    /**
     * 执行福缘测算动作
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
            
            // 2. 已付费，计算福缘等级
            $level = $this->calculateFortuneLevel($character, $config['calculation_formula'] ?? '');
            $levels = $config['levels'] ?? [];
            
            // 优先使用配置中的描述，如果没有则使用默认中文描述
            if (isset($levels[$level])) {
                $result = $levels[$level];
            } else {
                $result = $this->levelDescriptions[$level] ?? '未知';
            }
            
            // 3. 清除临时状态
            $this->clearPaymentStatus($charId, 'suanming');
            
            // 4. 返回测算结果
            $message = $this->formatResult($character['name'], $result);
            
            return [
                'success' => true,
                'message' => $message,
                'data' => [
                    'type' => 'fortune_result',
                    'level' => $level,
                    'result' => $result
                ]
            ];
            
        } catch (\Exception $e) {
            error_log("FuyuanHandler error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '福缘测算执行失败',
                'data' => null
            ];
        }
    }
    
    /**
     * 计算福缘等级
     * 
     * 公式: (kar + donation/1000000) / 5 - 2
     */
    private function calculateFortuneLevel(array $character, string $formula): int {
        $kar = $character['kar'] ?? 10;  // 默认幸运值10
        $donation = $character['donation'] ?? 0;
        
        // 计算公式
        $level = intval(($kar + $donation / 1000000) / 5 - 2);
        
        // 限制范围 0-6
        return max(0, min(6, $level));
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
    private function formatResult(string $playerName, string $result): string {
        return "袁守诚掐指一算，对{$playerName}说道：依我看，你乃是{$result}。";
    }
}
