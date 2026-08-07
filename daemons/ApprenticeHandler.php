<?php
/**
 * ApprenticeHandler - 拜师/师徒核心业务处理器
 *
 * 功能：
 * - 向NPC拜师（直接入门）
 * - 向玩家发起拜师请求（双向确认）
 * - 接受/拒绝拜师请求
 * - 逐出师门
 * - 主动离开门派（叛出）
 * - 背叛惩罚
 * - 师徒树/成员列表查询
 *
 * 数据表：
 *   characters       - 主角色表（含 master_id, master_name, generation,
 *                            family_enter_time, family_privs, betrayal_count, family）
 *   sect_members          - 门派成员表
 *   apprentice_requests   - 拜师请求表
 *
 * 参考：
 *   参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../helpers/SectHelper.php';
require_once __DIR__ . '/../helpers/ProfessionHelper.php';
require_once __DIR__ . '/../helpers/SkillManager.php';

class ApprenticeHandler
{
    // =========================================================
    // 1. 向NPC拜师（直接加入门派）
    // =========================================================

    /**
     * 向NPC拜师，直接加入对应门派
     *
     * @param int    $characterId 弟子角色ID
     * @param int    $npcId       NPC的ID（对应 sects.php 中 master_npc_id）
     * @param mixed  $db          保留参数（兼容旧调用，内部使用 Database:: 静态方法）
     * @return array ['success'=>bool, 'message'=>string, 'data'=>array|null]
     */
    public static function apprenticeToNpc(int $characterId, int $npcId, $db = null): array
    {
        try {
            // 查找对应门派
            $sect = SectHelper::getSectByNpcId($npcId);
            if (!$sect) {
                return ['success' => false, 'message' => '找不到对应的门派师父。', 'data' => null];
            }

            $sectName = $sect['key'];

            // 检查是否满足入门条件
            $canJoin = SectHelper::canJoinSect($characterId, $sectName, $db);
            if (!$canJoin['allowed']) {
                return ['success' => false, 'message' => $canJoin['reason'], 'data' => null];
            }

            // 检查NPC个性化拜师条件
            $npcCheck = self::checkNpcApprenticeConditions($characterId, $npcId);
            if (!$npcCheck['allowed']) {
                return ['success' => false, 'message' => $npcCheck['message'], 'data' => null];
            }

            // 检查背叛次数限制（预留接口）
            $betrayalCheck = self::checkBetrayalLimit($characterId);
            if (!$betrayalCheck['allowed']) {
                return ['success' => false, 'message' => $betrayalCheck['message'], 'data' => null];
            }

            // 获取角色信息
            $character = Database::queryOne(
                'SELECT id, name, race, family, betrayal_count FROM characters WHERE id = ?',
                [$characterId]
            );
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在。', 'data' => null];
            }

            // 执行入门（事务）
            Database::beginTransaction();

            // 获取门派排名列表，默认第一个为初始职位
            $ranks  = $sect['ranks'] ?? ['弟子'];
            $initRank = $ranks[0];

            // 获取NPC个性化配置（用于取得NPC名称）
            $npcMasterConfig = self::getNpcMasterConfig($npcId);
            $masterName = $npcMasterConfig['name'] ?? ($sect['master_npc'] ?? '师父');

            // 获取门派全称
            $familyName = $sect['family_name'] ?? ($sect['name'] ?? $sectName);

            // 更新 characters
            Database::execute(
                'UPDATE characters
                    SET family            = ?,
                        family_name       = ?,
                        master_id         = NULL,
                        master_name       = ?,
                        generation        = 1,
                        family_enter_time = NOW(),
                        family_privs      = NULL
                  WHERE id = ?',
                [$sectName, $familyName, $masterName, $characterId]
            );

            // 插入或更新 sect_members
            self::upsertSectMember($characterId, $sectName, $initRank);

            // 设置职业（对应原始项目的 set("class", "xxx")）
            $race = $character['race'] ?? 'human';
            $professionSet = ProfessionHelper::setProfessionOnJoin($characterId, $sectName, $race);

            // 授予门派专属技能（10级）
            $grantedSkills = self::grantSectSkills($characterId, $sect);

            Database::commit();

            $familyName = $sect['name'] ?? $sectName;
            $skillNames = !empty($grantedSkills) ? '，并蒙师授' . implode('、', $grantedSkills) . '各十级' : '';
            $message = sprintf(
                '你恭恭敬敬地向%s叩头行礼，正式成为%s的%s%s！',
                $masterName,
                $familyName,
                $initRank,
                $skillNames
            );

            return [
                'success' => true,
                'message' => $message,
                'data' => [
                    'sect_name'  => $sectName,
                    'sect_rank'  => $initRank,
                    'generation' => 1,
                ],
            ];


        } catch (\Exception $e) {
            Database::rollBack();
            error_log('ApprenticeHandler::apprenticeToNpc error: ' . $e->getMessage());
            return ['success' => false, 'message' => '拜师失败，请稍后再试。', 'data' => null];
        }
    }

    /**
     * 授予门派专属技能（拜师时自动获得10级）
     *
     * 根据门派配置的 exclusive skills，在 character_skills 表中插入10级记录，
     * 并在 character_skill_map 中建立技能类型映射（force→内功, spell→法术,
     * dodge→轻功, parry→招架, 武器技能→对应武器类型）。
     *
     * @param int   $characterId 角色ID
     * @param array $sect        门派配置（含 skills.exclusive 列表）
     * @return string[]          已授予的技能中文名列表
     */
    private static function grantSectSkills(int $characterId, array $sect): array {
        $exclusiveSkills = $sect['skills']['exclusive'] ?? [];
        if (empty($exclusiveSkills)) {
            return [];
        }

        $grantedNames = [];

        // 技能类型 → character_skill_map 中 skill_type 的映射规则
        // weapon 类型技能需要根据具体武器类型映射
        $typeToSkillType = [
            'force'    => 'force',
            'spell'    => 'spells',
            'movement' => 'dodge',
        ];

        // 武器技能 skill_id → skill_type 映射（根据 npc_skill_maps 实际数据）
        $weaponSkillTypeMap = [
            'qianjun-bang'    => 'stick',
            'puti-zhi'        => 'unarmed',
            'dragonfight'     => 'unarmed',
            'fengbo-cha'      => 'fork',
            'huntian-hammer'  => 'hammer',
            'jienan-zhi'      => 'unarmed',
            'lunhui-zhang'    => 'staff',
            'snowsword'       => 'sword',
            'baihua-zhang'    => 'unarmed',
            'sanqing-jian'    => 'sword',
            'xiaofeng-sword'  => 'sword',
            'kusang-bang'     => 'stick',
            'hellfire-whip'   => 'whip',
            'bawang-qiang'    => 'spear',
            'sanban-axe'      => 'axe',
            'snowwhip'        => 'whip',
            'huoyun-qiang'    => 'spear',
        ];

        // 需要建立 parry 映射的技能（valid_enable 中包含 parry 的 weapon 技能）
        $parrySkills = [
            'qianjun-bang', 'fengbo-cha', 'snowsword', 'sanqing-jian',
            'xiaofeng-sword', 'lunhui-zhang', 'bawang-qiang', 'sanban-axe',
            'hellfire-whip', 'snowwhip', 'huoyun-qiang', 'kusang-bang',
            'yueya-chan', 'kaishan-chui',
        ];

        foreach ($exclusiveSkills as $skillId => $skillName) {
            // 查询技能配置
            $skillConfig = SkillManager::getSkillConfig($skillId);
            if (!$skillConfig) {
                continue;
            }

            $skillType = $skillConfig['type'] ?? '';

            // 在 character_skills 中插入或更新为10级
            Database::execute(
                "INSERT INTO character_skills (char_id, skill_id, level, exp)
                 VALUES (?, ?, 10, 0)
                 ON DUPLICATE KEY UPDATE level = GREATEST(level, 10)",
                [$characterId, $skillId]
            );

            // 建立 skill_map 映射
            if ($skillType === 'weapon') {
                // 武器技能：映射到对应武器类型
                $mapType = $weaponSkillTypeMap[$skillId] ?? null;
                if ($mapType) {
                    self::forceMapSkill($characterId, $mapType, $skillId);
                    // 如果该技能同时可作为 parry，也建立 parry 映射
                    if (in_array($skillId, $parrySkills)) {
                        self::forceMapSkill($characterId, 'parry', $skillId);
                    }
                }
            } elseif (isset($typeToSkillType[$skillType])) {
                // 内功/法术/轻功：映射到对应类型
                $mapType = $typeToSkillType[$skillType];
                self::forceMapSkill($characterId, $mapType, $skillId);
            }

            $grantedNames[] = $skillName;
        }

        return $grantedNames;
    }

    /**
     * 直接写入 skill_map 映射（不检查技能等级，用于拜师时事务中调用）
     */
    private static function forceMapSkill(int $characterId, string $skillType, string $mappedSkill): void {
        Database::execute(
            "INSERT INTO character_skill_map (char_id, skill_type, mapped_skill)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE mapped_skill = ?",
            [$characterId, $skillType, $mappedSkill, $mappedSkill]
        );
    }

    // =========================================================
    // 1b. NPC个性化拜师条件检查
    // =========================================================

    /**
     * 检查角色是否满足指定NPC的个性化拜师条件
     *
     * 根据 config/sects.php 中 npc_masters 配置，逐条检查条件：
     * - daoxing_min   道行最低要求
     * - combat_exp_min  战斗经验最低要求
     * - str_min / int_min / dex_min / con_min / per_min  属性最低要求
     * - skills  技能等级要求 ['skill_id' => min_level, ...]
     * - special  特殊条件（如 'fate'=缘份随机判定, 'introduction'=需引见）
     *
     * @param int $characterId 角色ID
     * @param int $npcId       NPC的ID
     * @return array ['allowed' => bool, 'message' => string]
     */
    public static function checkNpcApprenticeConditions(int $characterId, int $npcId): array
    {
        try {
            // 获取NPC的拜师配置
            $masterConfig = self::getNpcMasterConfig($npcId);

            // 如果没有配置个性化条件，说明该NPC没有特殊要求，直接通过
            if (!$masterConfig || empty($masterConfig['conditions'])) {
                return ['allowed' => true, 'message' => ''];
            }

            $conditions = $masterConfig['conditions'];
            $messages   = $masterConfig['rejection_messages'] ?? [];

            // 获取角色属性数据
            $character = Database::queryOne(
                'SELECT daoxing, combat_exp, str, `int`, dex, con, per, spi, kar, gender, skills_data
                   FROM characters WHERE id = ?',
                [$characterId]
            );
            if (!$character) {
                return ['allowed' => false, 'message' => '角色不存在。'];
            }

            // 解析技能数据
            $charSkills = [];
            if (!empty($character['skills_data'])) {
                $skillsData = json_decode($character['skills_data'], true);
                $charSkills = $skillsData['skills'] ?? [];
            }

            // ---- 性别检查（放在最前面，性别不对其他都白搭） ----
            if (!empty($conditions['gender'])) {
                $requiredGender = $conditions['gender'];
                $charGender = $character['gender'] ?? '';
                
                // 兼容中英文性别值
                $isMatch = false;
                if (is_array($requiredGender)) {
                    // 数组形式：允许多个性别
                    foreach ($requiredGender as $g) {
                        if (self::genderMatch($charGender, $g)) {
                            $isMatch = true;
                            break;
                        }
                    }
                } else {
                    // 单个性别
                    $isMatch = self::genderMatch($charGender, $requiredGender);
                }
                
                if (!$isMatch) {
                    return [
                        'allowed' => false,
                        'message' => $messages['gender'] ?? '你的性别不符合拜师要求。',
                    ];
                }
            }

            // ---- 道行检查 ----
            if (isset($conditions['daoxing_min'])) {
                if ((int)$character['daoxing'] < (int)$conditions['daoxing_min']) {
                    return [
                        'allowed' => false,
                        'message' => $messages['daoxing'] ?? '你的道行还不够。',
                    ];
                }
            }

            // ---- 战斗经验检查 ----
            if (isset($conditions['combat_exp_min'])) {
                if ((int)$character['combat_exp'] < (int)$conditions['combat_exp_min']) {
                    return [
                        'allowed' => false,
                        'message' => $messages['combat_exp'] ?? '你的战斗经验不足。',
                    ];
                }
            }

            // ---- 属性检查：力量(str) ----
            if (isset($conditions['str_min'])) {
                if ((int)$character['str'] < (int)$conditions['str_min']) {
                    return [
                        'allowed' => false,
                        'message' => $messages['str'] ?? '你的力量不够。',
                    ];
                }
            }

            // ---- 属性检查：悟性(int) ----
            if (isset($conditions['int_min'])) {
                if ((int)$character['int'] < (int)$conditions['int_min']) {
                    return [
                        'allowed' => false,
                        'message' => $messages['int'] ?? '你的悟性不够。',
                    ];
                }
            }

            // ---- 属性检查：身法(dex) ----
            if (isset($conditions['dex_min'])) {
                if ((int)$character['dex'] < (int)$conditions['dex_min']) {
                    return [
                        'allowed' => false,
                        'message' => $messages['dex'] ?? '你的身法不够。',
                    ];
                }
            }

            // ---- 属性检查：根骨(con) ----
            if (isset($conditions['con_min'])) {
                if ((int)$character['con'] < (int)$conditions['con_min']) {
                    return [
                        'allowed' => false,
                        'message' => $messages['con'] ?? '你的根骨不够。',
                    ];
                }
            }

            // ---- 属性检查：容貌(per) ----
            if (isset($conditions['per_min'])) {
                if ((int)$character['per'] < (int)$conditions['per_min']) {
                    return [
                        'allowed' => false,
                        'message' => $messages['per'] ?? '你的容貌不够。',
                    ];
                }
            }

            // ---- 属性检查：灵性(spi) ----
            if (isset($conditions['spi_min'])) {
                if ((int)$character['spi'] < (int)$conditions['spi_min']) {
                    return [
                        'allowed' => false,
                        'message' => $messages['spi'] ?? '你的灵性不够。',
                    ];
                }
            }

            // ---- 技能等级检查 ----
            if (!empty($conditions['skills'])) {
                foreach ($conditions['skills'] as $skillId => $minLevel) {
                    $currentLevel = (int)($charSkills[$skillId] ?? 0);
                    if ($currentLevel < (int)$minLevel) {
                        return [
                            'allowed' => false,
                            'message' => $messages['skill'] ?? '你的技能还不够。',
                        ];
                    }
                }
            }

            // ---- 特殊条件检查 ----
            if (!empty($conditions['special'])) {
                $specialResult = self::checkSpecialCondition(
                    $conditions['special'],
                    $characterId,
                    $character
                );
                if (!$specialResult['allowed']) {
                    return [
                        'allowed' => false,
                        'message' => $messages['special'] ?? $specialResult['message'],
                    ];
                }
            }

            // 所有条件通过
            return ['allowed' => true, 'message' => ''];

        } catch (\Exception $e) {
            error_log('ApprenticeHandler::checkNpcApprenticeConditions error: ' . $e->getMessage());
            return ['allowed' => false, 'message' => '拜师条件检查失败，请稍后再试。'];
        }
    }

    // =========================================================
    // 1g. 性别匹配辅助方法
    // =========================================================

    /**
     * 检查角色性别是否匹配要求
     *
     * 支持多种性别表示方式：
     * - 中文：男性/女性/男/女
     * - 英文：male/female/man/woman
     * - 数字：1=男，2=女
     *
     * @param string $charGender 角色的性别值
     * @param string $requiredGender 要求的性别值
     * @return bool 是否匹配
     */
    private static function genderMatch(string $charGender, string $requiredGender): bool
    {
        // 统一转为小写，去除空格
        $charGender = strtolower(trim($charGender));
        $requiredGender = strtolower(trim($requiredGender));

        // 直接匹配
        if ($charGender === $requiredGender) {
            return true;
        }

        // 判断角色性别是否为男性
        $isMale = in_array($charGender, ['male', 'man', '男', '男性', '1', 'm']);
        
        // 判断角色性别是否为女性
        $isFemale = in_array($charGender, ['female', 'woman', '女', '女性', '2', 'f']);

        // 判断要求的性别
        $reqMale = in_array($requiredGender, ['male', 'man', '男', '男性', '1', 'm']);
        $reqFemale = in_array($requiredGender, ['female', 'woman', '女', '女性', '2', 'f']);

        if ($reqMale && $isMale) {
            return true;
        }
        if ($reqFemale && $isFemale) {
            return true;
        }

        return false;
    }

    // =========================================================
    // 1c. 特殊条件检查
    // =========================================================

    /**
     * 检查特殊拜师条件
     *
     * 目前支持的特殊条件：
     * - 'fate': 缘份条件，50%概率通过
     * - 'introduction': 需引见条件，目前预留接口，暂直接通过
     *
     * 可在此方法中扩展更多特殊条件
     *
     * @param string $specialType 特殊条件类型
     * @param int    $characterId 角色ID
     * @param array  $character   角色数据
     * @return array ['allowed' => bool, 'message' => string]
     */
    private static function checkSpecialCondition(string $specialType, int $characterId, array $character): array
    {
        switch ($specialType) {
            case 'fate':
                // 缘份条件：50%概率通过
                if (mt_rand(1, 100) <= 50) {
                    return ['allowed' => true, 'message' => ''];
                }
                return ['allowed' => false, 'message' => '你我无缘，请回吧。'];

            case 'introduction':
                // 需引见条件：预留接口，暂直接通过
                // TODO: 实现引见人检查逻辑，需判断是否有该门派NPC或玩家推荐
                return ['allowed' => true, 'message' => ''];

            default:
                // 未知特殊条件，默认通过
                return ['allowed' => true, 'message' => ''];
        }
    }

    // =========================================================
    // 1d. 背叛次数限制检查（预留接口）
    // =========================================================

    /**
     * 检查角色的背叛次数是否超过限制
     *
     * 预留接口，暂不实现。未来需要：
     * - 查询 characters.betrayal_count
     * - 与 sects.php 中 join_config.max_betrayals_allowed 比对
     * - 超过限制则拒绝拜师
     *
     * @param int $characterId 角色ID
     * @return array ['allowed' => bool, 'message' => string]
     */
    public static function checkBetrayalLimit(int $characterId): array
    {
        // TODO: 实现背叛次数检查逻辑
        // 预期逻辑：
        //   $character = Database::queryOne(
        //       'SELECT betrayal_count FROM characters WHERE id = ?',
        //       [$characterId]
        //   );
        //   $sectConfig = SectHelper::getSectConfig();
        //   $maxBetrayal = $sectConfig['join_config']['max_betrayals_allowed'] ?? 3;
        //   if (($character['betrayal_count'] ?? 0) > $maxBetrayal) {
        //       return ['allowed' => false, 'message' => '你的背叛次数过多，没有门派愿意收你为徒。'];
        //   }
        return ['allowed' => true, 'message' => ''];
    }

    // =========================================================
    // 1e. 获取NPC拜师配置
    // =========================================================

    /**
     * 从数据库中查找指定NPC的拜师配置
     *
     * 拜师条件数据已从 sects.php 迁移到 npcs 表的
     * apprentice_conditions / rejection_messages 等字段。
     *
     * @param int $npcId NPC的ID
     * @return array|null NPC拜师配置，未找到返回null
     */
    public static function getNpcMasterConfig(int $npcId): ?array
    {
        try {
            $npc = Database::queryOne(
                'SELECT id, name, master_title, sect_key, apprentice_conditions, rejection_messages 
                 FROM npcs WHERE id = ? AND is_master = 1',
                [$npcId]
            );

            if (!$npc) {
                return null;
            }

            $conditions = !empty($npc['apprentice_conditions'])
                ? json_decode($npc['apprentice_conditions'], true)
                : [];
            $messages = !empty($npc['rejection_messages'])
                ? json_decode($npc['rejection_messages'], true)
                : [];

            return [
                'npc_id'            => (int)$npc['id'],
                'name'              => $npc['name'],
                'title'             => $npc['master_title'] ?? '',
                'conditions'        => $conditions ?: [],
                'rejection_messages' => $messages ?: [],
                'sect_key'          => $npc['sect_key'] ?? '',
            ];
        } catch (\Exception $e) {
            error_log('ApprenticeHandler::getNpcMasterConfig error: ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================
    // 1f. 获取所有可拜师NPC列表
    // =========================================================

    /**
     * 获取所有门派中所有可拜师NPC的列表（供前端展示）
     *
     * @return array 可拜师NPC列表，每项包含 sect_key, sect_name, npc_id, name, title, conditions
     */
    public static function getMasterableSects(): array
    {
        try {
            $masters = Database::queryAll(
                'SELECT id, name, master_title, sect_key, apprentice_conditions
                 FROM npcs WHERE is_master = 1
                 ORDER BY sect_key, id'
            );

            if (!$masters) {
                return [];
            }

            $sectConfig = SectHelper::getSectConfig();
            $sects = $sectConfig['sects'] ?? [];

            $result = [];
            foreach ($masters as $master) {
                $sectKey = $master['sect_key'] ?? '';
                $sect = $sects[$sectKey] ?? [];
                $conditions = !empty($master['apprentice_conditions'])
                    ? json_decode($master['apprentice_conditions'], true)
                    : [];

                $result[] = [
                    'sect_key'   => $sectKey,
                    'sect_name'  => $sect['name'] ?? $sectKey,
                    'alignment'  => $sect['alignment'] ?? 0,
                    'npc_id'     => (int)$master['id'],
                    'name'       => $master['name'] ?? '',
                    'title'      => $master['master_title'] ?? '',
                    'conditions' => $conditions ?: [],
                ];
            }

            return $result;
        } catch (\Exception $e) {
            error_log('ApprenticeHandler::getMasterableSects error: ' . $e->getMessage());
            return [];
        }
    }

    // =========================================================
    // 2. 向玩家发起拜师请求
    // =========================================================

    /**
     * 向玩家发起拜师请求（需对方用 acceptApprenticeship 确认）
     *
     * @param int   $fromCharId 请求者（弟子）角色ID
     * @param int   $toCharId   目标师父角色ID
     * @param mixed $db         保留参数
     * @return array
     */
    public static function requestApprenticeship(int $fromCharId, int $toCharId, $db = null): array
    {
        try {
            if ($fromCharId === $toCharId) {
                return ['success' => false, 'message' => '不能拜自己为师。', 'data' => null];
            }

            // 检查弟子角色
            $fromChar = Database::queryOne(
                'SELECT id, name, family, betrayal_count FROM characters WHERE id = ?',
                [$fromCharId]
            );
            if (!$fromChar) {
                return ['success' => false, 'message' => '角色不存在。', 'data' => null];
            }

            // 检查师父角色
            $toChar = Database::queryOne(
                'SELECT id, name, family FROM characters WHERE id = ?',
                [$toCharId]
            );
            if (!$toChar) {
                return ['success' => false, 'message' => '对方角色不存在。', 'data' => null];
            }

            // 师父必须有门派
            if (empty($toChar['family'])) {
                return [
                    'success' => false,
                    'message' => $toChar['name'] . '既不属于任何门派，也没有开山立派，不能拜师。',
                    'data' => null,
                ];
            }

            // 不能拜自己的徒弟为师（循环检测）
            if (self::isApprenticeOf($toCharId, $fromCharId)) {
                return ['success' => false, 'message' => '不能拜自己的弟子为师。', 'data' => null];
            }

            // 已经是该师父的弟子
            if (self::isApprenticeOf($fromCharId, $toCharId)) {
                return ['success' => false, 'message' => '你已经是对方的弟子了。', 'data' => null];
            }

            // 检查弟子背叛次数
            $sectConfig = SectHelper::getSectConfig();
            $joinConfig = $sectConfig['join_config'] ?? [];
            $maxBetrayal = $joinConfig['max_betrayals_allowed'] ?? 3;
            if (($fromChar['betrayal_count'] ?? 0) > $maxBetrayal) {
                return ['success' => false, 'message' => '你的背叛次数过多，没有门派愿意收你为徒。', 'data' => null];
            }

            // 检查是否已有待处理请求（向同一人）
            $existing = Database::queryOne(
                'SELECT id FROM apprentice_requests
                  WHERE from_character_id = ? AND to_character_id = ? AND status = "pending"',
                [$fromCharId, $toCharId]
            );
            if ($existing) {
                return [
                    'success' => false,
                    'message' => '你已经向' . $toChar['name'] . '发起过拜师请求，等待对方回复。',
                    'data' => null,
                ];
            }

            // 取消对同一师父的旧请求（向其他人的重新发）
            Database::execute(
                'UPDATE apprentice_requests SET status = "expired", resolved_at = NOW()
                  WHERE from_character_id = ? AND status = "pending"',
                [$fromCharId]
            );

            // 确定门派
            $targetSect = $toChar['family'];

            // 插入新请求
            Database::execute(
                'INSERT INTO apprentice_requests
                    (from_character_id, to_character_id, to_is_npc, sect_name, status, created_at)
                  VALUES (?, ?, 0, ?, "pending", NOW())',
                [$fromCharId, $toCharId, $targetSect]
            );
            $requestId = Database::lastInsertId();

            return [
                'success' => true,
                'message' => sprintf(
                    '你向%s恭敬行礼，表示想拜其为师。等待对方回应……',
                    $toChar['name']
                ),
                'data' => [
                    'request_id'   => $requestId,
                    'to_char_name' => $toChar['name'],
                    'sect_name'    => $targetSect,
                ],
            ];

        } catch (\Exception $e) {
            error_log('ApprenticeHandler::requestApprenticeship error: ' . $e->getMessage());
            return ['success' => false, 'message' => '发起拜师请求失败，请稍后再试。', 'data' => null];
        }
    }

    // =========================================================
    // 3. 接受拜师请求（收徒）
    // =========================================================

    /**
     * 师父接受拜师请求，正式建立师徒关系
     *
     * @param int   $masterId      师父角色ID
     * @param int   $apprenticeId  弟子角色ID
     * @param mixed $db            保留参数
     * @return array
     */
    public static function acceptApprenticeship(int $masterId, int $apprenticeId, $db = null): array
    {
        try {
            // 查找对应的 pending 请求
            $request = Database::queryOne(
                'SELECT * FROM apprentice_requests
                  WHERE from_character_id = ? AND to_character_id = ? AND status = "pending"',
                [$apprenticeId, $masterId]
            );
            if (!$request) {
                return ['success' => false, 'message' => '没有找到待处理的拜师请求。', 'data' => null];
            }

            // 获取师父信息
            $master = Database::queryOne(
                'SELECT id, name, family, generation FROM characters WHERE id = ?',
                [$masterId]
            );
            if (!$master) {
                return ['success' => false, 'message' => '师父角色不存在。', 'data' => null];
            }

            // 获取弟子信息
            $apprentice = Database::queryOne(
                'SELECT id, name, family, betrayal_count FROM characters WHERE id = ?',
                [$apprenticeId]
            );
            if (!$apprentice) {
                return ['success' => false, 'message' => '弟子角色不存在。', 'data' => null];
            }

            $sectName  = $request['sect_name'] ?? $master['family'];
            $sectConf  = SectHelper::getSectConfig($sectName);
            $ranks     = $sectConf['ranks'] ?? ['弟子'];
            $initRank  = $ranks[0];

            // 判断是否是跨门派拜师（背叛计数）
            $isBetray  = !empty($apprentice['family']) && $apprentice['family'] !== $sectName;
            $masterGen = (int)($master['generation'] ?? 1);
            $newGen    = $masterGen + 1;

            Database::beginTransaction();

            // 若有原门派，先离开（计背叛）
            if ($isBetray) {
                Database::execute(
                    'UPDATE characters SET betrayal_count = betrayal_count + 1 WHERE id = ?',
                    [$apprenticeId]
                );
                // 同步更新原 sect_members 记录为无效
                Database::execute(
                    'UPDATE sect_members SET is_active = 0 WHERE character_id = ? AND is_active = 1',
                    [$apprenticeId]
                );
            }

            // 获取门派全称
            $familyName = $sectConf['family_name'] ?? ($sectConf['name'] ?? $sectName);

            // 更新弟子角色信息
            Database::execute(
                'UPDATE characters
                    SET family            = ?,
                        family_name       = ?,
                        master_id         = ?,
                        master_name       = ?,
                        generation        = ?,
                        family_enter_time = NOW(),
                        family_privs      = NULL
                  WHERE id = ?',
                [$sectName, $familyName, $masterId, $master['name'], $newGen, $apprenticeId]
            );

            // 插入/更新 sect_members
            self::upsertSectMember($apprenticeId, $sectName, $initRank);

            // 标记请求为已接受
            Database::execute(
                'UPDATE apprentice_requests
                    SET status = "accepted", resolved_at = NOW()
                  WHERE id = ?',
                [$request['id']]
            );

            Database::commit();

            // 若是背叛，应用惩罚
            if ($isBetray) {
                self::applyBetrayalPenalty($apprenticeId, $db);
            }

            $sectFamilyName = $sectConf['name'] ?? $sectName;
            $message = sprintf(
                '你收下%s为弟子，从此%s正式成为%s第%s代%s。',
                $apprentice['name'],
                $apprentice['name'],
                $sectFamilyName,
                self::chineseNumber($newGen),
                $initRank
            );

            return [
                'success' => true,
                'message' => $message,
                'data' => [
                    'apprentice_name' => $apprentice['name'],
                    'sect_name'       => $sectName,
                    'sect_rank'       => $initRank,
                    'generation'      => $newGen,
                    'is_betray'       => $isBetray,
                ],
            ];

        } catch (\Exception $e) {
            Database::rollBack();
            error_log('ApprenticeHandler::acceptApprenticeship error: ' . $e->getMessage());
            return ['success' => false, 'message' => '接受拜师失败，请稍后再试。', 'data' => null];
        }
    }

    // =========================================================
    // 4. 拒绝拜师请求
    // =========================================================

    /**
     * 师父拒绝拜师请求
     *
     * @param int   $masterId   师父角色ID
     * @param int   $requestId  请求记录ID（apprentice_requests.id）
     * @param mixed $db         保留参数
     * @return array
     */
    public static function rejectApprenticeship(int $masterId, int $requestId, $db = null): array
    {
        try {
            $request = Database::queryOne(
                'SELECT * FROM apprentice_requests WHERE id = ? AND to_character_id = ? AND status = "pending"',
                [$requestId, $masterId]
            );
            if (!$request) {
                return ['success' => false, 'message' => '找不到该拜师请求，或已处理。', 'data' => null];
            }

            Database::execute(
                'UPDATE apprentice_requests SET status = "rejected", resolved_at = NOW() WHERE id = ?',
                [$requestId]
            );

            // 获取请求者名称，用于消息
            $fromChar = Database::queryOne(
                'SELECT name FROM characters WHERE id = ?',
                [$request['from_character_id']]
            );
            $fromName = $fromChar['name'] ?? '对方';

            return [
                'success' => true,
                'message' => '你拒绝了' . $fromName . '的拜师请求。',
                'data'    => ['from_char_name' => $fromName],
            ];

        } catch (\Exception $e) {
            error_log('ApprenticeHandler::rejectApprenticeship error: ' . $e->getMessage());
            return ['success' => false, 'message' => '拒绝拜师失败，请稍后再试。', 'data' => null];
        }
    }

    // =========================================================
    // 5. 逐出师门
    // =========================================================

    /**
     * 师父将弟子逐出师门
     *
     * @param int    $masterId      师父角色ID
     * @param int    $apprenticeId  弟子角色ID
     * @param string $reason        逐出原因
     * @param mixed  $db            保留参数
     * @return array
     */
    public static function expellApprentice(int $masterId, int $apprenticeId, string $reason = '', $db = null): array
    {
        try {
            // 验证师徒关系
            if (!self::isApprenticeOf($apprenticeId, $masterId)) {
                return ['success' => false, 'message' => '对方不是你的弟子。', 'data' => null];
            }

            $apprentice = Database::queryOne(
                'SELECT id, name, family FROM characters WHERE id = ?',
                [$apprenticeId]
            );
            if (!$apprentice) {
                return ['success' => false, 'message' => '弟子角色不存在。', 'data' => null];
            }

            Database::beginTransaction();

            // 清除弟子的门派师父信息
            Database::execute(
                'UPDATE characters
                    SET master_id   = NULL,
                        master_name = NULL,
                        generation  = 0,
                        family      = NULL,
                        family_name = NULL,
                        family_enter_time = NULL,
                        family_privs      = NULL
                  WHERE id = ?',
                [$apprenticeId]
            );

            // 将 sect_members 设为无效
            Database::execute(
                'UPDATE sect_members SET is_active = 0 WHERE character_id = ? AND is_active = 1',
                [$apprenticeId]
            );

            Database::commit();

            $reasonText = !empty($reason) ? ('原因：' . $reason) : '';
            return [
                'success' => true,
                'message' => sprintf('你将%s逐出师门。%s', $apprentice['name'], $reasonText),
                'data'    => ['apprentice_name' => $apprentice['name'], 'reason' => $reason],
            ];

        } catch (\Exception $e) {
            Database::rollBack();
            error_log('ApprenticeHandler::expellApprentice error: ' . $e->getMessage());
            return ['success' => false, 'message' => '逐出师门操作失败，请稍后再试。', 'data' => null];
        }
    }

    // =========================================================
    // 6. 主动离开门派（叛出）
    // =========================================================

    /**
     * 角色主动离开门派（叛出）
     * 会增加 betrayal_count 并应用背叛惩罚
     *
     * @param int   $characterId 角色ID
     * @param mixed $db          保留参数
     * @return array
     */
    public static function leaveSect(int $characterId, $db = null): array
    {
        try {
            $character = Database::queryOne(
                'SELECT id, name, family, master_id FROM characters WHERE id = ?',
                [$characterId]
            );
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在。', 'data' => null];
            }

            if (empty($character['family'])) {
                return ['success' => false, 'message' => '你目前不属于任何门派。', 'data' => null];
            }

            $oldSect = $character['family'];
            $sectConf = SectHelper::getSectConfig($oldSect);
            $sectName = $sectConf['name'] ?? $oldSect;

            Database::beginTransaction();

            // 增加背叛计数
            Database::execute(
                'UPDATE characters SET betrayal_count = betrayal_count + 1 WHERE id = ?',
                [$characterId]
            );

            // 清除门派信息
            Database::execute(
                'UPDATE characters
                    SET family            = NULL,
                        family_name       = NULL,
                        master_id         = NULL,
                        master_name       = NULL,
                        generation        = 0,
                        family_enter_time = NULL,
                        family_privs      = NULL
                  WHERE id = ?',
                [$characterId]
            );

            // 将 sect_members 设为无效
            Database::execute(
                'UPDATE sect_members SET is_active = 0 WHERE character_id = ? AND is_active = 1',
                [$characterId]
            );

            Database::commit();

            // 应用背叛惩罚
            self::applyBetrayalPenalty($characterId, $db);

            return [
                'success' => true,
                'message' => sprintf('你背离了%s，从此成为叛徒，将承受门规的惩罚！', $sectName),
                'data'    => ['old_sect' => $oldSect],
            ];

        } catch (\Exception $e) {
            Database::rollBack();
            error_log('ApprenticeHandler::leaveSect error: ' . $e->getMessage());
            return ['success' => false, 'message' => '离开门派失败，请稍后再试。', 'data' => null];
        }
    }

    // =========================================================
    // 7. 检测并执行背叛惩罚
    // =========================================================

    /**
     * 应用背叛惩罚（损失经验值和技能等级，并惩罚原门派专属技能）
     *
     * @param int         $characterId 角色ID
     * @param mixed       $db          保留参数
     * @param string|null $oldSectName 原门派 key（可选；传入时对专属技能执行更严厉惩罚）
     * @return array
     */
    public static function applyBetrayalPenalty(int $characterId, $db = null, ?string $oldSectName = null): array
    {
        try {
            $character = Database::queryOne(
                'SELECT id, name, exp, betrayal_count, family, skills_data FROM characters WHERE id = ?',
                [$characterId]
            );
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在。'];
            }

            $sectConfig    = SectHelper::getSectConfig();
            $penaltyConfig = $sectConfig['betrayal_penalty'] ?? [];

            $expLossPercent  = $penaltyConfig['exp_loss_percent']     ?? 30;
            $skillLossPct    = $penaltyConfig['skill_loss_percent']    ?? 20;
            $multiplierBase  = $penaltyConfig['multiplier_per_count']  ?? 1.5;
            $betrayalCount   = (int)($character['betrayal_count'] ?? 1);

            // 计算惩罚倍数（背叛次数越多惩罚越重）
            $multiplier = pow($multiplierBase, max(0, $betrayalCount - 1));

            // 1. 经验惩罚
            $currentExp  = (int)($character['exp'] ?? 0);
            $expLoss     = (int)($currentExp * ($expLossPercent / 100) * $multiplier);
            $newExp      = max(0, $currentExp - $expLoss);

            Database::execute(
                'UPDATE characters SET exp = ? WHERE id = ?',
                [$newExp, $characterId]
            );

            // 2. 技能惩罚（含原门派专属技能加重惩罚）
            $skillsData = [];
            if (!empty($character['skills_data'])) {
                $skillsData = json_decode($character['skills_data'], true) ?? [];
            }
            $skills = $skillsData['skills'] ?? [];

            // 确定原门派专属技能列表
            $exclusiveSkills = [];
            $resolvedOldSect = $oldSectName ?? ($character['family'] ?? null);
            if ($resolvedOldSect) {
                $oldSectConf     = SectHelper::getSectConfig($resolvedOldSect);
                $exclusiveSkills = array_keys($oldSectConf['skills']['exclusive'] ?? []);
            }

            $exclusiveLost = [];
            if (!empty($skills)) {
                $lossRate = ($skillLossPct / 100) * $multiplier;
                foreach ($skills as $skill => $level) {
                    if (in_array($skill, $exclusiveSkills, true)) {
                        // 专属技能直接清零（最严厉惩罚）
                        $exclusiveLost[$skill] = $level;
                        $skills[$skill] = 0;
                    } else {
                        $skills[$skill] = (int)max(0, $level * (1 - $lossRate));
                    }
                }
                $skillsData['skills'] = $skills;
                Database::execute(
                    'UPDATE characters SET skills_data = ? WHERE id = ?',
                    [json_encode($skillsData), $characterId]
                );
            }

            // 3. 写入背叛日志
            self::writeBetrayalLog($characterId, $character, $resolvedOldSect, $expLoss, $exclusiveLost, $multiplier);

            $exclusiveMsg = !empty($exclusiveLost)
                ? sprintf('，原门派专属技能（%s）已被清除', implode('、', array_keys($exclusiveLost)))
                : '';

            return [
                'success'   => true,
                'message'   => sprintf(
                    '背叛惩罚已执行：损失经验 %d 点，技能降低 %.0f%%%s。',
                    $expLoss,
                    min(100, $skillLossPct * $multiplier),
                    $exclusiveMsg
                ),
                'data' => [
                    'exp_lost'        => $expLoss,
                    'skill_loss_pct'  => min(100, $skillLossPct * $multiplier),
                    'multiplier'      => $multiplier,
                    'exclusive_lost'  => $exclusiveLost,
                ],
            ];

        } catch (\Exception $e) {
            error_log('ApprenticeHandler::applyBetrayalPenalty error: ' . $e->getMessage());
            return ['success' => false, 'message' => '背叛惩罚应用失败。'];
        }
    }

    // =========================================================
    // 8. 获取师徒树
    // =========================================================

    /**
     * 获取角色的师徒树（向上到祖师、向下到所有弟子）
     *
     * @param int   $characterId 角色ID
     * @param mixed $db          保留参数
     * @return array ['success'=>bool, 'data'=>array]
     */
    public static function getApprenticeTree(int $characterId, $db = null): array
    {
        try {
            $character = Database::queryOne(
                'SELECT id, name, family, master_id, master_name, generation, family_enter_time FROM characters WHERE id = ?',
                [$characterId]
            );
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在。', 'data' => null];
            }

            // 向上追溯祖师链
            $masterChain = [];
            $currentMasterId = (int)($character['master_id'] ?? 0);
            $visited = [$characterId => true];

            while ($currentMasterId > 0 && !isset($visited[$currentMasterId])) {
                $visited[$currentMasterId] = true;
                $master = Database::queryOne(
                    'SELECT id, name, family, master_id, generation, family_enter_time FROM characters WHERE id = ?',
                    [$currentMasterId]
                );
                if (!$master) break;
                array_unshift($masterChain, [
                    'id'         => (int)$master['id'],
                    'name'       => $master['name'],
                    'generation' => (int)$master['generation'],
                ]);
                $currentMasterId = (int)($master['master_id'] ?? 0);
            }

            // 向下递归获取弟子树（支持多层级徒孙）
            $apprenticeTree = self::buildApprenticeTreeRecursive($characterId, 0, [$characterId]);

            return [
                'success' => true,
                'message' => '',
                'data' => [
                    'self' => [
                        'id'         => (int)$character['id'],
                        'name'       => $character['name'],
                        'sect'       => $character['family'],
                        'generation' => (int)$character['generation'],
                    ],
                    'master_chain' => $masterChain,
                    'apprentices'  => $apprenticeTree,
                ],
            ];

        } catch (\Exception $e) {
            error_log('ApprenticeHandler::getApprenticeTree error: ' . $e->getMessage());
            return ['success' => false, 'message' => '获取师徒树失败。', 'data' => null];
        }
    }

    /**
     * 递归构建弟子树（还原原始项目 look 命令的谱系关系判断）
     * 支持多层级徒孙查询，按 family_enter_time 排序（先入门为师兄）
     *
     * @param int $masterId 师父ID
     * @param int $depth 递归深度（防止无限循环）
     * @param array $visited 已访问的角色ID（防循环引用）
     * @return array 弟子树数组
     */
    private static function buildApprenticeTreeRecursive(int $masterId, int $depth, array $visited): array
    {
        if ($depth > 10) return []; // 最大递归深度

        $apprentices = Database::queryAll(
            'SELECT gc.id, gc.name, gc.family, gc.generation, gc.level, gc.family_enter_time,
                    sm.sect_rank, sm.join_time, sm.contribution
               FROM characters gc
               LEFT JOIN sect_members sm
                      ON sm.character_id = gc.id AND sm.is_active = 1
              WHERE gc.master_id = ?
              ORDER BY gc.family_enter_time ASC, gc.name ASC',
            [$masterId]
        );

        if (!$apprentices) return [];

        $tree = [];
        foreach ($apprentices as $ap) {
            $apId = (int)$ap['id'];
            // 防止循环引用
            if (isset($visited[$apId])) continue;
            $visited[$apId] = true;

            $node = [
                'id'         => (int)$ap['id'],
                'name'       => $ap['name'],
                'generation' => (int)$ap['generation'],
                'level'      => (int)($ap['level'] ?? 0),
                'sect_rank'  => $ap['sect_rank'] ?? '弟子',
                'enter_time' => $ap['family_enter_time'] ?? $ap['join_time'] ?? null,
                'sub_apprentices' => [],
            ];

            // 递归获取徒孙
            $subTree = self::buildApprenticeTreeRecursive($apId, $depth + 1, $visited);
            if (!empty($subTree)) {
                $node['sub_apprentices'] = $subTree;
            }

            $tree[] = $node;
        }
        return $tree;
    }

    // =========================================================
    // 9. 获取门派成员列表
    // =========================================================

    /**
     * 获取指定门派的所有有效成员
     *
     * @param string $sectName 门派名称（sects.php 中的 key，如 'lingtai'）
     * @param mixed  $db       保留参数
     * @return array ['success'=>bool, 'data'=>array]
     */
    public static function getSectMembers(string $sectName, $db = null): array
    {
        try {
            $members = Database::queryAll(
                'SELECT sm.character_id, sm.sect_rank, sm.join_time, sm.contribution,
                        gc.name, gc.level, gc.generation
                   FROM sect_members sm
                   JOIN characters gc ON gc.id = sm.character_id
                  WHERE sm.sect_name = ? AND sm.is_active = 1
                  ORDER BY gc.generation ASC, sm.contribution DESC',
                [$sectName]
            );

            return [
                'success' => true,
                'message' => '',
                'data'    => $members ?: [],
            ];

        } catch (\Exception $e) {
            error_log('ApprenticeHandler::getSectMembers error: ' . $e->getMessage());
            return ['success' => false, 'message' => '获取门派成员失败。', 'data' => null];
        }
    }

    // =========================================================
    // 10. 获取角色的师父信息
    // =========================================================

    /**
     * 获取角色的师父信息
     *
     * @param int   $characterId 角色ID
     * @param mixed $db          保留参数
     * @return array ['success'=>bool, 'data'=>array|null]
     */
    public static function getMasterInfo(int $characterId, $db = null): array
    {
        try {
            $character = Database::queryOne(
                'SELECT master_id, master_name FROM characters WHERE id = ?',
                [$characterId]
            );
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在。', 'data' => null];
            }

            $masterId = (int)($character['master_id'] ?? 0);
            if ($masterId <= 0) {
                return [
                    'success' => true,
                    'message' => '你目前没有师父。',
                    'data'    => null,
                ];
            }

            $master = Database::queryOne(
                'SELECT id, name, family, generation, level FROM characters WHERE id = ?',
                [$masterId]
            );

            if (!$master) {
                // 师父可能已删除，仅返回名称
                return [
                    'success' => true,
                    'message' => '',
                    'data'    => [
                        'id'   => $masterId,
                        'name' => $character['master_name'] ?? '未知',
                    ],
                ];
            }

            return [
                'success' => true,
                'message' => '',
                'data'    => [
                    'id'         => (int)$master['id'],
                    'name'       => $master['name'],
                    'sect'       => $master['family'],
                    'generation' => (int)$master['generation'],
                    'level'      => (int)$master['level'],
                ],
            ];

        } catch (\Exception $e) {
            error_log('ApprenticeHandler::getMasterInfo error: ' . $e->getMessage());
            return ['success' => false, 'message' => '获取师父信息失败。', 'data' => null];
        }
    }

    // =========================================================
    // 11. 获取角色的弟子列表
    // =========================================================

    /**
     * 获取角色的直接弟子列表
     *
     * @param int   $characterId 角色ID
     * @param mixed $db          保留参数
     * @return array ['success'=>bool, 'data'=>array]
     */
    public static function getApprentices(int $characterId, $db = null): array
    {
        try {
            $apprentices = Database::queryAll(
                'SELECT gc.id, gc.name, gc.family, gc.generation, gc.level,
                        sm.sect_rank, sm.join_time, sm.contribution
                   FROM characters gc
                   LEFT JOIN sect_members sm
                          ON sm.character_id = gc.id AND sm.is_active = 1
                  WHERE gc.master_id = ?
                  ORDER BY gc.generation ASC, gc.name ASC',
                [$characterId]
            );

            return [
                'success' => true,
                'message' => '',
                'data'    => $apprentices ?: [],
            ];

        } catch (\Exception $e) {
            error_log('ApprenticeHandler::getApprentices error: ' . $e->getMessage());
            return ['success' => false, 'message' => '获取弟子列表失败。', 'data' => null];
        }
    }

    // =========================================================
    // 12. 获取待处理的拜师请求
    // =========================================================

    /**
     * 获取角色相关的待处理拜师请求
     * - 作为师父：获取别人向自己发的请求
     * - 作为弟子：获取自己发出的请求
     *
     * @param int   $characterId 角色ID
     * @param mixed $db          保留参数
     * @return array ['success'=>bool, 'data'=>['incoming'=>[], 'outgoing'=>[]]]
     */
    public static function getPendingRequests(int $characterId, $db = null): array
    {
        try {
            // 收到的请求（别人想拜自己为师）
            $incoming = Database::queryAll(
                'SELECT ar.id, ar.from_character_id, ar.sect_name, ar.created_at,
                        gc.name AS from_char_name, gc.level AS from_char_level
                   FROM apprentice_requests ar
                   JOIN characters gc ON gc.id = ar.from_character_id
                  WHERE ar.to_character_id = ? AND ar.status = "pending"
                  ORDER BY ar.created_at DESC',
                [$characterId]
            );

            // 发出的请求（自己想拜某人为师）
            $outgoing = Database::queryAll(
                'SELECT ar.id, ar.to_character_id, ar.sect_name, ar.created_at,
                        gc.name AS to_char_name
                   FROM apprentice_requests ar
                   JOIN characters gc ON gc.id = ar.to_character_id
                  WHERE ar.from_character_id = ? AND ar.status = "pending"
                  ORDER BY ar.created_at DESC',
                [$characterId]
            );

            return [
                'success' => true,
                'message' => '',
                'data' => [
                    'incoming' => $incoming ?: [],
                    'outgoing' => $outgoing ?: [],
                ],
            ];

        } catch (\Exception $e) {
            error_log('ApprenticeHandler::getPendingRequests error: ' . $e->getMessage());
            return ['success' => false, 'message' => '获取拜师请求失败。', 'data' => null];
        }
    }

    // =========================================================
    // 私有辅助方法
    // =========================================================

    /**
     * 检查 apprenticeId 是否是 masterId 的弟子（通过 master_id 字段）
     */
    public static function isApprenticeOf(int $apprenticeId, int $masterId): bool
    {
        $row = Database::queryOne(
            'SELECT master_id FROM characters WHERE id = ?',
            [$apprenticeId]
        );
        return $row && (int)$row['master_id'] === $masterId;
    }

    /**
     * 判断两个玩家之间的师徒谱系关系（还原原始项目 look 命令的关系判定）
     *
     * 关系类型：
     * - 师父/师父：target 是 current 的直接师父
     * - 弟子/弟子：target 是 current 的直接弟子
     * - 师伯/师叔：与 current 同门，但入门时间早于 current（师伯）或晚于 current（师叔）
     *   注：原始项目中同门按 enter_time 排序，先入门为师兄/师姐，后入门为师弟/师妹
     *   此处"师伯/师叔"指师父的师兄/师弟（即与师父同门但辈分相同者）
     * - 师兄/师弟：与 current 同师父，按 enter_time 排序
     * - 师侄/师侄：target 是 current 弟子的弟子（徒孙）
     * - 师祖/师祖：target 是 current 师父的师父（师祖）
     *
     * @param int $currentCharId 当前玩家ID
     * @param int $targetCharId  目标玩家ID
     * @return array ['related'=>bool, 'relation'=>string, 'title'=>string]
     *               relation: 关系标识（master/apprentice/senior/junior/nephew/ancestor/sibling）
     *               title: 中文称谓（师父/弟子/师兄/师弟/师侄/师祖/无）
     */
    public static function getRelationship(int $currentCharId, int $targetCharId): array
    {
        try {
            if ($currentCharId === $targetCharId) {
                return ['related' => false, 'relation' => 'self', 'title' => ''];
            }

            // 获取双方角色信息
            $current = Database::queryOne(
                'SELECT id, master_id, family, family_enter_time, generation FROM characters WHERE id = ?',
                [$currentCharId]
            );
            $target = Database::queryOne(
                'SELECT id, master_id, family, family_enter_time, generation FROM characters WHERE id = ?',
                [$targetCharId]
            );

            if (!$current || !$target) {
                return ['related' => false, 'relation' => 'none', 'title' => ''];
            }

            $curMasterId = (int)($current['master_id'] ?? 0);
            $tgtMasterId = (int)($target['master_id'] ?? 0);
            $curFamily   = $current['family'] ?? '';
            $tgtFamily   = $target['family'] ?? '';

            // 1. 直接师徒关系：target 是 current 的师父
            if ($curMasterId > 0 && $curMasterId === $targetCharId) {
                return ['related' => true, 'relation' => 'master', 'title' => '师父'];
            }

            // 2. 直接师徒关系：target 是 current 的弟子
            if ($tgtMasterId > 0 && $tgtMasterId === $currentCharId) {
                return ['related' => true, 'relation' => 'apprentice', 'title' => '弟子'];
            }

            // 3. 同师父的师兄弟关系
            if ($curMasterId > 0 && $curMasterId === $tgtMasterId) {
                // 按 family_enter_time 排序判断师兄/师弟
                $curEnter = $current['family_enter_time'] ?? null;
                $tgtEnter = $target['family_enter_time'] ?? null;

                if ($curEnter && $tgtEnter) {
                    if (strcmp($tgtEnter, $curEnter) < 0) {
                        return ['related' => true, 'relation' => 'senior', 'title' => '师兄'];
                    } else {
                        return ['related' => true, 'relation' => 'junior', 'title' => '师弟'];
                    }
                }
                // 无入门时间，按 ID 判断（ID 小的为师兄）
                if ($targetCharId < $currentCharId) {
                    return ['related' => true, 'relation' => 'senior', 'title' => '师兄'];
                }
                return ['related' => true, 'relation' => 'junior', 'title' => '师弟'];
            }

            // 4. 师父的师父 = 师祖
            if ($curMasterId > 0) {
                $masterOfMaster = Database::queryOne(
                    'SELECT id, master_id FROM characters WHERE id = ?',
                    [$curMasterId]
                );
                if ($masterOfMaster && (int)($masterOfMaster['master_id'] ?? 0) === $targetCharId) {
                    return ['related' => true, 'relation' => 'ancestor', 'title' => '师祖'];
                }

                // 5. 师父的师兄/师弟 = 师伯/师叔
                $masterInfo = Database::queryOne(
                    'SELECT id, master_id, family_enter_time FROM characters WHERE id = ?',
                    [$curMasterId]
                );
                if ($masterInfo) {
                    $masterMasterId = (int)($masterInfo['master_id'] ?? 0);
                    if ($masterMasterId > 0 && $masterMasterId === $tgtMasterId) {
                        // target 与师父同门
                        $masterEnter = $masterInfo['family_enter_time'] ?? null;
                        $tgtEnter    = $target['family_enter_time'] ?? null;
                        if ($masterEnter && $tgtEnter) {
                            if (strcmp($tgtEnter, $masterEnter) < 0) {
                                return ['related' => true, 'relation' => 'uncle_senior', 'title' => '师伯'];
                            } else {
                                return ['related' => true, 'relation' => 'uncle_junior', 'title' => '师叔'];
                            }
                        }
                        if ($targetCharId < $curMasterId) {
                            return ['related' => true, 'relation' => 'uncle_senior', 'title' => '师伯'];
                        }
                        return ['related' => true, 'relation' => 'uncle_junior', 'title' => '师叔'];
                    }
                }
            }

            // 6. 弟子的弟子 = 师侄（target 是 current 徒弟的徒弟）
            $myApprentices = Database::queryAll(
                'SELECT id FROM characters WHERE master_id = ?',
                [$currentCharId]
            );
            if ($myApprentices) {
                $myApprenticeIds = array_map(fn($a) => (int)$a['id'], $myApprentices);
                $placeholders = implode(',', array_fill(0, count($myApprenticeIds), '?'));
                $grandApprentices = Database::queryAll(
                    "SELECT id FROM characters WHERE master_id IN ({$placeholders})",
                    $myApprenticeIds
                );
                if ($grandApprentices) {
                    foreach ($grandApprentices as $ga) {
                        if ((int)$ga['id'] === $targetCharId) {
                            return ['related' => true, 'relation' => 'nephew', 'title' => '师侄'];
                        }
                    }
                }
            }

            // 7. 同门但不同师父（同门派不同辈分）
            if (!empty($curFamily) && $curFamily === $tgtFamily) {
                $curGen = (int)($current['generation'] ?? 0);
                $tgtGen = (int)($target['generation'] ?? 0);
                if ($curGen > 0 && $tgtGen > 0) {
                    if ($tgtGen < $curGen) {
                        $diff = $curGen - $tgtGen;
                        if ($diff === 1) return ['related' => true, 'relation' => 'uncle_senior', 'title' => '师叔'];
                        return ['related' => true, 'relation' => 'ancestor', 'title' => '师祖'];
                    } elseif ($tgtGen > $curGen) {
                        $diff = $tgtGen - $curGen;
                        if ($diff === 1) return ['related' => true, 'relation' => 'nephew', 'title' => '师侄'];
                        return ['related' => true, 'relation' => 'descendant', 'title' => '徒孙'];
                    } else {
                        // 同辈分同门派，按入门时间判断师兄师弟
                        $curEnter = $current['family_enter_time'] ?? null;
                        $tgtEnter = $target['family_enter_time'] ?? null;
                        if ($curEnter && $tgtEnter) {
                            if (strcmp($tgtEnter, $curEnter) < 0) {
                                return ['related' => true, 'relation' => 'senior', 'title' => '师兄'];
                            } else {
                                return ['related' => true, 'relation' => 'junior', 'title' => '师弟'];
                            }
                        }
                        if ($targetCharId < $currentCharId) {
                            return ['related' => true, 'relation' => 'senior', 'title' => '师兄'];
                        }
                        return ['related' => true, 'relation' => 'junior', 'title' => '师弟'];
                    }
                }
            }

            return ['related' => false, 'relation' => 'none', 'title' => ''];

        } catch (\Exception $e) {
            error_log('ApprenticeHandler::getRelationship error: ' . $e->getMessage());
            return ['related' => false, 'relation' => 'none', 'title' => ''];
        }
    }

    /**
     * 插入或更新 sect_members 表中的记录
     */
    private static function upsertSectMember(int $characterId, string $sectName, string $sectRank): void
    {
        // 先将旧记录设为无效
        Database::execute(
            'UPDATE sect_members SET is_active = 0 WHERE character_id = ? AND is_active = 1',
            [$characterId]
        );

        // 插入新记录
        Database::execute(
            'INSERT INTO sect_members (character_id, sect_name, sect_rank, join_time, is_active, contribution)
              VALUES (?, ?, ?, NOW(), 1, 0)',
            [$characterId, $sectName, $sectRank]
        );
    }

    /**
     * 将整数转换为中文数字（用于第X代弟子）
     */
    private static function chineseNumber(int $num): string
    {
        $chars = ['零', '一', '二', '三', '四', '五', '六', '七', '八', '九', '十'];
        if ($num <= 0)  return '零';
        if ($num <= 10) return $chars[$num];
        if ($num < 20)  return '十' . $chars[$num - 10];
        if ($num < 100) {
            $ten = intdiv($num, 10);
            $one = $num % 10;
            return ($ten > 1 ? $chars[$ten] : '') . '十' . ($one > 0 ? $chars[$one] : '');
        }
        return (string)$num;
    }

    // =========================================================
    // 专项辅助：背叛日志写入
    // =========================================================

    /**
     * 将背叛事件写入日志文件
     *
     * @param int         $characterId    角色ID
     * @param array       $character      角色数据
     * @param string|null $oldSect        原门派 key
     * @param int         $expLoss        损失的经验量
     * @param array       $exclusiveLost  被清除的专属技能
     * @param float       $multiplier     惩罚倍数
     */
    private static function writeBetrayalLog(
        int    $characterId,
        array  $character,
        ?string $oldSect,
        int    $expLoss,
        array  $exclusiveLost,
        float  $multiplier
    ): void {
        try {
            $logDir  = __DIR__ . '/../logs';
            $logFile = $logDir . '/betrayal.log';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            $line = sprintf(
                "[%s] 角色ID=%d name=%s 原门派=%s 背叛次数=%d 损失经验=%d 专属清零=%s 倍数=%.2f\n",
                date('Y-m-d H:i:s'),
                $characterId,
                $character['name'] ?? '',
                $oldSect ?? 'unknown',
                (int)($character['betrayal_count'] ?? 0),
                $expLoss,
                implode(',', array_keys($exclusiveLost)),
                $multiplier
            );
            file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        } catch (\Exception $e) {
            error_log('ApprenticeHandler::writeBetrayalLog error: ' . $e->getMessage());
        }
    }

    // =========================================================
    // 7b. 背叛冷却期检查
    // =========================================================

    /**
     * 检查角色是否处于背叛冷却期
     *
     * @param int $characterId 角色ID
     * @return array ['allowed'=>bool, 'message'=>string, 'remaining_days'=>int]
     */
    public static function checkBetrayalCooldown(int $characterId): array
    {
        try {
            $character = Database::queryOne(
                'SELECT id, name, family_enter_time FROM characters WHERE id = ?',
                [$characterId]
            );
            if (!$character) {
                return ['allowed' => false, 'message' => '角色不存在。', 'remaining_days' => 0];
            }

            $sectConfig    = SectHelper::getSectConfig();
            $penaltyConfig = $sectConfig['betrayal_penalty'] ?? [];
            $cooldownDays  = (int)($penaltyConfig['cooldown_days'] ?? 7);

            if (empty($character['family_enter_time'])) {
                return ['allowed' => true, 'message' => '', 'remaining_days' => 0];
            }

            $enterTime   = strtotime($character['family_enter_time']);
            $nowTime     = time();
            $elapsedDays = (int)(($nowTime - $enterTime) / 86400);
            $remaining   = $cooldownDays - $elapsedDays;

            if ($remaining > 0) {
                return [
                    'allowed'        => false,
                    'message'        => sprintf(
                        '你入门未满 %d 天（还需 %d 天），尚不可离开门派。',
                        $cooldownDays,
                        $remaining
                    ),
                    'remaining_days' => $remaining,
                ];
            }

            return ['allowed' => true, 'message' => '', 'remaining_days' => 0];

        } catch (\Exception $e) {
            error_log('ApprenticeHandler::checkBetrayalCooldown error: ' . $e->getMessage());
            return ['allowed' => false, 'message' => '冷却期检查失败，请稍后再试。', 'remaining_days' => 0];
        }
    }

    // =========================================================
    // 13. 掌门权限系统
    // =========================================================

    /**
     * 检查角色是否为指定门派的掌门
     *
     * @param int         $characterId 角色ID
     * @param string|null $sectName    门派 key；不传则检查角色当前门派
     * @return bool
     */
    public static function isSectMaster(int $characterId, ?string $sectName = null): bool
    {
        try {
            // 确定门派
            if ($sectName === null) {
                $row = Database::queryOne(
                    'SELECT family FROM characters WHERE id = ?',
                    [$characterId]
                );
                if (!$row || empty($row['family'])) {
                    return false;
                }
                $sectName = $row['family'];
            }

            // 取该门派 ranks 数组的最后一项为掌门称谓
            $sectConf    = SectHelper::getSectConfig($sectName);
            $ranks       = $sectConf['ranks'] ?? [];
            $masterRank  = !empty($ranks) ? end($ranks) : null;

            if ($masterRank === null) {
                return false;
            }

            $member = Database::queryOne(
                'SELECT id FROM sect_members
                  WHERE character_id = ? AND sect_name = ? AND sect_rank = ? AND is_active = 1',
                [$characterId, $sectName, $masterRank]
            );

            return $member !== null && $member !== false;

        } catch (\Exception $e) {
            error_log('ApprenticeHandler::isSectMaster error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 掌门逐出任意门派成员（不限于自己弟子）
     *
     * @param int    $masterId  掌门角色ID
     * @param int    $targetId  被递出角色ID
     * @param string $reason    递出原因
     * @return array
     */
    public static function masterExpell(int $masterId, int $targetId, string $reason = ''): array
    {
        try {
            if ($masterId === $targetId) {
                return ['success' => false, 'message' => '掌门不能递出自己。', 'data' => null];
            }

            $master = Database::queryOne(
                'SELECT id, name, family FROM characters WHERE id = ?',
                [$masterId]
            );
            if (!$master || empty($master['family'])) {
                return ['success' => false, 'message' => '掌门角色不存在或没有门派。', 'data' => null];
            }

            $sectName = $master['family'];

            if (!self::isSectMaster($masterId, $sectName)) {
                return ['success' => false, 'message' => '你不是本门派掌门，无权递出成员。', 'data' => null];
            }

            $target = Database::queryOne(
                'SELECT id, name, family FROM characters WHERE id = ?',
                [$targetId]
            );
            if (!$target) {
                return ['success' => false, 'message' => '目标角色不存在。', 'data' => null];
            }

            if ($target['family'] !== $sectName) {
                return [
                    'success' => false,
                    'message' => $target['name'] . '不属于' . ($master['family']) . '，无法递出。',
                    'data' => null,
                ];
            }

            Database::beginTransaction();

            Database::execute(
                'UPDATE characters
                    SET master_id = NULL, master_name = NULL, generation = 0,
                        family = NULL, family_name = NULL, family_enter_time = NULL, family_privs = NULL
                  WHERE id = ?',
                [$targetId]
            );

            Database::execute(
                'UPDATE sect_members SET is_active = 0 WHERE character_id = ? AND is_active = 1',
                [$targetId]
            );

            Database::commit();

            $sectConf   = SectHelper::getSectConfig($sectName);
            $sectDisplay = $sectConf['name'] ?? $sectName;
            $reasonText  = !empty($reason) ? ('，原因：' . $reason) : '';
            $broadcastMsg = sprintf(
                '掌门%s将%s递出%s%s。',
                $master['name'],
                $target['name'],
                $sectDisplay,
                $reasonText
            );

            return [
                'success' => true,
                'message' => $broadcastMsg,
                'data'    => [
                    'master_name' => $master['name'],
                    'target_name' => $target['name'],
                    'sect_name'   => $sectName,
                    'reason'      => $reason,
                ],
            ];

        } catch (\Exception $e) {
            Database::rollBack();
            error_log('ApprenticeHandler::masterExpell error: ' . $e->getMessage());
            return ['success' => false, 'message' => '掌门递出操作失败。', 'data' => null];
        }
    }

    /**
     * 掌门审批入门请求（直接将请求者加入门派）
     *
     * @param int $masterId   掌门角色ID
     * @param int $requestId  apprentice_requests.id
     * @return array
     */
    public static function approveMember(int $masterId, int $requestId): array
    {
        try {
            $master = Database::queryOne(
                'SELECT id, name, family, generation FROM characters WHERE id = ?',
                [$masterId]
            );
            if (!$master || empty($master['family'])) {
                return ['success' => false, 'message' => '掌门角色不存在或没有门派。', 'data' => null];
            }

            $sectName = $master['family'];

            if (!self::isSectMaster($masterId, $sectName)) {
                return ['success' => false, 'message' => '你不是本门派掌门，无权审批入门请求。', 'data' => null];
            }

            $request = Database::queryOne(
                'SELECT * FROM apprentice_requests WHERE id = ? AND status = "pending"',
                [$requestId]
            );
            if (!$request) {
                return ['success' => false, 'message' => '找不到该入门请求或已处理。', 'data' => null];
            }

            $applicantId = (int)$request['from_character_id'];
            $applicant   = Database::queryOne(
                'SELECT id, name, family, betrayal_count FROM characters WHERE id = ?',
                [$applicantId]
            );
            if (!$applicant) {
                return ['success' => false, 'message' => '申请入门的角色不存在。', 'data' => null];
            }

            $sectConf = SectHelper::getSectConfig($sectName);
            $ranks    = $sectConf['ranks'] ?? ['弟子'];
            $initRank = $ranks[0];
            $isBetray = !empty($applicant['family']) && $applicant['family'] !== $sectName;
            $newGen   = (int)($master['generation'] ?? 1) + 1;

            Database::beginTransaction();

            if ($isBetray) {
                Database::execute(
                    'UPDATE characters SET betrayal_count = betrayal_count + 1 WHERE id = ?',
                    [$applicantId]
                );
                Database::execute(
                    'UPDATE sect_members SET is_active = 0 WHERE character_id = ? AND is_active = 1',
                    [$applicantId]
                );
            }

            // 获取门派全称
            $familyName = $sectConf['family_name'] ?? ($sectConf['name'] ?? $sectName);

            Database::execute(
                'UPDATE characters
                    SET family = ?, family_name = ?, master_id = ?, master_name = ?,
                        generation = ?, family_enter_time = NOW(), family_privs = NULL
                  WHERE id = ?',
                [$sectName, $familyName, $masterId, $master['name'], $newGen, $applicantId]
            );

            self::upsertSectMember($applicantId, $sectName, $initRank);

            Database::execute(
                'UPDATE apprentice_requests SET status = "accepted", resolved_at = NOW() WHERE id = ?',
                [$requestId]
            );

            Database::commit();

            if ($isBetray) {
                self::applyBetrayalPenalty($applicantId, null, $applicant['family']);
            }

            $sectDisplay = $sectConf['name'] ?? $sectName;
            return [
                'success' => true,
                'message' => sprintf(
                    '掌门%s审批了%s的入门申请，%s正式加入%s。',
                    $master['name'],
                    $applicant['name'],
                    $applicant['name'],
                    $sectDisplay
                ),
                'data' => [
                    'applicant_name' => $applicant['name'],
                    'sect_name'      => $sectName,
                    'sect_rank'      => $initRank,
                    'generation'     => $newGen,
                ],
            ];

        } catch (\Exception $e) {
            Database::rollBack();
            error_log('ApprenticeHandler::approveMember error: ' . $e->getMessage());
            return ['success' => false, 'message' => '掌门审批失败。', 'data' => null];
        }
    }

    /**
     * 掌门传位（将掌门职位移交给继承者）
     *
     * @param int $currentMasterId 当前掌门角色ID
     * @param int $successorId     新掌门角色ID
     * @return array
     */
    public static function transferMastership(int $currentMasterId, int $successorId): array
    {
        try {
            if ($currentMasterId === $successorId) {
                return ['success' => false, 'message' => '无法将掌门传给自己。', 'data' => null];
            }

            $current = Database::queryOne(
                'SELECT id, name, family FROM characters WHERE id = ?',
                [$currentMasterId]
            );
            if (!$current || empty($current['family'])) {
                return ['success' => false, 'message' => '当前掌门角色不存在或没有门派。', 'data' => null];
            }

            $sectName = $current['family'];

            if (!self::isSectMaster($currentMasterId, $sectName)) {
                return ['success' => false, 'message' => '你不是本门派掌门，无权传位。', 'data' => null];
            }

            $successor = Database::queryOne(
                'SELECT id, name, family FROM characters WHERE id = ?',
                [$successorId]
            );
            if (!$successor) {
                return ['success' => false, 'message' => '继承者角色不存在。', 'data' => null];
            }

            if ($successor['family'] !== $sectName) {
                return [
                    'success' => false,
                    'message' => $successor['name'] . '不属于本门派，无法传位。',
                    'data' => null,
                ];
            }

            $sectConf   = SectHelper::getSectConfig($sectName);
            $ranks      = $sectConf['ranks'] ?? [];
            $masterRank = !empty($ranks) ? end($ranks) : '掌门';
            // 继承者的旧职位
            $successorMember = Database::queryOne(
                'SELECT sect_rank FROM sect_members
                  WHERE character_id = ? AND sect_name = ? AND is_active = 1',
                [$successorId, $sectName]
            );
            $successorOldRank = $successorMember['sect_rank'] ?? ($ranks[max(0, count($ranks) - 2)] ?? '弟子');

            Database::beginTransaction();

            // 当前掌门降为继承者的旧职位
            Database::execute(
                'UPDATE sect_members SET sect_rank = ? WHERE character_id = ? AND sect_name = ? AND is_active = 1',
                [$successorOldRank, $currentMasterId, $sectName]
            );

            // 继承者晋升为掌门
            Database::execute(
                'UPDATE sect_members SET sect_rank = ? WHERE character_id = ? AND sect_name = ? AND is_active = 1',
                [$masterRank, $successorId, $sectName]
            );

            Database::commit();

            $sectDisplay = $sectConf['name'] ?? $sectName;
            return [
                'success' => true,
                'message' => sprintf(
                    '%s将%s掌门之位传给%s，%s正式就任新掌门。',
                    $current['name'],
                    $sectDisplay,
                    $successor['name'],
                    $successor['name']
                ),
                'data' => [
                    'old_master'  => $current['name'],
                    'new_master'  => $successor['name'],
                    'sect_name'   => $sectName,
                    'old_rank'    => $successorOldRank,
                    'new_rank'    => $masterRank,
                ],
            ];

        } catch (\Exception $e) {
            Database::rollBack();
            error_log('ApprenticeHandler::transferMastership error: ' . $e->getMessage());
            return ['success' => false, 'message' => '掌门传位失败。', 'data' => null];
        }
    }

    /**
     * 掌门自动继承逻辑（找贡献最高且加入时间最久的核心弟子或长老）
     *
     * @param string $sectName 门派 key
     * @return array
     */
    public static function autoSuccession(string $sectName): array
    {
        try {
            $sectConf   = SectHelper::getSectConfig($sectName);
            $ranks      = $sectConf['ranks'] ?? [];
            $masterRank = !empty($ranks) ? end($ranks) : '掌门';

            // 高级资格进行继承：尽量从高级屁中选
            // 我们只需要排除掌门自身，找贡献最高且入门最久的
            $candidate = Database::queryOne(
                'SELECT sm.character_id, sm.sect_rank, sm.join_time, sm.contribution,
                        gc.name
                   FROM sect_members sm
                   JOIN characters gc ON gc.id = sm.character_id
                  WHERE sm.sect_name = ? AND sm.is_active = 1 AND sm.sect_rank != ?
                  ORDER BY sm.contribution DESC, sm.join_time ASC
                  LIMIT 1',
                [$sectName, $masterRank]
            );

            if (!$candidate) {
                return [
                    'success' => false,
                    'message' => sprintf('%s无合适的继承者，无法自动继承。', $sectConf['name'] ?? $sectName),
                    'data'   => null,
                ];
            }

            $successorId   = (int)$candidate['character_id'];
            $successorName = $candidate['name'];

            Database::execute(
                'UPDATE sect_members SET sect_rank = ?
                  WHERE character_id = ? AND sect_name = ? AND is_active = 1',
                [$masterRank, $successorId, $sectName]
            );

            $sectDisplay = $sectConf['name'] ?? $sectName;
            return [
                'success' => true,
                'message' => sprintf(
                    '%s无掌门，%s因贡献卒御而就任新掌门。',
                    $sectDisplay,
                    $successorName
                ),
                'data' => [
                    'new_master_id'   => $successorId,
                    'new_master_name' => $successorName,
                    'sect_name'       => $sectName,
                    'new_rank'        => $masterRank,
                ],
            ];

        } catch (\Exception $e) {
            error_log('ApprenticeHandler::autoSuccession error: ' . $e->getMessage());
            return ['success' => false, 'message' => '自动继承处理失败。', 'data' => null];
        }
    }

    // =========================================================
    // 14. 掌门专属加成
    // =========================================================

    /**
     * 获取掌门专属加成
     *
     * 掌门练功效率+10%，经验获取+20%，战斗属性+5%
     *
     * @param int $characterId 角色ID
     * @return array ['is_master'=>bool, 'bonuses'=>array]
     */
    public static function getMasterBonus(int $characterId): array
    {
        try {
            $isMaster = self::isSectMaster($characterId);

            if (!$isMaster) {
                return [
                    'is_master' => false,
                    'bonuses'   => [
                        'skill_bonus'  => 0,
                        'exp_bonus'    => 0,
                        'combat_bonus' => 0,
                    ],
                ];
            }

            return [
                'is_master' => true,
                'bonuses'   => [
                    'skill_bonus'  => 10,  // 练功效率 +10%
                    'exp_bonus'    => 20,  // 经验获取 +20%
                    'combat_bonus' => 5,   // 战斗属性 +5%
                ],
            ];

        } catch (\Exception $e) {
            error_log('ApprenticeHandler::getMasterBonus error: ' . $e->getMessage());
            return [
                'is_master' => false,
                'bonuses'   => [
                    'skill_bonus'  => 0,
                    'exp_bonus'    => 0,
                    'combat_bonus' => 0,
                ],
            ];
        }
    }
}

