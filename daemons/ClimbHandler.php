<?php
/**
 * 爬旗杆动作处理器
 * 房间：傲来台 (dntg/hgs/center)
 * 命令：climb qigan
 * 
 * 原始逻辑：
 * - dodge < 20：提升 dodge+5，扣 10 气血（摔下来）
 * - dodge >= 20：成功登顶，广播喝彩
 */

require_once __DIR__ . '/ActionHandler.php';

class ClimbHandler extends ActionHandler
{
    public function getDefaultConfig(): array {
        return [
            'skill_required'       => 'dodge',
            'skill_threshold'      => 20,
            'skill_gain'           => 5,
            'kee_cost'             => 10,
            'target_arg'           => 'qigan',
        ];
    }

    public function execute(int $charId, array $action, array $params = []): array
    {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../models/Character.php';
        require_once __DIR__ . '/MessageDaemon.php';

        $cfg = $this->mergeConfig($this->getDefaultConfig(), $this->parseConfig($action));

        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }

        $charName = $char['name'];
        $roomId = $char['current_room'];
        $param = trim($params['arg'] ?? '');

        // 参数校验
        if ($param !== $cfg['target_arg']) {
            return ['success' => false, 'message' => '你要爬什么？'];
        }

        // 广播：开始爬旗杆
        $climbMsg = HTML_HICYN . "{$charName}搓了搓手，腾地就顺着旗杆向上爬去。" . HTML_NOR;
        MessageDaemon::broadcastToRoom($roomId, $climbMsg, $charId);

        // 获取技能等级
        $skillName = $cfg['skill_required'];
        $dodgeLevel = $this->getSkillLevel($charId, $skillName);

        if ($dodgeLevel < $cfg['skill_threshold']) {
            // 技能不足：训练技能，扣气血
            $gain = $cfg['skill_gain'];
            $keeCost = $cfg['kee_cost'];
            $this->trainSkill($charId, $skillName, $gain);

            // 扣气血（不低于1）
            Database::execute(
                'UPDATE characters SET kee = GREATEST(1, kee - ?) WHERE id = ?',
                [$keeCost, $charId]
            );

            $newDodge = $dodgeLevel + $gain;
            $msg = HTML_HIYEL . "你奋力向上攀爬，但手脚不听使唤，啪的一声摔了下来！" . HTML_NOR;
            $msg .= "\n" . HTML_HIGRN . "你的{$skillName}技能提升了！({$skillName} {$dodgeLevel} → {$newDodge})" . HTML_NOR;
            $msg .= "\n" . HTML_HIRED . "你摔得浑身疼痛，气血 -{$keeCost}。" . HTML_NOR;

            // 广播摔跤
            $fallMsg = HTML_HIYEL . "{$charName}爬到一半，啪的一声摔了下来！" . HTML_NOR;
            MessageDaemon::broadcastToRoom($roomId, $fallMsg, $charId);
            MessageDaemon::queueMessageToSelf($charId, $fallMsg, 'room');

            return ['success' => true, 'message' => $msg];
        } else {
            // 技能足够：成功登顶
            $successMsg = HTML_HIYEL . "你奋力爬到最顶，博得周围一阵喝采！" . HTML_NOR;

            $broadcastMsg = HTML_HIYEL . "{$charName}奋力爬到最顶，博得周围一阵喝采！" . HTML_NOR;
            MessageDaemon::broadcastToRoom($roomId, $broadcastMsg, $charId);
            MessageDaemon::queueMessageToSelf($charId, $broadcastMsg, 'room');

            return ['success' => true, 'message' => $successMsg];
        }
    }

    /**
     * 获取技能等级
     */
    private function getSkillLevel(int $charId, string $skillId): int
    {
        $result = Database::queryOne(
            'SELECT level FROM character_skills WHERE char_id = ? AND skill_id = ? LIMIT 1',
            [$charId, $skillId]
        );
        return $result ? intval($result['level']) : 0;
    }

    /**
     * 训练技能（直接增加等级）
     */
    private function trainSkill(int $charId, string $skillId, int $gain): void
    {
        $existing = Database::queryOne(
            'SELECT id FROM character_skills WHERE char_id = ? AND skill_id = ?',
            [$charId, $skillId]
        );

        if ($existing) {
            Database::execute(
                'UPDATE character_skills SET level = level + ? WHERE char_id = ? AND skill_id = ?',
                [$gain, $charId, $skillId]
            );
        } else {
            Database::execute(
                'INSERT INTO character_skills (char_id, skill_id, level, exp) VALUES (?, ?, ?, 0)',
                [$charId, $skillId, $gain]
            );
        }
    }
}
