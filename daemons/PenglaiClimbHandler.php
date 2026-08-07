<?php
/**
 * PenglaiClimbHandler - 蓬莱青石崖攀爬
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/MessageDaemon.php';
require_once __DIR__ . '/../models/Character.php';

class PenglaiClimbHandler extends ActionHandler {

    /**
     * 执行攀爬动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        $character = $this->getCharacter($charId);
        if (!$character) {
            return ['success' => false, 'message' => '角色不存在'];
        }

        $currentRoom = $character['current_room'];
        $config = !empty($action['config']) ? (is_string($action['config']) ? json_decode($action['config'], true) : $action['config']) : [];
        $direction = $config['direction'] ?? 'up';
        $targetRoom = $config['target_room'] ?? '';
        $targetArea = $config['target_area'] ?? 'penglai';

        if ($direction === 'down') {
            // 下高崖：从yazhong回到yaxia，简单无判定
            return $this->doClimbDown($charId, $character, $targetRoom, $targetArea);
        }

        return $this->doClimbUp($charId, $character, $currentRoom, $config);
    }

    /**
     * 爬高崖（向上）
     */
    private function doClimbUp(int $charId, array $character, string $currentRoom, array $config): array {
        $targetRoom = $config['target_room'] ?? '';
        $targetArea = $config['target_area'] ?? 'penglai';
        $failRoom = $config['fail_room'] ?? 'penglai/road1';
        $failArea = $config['fail_area'] ?? 'penglai';

        $charName = $character['name'];

        // 检查技能要求
        $dodge = intval($character['dodge'] ?? 0);
        $unarmed = intval($character['unarmed'] ?? 0);
        $kee = intval($character['kee'] ?? 0);
        $sen = intval($character['sen'] ?? 0);
        $kar = intval($character['kar'] ?? 0);

        $requiredDodge = intval($config['required_dodge'] ?? 150);
        $requiredUnarmed = intval($config['required_unarmed'] ?? 150);
        $requiredKee = intval($config['required_kee'] ?? 500);
        $requiredSen = intval($config['required_sen'] ?? 500);

        // 技能不够
        if ($dodge + $unarmed < $requiredDodge + $requiredUnarmed) {
            return ['success' => false, 'message' => '你手脚笨拙，攀爬不上这高崖。'];
        }

        // 精气神不够
        if ($kee < $requiredKee) {
            return ['success' => false, 'message' => '你现在气血不足，无力攀爬。'];
        }
        if ($sen < $requiredSen) {
            return ['success' => false, 'message' => '你现在心神不宁，无法专注攀爬。'];
        }

        // 攀爬广播消息
        $climbMsg = HTML_HIYEL . "{$charName}深吸一口气，开始攀爬高崖……" . HTML_NOR;
        MessageDaemon::broadcastToRoom($currentRoom, $climbMsg, $charId, 'room');

        // 福缘判定（仅对yazhong→yashang有随机失败判定）
        $hasFuYuan = false;
        if ($targetRoom === 'penglai/yashang') {
            // 福缘≥30必定成功，否则随机失败概率
            if ($kar >= 30) {
                $hasFuYuan = true;
            } else {
                $failChance = max(5, (30 - $kar) * 3); // 福缘越低失败率越高，最高85%
                if (mt_rand(1, 100) <= $failChance) {
                    return $this->handleClimbFail($charId, $character, $currentRoom, $failRoom, $failArea);
                }
            }
        }

        // 扣减气血和心神
        $costKee = intval($config['cost_kee'] ?? 100);
        $costSen = intval($config['cost_sen'] ?? 50);
        Database::execute(
            "UPDATE characters SET kee = GREATEST(0, kee - ?), sen = GREATEST(0, sen - ?) WHERE id = ?",
            [$costKee, $costSen, $charId]
        );

        // 移动到目标房间
        CharacterModel::updatePosition($charId, $targetArea, $targetRoom);

        // 给自己的消息
        $selfMsg = HTML_HIYEL . "你手脚并用，奋力向上攀爬，终于到了更高处。" . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'room');

        // 给新房间的到达消息
        $arriveMsg = HTML_HIYEL . "{$charName}从下面攀爬了上来。" . HTML_NOR;
        MessageDaemon::broadcastToRoom($targetRoom, $arriveMsg, $charId, 'room');

        // 如果目标是yashang，触发琼草生长
        if ($targetRoom === 'penglai/yashang') {
            require_once __DIR__ . '/QiongcaoHandler.php';
            $qiongcaoHandler = new QiongcaoHandler();
            $qiongcaoHandler->tryStartGrowth('penglai/yashang');
        }

        return [
            'success' => true,
            'type' => 'move',
            'message' => $selfMsg,
            'redirect' => room_url($targetArea, $targetRoom),
            'new_area' => $targetArea,
            'new_room' => $targetRoom
        ];
    }

    /**
     * 下高崖
     */
    private function doClimbDown(int $charId, array $character, string $targetRoom, string $targetArea): array {
        $charName = $character['name'];
        $currentRoom = $character['current_room'];

        $downMsg = HTML_HIYEL . "{$charName}小心翼翼地攀着崖壁向下爬去。" . HTML_NOR;
        MessageDaemon::broadcastToRoom($currentRoom, $downMsg, $charId, 'room');

        CharacterModel::updatePosition($charId, $targetArea, $targetRoom);

        $selfMsg = HTML_HIYEL . "你小心翼翼地攀着崖壁向下爬，终于回到了崖下。" . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'room');

        $arriveMsg = HTML_HIYEL . "{$charName}从上面攀爬了下来。" . HTML_NOR;
        MessageDaemon::broadcastToRoom($targetRoom, $arriveMsg, $charId, 'room');

        return [
            'success' => true,
            'type' => 'move',
            'message' => $selfMsg,
            'redirect' => room_url($targetArea, $targetRoom),
            'new_area' => $targetArea,
            'new_room' => $targetRoom
        ];
    }

    /**
     * 攀爬失败处理
     */
    private function handleClimbFail(int $charId, array $character, string $currentRoom, string $failRoom, string $failArea): array {
        $charName = $character['name'];

        // 失败广播
        $failBroadcast = HTML_HIYEL . "{$charName}脚下一滑，从崖壁上摔了下来！" . HTML_NOR;
        MessageDaemon::broadcastToRoom($currentRoom, $failBroadcast, $charId, 'room');

        // 摔伤：扣减气血到1%（最低保留）
        $currentKee = intval($character['kee'] ?? 1000);
        $newKee = max(1, intval($currentKee * 0.1));
        Database::execute("UPDATE characters SET kee = ? WHERE id = ?", [$newKee, $charId]);

        // 移动到失败房间
        CharacterModel::updatePosition($charId, $failArea, $failRoom);

        // 设置昏迷状态
        Database::execute(
            "UPDATE characters SET unconscious_state = 1, unconscious_end_time = ? WHERE id = ?",
            [time() + 30, $charId]
        );
        $_SESSION["unconscious_{$charId}"] = [
            'timestamp' => time(),
            'duration' => 30
        ];

        $selfMsg = HTML_HIRED . "你脚下一滑，从崖壁上直摔下来！重重地跌在地上，昏了过去。" . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'room');

        $arriveMsg = HTML_HIYEL . "只听得一声闷响，{$charName}从崖壁上摔了下来，倒在地上昏迷不醒。" . HTML_NOR;
        MessageDaemon::broadcastToRoom($failRoom, $arriveMsg, $charId, 'room');

        return [
            'success' => true,
            'type' => 'move',
            'message' => $selfMsg,
            'redirect' => room_url($failArea, $failRoom),
            'new_area' => $failArea,
            'new_room' => $failRoom
        ];
    }
}
