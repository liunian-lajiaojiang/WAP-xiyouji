<?php
/**
 * 年龄帮助类 - 处理角色年龄更新逻辑
 */
class AgeHelper
{
    /**
     * 更新角色年龄
     * 
     * @param int $charId 角色ID
     * @return bool 是否成功更新
     */
    public static function updateAge(int $charId): bool
    {
        try {
            $char = self::getCharacter($charId);
            if (!$char) {
                return false;
            }

            $mudAge = intval($char['mud_age'] ?? 0);
            $ageModify = intval($char['age_modify'] ?? 0);

            // 计算年龄（基于 mud_age，由 update_online_time 每次页面请求累积）
            $age = 14 + floor($ageModify / 86400) + floor($mudAge / 86400);
            if ($age < 1) {
                $age = 1;
            }

            // 只更新年龄，不再累加 mud_age（已由全局 update_online_time 处理）
            self::saveCharacterAge($charId, $age, $mudAge, intval($char['last_age_set'] ?? time()));
            
            return true;
        } catch (Exception $e) {
            error_log('AgeHelper::updateAge error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取角色信息
     */
    private static function getCharacter(int $charId): ?array
    {
        $sql = "SELECT id, mud_age, age_modify, last_age_set 
                FROM characters 
                WHERE id = ?";
        return Database::queryOne($sql, [$charId]);
    }

    /**
     * 保存角色年龄信息
     */
    private static function saveCharacterAge(int $charId, int $age, int $mudAge, int $lastAgeSet): void
    {
        $sql = "UPDATE characters 
                SET age = ?, mud_age = ?, last_age_set = ?
                WHERE id = ?";
        Database::execute($sql, [$age, $mudAge, $lastAgeSet, $charId]);
    }

    /**
     * 获取角色当前年龄
     * 
     * @param int $charId 角色ID
     * @return int 年龄（岁）
     */
    public static function getAge(int $charId): int
    {
        $char = self::getCharacter($charId);
        if (!$char) {
            return 14;
        }
        return intval($char['age'] ?? 14);
    }

    /**
     * 初始化新角色的年龄字段
     * 
     * @param int $charId 角色ID
     */
    public static function initNewCharacter(int $charId): void
    {
        $now = time();
        $sql = "UPDATE characters 
                SET age = 14, mud_age = 0, age_modify = 0, last_age_set = ?
                WHERE id = ?";
        Database::execute($sql, [$now, $charId]);
    }
}
