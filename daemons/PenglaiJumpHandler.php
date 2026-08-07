<?php
/**
 * PenglaiJumpHandler - 蓬莱薄命岩跳下悬崖
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 从薄命岩跳下 → 随机降落到百花谷(baihuagu10~44)
 * 摔伤公式: random(300 - dodge)，最低保留50气血
 */

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/MessageDaemon.php';
require_once __DIR__ . '/../models/Character.php';

class PenglaiJumpHandler extends ActionHandler {

    public function execute(int $charId, array $action, array $params = []): array {
        $character = $this->getCharacter($charId);
        if (!$character) {
            return ['success' => false, 'message' => '角色不存在'];
        }

        $currentRoom = $character['current_room'];
        $charName = $character['name'];

        // 必须在薄命岩
        if ($currentRoom !== 'penglai/bomingyan') {
            return ['success' => false, 'message' => '这里没法跳下去。'];
        }

        // 跳下广播
        $jumpBroadcast = HTML_HIYEL . "{$charName}纵身一跃，从悬崖上跳了下去！" . HTML_NOR;
        MessageDaemon::broadcastToRoom($currentRoom, $jumpBroadcast, $charId, 'room');

        // 随机降落到百花谷
        // 原始LPC: baihuagu(十位,个位) = random(4)+1 . random(5)
        // 结果: baihuagu10~44
        $tens = mt_rand(1, 4);    // 十位: 1-4
        $ones = mt_rand(0, 4);     // 个位: 0-4
        $targetRoom = "penglai/baihuagu{$tens}{$ones}";
        $targetArea = 'penglai';

        // 摔伤计算：random(300 - dodge)，最低保留50
        $dodge = intval($character['dodge'] ?? 0);
        $fallDamage = mt_rand(0, max(0, 300 - $dodge));
        $currentKee = intval($character['kee'] ?? 1000);
        $newKee = max(50, $currentKee - $fallDamage);

        // 更新位置和气血
        CharacterModel::updatePosition($charId, $targetArea, $targetRoom);
        Database::execute("UPDATE characters SET kee = ? WHERE id = ?", [$newKee, $charId]);

        // 给自己消息
        $selfMsg = HTML_HIYEL . "你纵身一跃，跳下悬崖。";
        if ($fallDamage > 0) {
            $selfMsg .= " 你在空中翻滚了几圈，重重摔在百花丛中。";
            $selfMsg .= HTML_HIRED . "\n你受到了{$fallDamage}点摔伤。" . HTML_NOR;
        } else {
            $selfMsg .= " 你轻盈地落到百花丛中。";
        }
        $selfMsg .= HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'room');

        // 到达广播
        $arriveMsg = HTML_HIYEL . "只听「嗖——」的一声，{$charName}从天而降，跌落在花丛中。" . HTML_NOR;
        MessageDaemon::broadcastToRoom($targetRoom, $arriveMsg, $charId, 'room');

        return [
            'success' => true,
            'type' => 'move',
            'message' => $selfMsg,
            'redirect' => room_url($targetArea, $targetRoom),
            'new_area' => $targetArea,
            'new_room' => $targetRoom
        ];
    }
}
