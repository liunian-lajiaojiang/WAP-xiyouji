<?php
/**
 * 临时状态存储辅助类
 * 用于管理角色的临时状态（如 powerup、dodge_bonus、fainted 等）
 * 
 * 存储方案：
 * - 默认使用数据库存储（可靠性高）
 * - 支持 Redis 存储（性能高，需要配置）
 * - 支持 Session 存储（简单但可靠性低）
 * 
 * 使用方法：
 * TempStateHelper::set($charId, 'powerup', ['attack_bonus' => 10, 'expires_at' => time() + 60]);
 * TempStateHelper::get($charId, 'powerup');
 * TempStateHelper::delete($charId, 'powerup');
 */

require_once __DIR__ . '/../includes/db.php';

class TempStateHelper {
    
    // 存储类型常量
    const STORAGE_DATABASE = 'database';  // 数据库存储（默认）
    const STORAGE_REDIS = 'redis';        // Redis 存储
    const STORAGE_SESSION = 'session';    // Session 存储
    
    // 当前使用的存储类型
    private static $storageType = self::STORAGE_DATABASE;
    
    // Redis 连接（如果使用 Redis）
    private static $redis = null;
    
    // 是否已初始化存储类型
    private static $storageInitialized = false;
    
    /**
     * 初始化存储类型（根据配置文件自动选择）
     */
    private static function initStorageType(): void {
        if (self::$storageInitialized) {
            return;
        }
        
        try {
            $redisConfig = require __DIR__ . '/../config/redis.php';
            if (!empty($redisConfig['enabled']) && $redisConfig['enabled'] === true) {
                self::$storageType = self::STORAGE_REDIS;
            }
        } catch (Exception $e) {
            // 配置文件不存在或出错，保持默认数据库存储
            error_log('TempStateHelper initStorageType error: ' . $e->getMessage());
        }
        
        self::$storageInitialized = true;
    }
    
    /**
     * 设置存储类型
     * @param string $type 存储类型（使用 STORAGE_* 常量）
     */
    public static function setStorageType(string $type): void {
        self::$storageType = $type;
    }
    
    /**
     * 设置临时状态
     * @param int $charId 角色ID
     * @param string $key 状态键名（如 'powerup', 'dodge_bonus', 'fainted'）
     * @param mixed $value 状态值（数组或简单值）
     * @param int $ttl 过期时间（秒），0 表示使用 value 中的 expires_at
     * @return bool 是否成功
     */
    public static function set(int $charId, string $key, $value, int $ttl = 0): bool {
        // 初始化存储类型（根据配置）
        self::initStorageType();
        
        // 如果是简单值，包装成数组
        if (!is_array($value)) {
            $value = ['_value' => $value];
        }
        
        // 确保 value 包含 expires_at
        if (!isset($value['expires_at'])) {
            $value['expires_at'] = time() + ($ttl > 0 ? $ttl : 3600);
        }
        
        switch (self::$storageType) {
            case self::STORAGE_DATABASE:
                return self::setToDatabase($charId, $key, $value);
            case self::STORAGE_REDIS:
                return self::setToRedis($charId, $key, $value, $ttl);
            case self::STORAGE_SESSION:
                return self::setToSession($charId, $key, $value);
            default:
                return self::setToDatabase($charId, $key, $value);
        }
    }
    
    /**
     * 获取临时状态
     * @param int $charId 角色ID
     * @param string $key 状态键名
     * @return mixed|null 状态值，不存在或已过期返回 null
     */
    public static function get(int $charId, string $key) {
        // 初始化存储类型（根据配置）
        self::initStorageType();
        
        switch (self::$storageType) {
            case self::STORAGE_DATABASE:
                $value = self::getFromDatabase($charId, $key);
                break;
            case self::STORAGE_REDIS:
                $value = self::getFromRedis($charId, $key);
                break;
            case self::STORAGE_SESSION:
                $value = self::getFromSession($charId, $key);
                break;
            default:
                $value = self::getFromDatabase($charId, $key);
        }
        
        // 检查是否过期
        if ($value !== null && isset($value['expires_at'])) {
            if (time() > $value['expires_at']) {
                // 已过期，删除并返回 null
                self::delete($charId, $key);
                return null;
            }
        }
        
        // 如果是简单值包装（只有 _value 和 expires_at），返回原始值
        if (is_array($value) && isset($value['_value']) && !isset($value['expires_at'])) {
            return $value['_value'];
        }
        if (is_array($value) && isset($value['_value']) && count($value) === 2 && isset($value['expires_at'])) {
            return $value['_value'];
        }
        
        return $value;
    }
    
    /**
     * 删除临时状态
     * @param int $charId 角色ID
     * @param string $key 状态键名
     * @return bool 是否成功
     */
    public static function delete(int $charId, string $key): bool {
        // 初始化存储类型（根据配置）
        self::initStorageType();
        
        switch (self::$storageType) {
            case self::STORAGE_DATABASE:
                return self::deleteFromDatabase($charId, $key);
            case self::STORAGE_REDIS:
                return self::deleteFromRedis($charId, $key);
            case self::STORAGE_SESSION:
                return self::deleteFromSession($charId, $key);
            default:
                return self::deleteFromDatabase($charId, $key);
        }
    }
    
    /**
     * 删除临时状态（别名方法）
     * @param int $charId 角色ID
     * @param string $key 状态键名
     * @return bool 是否成功
     */
    public static function remove(int $charId, string $key): bool {
        return self::delete($charId, $key);
    }
    
    /**
     * 检查临时状态是否存在且未过期
     * @param int $charId 角色ID
     * @param string $key 状态键名
     * @return bool 是否存在且未过期
     */
    public static function has(int $charId, string $key): bool {
        return self::get($charId, $key) !== null;
    }
    
    /**
     * 获取角色的所有临时状态
     * @param int $charId 角色ID
     * @return array 所有临时状态
     */
    public static function getAll(int $charId): array {
        // 初始化存储类型（根据配置）
        self::initStorageType();
        
        switch (self::$storageType) {
            case self::STORAGE_DATABASE:
                return self::getAllFromDatabase($charId);
            case self::STORAGE_REDIS:
                return self::getAllFromRedis($charId);
            case self::STORAGE_SESSION:
                return self::getAllFromSession($charId);
            default:
                return self::getAllFromDatabase($charId);
        }
    }
    
    /**
     * 清理所有过期的临时状态
     * @return int 清理的数量
     */
    public static function cleanExpired(): int {
        switch (self::$storageType) {
            case self::STORAGE_DATABASE:
                return self::cleanExpiredFromDatabase();
            case self::STORAGE_REDIS:
                return 0;  // Redis 自动过期
            case self::STORAGE_SESSION:
                return self::cleanExpiredFromSession();
            default:
                return self::cleanExpiredFromDatabase();
        }
    }
    
    // ========== 数据库存储方法 ==========
    
    private static function setToDatabase(int $charId, string $key, array $value): bool {
        $expiresAt = $value['expires_at'] ?? time() + 3600;
        $expireTime = date('Y-m-d H:i:s', $expiresAt);
        $valueJson = json_encode($value);
        
        // 先删除旧记录
        Database::execute(
            "DELETE FROM character_temp_states WHERE char_id = ? AND state_key = ?",
            [$charId, $key]
        );
        
        // 插入新记录
        return Database::execute(
            "INSERT INTO character_temp_states (char_id, state_key, state_value, expire_time) VALUES (?, ?, ?, ?)",
            [$charId, $key, $valueJson, $expireTime]
        ) > 0;
    }
    
    private static function getFromDatabase(int $charId, string $key) {
        $result = Database::queryOne(
            "SELECT state_value, expire_time FROM character_temp_states WHERE char_id = ? AND state_key = ?",
            [$charId, $key]
        );
        
        if (!$result) {
            return null;
        }
        
        $value = json_decode($result['state_value'], true);
        
        // 处理旧格式数据（非JSON标量值）
        if (!is_array($value)) {
            $scalarValue = $result['state_value'];
            // 如果是数字字符串，尝试转换为整数
            if (is_numeric($scalarValue)) {
                $scalarValue = intval($scalarValue);
            }
            $value = ['_value' => $scalarValue];
        }
        
        $value['expires_at'] = $result['expire_time'] ? strtotime($result['expire_time']) : time() + 3600;
        
        return $value;
    }
    
    private static function deleteFromDatabase(int $charId, string $key): bool {
        return Database::execute(
            "DELETE FROM character_temp_states WHERE char_id = ? AND state_key = ?",
            [$charId, $key]
        ) >= 0;
    }
    
    private static function getAllFromDatabase(int $charId): array {
        $results = Database::queryAll(
            "SELECT state_key, state_value, expire_time FROM character_temp_states WHERE char_id = ?",
            [$charId]
        );
        
        $states = [];
        foreach ($results as $result) {
            $value = json_decode($result['state_value'], true);
            
            // 处理旧格式数据（非JSON标量值）
            if (!is_array($value)) {
                $scalarValue = $result['state_value'];
                if (is_numeric($scalarValue)) {
                    $scalarValue = intval($scalarValue);
                }
                $value = ['_value' => $scalarValue];
            }
            
            $value['expires_at'] = $result['expire_time'] ? strtotime($result['expire_time']) : time() + 3600;
            
            // 检查是否过期
            if (time() <= $value['expires_at']) {
                $states[$result['state_key']] = $value;
            }
        }
        
        return $states;
    }
    
    private static function cleanExpiredFromDatabase(): int {
        return Database::execute(
            "DELETE FROM character_temp_states WHERE expire_time < ?",
            [date('Y-m-d H:i:s')]
        );
    }
    
    // ========== Redis 存储方法 ==========
    
    private static function initRedis(): void {
        if (self::$redis === null) {
            // 尝试连接 Redis
            $redisConfig = require __DIR__ . '/../config/redis.php';
            try {
                if (!class_exists('Redis')) {
                    throw new \Exception('Redis extension not installed');
                }
                self::$redis = new \Redis();
                self::$redis->connect($redisConfig['host'], $redisConfig['port']);
                if (!empty($redisConfig['password'])) {
                    self::$redis->auth($redisConfig['password']);
                }
                self::$redis->select($redisConfig['database'] ?? 0);
            } catch (\Throwable $e) {
                // Redis 连接失败，回退到数据库存储
                self::$storageType = self::STORAGE_DATABASE;
                error_log('Redis connection failed: ' . $e->getMessage());
            }
        }
    }
    
    private static function setToRedis(int $charId, string $key, array $value, int $ttl = 0): bool {
        self::initRedis();
        if (self::$redis === null) {
            return self::setToDatabase($charId, $key, $value);
        }
        
        $redisKey = "temp_state:{$charId}:{$key}";
        $valueJson = json_encode($value);
        
        $expiresAt = $value['expires_at'] ?? time() + ($ttl > 0 ? $ttl : 3600);
        $actualTtl = $expiresAt - time();
        
        if ($actualTtl <= 0) {
            return false;
        }
        
        return self::$redis->setex($redisKey, $actualTtl, $valueJson);
    }
    
    private static function getFromRedis(int $charId, string $key): ?array {
        self::initRedis();
        if (self::$redis === null) {
            return self::getFromDatabase($charId, $key);
        }
        
        $redisKey = "temp_state:{$charId}:{$key}";
        $valueJson = self::$redis->get($redisKey);
        
        if ($valueJson === false) {
            return null;
        }
        
        return json_decode($valueJson, true);
    }
    
    private static function deleteFromRedis(int $charId, string $key): bool {
        self::initRedis();
        if (self::$redis === null) {
            return self::deleteFromDatabase($charId, $key);
        }
        
        $redisKey = "temp_state:{$charId}:{$key}";
        return self::$redis->del($redisKey) >= 0;
    }
    
    private static function getAllFromRedis(int $charId): array {
        self::initRedis();
        if (self::$redis === null) {
            return self::getAllFromDatabase($charId);
        }
        
        $pattern = "temp_state:{$charId}:*";
        $keys = self::$redis->keys($pattern);
        
        $states = [];
        foreach ($keys as $redisKey) {
            $valueJson = self::$redis->get($redisKey);
            if ($valueJson !== false) {
                $key = str_replace("temp_state:{$charId}:", '', $redisKey);
                $states[$key] = json_decode($valueJson, true);
            }
        }
        
        return $states;
    }
    
    // ========== Session 存储方法 ==========
    
    private static function setToSession(int $charId, string $key, array $value): bool {
        $_SESSION[$key . '_' . $charId] = $value;
        return true;
    }
    
    private static function getFromSession(int $charId, string $key): ?array {
        $sessionKey = $key . '_' . $charId;
        return $_SESSION[$sessionKey] ?? null;
    }
    
    private static function deleteFromSession(int $charId, string $key): bool {
        $sessionKey = $key . '_' . $charId;
        unset($_SESSION[$sessionKey]);
        return true;
    }
    
    private static function getAllFromSession(int $charId): array {
        $states = [];
        foreach ($_SESSION as $sessionKey => $value) {
            if (strpos($sessionKey, '_' . $charId) !== false) {
                $key = str_replace('_', '', strstr($sessionKey, '_' . $charId, true));
                $states[$key] = $value;
            }
        }
        return $states;
    }
    
    private static function cleanExpiredFromSession(): int {
        $count = 0;
        foreach ($_SESSION as $sessionKey => $value) {
            if (is_array($value) && isset($value['expires_at'])) {
                if (time() > $value['expires_at']) {
                    unset($_SESSION[$sessionKey]);
                    $count++;
                }
            }
        }
        return $count;
    }
}