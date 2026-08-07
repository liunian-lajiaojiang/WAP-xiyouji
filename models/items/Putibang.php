<?php
namespace XYJ\Models\Items;

use XYJ\Helpers\Character;
use XYJ\Helpers\Inventory;

/**
 * 菩提杖物品模型
 * 实现菩提杖的特殊效果和使用逻辑
 */
class Putibang {
    
    /**
     * 使用菩提杖效果
     * @param int $playerId 玩家ID
     * @return array 使用结果
     */
    public static function usePutiBang(int $playerId): array {
        $playerData = Character::getPlayerData($playerId);
        if (!$playerData) {
            return ['success' => false, 'message' => '玩家数据不存在'];
        }
        
        // 检查是否拥有菩提杖
        $items = Inventory::getPlayerItems($playerId);
        $hasPutiBang = false;
        
        foreach ($items as $item) {
            if ($item['item_id'] === 'putibang') {
                $hasPutiBang = true;
                break;
            }
        }
        
        if (!$hasPutiBang) {
            return ['success' => false, 'message' => '你没有菩提杖'];
        }
        
        // 使用菩提杖
        $effect = json_decode($items[0]['stats'], true);
        
        // 应用效果到角色
        switch ($effect['type']) {
            case 'heal':
                Health::recoverHealth($playerId, $effect['value']);
                break;
                
            case 'buff':
                Buff::addBuff($playerId, $effect['buff_id'], $effect['duration']);
                break;
                
            case 'attack_boost':
                Combat::boostAttack($playerId, $effect['value'], $effect['duration']);
                break;
                
            default:
                break;
        }
        
        // 消耗菩提杖
        Inventory::removeItem($playerId, 'putibang', 1);
        
        return [
            'success' => true,
            'effect' => $effect,
            'message' => "使用菩提杖成功，获得效果：{$effect['description']}"
        ];
    }
    
    /**
     * 获取菩提杖效果数据
     */
    public static function getEffectData(): array {
        return [
            'type' => 'heal',
            'value' => 100,
            'description' => '恢复100点生命',
            'duration' => 10
        ];
    }
}