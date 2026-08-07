<?php
/**
 * 洞穴床铺动作处理器 (CaveBedHandler)
 * 
 * 处理水帘洞内(dntg/hgs/dongnei)的睡觉交互：
 * - bed/gosleep/gobed → 传送到石床(dntg/hgs/shichuang)睡眠室
 * 
 * 原始LPC逻辑参考：
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */

require_once __DIR__ . '/ActionHandler.php';

class CaveBedHandler extends ActionHandler
{
    public function execute(int $charId, array $action, array $params = []): array
    {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../models/Character.php';
        require_once __DIR__ . '/../models/Room.php';
        require_once __DIR__ . '/MessageDaemon.php';

        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }

        $roomId = $char['current_room'];
        if ($roomId !== 'dntg/hgs/dongnei') {
            return ['success' => false, 'message' => '这里没有可以睡觉的地方。'];
        }

        $actionCmd = $action['action_cmd'] ?? '';
        if (!in_array($actionCmd, ['bed', 'gosleep', 'gobed'])) {
            return ['success' => false, 'message' => '你不知道怎么做。'];
        }

        $charName = $char['name'];

        // 广播躺下消息
        $bedMsg = HTML_HIYEL . $charName . '往石床上一躺，准备睡觉了。' . HTML_NOR;
        MessageDaemon::broadcastToRoom('dntg/hgs/dongnei', $bedMsg, $charId, 'room');

        // 更新位置到石床
        CharacterModel::updatePosition($charId, 'dntg', 'dntg/hgs/shichuang');

        // 广播到达消息
        $arriveMsg = HTML_HIYEL . $charName . '钻到了被窝里。' . HTML_NOR;
        MessageDaemon::broadcastToRoom('dntg/hgs/shichuang', $arriveMsg, $charId, 'room');

        $targetRoom = RoomModel::load('dntg', 'dntg/hgs/shichuang');

        $personalMsg = HTML_HICYN . '石床' . HTML_NOR . "\n";
        $personalMsg .= "一张长长的石床。\n";
        $personalMsg .= HTML_HIYEL . '你在水帘洞内的石床上躺了下来，十分舒适。' . HTML_NOR;

        return [
            'success' => true,
            'type' => 'move',
            'message' => $personalMsg,
            // leave/arrive 消息已在上方通过 broadcastToRoom 广播，不再由 action.php 重复广播
            'leave_message' => '',
            'arrive_message' => '',
            'new_room' => $targetRoom,
            'redirect' => 'room.php?area=dntg&room=dntg/hgs/shichuang',
        ];
    }
}
