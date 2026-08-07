<?php
/**
 * PenglaiBrewHandler - 蓬莱百花窖酿酒
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 *
 * 所有游戏数据从 room_actions.config (JSON) 读取，PHP 无硬编码值。
 * 酿造进度通过 room_actions.config 中的 brew_count 持久化。
 */
require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/MessageDaemon.php';
require_once __DIR__ . '/../models/Item.php';

class PenglaiBrewHandler extends ActionHandler {

    /**
     * 仅返回结构骨架，所有实际值从数据库 config JSON 读取。
     * 任何等于 0 / '' / [] 的值表示"未从数据库加载"。
     */
    public function getDefaultConfig(): array {
        return [
            'brew_count'      => 0,
            'required_count'  => 0,
            'reward_item'     => '',
            'reward_category' => '',
            'stages'          => [],
            'msgs'            => [
                'config_error' => '酿酒系统配置错误，请联系管理员处理。',
            ],
        ];
    }

    public function execute(int $charId, array $action, array $params = []): array {
        $character = $this->getCharacter($charId);
        if (!$character) {
            return ['success' => false, 'message' => '角色不存在'];
        }

        $cfg  = $this->mergeConfig($this->getDefaultConfig(), $this->parseConfig($action));
        $msgs = $cfg['msgs'];

        // 验证配置是否完整加载
        $required = intval($cfg['required_count'] ?? 0);
        if ($required <= 0 || empty($cfg['reward_item'])) {
            return ['success' => false, 'message' => $msgs['config_error'] ?? '配置缺失'];
        }

        $roomId      = $action['room_id'] ?? '';
        $currentRoom = $character['current_room'];
        if ($currentRoom !== $roomId) {
            return ['success' => false, 'message' => $msgs['not_here'] ?? '你不在酿酒窖中。'];
        }

        $charName  = $character['name'];
        $flowerArg = $params['arg'] ?? '';
        if (empty($flowerArg)) {
            return ['success' => false, 'message' => $msgs['ask_flower'] ?? '请输入你要添加的花名。'];
        }

        // 搜索玩家背包中的花
        $inventory = Database::queryAll(
            "SELECT pi.item_id, pi.item_name, pi.quantity, i.type FROM player_items pi
             LEFT JOIN items i ON pi.item_id = i.item_id
             WHERE pi.char_id = ? AND (pi.item_id LIKE ? OR pi.item_name LIKE ? OR i.type = 'flower')",
            [$charId, "%{$flowerArg}%", "%{$flowerArg}%"]
        );
        $hasFlower = null;
        foreach ($inventory as $item) {
            if (
                stripos($item['item_name'] ?? '', $flowerArg) !== false ||
                stripos($item['item_id'] ?? '', $flowerArg) !== false ||
                ($item['type'] ?? '') === 'flower'
            ) {
                $hasFlower = $item;
                break;
            }
        }
        if (!$hasFlower) {
            return ['success' => false, 'message' => str_replace('{flower}', $flowerArg, $msgs['no_flower'] ?? '你没有这种花。')];
        }

        // 确认 fixed_objects 中百花酿存在（object_id 从 config 读取）
        $rewardItem = $cfg['reward_item'];
        $brewObj = Database::queryOne(
            "SELECT * FROM fixed_objects WHERE object_id = ?", [$rewardItem]
        );
        if (!$brewObj) {
            return ['success' => false, 'message' => $msgs['no_brew'] ?? '这里没有百花酿。'];
        }

        $brewCount = intval($cfg['brew_count'] ?? 0);
        if ($brewCount >= $required) {
            return ['success' => false, 'message' => $msgs['already_done'] ?? '酿造已完成。'];
        }

        // 扣除 1 朵花
        Database::execute(
            "UPDATE player_items SET quantity = quantity - 1 WHERE char_id = ? AND item_id = ? AND quantity > 0",
            [$charId, $hasFlower['item_id']]
        );
        Database::execute(
            "DELETE FROM player_items WHERE char_id = ? AND item_id = ? AND quantity <= 0",
            [$charId, $hasFlower['item_id']]
        );

        // 更新酿造进度到 room_actions.config（使用 $action 字段，无条件名硬编码）
        $newCount = $brewCount + 1;
        $cfg['brew_count'] = $newCount;
        Database::execute(
            "UPDATE room_actions SET config = ?
             WHERE room_id = ? AND handler_class = ?",
            [json_encode($cfg, JSON_UNESCAPED_UNICODE), $roomId, $action['handler_class'] ?? 'PenglaiBrewHandler']
        );

        $displayName = $hasFlower['item_name'] ?: $hasFlower['item_id'];

        // 构建自身消息
        $selfMsg = HTML_HIYEL
            . str_replace('{flower}', $displayName, $msgs['add_flower'] ?? '你放入了一朵花。');

        if ($newCount >= $required) {
            $stageMsg = $cfg['stages'][(string)$required] ?? '';
            $selfMsg .= "\n" . HTML_HICYN . $stageMsg . HTML_NOR;
            try {
                ItemModel::addToInventory($charId, $rewardItem, 1, $cfg['reward_category']);
            } catch (\Exception $e) {
                error_log("PenglaiBrewHandler reward error: " . $e->getMessage());
            }
        } else {
            $stageMsg = $cfg['stages'][(string)$newCount] ?? '';
            if ($stageMsg !== '') {
                $selfMsg .= "\n" . $stageMsg;
            }
        }
        $selfMsg .= HTML_NOR;

        MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'room');

        // 广播
        $broadcastMsg = HTML_HIYEL
            . str_replace(['{name}', '{flower}'], [$charName, $displayName], $msgs['broadcast'] ?? '{name}放入了一朵花。')
            . HTML_NOR;
        MessageDaemon::broadcastToRoom($currentRoom, $broadcastMsg, $charId, 'room');

        return ['success' => true, 'message' => $selfMsg];
    }
}
