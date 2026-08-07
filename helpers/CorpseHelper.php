<?php
/**
 * 尸体拾取辅助类
 * 为AI玩家和自动系统提供CLI模式下的尸体拾取能力
 * 
 * 与 web 界面的 corpse.php 不同，此类直接调用模型层，无需 session
 */

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../models/Corpse.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/MoneyHelper.php';
require_once __DIR__ . '/WeightHelper.php';
require_once __DIR__ . '/../models/Item.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../daemons/MessageDaemon.php';

class CorpseHelper {

    /**
     * 拾取指定尸体的货币（自动拾取白银/黄金/铜钱）
     * 装备物品不自动拾取，留在尸体中供玩家手动拾取
     * 
     * @param int $charId 角色ID
     * @param int $corpseId 尸体ID
     * @return array 拾取结果 ['success' => bool, 'looted' => items[], 'messages' => string[]]
     */
    public static function lootCorpseCurrency(int $charId, int $corpseId): array {
        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'looted' => [], 'messages' => ['角色不存在']];
        }

        $corpse = Corpse::find($corpseId);
        if (!$corpse) {
            return ['success' => false, 'looted' => [], 'messages' => ['尸体不存在']];
        }

        // 检查角色是否在尸体旁边（同一房间或正在背着）
        $currentArea = $char['current_area'] ?? '';
        $currentRoom = $char['current_room'] ?? '';
        $isCarriedByMe = Corpse::isCarriedBy($corpseId, $charId);
        $isInSameRoom = (intval($corpse['carried']) === 0
            && $corpse['room_area'] === $currentArea
            && $corpse['room_id'] === $currentRoom);

        if (!$isCarriedByMe && !$isInSameRoom) {
            return ['success' => false, 'looted' => [], 'messages' => ['你不在尸体旁边']];
        }

        // 检查尸体是否已被拾取
        if (!empty($corpse['looted'])) {
            return ['success' => false, 'looted' => [], 'messages' => ['尸体已被搜刮']];
        }

        $items = Corpse::getItems($corpseId);
        $looted = [];
        $messages = [];
        $currencyLooted = false;

        foreach ($items as $item) {
            $itemId = $item['item_id'];
            $quantity = intval($item['quantity'] ?? 1);
            $itemName = $item['item_name'] ?? $itemId;
            $itemType = $item['item_type'] ?? '';

            // 只自动拾取货币类物品
            $isCurrency = in_array($itemId, ['gold', 'silver', 'coin', 'copper'])
                || $itemType === 'currency';

            if (!$isCurrency) {
                // 非货币物品，跳过（留在尸体中）
                continue;
            }

            // 防重复拾取
            if (!Corpse::itemStillInCorpse($corpseId, $item['id'])) {
                continue;
            }

            // 货币直接加钱（copper/coin 都是铜钱单位）
            if ($itemId === 'gold') {
                MoneyHelper::addMoney($charId, $quantity * 10000);
                $msg = "你从{$corpse['owner_name']}的尸体中搜出了 {$quantity} 两黄金！";
            } elseif ($itemId === 'silver') {
                MoneyHelper::addMoney($charId, $quantity * 100);
                $msg = "你从{$corpse['owner_name']}的尸体中搜出了 {$quantity} 两白银！";
            } else {
                // coin / copper → 铜钱
                MoneyHelper::addMoney($charId, $quantity);
                $msg = "你从{$corpse['owner_name']}的尸体中搜出了 {$quantity} 铜钱！";
            }

            Corpse::removeItem($item['id']);
            $looted[] = [
                'item_id' => $itemId,
                'item_name' => $itemName,
                'quantity' => $quantity,
                'type' => 'currency',
            ];
            $messages[] = $msg;
            $currencyLooted = true;
        }

        // 如果拾取了货币，发送消息
        if ($currencyLooted) {
            // 合并消息为一个
            $totalSilver = 0;
            $totalGold = 0;
            $totalCoin = 0;
            foreach ($looted as $l) {
                if ($l['item_id'] === 'silver') $totalSilver += $l['quantity'];
                elseif ($l['item_id'] === 'gold') $totalGold += $l['quantity'];
                else $totalCoin += $l['quantity'];
            }
            $parts = [];
            if ($totalGold > 0) $parts[] = "{$totalGold}两黄金";
            if ($totalSilver > 0) $parts[] = "{$totalSilver}两白银";
            if ($totalCoin > 0) $parts[] = "{$totalCoin}铜钱";
            $lootMsg = HTML_HIGRN . '你搜刮了' . implode('、', $parts) . '！' . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $lootMsg, 'loot');
        }

        return [
            'success' => true,
            'looted' => $looted,
            'messages' => $messages,
        ];
    }

    /**
     * 拾取房间内所有自己击杀的尸体货币
     * 
     * @param int $charId 角色ID
     * @param string $area 区域
     * @param string $roomId 房间ID
     * @return array ['success' => bool, 'total_looted' => int, 'corpse_count' => int]
     */
    public static function lootCorpsesInRoom(int $charId, string $area, string $roomId): array {
        $corpses = Corpse::getCorpsesInRoom($area, $roomId);
        $totalLooted = 0;
        $corpseCount = 0;

        foreach ($corpses as $corpse) {
            // 只拾取自己击杀的尸体（killer_id = charId）
            if (intval($corpse['killer_id']) !== $charId) {
                continue;
            }

            $result = self::lootCorpseCurrency($charId, intval($corpse['id']));
            if ($result['success'] && count($result['looted']) > 0) {
                $totalLooted += count($result['looted']);
                $corpseCount++;
            }
        }

        return [
            'success' => true,
            'total_looted' => $totalLooted,
            'corpse_count' => $corpseCount,
        ];
    }
}
