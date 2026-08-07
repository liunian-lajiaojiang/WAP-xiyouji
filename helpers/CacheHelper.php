<?php
/**
 * 统一缓存管理器
 * 提供 Redis + 文件缓存的双层缓存方案
 * 
 * 使用方法：
 * CacheHelper::get('room', 'city/main');           // 获取缓存
 * CacheHelper::set('room', 'city/main', $data);    // 设置缓存（默认1小时）
 * CacheHelper::set('room', 'city/main', $data, 3600); // 设置缓存（指定TTL）
 * CacheHelper::delete('room', 'city/main');        // 删除缓存
 * CacheHelper::clear('room');                      // 清除某个命名空间的缓存
 * CacheHelper::clearAll();                         // 清除所有缓存
 * CacheHelper::exists('room', 'city/main');        // 检查缓存是否存在
 * CacheHelper::refresh('room', 'city/main', $data); // 刷新缓存（先删后加）
 */

class CacheHelper {
    
    // 缓存命名空间常量
    const NAMESPACE_ROOM = 'room';        // 房间数据
    const NAMESPACE_ITEM = 'item';        // 物品定义
    const NAMESPACE_NPC = 'npc';          // NPC数据
    const NAMESPACE_SKILL = 'skill';      // 技能数据
    const NAMESPACE_CHAR = 'char';        // 角色基础信息
    const NAMESPACE_CONFIG = 'config';    // 配置数据
    const NAMESPACE_QUEST = 'quest';      // 任务数据
    const NAMESPACE_OTHER = 'other';      // 其他数据
    
    // 默认TTL（秒）
    const DEFAULT_TTL = 3600;             // 1小时
    const LONG_TTL = 86400;              // 24小时（适合几乎不变的数据）
    const SHORT_TTL = 300;                // 5分钟（适合频繁变化的数据）
    
    // Redis 连接
    private static $redis = null;
    
    // Redis 是否可用
    private static $redisEnabled = null;
    
    // 文件缓存目录
    private static $fileCacheDir = null;
    
    // 内存缓存（请求级别）
    private static $memoryCache = [];
    
    // 是否已初始化
    private static $initialized = false;
    
    /**
     * 初始化缓存系统
     */
    private static function init(): void {
        if (self::$initialized) {
            return;
        }
        
        // 检查 Redis 配置
        try {
            $redisConfig = require __DIR__ . '/../config/redis.php';
            self::$redisEnabled = !empty($redisConfig['enabled']) && $redisConfig['enabled'] === true;
        } catch (Exception $e) {
            self::$redisEnabled = false;
            error_log('CacheHelper: Failed to load Redis config: ' . $e->getMessage());
        }
        
        // 检查 Redis 扩展是否安装
        if (self::$redisEnabled && !extension_loaded('redis')) {
            self::$redisEnabled = false;
            error_log('CacheHelper: Redis extension not loaded, falling back to file cache');
        }
        
        // 初始化 Redis 连接
        if (self::$redisEnabled) {
            self::initRedis();
        }
        
        // 初始化文件缓存目录
        self::$fileCacheDir = __DIR__ . '/../data/cache';
        if (!is_dir(self::$fileCacheDir)) {
            @mkdir(self::$fileCacheDir, 0755, true);
        }
        
        self::$initialized = true;
    }
    
    /**
     * 初始化 Redis 连接
     */
    private static function initRedis(): void {
        if (self::$redis !== null) {
            return;
        }
        
        try {
            $redisConfig = require __DIR__ . '/../config/redis.php';
            
            self::$redis = new Redis();
            self::$redis->connect(
                $redisConfig['host'] ?? '127.0.0.1',
                $redisConfig['port'] ?? 6379,
                $redisConfig['timeout'] ?? 2.0
            );
            
            // 测试连接
            self::$redis->ping();
            self::$redisEnabled = true;
            
            error_log('CacheHelper: Redis connected successfully');
        } catch (Exception $e) {
            self::$redis = null;
            self::$redisEnabled = false;
            error_log('CacheHelper: Redis connection failed: ' . $e->getMessage());
        }
    }
    
    /**
     * 生成缓存键
     * @param string $namespace 命名空间
     * @param string $key 缓存键
     * @return string 完整的缓存键
     */
    private static function buildKey(string $namespace, string $key): string {
        return "cache:{$namespace}:{$key}";
    }
    
    /**
     * 获取缓存
     * @param string $namespace 命名空间
     * @param string $key 缓存键
     * @param mixed $default 默认值（缓存不存在时返回）
     * @return mixed 缓存值或默认值
     */
    public static function get(string $namespace, string $key, $default = null) {
        self::init();
        
        $fullKey = self::buildKey($namespace, $key);
        
        // 1. 先检查内存缓存
        if (isset(self::$memoryCache[$fullKey])) {
            return self::$memoryCache[$fullKey];
        }
        
        $value = null;
        
        // 2. 尝试从 Redis 获取
        if (self::$redisEnabled && self::$redis !== null) {
            try {
                $value = self::$redis->get($fullKey);
                if ($value !== false) {
                    $value = json_decode($value, true);
                }
            } catch (Exception $e) {
                error_log('CacheHelper Redis get error: ' . $e->getMessage());
                $value = null;
            }
        }
        
        // 3. 如果 Redis 没有，尝试文件缓存
        if ($value === null) {
            $value = self::getFromFileCache($fullKey);
        }
        
        // 4. 如果找到值，存入内存缓存并返回
        if ($value !== null) {
            self::$memoryCache[$fullKey] = $value;
            return $value;
        }
        
        return $default;
    }
    
    /**
     * 设置缓存
     * @param string $namespace 命名空间
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param int $ttl 过期时间（秒），0 表示使用默认TTL
     * @return bool 是否成功
     */
    public static function set(string $namespace, string $key, $value, int $ttl = 0): bool {
        self::init();
        
        $fullKey = self::buildKey($namespace, $key);
        $ttl = $ttl > 0 ? $ttl : self::DEFAULT_TTL;
        
        // 序列化值
        $serialized = json_encode($value, JSON_UNESCAPED_UNICODE);
        
        // 1. 存入内存缓存
        self::$memoryCache[$fullKey] = $value;
        
        $success = true;
        
        // 2. 尝试存入 Redis
        if (self::$redisEnabled && self::$redis !== null) {
            try {
                $success = self::$redis->setex($fullKey, $ttl, $serialized);
            } catch (Exception $e) {
                error_log('CacheHelper Redis set error: ' . $e->getMessage());
                $success = false;
            }
        }
        
        // 3. 如果 Redis 失败或未启用，存入文件缓存
        if (!$success) {
            self::setToFileCache($fullKey, $serialized, $ttl);
        }
        
        return true;
    }
    
    /**
     * 删除缓存
     * @param string $namespace 命名空间
     * @param string $key 缓存键
     * @return bool 是否成功
     */
    public static function delete(string $namespace, string $key): bool {
        self::init();
        
        $fullKey = self::buildKey($namespace, $key);
        
        // 1. 从内存缓存删除
        unset(self::$memoryCache[$fullKey]);
        
        // 2. 从 Redis 删除
        if (self::$redisEnabled && self::$redis !== null) {
            try {
                self::$redis->del($fullKey);
            } catch (Exception $e) {
                error_log('CacheHelper Redis delete error: ' . $e->getMessage());
            }
        }
        
        // 3. 从文件缓存删除
        self::deleteFromFileCache($fullKey);
        
        return true;
    }
    
    /**
     * 检查缓存是否存在
     * @param string $namespace 命名空间
     * @param string $key 缓存键
     * @return bool 是否存在
     */
    public static function exists(string $namespace, string $key): bool {
        self::init();
        
        $fullKey = self::buildKey($namespace, $key);
        
        // 1. 检查内存缓存
        if (isset(self::$memoryCache[$fullKey])) {
            return true;
        }
        
        // 2. 检查 Redis
        if (self::$redisEnabled && self::$redis !== null) {
            try {
                return self::$redis->exists($fullKey) > 0;
            } catch (Exception $e) {
                error_log('CacheHelper Redis exists error: ' . $e->getMessage());
            }
        }
        
        // 3. 检查文件缓存
        return self::existsInFileCache($fullKey);
    }
    
    /**
     * 刷新缓存（先删后加）
     * @param string $namespace 命名空间
     * @param string $key 缓存键
     * @param mixed $value 新的缓存值
     * @param int $ttl 过期时间（秒）
     * @return bool 是否成功
     */
    public static function refresh(string $namespace, string $key, $value, int $ttl = 0): bool {
        self::delete($namespace, $key);
        return self::set($namespace, $key, $value, $ttl);
    }
    
    /**
     * 清除指定命名空间的所有缓存
     * @param string $namespace 命名空间
     * @return bool 是否成功
     */
    public static function clear(string $namespace): bool {
        self::init();
        
        $pattern = "cache:{$namespace}:*";
        
        // 1. 清除内存缓存中该命名空间的所有键
        foreach (self::$memoryCache as $key => $value) {
            if (strpos($key, "cache:{$namespace}:") === 0) {
                unset(self::$memoryCache[$key]);
            }
        }
        
        // 2. 清除 Redis 中该命名空间的所有键
        if (self::$redisEnabled && self::$redis !== null) {
            try {
                $keys = self::$redis->keys($pattern);
                if (!empty($keys)) {
                    self::$redis->del($keys);
                }
            } catch (Exception $e) {
                error_log('CacheHelper Redis clear error: ' . $e->getMessage());
            }
        }
        
        // 3. 清除文件缓存中该命名空间的所有文件
        self::clearFileCacheNamespace($namespace);
        
        return true;
    }
    
    /**
     * 清除所有缓存
     * @return bool 是否成功
     */
    public static function clearAll(): bool {
        self::init();
        
        // 1. 清除所有内存缓存
        self::$memoryCache = [];
        
        // 2. 清除 Redis 中所有缓存键
        if (self::$redisEnabled && self::$redis !== null) {
            try {
                $keys = self::$redis->keys('cache:*');
                if (!empty($keys)) {
                    self::$redis->del($keys);
                }
            } catch (Exception $e) {
                error_log('CacheHelper Redis clearAll error: ' . $e->getMessage());
            }
        }
        
        // 3. 清除所有文件缓存
        self::clearAllFileCache();
        
        return true;
    }
    
    /**
     * 获取缓存统计信息
     * @return array 统计信息
     */
    public static function getStats(): array {
        self::init();
        
        $stats = [
            'memory_cache_count' => count(self::$memoryCache),
            'redis_enabled' => self::$redisEnabled,
            'redis_connected' => self::$redis !== null,
            'file_cache_dir' => self::$fileCacheDir,
        ];
        
        if (self::$redisEnabled && self::$redis !== null) {
            try {
                $info = self::$redis->info('memory');
                $stats['redis_memory_used'] = $info['used_memory_human'] ?? 'N/A';
            } catch (Exception $e) {
                $stats['redis_memory_used'] = 'N/A';
            }
        }
        
        return $stats;
    }
    
    // ==================== 文件缓存操作 ====================
    
    /**
     * 从文件缓存获取
     */
    private static function getFromFileCache(string $fullKey): ?array {
        $file = self::getCacheFilePath($fullKey);
        
        if (!file_exists($file)) {
            return null;
        }
        
        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }
        
        $data = json_decode($content, true);
        if ($data === null) {
            return null;
        }
        
        // 检查过期
        if (isset($data['expires_at']) && time() > $data['expires_at']) {
            @unlink($file);
            return null;
        }
        
        return $data['value'] ?? null;
    }
    
    /**
     * 存入文件缓存
     */
    private static function setToFileCache(string $fullKey, string $serialized, int $ttl): bool {
        $file = self::getCacheFilePath($fullKey);
        $dir = dirname($file);
        
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        
        $data = [
            'value' => json_decode($serialized, true),
            'expires_at' => time() + $ttl,
            'created_at' => time(),
        ];
        
        $content = json_encode($data, JSON_UNESCAPED_UNICODE);
        
        return @file_put_contents($file, $content, LOCK_EX) !== false;
    }
    
    /**
     * 从文件缓存删除
     */
    private static function deleteFromFileCache(string $fullKey): bool {
        $file = self::getCacheFilePath($fullKey);
        
        if (file_exists($file)) {
            return @unlink($file);
        }
        
        return true;
    }
    
    /**
     * 检查文件缓存是否存在
     */
    private static function existsInFileCache(string $fullKey): bool {
        $file = self::getCacheFilePath($fullKey);
        
        if (!file_exists($file)) {
            return false;
        }
        
        // 检查是否过期
        $content = @file_get_contents($file);
        if ($content === false) {
            return false;
        }
        
        $data = json_decode($content, true);
        if (isset($data['expires_at']) && time() > $data['expires_at']) {
            @unlink($file);
            return false;
        }
        
        return true;
    }
    
    /**
     * 清除文件缓存中某个命名空间的所有文件
     */
    private static function clearFileCacheNamespace(string $namespace): void {
        $pattern = self::$fileCacheDir . '/cache_' . $namespace . '_*';
        $files = glob($pattern);
        
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }
    
    /**
     * 清除所有文件缓存
     */
    private static function clearAllFileCache(): void {
        $pattern = self::$fileCacheDir . '/cache_*';
        $files = glob($pattern);
        
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }
    
    /**
     * 获取缓存文件路径
     */
    private static function getCacheFilePath(string $fullKey): string {
        // 将 key 转换为安全的文件名
        // 例如 cache:room:city/main -> cache_room_city_main.json
        $safeKey = str_replace([':', '/', '\\', '..'], '_', $fullKey);
        return self::$fileCacheDir . '/' . $safeKey . '.json';
    }
    
    // ==================== 便捷方法 ====================
    
    /**
     * 获取房间数据
     * @param string $area 区域
     * @param string $roomId 房间ID
     * @param callable|null $loader 缓存未命中时的加载函数
     * @return array|null
     */
    public static function getRoom(string $area, string $roomId, ?callable $loader = null): ?array {
        $key = "{$area}:{$roomId}";
        $data = self::get(self::NAMESPACE_ROOM, $key);
        
        if ($data === null && $loader !== null) {
            $data = $loader();
            if ($data !== null) {
                self::set(self::NAMESPACE_ROOM, $key, $data, self::LONG_TTL);
            }
        }
        
        return $data;
    }
    
    /**
     * 获取物品定义
     * @param string $itemId 物品ID
     * @param string $category 分类
     * @param callable|null $loader 缓存未命中时的加载函数
     * @return array|null
     */
    public static function getItem(string $itemId, string $category = '', ?callable $loader = null): ?array {
        $key = $category !== '' ? "{$itemId}:{$category}" : $itemId;
        $data = self::get(self::NAMESPACE_ITEM, $key);
        
        if ($data === null && $loader !== null) {
            $data = $loader();
            if ($data !== null) {
                self::set(self::NAMESPACE_ITEM, $key, $data, self::LONG_TTL);
            }
        }
        
        return $data;
    }
    
    /**
     * 清除房间缓存
     * @param string $area 区域
     * @param string $roomId 房间ID
     */
    public static function clearRoom(string $area, string $roomId): void {
        self::delete(self::NAMESPACE_ROOM, "{$area}:{$roomId}");
    }
    
    /**
     * 清除物品缓存
     * @param string $itemId 物品ID
     * @param string $category 分类
     */
    public static function clearItem(string $itemId, string $category = ''): void {
        $key = $category !== '' ? "{$itemId}:{$category}" : $itemId;
        self::delete(self::NAMESPACE_ITEM, $key);
    }
    
    /**
     * 批量获取房间数据
     * @param array $keys 键数组，每项格式为 ['area' => 'city', 'room_id' => 'main']
     * @param callable $loader 加载函数，接收 (area, roomId) 返回数据
     * @return array 键到数据的映射
     */
    public static function getRoomsBatch(array $keys, callable $loader): array {
        $result = [];
        $missingKeys = [];
        
        // 先批量获取缓存
        foreach ($keys as $item) {
            $area = $item['area'];
            $roomId = $item['room_id'];
            $key = "{$area}:{$roomId}";
            
            $data = self::get(self::NAMESPACE_ROOM, $key);
            if ($data !== null) {
                $result[$key] = $data;
            } else {
                $missingKeys[] = ['area' => $area, 'room_id' => $roomId];
            }
        }
        
        // 批量加载缺失的数据
        if (!empty($missingKeys)) {
            foreach ($missingKeys as $item) {
                $area = $item['area'];
                $roomId = $item['room_id'];
                $key = "{$area}:{$roomId}";
                
                $data = $loader($area, $roomId);
                if ($data !== null) {
                    self::set(self::NAMESPACE_ROOM, $key, $data, self::LONG_TTL);
                    $result[$key] = $data;
                }
            }
        }
        
        return $result;
    }
}
