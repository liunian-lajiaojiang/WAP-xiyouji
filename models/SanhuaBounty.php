<?php
/**
 * 三花堂悬赏模型
 * 处理悬赏相关的数据库操作
 */

class SanhuaBounty {
    
    /**
     * 添加/追加悬赏
     * @param int $targetId 被悬赏玩家ID
     * @param string $targetName 被悬赏玩家名称
     * @param int $amount 悬赏金额（两黄金）
     * @param int $sponsorId 悬赏者ID
     * @param string $sponsorName 悬赏者名称
     * @return array 返回悬赏信息
     */
    public static function addBounty(int $targetId, string $targetName, int $amount, int $sponsorId, string $sponsorName): array {
        // 检查是否已有悬赏
        $existing = self::getBountyByTargetId($targetId);
        
        if ($existing) {
            // 追加悬赏
            $newAmount = $existing['amount'] + $amount;
            $sql = "UPDATE sanhua_bounties SET amount = ?, last_add_time = ? WHERE target_id = ?";
            Database::execute($sql, [$newAmount, time(), $targetId]);
        } else {
            // 新增悬赏
            $sql = "INSERT INTO sanhua_bounties (target_id, target_name, target_id_str, amount, last_add_time) VALUES (?, ?, ?, ?, ?)";
            Database::execute($sql, [$targetId, $targetName, (string)$targetId, $amount, time()]);
            $newAmount = $amount;
        }
        
        // 记录日志
        self::addLog('add', $targetId, $targetName, $amount, $sponsorId, $sponsorName);
        
        return self::getBountyByTargetId($targetId);
    }
    
    /**
     * 根据玩家ID获取悬赏
     * @param int $targetId 被悬赏玩家ID
     * @return array|null
     */
    public static function getBountyByTargetId(int $targetId): ?array {
        $sql = "SELECT * FROM sanhua_bounties WHERE target_id = ?";
        return Database::queryOne($sql, [$targetId]);
    }
    
    /**
     * 根据玩家ID字符串获取悬赏（兼容）
     * @param string $targetIdStr 被悬赏玩家ID字符串
     * @return array|null
     */
    public static function getBountyByTargetIdStr(string $targetIdStr): ?array {
        $sql = "SELECT * FROM sanhua_bounties WHERE target_id_str = ?";
        return Database::queryOne($sql, [$targetIdStr]);
    }
    
    /**
     * 获取所有悬赏（按金额从高到低排序）
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @return array
     */
    public static function getAllBounties(int $limit = 100, int $offset = 0): array {
        $sql = "SELECT * FROM sanhua_bounties ORDER BY amount DESC LIMIT {$limit} OFFSET {$offset}";
        return Database::queryAll($sql);
    }
    
    /**
     * 获取悬赏总数
     * @return int
     */
    public static function getBountyCount(): int {
        $sql = "SELECT COUNT(*) as count FROM sanhua_bounties";
        $result = Database::queryOne($sql, []);
        return intval($result['count'] ?? 0);
    }
    
    /**
     * 领取赏金
     * @param int $targetId 被悬赏玩家ID
     * @param int $claimerId 领取赏金者ID
     * @param string $claimerName 领取赏金者名称
     * @return array|null 返回悬赏信息，失败返回null
     */
    public static function claimBounty(int $targetId, int $claimerId, string $claimerName): ?array {
        $bounty = self::getBountyByTargetId($targetId);
        
        if (!$bounty) {
            return null;
        }
        
        $amount = $bounty['amount'];
        $targetName = $bounty['target_name'];
        
        // 删除悬赏
        $sql = "DELETE FROM sanhua_bounties WHERE target_id = ?";
        Database::execute($sql, [$targetId]);
        
        // 记录日志
        self::addLog('claim', $targetId, $targetName, $amount, null, null, $claimerId, $claimerName);
        
        return $bounty;
    }
    
    /**
     * 检查并处理过期悬赏
     * 超过7天无追加，收取30%保管费
     * @return int 处理的悬赏数量
     */
    public static function checkExpiredBounties(): int {
        $expireTime = 604800; // 7天 = 604800秒
        $now = time();
        
        // 获取所有可能过期的悬赏
        $sql = "SELECT * FROM sanhua_bounties WHERE last_add_time < ?";
        $bounties = Database::queryAll($sql, [$now - $expireTime]);
        
        $processed = 0;
        
        foreach ($bounties as $bounty) {
            $targetId = $bounty['target_id'];
            $targetName = $bounty['target_name'];
            $oldAmount = $bounty['amount'];
            
            // 收取30%保管费，剩余70%
            $newAmount = intval($oldAmount * 7 / 10);
            
            if ($newAmount < 1) {
                // 金额小于1两，删除悬赏
                $sql = "DELETE FROM sanhua_bounties WHERE target_id = ?";
                Database::execute($sql, [$targetId]);
                
                // 记录日志
                self::addLog('expire', $targetId, $targetName, $oldAmount);
            } else {
                // 更新金额和时间
                $sql = "UPDATE sanhua_bounties SET amount = ?, last_add_time = ? WHERE target_id = ?";
                Database::execute($sql, [$newAmount, $now, $targetId]);
                
                // 记录日志（扣除的金额）
                $deductedAmount = $oldAmount - $newAmount;
                self::addLog('expire', $targetId, $targetName, $deductedAmount);
            }
            
            $processed++;
        }
        
        return $processed;
    }
    
    /**
     * 添加日志
     * @param string $type 类型：add/claim/expire
     * @param int $targetId 被悬赏玩家ID
     * @param string $targetName 被悬赏玩家名称
     * @param int $amount 金额
     * @param int|null $sponsorId 悬赏者ID
     * @param string|null $sponsorName 悬赏者名称
     * @param int|null $claimerId 领取者ID
     * @param string|null $claimerName 领取者名称
     * @return bool
     */
    public static function addLog(string $type, int $targetId, string $targetName, int $amount, ?int $sponsorId = null, ?string $sponsorName = null, ?int $claimerId = null, ?string $claimerName = null): bool {
        $sql = "INSERT INTO sanhua_bounty_logs (type, target_id, target_name, sponsor_id, sponsor_name, amount, claimer_id, claimer_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        Database::execute($sql, [$type, $targetId, $targetName, $sponsorId, $sponsorName, $amount, $claimerId, $claimerName]);
        return true;
    }
    
    /**
     * 获取日志列表
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @return array
     */
    public static function getLogs(int $limit = 50, int $offset = 0): array {
        $sql = "SELECT * FROM sanhua_bounty_logs ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
        return Database::queryAll($sql);
    }
}
