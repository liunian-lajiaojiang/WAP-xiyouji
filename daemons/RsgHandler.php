<?php
/**
 * Rsg Handler (Ginseng Fruit Fortune Telling)
 * 
 * 人参果算命处理器
 * 处理袁守诚的人参果测算功能
 */

require_once __DIR__ . '/ActionHandler.php';

class RsgHandler extends ActionHandler {
    
    /**
     * 执行人参果测算动作
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
            
            // 2. 已付费，查人参果食用数量
            $rsgCount = $this->getRsgEatenCount($charId);
            
            // 3. 清除临时状态
            $this->clearPaymentStatus($charId, 'suanming');
            
            // 4. 返回结果
            $message = $this->formatResult($character['name'], $rsgCount);
            
            return [
                'success' => true,
                'message' => $message,
                'data' => [
                    'type' => 'rsg_result',
                    'rsg_count' => $rsgCount
                ]
            ];
            
        } catch (\Exception $e) {
            error_log("RsgHandler error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '人参果测算执行失败',
                'data' => null
            ];
        }
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
     * 获取人参果食用数量
     */
    private function getRsgEatenCount(int $charId): int {
        require_once __DIR__ . '/../includes/db.php';
        
        $result = Database::queryOne(
            'SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?',
            [$charId, 'rsg_eaten']
        );
        
        if (!$result) {
            return 0;
        }
        
        $stateData = json_decode($result['state_value'], true);
        return intval($stateData['count'] ?? $stateData['eaten'] ?? 0);
    }
    
    /**
     * 格式化结果消息
     */
    private function formatResult(string $playerName, int $rsgCount): string {
        if ($rsgCount <= 0) {
            return "袁守诚掐指一算，对{$playerName}说道：你还没吃过人参果吧？";
        } else {
            return "袁守诚掐指一算，对{$playerName}说道：你已经吃了{$rsgCount}个人参果，真是福缘不浅啊。";
        }
    }
}
