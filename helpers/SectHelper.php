<?php
/**
 * 门派帮助类
 */
class SectHelper {
    
    /**
     * 根据NPC ID获取门派信息
     * 
     * @param int $npcId NPC ID
     * @return array|null 门派信息，如果不是掌门NPC则返回null
     */
    public static function getSectByNpcId(int $npcId): ?array {
        try {
            // 从数据库查询NPC是否为可拜师师父
            $npc = Database::queryOne(
                'SELECT id, is_master, sect_key FROM npcs WHERE id = ?',
                [$npcId]
            );

            if (!$npc || (int)$npc['is_master'] !== 1) {
                return null;
            }

            $sectKey = $npc['sect_key'] ?? '';
            if (empty($sectKey)) {
                return null;
            }

            // 从 sects.php 获取门派完整配置
            $sectConfig = require __DIR__ . '/../config/sects.php';
            $sects = $sectConfig['sects'] ?? [];

            $sect = $sects[$sectKey] ?? null;
            if (!$sect) {
                return null;
            }

            return array_merge($sect, ['key' => $sectKey]);
        } catch (\Exception $e) {
            error_log('SectHelper::getSectByNpcId error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 获取门派信息（从 config/sects.php 读取）
     * 
     * @param string $sectName 门派key或中文名
     * @return array|null 门派信息
     */
    public static function getSectInfo(string $sectName): ?array {
        $sectConfig = require __DIR__ . '/../config/sects.php';
        $sects = $sectConfig['sects'] ?? [];
        
        // 先按 key 查找
        if (isset($sects[$sectName])) {
            $sect = $sects[$sectName];
            return [
                'name' => $sect['name'] ?? $sectName,
                'master' => $sect['master_npc'] ?? '',
                'location' => $sect['location'] ?? '',
                'skills' => array_merge(
                    array_keys($sect['skills']['exclusive'] ?? []),
                    array_keys($sect['skills']['important'] ?? [])
                ),
                'key' => $sectName,
            ];
        }
        
        // 再按中文名查找
        foreach ($sects as $key => $sect) {
            if (($sect['name'] ?? '') === $sectName || ($sect['short_name'] ?? '') === $sectName) {
                return [
                    'name' => $sect['name'] ?? $sectName,
                    'master' => $sect['master_npc'] ?? '',
                    'location' => $sect['location'] ?? '',
                    'skills' => array_merge(
                        array_keys($sect['skills']['exclusive'] ?? []),
                        array_keys($sect['skills']['important'] ?? [])
                    ),
                    'key' => $key,
                ];
            }
        }
        
        return null;
    }
    
    /**
     * 检查角色是否属于某个门派
     * 
     * @param int $charId 角色ID
     * @return array|null 门派信息，如果角色没有加入门派则返回null
     */
    public static function getCharSect(int $charId): ?array {
        try {
            $result = Database::queryOne(
                "SELECT family as sect_key, master_id, master_name, generation FROM characters WHERE id = ?",
                [$charId]
            );
            
            if ($result && !empty($result['sect_key'])) {
                $sectInfo = self::getSectInfo($result['sect_key']);
                if ($sectInfo) {
                    return array_merge($sectInfo, [
                        'master_id' => $result['master_id'],
                        'master_name' => $result['master_name'],
                        'generation' => $result['generation'],
                    ]);
                }
                // 如果从 config/sects.php 找不到，返回基本数据
                return [
                    'name' => $result['sect_key'],
                    'master' => $result['master_name'] ?? '',
                    'location' => '',
                    'skills' => [],
                    'key' => $result['sect_key'],
                    'master_id' => $result['master_id'],
                    'master_name' => $result['master_name'],
                    'generation' => $result['generation'],
                ];
            }
        } catch (\Exception $e) {
            error_log('SectHelper::getCharSect error: ' . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * 获取所有门派列表
     * 
     * @return array 门派列表
     */
    public static function getAllSects(): array {
        $sectConfig = require __DIR__ . '/../config/sects.php';
        $sects = $sectConfig['sects'] ?? [];
        
        $result = [];
        foreach ($sects as $key => $sect) {
            $result[] = [
                'key' => $key,
                'name' => $sect['name'] ?? $key,
                'location' => $sect['location'] ?? '',
            ];
        }
        
        return $result;
    }
    
    /**
     * 获取门派配置
     * 
     * @param string|null $sectName 门派key，不传则返回整个配置
     * @return array 门派配置
     */
    public static function getSectConfig(?string $sectName = null): array {
        $sectConfig = require __DIR__ . '/../config/sects.php';
        
        if ($sectName !== null) {
            return $sectConfig['sects'][$sectName] ?? [];
        }
        
        return $sectConfig;
    }
    
    /**
     * 根据门派key获取中文名
     */
    public static function getSectName($sectKey) {
        if (empty($sectKey)) {
            return '';
        }
        $sectConfig = self::getSectConfig($sectKey);
        return $sectConfig['name'] ?? $sectKey;
    }

    /**
     * 检查角色是否可以加入门派
     * 
     * @param int         $characterId 角色ID
     * @param string      $sectName    门派key
     * @param mixed       $db          保留参数
     * @return array      ['allowed' => bool, 'reason' => string]
     */
    public static function canJoinSect(int $characterId, string $sectName, $db = null): array {
        try {
            // 获取角色信息
            $character = Database::queryOne(
                'SELECT family, betrayal_count FROM characters WHERE id = ?',
                [$characterId]
            );
            
            if (!$character) {
                return ['allowed' => false, 'reason' => '角色不存在。'];
            }
            
            // 检查是否已有门派
            if (!empty($character['family'])) {
                return ['allowed' => false, 'reason' => '你已经加入了其他门派，需要先离开当前门派才能加入新门派。'];
            }
            
            // 检查背叛次数限制
            $sectConfig = self::getSectConfig();
            $joinConfig = $sectConfig['join_config'] ?? [];
            $maxBetrayal = $joinConfig['max_betrayals_allowed'] ?? 3;
            
            $betrayalCount = (int)($character['betrayal_count'] ?? 0);
            if ($betrayalCount > $maxBetrayal) {
                return ['allowed' => false, 'reason' => '你的背叛次数过多，没有门派愿意收你为徒。'];
            }
            
            return ['allowed' => true, 'reason' => ''];
            
        } catch (\Exception $e) {
            error_log('SectHelper::canJoinSect error: ' . $e->getMessage());
            return ['allowed' => false, 'reason' => '检查入门条件失败，请稍后再试。'];
        }
    }
    
    /**
     * 获取角色的门派信息
     * 
     * @param int $charId 角色ID
     * @return array|null 角色的门派信息
     */
    public static function getCharacterSect(int $charId): ?array {
        try {
            $charInfo = Database::queryOne(
                'SELECT family, generation, family_enter_time FROM characters WHERE id = ?',
                [$charId]
            );
            
            if (!$charInfo || empty($charInfo['family'])) {
                return null;
            }
            
            $sectKey = $charInfo['family'];
            $sectConfig = self::getSectConfig($sectKey);
            
            // 获取门派成员信息
            $member = Database::queryOne(
                'SELECT sect_rank, contribution FROM sect_members 
                  WHERE character_id = ? AND is_active = 1',
                [$charId]
            );
            
            return [
                'sect_name' => $sectConfig['name'] ?? $sectKey,
                'sect_key' => $sectKey,
                'sect_rank' => $member['sect_rank'] ?? '弟子',
                'generation' => (int)($charInfo['generation'] ?? 0),
                'enter_time' => $charInfo['family_enter_time'],
                'contribution' => (int)($member['contribution'] ?? 0),
            ];
            
        } catch (\Exception $e) {
            error_log('SectHelper::getCharacterSect error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 获取门派技能列表
     * 
     * @param string $sectKey 门派key
     * @return array 技能列表
     */
    public static function getSectSkills(string $sectKey): array {
        $sectConfig = self::getSectConfig($sectKey);
        return [
            'exclusive' => $sectConfig['skills']['exclusive'] ?? [],
            'important' => $sectConfig['skills']['important'] ?? [],
        ];
    }
    
    /**
     * 检查角色是否可以学习某项技能（考虑门派限制）
     * 
     * @param int $charId 角色ID
     * @param string $skillId 技能ID
     * @return array ['allowed' => bool, 'reason' => string, 'bonus' => float, 'is_sect_skill' => bool]
     */
    public static function canLearnSkill(int $charId, string $skillId): array {
        try {
            $charInfo = Database::queryOne(
                'SELECT family, bellicosity FROM characters WHERE id = ?',
                [$charId]
            );
            
            if (!$charInfo) {
                return ['allowed' => true, 'reason' => '', 'bonus' => 0, 'is_sect_skill' => false];
            }
            
            // 检查技能的杀气限制
            $skillInfo = Database::queryOne(
                'SELECT data FROM skills WHERE id = ?',
                [$skillId]
            );
            
            if ($skillInfo && !empty($skillInfo['data'])) {
                $skillData = json_decode($skillInfo['data'], true);
                
                // 检查最大杀气限制（佛法类技能）
                if (isset($skillData['bellicosity_max']) && $skillData['bellicosity_max'] > 0) {
                    $charBellicosity = intval($charInfo['bellicosity'] ?? 0);
                    if ($charBellicosity > $skillData['bellicosity_max']) {
                        $message = $skillData['message']['bellicosity'] ?? '你的杀气太重，无法学习此技能。';
                        return ['allowed' => false, 'reason' => $message, 'bonus' => 0, 'is_sect_skill' => false];
                    }
                }
                
                // 检查最小杀气要求（魔功类技能）
                if (isset($skillData['bellicosity_formula'])) {
                    $charBellicosity = intval($charInfo['bellicosity'] ?? 0);
                    $currentSkillLevel = self::getSkillLevel($charId, $skillId);
                    
                    // 解析公式，如 "skill * 10"
                    $formula = str_replace('skill', $currentSkillLevel, $skillData['bellicosity_formula']);
                    $requiredBellicosity = eval("return $formula;");
                    
                    if ($charBellicosity < $requiredBellicosity) {
                        $message = $skillData['message'] ?? '你的杀气不够，无法学习此技能。';
                        return ['allowed' => false, 'reason' => $message, 'bonus' => 0, 'is_sect_skill' => false];
                    }
                }
            }
            
            if (empty($charInfo['family'])) {
                return ['allowed' => true, 'reason' => '', 'bonus' => 0, 'is_sect_skill' => false];
            }
            
            $sectKey = $charInfo['family'];
            $sectSkills = self::getSectSkills($sectKey);
            
            $exclusiveSkills = $sectSkills['exclusive'] ?? [];
            $importantSkills = $sectSkills['important'] ?? [];
            
            // 检查是否是本门专属技能
            if (isset($exclusiveSkills[$skillId])) {
                return ['allowed' => true, 'reason' => '', 'bonus' => 30, 'is_sect_skill' => true];
            }
            
            // 检查是否是本门重点技艺
            if (isset($importantSkills[$skillId])) {
                return ['allowed' => true, 'reason' => '', 'bonus' => 15, 'is_sect_skill' => false];
            }
            
            // 其他技能无加成
            return ['allowed' => true, 'reason' => '', 'bonus' => 0, 'is_sect_skill' => false];
            
        } catch (\Exception $e) {
            error_log('SectHelper::canLearnSkill error: ' . $e->getMessage());
            return ['allowed' => true, 'reason' => '', 'bonus' => 0, 'is_sect_skill' => false];
        }
    }
    
    private static function getSkillLevel($charId, $skillId) {
        $result = Database::queryOne(
            'SELECT level FROM character_skills WHERE char_id = ? AND skill_id = ?',
            [$charId, $skillId]
        );
        return $result ? intval($result['level']) : 0;
    }
}

