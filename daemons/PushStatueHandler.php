<?php
require_once __DIR__ . '/ActionHandler.php';

class PushStatueHandler extends ActionHandler {
    public function getDefaultConfig(): array {
        return [
            'npc_id'      => 'niwawa',
            'npc_name'    => '泥娃娃',
            'max_kee'     => 400,
            'kee'         => 400,
            'max_sen'     => 400,
            'sen'         => 400,
            'combat_exp'  => 100,
            'attitude'    => 'peaceful',
        ];
    }

    public function execute(int $charId, array $action, array $params = []): array {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../models/Character.php';
        
        $cfg = $this->mergeConfig($this->getDefaultConfig(), $this->parseConfig($action));
        
        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        $roomId = $char['current_room'];
        
        // 检查房间是否已有泥娃娃
        if ($this->hasNiwawa($roomId, $cfg)) {
            return ['success' => false, 'message' => '泥娃娃正在翩翩起舞，不要打扰她好吗？'];
        }
        
        // 召唤泥娃娃
        if ($this->summonNiwawa($charId, $roomId, $cfg)) {
            $message = "<span style='color:#FFD700'>{$char['name']}用力推了推土地公公和土地婆婆的雕像，奇迹出现了！</span>";
            $this->broadcastToRoom($roomId, $message, $charId);
            
            return [
                'success' => true,
                'message' => '哇！奇迹出现了，一尊泥娃娃从供台上跳了下来！',
                'data' => ['room_id' => $roomId]
            ];
        }
        
        return ['success' => false, 'message' => '召唤泥娃娃失败'];
    }

    /**
     * 检查房间是否已有泥娃娃
     * 按名字判定（对应原始 LPC present("mud baby")），避免与静态表上
     * npc_id='niwawa'（id 776）的唯一键混淆。
     */
    private function hasNiwawa(string $roomId, array $cfg): bool {
        $count = Database::queryOne(
            'SELECT COUNT(*) as count FROM npcs WHERE spawn_room = ? AND name = ?',
            [$roomId, $cfg['npc_name']]
        );

        return $count && $count['count'] > 0;
    }

    /**
     * 召唤泥娃娃到房间
     * 每次生成唯一 npc_id 的独立克隆（忠实还原 LPC new() 克隆语义），
     * 避免与 npcs.uk_npc_id 唯一键冲突导致「召唤泥娃娃失败」。
     */
    private function summonNiwawa(int $charId, string $roomId, array $cfg): bool {
        $spawnNpcId = 'niwawa_' . $charId . '_' . intval(microtime(true) * 1000) . '_' . mt_rand(1000, 9999);
        try {
            Database::execute(
                'INSERT INTO npcs (npc_id, spawn_room, name, max_kee, kee, max_sen, sen, combat_exp, attitude)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $spawnNpcId,
                    $roomId,
                    $cfg['npc_name'],
                    $cfg['max_kee'],
                    $cfg['kee'],
                    $cfg['max_sen'],
                    $cfg['sen'],
                    $cfg['combat_exp'],
                    $cfg['attitude'],
                ]
            );
            return true;
        } catch (Exception $e) {
            error_log('PushStatueHandler: 召唤泥娃娃失败 - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 向房间广播消息
     */
    protected function broadcastToRoom(string $roomId, string $message, int $excludeCharId = 0): void {
        error_log("Room {$roomId}: {$message}");
    }
}
?>