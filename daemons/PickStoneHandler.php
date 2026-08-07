<?php
/**
 * 捡石子房间动作处理器
 * 在海边捡石子，用于填海
 */

require_once __DIR__ . '/ActionHandler.php';

class PickStoneHandler extends ActionHandler
{
    public function execute(int $charId, array $action, array $params = []): array
    {
        $char = $this->getCharacter($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }

        $config = $this->parseConfig($action);
        $costKee = $config['cost_kee'] ?? 20;
        $dropRate = $config['drop_rate'] ?? 0.7;
        $rewardItem = $config['reward_item'] ?? 'shi';
        $rewardCategory = $config['reward_category'] ?? 'weapon';
        $maxQuantity = $config['max_quantity'] ?? 3;

        if (intval($char['kee']) < $costKee) {
            return ['success' => false, 'message' => '你太累了，先歇会儿再捡吧。'];
        }

        if (is_player_busy($charId)) {
            return ['success' => false, 'message' => '你正忙着呢。'];
        }

        require_once DAEMON_PATH . 'CombatDaemon.php';
        if (CombatDaemon::isInCombat($charId)) {
            return ['success' => false, 'message' => '你正打着呢，哪有空捡石子！'];
        }

        Database::execute(
            "UPDATE characters SET kee = kee - ? WHERE id = ?",
            [$costKee, $charId]
        );

        if (mt_rand(1, 100) > ($dropRate * 100)) {
            return ['success' => true, 'message' => '你在海滩上翻来翻去，结果什么也没找到。'];
        }

        $quantity = mt_rand(1, $maxQuantity);

        require_once MODEL_PATH . 'Item.php';
        ItemModel::addToInventory($charId, $rewardItem, $quantity, $rewardCategory);

        $itemName = $this->getItemName($rewardItem);
        $message = "你在海滩上捡到了{$quantity}块{$itemName}！";

        $busyTime = 2 + mt_rand(0, 2);
        set_player_busy($charId, $busyTime);

        return [
            'success' => true,
            'message' => $message
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'cost_kee' => 20,
            'drop_rate' => 0.7,
            'reward_item' => 'shi',
            'reward_category' => 'weapon',
            'max_quantity' => 3
        ];
    }

    private function getItemName(string $itemId): string
    {
        $item = Database::queryOne(
            "SELECT name FROM items WHERE item_id = ? LIMIT 1",
            [$itemId]
        );
        return $item ? $item['name'] : '石子';
    }
}
