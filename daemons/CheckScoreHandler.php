<?php
/**
 * CheckScore Handler
 * 
 * 比武战绩查看处理器
 * 
 * 从 ActionRouter::handleLegacyAction 的 checkscore 分支迁移
 */

require_once __DIR__ . '/ActionHandler.php';

class CheckScoreHandler extends ActionHandler {
    
    /**
     * 执行查看比武战绩动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在', 'data' => null];
            }
            
            // 解析配置
            $config = $this->parseConfig($action);
            $title = $config['title'] ?? '=== 你的比武战绩 ===';
            $noStatsMessage = $config['no_stats_message'] ?? "你还没有参加过任何比武。\n去擂台上挑战其他玩家吧！";
            
            // 获取战绩数据
            require_once __DIR__ . '/../helpers/CombatSystemHelper.php';
            $stats = CombatSystemHelper::getStats($charId);
            
            if (!$stats) {
                return [
                    'success' => true,
                    'message' => $noStatsMessage,
                    
                ];
            }
            
            // 构建战绩信息
            $message = $title . "\n";
            $message .= "排名: 第 {$stats['rank']} 名\n";
            $message .= "总积分: {$stats['rating']} 分\n";
            $message .= "总场次: {$stats['total_fights']} 场\n";
            $message .= "胜利: {$stats['wins']} 场\n";
            $message .= "失败: {$stats['losses']} 场\n";
            $message .= "胜率: {$stats['win_rate']}%\n";
            
            return ['success' => true, 'message' => $message, ];
            
        } catch (\Exception $e) {
            error_log("CheckScoreHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '查看战绩失败', 'data' => null];
        }
    }
}

