<?php
/**
 * 游泳动作处理器
 * 处理小溪前↔小溪之间的游泳移动
 * 
 * 原始LPC逻辑：
 * - 小溪前(xiaoxiqian) swim → 小溪(xiaoxi)
 * - 小溪(xiaoxi) swim → 小溪前(xiaoxiqian)
 */

require_once __DIR__ . '/ActionHandler.php';

class SwimHandler extends ActionHandler
{
    public function getDefaultConfig(): array {
        return [
            'routes' => [
                'dntg/hgs/xiaoxiqian' => [
                    'target'      => 'dntg/hgs/xiaoxi',
                    'target_area' => 'dntg',
                    'leave_msg'   => '$N纵身跃入小溪。',
                    'arrive_msg'  => '只见小溪中水花四溅，几条小鱼跳了起来。',
                    'personal_msg'=> '你纵身跃入清澈的小溪，冰凉的水沁人心脾。',
                ],
                'dntg/hgs/xiaoxi' => [
                    'target'      => 'dntg/hgs/xiaoxiqian',
                    'target_area' => 'dntg',
                    'leave_msg'   => '$N游到岸边。',
                    'arrive_msg'  => '$N分开水面，爬上岸来。',
                    'personal_msg'=> '你游到岸边，爬上了岸。',
                ],
            ],
        ];
    }

    /** @var array|null 配置缓存 */
    private static ?array $configCache = null;

    private function getConfig(array $action): array {
        if (self::$configCache !== null) {
            return self::$configCache;
        }
        $dbCfg = $this->parseConfig($action);
        self::$configCache = array_merge($this->getDefaultConfig(), $dbCfg);
        return self::$configCache;
    }

    public function execute(int $charId, array $action, array $params = []): array
    {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../models/Character.php';
        require_once __DIR__ . '/../models/Room.php';
        require_once __DIR__ . '/MessageDaemon.php';

        $cfg = $this->getConfig($action);
        $routes = $cfg['routes'];

        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }

        $roomId = $char['current_room'];

        // 检查当前房间是否有游泳路线
        if (!isset($routes[$roomId])) {
            return ['success' => false, 'message' => '这里没法游泳。'];
        }

        $route = $routes[$roomId];
        $charName = $char['name'];

        // 广播离开消息（原房间）
        $leaveMsg = HTML_HIYEL . str_replace('$N', $charName, $route['leave_msg']) . HTML_NOR;
        MessageDaemon::broadcastToRoom($roomId, $leaveMsg, $charId, 'room');

        // 更新角色位置
        $targetRoom = RoomModel::load($route['target_area'], $route['target']);
        if (!$targetRoom) {
            return ['success' => false, 'message' => '目标房间不存在'];
        }
        CharacterModel::updatePosition($charId, $route['target_area'], $route['target']);

        // 广播到达消息（目标房间）
        $arriveMsg = HTML_HIYEL . str_replace('$N', $charName, $route['arrive_msg']) . HTML_NOR;
        MessageDaemon::broadcastToRoom($route['target'], $arriveMsg, $charId, 'room');

        // 构建个人消息
        $personalMsg = HTML_HICYN . $targetRoom['name'] . HTML_NOR . "\n";
        $personalMsg .= ($targetRoom['description'] ? $targetRoom['description'] . "\n" : '');
        $personalMsg .= HTML_HIYEL . $route['personal_msg'] . HTML_NOR;

        return [
            'success' => true,
            'type' => 'move',
            'message' => $personalMsg,
            // leave/arrive 消息已在上方通过 broadcastToRoom 广播，不再由 action.php 重复广播
            'leave_message' => '',
            'arrive_message' => '',
            'new_room' => $targetRoom,
            'redirect' => 'room.php?area=' . urlencode($route['target_area']) . '&room=' . urlencode($route['target']),
        ];
    }
}
