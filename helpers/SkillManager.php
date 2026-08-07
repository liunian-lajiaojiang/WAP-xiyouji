<?php
/**
 * 技能管理器
 * 负责技能的查询、练习、提升等功能
 */
require_once __DIR__ . '/../includes/db.php';

class SkillManager {
    
    /**
     * 基础技能类型列表
     * 这些是"分类"技能，在 raw=false 时等级会除以2
     * 具体技能（如 buddhism, daoism, taiyi 等）不在列表中，直接返回原始等级
     */
    private const BASE_SKILL_TYPES = [
        'unarmed', 'sword', 'blade', 'stick', 'staff', 'throwing',
        'force', 'parry', 'dodge', 'spells', 'whip', 'spear',
        'axe', 'mace', 'fork', 'rake', 'archery', 'hammer',
        'magic', 'literate'
    ];
    
    /**
     * 判断是否为基础技能类型
     * @param string $skillId 技能ID
     * @return bool
     */
    public static function isBaseSkillType(string $skillId): bool {
        return in_array($skillId, self::BASE_SKILL_TYPES);
    }
    
    /**
     * 查询角色已映射的技能
     * @param int $charId 角色ID
     * @param string $skillType 技能类型（如 sword, blade, unarmed, dodge, parry, force 等）
     * @return string|null 映射的技能ID，未映射则返回null
     */
    public static function querySkillMapped(int $charId, string $skillType): ?string {
        $sql = "SELECT mapped_skill FROM character_skill_map WHERE char_id = ? AND skill_type = ? LIMIT 1";
        $result = Database::queryOne($sql, [$charId, $skillType]);
        return $result ? $result['mapped_skill'] : null;
    }
    
    /**
     * 查询角色的技能等级
     * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
     * 
     * @param int $charId 角色ID
     * @param string $skillId 技能ID
     * @param bool $raw 是否返回原始等级（true=基础等级，false=含加成和映射的最终等级）
     * @return int 技能等级，未学习则返回0
     */
    public static function querySkill(int $charId, string $skillId, bool $raw = false): int {
        $baseLevel = self::getBaseSkillLevel($charId, $skillId);
        
        if (!$raw) {
            $mappedLevel = self::getMappedSkillLevel($charId, $skillId);
            $tempBonus = self::getTempSkillBonus($charId, $skillId);
            
            // 基础技能类型（force, spells, dodge等）：等级除以2 + 映射技能等级 + 临时加成
            // 具体技能（buddhism, daoism, taiyi等）：直接返回原始等级 + 临时加成
            if (self::isBaseSkillType($skillId)) {
                return intval($baseLevel / 2) + $mappedLevel + $tempBonus;
            } else {
                return $baseLevel + $tempBonus;
            }
        }
        
        // 返回原始基础等级
        return $baseLevel;
    }
    
    /**
     * 获取技能配置信息
     * @param string $skillId 技能ID
     * @return array|null 技能配置数组，不存在则返回null
     */
    public static function getSkillConfig(string $skillId): ?array {
        $sql = "SELECT * FROM skills WHERE skill_id = ? LIMIT 1";
        $result = Database::queryOne($sql, [$skillId]);
        return $result ?: null;
    }
    
    /**
     * 获取角色的技能等级（别名方法，与querySkill功能相同）
     * @param int $charId 角色ID
     * @param string $skillId 技能ID
     * @return int 技能等级
     */
    public static function getSkillLevel(int $charId, string $skillId): int {
        return self::querySkill($charId, $skillId, true); // 默认返回基础等级
    }
    
    /**
     * 获取基础技能等级（从数据库）
     * @param int $charId 角色ID
     * @param string $skillId 技能ID
     * @return int 基础等级
     */
    private static function getBaseSkillLevel(int $charId, string $skillId): int {
        $sql = "SELECT level FROM character_skills WHERE char_id = ? AND skill_id = ? LIMIT 1";
        $result = Database::queryOne($sql, [$charId, $skillId]);
        return $result ? intval($result['level']) : 0;
    }
    
    /**
     * 获取映射技能的等级加成
     * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
     * @param int $charId 角色ID
     * @param string $skillId 原始技能ID
     * @return int 映射技能提供的等级加成
     */
    private static function getMappedSkillLevel(int $charId, string $skillId): int {
        $mappedSkill = self::querySkillMapped($charId, $skillId);
        if (!$mappedSkill) {
            return 0;
        }
        
        return self::getBaseSkillLevel($charId, $mappedSkill);
    }
    
    /**
     * 获取临时技能加成（来自装备、buff等）
     * @param int $charId 角色ID
     * @param string $skillId 技能ID
     * @return int 临时加成
     */
    private static function getTempSkillBonus(int $charId, string $skillId): int {
        // TODO: 从 session 或数据库中读取临时加成
        $tempKey = "skill_bonus_{$charId}_{$skillId}";
        return $_SESSION[$tempKey] ?? 0;
    }
    
    /**
     * 映射技能
     * @param int $charId 角色ID
     * @param string $skillType 技能类型（如 sword, blade, unarmed 等）
     * @param string|null $mappedSkill 要映射的技能ID，null表示取消映射
     * @return bool 是否成功
     */
    public static function mapSkill(int $charId, string $skillType, ?string $mappedSkill): bool {
        if ($mappedSkill === null) {
            // 取消映射
            $sql = "DELETE FROM character_skill_map WHERE char_id = ? AND skill_type = ?";
            Database::execute($sql, [$charId, $skillType]);
            return true;
        }
        
        // 验证技能是否存在
        $skillConfig = self::getSkillConfig($mappedSkill);
        if (!$skillConfig) {
            return false;
        }
        
        // 检查角色是否学习了该技能（使用原始等级，不使用计算后的等级）
        $skillLevel = self::querySkill($charId, $mappedSkill, true);
        if ($skillLevel < 1) {
            return false;
        }
        
        // 插入或更新映射
        $sql = "INSERT INTO character_skill_map (char_id, skill_type, mapped_skill) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE mapped_skill = ?";
        Database::execute($sql, [$charId, $skillType, $mappedSkill, $mappedSkill]);
        return true;
    }
    
    /**
     * 获取角色的所有技能
     * @param int $charId 角色ID
     * @return array 技能列表
     */
    public static function getAllSkills(int $charId): array {
        $sql = "SELECT cs.skill_id, cs.level, cs.exp, s.name, s.type, s.category 
                FROM character_skills cs
                LEFT JOIN skills s ON cs.skill_id = s.skill_id
                WHERE cs.char_id = ?
                ORDER BY s.type, cs.level DESC";
        return Database::queryAll($sql, [$charId]);
    }
    
    /**
     * 获取指定类型的所有可用技能
     * @param string $type 技能类型（martial, magic, force, dodge, parry等）
     * @return array 技能列表
     */
    public static function getAllSkillsByType(string $type): array {
        $sql = "SELECT * FROM skills WHERE type = ? ORDER BY skill_id";
        return Database::queryAll($sql, [$type]);
    }
    
    /**
     * 获取技能的所有招式动作
     * @param string $skillId 技能ID
     * @return array 招式动作列表
     */
    public static function getSkillActions(string $skillId): array {
        $sql = "SELECT * FROM skill_actions WHERE skill_id = ? ORDER BY id";
        $actions = Database::queryAll($sql, [$skillId]);
        
        // 给普通招式添加默认消耗：1点内力 + 1点法力
        // 特殊招式（已经设置了消耗的）保持原样
        foreach ($actions as &$action) {
            $forceCost = intval($action['force_cost'] ?? 0);
            $manaCost = intval($action['mana_cost'] ?? 0);
            
            // 如果内力消耗为0，默认设为1
            if ($forceCost <= 0) {
                $action['force_cost'] = 1;
            }
            
            // 如果法力消耗为0，默认设为1
            if ($manaCost <= 0) {
                $action['mana_cost'] = 1;
            }
        }
        unset($action); // 解除引用
        
        return $actions;
    }
    
    /**
     * 获取技能的随机动作文本
     * @param string $skillId 技能ID
     * @return string|null 动作文本，无动作则返回null
     */
    public static function getRandomActionText(string $skillId): ?string {
        $actions = self::getSkillActions($skillId);
        if (empty($actions)) {
            return null;
        }
        
        // 过滤掉 action_text 为空的招式
        $validActions = array_filter($actions, function($action) {
            return !empty($action['action_text']);
        });
        
        if (empty($validActions)) {
            return null;
        }
        
        // 随机选择一个动作
        $randomAction = $validActions[array_rand($validActions)];
        return $randomAction['action_text'];
    }
    
    /**
     * 获取特定招式的详细信息
     * @param string $skillId 技能ID
     * @param string $actionCode 招式代码
     * @return array|null 招式信息，不存在则返回null
     */
    public static function getPerformAction(string $skillId, string $actionCodeOrName): ?array {
        // 先按 action_code 查找
        $sql = "SELECT * FROM skill_actions WHERE skill_id = ? AND action_code = ? LIMIT 1";
        $result = Database::queryOne($sql, [$skillId, $actionCodeOrName]);
        
        // 如果没找到，再按 action_name 查找
        if (!$result) {
            $sql = "SELECT * FROM skill_actions WHERE skill_id = ? AND action_name = ? LIMIT 1";
            $result = Database::queryOne($sql, [$skillId, $actionCodeOrName]);
        }
        
        if (!$result) {
            return null;
        }
        
        // 给普通招式添加默认消耗：1点内力 + 1点法力
        $forceCost = intval($result['force_cost'] ?? 0);
        $manaCost = intval($result['mana_cost'] ?? 0);
        
        if ($forceCost <= 0) {
            $result['force_cost'] = 1;
        }
        if ($manaCost <= 0) {
            $result['mana_cost'] = 1;
        }
        
        return $result;
    }
    
    /**
     * 获取技能的中文名称
     * @param string $skillId 技能ID
     * @return string 技能中文名，不存在则返回技能ID
     */
    public static function getSkillChineseName(string $skillId): string {
        $config = self::getSkillConfig($skillId);
        return $config ? $config['name'] : $skillId;
    }
    
    /**
     * 检查角色是否可以学习某技能
     * @param int $charId 角色ID
     * @param string $skillId 技能ID
     * @return bool 是否可以学习
     */
    public static function canLearn(int $charId, string $skillId): array {
        $character = \CharacterModel::find($charId);
        if (!$character) {
            return ['allowed' => false, 'reason' => '角色不存在'];
        }
        
        $skillConfig = self::getSkillConfig($skillId);
        if (!$skillConfig) {
            return ['allowed' => false, 'reason' => '技能配置不存在'];
        }
        
        // 检查等级要求
        if (isset($skillConfig['level_required']) && $character['level'] < $skillConfig['level_required']) {
            return ['allowed' => false, 'reason' => '等级不足，需要等级 ' . $skillConfig['level_required']];
        }
        
        // 检查道行要求
        if (isset($skillConfig['daoxing_required']) && $character['daoxing'] < $skillConfig['daoxing_required']) {
            return ['allowed' => false, 'reason' => '道行不足，需要道行 ' . $skillConfig['daoxing_required']];
        }
        
        // 检查实战经验要求
        if (isset($skillConfig['combat_exp_required']) && $character['combat_exp'] < $skillConfig['combat_exp_required']) {
            return ['allowed' => false, 'reason' => '实战经验不足'];
        }
        
        // 检查 valid_learn 中的限制条件
        if (!empty($skillConfig['valid_learn'])) {
            $validLearn = json_decode($skillConfig['valid_learn'], true);
            if (is_array($validLearn)) {
                // 检查门派限制
                if (isset($validLearn['family_required'])) {
                    $requiredFamily = $validLearn['family_required'];
                    $charFamily = $character['family'] ?? '';
                    if (!empty($requiredFamily) && $charFamily !== $requiredFamily) {
                        return ['allowed' => false, 'reason' => '你不属于学习此技能所需的门派'];
                    }
                }
                
                // 检查内力要求（max_force 实际是最小内力要求）
                if (isset($validLearn['max_force'])) {
                    $charForce = $character['max_force'] ?? 0;
                    if ($charForce < $validLearn['max_force']) {
                        return ['allowed' => false, 'reason' => '内力不足，需要内力 ' . $validLearn['max_force']];
                    }
                }
                
                // 检查法术基础要求
                if (isset($validLearn['spells_base'])) {
                    $charSpells = self::querySkill($charId, 'spells');
                    if ($charSpells < $validLearn['spells_base']) {
                        return ['allowed' => false, 'reason' => '法术基础不足，需要法术等级 ' . $validLearn['spells_base']];
                    }
                }
                
                // 检查武器要求
                if (isset($validLearn['weapon_type'])) {
                    require_once __DIR__ . '/WeaponHelper.php';
                    $equippedWeapon = \WeaponHelper::getEquippedWeapon($charId);
                    if (!$equippedWeapon) {
                        return ['allowed' => false, 'reason' => '需要装备武器'];
                    }
                    $weaponType = \WeaponHelper::getWeaponTypeByItemId($equippedWeapon['item_id'] ?? '');
                    if ($weaponType !== $validLearn['weapon_type']) {
                        return ['allowed' => false, 'reason' => '装备的武器类型不正确'];
                    }
                }
            }
        }
        
        // 检查前置技能
        if (!empty($skillConfig['prerequisite_skills'])) {
            $prerequisites = json_decode($skillConfig['prerequisite_skills'], true);
            if (is_array($prerequisites)) {
                foreach ($prerequisites as $prereqSkill => $prereqLevel) {
                    $currentLevel = self::querySkill($charId, $prereqSkill);
                    if ($currentLevel < $prereqLevel) {
                        return ['allowed' => false, 'reason' => '前置技能不足'];
                    }
                }
            }
        }
        
        return ['allowed' => true, 'reason' => ''];
    }
    
    /**
     * 练习技能（增加技能经验）
     * @param int $charId 角色ID
     * @param string $skillId 技能ID
     * @return array ['success' => bool, 'message' => string, 'exp_gained' => int]
     */
    public static function practiceSkill(int $charId, string $skillId): array {
        $character = \CharacterModel::find($charId);
        if (!$character) {
            return ['success' => false, 'message' => '角色不存在', 'exp_gained' => 0];
        }
        
        // 检查是否可以学习
        $canLearn = self::canLearn($charId, $skillId);
        if (!$canLearn['allowed']) {
            return ['success' => false, 'message' => $canLearn['reason'], 'exp_gained' => 0];
        }
        
        // 获取当前技能等级和经验
        $sql = "SELECT level, exp FROM character_skills WHERE char_id = ? AND skill_id = ? LIMIT 1";
        $skillData = Database::queryOne($sql, [$charId, $skillId]);
        
        if (!$skillData) {
            // 第一次学习，初始化
            $level = 1;
            $exp = 0;
            $sql = "INSERT INTO character_skills (char_id, skill_id, level, exp) VALUES (?, ?, 1, 0)";
            Database::execute($sql, [$charId, $skillId]);
        } else {
            $level = $skillData['level'];
            $exp = $skillData['exp'];
        }
        
        // 检查是否已达到最大等级
        $skillConfig = self::getSkillConfig($skillId);
        $maxLevel = $skillConfig ? intval($skillConfig['max_level'] ?? 100) : 100;
        
        if ($level >= $maxLevel) {
            return ['success' => false, 'message' => '你的' . self::getSkillChineseName($skillId) . '已经练到最高境界了', 'exp_gained' => 0];
        }
        
        // 计算获得的经验（基于角色的悟性和当前等级）
        $cps = $character['cps'] ?? 10;
        $baseExp = mt_rand(5, 15) + intval($cps / 5);
        $expGained = max(1, intval($baseExp * (1.0 - $level / 200.0)));
        
        // 更新技能经验
        $newExp = $exp + $expGained;
        $sql = "UPDATE character_skills SET exp = ? WHERE char_id = ? AND skill_id = ?";
        Database::execute($sql, [$newExp, $charId, $skillId]);
        
        return [
            'success' => true,
            'message' => '你开始练习' . self::getSkillChineseName($skillId),
            'exp_gained' => $expGained
        ];
    }
    
    /**
     * 提升技能等级（使用潜能将经验转化为等级）
     * @param int $charId 角色ID
     * @param string $skillId 技能ID
     * @param int $improveAmount 提升幅度（可选，默认自动计算）
     * @param bool $shouldLimit 是否限制提升幅度
     * @param float $sectBonus 门派加成系数
     * @return array ['success' => bool, 'message' => string, 'level_up' => int]
     */
    public static function improveSkill(int $charId, string $skillId, int $improveAmount = 0, bool $shouldLimit = true, float $sectBonus = 1.0): array {
        $character = \CharacterModel::find($charId);
        if (!$character) {
            return ['success' => false, 'message' => '角色不存在', 'level_up' => 0];
        }
        
        // 获取当前技能数据
        $sql = "SELECT level, exp FROM character_skills WHERE char_id = ? AND skill_id = ? LIMIT 1";
        $skillData = Database::queryOne($sql, [$charId, $skillId]);
        
        if (!$skillData) {
            // 首次学习：初始化技能记录
            $level = 1;
            $exp = 0;
            $sql = "INSERT INTO character_skills (char_id, skill_id, level, exp) VALUES (?, ?, 1, 0)";
            Database::execute($sql, [$charId, $skillId]);
            $skillData = ['level' => 1, 'exp' => 0];
        }
        
        $level = $skillData['level'];
        $exp = $skillData['exp'];
        
        // 检查是否已达到最大等级
        $skillConfig = self::getSkillConfig($skillId);
        $maxLevel = $skillConfig ? intval($skillConfig['max_level'] ?? 100) : 100;
        
        if ($level >= $maxLevel) {
            return ['success' => false, 'message' => '你的' . self::getSkillChineseName($skillId) . '已经练到最高境界了', 'level_up' => 0];
        }
        
        // 计算提升到下一级所需经验
        $expNeeded = self::calculateExpNeeded($level);
        
        if ($exp < $expNeeded) {
            return ['success' => false, 'message' => '你的' . self::getSkillChineseName($skillId) . '还需要继续练习', 'level_up' => 0];
        }
        
        // 检查潜能是否足够
        $potentialCost = self::calculatePotentialCost($level);
        if ($character['potential'] < $potentialCost) {
            return ['success' => false, 'message' => '你的潜能不足，无法提升' . self::getSkillChineseName($skillId), 'level_up' => 0];
        }
        
        // 提升等级
        $newLevel = $level + 1;
        $newExp = $exp - $expNeeded;
        
        // 应用门派加成
        if ($sectBonus > 1.0) {
            $bonusLevels = intval(($newLevel - $level) * ($sectBonus - 1.0));
            if ($bonusLevels > 0) {
                $newLevel += $bonusLevels;
            }
        }
        
        // 更新数据库
        $sql = "UPDATE character_skills SET level = ?, exp = ? WHERE char_id = ? AND skill_id = ?";
        Database::execute($sql, [$newLevel, $newExp, $charId, $skillId]);
        
        // 扣除潜能
        $sql = "UPDATE characters SET potential = potential - ? WHERE id = ?";
        Database::execute($sql, [$potentialCost, $charId]);
        
        return [
            'success' => true,
            'message' => '你的' . self::getSkillChineseName($skillId) . '提升了！当前等级：' . $newLevel,
            'level_up' => $newLevel - $level
        ];
    }
    
    /**
     * 计算提升到下一级所需经验
     * @param int $currentLevel 当前等级
     * @return int 所需经验
     */
    private static function calculateExpNeeded(int $currentLevel): int {
        // 经验需求公式：等级^2 * 10 + 等级 * 50
        return intval(pow($currentLevel, 2) * 10 + $currentLevel * 50);
    }
    
    /**
     * 计算提升等级所需潜能
     * @param int $currentLevel 当前等级
     * @return int 所需潜能
     */
    private static function calculatePotentialCost(int $currentLevel): int {
        // 潜能消耗公式：等级 + 10
        return $currentLevel + 10;
    }
    
    /**
     * 查询最大法力
     * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
     * 
     * @param int $charId 角色ID
     * @return int 最大法力值
     */
    public static function queryMaxMana(int $charId): int {
        $character = \CharacterModel::find($charId);
        if (!$character) {
            return 0;
        }
        
        $s = 0;
        
        // spells 技能贡献
        $spellsLevel = self::getBaseSkillLevel($charId, 'spells');
        $s += intval($spellsLevel / 2);
        
        // 映射的 spells 技能贡献
        $mappedSpells = self::querySkillMapped($charId, 'spells');
        if ($mappedSpells) {
            $s += self::getBaseSkillLevel($charId, $mappedSpells);
        }
        
        // 转换为法力值（每点技能等级 = 10 法力）
        return $s * 10;
    }
    
    /**
     * 查询最大内力
     * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
     * 
     * @param int $charId 角色ID
     * @return int 最大内力值
     */
    public static function queryMaxForce(int $charId): int {
        $character = \CharacterModel::find($charId);
        if (!$character) {
            return 0;
        }
        
        $s = 0;
        
        // force 技能贡献
        $forceLevel = self::getBaseSkillLevel($charId, 'force');
        $s += intval($forceLevel / 2);
        
        // 映射的 force 技能贡献
        $mappedForce = self::querySkillMapped($charId, 'force');
        if ($mappedForce) {
            $s += self::getBaseSkillLevel($charId, $mappedForce);
        }
        
        // 转换为内力值（每点技能等级 = 10 内力）
        return $s * 10;
    }

    /**
     * 查询最大灵力
     * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
     * 
     * @param int $charId 角色ID
     * @return int 最大灵力值
     */
    public static function queryMaxAtman(int $charId): int {
        $character = \CharacterModel::find($charId);
        if (!$character) {
            return 0;
        }
        
        $s = 0;
        
        // magic 技能贡献
        $magicLevel = self::getBaseSkillLevel($charId, 'magic');
        $s += intval($magicLevel / 2);
        
        // 映射的 magic 技能贡献
        $mappedMagic = self::querySkillMapped($charId, 'magic');
        if ($mappedMagic) {
            $s += self::getBaseSkillLevel($charId, $mappedMagic);
        }
        
        // 转换为灵力值（每点技能等级 = 10 灵力）
        return $s * 10;
    }

    /**
     * 计算技能命中时的伤害加成（模拟原项目hit_ob回调）
     * @param int $charId 角色/NPC ID
     * @param string $skillId 技能ID
     * @param int $baseDamage 基础伤害
     * @param bool $isNpc 是否为NPC
     * @return int 额外伤害加成
     */
    public static function calculateHitBonus($charId, $skillId, $baseDamage, $isNpc = false): int
    {
        try {
            if ($isNpc) {
                // NPC：从 npc_skills 表获取技能等级
                $sql = "SELECT skill_level FROM npc_skills WHERE npc_id = ? AND skill_name = ? LIMIT 1";
                $result = Database::queryOne($sql, [$charId, $skillId]);
                $skillLevel = $result ? intval($result['skill_level']) : 0;
            } else {
                // 玩家：从 character_skills 表获取技能等级
                $skillLevel = self::getSkillLevel($charId, $skillId);
            }

            if ($skillLevel <= 0 || $baseDamage <= 0) {
                return 0;
            }

            // 伤害加成 = floor(技能等级 * 基础伤害 / 200)
            return max(0, intval(floor($skillLevel * $baseDamage / 200)));
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
     * 
     * 公式: damage = floor(force/20) + force_factor - floor(victim_force/25)
     * 反震: 当 damage < 0 且攻击者徒手时，若防守方内功技能 > 攻击者内功技能/2，
     *       反震伤害 = abs(damage) * 2 回扣攻击者
     * 技能门控: random(atk_force_skill) < damage 时生效，否则 fizzle
     * 
     * @param int $attackerId 攻击者ID
     * @param array $attacker 攻击者数据
     * @param array $defender 防守方数据（需含 force, force_skill_level）
     * @param bool $attackerUnarmed 攻击者是否徒手
     * @return array ['damage' => int, 'reflected' => bool, 'reflect_damage' => int, 'fizzled' => bool]
     */
    public static function calculateForceHitOb(int $attackerId, array $attacker, array $defender, bool $attackerUnarmed): array {
        $result = ['damage' => 0, 'reflected' => false, 'reflect_damage' => 0, 'fizzled' => false];
        
        try {
            // 获取攻击者内力和 force_factor
            $force = intval($attacker['force'] ?? 0);
            $forceFactor = intval($attacker['force_factor'] ?? 0);
            $availableForce = $force - $forceFactor;
            
            // 获取防守方内力
            $victimForce = intval($defender['force'] ?? 0);
            
            // 核心公式
            $damage = intval(floor($availableForce / 20)) + $forceFactor - intval(floor($victimForce / 25));
            
            // 获取双方内功技能等级
            $atkForceSkill = intval($defender['attacker_force_skill'] ?? 0);
            $victimForceSkill = intval($defender['force_skill_level'] ?? 0);
            
            // 反震判定：damage < 0 且徒手
            if ($damage < 0 && $attackerUnarmed && $victimForceSkill > 0) {
                if (mt_rand(0, max(1, $victimForceSkill) - 1) > intval($atkForceSkill / 2)) {
                    // 反震成功：攻击者受到 abs(damage)*2 伤害
                    $result['reflected'] = true;
                    $result['reflect_damage'] = abs($damage) * 2;
                    return $result;
                }
            }
            
            // damage < 0 但无反震 → 无效果
            if ($damage < 0) {
                return $result;
            }
            
            // 减去 armor_vs_force（防守方内功抗性，简化为0）
            // 在原始项目中 armor_vs_force 由装备提供，当前项目暂未实现
            
            // 技能门控：random(atk_force_skill) < damage → 生效
            if ($atkForceSkill > 0 && mt_rand(0, max(1, $atkForceSkill) - 1) >= $damage) {
                // 技能不足，fizzle
                $result['fizzled'] = true;
                return $result;
            }
            
            $result['damage'] = max(0, $damage);
            return $result;
            
        } catch (\Exception $e) {
            return $result;
        }
    }

    /**
     * 战斗中技能增长（还原原始 LPC improve_skill 机制）
     * 委托给 improveSkillOriginal()，使用学习进度累积制：
     * - 每次战斗 tick 增加 amount=1 的经验
     * - 升级条件：exp > (level+1)^2
     * - 多技能惩罚：已学技能数 > 悟性时，经验增量 /= (技能数 - 悟性)
     */
    public static function combatImproveSkill(int $charId, string $skillId): bool {
        // 验证技能已学习（原始等级 > 0）
        $skillData = Database::queryOne(
            "SELECT level FROM character_skills WHERE char_id = ? AND skill_id = ? LIMIT 1",
            [$charId, $skillId]
        );
        if (!$skillData || intval($skillData['level']) <= 0) {
            return false;
        }
        
        // 委托给 improveSkillOriginal，amount=1, weakMode=false（可自动升级）
        $result = self::improveSkillOriginal($charId, $skillId, 1, false);
        
        return $result['leveled_up'] ?? false;
    }
    
    /**
     * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
     * 
     * @param int $charId 角色ID
     * @param string $skillId 技能ID
     * @param int $amount 经验增加量
     * @param bool $weakMode 弱模式（true=只增加经验不自动升级，false=可以自动升级）
     * @return array ['success' => bool, 'leveled_up' => bool, 'new_level' => int]
     */
    public static function improveSkillOriginal(int $charId, string $skillId, int $amount, bool $weakMode = false): array {
        $character = \CharacterModel::find($charId);
        if (!$character) {
            return ['success' => false, 'leveled_up' => false, 'new_level' => 0];
        }
        
        // 获取当前技能数据
        $sql = "SELECT level, exp FROM character_skills WHERE char_id = ? AND skill_id = ? LIMIT 1";
        $skillData = Database::queryOne($sql, [$charId, $skillId]);
        
        if (!$skillData) {
            // 首次学习：初始化技能记录
            $sql = "INSERT INTO character_skills (char_id, skill_id, level, exp) VALUES (?, ?, 0, 0)";
            Database::execute($sql, [$charId, $skillId]);
            $skillData = ['level' => 0, 'exp' => 0];
        }
        
        $level = $skillData['level'];
        $exp = $skillData['exp'];
        
        // 学习惩罚：如果已学技能数量超过灵性，则经验增加量减少
        $spi = intval($character['int'] ?? 10); // 使用悟性作为灵性
        $allSkills = self::getAllSkills($charId);
        $learnedCount = count($allSkills);
        
        if ($learnedCount > $spi && $amount > 0) {
            $amount = max(1, intval($amount / ($learnedCount - $spi)));
        }
        
        // 增加经验
        $newExp = $exp + $amount;
        $leveledUp = false;
        $newLevel = $level;
        
        // 升级条件：经验 > (等级+1)^2
        // 在弱模式下（weakMode=true），不自动升级
        if (!$weakMode) {
            $expNeeded = ($level + 1) * ($level + 1);
            if ($newExp > $expNeeded) {
                $newLevel = $level + 1;
                $newExp = 0;
                $leveledUp = true;
            }
        }
        
        // 更新数据库
        $sql = "UPDATE character_skills SET level = ?, exp = ? WHERE char_id = ? AND skill_id = ?";
        Database::execute($sql, [$newLevel, $newExp, $charId, $skillId]);
        
        return [
            'success' => true,
            'leveled_up' => $leveledUp,
            'new_level' => $newLevel,
            'exp_gained' => $amount
        ];
    }
}

