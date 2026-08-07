<?php
/**
 * List Handler
 * 
 * 排行榜处理器
 * 
 * 从 ActionRouter::handleLegacyAction 的 list 分支迁移
 */

require_once __DIR__ . '/ActionHandler.php';

class ListHandler extends ActionHandler {
    
    /**
     * 执行查看排行榜动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在', 'data' => null];
            }
            
            // 解析配置
            $config = $this->parseConfig($action);
            $topCount = $config['top_count'] ?? 10;
            $title = $config['title'] ?? '=== 比武排行榜 ===';
            $noRankingMessage = $config['no_ranking_message'] ?? "目前还没有人参加过比武。\n成为第一个上榜的高手吧！";
            
            // 获取排行榜数据
            require_once __DIR__ . '/../helpers/CombatSystemHelper.php';
            $ranking = CombatSystemHelper::getRanking($topCount);
            
            if (empty($ranking)) {
                return [
                    'success' => true,
                    'message' => $noRankingMessage,
                    
                ];
            }
            
            // 构建排行榜信息
            $message = $title . "\n";
            $message .= sprintf("%-4s %-10s %6s %6s %8s\n", "排名", "姓名", "积分", "胜场", "胜率");
            $message .= str_repeat("-", 40) . "\n";
            
            foreach ($ranking as $idx => $player) {
                $rank = $idx + 1;
                $name = mb_substr($player['char_name'], 0, 5);
                $rating = $player['rating'];
                $wins = $player['wins'];
                $winRate = $player['win_rate'];
                
                $message .= sprintf("%-4d %-10s %6d %6d %7.1f%%\n", 
                    $rank, $name, $rating, $wins, $winRate);
            }
            
            return ['success' => true, 'message' => $message, ];
            
        } catch (\Exception $e) {
            error_log("ListHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '查看排行榜失败', 'data' => null];
        }
    }
}

