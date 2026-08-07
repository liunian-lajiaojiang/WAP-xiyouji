<?php
/**
 * Challenge Handler
 * 
 * 擂台挑战发起处理器
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 玩家在观礼台(kantai)可以向其他在线玩家发起擂台挑战
 */

require_once __DIR__ . '/ActionHandler.php';

class ChallengeHandler extends ActionHandler {

    /**
     * 执行发起挑战动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在', 'data' => null];
            }

            $targetId = intval($_GET['target'] ?? $_POST['target'] ?? 0);

            // 无目标：显示可挑战的在线玩家列表
            if ($targetId <= 0) {
                return $this->showChallengeList($charId, $action);
            }

            return $this->sendChallenge($charId, $targetId);

        } catch (\Exception $e) {
            error_log("ChallengeHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '发起挑战失败：' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * 显示可挑战的在线玩家列表
     */
    private function showChallengeList(int $charId, array $action): array {
        $config = $this->parseConfig($action);
        $title = $config['title'] ?? '=== 擂台挑战 ===';
        $noOnlineMessage = $config['no_online_message'] ?? "当前没有其他在线玩家可以挑战。\n等待更多高手来到观礼台吧！";

        // 获取其他在线玩家（排除自己）
        $players = Database::queryAll(
            "SELECT id, name, level, combat_exp, daoxing FROM characters 
             WHERE online = 1 AND id != ? AND kee > 0 
             ORDER BY daoxing DESC LIMIT 50",
            [$charId]
        );

        if (empty($players)) {
            return ['success' => true, 'message' => $noOnlineMessage];
        }

        // 检查是否已经有正在进行的挑战
        $myChallenge = Database::queryOne(
            "SELECT id, defender_id, status FROM arena_challenges 
             WHERE challenger_id = ? AND status = 'pending' 
             ORDER BY created_at DESC LIMIT 1",
            [$charId]
        );

        $challengedIds = [];
        if ($myChallenge) {
            $challengedIds[$myChallenge['defender_id']] = true;
        }

        $message = $title . "\n";
        $message .= "你可以向以下在线玩家发起挑战：\n";
        $message .= str_repeat("-", 40) . "\n";

        foreach ($players as $p) {
            $alreadyChallenged = isset($challengedIds[$p['id']]);
            $status = $alreadyChallenged ? ' [已挑战]' : '';
            $daoxing = number_format($p['daoxing'] ?? 0);
            $message .= sprintf("  %s (Lv.%d, 道行:%s)%s\n", $p['name'], $p['level'] ?? 1, $daoxing, $status);
        }

        $message .= "\n在玩家页面点击「挑战」即可发起擂台比武。";

        return ['success' => true, 'message' => $message];
    }

    /**
     * 发送挑战
     */
    private function sendChallenge(int $charId, int $targetId): array {
        // 不能挑战自己
        if ($charId === $targetId) {
            return ['success' => false, 'message' => '挑战自我，有志气！'];
        }

        // 检查目标玩家存在且在线
        $target = Database::queryOne(
            "SELECT id, name, online, kee FROM characters WHERE id = ?",
            [$targetId]
        );
        if (!$target) {
            return ['success' => false, 'message' => '该玩家不存在。'];
        }
        if (!$target['online']) {
            return ['success' => false, 'message' => $target['name'] . '当前离线，无法挑战。'];
        }
        if (($target['kee'] ?? 0) <= 0) {
            return ['success' => false, 'message' => $target['name'] . '已经无法战斗了。'];
        }

        // 检查自己是否已经向该玩家发起了挑战（pending）
        $existing = Database::queryOne(
            "SELECT id FROM arena_challenges 
             WHERE challenger_id = ? AND defender_id = ? AND status = 'pending'",
            [$charId, $targetId]
        );
        if ($existing) {
            return ['success' => false, 'message' => '你已经向' . $target['name'] . '发起了挑战，等待对方应战。'];
        }

        // 检查是否已经有别人向该玩家挑战（pending）
        $otherChallenge = Database::queryOne(
            "SELECT c.name FROM arena_challenges ac 
             JOIN characters c ON c.id = ac.challenger_id
             WHERE ac.defender_id = ? AND ac.status = 'pending'",
            [$targetId]
        );
        if ($otherChallenge) {
            return ['success' => false, 'message' => $target['name'] . '已被' . $otherChallenge['name'] . '挑战，请等待该挑战结束。'];
        }

        // 检查自己是否正在被挑战
        $beingChallenged = Database::queryOne(
            "SELECT c.name FROM arena_challenges ac 
             JOIN characters c ON c.id = ac.challenger_id
             WHERE ac.defender_id = ? AND ac.status = 'pending'",
            [$charId]
        );
        if ($beingChallenged) {
            return ['success' => false, 'message' => $beingChallenged['name'] . '正在向你挑战，请先应战(defend)。'];
        }

        // 检查自己是否已有进行中的挑战
        $myPending = Database::queryOne(
            "SELECT id, defender_id FROM arena_challenges 
             WHERE challenger_id = ? AND status = 'pending'",
            [$charId]
        );
        if ($myPending) {
            // 取消旧挑战
            Database::execute(
                "UPDATE arena_challenges SET status = 'cancelled' WHERE id = ?",
                [$myPending['id']]
            );
            // 通知旧对手
            $oldDefender = Database::queryOne("SELECT name FROM characters WHERE id = ?", [$myPending['defender_id']]);
            if ($oldDefender) {
                $challenger = CharacterModel::find($charId);
                $cancelMsg = ($challenger['name'] ?? '某人') . '取消了与你的擂台比武。';
                MessageDaemon::queueMessageToSelf($myPending['defender_id'], $cancelMsg, 'self_event');
            }
        }

        // 创建挑战记录
        Database::execute(
            "INSERT INTO arena_challenges (challenger_id, defender_id, status, created_at) 
             VALUES (?, ?, 'pending', NOW())",
            [$charId, $targetId]
        );

        $challenger = CharacterModel::find($charId);
        $challengerName = $challenger['name'] ?? '某人';

        // 通知被挑战者
        $notifyMsg = HTML_HIYEL . "【擂台挑战】" . HTML_NOR . " "
            . HTML_HICYN . $challengerName . HTML_NOR
            . "向你发起擂台挑战！\n"
            . "前往观礼台应战(defend)，或前往擂台接受比武。";
        MessageDaemon::queueMessageToSelf($targetId, $notifyMsg, 'self_event');

        // 构建返回消息
        $message = HTML_HIYEL . "【擂台挑战】" . HTML_NOR . " 你向"
            . HTML_HICYN . $target['name'] . HTML_NOR
            . "发起擂台挑战！\n"
            . "等待对方应战...对方需要到观礼台输入 defend 接受挑战。";

        return [
            'success' => true,
            'message' => $message,
            'redirect' => 'room.php?area=city&room=' . urlencode('city/misc/kantai')
        ];
    }
}
