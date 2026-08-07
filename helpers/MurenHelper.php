<?php
/**
 * 木人Helper - 玩家镜像对手训练系统
 * 还原原始项目 xyj2000/d/obj/misc/muren.c 的设计：
 * - 木人接受战斗时复制玩家的技能和属性（镜像对手）
 * - 同一玩家不能连续挑战同一木人（5-10分钟冷却）
 * - 木人累计战斗次数后有随机损坏机制
 * 
 * xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../helpers/AttributeHelper.php';

class MurenHelper {
    
    // 木人NPC的npc_id列表
    const MUREN_NPC_IDS = ['mu1', 'mu2', 'mu3'];
    
    // 冷却时间范围（秒），还原原始项目的 300+random(300)
    const COOLDOWN_MIN = 300;
    const COOLDOWN_MAX = 600;
    
    // 损坏阈值：random(fight_times) >= DAMAGE_THRESHOLD 时损坏
    const DAMAGE_THRESHOLD = 10;
    
    /**
     * 判断NPC是否是木人
     */
    public static function isMuren(array $npc): bool {
        return in_array($npc['npc_id'] ?? '', self::MUREN_NPC_IDS);
    }
    
    /**
     * 判断NPC数据库ID是否是木人
     */
    public static function isMurenById(int $npcDbId): bool {
        $npc = Database::queryOne("SELECT npc_id FROM npcs WHERE id = ? LIMIT 1", [$npcDbId]);
        return $npc && in_array($npc['npc_id'], self::MUREN_NPC_IDS);
    }
    
    /**
     * 木人接受战斗（还原原始 muren.c::accept_fight 逻辑）
     * 
     * @param int $charId 玩家ID
     * @param array $npc NPC数据（需包含 id, npc_id, name）
     * @return array ['accept' => bool, 'message' => string]
     */
    public static function acceptFight(int $charId, array $npc): array {
        $npcDbId = intval($npc['id']);
        $npcName = $npc['name'] ?? '木人';
        
        // 1. 检查木人是否已损坏（还原原始：if(me->query("damaged"))）
        $status = self::getStatus($npcDbId);
        if ($status && intval($status['damaged']) === 1) {
            return [
                'accept' => false,
                'message' => '这个木人已经被打坏了！'
            ];
        }
        
        // 2. 随机损坏判定（还原原始：if(random(fight_times)>=10)）
        $fightTimes = $status ? intval($status['fight_times']) : 0;
        if ($fightTimes > 0 && mt_rand(0, max(1, $fightTimes)) >= self::DAMAGE_THRESHOLD) {
            self::setDamaged($npcDbId);
            return [
                'accept' => false,
                'message' => '这个木人已经被打坏了！'
            ];
        }
        
        // 3. 同一玩家连续挑战检查（还原原始：if(last_fighter==ob->query("id"))）
        if ($status && $status['last_fighter_id']) {
            if (intval($status['last_fighter_id']) === $charId) {
                $lastFightTime = intval($status['last_fight_time']);
                // 冷却时间：300~600秒（还原原始 call_out("renewing", 300+random(300))）
                $cooldown = self::COOLDOWN_MIN + mt_rand(0, self::COOLDOWN_MAX - self::COOLDOWN_MIN);
                if (time() - $lastFightTime < $cooldown) {
                    return [
                        'accept' => false,
                        'message' => '你刚跟这个木人练过功！'
                    ];
                }
                // 冷却已过，清除 last_fighter（还原原始 renewing()）
                self::clearLastFighter($npcDbId);
            }
        }
        
        // 4. 记录状态：last_fighter + fight_times（还原原始 set/add 逻辑）
        self::updateFightStatus($npcDbId, $charId);
        
        // 5. 镜像复制玩家属性到木人（还原原始 accept_fight 的核心复制逻辑）
        self::mirrorPlayerAttributes($charId, $npcDbId);
        
        // 6. 接受战斗
        return [
            'accept' => true,
            'message' => $npcName . '做好了比武的准备。'
        ];
    }
    
    /**
     * 复制玩家属性到木人NPC（还原原始 muren.c 第66-104行）
     * 
     * 还原的逻辑：
     * - me->delete_skill("unarmed") → 清除木人原有技能
     * - me->map_skill(...) → 清除木人原有映射
     * - 复制玩家所有技能 set_skill(sname[i], level)
     * - 复制玩家技能映射 map_skill(mname[i], mapped)
     * - 复制玩家天赋 str/int/con/dex
     * - 复制玩家气血 max_kee/eff_kee/kee/max_gin/eff_gin/gin/max_sen/eff_sen/sen
     * - 复制玩家内力 max_force/force/enforce
     * - 复制玩家战斗经验 combat_exp
     */
    private static function mirrorPlayerAttributes(int $charId, int $npcDbId): void {
        require_once __DIR__ . '/../models/Character.php';
        require_once __DIR__ . '/../helpers/SkillManager.php';
        
        $char = CharacterModel::find($charId);
        if (!$char) return;
        
        // 复制天赋（还原原始 set("str/int/con/dex", hp_status[...])）
        $str = AttributeHelper::queryStr($char);
        $int = AttributeHelper::queryInt($char);
        $con = AttributeHelper::queryCon($char);
        $dex = AttributeHelper::queryDex($char);
        
        // 复制气血/精/神（还原原始第92-100行）
        $maxKee = intval($char['max_kee']);
        $kee = intval($char['kee']);
        $maxGin = intval($char['max_gin']);
        $gin = intval($char['gin']);
        $maxSen = intval($char['max_sen']);
        $sen = intval($char['sen']);
        
        // 复制内力（还原原始第101-103行）
        $maxForce = intval($char['max_force']);
        $force = intval($char['force']);
        $enforce = intval($char['force_factor'] ?? 0);
        
        // 复制战斗经验（还原原始第104行）
        $combatExp = intval($char['combat_exp']);
        
        // 更新NPC数据库记录（force 是MySQL保留字，必须加反引号）
        Database::execute(
            "UPDATE npcs SET 
                str = ?, `int` = ?, con = ?, dex = ?,
                max_kee = ?, kee = ?, max_gin = ?, gin = ?, 
                max_sen = ?, sen = ?, max_force = ?, `force` = ?, force_factor = ?,
                combat_exp = ?
             WHERE id = ?",
            [$str, $int, $con, $dex,
             $maxKee, $kee, $maxGin, $gin,
             $maxSen, $sen, $maxForce, $force, $enforce,
             $combatExp, $npcDbId]
        );
        
        // 复制技能（还原原始第66-76行：delete_skill → set_skill循环）
        self::mirrorSkills($charId, $npcDbId);
        
        // 复制技能映射（还原原始第78-83行：map_skill循环）
        self::mirrorSkillMaps($charId, $npcDbId);
    }
    
    /**
     * 复制玩家技能到NPC技能表
     * 还原原始：me->delete_skill("unarmed") + for循环 set_skill
     */
    private static function mirrorSkills(int $charId, int $npcDbId): void {
        // 先清除木人原有技能（还原原始 me->delete_skill）
        Database::execute("DELETE FROM npc_skills WHERE npc_id = ?", [$npcDbId]);
        
        // 获取玩家所有技能
        $playerSkills = SkillManager::getAllSkills($charId);
        
        // 复制每个技能（还原原始 for(i=0; i<sizeof(skill_status); i++) set_skill）
        foreach ($playerSkills as $skill) {
            $skillId = $skill['skill_id'] ?? '';
            $level = intval($skill['level'] ?? 0);
            if ($level > 0 && !empty($skillId)) {
                Database::execute(
                    "INSERT INTO npc_skills (npc_id, skill_name, skill_level) VALUES (?, ?, ?)",
                    [$npcDbId, $skillId, $level]
                );
            }
        }
    }
    
    /**
     * 复制玩家技能映射到NPC技能映射表
     * 还原原始：me->map_skill("unarmed/dodge/parry") 清除 + for循环 map_skill
     */
    private static function mirrorSkillMaps(int $charId, int $npcDbId): void {
        // 先清除木人原有映射（还原原始 me->map_skill("unarmed") 清除映射）
        Database::execute("DELETE FROM npc_skill_maps WHERE npc_id = ?", [$npcDbId]);
        
        // 获取玩家技能映射并复制
        $skillTypes = ['unarmed', 'dodge', 'parry', 'force'];
        foreach ($skillTypes as $type) {
            $mapped = SkillManager::querySkillMapped($charId, $type);
            if ($mapped) {
                Database::execute(
                    "INSERT INTO npc_skill_maps (npc_id, base_skill, mapped_skill) VALUES (?, ?, ?)",
                    [$npcDbId, $type, $mapped]
                );
            }
        }
    }
    
    /**
     * 战斗结束后恢复木人默认属性
     * 还原原始 muren.c create() 中的初始值
     */
    public static function restoreDefaults(int $npcDbId): void {
        // 恢复NPC数据库记录为默认值
        // 还原原始：str=25, cor=25, cps=25, int=25, max_kee=300, max_gin=100, max_force=300, jiali=10, combat_exp=50000
        Database::execute(
            "UPDATE npcs SET 
                str = 25, `int` = 25, con = 25, dex = 25, cor = 25, cps = 25, per = 25,
                max_kee = 300, kee = 300, max_gin = 100, gin = 100, 
                max_sen = 100, sen = 100, max_force = 300, `force` = 300, force_factor = 0,
                combat_exp = 50000
             WHERE id = ?",
            [$npcDbId]
        );
        
        // 清除木人的技能和映射
        Database::execute("DELETE FROM npc_skills WHERE npc_id = ?", [$npcDbId]);
        Database::execute("DELETE FROM npc_skill_maps WHERE npc_id = ?", [$npcDbId]);
        
        // 添加默认技能（还原原始 set_skill("force/unarmed/dodge/parry", 30)）
        $defaultSkills = ['force', 'unarmed', 'dodge', 'parry'];
        foreach ($defaultSkills as $skill) {
            Database::execute(
                "INSERT INTO npc_skills (npc_id, skill_name, skill_level) VALUES (?, ?, 30)",
                [$npcDbId, $skill]
            );
        }
    }
    
    /**
     * 获取木人状态
     */
    private static function getStatus(int $npcDbId): ?array {
        return Database::queryOne(
            "SELECT fight_times, damaged, last_fighter_id, last_fight_time FROM muren_status WHERE npc_id = ?",
            [$npcDbId]
        );
    }
    
    /**
     * 更新战斗状态（记录 last_fighter 和增加 fight_times）
     * 还原原始：me->set("last_fighter", ob->query("id")) + me->add("fight_times", 1)
     */
    private static function updateFightStatus(int $npcDbId, int $charId): void {
        $existing = self::getStatus($npcDbId);
        $now = time();
        
        if ($existing) {
            Database::execute(
                "UPDATE muren_status SET fight_times = fight_times + 1, last_fighter_id = ?, last_fight_time = ? WHERE npc_id = ?",
                [$charId, $now, $npcDbId]
            );
        } else {
            Database::execute(
                "INSERT INTO muren_status (npc_id, fight_times, damaged, last_fighter_id, last_fight_time) VALUES (?, 1, 0, ?, ?)",
                [$npcDbId, $charId, $now]
            );
        }
    }
    
    /**
     * 设置木人为已损坏
     */
    private static function setDamaged(int $npcDbId): void {
        Database::execute(
            "UPDATE muren_status SET damaged = 1 WHERE npc_id = ?",
            [$npcDbId]
        );
    }
    
    /**
     * 清除last_fighter（冷却到期后）
     * 还原原始 renewing()：me->delete("last_fighter")
     */
    private static function clearLastFighter(int $npcDbId): void {
        Database::execute(
            "UPDATE muren_status SET last_fighter_id = NULL, last_fight_time = 0 WHERE npc_id = ?",
            [$npcDbId]
        );
    }
}
