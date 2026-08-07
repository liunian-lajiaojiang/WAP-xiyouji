<?php
/**
 * Jump Arena Handler
 * 
 * 跳下擂台处理器
 * 将玩家从擂台(city/misc/leitai)移回观礼台(city/misc/kantai)
 */

require_once __DIR__ . '/ActionHandler.php';

class JumpArenaHandler extends ActionHandler {

    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在', 'data' => null];
            }

            // 移动角色到观礼台
            Database::execute(
                "UPDATE characters SET current_area = 'city', current_room = 'city/misc/kantai' WHERE id = ?",
                [$charId]
            );

            $name = $character['name'] ?? '你';

            // 广播跳下擂台消息到擂台
            MessageDaemon::broadcastToRoom(
                $character['current_room'],
                HTML_HIYEL . $name . '纵身一跃，跳下了擂台。' . HTML_NOR,
                $charId
            );

            return [
                'success' => true,
                'message' => HTML_HIYEL . '你纵身一跃，跳下了擂台，回到了观礼台。' . HTML_NOR,
                'redirect' => 'room.php?area=city&room=' . urlencode('city/misc/kantai')
            ];

        } catch (\Exception $e) {
            error_log("JumpArenaHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '跳下擂台失败', 'data' => null];
        }
    }
}
