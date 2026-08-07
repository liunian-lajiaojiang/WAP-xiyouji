<?php
/**
 * 石房动作处理器 (ShifangHandler)
 * 
 * 处理水帘洞石房的特殊交互：
 * 1. ba flag - 拔旗（运气+体力判定，获得大旗物品）
 * 2. bed/gosleep/gobed - 躺到石床上（传送到石床睡眠室）
 * 
 * 原始LPC逻辑参考：
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */

require_once __DIR__ . '/ActionHandler.php';

class ShifangHandler extends ActionHandler
{
    /**
     * 默认配置
     */
    public function getDefaultConfig(): array {
        return [
            'ba_flag_roll_max' => 10,    // 拔旗随机数上限
            'ba_flag_threshold' => 5,    // 拔旗成功阈值 (roll < N 则失败)
            'ba_flag_kee_cost' => 200,   // 拔旗消耗气血
        ];
    }

    /**
     * 获取配置（带缓存）
     */
    private function getShifangConfig(array $action): array {
        static $cache = null;
        if ($cache === null) {
            $cache = $this->mergeConfig($this->getDefaultConfig(), $this->parseConfig($action));
        }
        return $cache;
    }

    public function execute(int $charId, array $action, array $params = []): array
    {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../models/Character.php';
        require_once __DIR__ . '/../models/Room.php';
        require_once __DIR__ . '/MessageDaemon.php';

        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }

        $roomId = $char['current_room'];
        if ($roomId !== 'dntg/hgs/shifang') {
            return ['success' => false, 'message' => '这里没有可以这样做的东西。'];
        }

        $cfg = $this->getShifangConfig($action);
        $actionCmd = $action['action_cmd'] ?? '';
        $charName = $char['name'];

        // 拔旗
        if ($actionCmd === 'ba flag') {
            return $this->doBaFlag($charId, $char, $charName, $cfg);
        }

        // 睡觉（bed/gosleep/gobed）
        if (in_array($actionCmd, ['bed', 'gosleep', 'gobed'])) {
            return $this->doBed($charId, $char, $charName);
        }

        return ['success' => false, 'message' => '你不知道怎么做。'];
    }

    /**
     * 拔旗 (ba flag)
     * 
     * 原始LPC逻辑：
     * - 已经有旗子 → 拒绝
     * - 旗子已被拔走(getflag=1) → 拒绝
     * - 任务已完成(dntg/huaguo == "done") → 回忆文字
     * - random(10) < 5 → 失败，消耗200气力或昏迷
     * - random(10) >= 5 → 成功，消耗200气力，获得旗子
     * 
     * 注意：getflag 是房间级状态，用 variables 表追踪
     */
    private function doBaFlag(int $charId, array $char, string $charName, array $cfg): array
    {
        // 检查是否已经有旗子
        $existingFlag = Database::queryOne(
            "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = 'flag'",
            [$charId]
        );
        if ($existingFlag) {
            return ['success' => false, 'message' => '你不是已经有旗子了吗？', 'skip_queue' => true];
        }

        // 检查旗子是否已被拔走（全局变量）
        $flagTaken = $this->getGlobalVar('shifang_flag_taken');
        if ($flagTaken === '1') {
            return ['success' => false, 'message' => '大旗已经被别人拔走了，这里只留下一截旗杆。', 'skip_queue' => true];
        }

        // 检查任务是否已完成
        $questState = $this->getQuestState($charId, 'dntg/huaguo');
        if ($questState === 'done') {
            return [
                'success' => false,
                'message' => '你手握大旗的位置，不禁想起自己当年在此称王的快乐时光。',
                'skip_queue' => true
            ];
        }

        $kee = intval($char['kee'] ?? 0);
        $rollMax = $cfg['ba_flag_roll_max'];
        $threshold = $cfg['ba_flag_threshold'];
        $keeCost = $cfg['ba_flag_kee_cost'];

        // N/M 概率成功/失败
        if (rand(1, $rollMax) < $threshold) {
            // 失败
            if ($kee > $keeCost) {
                Database::execute('UPDATE characters SET kee = kee - ? WHERE id = ?', [$keeCost, $charId]);
                $failMsg = HTML_HIYEL . $charName . '使尽吃奶的力气也没将大旗拔出来。' . HTML_NOR;
                MessageDaemon::broadcastToRoom('dntg/hgs/shifang', $failMsg, $charId, 'room');

                return [
                    'success' => false,
                    'message' => '你使尽吃奶的力气，大旗纹丝不动。（消耗' . $keeCost . '气力）',
                    'skip_queue' => true
                ];
            } else {
                // 气力不足，昏迷
                Database::execute('UPDATE characters SET kee = 1 WHERE id = ?', [$charId]);
                return [
                    'success' => false,
                    'message' => HTML_HIRED . '你气力不足，拼命拔旗却力竭昏迷了过去……' . HTML_NOR,
                    'skip_queue' => true
                ];
            }
        }

        // 成功
        if ($kee <= $keeCost) {
            Database::execute('UPDATE characters SET kee = 1 WHERE id = ?', [$charId]);
            return [
                'success' => false,
                'message' => HTML_HIRED . '你大喝一声拔出了大旗，但气力耗尽，昏了过去……' . HTML_NOR,
                'skip_queue' => true
            ];
        }

        // 扣除气力
        Database::execute('UPDATE characters SET kee = kee - ? WHERE id = ?', [$keeCost, $charId]);

        // 给予旗子物品
        $this->giveFlagItem($charId);

        // 标记全局旗子已被拔走
        $this->setGlobalVar('shifang_flag_taken', '1');

        // 广播成功消息
        $successMsg = HTML_HIYEL . $charName . '大喝一声，将大旗拔了下来。' . HTML_NOR;
        MessageDaemon::broadcastToRoom('dntg/hgs/shifang', $successMsg, $charId, 'room');

        return [
            'success' => true,
            'message' => HTML_HIGRN . '你大喝一声，将大旗拔了下来！' . HTML_NOR . "\n" .
                         '一面迎风招展的三色大旗落入你手中。旗上写着："得此旗者可为仙灵福地水帘洞之洞主"。' . "\n" .
                         '（消耗200气力）',
            'skip_queue' => true
        ];
    }

    /**
     * 躺到石床上 (bed/gosleep/gobed)
     * 
     * 原始LPC逻辑：传送到石床(Shichuang)房间
     */
    private function doBed(int $charId, array $char, string $charName): array
    {
        // 广播躺下消息
        $bedMsg = HTML_HIYEL . $charName . '往石床上一躺，准备睡觉了。' . HTML_NOR;
        MessageDaemon::broadcastToRoom('dntg/hgs/shifang', $bedMsg, $charId, 'room');

        // 更新位置到石床
        CharacterModel::updatePosition($charId, 'dntg', 'dntg/hgs/shichuang');

        // 广播到达消息
        $arriveMsg = HTML_HIYEL . $charName . '钻到了被窝里。' . HTML_NOR;
        MessageDaemon::broadcastToRoom('dntg/hgs/shichuang', $arriveMsg, $charId, 'room');

        $targetRoom = RoomModel::load('dntg', 'dntg/hgs/shichuang');

        $personalMsg = HTML_HICYN . '石床' . HTML_NOR . "\n";
        $personalMsg .= "一张长长的石床。\n";
        $personalMsg .= HTML_HIYEL . '你往石床上一躺，舒适极了。' . HTML_NOR;

        return [
            'success' => true,
            'type' => 'move',
            'message' => $personalMsg,
            // leave/arrive 消息已在上方通过 broadcastToRoom 广播，不再由 action.php 重复广播
            'leave_message' => '',
            'arrive_message' => '',
            'new_room' => $targetRoom,
            'redirect' => 'room.php?area=dntg&room=dntg/hgs/shichuang',
            'skip_queue' => true,
        ];
    }

    /**
     * 给予旗子物品
     */
    private function giveFlagItem(int $charId): void
    {
        // 检查 items 表是否有 flag 物品定义
        $itemDef = Database::queryOne("SELECT item_id FROM items WHERE item_id = 'flag'");
        if (!$itemDef) {
            // 如果物品表没有定义，先创建一个
            Database::execute(
                "INSERT INTO items (item_id, name, description, type, value, weight) VALUES ('flag', '三色大旗', '一面迎风招展的三色大旗，旗上写着：得此旗者可为仙灵福地水帘洞之洞主。', 'quest', 0, 5)",
                []
            );
        }

        // 添加到背包
        $existing = Database::queryOne(
            "SELECT id, quantity FROM character_inventory WHERE char_id = ? AND item_id = 'flag'",
            [$charId]
        );
        if ($existing) {
            Database::execute(
                "UPDATE character_inventory SET quantity = quantity + 1 WHERE id = ?",
                [$existing['id']]
            );
        } else {
            Database::execute(
                "INSERT INTO character_inventory (char_id, item_id, quantity, equipped) VALUES (?, 'flag', 1, 0)",
                [$charId]
            );
        }
    }

    /**
     * 获取全局变量
     */
    private function getGlobalVar(string $key): ?string
    {
        $row = Database::queryOne(
            "SELECT `value` FROM variables WHERE var_key = ?",
            [$key]
        );
        return $row ? ($row['value'] ?? null) : null;
    }

    /**
     * 设置全局变量
     */
    private function setGlobalVar(string $key, string $value): void
    {
        Database::execute(
            'INSERT INTO variables (var_key, `value`, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()',
            [$key, $value]
        );
    }

    /**
     * 获取角色任务状态
     */
    private function getQuestState(int $charId, string $key): ?string
    {
        $row = Database::queryOne(
            "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?",
            [$charId, $key]
        );
        return $row ? ($row['state_value'] ?? null) : null;
    }
}
