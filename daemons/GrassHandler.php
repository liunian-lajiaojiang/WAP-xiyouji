<?php
require_once __DIR__ . '/ActionHandler.php';

class GrassHandler extends ActionHandler {
    /** @var array|null 配置缓存 */
    private static ?array $configCache = null;

    /**
     * 获取配置（优先数据库 config JSON，fallback 默认值）
     */
    private function getConfig(array $action): array {
        if (self::$configCache !== null) {
            return self::$configCache;
        }
        $dbCfg = $this->parseConfig($action);
        $default = $this->getDefaultConfig();
        self::$configCache = array_merge($default, $dbCfg);
        return self::$configCache;
    }

    public function getDefaultConfig(): array {
        return [
            'grass_max_count'          => 5,
            'kee_cost'                 => 10,
            'grass_item_id'            => 'grass',
            'refresh_interval_minutes' => 10,
        ];
    }

    public function execute(int $charId, array $action, array $params = []): array {
        require_once MODEL_PATH . 'Character.php';
        require_once __DIR__ . '/../includes/db.php';
        
        $cfg = $this->getConfig($action);
        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        $roomId = $char['current_room'];
        $grassKey = 'grass_count_' . md5($roomId);
        
        $grassInfo = $this->getGrassInfo($grassKey, $cfg);
        $grassCount = $grassInfo['count'];
        
        if ($grassCount <= 0) {
            return ['success' => false, 'message' => '这里已经被人拔过了，没有了。'];
        }
        
        if ($char['kee'] < $cfg['kee_cost']) {
            return ['success' => false, 'message' => '你太虚弱了，无法拔草。'];
        }
        
        Database::beginTransaction();
        
        try {
            Database::execute(
                'UPDATE characters SET kee = kee - ? WHERE id = ?',
                [$cfg['kee_cost'], $charId]
            );
            
            Database::execute(
                'INSERT INTO character_inventory (char_id, item_id, quantity)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE quantity = quantity + 1',
                [$charId, $cfg['grass_item_id']]
            );
            
            $grassCount--;
            $this->setGrassCount($grassKey, $grassCount);
            
            Database::commit();
            
            $message = "<span style='color:#00FF00'>{$char['name']}费力地拔了一根小草。</span>";
            $this->broadcastToRoom($roomId, $message, $charId);
            
            return [
                'success' => true,
                'message' => '你拔了一根小草，放入了背包。',
                'data' => ['grass_remaining' => $grassCount]
            ];
        } catch (Exception $e) {
            Database::rollBack();
            error_log('GrassHandler error: ' . $e->getMessage());
            return ['success' => false, 'message' => '拔草失败：' . $e->getMessage()];
        }
    }

    /**
     * 获取草的信息，包括数量和最后更新时间
     * 如果超过刷新时间会自动重置
     */
    private function getGrassInfo(string $key, array $cfg): array {
        $result = Database::queryOne(
            'SELECT `value`, updated_at FROM variables WHERE var_key = ?',
            [$key]
        );

        if ($result) {
            $count = (int)$result['value'];
            $updatedAt = $result['updated_at'];
            
            // 检查是否需要刷新
            if ($this->needsRefresh($updatedAt, $cfg)) {
                $this->resetGrass($key, $cfg);
                return ['count' => $cfg['grass_max_count'], 'updated_at' => date('Y-m-d H:i:s')];
            }
            
            return ['count' => $count, 'updated_at' => $updatedAt];
        }
        
        // 不存在则初始化
        Database::execute(
            'INSERT INTO variables (var_key, `value`, updated_at) VALUES (?, ?, NOW())',
            [$key, $cfg['grass_max_count']]
        );
        
        return ['count' => $cfg['grass_max_count'], 'updated_at' => date('Y-m-d H:i:s')];
    }

    /**
     * 获取草数量的公开方法（用于页面显示）
     */
    public function getCurrentGrassCount($roomId, array $action = []) {
        $cfg = self::$configCache ?? $this->getDefaultConfig();
        if (!empty($action)) {
            $cfg = $this->getConfig($action);
        }
        $grassKey = 'grass_count_' . md5($roomId);
        $info = $this->getGrassInfo($grassKey, $cfg);
        return $info['count'];
    }

    /**
     * 获取下次刷新时间
     */
    public function getNextRefreshTime($roomId, array $action = []) {
        $cfg = self::$configCache ?? $this->getDefaultConfig();
        if (!empty($action)) {
            $cfg = $this->getConfig($action);
        }
        $grassKey = 'grass_count_' . md5($roomId);
        $result = Database::queryOne(
            'SELECT updated_at FROM variables WHERE var_key = ?',
            [$grassKey]
        );
        
        if ($result) {
            $updatedAt = strtotime($result['updated_at']);
            $nextRefresh = $updatedAt + ($cfg['refresh_interval_minutes'] * 60);
            return date('Y-m-d H:i:s', $nextRefresh);
        }
        
        return date('Y-m-d H:i:s', time() + ($cfg['refresh_interval_minutes'] * 60));
    }

    /**
     * 检查是否需要刷新
     */
    private function needsRefresh(string $updatedAt, array $cfg): bool {
        $updatedTime = strtotime($updatedAt);
        $currentTime = time();
        $intervalSeconds = $cfg['refresh_interval_minutes'] * 60;
        
        return ($currentTime - $updatedTime) >= $intervalSeconds;
    }

    /**
     * 重置草数量
     */
    private function resetGrass(string $key, array $cfg): void {
        Database::execute(
            'UPDATE variables SET `value` = ?, updated_at = NOW() WHERE var_key = ?',
            [$cfg['grass_max_count'], $key]
        );
    }

    private function setGrassCount($key, $count) {
        Database::execute(
            'UPDATE variables SET `value` = ?, updated_at = NOW() WHERE var_key = ?',
            [$count, $key]
        );
    }
}
?>