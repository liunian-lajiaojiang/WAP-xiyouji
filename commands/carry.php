<?php
/**
 * 背起命令 (carry) - 背起尸体
 * 参考原始MUD逻辑：将尸体扛在肩上，可以带到其他地方埋葬
 */
require_once __DIR__ . '/../models/Corpse.php';
require_once __DIR__ . '/../models/Room.php';
require_once __DIR__ . '/../helpers/TempStateHelper.php';

// 加载任务配置
static $_questCfg = null;
if ($_questCfg === null) {
    $_questCfg = require __DIR__ . '/../config/quest.php';
}

function cmd_carry(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    // 检查是否已经背着尸体
    $carryingCorpse = TempStateHelper::get($charId, 'carrying_corpse');
    if ($carryingCorpse) {
        return ['success' => false, 'message' => '你已经背着一具尸体了！'];
    }

    // 获取当前房间
    $room = RoomModel::getFullInfo($char['current_area'], $char['current_room']);
    if (!$room) {
        return ['success' => false, 'message' => '当前位置无效'];
    }

    // 获取房间内的尸体
    $corpses = Corpse::getCorpsesInRoom($char['current_area'], $char['current_room']);
    if (empty($corpses)) {
        return ['success' => false, 'message' => '这里没有尸体可以背。'];
    }

    // 如果只有一具尸体，直接背起
    if (count($corpses) === 1) {
        $targetCorpse = $corpses[0];
    } else {
        // 有多具尸体，尝试匹配名称
        if (empty($param)) {
            $corpseNames = array_column($corpses, 'owner_name');
            return ['success' => false, 'message' => '这里有好几具尸体，你要背哪一具？' . implode('、', $corpseNames)];
        }

        // 按名称查找尸体
        $targetCorpse = null;
        foreach ($corpses as $corpse) {
            if (stripos($corpse['owner_name'], $param) !== false) {
                $targetCorpse = $corpse;
                break;
            }
        }

        if (!$targetCorpse) {
            return ['success' => false, 'message' => '没有找到名为"'.$param.'"的尸体。'];
        }
    }

    // 检查体力
    if (intval($char['kee']) < 30) {
        return ['success' => false, 'message' => '你的体力不足，无法背起尸体。'];
    }

    // 消耗体力
    Database::execute(
        "UPDATE characters SET kee = GREATEST(0, kee - 30) WHERE id = ?",
        [$charId]
    );

    // 记录背起的尸体（同时更新数据库，确保和bei命令一致）
    Corpse::carryCorpse($targetCorpse['id'], $charId);
    
    // 同时在TempStateHelper中记录（用于体力消耗等额外功能）
    TempStateHelper::set($charId, 'carrying_corpse', [
        'corpse_id' => $targetCorpse['id'],
        'owner_name' => $targetCorpse['owner_name'],
        'owner_type' => $targetCorpse['owner_type'],
        'owner_id' => $targetCorpse['owner_id']
    ], $_questCfg['expiry']['carry_default_seconds']);

    log_game('CARRY_CORPSE', "{$char['name']} 背起了 {$targetCorpse['owner_name']} 的尸体");

    return [
        'success' => true,
        'message' => "你费了好大的劲，终于把{$targetCorpse['owner_name']}的尸体扛在了肩上。\n",
        'broadcast_message' => "{$char['name']}扛起了{$targetCorpse['owner_name']}的尸体。\n"
    ];
}

function cmd_carry_stop(int $charId): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    // 检查是否背着尸体
    $carryingCorpse = TempStateHelper::get($charId, 'carrying_corpse');
    if (!$carryingCorpse) {
        return ['success' => false, 'message' => '你没有背着尸体。'];
    }

    // 将尸体放到当前房间
    $corpseId = $carryingCorpse['corpse_id'];
    
    // 使用Corpse::dropCorpse方法，确保和bei命令一致
    Corpse::dropCorpse($corpseId, $char['current_area'], $char['current_room']);
    
    // 清除状态
    TempStateHelper::delete($charId, 'carrying_corpse');

    log_game('DROP_CORPSE', "{$char['name']} 将 {$carryingCorpse['owner_name']} 的尸体放在了地上");

    return [
        'success' => true,
        'message' => "你将{$carryingCorpse['owner_name']}的尸体放在了地上。\n",
        'broadcast_message' => "{$char['name']}将{$carryingCorpse['owner_name']}的尸体放在了地上。\n"
    ];
}

function cmd_drop_corpse(int $charId): array {
    return cmd_carry_stop($charId);
}
