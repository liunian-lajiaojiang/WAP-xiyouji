<?php
/**
 * 瀑布动作处理器 (WaterfallHandler)
 * 
 * 处理花果山瀑布区域的特殊交互：
 * 1. 瀑布前(dntg/hgs/pubu) - jump pubu：跳入瀑布（运气判定）
 * 2. 瀑布前(dntg/hgs/pubu) - wave flag：挥舞大旗（任务推进）
 * 3. 铁板桥(dntg/hgs/tiebanqiao) - jump bridge：跳出瀑布（运气判定）
 * 
 * 原始LPC逻辑参考：pubu(do_jump)、shifang(do_ba)、tiebanqiao(do_go)
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */

require_once __DIR__ . '/ActionHandler.php';

class WaterfallHandler extends ActionHandler
{
    /**
     * 默认配置
     */
    public function getDefaultConfig(): array {
        return [
            'luck_roll_max' => 30,        // 运气随机数上限
            'luck_threshold' => 30,       // 运气判定阈值 (roll + luck < N 则失败)
            'unconscious_duration' => 30,  // 昏迷持续时间（秒）
        ];
    }

    /**
     * 获取配置（带缓存）
     */
    private function getWaterfallConfig(array $action): array {
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

        $cfg = $this->getWaterfallConfig($action);
        $roomId = $char['current_room'];
        $actionCmd = $action['action_cmd'] ?? '';
        $charName = $char['name'];

        // 根据 action_cmd 分发到具体处理方法
        if ($actionCmd === 'jump pubu' && $roomId === 'dntg/hgs/pubu') {
            return $this->doJumpPubu($charId, $char, $charName, $cfg);
        }

        if ($actionCmd === 'wave flag' && $roomId === 'dntg/hgs/pubu') {
            return $this->doWaveFlag($charId, $char, $charName);
        }

        if ($actionCmd === 'jump bridge' && $roomId === 'dntg/hgs/tiebanqiao') {
            return $this->doJumpBridge($charId, $char, $charName, $cfg);
        }

        return ['success' => false, 'message' => '这里不能这样做。'];
    }

    /**
     * 跳入瀑布 (jump pubu)
     * 
     * 原始LPC逻辑：
     * - random(30) + kar < 30 → 失败，撞石头昏迷
     * - 成功 → 进入瀑布中(Pubu1)
     * 
     * kar(运气) 在 PHP 版中映射为角色的 luck 属性
     */
    private function doJumpPubu(int $charId, array $char, string $charName, array $cfg): array
    {
        // 运气值: 数据库中的 kar 字段 (LPC中的luck/kar)
        $luck = intval($char['kar'] ?? $char['luck'] ?? 10);

        // 广播跳跃消息
        $jumpMsg = HTML_HIYEL . $charName . '大喝一声："我去瞧瞧！"说罢飞身跃入瀑布。' . HTML_NOR;

        // 运气判定：random(roll_max) + luck < threshold 则失败
        $roll = rand(1, $cfg['luck_roll_max']);
        if ($roll + $luck < $cfg['luck_threshold']) {
            // 失败：撞到石头，昏迷
            $failMsg = HTML_HIRED . $charName . '向下一纵，不小心撞在了一块石头上，昏了过去。' . HTML_NOR;
            MessageDaemon::broadcastToRoom('dntg/hgs/pubu', $failMsg, $charId, 'room');

            // 设置昏迷状态（扣减气血到1，设置昏迷时间）
            $this->setUnconscious($charId, $cfg);

            return [
                'success' => false,
                'message' => HTML_HIRED . '你向下一纵，不小心撞在了一块石头上，眼前一黑……' . HTML_NOR,
                'skip_queue' => true,
            ];
        }

        // 成功：进入瀑布迷宫第一层
        MessageDaemon::broadcastToRoom('dntg/hgs/pubu', $jumpMsg, $charId, 'room');

        // 更新位置到 Pubu1
        CharacterModel::updatePosition($charId, 'dntg', 'dntg/hgs/pubu1');
        $targetRoom = RoomModel::load('dntg', 'dntg/hgs/pubu1');

        $personalMsg = HTML_HICYN . ($targetRoom['name'] ?? '瀑布中') . HTML_NOR . "\n";
        $personalMsg .= ($targetRoom['description'] ?? '你似乎什么也看不清楚，只觉得四周涧水奔流，难以探到前方的出路……') . "\n";
        $personalMsg .= HTML_HIYEL . '你飞身跃入瀑布，穿过水帘，来到了一个隐秘的空间。' . HTML_NOR;

        return [
            'success' => true,
            'type' => 'move',
            'message' => $personalMsg,
            // leave 消息已在上方通过 broadcastToRoom 广播，不再由 action.php 重复广播
            'leave_message' => '',
            'arrive_message' => '',
            'new_room' => $targetRoom,
            'old_room' => RoomModel::load('dntg', 'dntg/hgs/pubu'),
            'redirect' => 'room.php?area=dntg&room=dntg/hgs/pubu1',
            'skip_queue' => true,  // 防止 action.php 重复保存消息
        ];
    }

    /**
     * 挥舞大旗 (wave flag)
     * 
     * 原始LPC逻辑：
     * - 必须持有 flag 物品
     * - 挥舞后设置 dntg/huaguo = "allow"（允许进入水帘洞内部）
     * - 销毁 flag 物品
     * - 猴子们跟着跳下瀑布
     */
    private function doWaveFlag(int $charId, array $char, string $charName): array
    {
        // 检查是否持有大旗
        $flagItem = Database::queryOne(
            "SELECT id, item_id, quantity FROM character_inventory WHERE char_id = ? AND item_id = 'flag'",
            [$charId]
        );

        if (!$flagItem) {
            return ['success' => false, 'message' => '你身上没有旗子，挥舞什么？', 'skip_queue' => true];
        }

        // 检查任务状态
        $questState = $this->getQuestState($charId, 'dntg/huaguo');
        if ($questState === 'done') {
            return ['success' => false, 'message' => '你已经完成了这个步骤。', 'skip_queue' => true];
        }

        // 设置任务状态为 "allow"
        $this->setQuestState($charId, 'dntg/huaguo', 'allow');

        // 销毁旗子
        Database::execute(
            "DELETE FROM character_inventory WHERE id = ?",
            [$flagItem['id']]
        );

        // 广播挥旗消息
        $waveMsg = HTML_HIYEL . $charName . '挥舞着旗子，大喝一声："大造化！大造化！下面没水！原来是一座铁板桥。桥那边是一座天造地设的家当。兄弟们快去呀！"' . HTML_NOR;
        MessageDaemon::broadcastToRoom('dntg/hgs/pubu', $waveMsg, $charId, 'room');

        // 猴子们跳下瀑布
        $monkeyMsg = HTML_HIYEL . '猴子们听罢争先恐后的跳下瀑布。' . HTML_NOR;
        MessageDaemon::broadcastToRoom('dntg/hgs/pubu', $monkeyMsg, $charId, 'room');

        // 给自己发任务提示
        $selfMsg = HTML_HIGRN . '【花果山】你成功挥舞大旗，猴子们认可了你的领导地位！现在你可以进入水帘洞深处了。' . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'self_event');

        return [
            'success' => true,
            'message' => $waveMsg . "\n" . $monkeyMsg . "\n" . HTML_HIGRN . '猴子们认可了你的领导地位！你可以进入水帘洞深处了。' . HTML_NOR,
            'skip_queue' => true,
        ];
    }

    /**
     * 从铁板桥跳出瀑布 (jump bridge)
     * 
     * 原始LPC逻辑：
     * - random(30) + kar < 30 → 失败，摔下来昏迷
     * - 成功 → 回到瀑布前(Pubu)
     */
    private function doJumpBridge(int $charId, array $char, string $charName, array $cfg): array
    {
        // 运气值: 数据库中的 kar 字段 (LPC中的luck/kar)
        $luck = intval($char['kar'] ?? $char['luck'] ?? 10);

        $roll = rand(1, $cfg['luck_roll_max']);
        if ($roll + $luck < $cfg['luck_threshold']) {
            // 失败
            $failMsg = HTML_HIRED . $charName . '奋力向上一跃，又从半空中摔了下来。' . HTML_NOR;
            MessageDaemon::broadcastToRoom('dntg/hgs/tiebanqiao', $failMsg, $charId, 'room');

            $this->setUnconscious($charId, $cfg);

            return [
                'success' => false,
                'message' => HTML_HIRED . '你奋力向上一跃，又从半空中摔了下来，眼前一黑……' . HTML_NOR,
                'skip_queue' => true,
            ];
        }

        // 成功：回到瀑布前
        $jumpMsg = HTML_HIYEL . $charName . '从桥上飞身纵出瀑布。' . HTML_NOR;
        MessageDaemon::broadcastToRoom('dntg/hgs/tiebanqiao', $jumpMsg, $charId, 'room');

        CharacterModel::updatePosition($charId, 'dntg', 'dntg/hgs/pubu');
        $targetRoom = RoomModel::load('dntg', 'dntg/hgs/pubu');

        $personalMsg = HTML_HICYN . ($targetRoom['name'] ?? '瀑布前') . HTML_NOR . "\n";
        $personalMsg .= ($targetRoom['description'] ?? '') . "\n";
        $personalMsg .= HTML_HIYEL . '你从铁板桥上飞身纵出，穿过了瀑布水帘，回到了外面。' . HTML_NOR;

        return [
            'success' => true,
            'type' => 'move',
            'message' => $personalMsg,
            // leave 消息已在上方通过 broadcastToRoom 广播，不再由 action.php 重复广播
            'leave_message' => '',
            // arrive 消息由 action.php 广播（handler 未自行广播到达消息）
            'arrive_message' => HTML_HIYEL . $charName . '从瀑布中飞身跃出。' . HTML_NOR,
            'new_room' => $targetRoom,
            'old_room' => RoomModel::load('dntg', 'dntg/hgs/tiebanqiao'),
            'redirect' => 'room.php?area=dntg&room=dntg/hgs/pubu',
            'skip_queue' => true,
        ];
    }

    /**
     * 设置角色昏迷状态
     * 参考原版 unconcious() 逻辑：kee=1, 设置昏迷标记
     */
    private function setUnconscious(int $charId, array $cfg = []): void
    {
        // 将气血扣到1（保留性命）
        Database::execute(
            'UPDATE characters SET kee = 1 WHERE id = ?',
            [$charId]
        );

        $duration = $cfg['unconscious_duration'] ?? 30;

        // 设置昏迷session（供 room.php 和其他系统检查）
        $_SESSION['unconscious_' . $charId] = [
            'timestamp' => time(),
            'duration' => $duration,
        ];

        // 给自己发昏迷提示
        MessageDaemon::queueMessageToSelf(
            $charId,
            HTML_HIRED . '你昏迷了过去……需要休息片刻才能恢复。' . HTML_NOR,
            'self_event'
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

    /**
     * 设置角色任务状态
     */
    private function setQuestState(int $charId, string $key, string $value): void
    {
        Database::execute(
            'INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) 
             VALUES (?, ?, ?, NOW(), NOW()) 
             ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = NOW()',
            [$charId, $key, $value]
        );
    }
}
