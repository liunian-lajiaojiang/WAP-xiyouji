<?php

require_once __DIR__ . '/../helpers/TempStateHelper.php';
require_once __DIR__ . '/../models/Character.php';

class DismountCommand {
    public static function execute($characterId, $params) {
        $character = Character::find($characterId);
        if (!$character) {
            return ['success' => false, 'message' => '角色不存在'];
        }

        $mountData = TempStateHelper::get($characterId, 'ride/mounted');
        if (!$mountData) {
            return ['success' => false, 'message' => '你没有骑在坐骑上'];
        }

        if (!isset($mountData['npc_name'])) {
            return ['success' => false, 'message' => '骑乘状态数据无效'];
        }

        TempStateHelper::delete($characterId, 'ride/mounted');

        // 设置坐骑跟随玩家
        if (isset($mountData['npc_id']) && is_numeric($mountData['npc_id'])) {
            $npcId = (int)$mountData['npc_id'];
            
            // 设置NPC的当前位置为玩家当前位置
            $currentLocation = json_encode([
                'area' => $character['current_area'],
                'room' => $character['current_room']
            ]);
            Database::execute(
                "INSERT INTO npc_temp (npc_id, temp_key, temp_value) VALUES (?, 'current_location', ?) ON DUPLICATE KEY UPDATE temp_value = ?",
                [$npcId, $currentLocation, $currentLocation]
            );
            
            // 设置NPC跟随玩家
            Database::execute(
                "INSERT INTO npc_temp (npc_id, temp_key, temp_value) VALUES (?, 'leader', ?) ON DUPLICATE KEY UPDATE temp_value = ?",
                [$npcId, $characterId, $characterId]
            );
        }

        return [
            'success' => true,
            'message' => '你从' . $mountData['npc_name'] . '上跳了下来，它乖乖地跟在你身后。',
            'broadcast' => $character['name'] . '从' . $mountData['npc_name'] . '上跳了下来。',
            'effects' => [
                'dodge_bonus' => -$mountData['dodge_bonus']
            ]
        ];
    }
}
