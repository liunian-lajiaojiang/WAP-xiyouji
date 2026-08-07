<?php
/**
 * 封禁辅助类
 * 实现IP封禁、用户封禁、多端登录限制等功能
 */
class BanHelper {
    
    // 监禁房间路径（与数据库rooms表room_id格式一致）
    const PRISON_ROOM = 'wiz/prison';
    
    // 用户状态常量
    const STATUS_ACTIVE = 1;      // 正常
    const STATUS_BANNED = 2;      // 封禁
    const STATUS_PRISONED = 3;    // 监禁
    const STATUS_GUEST = 4;       // 欢迎室
    
    /**
     * 检查IP是否被封禁
     * @param string $ip 客户端IP
     * @return array|false 返回封禁信息或false
     */
    public static function checkIpBanned(string $ip) {
        // 获取所有有效的IP封禁规则
        $sql = "SELECT * FROM banned_ips WHERE expires_at IS NULL OR expires_at > NOW()";
        $bannedList = Database::queryAll($sql);
        
        foreach ($bannedList as $ban) {
            $pattern = $ban['ip_pattern'];
            // 将通配符*转换为正则表达式
            $pattern = str_replace('*', '.*', $pattern);
            $pattern = str_replace('.', '\.', $pattern);
            
            if (preg_match('/^' . $pattern . '$/', $ip)) {
                return $ban;
            }
        }
        
        return false;
    }
    
    /**
     * 检查用户是否被封禁
     * @param int $userId 用户ID
     * @return array|false 返回用户信息或false（表示被封禁）
     */
    public static function checkUserBanned(int $userId) {
        $user = Database::queryOne("SELECT id, username, status FROM users WHERE id = ?", [$userId]);
        
        if (!$user) {
            return false;
        }
        
        // status != 1 表示被封禁或监禁
        if ($user['status'] != self::STATUS_ACTIVE) {
            return $user;
        }
        
        return false;
    }
    
    /**
     * 检查同IP登录数量是否超限
     * @param string $ip 客户端IP
     * @return bool 是否超限
     */
    public static function checkLoginLimit(string $ip): bool {
        // 获取该IP的登录限制
        $limit = self::getLoginLimit($ip);
        
        // 统计当前IP的在线用户数
        $sql = "SELECT COUNT(*) as count FROM characters c 
                JOIN users u ON c.user_id = u.id 
                WHERE u.last_ip = ? AND c.online = 1";
        $result = Database::queryOne($sql, [$ip]);
        
        return $result['count'] >= $limit;
    }
    
    /**
     * 获取IP的登录限制数量
     * @param string $ip 客户端IP
     * @return int 最大登录数
     */
    public static function getLoginLimit(string $ip): int {
        $sql = "SELECT * FROM login_limits ORDER BY id";
        $limits = Database::queryAll($sql);
        
        foreach ($limits as $limit) {
            $pattern = $limit['ip_pattern'];
            // 通配符*匹配所有
            if ($pattern === '*') {
                return $limit['max_logins'];
            }
            
            // 将通配符*转换为正则表达式
            $pattern = str_replace('*', '.*', $pattern);
            $pattern = str_replace('.', '\.', $pattern);
            
            if (preg_match('/^' . $pattern . '$/', $ip)) {
                return $limit['max_logins'];
            }
        }
        
        return 3; // 默认限制
    }
    
    /**
     * 封禁IP
     * @param string $ipPattern IP模式
     * @param string $reason 封禁原因
     * @param int $banType 封禁类型
     * @param string $bannedBy 操作者
     * @param int|null $expiresIn 过期时间（秒），null为永久
     * @return bool 是否成功
     */
    public static function banIp(string $ipPattern, string $reason, int $banType = 1, ?string $bannedBy = null, ?int $expiresIn = null): bool {
        $expiresAt = null;
        if ($expiresIn !== null) {
            $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        }
        
        $sql = "INSERT INTO banned_ips (ip_pattern, reason, ban_type, banned_by, expires_at) VALUES (?, ?, ?, ?, ?)";
        return Database::execute($sql, [$ipPattern, $reason, $banType, $bannedBy, $expiresAt]) > 0;
    }
    
    /**
     * 解封IP
     * @param string $ipPattern IP模式
     * @return bool 是否成功
     */
    public static function unbanIp(string $ipPattern): bool {
        $sql = "DELETE FROM banned_ips WHERE ip_pattern = ?";
        return Database::execute($sql, [$ipPattern]) > 0;
    }
    
    /**
     * 封禁用户
     * @param int $userId 用户ID
     * @param int $status 状态值
     * @return bool 是否成功
     */
    public static function banUser(int $userId, int $status = self::STATUS_BANNED): bool {
        $sql = "UPDATE users SET status = ? WHERE id = ?";
        return Database::execute($sql, [$status, $userId]) > 0;
    }
    
    /**
     * 解封用户（统一恢复：封禁或监禁均适用）
     * 自动处理角色迁回、清理监禁记录
     * @param int $userId 用户ID
     * @return bool 是否成功
     */
    public static function unbanUser(int $userId): bool {
        $user = Database::queryOne("SELECT id, status FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            return false;
        }
        
        // 已经是正常状态，仍需确保角色迁回（防止之前被监禁但状态已恢复的情况）
        if ($user['status'] != self::STATUS_ACTIVE) {
            Database::execute("UPDATE users SET status = ? WHERE id = ?", [self::STATUS_ACTIVE, $userId]);
        }
        
        // 如果是监禁状态恢复，需要把角色迁出监禁室
        if ($user['status'] == self::STATUS_PRISONED) {
            self::moveOutOfPrison($userId);
        }
        
        return true;
    }
    
    /**
     * 将用户所有角色迁出监禁室（内部辅助方法）
     * @param int $userId 用户ID
     */
    private static function moveOutOfPrison(int $userId): void {
        // 将所有角色移到起始房间（南城客栈）
        $sql = "UPDATE characters SET current_area = 'city', current_room = 'city/kezhan' WHERE user_id = ? AND (current_room IN (?, ?, ?) OR current_area = 'wiz')";
        Database::execute($sql, [$userId, self::PRISON_ROOM, '/d/' . self::PRISON_ROOM, 'd/' . self::PRISON_ROOM]);
        
        // 清理监禁自动释放记录
        Database::execute(
            "DELETE FROM character_temp_states WHERE state_key = 'prison_release' AND char_id IN (SELECT id FROM characters WHERE user_id = ?)",
            [$userId]
        );
        
        // 发送释放消息给在线角色
        $char = Database::queryOne("SELECT id FROM characters WHERE user_id = ? AND online = 1 LIMIT 1", [$userId]);
        if ($char) {
            Database::execute(
                "INSERT INTO message_queue (char_id, message, type, from_char_id) VALUES (?, ?, 'private', 0)",
                [$char['id'], '[系统] 你已被释放！欢迎回到自由世界。']
            );
        }
    }
    
    /**
     * 监禁用户（将用户状态设为监禁，角色移到监禁房间）
     * 支持在线和离线用户
     * @param int $userId 用户ID
     * @param int|null $days 监禁天数（null=永久），到期后自动释放
     * @return bool 是否成功
     */
    public static function imprisonUser(int $userId, ?int $days = null): bool {
        // 设置用户状态为监禁
        $sql = "UPDATE users SET status = ? WHERE id = ?";
        $result = Database::execute($sql, [self::STATUS_PRISONED, $userId]);
        
        if ($result > 0) {
            // 将所有角色（包括离线角色）移到监禁房间
            $sql = "UPDATE characters SET current_room = ?, current_area = 'wiz' WHERE user_id = ?";
            Database::execute($sql, [self::PRISON_ROOM, $userId]);
            
            // 记录监禁信息到 character_temp_states（用于自动释放）
            if ($days !== null && $days > 0) {
                $releaseAt = time() + ($days * 86400);
                $releaseTimeStr = date('Y-m-d H:i:s', $releaseAt);
                $chars = Database::queryAll(
                    "SELECT id FROM characters WHERE user_id = ?",
                    [$userId]
                );
                foreach ($chars as $c) {
                    Database::execute(
                        "INSERT INTO character_temp_states (char_id, state_key, state_value, expire_time) 
                         VALUES (?, 'prison_release', ?, ?)
                         ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), expire_time = VALUES(expire_time)",
                        [$c['id'], $releaseTimeStr, $releaseTimeStr]
                    );
                }
            }
            
            // 发送消息给在线角色
            $char = Database::queryOne("SELECT id FROM characters WHERE user_id = ? AND online = 1 LIMIT 1", [$userId]);
            if ($char) {
                $msgSql = "INSERT INTO message_queue (char_id, message, type, from_char_id) VALUES (?, ?, 'private', 0)";
                $dayStr = ($days !== null && $days > 0) ? "（{$days}天后自动释放）" : '';
                Database::execute($msgSql, [$char['id'], "[系统] 你已被关入监禁室！{$dayStr}"]);
            }
        }
        
        return $result > 0;
    }
    
    /**
     * 释放用户（从监禁状态恢复）
     * @param int $userId 用户ID
     * @return bool 是否成功
     */
    public static function releaseUser(int $userId): bool {
        // 先检查用户当前状态
        $user = Database::queryOne("SELECT id, status FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            return false;
        }
        
        // 如果已经是正常状态，说明已释放过，但角色的位置可能还在监禁房
        $alreadyActive = ($user['status'] == self::STATUS_ACTIVE);
        
        if (!$alreadyActive) {
            // 设置用户状态为正常
            Database::execute("UPDATE users SET status = ? WHERE id = ?", [self::STATUS_ACTIVE, $userId]);
        }
        
        // 如果原来是监禁状态，迁出监禁室
        if ($user['status'] == self::STATUS_PRISONED || $alreadyActive) {
            self::moveOutOfPrison($userId);
        }
        
        return true;
    }
    
    /**
     * 检查角色是否在监禁房间
     * @param int $charId 角色ID
     * @return bool 是否在监禁房间
     */
    public static function isInPrison(int $charId): bool {
        $char = Database::queryOne("SELECT current_room FROM characters WHERE id = ?", [$charId]);
        return $char && $char['current_room'] === self::PRISON_ROOM;
    }
    
    /**
     * 检查用户是否被监禁
     * @param int $userId 用户ID
     * @return bool 是否被监禁
     */
    public static function isImprisoned(int $userId): bool {
        $user = Database::queryOne("SELECT status FROM users WHERE id = ?", [$userId]);
        return $user && $user['status'] == self::STATUS_PRISONED;
    }
    
    /**
     * 获取所有封禁IP列表
     * @return array
     */
    public static function getBannedIps(): array {
        $sql = "SELECT * FROM banned_ips ORDER BY created_at DESC";
        return Database::queryAll($sql);
    }
    
    /**
     * 获取被封禁/监禁的用户列表
     * @return array
     */
    public static function getBannedUsers(): array {
        $sql = "SELECT id, username, status FROM users WHERE status != ?";
        return Database::queryAll($sql, [self::STATUS_ACTIVE]);
    }
}