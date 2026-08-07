<?php
/**
 * PenglaiPickFlowerHandler - 蓬莱百花谷采花
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 *
 * 所有游戏数据从 room_actions.config (JSON) 读取，PHP 无硬编码值。
 * 在百花谷中采摘鲜花，随机获得不同种类的花卉。
 */
require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/MessageDaemon.php';
require_once __DIR__ . '/../models/Item.php';

class PenglaiPickFlowerHandler extends ActionHandler {

    /**
     * 仅返回结构骨架，所有实际值从数据库 config JSON 读取。
     * 任何等于 0 / '' / [] 的值表示"未从数据库加载"。
     */
    public function getDefaultConfig(): array {
        return [
            'success_rate' => 0,
            'flowers'      => [],
            'empty_msgs'   => [],
            'success_msgs' => [],
            'broadcast'    => ['empty' => '', 'success' => ''],
            'msgs'         => [
                'config_error' => '采花系统配置错误，请联系管理员处理。',
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
        $flowers = $cfg['flowers'];
        if (empty($flowers) || ($cfg['success_rate'] ?? 0) <= 0) {
            return ['success' => false, 'message' => $msgs['config_error'] ?? '配置缺失'];
        }

        $currentRoom = $character['current_room'] ?? '';
        if (!preg_match('/^penglai\/baihuagu\d+$/', $currentRoom)) {
            return ['success' => false, 'message' => $msgs['not_here'] ?? '这里没有花可以采。'];
        }

        $charName  = $character['name'];
        $broadcast = $cfg['broadcast'];

        // 计算总权重
        $totalWeight = 0;
        foreach ($flowers as $f) {
            $totalWeight += intval($f['w'] ?? 0);
        }

        // 按成功率判定是否空手
        $rate = floatval($cfg['success_rate'] ?? 0);
        if ($totalWeight <= 0 || mt_rand(1, 100) > $rate * 100) {
            $emptyMsgs = $cfg['empty_msgs'];
            $msg = $emptyMsgs[array_rand($emptyMsgs)] ?? ($emptyMsgs[0] ?? '什么也没找到。');
            $selfMsg = HTML_HIYEL . $msg . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'room');

            $broadcastMsg = HTML_HIYEL
                . str_replace('{name}', $charName, $broadcast['empty'] ?? '{name}在花丛中寻找着什么。')
                . HTML_NOR;
            MessageDaemon::broadcastToRoom($currentRoom, $broadcastMsg, $charId, 'room');

            return ['success' => true, 'message' => $selfMsg];
        }

        // 按权重随机选择花卉
        $roll = mt_rand(1, max($totalWeight, 1));
        $cumulative = 0;
        $picked = null;
        foreach ($flowers as $f) {
            $cumulative += intval($f['w'] ?? 0);
            if ($roll <= $cumulative) {
                $picked = $f;
                break;
            }
        }
        if (!$picked) {
            $picked = $flowers[0];
        }

        $itemId   = $picked['id'];
        $itemName = $picked['name'] ?? $itemId;
        $category = $picked['cat'] ?? 'obj';

        // 给予玩家花卉
        try {
            ItemModel::addToInventory($charId, $itemId, 1, $category);
        } catch (\Exception $e) {
            error_log("PenglaiPickFlowerHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => $msgs['error'] ?? '操作失败，请稍后再试。'];
        }

        $successMsgs = $cfg['success_msgs'];
        $template = $successMsgs[array_rand($successMsgs)] ?? ($successMsgs[0] ?? '你采到了一朵{item_name}。');
        $selfMsg = HTML_HIYEL . str_replace('{item_name}', $itemName, $template) . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'room');

        $broadcastMsg = HTML_HIYEL
            . str_replace(['{name}', '{item_name}'], [$charName, $itemName], $broadcast['success'] ?? '{name}采到了一朵{item_name}。')
            . HTML_NOR;
        MessageDaemon::broadcastToRoom($currentRoom, $broadcastMsg, $charId, 'room');

        return ['success' => true, 'message' => $selfMsg];
    }
}
