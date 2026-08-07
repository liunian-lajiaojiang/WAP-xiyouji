<?php
/**
 * AI 玩家守护进程
 * 
 * 负责：
 * - AI 玩家的行为调度和批量处理
 * - 与现有的 action.php 入口对接（支持通过 AJAX 触发 AI tick）
 * - AI 玩家登录/登出管理
 * 
 * 调用方式：
 * - 定时任务：php tasks/AiPlayerTickTask.php
 * - 前端触发：action.php?action=ai_tick (需在 ActionRouter 中注册)
 * - 手动触发：AiPlayerDaemon::runAllAiPlayers()
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../helpers/AiPlayerHelper.php';

class AiPlayerDaemon {
    
    /**
     * 单次运行：处理所有 AI 玩家的一个 tick
     * 
     * 设计思路：
     * - 每次被调用时，处理一小批 AI 玩家（避免单次处理过多）
     * - 每个 AI 玩家执行一个动作
     * - 适用于定时任务（如每5秒调用一次）
     * 
     * @param int $batchSize 每次处理的 AI 玩家数量（默认3个）
     * @return array 处理结果
     */
    public static function runTick(int $batchSize = 3): array {
        $aiPlayerIds = AiPlayerHelper::getAiPlayerIds();
        
        if (empty($aiPlayerIds)) {
            return [
                'success' => true,
                'message' => '没有在线的AI玩家',
                'total' => 0,
                'results' => []
            ];
        }
        
        // 按上次动作时间排序，优先处理等待最久的
        $pendingIds = [];
        foreach ($aiPlayerIds as $charId) {
            $char = CharacterModel::find($charId);
            if ($char) {
                $lastAction = intval($char['ai_last_action'] ?? 0);
                $pendingIds[$charId] = $lastAction;
            }
        }
        asort($pendingIds); // 升序，等待最久的排前面
        
        // 取前 batchSize 个
        $processIds = array_slice(array_keys($pendingIds), 0, $batchSize);
        
        $results = [];
        foreach ($processIds as $charId) {
            try {
                $result = AiPlayerHelper::tick($charId);
                $results[] = $result;
            } catch (\Exception $e) {
                error_log("[AiPlayerDaemon] 处理AI玩家#{$charId}出错: " . $e->getMessage());
                $results[] = [
                    'success' => false,
                    'char_id' => $charId,
                    'message' => $e->getMessage(),
                ];
            }
        }
        
        return [
            'success' => true,
            'message' => "处理了 " . count($processIds) . " 个AI玩家",
            'total' => count($aiPlayerIds),
            'processed' => count($processIds),
            'results' => $results,
        ];
    }
    
    /**
     * 登录所有 AI 玩家
     * 通常在服务器启动时调用
     * @return array
     */
    public static function loginAllAiPlayers(): array {
        $aiPlayers = Database::queryAll(
            "SELECT id, name FROM characters WHERE is_ai_player = 1"
        );
        
        $results = [];
        foreach ($aiPlayers as $aiPlayer) {
            $charId = intval($aiPlayer['id']);
            $success = AiPlayerHelper::loginAiPlayer($charId);
            $results[] = [
                'char_id' => $charId,
                'name' => $aiPlayer['name'],
                'success' => $success,
            ];
        }
        
        return [
            'total' => count($aiPlayers),
            'results' => $results,
        ];
    }
    
    /**
     * 登出所有 AI 玩家
     * @return array
     */
    public static function logoutAllAiPlayers(): array {
        $aiPlayers = Database::queryAll(
            "SELECT id, name FROM characters WHERE is_ai_player = 1 AND online = 1"
        );
        
        $results = [];
        foreach ($aiPlayers as $aiPlayer) {
            $charId = intval($aiPlayer['id']);
            $success = AiPlayerHelper::logoutAiPlayer($charId);
            $results[] = [
                'char_id' => $charId,
                'name' => $aiPlayer['name'],
                'success' => $success,
            ];
        }
        
        return [
            'total' => count($aiPlayers),
            'results' => $results,
        ];
    }
    
    /**
     * 创建一个新的 AI 玩家角色并上线
     * @param string $name 角色名
     * @param string $gender 性别
     * @param string $race 种族
     * @return array
     */
    public static function createAndLogin(string $name, string $gender = 'male', string $race = 'human'): array {
        $charId = AiPlayerHelper::createAiCharacter($name, $gender, $race);
        
        if ($charId === false) {
            return ['success' => false, 'message' => "创建AI玩家失败，角色名「{$name}」可能已存在"];
        }
        
        $loginResult = AiPlayerHelper::loginAiPlayer($charId);
        
        return [
            'success' => true,
            'message' => "AI玩家「{$name}」创建成功并已上线",
            'char_id' => $charId,
            'name' => $name,
            'logged_in' => $loginResult,
        ];
    }
    
    /**
     * 获取 AI 玩家状态摘要
     * @return array
     */
    public static function getStatus(): array {
        $total = Database::queryOne(
            "SELECT COUNT(*) as cnt FROM characters WHERE is_ai_player = 1"
        );
        $online = Database::queryOne(
            "SELECT COUNT(*) as cnt FROM characters WHERE is_ai_player = 1 AND online = 1"
        );
        
        $players = Database::queryAll(
            "SELECT id, name, race, gender, current_area, current_room, kee, max_kee, online, ai_last_action
             FROM characters WHERE is_ai_player = 1
             ORDER BY id"
        );
        
        return [
            'total' => intval($total['cnt'] ?? 0),
            'online' => intval($online['cnt'] ?? 0),
            'players' => $players,
        ];
    }
}
