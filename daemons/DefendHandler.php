<?php
/**
 * Defend Handler
 * 
 * 擂台应战处理器
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 被挑战者在观礼台接受挑战，双方传送到擂台进行比武
 */

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/ArenaDaemon.php';

class DefendHandler extends ActionHandler {

    /**
     * 执行应战动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在', 'data' => null];
            }

            // 获取挑战ID（从URL参数或POST）
            $challengeId = intval($_GET['challenge_id'] ?? $_POST['challenge_id'] ?? 0);

            // 如果没有指定挑战ID，检查是否有针对当前玩家的待处理挑战
            if ($challengeId <= 0) {
                return $this->showPendingChallenges($charId, $action);
            }

            return $this->acceptChallenge($charId, $challengeId);

        } catch (\Exception $e) {
            error_log("DefendHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '应战失败：' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * 显示待处理的挑战列表
     */
    private function showPendingChallenges(int $charId, array $action): array {
        $config = $this->parseConfig($action);
        $message = $config['message'] ?? "你摆开架势，准备迎接挑战！\n（等待其他玩家来挑战你...）";

        // 检查是否有针对自己的待处理挑战
        $pendingChallenge = ArenaDaemon::getPendingChallengeFor($charId);

        if ($pendingChallenge) {
            $challengerName = $pendingChallenge['challenger_name'] ?? '某人';
            $challengeTime = $pendingChallenge['created_at'] ?? '未知';

            $msg = HTML_HIYEL . "【擂台应战】" . HTML_NOR . "\n";
            $msg .= HTML_HICYN . $challengerName . HTML_NOR . "正在向你挑战！\n";
            $msg .= "发起时间: {$challengeTime}\n\n";
            $msg .= "点击「应战」接受挑战，前往擂台比武：\n";

            return [
                'success' => true,
                'message' => $msg,
                'html' => $this->buildDefendForm($pendingChallenge),
                
            ];
        }

        // 检查自己发起的挑战是否有人应战
        $myChallenge = ArenaDaemon::getPendingChallengeFrom($charId);
        if ($myChallenge) {
            $defenderName = $myChallenge['defender_name'] ?? '某人';
            return [
                'success' => true,
                'message' => "你已经向 {$defenderName} 发起了挑战，等待对方应战中...",
                
            ];
        }

        return ['success' => true, 'message' => $message, ];
    }

    /**
     * 构建应战表单（HTML）
     */
    private function buildDefendForm(array $challenge): string {
        $challengeId = (int)$challenge['id'];
        $challengerName = htmlspecialchars($challenge['challenger_name'] ?? '某人');

        return '
        <div style="padding: 10px; margin: 10px 0; background: #2d2d2d; border-radius: 5px; border: 1px solid #555;">
            <p style="color: #FFD700; font-weight: bold;">
                ' . $challengerName . ' 向你发起擂台挑战！
            </p>
            <p>
                <a href="action.php?action=defend&challenge_id=' . $challengeId . '&area=city&room=' . urlencode('city/misc/kantai') . '"
                   style="color: #00ff00; font-weight: bold; text-decoration: none; border: 1px solid #00ff00; padding: 5px 15px; border-radius: 3px;">
                    接受挑战(defend)
                </a>
                &ensp;
                <a href="javascript:void(0);" onclick="declineChallenge(' . $challengeId . ')"
                   style="color: #ff6666; text-decoration: none; border: 1px solid #ff6666; padding: 5px 15px; border-radius: 3px;">
                    拒绝
                </a>
            </p>
        </div>
        <script>
        function declineChallenge(id) {
            fetch("action.php?action=defend&decline=1&challenge_id=" + id, {
                headers: { "X-Requested-With": "XMLHttpRequest" }
            }).then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) location.reload();
                else alert(d.message || "操作失败");
            });
        }
        </script>';
    }

    /**
     * 接受挑战
     */
    private function acceptChallenge(int $charId, int $challengeId): array {
        // 检查拒绝操作
        if (!empty($_GET['decline'])) {
            return $this->declineChallenge($charId, $challengeId);
        }

        // 验证挑战存在且是针对当前玩家的
        $challenge = Database::queryOne(
            "SELECT * FROM arena_challenges WHERE id = ? AND status = 'pending'",
            [$challengeId]
        );

        if (!$challenge) {
            return ['success' => false, 'message' => '该挑战不存在或已过期。'];
        }

        if ((int)$challenge['defender_id'] !== $charId) {
            return ['success' => false, 'message' => '这个挑战不是针对你的。'];
        }

        // 检查擂台是否空闲
        if (ArenaDaemon::isArenaBusy()) {
            return ['success' => false, 'message' => '有人正在擂台上交手，请稍候。'];
        }

        // 检查双方是否都在线
        $challenger = CharacterModel::find((int)$challenge['challenger_id']);
        $defender = CharacterModel::find($charId);

        if (!$challenger || !$challenger['online']) {
            Database::execute("UPDATE arena_challenges SET status = 'cancelled' WHERE id = ?", [$challengeId]);
            return ['success' => false, 'message' => '挑战者已经离线，比武取消。'];
        }

        // === 执行比武！ ===
        $result = ArenaDaemon::executeCombat($challengeId);

        if (!$result['success']) {
            return ['success' => false, 'message' => '比武失败：' . ($result['message'] ?? '未知错误')];
        }

        // 构建返回消息
        $msg = HTML_HIYEL . "【擂台比武】" . HTML_NOR . " "
            . HTML_HICYN . $defender['name'] . HTML_NOR . "接受"
            . HTML_HICYN . $challenger['name'] . HTML_NOR . "的挑战！\n\n";
        $msg .= $result['report'] ?? '';

        return [
            'success' => true,
            'message' => $msg,
            'redirect' => 'room.php?area=city&room=' . urlencode('city/misc/leitai')
        ];
    }

    /**
     * 拒绝挑战
     */
    private function declineChallenge(int $charId, int $challengeId): array {
        $challenge = Database::queryOne(
            "SELECT * FROM arena_challenges WHERE id = ? AND status = 'pending'",
            [$challengeId]
        );

        if (!$challenge || (int)$challenge['defender_id'] !== $charId) {
            return ['success' => false, 'message' => '挑战不存在。'];
        }

        // 取消挑战
        Database::execute("UPDATE arena_challenges SET status = 'cancelled' WHERE id = ?", [$challengeId]);

        // 通知挑战者
        $defender = CharacterModel::find($charId);
        $cancelMsg = HTML_HIYEL . "【擂台挑战】" . HTML_NOR . " "
            . HTML_HICYN . ($defender['name'] ?? '对方') . HTML_NOR
            . "拒绝了你的擂台挑战。";
        MessageDaemon::queueMessageToSelf((int)$challenge['challenger_id'], $cancelMsg, 'self_event');

        return ['success' => true, 'message' => '你拒绝了擂台挑战。'];
    }
}
