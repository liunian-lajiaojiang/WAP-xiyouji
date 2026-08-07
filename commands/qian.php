<?php

require_once __DIR__ . '/../helpers/TempStateHelper.php';
require_once __DIR__ . '/../models/Npc.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../models/Room.php';
require_once __DIR__ . '/../includes/db.php';

class QianCommand {
    public static function execute($characterId, $params) {
        $character = Character::find($characterId);
        if (!$character) {
            return ['success' => false, 'message' => '角色不存在'];
        }

        $horseName = trim(implode(' ', $params));
        
        if (empty($horseName)) {
            return ['success' => false, 'message' => '你想牵什么？'];
        }

        $currentRoom = Room::findByKey($character['current_room']);
        if (!$currentRoom) {
            return ['success' => false, 'message' => '当前房间不存在'];
        }

        // 检查是否已经骑乘
        $existingMount = TempStateHelper::get($characterId, 'ride/mounted');
        if ($existingMount) {
            return ['success' => false, 'message' => '你已经骑在坐骑上了，先下马再牵吧。'];
        }

        // 查找房间里的小马驹
        $roomNpcs = Npc::findByRoom($character['current_area'], $character['current_room']);
        
        $targetNpc = null;
        foreach ($roomNpcs as $npc) {
            if (stripos($npc['name'], $horseName) !== false || stripos($npc['npc_id'], $horseName) !== false) {
                $targetNpc = $npc;
                break;
            }
        }

        if (!$targetNpc) {
            return ['success' => false, 'message' => "这里没有名为'{$horseName}'的小马驹"];
        }

        // 检查是不是小马驹
        if ($targetNpc['npc_id'] !== 'mount_xiaomaju' && $targetNpc['name'] !== '小马驹') {
            return ['success' => false, 'message' => "{$targetNpc['name']}不是小马驹，不需要牵。"];
        }

        // 检查是否已经长大了
        $growthLevel = 0;
        $growthData = Database::queryOne(
            "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'growth_level'",
            [$targetNpc['id']]
        );
        if ($growthData && !empty($growthData['temp_value'])) {
            $growthLevel = (int)$growthData['temp_value'];
        }

        if ($growthLevel >= 10) {
            return ['success' => false, 'message' => "{$targetNpc['name']}已经长大了，可以直接骑乘了。"];
        }

        // 检查玩家气血
        $keeCost = 50;
        if ($character['kee'] < $keeCost) {
            return ['success' => false, 'message' => '你的气血不足，无法牵马。'];
        }

        // 消耗玩家气血
        Database::execute(
            "UPDATE characters SET kee = kee - ? WHERE id = ?",
            [$keeCost, $characterId]
        );

        // 随机成长
        $growthChance = 20; // 20%概率成长
        $random = rand(1, 100);

        if ($random <= $growthChance) {
            // 成长了
            $newGrowthLevel = $growthLevel + 1;
            
            Database::execute(
                "INSERT INTO npc_temp (npc_id, temp_key, temp_value) VALUES (?, 'growth_level', ?) ON DUPLICATE KEY UPDATE temp_value = ?",
                [$targetNpc['id'], $newGrowthLevel, $newGrowthLevel]
            );

            if ($newGrowthLevel >= 10) {
                // 完全长大了
                // 更新闪避加成
                Database::execute(
                    "UPDATE npcs SET ride_dodge = 20, ride_msg = '骑上大宛马' WHERE id = ?",
                    [$targetNpc['id']]
                );

                return [
                    'success' => true,
                    'message' => "你牵着小马驹溜了一圈，它突然一声嘶鸣，奋蹄疾奔！它长大了！现在你可以骑乘它了。",
                    'broadcast' => $character['name'] . "牵着小马驹溜了一圈，小马驹突然长大了！"
                ];
            } else {
                return [
                    'success' => true,
                    'message' => "你牵着小马驹溜了一圈，它看起来精神了一些。（成长进度：{$newGrowthLevel}/10）",
                    'broadcast' => $character['name'] . "牵着小马驹溜了一圈。"
                ];
            }
        } else {
            // 没有成长
            return [
                'success' => true,
                'message' => "你牵着小马驹溜了一圈，但它依然一副无精打采的样子。（成长进度：{$growthLevel}/10）",
                'broadcast' => $character['name'] . "牵着小马驹溜了一圈。"
            ];
        }
    }
}
?>
