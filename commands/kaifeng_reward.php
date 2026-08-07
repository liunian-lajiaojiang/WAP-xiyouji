<?php
/**
 * 赴京请赏命令 - 完成开封解谜任务后积累品德值(quest_reward)，赴京进宫请赏
 * 用法: reward / 请赏
 * 
 * 原始LPC还原：
 *   - 使用 characters.quest_reward（每次回访领奖时累加的品德值）
 *   - 祥云颜色数量决定倍率（1色=1倍, 3色=3倍, 5色=10倍, 7色=25倍）
 *   - reward_point = quest_reward * 倍率 / 6
 *   - 大臣按原始概率分配（段志贤53%/徐茂功47%/杜如晦3%/张士衡1%/孟子如2%）
 *   - 必须在皇宫（北京区域）
 */

function cmd_kaifeng_reward(int $charId, string $param = ''): array {
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../models/Character.php';
    require_once __DIR__ . '/../helpers/QuestHelper.php';
    
    // 获取角色信息
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    // 检查玩家是否在皇宫（北京区域）
    $area = $char['current_area'] ?? '';
    if ($area !== 'beijing' && $area !== '皇宫' && $area !== 'palace' && $area !== 'huanggong') {
        return ['success' => false, 'message' => '你必须在皇宫大殿之上，方能面圣请赏。'];
    }

    // 委托给 QuestHelper::calculateBeijingReward（已按原始LPC还原）
    $result = QuestHelper::calculateBeijingReward($charId);
    return $result;
}
