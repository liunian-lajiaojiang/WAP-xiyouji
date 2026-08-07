<?php
/**
 * 自动寻怪处理器
 */

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/../models/Character.php';

class AutoFindYaoguaiHandler extends ActionHandler {
    
    /**
     * 执行自动寻怪
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在'];
            }
            
            // 检查玩家背包内是否有水晶球
            $hasCrystalBall = false;
            try {
                $inventoryItem = Database::queryOne(
                    "SELECT ci.item_id, ci.category, gi.name 
                     FROM character_inventory ci 
                     LEFT JOIN items gi ON ci.item_id = gi.item_id AND ci.category = gi.category 
                     WHERE ci.char_id = ? AND ci.quantity > 0 AND ci.item_id = 'crystalball' 
                     LIMIT 1",
                    [$charId]
                );
                $hasCrystalBall = !empty($inventoryItem);
            } catch (Exception $e) {
                error_log("检查水晶球失败: " . $e->getMessage());
                $hasCrystalBall = false;
            }
            if (!$hasCrystalBall) {
                return [
                    'success' => false,
                    'message' => '你需要水晶球才能自动寻怪！'
                ];
            }

            // 查找玩家的灭妖任务
            require_once __DIR__ . '/MieyaoHandler.php';
            $yaoguai = Database::queryOne(
                "SELECT * FROM mieyao_yaoguai WHERE owner_id = ? AND is_killed = 0",
                [$charId]
            );

            if (!$yaoguai) {
                return [
                    'success' => false,
                    'message' => '你目前没有灭妖任务，请先去袁天罡那里领取任务。'
                ];
            }

            // 检查妖怪是否还存在（没过期）
            if (strtotime($yaoguai['expires_at']) < time()) {
                return [
                    'success' => false,
                    'message' => '你的任务妖怪已经消失了，请去袁天罡那里重新领取任务。'
                ];
            }

            // 获取房间信息 - room_id 保存的是 rooms 表的 room_id 字段
            $roomDbId = $yaoguai['room_id'];
            
            // 从 rooms 表获取完整的房间信息
            $roomInfo = Database::queryOne(
                "SELECT * FROM rooms WHERE room_id = ?",
                [$roomDbId]
            );
            
            if (!$roomInfo) {
                return [
                    'success' => false,
                    'message' => '妖怪所在的地点无法找到。'
                ];
            }

            $area = $roomInfo['area'];
            $fullRoomId = $roomInfo['room_id'];
            
            // 更新玩家位置
            CharacterModel::updatePosition($charId, $area, $fullRoomId);

            // 地点描述
            $placeDesc = $this->getPlaceDescription($area);
            
            // 构建重定向URL
            require_once __DIR__ . '/../includes/functions.php';
            $redirectUrl = room_url($area, $fullRoomId);
            
            return [
                'success' => true,
                'message' => "你念动咒语，化作一道流光飞向" . $placeDesc . "！",
                'redirect' => $redirectUrl
            ];
            
        } catch (\Exception $e) {
            error_log("AutoFindYaoguaiHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '自动寻怪失败: ' . $e->getMessage()];
        }
    }
    
    /**
     * 获取地点描述
     */
    private function getPlaceDescription(string $area): string {
        $areaNames = [
            'city' => '长安城',
            'westway' => '城西大道',
            'kaifeng' => '开封府',
            'lingtai' => '灵台方寸',
            'moon' => '月宫',
            'gao' => '高老庄',
            'sea' => '东海',
            'nanhai' => '南海',
            'eastway' => '城东大道',
            'xueshan' => '大雪山',
            'wuzhuang' => '五庄观',
            'death' => '地府',
            'meishan' => '梅山',
        ];

        return $areaNames[$area] ?? $area;
    }
}

