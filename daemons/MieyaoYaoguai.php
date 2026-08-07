<?php
/**
 * 灭妖任务妖怪生成系统
 * 完整还原原始项目 xyj2000/ 的 yaoguai.c 逻辑：
 * - dirs 分层投放 (dirs1/dirs2/dirs3)
 * - no_mieyao 过滤
 * - 门派技能复制 (9种门派妖怪)
 * - 妖怪法术/绝招/内功
 * - 装备掉落
 */

class MieyaoYaoguai {

    // ============================================================
    //  dirs 分层投放（还原原始项目 yaoguai.c）
    // ============================================================

    /** dirs1: 初级区域 */
    const DIRS1 = [
        '/d/city', '/d/westway', '/d/kaifeng', '/d/lingtai',
        '/d/moon', '/d/gao', '/d/sea', '/d/nanhai', '/d/eastway',
        '/d/ourhome/honglou',
    ];

    /** dirs2: 中级区域 */
    const DIRS2 = [
        '/d/xueshan', '/d/qujing/wuzhuang', '/d/qujing/baotou',
        '/d/qujing/baoxiang', '/d/qujing/bibotan', '/d/qujing/biqiu',
        '/d/qujing/chechi', '/d/qujing/dudi', '/d/qujing/fengxian',
        '/d/qujing/firemount', '/d/qujing/jilei', '/d/qujing/jindou',
        '/d/qujing/jingjiling', '/d/qujing/jinping', '/d/qujing/jisaiguo',
        '/d/qujing/maoying', '/d/qujing/nuerguo', '/d/qujing/pingding',
        '/d/qujing/pansi', '/d/qujing/tongtian', '/d/qujing/qilin',
        '/d/qujing/qinfa', '/d/qujing/qinglong', '/d/qujing/tianzhu',
        '/d/qujing/wudidong', '/d/qujing/wuji', '/d/qujing/xiaoxitian',
        '/d/qujing/yinwu', '/d/qujing/yuhua', '/d/qujing/zhujie',
        '/d/qujing/zhuzi', '/d/penglai',
    ];

    /** dirs3: 高级区域 */
    const DIRS3 = [
        '/d/death', '/d/meishan', '/d/qujing/lingshan',
    ];

    /** no_mieyao 标记房间（还原原始项目的过滤机制） */
    const NO_MIEYAO_ROOMS = [
        'kaifeng/yuwang6',     // 禹王林死亡层
        'kaifeng/milin',        // 禹王林浓雾迷宫
        'kaifeng/maze',         // 三维迷宫基类
        'kaifeng/ground',       // 水陆大会赛场
        'wudidong/mishi',       // 无底洞暗室
        'wudidong/gongshi',     // 无底洞供室
        'wudidong/cave2',       // 无底洞绝崖
        'nuerguo/greenyard',    // 女儿国绿迷宫
    ];

    // ============================================================
    //  9种门派妖怪技能/法术定义（还原原始项目 yg-*.c）
    // ============================================================

    /** 妖怪门派类型列表 */
    const MONSTER_TYPES = [
        'dragon',   // 龙宫
        'fangcun',  // 方寸山
        'hell',     // 阎罗地府
        'jjf',      // 将军府
        'moon',     // 月宫
        'putuo',    // 普陀山
        'wudidong', // 无底洞
        'wzg',      // 五庄观
        'xueshan',  // 大雪山
    ];

    /** 各门派妖怪技能配置（还原 yg-*.c 的 set_skills） */
    const MONSTER_SKILLS = [
        'dragon' => [
            'basic'     => ['unarmed', 'dodge', 'parry', 'force', 'spells', 'fork', 'hammer'],
            'mappings'  => [
                'force' => 'dragonforce', 'spells' => 'seashentong',
                'unarmed' => 'dragonfight', 'dodge' => 'dragonstep',
                'parry' => 'huntian-hammer', 'fork' => 'fengbo-cha',
                'hammer' => 'huntian-hammer',
            ],
            'specials'  => ['fengbo-cha', 'huntian-hammer', 'dragonstep', 'dragonfight', 'dragonforce', 'seashentong'],
            'weapons'   => [['hammer', 'jingua'], ['fork', 'gangcha']],
            'cast_spells' => ['freez', 'breathe'],
            'exert_funcs' => ['roar', 'shield'],
            'perform_actions' => [],
            'title' => '龙宫水怪',
        ],
        'fangcun' => [
            'basic'     => ['unarmed', 'dodge', 'parry', 'force', 'spells', 'stick'],
            'mappings'  => [
                'force' => 'wuxiangforce', 'spells' => 'dao',
                'unarmed' => 'puti-zhi', 'dodge' => 'jindouyun',
                'parry' => 'qianjun-bang', 'stick' => 'qianjun-bang',
            ],
            'specials'  => ['qianjun-bang', 'jindouyun', 'wuxiangforce', 'puti-zhi', 'dao'],
            'weapons'   => [['stick', 'xiangmo'], ['stick', 'qimeigun']],
            'cast_spells' => ['thunder', 'light', 'dingshen'],
            'exert_funcs' => [],
            'perform_actions' => [['stick', 'pili'], ['stick', 'qiankun']],
            'title' => '方寸妖道',
        ],
        'hell' => [
            'basic'     => ['unarmed', 'dodge', 'parry', 'force', 'spells', 'whip', 'stick'],
            'mappings'  => [
                'force' => 'tonsillit', 'spells' => 'gouhunshu',
                'unarmed' => 'jinghun-zhang', 'dodge' => 'ghost-steps',
                'parry' => 'kusang-bang', 'whip' => 'hellfire-whip',
                'stick' => 'kusang-bang',
            ],
            'specials'  => ['hellfire-whip', 'kusang-bang', 'ghost-steps', 'jinghun-zhang', 'tonsillit', 'gouhunshu'],
            'weapons'   => [['whip', 'tielian'], ['stick', 'xiangmo']],
            'cast_spells' => ['gouhun'],
            'exert_funcs' => ['sheqi'],
            'perform_actions' => [['whip', 'three'], ['whip', 'lunhui']],
            'title' => '幽冥恶鬼',
        ],
        'jjf' => [
            'basic'     => ['unarmed', 'dodge', 'parry', 'force', 'spells', 'spear', 'axe'],
            'mappings'  => [
                'force' => 'lengquan-force', 'spells' => 'baguazhou',
                'unarmed' => 'changquan', 'dodge' => 'yanxing-steps',
                'parry' => 'bawang-qiang', 'spear' => 'bawang-qiang',
                'axe' => 'sanban-axe',
            ],
            'specials'  => ['bawang-qiang', 'sanban-axe', 'yanxing-steps', 'changquan', 'lengquan-force', 'baguazhou'],
            'weapons'   => [['axe', 'huafu'], ['spear', 'changqiang']],
            'cast_spells' => [],
            'exert_funcs' => ['jingxin'],
            'perform_actions' => [['axe', 'sanban']],
            'title' => '将军府叛将',
        ],
        'moon' => [
            'basic'     => ['unarmed', 'dodge', 'parry', 'force', 'spells', 'whip', 'sword'],
            'mappings'  => [
                'force' => 'moonforce', 'spells' => 'moonshentong',
                'unarmed' => 'baihua-zhang', 'dodge' => 'moondance',
                'parry' => 'jueqingbian', 'whip' => 'jueqingbian',
                'sword' => 'snowsword',
            ],
            'specials'  => ['jueqingbian', 'snowsword', 'moondance', 'moonshentong', 'baihua-zhang', 'moonforce'],
            'weapons'   => [['whip', 'dragonwhip'], ['sword', 'qingfeng']],
            'cast_spells' => ['arrow'],
            'exert_funcs' => [],
            'perform_actions' => [],
            'title' => '月宫魔女',
        ],
        'putuo' => [
            'basic'     => ['unarmed', 'dodge', 'parry', 'force', 'spells', 'staff'],
            'mappings'  => [
                'force' => 'lotusforce', 'spells' => 'buddhism',
                'unarmed' => 'jienan-zhi', 'dodge' => 'lotusmove',
                'parry' => 'lunhui-zhang', 'staff' => 'lunhui-zhang',
            ],
            'specials'  => ['lunhui-zhang', 'lotusmove', 'buddhism', 'jienan-zhi', 'lotusforce'],
            'weapons'   => [['staff', 'budd_staff'], ['staff', 'tiezhang']],
            'cast_spells' => ['bighammer'],
            'exert_funcs' => [],
            'perform_actions' => [],
            'title' => '普陀恶僧',
        ],
        'wudidong' => [
            'basic'     => ['unarmed', 'dodge', 'parry', 'force', 'spells', 'blade', 'sword'],
            'mappings'  => [
                'force' => 'huntian-qigong', 'spells' => 'yaofa',
                'unarmed' => 'yinfeng-zhua', 'dodge' => 'lingfu-steps',
                'parry' => 'kugu-blade', 'blade' => 'kugu-blade',
                'sword' => 'qixiu-jian',
            ],
            'specials'  => ['kugu-blade', 'qixiu-jian', 'lingfu-steps', 'yaofa', 'huntian-qigong', 'yinfeng-zhua'],
            'weapons'   => [['sword', 'qingfeng'], ['blade', 'jindao']],
            'cast_spells' => ['huanying', 'suliao'],
            'exert_funcs' => [],
            'perform_actions' => [],
            'title' => '无底洞妖孽',
        ],
        'wzg' => [
            'basic'     => ['unarmed', 'dodge', 'parry', 'force', 'spells', 'hammer', 'sword'],
            'mappings'  => [
                'force' => 'zhenyuan-force', 'spells' => 'taiyi',
                'unarmed' => 'wuxing-quan', 'dodge' => 'baguazhen',
                'parry' => 'kaishan-chui', 'hammer' => 'kaishan-chui',
                'sword' => 'sanqing-jian',
            ],
            'specials'  => ['kaishan-chui', 'sanqing-jian', 'baguazhen', 'taiyi', 'wuxing-quan', 'zhenyuan-force'],
            'weapons'   => [['hammer', 'jingua'], ['sword', 'qingfeng']],
            'cast_spells' => ['zhenhuo', 'baxian'],
            'exert_funcs' => [],
            'perform_actions' => [],
            'title' => '五庄观凶徒',
        ],
        'xueshan' => [
            'basic'     => ['unarmed', 'dodge', 'parry', 'force', 'spells', 'blade', 'sword'],
            'mappings'  => [
                'force' => 'ningxie-force', 'spells' => 'dengxian-dafa',
                'unarmed' => 'cuixin-zhang', 'dodge' => 'xiaoyaoyou',
                'parry' => 'bingpo-blade', 'blade' => 'bingpo-blade',
                'sword' => 'bainiao-jian',
            ],
            'specials'  => ['xiaoyaoyou', 'dengxian-dafa', 'ningxie-force', 'cuixin-zhang', 'bingpo-blade', 'bainiao-jian'],
            'weapons'   => [['sword', 'qingfeng'], ['blade', 'jindao']],
            'cast_spells' => ['juanbi', 'tuntian'],
            'exert_funcs' => [],
            'perform_actions' => [],
            'title' => '雪山老妖',
        ],
    ];

    /**
     * 生成妖怪名称
     */
    public static function generateName() {
        $names = Database::queryAll(
            "SELECT name, pinyin FROM yaoguai_names ORDER BY RAND() LIMIT 1"
        );
        
        if (empty($names)) {
            return [
                'name' => '妖怪',
                'id' => 'yaoguai',
                'title' => self::calculateTitle()
            ];
        }
        
        $namePair = $names[0];
        $j = rand(0, 1);
        
        $name = $namePair['name'] . ($j ? '精' : '怪');
        $id = $namePair['pinyin'] . ' ' . ($j ? 'jing' : 'guai');
        
        return [
            'name' => $name,
            'id' => $id,
            'title' => self::calculateTitle()
        ];
    }

    /**
     * 计算妖怪称号
     */
    private static function calculateTitle() {
        $titles = Database::queryAll(
            "SELECT title FROM yaoguai_titles ORDER BY RAND() LIMIT 1"
        );
        
        if (empty($titles)) {
            return '小精怪';
        }
        
        return $titles[0]['title'];
    }

    /**
     * 根据玩家经验选择 dirs 分层
     * 还原原始项目 yaoguai.c::_invocation() 逻辑
     */
    private static function selectDirs($player, bool $isHeaven = false): array {
        $dx = intval($player['daoxing'] ?? 0);
        $exp = intval($player['combat_exp'] ?? 0);
        $total = ($dx + $exp) / 2;

        // 天廷入口（李靖）：始终使用完整 dirs
        // 人间入口（袁天罡）：仅使用 dirs1
        if (!$isHeaven) {
            return self::DIRS1; // 人间版始终只使用初级区域
        }

        if ($total < 30000) {
            return self::DIRS1;
        } elseif ($total < 3000000) {
            return array_merge(self::DIRS1, self::DIRS2);
        } else {
            return array_merge(self::DIRS1, self::DIRS2, self::DIRS3);
        }
    }

    /**
     * 检查房间是否允许灭妖（no_mieyao 过滤）
     * 还原原始项目 random_place() 过滤逻辑
     */
    private static function isRoomAllowed(array $room): bool {
        if (empty($room)) return false;

        $area = $room['area'] ?? '';
        $roomId = $room['room_id'] ?? '';

        // 检查 no_mieyao 标记
        $roomKey = $area . '/' . $roomId;
        foreach (self::NO_MIEYAO_ROOMS as $forbidden) {
            if (strpos($roomKey, $forbidden) !== false) {
                return false;
            }
        }

        // 检查 no_fight 标记
        if (!empty($room['no_fight']) && $room['no_fight']) {
            return false;
        }

        // 检查 no_magic 标记
        if (!empty($room['no_magic']) && $room['no_magic']) {
            return false;
        }

        // 检查 outdoors 字段（有 outdoors 表示可通行）
        // 注意：rooms 表中没有 exits 字段，用 outdoors 替代判断
        $outdoors = $room['outdoors'] ?? '';
        if (empty($outdoors)) {
            return false;
        }

        return true;
    }

    /**
     * 选择随机地点放置妖怪（增强版：dirs 分层 + no_mieyao 过滤）
     * 还原原始项目 random_place() 完整逻辑
     */
    public static function chooseRandomPlace($player, bool $isHeaven = false) {
        $dirs = self::selectDirs($player, $isHeaven);

        if (empty($dirs)) {
            return null;
        }

        // 最多尝试30次（还原原始项目的 for(k=0; k<30; k++) 逻辑）
        for ($attempt = 0; $attempt < 30; $attempt++) {
            // 随机选一个目录
            $dir = $dirs[array_rand($dirs)];
            $area = str_replace('/d/', '', $dir);

            // 从数据库获取该区域的房间列表
            $rooms = Database::queryAll(
                "SELECT * FROM rooms WHERE area = ? ORDER BY RAND() LIMIT 10",
                [$area]
            );

            if (empty($rooms)) continue;

            // 随机选一个房间并检查
            $room = $rooms[array_rand($rooms)];
            if (self::isRoomAllowed($room)) {
                return $room;
            }
        }

        // 30次都没找到合适的房间，降级到长安城
        $rooms = Database::queryAll(
            "SELECT * FROM rooms WHERE area = 'city' ORDER BY RAND() LIMIT 10"
        );
        foreach ($rooms as $room) {
            if (self::isRoomAllowed($room)) {
                return $room;
            }
        }

        return null;
    }

    /**
     * 计算妖怪属性（基于玩家属性）
     * 增强版：添加门派技能/法术/装备
     */
    public static function calculateAttributes($player, $level = 0, bool $isHeaven = false) {
        $base = 20;
        $lvl = $level + $base - 2;

        // 获取玩家的技能等级
        $playerSkills = Database::queryAll(
            "SELECT level FROM character_skills WHERE char_id = ?",
            [$player['id']]
        );

        $maxLevel = 1;
        foreach ($playerSkills as $skill) {
            if ($skill['level'] > $maxLevel) {
                $maxLevel = $skill['level'];
            }
        }
        
        $monsterLevel = intval($maxLevel * $lvl / $base);
        if ($monsterLevel < 1) $monsterLevel = 1;

        $nameData = self::generateName();
        $room = self::chooseRandomPlace($player, $isHeaven);

        // 随机选择妖怪门派类型
        $monsterTypeKey = self::MONSTER_TYPES[array_rand(self::MONSTER_TYPES)];
        $skillConfig = self::MONSTER_SKILLS[$monsterTypeKey] ?? self::MONSTER_SKILLS['dragon'];

        // 计算奖励
        $expPlayer = (intval($player['daoxing'] ?? 0) + intval($player['combat_exp'] ?? 0)) / 2;
        if ($expPlayer < 30000) {
            $expReward = 500 + intval($expPlayer / 60);
            $potReward = 200 + intval($expPlayer / 300);
            $daoxingReward = 300 + intval($expPlayer / 100);
        } else if ($expPlayer < 300000) {
            $expReward = 1000 + intval($expPlayer / 600);
            $potReward = 300 + intval($expPlayer / 6000);
            $daoxingReward = 600 + intval($expPlayer / 1000);
        } else if ($expPlayer < 3000000) {
            $expReward = 1500 + intval($expPlayer / 6000);
            $potReward = 350 + intval($expPlayer / 20000);
            $daoxingReward = 1000 + intval($expPlayer / 5000);
        } else {
            $expReward = 2000;
            $potReward = 500;
            $daoxingReward = 1500;
        }

        $expReward = intval($expReward * ($level + 1) / 10);
        $potReward = intval($potReward * ($level + 1) / 10);
        $daoxingReward = intval($daoxingReward * ($level + 1) / 10);

        // 天廷版：妖怪属性更强（还原原始 dntg vs city 差异）
        $attrMultiplier = $isHeaven ? 1.0 : 0.7;
        $skillBonus = $isHeaven ? 15 : 2; // 天廷版技能+random(15)，人间版+random(2)

        // 计算具体技能等级
        $skills = [];
        $j = $monsterLevel + 18; // 基础技能等级 = monsterLevel + 18（还原 yaoguai.c copy_status）
        foreach ($skillConfig['basic'] as $basicSkill) {
            $skills[$basicSkill] = $j;
        }
        foreach ($skillConfig['specials'] as $specialSkill) {
            $skills[$specialSkill] = $j + mt_rand(0, $skillBonus);
        }

        // 随机选择武器
        $weapons = $skillConfig['weapons'];
        $chosenWeapon = $weapons[array_rand($weapons)];

        // 计算 cast_chance（天廷版使用 cast_chance(level)）
        $castChance = $isHeaven ? (5 + intval($level / 2)) : (10 + 2 * $level);

        return [
            'name' => $nameData['name'],
            'id' => $nameData['id'],
            'title' => $skillConfig['title'] ?? $nameData['title'],
            'monster_level' => $monsterLevel,
            'daoxing' => intval((intval($player['daoxing'] ?? 1000) * $attrMultiplier) * ($lvl / $base)),
            'combat_exp' => intval((intval($player['combat_exp'] ?? 1000) * $attrMultiplier) * ($lvl / $base)),
            'max_kee' => intval((intval($player['max_kee'] ?? 100) * $attrMultiplier) * ($lvl / $base)),
            'max_sen' => intval((intval($player['max_sen'] ?? 100) * $attrMultiplier) * ($lvl / $base)),
            'exp_reward' => $expReward,
            'pot_reward' => $potReward,
            'daoxing_reward' => $daoxingReward,
            'room' => $room,
            'type' => self::chooseMonsterType(),
            'face' => self::generateFace(),
            // === 新增：门派技能/法术/装备 ===
            'monster_type_key' => $monsterTypeKey,
            'skills' => $skills,                  // 技能列表 [skill_name => level]
            'skill_mappings' => $skillConfig['mappings'], // 技能映射
            'weapon_type' => $chosenWeapon[0],     // 武器类型
            'weapon_id' => $chosenWeapon[1],       // 武器ID
            'cast_spells' => $skillConfig['cast_spells'],   // 法术列表
            'exert_funcs' => $skillConfig['exert_funcs'],   // 内功列表
            'perform_actions' => $skillConfig['perform_actions'], // 绝招列表
            'cast_chance' => $castChance,          // 施法概率
            'is_heaven' => $isHeaven,              // 是否天廷版
        ];
    }
    
    /**
     * 生成随机容貌
     */
    private static function generateFace() {
        $locations = [
            '满山跑的',
            '洞里蹲的',
            '野地里的',
            '草窠里的',
            '树上爬的',
            '路边走的'
        ];
        
        $actions = [
            '专干吃人的勾当',
            '样子挺吓人的',
            '经常会咬人'
        ];
        
        $appearances = [
            '青面獠牙',
            '三头六臂',
            '头生双角',
            '红发红眼',
            '虎头虎脑',
            '蛇首人身',
            '牛首人身',
            '马首人身',
            '面目狰狞',
            '尖嘴猴腮',
            '肥头大耳',
            '瘦小枯干',
            '满身红毛',
            '蓝脸獠牙',
            '血盆大口',
            '铜铃大眼',
            '一脸横肉',
            '面色煞白',
            '面有三目',
            '面似黑炭',
            '狮首人身',
            '豹首人身',
            '浑身绿毛',
            '面如冠玉',
            '面似锅底'
        ];
        
        $location = $locations[array_rand($locations)];
        $action = $actions[array_rand($actions)];
        $appearance = $appearances[array_rand($appearances)];
        
        return $location . $appearance . '，' . $action;
    }

    /**
     * 选择妖怪类型
     * 原始项目概率分布：
     * - aggressive: 1% (i < 10)
     * - blocker: 1% (i < 20)
     * - aggressive_on_owner: 20% (i < 220)
     * - aggressive_on_owner1: 10% (i < 320)
     * - normal: 68% (其余)
     */
    private static function chooseMonsterType() {
        $i = rand(0, 999);
        if ($i < 10) {
            return 'aggressive';  // 主动攻击所有玩家
        } else if ($i < 20) {
            return 'blocker';    // 阻挡移动，需等待
        } else if ($i < 220) {
            return 'aggressive_on_owner';  // 只攻击任务主人
        } else if ($i < 320) {
            return 'aggressive_on_owner1'; // 只攻击主人，有概率逃跑
        } else {
            return 'normal';      // 正常妖怪
        }
    }

    /**
     * 获取所有妖怪名称（用于管理）
     */
    public static function getAllNames(): array {
        return Database::queryAll("SELECT id, name, pinyin FROM yaoguai_names ORDER BY name");
    }

    /**
     * 获取所有妖怪称号（用于管理）
     */
    public static function getAllTitles(): array {
        return Database::queryAll("SELECT id, title FROM yaoguai_titles ORDER BY id");
    }

    /**
     * 获取所有妖怪区域（用于管理）
     */
    public static function getAllAreas(): array {
        return Database::queryAll("SELECT id, area, area_name, group_name, min_exp FROM yaoguai_areas ORDER BY group_name, area");
    }
}

