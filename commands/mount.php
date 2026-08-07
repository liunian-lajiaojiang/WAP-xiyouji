<?php

require_once __DIR__ . '/../helpers/TempStateHelper.php';
require_once __DIR__ . '/../models/Npc.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../models/Room.php';
require_once __DIR__ . '/../includes/db.php';

class MountCommand {
    public static function execute($characterId, $params) {
        $character = Character::find($characterId);
        if (!$character) {
            return ['success' => false, 'message' => '角色不存在'];
        }

        $mountName = trim(implode(' ', $params));
        
        if (empty($mountName)) {
            return ['success' => false, 'message' => '你想骑什么？'];
        }

        $currentRoom = Room::findByKey($character['current_room']);
        if (!$currentRoom) {
            return ['success' => false, 'message' => '当前房间不存在'];
        }

        $existingMount = TempStateHelper::get($characterId, 'ride/mounted');
        if ($existingMount) {
            return ['success' => false, 'message' => '你已经骑在坐骑上了'];
        }

        // 先检查玩家是否有专属坐骑
        $ownedMount = TempStateHelper::get($characterId, 'ride/owned_mount');
        if ($ownedMount) {
            if (isset($ownedMount['npc_name'])) {
                // 如果玩家输入的是自己坐骑的名字，或者输入"坐骑"，就直接召唤
                if (stripos($ownedMount['npc_name'], $mountName) !== false || $mountName === '坐骑' || $mountName === 'my mount') {
                    $mountData = [
                        'npc_id' => $ownedMount['npc_id'],
                        'npc_name' => $ownedMount['npc_name'],
                        'dodge_bonus' => $ownedMount['dodge_bonus'],
                        'mount_time' => time()
                    ];
                    
                    TempStateHelper::set($characterId, 'ride/mounted', $mountData);
                    
                    return [
                        'success' => true,
                        'message' => '你召唤出' . $ownedMount['npc_name'] . '，翻身骑了上去，你的闪避增加了 ' . $ownedMount['dodge_bonus'] . ' 点。',
                        'broadcast' => $character['name'] . '召唤出' . $ownedMount['npc_name'] . '，翻身骑了上去。',
                        'effects' => [
                            'dodge_bonus' => $ownedMount['dodge_bonus']
                        ]
                    ];
                }
            }
        }

        $roomNpcs = Npc::findByRoom($character['current_area'], $character['current_room']);
        
        $targetNpc = null;
        foreach ($roomNpcs as $npc) {
            if (stripos($npc['name'], $mountName) !== false || stripos($npc['npc_id'], $mountName) !== false) {
                $targetNpc = $npc;
                break;
            }
        }

        if (!$targetNpc) {
            return ['success' => false, 'message' => "这里没有名为'{$mountName}'的坐骑"];
        }

        if (empty($targetNpc['ride_msg'])) {
            return ['success' => false, 'message' => "{$targetNpc['name']}不能被骑乘"];
        }

        if ($targetNpc['ride_female_only'] && $character['gender'] !== 'female') {
            return ['success' => false, 'message' => "{$targetNpc['name']}仅限女性骑乘"];
        }

        if ($targetNpc['ride_owner_id'] && $targetNpc['ride_owner_id'] != $characterId) {
            return ['success' => false, 'message' => "{$targetNpc['name']}是别人的专属坐骑"];
        }

        $mountData = [
            'npc_id' => $targetNpc['id'],
            'npc_name' => $targetNpc['name'],
            'dodge_bonus' => $targetNpc['ride_dodge'],
            'mount_time' => time()
        ];

        TempStateHelper::set($characterId, 'ride/mounted', $mountData);

        // 取消坐骑的跟随状态
        if (isset($targetNpc['id'])) {
            Database::execute(
                "DELETE FROM npc_temp WHERE npc_id = ? AND temp_key = 'leader'",
                [$targetNpc['id']]
            );
        }

        return [
            'success' => true,
            'message' => $targetNpc['ride_msg'] . '，你的闪避增加了 ' . $targetNpc['ride_dodge'] . ' 点。',
            'broadcast' => $character['name'] . $targetNpc['ride_msg'] . '。',
            'effects' => [
                'dodge_bonus' => $targetNpc['ride_dodge']
            ]
        ];
    }
}
?>