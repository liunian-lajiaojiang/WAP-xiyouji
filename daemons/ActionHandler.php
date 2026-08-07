<?php
/**
 * Action Handler 基类
 * 
 * 所有特殊动作处理器的抽象基类
 * 采用策略模式，支持数据驱动和代码驱动的混合架构
 */

abstract class ActionHandler {
    
    /**
     * 执行动作
     * 
     * @param int $charId 角色ID
     * @param array $action 动作配置（来自数据库）
     * @param array $params 额外参数（来自用户输入）
     * @return array 执行结果 ['success' => bool, 'message' => string, 'data' => mixed]
     */
    abstract public function execute(int $charId, array $action, array $params = []): array;
    
    /**
     * 验证动作配置是否有效
     * 
     * @param array $action 动作配置
     * @return bool 是否有效
     */
    public function validate(array $action): bool {
        // 基本验证：必须包含必要的字段
        return isset($action['action_name']) && isset($action['action_cmd']);
    }
    
    /**
     * 获取默认配置
     * 
     * @return array 默认配置
     */
    public function getDefaultConfig(): array {
        return [];
    }
    
    /**
     * 合并配置（用户配置覆盖默认配置）
     * 
     * @param array $defaultConfig 默认配置
     * @param array $userConfig 用户配置
     * @return array 合并后的配置
     */
    protected function mergeConfig(array $defaultConfig, array $userConfig): array {
        return array_merge($defaultConfig, $userConfig);
    }
    
    /**
     * 解析动作配置
     * 处理config字段可能是JSON字符串或已解析数组的情况
     * 
     * @param array $action 动作配置
     * @return array 解析后的配置
     */
    protected function parseConfig(array $action): array {
        if (isset($action['config'])) {
            if (is_string($action['config'])) {
                // 如果是字符串，需要json_decode
                $config = json_decode($action['config'], true);
                if ($config === null) {
                    return [];
                }
                return $config;
            } elseif (is_array($action['config'])) {
                // 如果已经是数组，直接使用
                return $action['config'];
            }
        }
        return [];
    }
    
    /**
     * 获取角色信息
     * 
     * @param int $charId 角色ID
     * @return array|null 角色信息
     */
    protected function getCharacter(int $charId): ?array {
        require_once __DIR__ . '/../models/Character.php';
        return CharacterModel::find($charId);
    }
    
    /**
     * 获取房间信息
     * 
     * @param string $roomId 房间ID
     * @return array|null 房间信息
     */
    protected function getRoom(string $roomId): ?array {
        require_once __DIR__ . '/../models/Room.php';
        // 解析room_id获取area和room
        $parts = explode('/', $roomId, 2);
        if (count($parts) === 2) {
            return RoomModel::getFullInfo($parts[0], $parts[1]);
        }
        return null;
    }
    
    /**
     * 发送消息给角色
     * 
     * @param int $charId 角色ID
     * @param string $message 消息内容
     */
    protected function sendMessage(int $charId, string $message): void {
        // 消息将通过session或返回结果传递
        // 这里可以扩展为实时推送
    }
    
    /**
     * 广播消息到房间
     * 
     * @param string $roomId 房间ID
     * @param string $message 消息内容
     * @param int $excludeCharId 排除的角色ID（通常是发起者）
     */
    protected function broadcastToRoom(string $roomId, string $message, int $excludeCharId = 0): void {
        require_once __DIR__ . '/MessageDaemon.php';
        MessageDaemon::broadcastToRoom($roomId, $message, $excludeCharId);
    }
    
    /**
     * 记录动作日志
     * 
     * @param int $charId 角色ID
     * @param string $actionName 动作名称
     * @param array $result 执行结果
     */
    protected function logAction(int $charId, string $actionName, array $result): void {
        // 可选：记录到日志文件或数据库
        // error_log("Action: {$actionName}, Char: {$charId}, Result: " . json_encode($result));
    }
}

