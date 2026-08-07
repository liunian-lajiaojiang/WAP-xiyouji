<?php
/**
 * 天河下雨动作处理器 (xiayu)
 * 对应原始 LPC dntg/sky/tianhe.c 的 do_xiayu()
 *
 * 前置条件（对应原始 LPC dntg/bmw == "allow"）：
 *   - 玩家须先向风婆索取风灵符（yao 命令会就地授予 dntg/bmw=allow，
 *     因本项目未实现玉皇大帝册封弼马温的完整链路）
 *   - 玩家须持有 风/云/雷/电 四张灵符
 *
 * 执行效果：
 *   - 消耗四张灵符，降下大雨
 *   - 唤来天蓬元帅与玩家交战（dntg/bmw = "fight"）
 *   - 击败天蓬元帅后由 DntgQuestHandler::onNpcKilled 调用 handleTianpengDefeated() 收尾
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/ActionHandler.php';

class TianheRainHandler extends ActionHandler
{
    /** 四灵符 item_id => 名称 */
    private const LINGFU = [
        'fenglingfu' => '风灵符',
        'yunlingfu'  => '云灵符',
        'leilingfu'  => '雷灵符',
        'dianlingfu' => '电灵符',
    ];

    private const TIANPENG_NPC_ID = 1586;        // 天蓬元帅 (npc_id = tianpengyuanshuai)
    private const TIANHE_ROOM     = 'dntg/sky/tianhe';

    public function execute(int $charId, array $action, array $params = []): array
    {
        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }

        $area   = $char['current_area'] ?? '';
        $roomId = $char['current_room'] ?? '';
        $charName = $char['name'] ?? '你';

        // 1. 呼风唤雨许可检查（对应 LPC dntg/bmw == "allow"）
        if ($this->getBmwState($charId) !== 'allow') {
            return [
                'success' => true,
                'message' => HTML_HIYEL . '没事儿乱呼风唤雨的，不怕惹祸？' . HTML_NOR,
                'redirect' => room_url($area, $roomId),
            ];
        }

        // 2. 四灵符是否齐全
        $inventory = CharacterModel::getInventory($charId);
        $missing = $this->getMissingLingfu($inventory);
        if (!empty($missing)) {
            return [
                'success' => true,
                'message' => HTML_HICYN . '你还是凑齐“风”、“云”、“雷”、“电”四张灵符再来下雨吧。' . HTML_NOR,
                'redirect' => room_url($area, $roomId),
            ];
        }

        // 3. 消耗四灵符
        require_once __DIR__ . '/../models/Item.php';
        foreach (self::LINGFU as $itemId => $name) {
            ItemModel::removeFromInventory($charId, $itemId, 1);
        }

        // 4. 降下大雨
        $this->broadcastToRoom($roomId, HTML_HIYEL . "{$charName}伸手一指，天空中飘过一朵乌云。" . HTML_NOR, $charId);
        $this->broadcastToRoom($roomId, HTML_HIYEL . "转瞬间，天空中电闪雷鸣，下起了飘泼大雨。" . HTML_NOR, $charId);

        // 5. 进入交战状态并唤来天蓬元帅
        $this->setBmwState($charId, 'fight');
        $this->spawnTianpeng();

        $this->broadcastToRoom(
            $roomId,
            HTML_HIRED . "忽然天蓬元帅急急忙忙赶来，对你大喝道：敢来天河造次，反了不成？" . HTML_NOR,
            $charId
        );

        return [
            'success' => true,
            'message' => HTML_HIGRN . "乌云压顶，雷鸣电闪，大雨倾盆而下！\n天蓬元帅怒气冲冲地赶来，向你杀来——快迎战（kill 天蓬元帅）！" . HTML_NOR,
            'redirect' => room_url($area, $roomId),
        ];
    }

    /**
     * 天蓬元帅被击败后的收尾逻辑（由 DntgQuestHandler::onNpcKilled 调用）
     *
     * @return array ['success'=>bool, 'message'=>string]
     */
    public static function handleTianpengDefeated(int $charId): array
    {
        // 仅当正处于交战状态时才处理，避免干扰大闹天宫主线
        if (self::getBmwStateStatic($charId) !== 'fight') {
            return ['success' => false, 'message' => ''];
        }

        // 标记完成
        self::setBmwStateStatic($charId, 'done');

        // 天蓬元帅离开房间
        Database::execute(
            "DELETE FROM npc_temp WHERE npc_id = ? AND temp_key = 'current_location'",
            [self::TIANPENG_NPC_ID]
        );

        $char = CharacterModel::find($charId);
        $charName = $char['name'] ?? '你';
        require_once __DIR__ . '/MessageDaemon.php';
        MessageDaemon::broadcastToRoom(
            self::TIANHE_ROOM,
            HTML_HICYN . "{$charName}击退了天蓬元帅，天河重归平静。" . HTML_NOR,
            $charId
        );

        return [
            'success' => true,
            'message' => HTML_HIGRN . "天蓬元帅冷哼一声化作一股狂风不见了。\n你成功击退了天蓬元帅，呼风唤雨之能更进一层！" . HTML_NOR,
        ];
    }

    // ==================== 内部辅助 ====================

    /**
     * 返回玩家背包中缺失的灵符名称列表
     */
    private function getMissingLingfu(array $inventory): array
    {
        $have = [];
        foreach ($inventory as $it) {
            $id   = $it['item_id'] ?? '';
            $name = $it['item_name'] ?? $it['name'] ?? '';
            if (isset(self::LINGFU[$id]) || in_array($name, self::LINGFU, true)) {
                $have[$id] = true;
            }
        }
        $missing = [];
        foreach (self::LINGFU as $itemId => $name) {
            if (!isset($have[$itemId])) {
                $missing[] = $name;
            }
        }
        return $missing;
    }

    /**
     * 将天蓬元帅动态放入天河房间（npc_temp.current_location）
     */
    private function spawnTianpeng(): void
    {
        $locationJson = json_encode([
            'area' => 'dntg',
            'room' => self::TIANHE_ROOM,
        ]);
        $now = time();
        Database::execute(
            "INSERT INTO npc_temp (npc_id, temp_key, temp_value, updated_at) "
            . "VALUES (?, 'current_location', ?, ?) "
            . "ON DUPLICATE KEY UPDATE temp_value = VALUES(temp_value), updated_at = ?",
            [self::TIANPENG_NPC_ID, $locationJson, $now, $now]
        );
    }

    private function getBmwState(int $charId): ?string
    {
        return self::getBmwStateStatic($charId);
    }

    private function setBmwState(int $charId, string $value): void
    {
        self::setBmwStateStatic($charId, $value);
    }

    private static function getBmwStateStatic(int $charId): ?string
    {
        $row = Database::queryOne(
            "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = 'dntg/bmw'",
            [$charId]
        );
        return $row ? ($row['state_value'] ?? null) : null;
    }

    private static function setBmwStateStatic(int $charId, string $value): void
    {
        Database::execute(
            'INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) '
            . 'VALUES (?, ?, ?, NOW(), NOW()) '
            . 'ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = NOW()',
            [$charId, 'dntg/bmw', $value]
        );
    }
}
