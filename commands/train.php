<?php

require_once __DIR__ . '/../helpers/TempStateHelper.php';
require_once __DIR__ . '/../models/Npc.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../models/Room.php';
require_once __DIR__ . '/../includes/db.php';

// 加载任务与技能配置
static $_questCfg = null;
if ($_questCfg === null) {
    $_questCfg = require __DIR__ . '/../config/quest.php';
}

class TrainCommand {
    public static function execute($characterId, $params) {
        $character = Character::find($characterId);
        if (!$character) {
            return ['success' => false, 'message' => '角色不存在'];
        }

        $mountName = trim(implode(' ', $params));
        
        if (empty($mountName)) {
            return ['success' => false, 'message' => '你想驯服什么？'];
        }

        $currentRoom = Room::findByKey($character['current_room']);
        if (!$currentRoom) {
            return ['success' => false, 'message' => '当前房间不存在'];
        }

        // 检查是否已经骑乘
        $existingMount = TempStateHelper::get($characterId, 'ride/mounted');
        if ($existingMount) {
            return ['success' => false, 'message' => '你已经骑在坐骑上了，先下马再驯服吧。'];
        }

        // 查找房间里的坐骑
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

        // 检查是否可以骑乘
        if (empty($targetNpc['ride_msg'])) {
            return ['success' => false, 'message' => "{$targetNpc['name']}不能被骑乘，更无法驯服。"];
        }

        // 检查是否需要驯服
        if (empty($targetNpc['ride_need_train'])) {
            return ['success' => false, 'message' => "{$targetNpc['name']}不需要驯服，直接骑上去就行了。"];
        }

        // 检查是否已经有主人
        if (!empty($targetNpc['ride_owner_id'])) {
            if ($targetNpc['ride_owner_id'] == $characterId) {
                return ['success' => false, 'message' => "{$targetNpc['name']}已经是你的坐骑了。"];
            } else {
                return ['success' => false, 'message' => "{$targetNpc['name']}是别人的专属坐骑，你驯服不了。"];
            }
        }

        // 检查女性专属
        if ($targetNpc['ride_female_only'] && $character['gender'] !== 'female') {
            return ['success' => false, 'message' => "{$targetNpc['name']}只有女性才能驯服和骑乘。"];
        }

        // 检查玩家的战斗经验和道行
        $requiredExp = $_questCfg['train']['required_combat_exp'];
        $requiredDaoxing = $_questCfg['train']['required_daoxing'];

        if ($character['combat_exp'] < $requiredExp) {
            return ['success' => false, 'message' => "你的战斗经验不足，需要至少{$requiredExp}点才能驯服{$targetNpc['name']}。"];
        }

        if ($character['daoxing'] < $requiredDaoxing) {
            return ['success' => false, 'message' => "你的道行不足，需要至少{$requiredDaoxing}点才能驯服{$targetNpc['name']}。"];
        }

        // 计算驯服成功率
        $baseChance = $_questCfg['train']['base_chance'];
        $expBonus = floor(($character['combat_exp'] - $requiredExp) / 100000) * 10;
        $daoxingBonus = floor(($character['daoxing'] - $requiredDaoxing) / 50000) * 10;
        $successChance = min(90, $baseChance + $expBonus + $daoxingBonus);

        // 随机结果
        $random = rand(1, 100);
        
        if ($random <= $successChance) {
            // 驯服成功
            // 设置坐骑的主人
            Database::execute(
                "UPDATE npcs SET ride_owner_id = ? WHERE id = ?",
                [$characterId, $targetNpc['id']]
            );

            // 设置坐骑跟随玩家
            $currentLocation = json_encode([
                'area' => $character['current_area'],
                'room' => $character['current_room']
            ]);
            Database::execute(
                "INSERT INTO npc_temp (npc_id, temp_key, temp_value) VALUES (?, 'current_location', ?) ON DUPLICATE KEY UPDATE temp_value = ?",
                [$targetNpc['id'], $currentLocation, $currentLocation]
            );
            Database::execute(
                "INSERT INTO npc_temp (npc_id, temp_key, temp_value) VALUES (?, 'leader', ?) ON DUPLICATE KEY UPDATE temp_value = ?",
                [$targetNpc['id'], $characterId, $characterId]
            );

            return [
                'success' => true,
                'message' => "经过一番努力，你成功驯服了{$targetNpc['name']}！它乖乖地跟在你身后，成为了你的专属坐骑。",
                'broadcast' => $character['name'] . "成功驯服了{$targetNpc['name']}！"
            ];
        } else {
            // 驯服失败
            // 有一定概率被坐骑攻击
            $attackChance = 30;
            if ($targetNpc['attitude'] === 'aggressive') {
                $attackChance = 70;
            }

            if (rand(1, 100) <= $attackChance) {
                return [
                    'success' => false,
                    'message' => "驯服失败！{$targetNpc['name']}被激怒了，向你发起了攻击！",
                    'broadcast' => $character['name'] . "试图驯服{$targetNpc['name']}，但失败了，{$targetNpc['name']}被激怒了！",
                    'trigger_combat' => true,
                    'target_npc_id' => $targetNpc['id']
                ];
            } else {
                return [
                    'success' => false,
                    'message' => "驯服失败！{$targetNpc['name']}不屑地看了你一眼，走开了。",
                    'broadcast' => $character['name'] . "试图驯服{$targetNpc['name']}，但失败了。"
                ];
            }
        }
    }
}
?>
