<?php
/**
 * Work Handler - 仓库打工处理器
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 逻辑：
 * 1. 玩家搬大米，消耗 20 kee + 20 sen
 * 2. 工头支付 1 两银子
 * 3. 冷却时间内不可重复打工
 */

require_once __DIR__ . '/ActionHandler.php';

class WorkHandler extends ActionHandler {
    
    /** 冷却时间（秒），原版LPC为1秒，Web版适当延长 */
    private const COOLDOWN_SECONDS = 2;
    
    /** 消耗气血 */
    private const KEE_COST = 20;
    
    /** 消耗精力 */
    private const SEN_COST = 20;
    
    /** 银子奖励 */
    private const SILVER_REWARD = 1;
    
    /**
     * 获取配置（优先从 room_actions.config JSON 读取）
     */
    private function getWorkConfig(array $action): array {
        $dbConfig = $this->parseConfig($action);
        return [
            'cooldown_seconds' => $dbConfig['cooldown_seconds'] ?? self::COOLDOWN_SECONDS,
            'kee_cost' => $dbConfig['kee_cost'] ?? self::KEE_COST,
            'sen_cost' => $dbConfig['sen_cost'] ?? self::SEN_COST,
            'silver_reward' => $dbConfig['silver_reward'] ?? self::SILVER_REWARD,
        ];
    }
    
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $config = $this->getWorkConfig($action);
            
            $char = $this->getCharacter($charId);
            if (!$char) {
                return ['success' => false, 'message' => '角色不存在', 'data' => null];
            }
            
            // 检查冷却时间（使用 session 存储临时标记）
            $cooldownKey = 'work_cooldown_' . ($action['room_id'] ?? 'default');
            $lastWorkTime = $_SESSION[$cooldownKey] ?? 0;
            $cooldown = $config['cooldown_seconds'];
            
            if ($lastWorkTime && (time() - intval($lastWorkTime)) < $cooldown) {
                $remaining = $cooldown - (time() - intval($lastWorkTime));
                return [
                    'success' => false,
                    'message' => HTML_HIYEL . '旁边的壮汉赶紧把你扶起来：先歇一会儿。' . HTML_NOR,
                    'data' => ['type' => 'work_cooldown', 'remaining' => $remaining]
                ];
            }
            
            // 检查气血和精力是否足够
            $keeCost = $config['kee_cost'];
            $senCost = $config['sen_cost'];
            
            if (intval($char['kee']) < $keeCost) {
                return [
                    'success' => false,
                    'message' => HTML_HIRED . '你气血不足，实在干不动了，先歇歇吧。' . HTML_NOR,
                    'data' => null
                ];
            }
            
            if (intval($char['sen']) < $senCost) {
                return [
                    'success' => false,
                    'message' => HTML_HIRED . '你精力不够，头晕眼花的，先休息一下吧。' . HTML_NOR,
                    'data' => null
                ];
            }
            
            // 消耗气血和精力
            Database::execute(
                'UPDATE characters SET kee = GREATEST(0, kee - ?), sen = GREATEST(0, sen - ?) WHERE id = ?',
                [$keeCost, $senCost, $charId]
            );
            
            // 奖励银子（添加到物品栏）
            require_once __DIR__ . '/../models/Item.php';
            ItemModel::addToInventory($charId, 'silver', $config['silver_reward']);
            
            // 设置冷却标记
            $_SESSION[$cooldownKey] = time();
            
            // 构建输出消息
            $charName = h($char['name']);
            $message = HTML_HIGRN . "你从车上卸下一袋袋的大米，又垒在墙边，累的腰酸腿疼！" . HTML_NOR . "<br>";
            $message .= HTML_HIYEL . "旁边过来个工头笑眯眯地对你说：辛苦啦，这是你的工钱。" . HTML_NOR . "<br>";
            $message .= HTML_HIYEL . "你获得了 " . self::SILVER_REWARD . " 两银子。" . HTML_NOR;
            
            // 广播到房间
            $roomId = $char['current_room'];
            $broadcastMsg = HTML_HIGRN . "{$charName}从车上卸下一袋袋的大米，又垒在墙边，累的腰酸腿疼！" . HTML_NOR;
            $this->broadcastToRoom($roomId, $broadcastMsg, $charId);
            
            $broadcastMsg2 = HTML_HIYEL . "旁边过来个工头笑眯眯地对{$charName}说：辛苦啦，这是你的工钱。" . HTML_NOR;
            $this->broadcastToRoom($roomId, $broadcastMsg2, $charId);
            
            return [
                'success' => true,
                'message' => $message,
                'output' => $message,
                'data' => [
                    'type' => 'work_success',
                    'kee_cost' => self::KEE_COST,
                    'sen_cost' => self::SEN_COST,
                    'silver_reward' => self::SILVER_REWARD
                ]
            ];
            
        } catch (\Exception $e) {
            error_log("WorkHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '打工失败，请稍后再试', 'data' => null];
        }
    }
    
    // 冷却通过 session 管理，无需额外数据库表
}
