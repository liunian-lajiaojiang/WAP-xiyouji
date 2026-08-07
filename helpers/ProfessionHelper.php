<?php
/**
 * ProfessionHelper - 职业系统集中管理
 *
 * 对应原始项目的 class 属性系统
 *
 * 职业列表：
 *   xian     - 仙人（仙族门派）
 *   yaomo    - 妖魔（妖族门派）
 *   dragon   - 龙族（龙宫）
 *   youling  - 幽灵（地府）
 *   bonze    - 僧人（南海普陀）
 *   taoist   - 道士（方寸山、五庄观）
 *   fighter  - 武将（将军府）
 *   scholar  - 儒生（默认人类、贺知章处申请）
 *   dancer   - 舞者（公孙大娘处申请，仅女性）
 *
 * 原始项目逻辑：
 *   1. 拜师时由NPC的recruit_apprentice()设置class
 *   2. 部分职业需手动申请（dancer、scholar）
 *   3. rankd.c 根据class显示不同头衔
 *   4. 技能学习、功能限制检查class
 */

require_once __DIR__ . '/../includes/db.php';

class ProfessionHelper
{
    /**
     * 所有有效职业列表
     */
    const VALID_PROFESSIONS = [
        'xian', 'yaomo', 'dragon', 'youling',
        'bonze', 'taoist', 'fighter', 'scholar', 'dancer',
    ];

    /**
     * 职业中文名
     */
    const PROFESSION_NAMES = [
        'xian'    => '仙',
        'yaomo'   => '妖魔',
        'dragon'  => '龙',
        'youling' => '幽灵',
        'bonze'   => '僧',
        'taoist'  => '道',
        'fighter' => '侠',
        'scholar' => '儒',
        'dancer'  => '舞',
    ];

    /**
     * 门派 → 默认职业映射（根据种族可能不同）
     */
    const SECT_DEFAULT_PROFESSION = [
        'lingtai'    => 'taoist',
        'wudidong'   => 'yaomo',
        'longgong'   => 'dragon',
        'nanhai'     => 'bonze',
        'moon'       => null,      // 月宫无固定职业
        'wzg'        => 'taoist',
        'yanluofu'   => 'youling',
        'jiangjunfu' => 'fighter',
        'huoyun'     => 'yaomo',
        'xueshan'    => 'yaomo',
    ];

    /**
     * 根据race和family推算职业（动态推算，不依赖数据库存储）
     *
     * 对应原始项目 rankd.c 中的 class 推算逻辑
     */
    public static function inferProfession(string $race, string $family): string
    {
        // 仙族
        if ($race === 'god') return 'xian';
        // 妖族
        if ($race === 'demon') return 'yaomo';
        // 怪物
        if ($race === 'monster') {
            if ($family === 'longgong') return 'dragon';
            if ($family === 'yanluofu') return 'youling';
        }
        // 人类
        if ($race === 'human') {
            if ($family === 'nanhai') return 'bonze';
            if (in_array($family, ['wzg', 'lingtai'])) return 'taoist';
            if ($family === 'jiangjunfu') return 'fighter';
            return 'scholar';
        }
        return 'scholar';
    }

    /**
     * 获取玩家的实际职业（优先读取数据库存储值，否则动态推算）
     *
     * 对应原始项目的 query("class") 逻辑
     *
     * @param array $char 角色数据（需包含 race, family, profession）
     * @return string 职业标识
     */
    public static function getProfession(array $char): string
    {
        // 优先使用数据库中存储的profession（手动设置的，如dancer、scholar）
        $stored = $char['profession'] ?? '';
        if (!empty($stored) && in_array($stored, self::VALID_PROFESSIONS)) {
            return $stored;
        }
        // 否则动态推算
        return self::inferProfession($char['race'] ?? 'human', $char['family'] ?? '');
    }

    /**
     * 获取职业中文名
     */
    public static function getProfessionName(string $profession): string
    {
        return self::PROFESSION_NAMES[$profession] ?? '凡人';
    }

    /**
     * 设置玩家职业
     *
     * 对应原始项目的 set("class", "xxx")
     *
     * @param int    $charId     角色ID
     * @param string $profession 职业标识
     * @return bool
     */
    public static function setProfession(int $charId, string $profession): bool
    {
        if (!in_array($profession, self::VALID_PROFESSIONS)) {
            return false;
        }
        try {
            Database::execute(
                'UPDATE characters SET profession = ? WHERE id = ?',
                [$profession, $charId]
            );
            return true;
        } catch (\Exception $e) {
            error_log('ProfessionHelper::setProfession error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 清除玩家职业（叛门时调用）
     *
     * 对应原始项目的 delete("class")
     */
    public static function clearProfession(int $charId): bool
    {
        try {
            Database::execute(
                'UPDATE characters SET profession = NULL WHERE id = ?',
                [$charId]
            );
            return true;
        } catch (\Exception $e) {
            error_log('ProfessionHelper::clearProfession error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 拜师时自动设置职业
     *
     * 根据门派配置和角色种族决定职业
     *
     * @param int    $charId   角色ID
     * @param string $sectKey  门派key
     * @param string $race     角色种族
     * @return string 实际设置的职业
     */
    public static function setProfessionOnJoin(int $charId, string $sectKey, string $race): string
    {
        $profession = self::SECT_DEFAULT_PROFESSION[$sectKey] ?? null;

        if ($profession === null) {
            // 无固定职业的门派（如月宫），根据种族推算
            $profession = self::inferProfession($race, $sectKey);
        }

        self::setProfession($charId, $profession);
        return $profession;
    }

    /**
     * 申请舞者职业
     *
     * 对应原始项目公孙大娘处申请 dancer
     *
     * 条件：女性，年龄<30
     */
    public static function applyDancer(array $char): array
    {
        $gender = $char['gender'] ?? '';
        $age = intval($char['age'] ?? 20);
        $charId = intval($char['id'] ?? 0);

        if ($gender !== 'female' && $gender !== '女') {
            return ['success' => false, 'message' => '只有女性才能成为舞者。'];
        }

        if ($age >= 30) {
            return ['success' => false, 'message' => '岁月不饶人，姑娘还是另寻它路吧。'];
        }

        $profession = $char['profession'] ?? '';
        if ($profession === 'dancer') {
            return ['success' => false, 'message' => '你已经是舞者了。'];
        }

        if (self::setProfession($charId, 'dancer')) {
            return ['success' => true, 'message' => '你正式成为了一名舞者！好好修炼舞技吧。'];
        }

        return ['success' => false, 'message' => '申请失败，请稍后再试。'];
    }

    /**
     * 申请儒生职业
     *
     * 对应原始项目何知章处申请 scholar
     */
    public static function applyScholar(array $char): array
    {
        $charId = intval($char['id'] ?? 0);
        $profession = $char['profession'] ?? '';

        if ($profession === 'scholar') {
            return ['success' => false, 'message' => '你已经是儒生了。'];
        }

        // 原始项目无特殊限制，任何人可申请
        if (self::setProfession($charId, 'scholar')) {
            return ['success' => true, 'message' => '你正式成为了一名儒生！好好读书吧。'];
        }

        return ['success' => false, 'message' => '申请失败，请稍后再试。'];
    }

    /**
     * 检查职业限制
     *
     * 对应原始项目的 query("class") != "xxx" 检查
     *
     * @param array  $char       角色数据
     * @param string $required   要求的职业
     * @return bool
     */
    public static function hasProfession(array $char, string $required): bool
    {
        return self::getProfession($char) === $required;
    }

    /**
     * 检查是否为妖魔（取经人资格检查）
     */
    public static function isYaomo(array $char): bool
    {
        $profession = self::getProfession($char);
        return $profession === 'yaomo';
    }
}
