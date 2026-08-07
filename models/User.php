<?php
/**
 * 用户模型
 */
class UserModel {
    
    /**
     * 根据ID查找用户
     */
    public static function find(int $userId): ?array {
        $sql = "SELECT * FROM users WHERE id = ?";
        return Database::queryOne($sql, [$userId]);
    }
    
    /**
     * 根据用户名查找
     */
    public static function findByUsername(string $username): ?array {
        $sql = "SELECT * FROM users WHERE username = ?";
        return Database::queryOne($sql, [$username]);
    }
    
    /**
     * 创建新用户
     */
    public static function create(array $data): int {
        $sql = "INSERT INTO users (username, password, wizard_level, status) VALUES (?, ?, ?, ?)";
        
        Database::execute($sql, [
            $data['username'],
            password_hash($data['password'], PASSWORD_DEFAULT),
            $data['wizard_level'] ?? 0,
            1
        ]);
        
        return intval(Database::lastInsertId());
    }
    
    /**
     * 验证密码
     */
    public static function verifyPassword(string $username, string $password): bool {
        $user = self::findByUsername($username);
        if (!$user) {
            return false;
        }
        
        return password_verify($password, $user['password']);
    }
    
    /**
     * 更新最后登录信息
     */
    public static function updateLastLogin(int $userId, string $ip): bool {
        $sql = "UPDATE users SET last_login = NOW(), last_ip = ? WHERE id = ?";
        return Database::execute($sql, [$ip, $userId]) > 0;
    }
    
    /**
     * 更新VIP等级
     */
    public static function updateVipLevel(int $userId, int $vipLevel): bool {
        $sql = "UPDATE users SET vip_level = ? WHERE id = ?";
        return Database::execute($sql, [$vipLevel, $userId]) > 0;
    }
    
    /**
     * 更新用户状态
     */
    public static function updateStatus(int $userId, int $status): bool {
        $sql = "UPDATE users SET status = ? WHERE id = ?";
        return Database::execute($sql, [$status, $userId]) > 0;
    }
    
    /**
     * 判断用户是否为巫师（神仙及以上）
     * 与 WizardHelper::isWizard() 对齐，要求 wizard_level >= 2
     */
    public static function isWizard(int $userId): bool {
        $user = self::find($userId);
        if (!$user) {
            return false;
        }
        return intval($user['wizard_level']) >= 2;
    }
    
    /**
     * 判断用户是否为长老及以上（含长老）
     * 用于 wiz 区域入口等需要更宽松判定的场景
     */
    public static function isElder(int $userId): bool {
        $user = self::find($userId);
        if (!$user) {
            return false;
        }
        return intval($user['wizard_level']) >= 1;
    }
}