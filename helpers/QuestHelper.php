<?php
/**
 * 任务系统核心辅助类
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 *
 * 支持的任务类型：
 * - kill: 杀怪任务
 * - give: 给予任务
 * - ask: 询问任务
 * - find: 查找任务
 * - weapon: 武器任务
 * - armor: 盔甲任务
 * - cloth: 衣服任务
 * - food: 食物任务
 * - wearing: 穿戴任务
 * - misc: 杂项任务
 *
 * 品德值系统：
 * - 完成开封解谜任务可获得品德值
 * - 品德值存储在 characters 表的 moral_value 字段
 * - 赴京请赏时消耗品德值兑换奖励
 */
class QuestHelper {
    
    /**
     * 任务类型常量
     */
    const TYPE_KILL = 'kill';
    const TYPE_GIVE = 'give';
    const TYPE_ASK = 'ask';
    const TYPE_FIND = 'find';
    const TYPE_WEAPON = 'weapon';
    const TYPE_ARMOR = 'armor';
    const TYPE_CLOTH = 'cloth';
    const TYPE_FOOD = 'food';
    const TYPE_WEARING = 'wearing';
    const TYPE_MISC = 'misc';
    
    /**
     * 颜色祥云配置（9色，对应原始LPC color_code mapping）
     */
    private static array $colorClouds = [
        'red' => ['name' => '红色', 'code' => '#ff0000'],
        'green' => ['name' => '绿色', 'code' => '#00ff00'],
        'yellow' => ['name' => '黄色', 'code' => '#ffff00'],
        'blue' => ['name' => '蓝色', 'code' => '#0000ff'],
        'purple' => ['name' => '紫色', 'code' => '#800080'],
        'cyan' => ['name' => '青色', 'code' => '#00ffff'],
        'white' => ['name' => '白色', 'code' => '#ffffff'],
        'pink' => ['name' => '粉色', 'code' => '#ff69b4'],
        'orange' => ['name' => '橙色', 'code' => '#ff8c00'],
    ];
    
    /**
     * kill任务通用ID映射表（从原始LPC quest_kl.c提取）
     * 这些通用ID在运行时需要动态解析为具体的NPC
     */
    private static array $killGenericIds = [
        'yaoguai' => [
            ['name' => '鹿头怪', 'npc_ids' => ['lutuoguai', 'yaoguai']],
            ['name' => '虎头怪', 'npc_ids' => ['hutouguai', 'yaoguai']],
            ['name' => '狮头怪', 'npc_ids' => ['shitouguai', 'yaoguai']],
            ['name' => '象头怪', 'npc_ids' => ['xiangtouguai', 'yaoguai']],
            ['name' => '熊头怪', 'npc_ids' => ['xiongtouguai', 'bearmonster', 'yaoguai']],
            ['name' => '妖怪', 'npc_ids' => ['yaoguai']],
            ['name' => '狮子', 'npc_ids' => ['shizi', 'lion', 'yaoguai']],
            ['name' => '豹', 'npc_ids' => ['bao', 'leopard', 'yaoguai']],
            ['name' => '虎', 'npc_ids' => ['hu', 'tiger', 'yaoguai']],
            ['name' => '狼', 'npc_ids' => ['lang', 'wolf', 'yaoguai']],
            ['name' => '狐', 'npc_ids' => ['hu', 'fox', 'yaoguai']],
            ['name' => '猫', 'npc_ids' => ['mao', 'cat', 'yaoguai']],
            ['name' => '猴', 'npc_ids' => ['hou', 'monkey', 'yaoguai']],
            ['name' => '龟', 'npc_ids' => ['gui', 'turtle', 'yaoguai']],
            ['name' => '鹰', 'npc_ids' => ['ying', 'eagle', 'yaoguai']],
            ['name' => '蛇', 'npc_ids' => ['she', 'snake', 'yaoguai']],
            ['name' => '蜘蛛', 'npc_ids' => ['zhizhu', 'spider', 'yaoguai']],
            ['name' => '蜈蚣', 'npc_ids' => ['wugong', 'centipede', 'yaoguai']],
            ['name' => '蝎子', 'npc_ids' => ['xiezi', 'scorpion', 'yaoguai']],
            ['name' => '蝙蝠', 'npc_ids' => ['bianfu', 'bat', 'yaoguai']],
            ['name' => '山魈', 'npc_ids' => ['shanxiao', 'yaoguai']],
            ['name' => '山精', 'npc_ids' => ['shanjing', 'yaoguai']],
            ['name' => '树精', 'npc_ids' => ['shujing', 'yaoguai']],
            ['name' => '花妖', 'npc_ids' => ['huayao', 'yaoguai']],
            ['name' => '草妖', 'npc_ids' => ['caoyao', 'yaoguai']],
            ['name' => '石怪', 'npc_ids' => ['shiguai', 'yaoguai']],
            ['name' => '水怪', 'npc_ids' => ['shuiguai', 'yaoguai']],
            ['name' => '火怪', 'npc_ids' => ['huoguai', 'yaoguai']],
            ['name' => '风怪', 'npc_ids' => ['fengguai', 'yaoguai']],
            ['name' => '雷怪', 'npc_ids' => ['leiguai', 'yaoguai']],
        ],
        'yaojing' => [
            ['name' => '妖精', 'npc_ids' => ['yaojing', 'yaoguai']],
            ['name' => '狐狸精', 'npc_ids' => ['huli', 'fox', 'yaojing']],
            ['name' => '蛇精', 'npc_ids' => ['shejing', 'snake', 'yaojing']],
            ['name' => '树精', 'npc_ids' => ['shujing', 'yaojing']],
            ['name' => '鱼精', 'npc_ids' => ['yujing', 'fish', 'yaojing']],
            ['name' => '蚌精', 'npc_ids' => ['bangjing', 'yaojing']],
            ['name' => '蜘蛛精', 'npc_ids' => ['zhizhujing', 'spider', 'yaojing']],
            ['name' => '蝎子精', 'npc_ids' => ['xiezijing', 'scorpion', 'yaojing']],
            ['name' => '蜈蚣精', 'npc_ids' => ['wugongjing', 'centipede', 'yaojing']],
            ['name' => '蝙蝠精', 'npc_ids' => ['bianfujing', 'bat', 'yaojing']],
            ['name' => '蛤蟆精', 'npc_ids' => ['hamajing', 'frog', 'yaojing']],
            ['name' => '老鼠精', 'npc_ids' => ['laoshujing', 'rat', 'yaojing']],
            ['name' => '兔子精', 'npc_ids' => ['tuzijing', 'rabbit', 'yaojing']],
            ['name' => '山羊精', 'npc_ids' => ['shanyangjing', 'goat', 'yaojing']],
            ['name' => '黄牛精', 'npc_ids' => ['huangniujing', 'cow', 'yaojing']],
            ['name' => '水牛精', 'npc_ids' => ['shuiniujing', 'buffalo', 'yaojing']],
            ['name' => '黑熊精', 'npc_ids' => ['heixiong', 'bearmonster', 'yaojing']],
            ['name' => '白熊精', 'npc_ids' => ['baixiong', 'bear', 'yaojing']],
            ['name' => '青狮精', 'npc_ids' => ['qingshi', 'lion', 'yaojing']],
            ['name' => '白象精', 'npc_ids' => ['baixiang', 'elephant', 'yaojing']],
        ],
        'shark' => [
            ['name' => '海鲨', 'npc_ids' => ['shark', 'haisha']],
            ['name' => '双髻鲨', 'npc_ids' => ['shuangjisha', 'shark']],
            ['name' => '锤头鲨', 'npc_ids' => ['chuitousha', 'shark']],
            ['name' => '巨齿鲨', 'npc_ids' => ['juchisha', 'shark']],
        ],
        'dragon' => [
            ['name' => '龙', 'npc_ids' => ['dragon', 'long']],
            ['name' => '金龙', 'npc_ids' => ['goldendragon', 'jinlong']],
            ['name' => '青龙', 'npc_ids' => ['qinglong', 'greendragon']],
            ['name' => '白龙', 'npc_ids' => ['bailong', 'whitedragon']],
            ['name' => '黑龙', 'npc_ids' => ['heilong', 'blackdragon']],
            ['name' => '赤龙', 'npc_ids' => ['chilong', 'reddragon']],
            ['name' => '苍龙', 'npc_ids' => ['canglong', 'dragon']],
        ],
        'wolf' => [
            ['name' => '小狼', 'npc_ids' => ['wolf', 'xiaolang']],
            ['name' => '恶狼', 'npc_ids' => ['elang', 'wolf']],
            ['name' => '灰狼', 'npc_ids' => ['huilang', 'wolf']],
            ['name' => '狼群', 'npc_ids' => ['langqun', 'wolf']],
        ],
        'shanjing' => [
            ['name' => '山精', 'npc_ids' => ['shanjing', 'yaoguai']],
            ['name' => '山妖', 'npc_ids' => ['shanyao', 'yaoguai']],
        ],
        'xiaoyaojing' => [
            ['name' => '小妖', 'npc_ids' => ['xiaoyao', 'yaoguai']],
            ['name' => '妖精', 'npc_ids' => ['yaojing', 'yaoguai']],
        ],
    ];

    /**
     * 祥云最大计数（原始LPC: Max_count=10）
     */
    const MAX_COLOR_COUNT = 10;
    
    /**
     * 任务定义配置（优先从数据库读取，配置文件作为备份）
     */
    private static ?array $questDefinitions = null;
    
    /**
     * 开封任务配置缓存
     */
    private static ?array $kaifengConfig = null;
    
    /**
     * 加载开封任务配置（优先从数据库读取，配置文件作为备份）
     */
    private static function loadKaifengConfig(): void {
        if (self::$kaifengConfig === null) {
            self::$kaifengConfig = self::loadConfigFromDatabase();
            
            if (empty(self::$kaifengConfig['quest_pools'])) {
                $configFile = __DIR__ . '/../config/kaifeng_quests.php';
                if (file_exists($configFile)) {
                    self::$kaifengConfig = require $configFile;
                } else {
                    self::$kaifengConfig = [
                        'npc_map' => [],
                        'quest_pools' => [],
                        'cache_size' => 30,
                        'index_delta' => 20,
                    ];
                }
            }
        }
    }
    
    /**
     * 从数据库加载任务配置
     */
    private static function loadConfigFromDatabase(): array {
        $config = [
            'npc_map' => [],
            'quest_pools' => [],
            'cache_size' => 30,
            'index_delta' => 20,
        ];
        
        try {
            $npcs = Database::queryAll("SELECT npc_id, name, quest_type, room, topic, color_code FROM kaifeng_npc_config");
            foreach ($npcs as $npc) {
                $config['npc_map'][$npc['npc_id']] = [
                    'name' => $npc['name'],
                    'quest_type' => $npc['quest_type'],
                    'room' => $npc['room'],
                    'topic' => $npc['topic'],
                    'color_code' => $npc['color_code'],
                ];
            }
            
            $quests = Database::queryAll("SELECT quest_type, daoxing_range, name, target_id, object_name, amount, color_code FROM quest_definitions");
            foreach ($quests as $quest) {
                $type = $quest['quest_type'];
                $daoxing = $quest['daoxing_range'];
                if (!isset($config['quest_pools'][$type])) {
                    $config['quest_pools'][$type] = [];
                }
                $config['quest_pools'][$type][$daoxing] = [
                    'type' => $type,
                    'name' => $quest['name'],
                    'id' => $quest['target_id'],
                    'topic' => $quest['object_name'],
                    'amount' => $quest['amount'] ?? 1,
                ];
            }
        } catch (\Exception $e) {
            error_log("loadConfigFromDatabase error: " . $e->getMessage());
        }
        
        return $config;
    }
    
    /**
     * 获取任务池
     * 
     * @param string $questType 任务类型
     * @return array 任务池（以daoxing为key的关联数组）
     */
    public static function getQuestPool(string $questType): array {
        self::loadKaifengConfig();
        return self::$kaifengConfig['quest_pools'][$questType] ?? [];
    }
    
    /**
     * 获取所有可用任务类型
     * 
     * @return array 任务类型列表
     */
    public static function getAvailableQuestTypes(): array {
        self::loadKaifengConfig();
        return array_keys(self::$kaifengConfig['quest_pools'] ?? []);
    }
    
    /**
     * 获取NPC映射配置
     * 
     * @return array NPC映射
     */
    public static function getNpcMap(): array {
        self::loadKaifengConfig();
        return self::$kaifengConfig['npc_map'] ?? [];
    }
    
    /**
     * 获取配置参数
     * 
     * @param string $key 参数名
     * @param mixed $default 默认值
     * @return mixed 参数值
     */
    public static function getConfigParam(string $key, mixed $default = null): mixed {
        self::loadKaifengConfig();
        return self::$kaifengConfig[$key] ?? $default;
    }
    
    /**
     * 加载任务定义配置（兼容旧接口，内部使用kaifeng_quests.php）
     */
    private static function loadQuestDefinitions(): void {
        if (self::$questDefinitions === null) {
            self::loadKaifengConfig();
            self::$questDefinitions = self::$kaifengConfig['quest_pools'] ?? [];
        }
    }
    
    /**
     * 标准化物品ID（去除空格、下划线、横杠，转换为小写）
     *
     * @param string $itemId 原始物品ID
     * @return string 标准化后的物品ID
     */
    public static function normalizeItemId(string $itemId): string {
        return strtolower(preg_replace('/[\s_\-]/', '', $itemId));
    }
    
    /**
     * 解析kill任务通用ID（从原始LPC quest_kl.c提取的逻辑）
     * 在原始LPC中，yaoguai、yaojing等是通用ID，运行时会动态匹配多个可能的NPC
     * 
     * @param string $genericId 通用ID（如 yaoguai, yaojing）
     * @param string $expectedName 预期的怪物名称（从任务配置中获取）
     * @return array|null 解析后的具体NPC信息 ['name' => string, 'npc_id' => string]
     */
    public static function resolveKillGenericId(string $genericId, string $expectedName = ''): ?array {
        $normalizedId = self::normalizeItemId($genericId);
        
        if (!isset(self::$killGenericIds[$normalizedId])) {
            return null;
        }
        
        $candidates = self::$killGenericIds[$normalizedId];
        
        if (!empty($expectedName)) {
            foreach ($candidates as $candidate) {
                if ($candidate['name'] === $expectedName) {
                    $selected = $candidate;
                    $foundNpcId = self::findExistingNpcId($selected['npc_ids']);
                    if ($foundNpcId) {
                        return ['name' => $selected['name'], 'npc_id' => $foundNpcId];
                    }
                    return ['name' => $selected['name'], 'npc_id' => $selected['npc_ids'][0]];
                }
            }
        }
        
        $randomIndex = rand(0, count($candidates) - 1);
        $selected = $candidates[$randomIndex];
        $foundNpcId = self::findExistingNpcId($selected['npc_ids']);
        
        if ($foundNpcId) {
            return ['name' => $selected['name'], 'npc_id' => $foundNpcId];
        }
        
        return ['name' => $selected['name'], 'npc_id' => $selected['npc_ids'][0]];
    }
    
    /**
     * 在数据库中查找存在的NPC ID
     * 
     * @param array $npcIds 候选NPC ID列表
     * @return string|null 找到的NPC ID，未找到返回null
     */
    private static function findExistingNpcId(array $npcIds): ?string {
        require_once __DIR__ . '/../includes/db.php';
        
        foreach ($npcIds as $npcId) {
            $normalized = strtolower(str_replace([' ', '-', '_'], '', $npcId));
            $result = Database::queryOne("SELECT npc_id FROM npcs WHERE npc_id = ?", [$normalized]);
            if ($result) {
                return $result['npc_id'];
            }
        }
        
        return null;
    }
    
    /**
     * 判断是否为kill任务通用ID
     * 
     * @param string $id 任务ID
     * @return bool 是否为通用ID
     */
    public static function isKillGenericId(string $id): bool {
        $normalizedId = self::normalizeItemId($id);
        return isset(self::$killGenericIds[$normalizedId]);
    }
    
    /**
     * 通过别名查找物品（智能匹配）
     *
     * @param string $alias 物品别名（支持多种格式）
     * @return array|null 物品数据，未找到返回null
     */
    public static function findItemByAlias(string $alias): ?array {
        require_once __DIR__ . '/../includes/db.php';
        
        $alias = trim($alias);
        if (empty($alias)) {
            return null;
        }
        
        $normalizedId = self::normalizeItemId($alias);
        
        $sql = "SELECT i.* FROM items i 
                WHERE i.item_id COLLATE utf8mb4_0900_ai_ci = ? COLLATE utf8mb4_0900_ai_ci 
                UNION
                SELECT i.* FROM items i 
                JOIN item_aliases a ON i.item_id COLLATE utf8mb4_0900_ai_ci = a.primary_item_id COLLATE utf8mb4_0900_ai_ci
                WHERE a.alias COLLATE utf8mb4_0900_ai_ci = ? COLLATE utf8mb4_0900_ai_ci";
        
        $result = Database::queryAll($sql, [$normalizedId, $alias]);
        
        if (!empty($result)) {
            return $result[0];
        }
        
        $variants = self::generateAliasVariants($alias);
        foreach ($variants as $variant) {
            $result = Database::queryAll($sql, [$variant, $variant]);
            if (!empty($result)) {
                return $result[0];
            }
        }
        
        $nameMatch = self::matchItemByName($alias);
        if ($nameMatch) {
            return $nameMatch;
        }
        
        return null;
    }
    
    private static function matchItemByName(string $alias): ?array {
        require_once __DIR__ . '/../includes/db.php';
        
        $pinyinToChinese = [
            'zhurou' => '猪',
            'zhu' => '猪',
            'tanzi' => '坛',
            'jiuping' => '酒',
            'jiu' => '酒',
            'ping' => '瓶',
            'sucaibao' => '素菜',
            'zhuroubao' => '猪肉',
            'haixianbao' => '海鲜',
            'yunnanbaiyao' => '云南白药',
            'mihoutaogan' => '猕猴桃',
            'huashengmi' => '花生',
            'xunlachang' => '腊肠',
            'zuiziji' => '醉鸡',
            'wuhuamenrou' => '五花肉',
            'qingsixunyu' => '熏鱼',
            'tangcupaigu' => '排骨',
            'jingjiangrousi' => '肉丝',
            'youqiangdaxia' => '虾',
            'cuipikaoya' => '烤鸭',
            'hongshaosue' => '红烧',
            'gongbaojiding' => '鸡丁',
            'maladusi' => '肚丝',
            'chair' => '椅',
            'yizi' => '椅',
            'table' => '桌',
            'zhuozi' => '桌',
            'denglong' => '灯笼',
            'deng' => '灯',
            'long' => '龙',
            'cloth' => '布',
            'silk' => '丝',
            'wood' => '木',
            'iron' => '铁',
            'gold' => '金',
            'silver' => '银',
            'stone' => '石',
            'paper' => '纸',
            'bottle' => '瓶',
            'cup' => '杯',
            'bowl' => '碗',
            'plate' => '盘',
            'box' => '盒',
            'bag' => '袋',
            'knife' => '刀',
            'sword' => '剑',
            'axe' => '斧',
            'spear' => '矛',
            'staff' => '杖',
            'hammer' => '锤',
            'fan' => '扇',
            'mirror' => '镜',
            'book' => '书',
            'scroll' => '卷',
            'pill' => '丸',
            'powder' => '粉',
            'wine' => '酒',
            'tea' => '茶',
            'food' => '食',
            'meat' => '肉',
            'fish' => '鱼',
            'egg' => '蛋',
            'rice' => '米',
            'cake' => '糕',
            'soup' => '汤',
            'dumpling' => '饺',
            'noodle' => '面',
            'hongyoufeipian' => '红油',
            'zouyoucuichang' => '肥肠',
            'baochaoyaohua' => '腰花',
            'xipatourou' => '头肉',
            'shexiangdongsun' => '冬笋',
            'feicuidoufu' => '豆腐',
            'shuxianshucai' => '蔬菜',
            'pidanshourouzhou' => '皮蛋',
            'chazhan' => '茶盏',
            'jiandoufu' => '豆腐',
            'shaomianjin' => '面巾',
            'hamigua' => '哈密瓜',
            'caomei' => '草莓',
            'pingguo' => '苹果',
            'putao' => '葡萄',
            'tao' => '桃',
            'wuweishaoe' => '烧鹅',
            'xiangzhizhengya' => '蒸鸭',
            'laweiniutang' => '牛汤',
            'feipian' => '片',
            'rou' => '肉',
            'hua' => '花',
            'dong' => '冬',
            'sun' => '笋',
            'doufu' => '豆腐',
            'shucai' => '菜',
            'zhou' => '粥',
            'zhan' => '盏',
            'mian' => '面',
            'jin' => '巾',
            'gua' => '瓜',
            'mei' => '莓',
            'guo' => '果',
            'e' => '鹅',
            'ya' => '鸭',
            'niu' => '牛',
            'tang' => '汤',
            'xiang' => '香',
            'zhi' => '汁',
            'zheng' => '蒸',
            'bao' => '爆',
            'chao' => '炒',
            'shao' => '烧',
            'jian' => '煎',
            'cu' => '醋',
            'you' => '油',
            'hong' => '红',
            'qing' => '青',
            'bai' => '白',
            'hei' => '黑',
            'huang' => '黄',
            'lv' => '绿',
            'lan' => '蓝',
            'zi' => '紫',
            'fen' => '粉',
            'dan' => '蛋',
            'shou' => '瘦',
            'cuichang' => '肠',
            'rousi' => '丝',
            'gu' => '骨',
            'xia' => '虾',
            'ji' => '鸡',
            'ding' => '丁',
            'tu' => '兔',
            'niu' => '牛',
            'yang' => '羊',
            'gou' => '狗',
            'ma' => '马',
            'liao' => '料',
            'cai' => '菜',
            'xiang' => '香',
            'la' => '辣',
            'tian' => '甜',
            'suan' => '酸',
            'ku' => '苦',
            'xian' => '鲜',
            'dan' => '淡',
            'rong' => '茸',
            'pian' => '片',
            'si' => '丝',
            'kuai' => '块',
            'dou' => '豆',
            'jiang' => '酱',
            'mi' => '蜜',
            'tang' => '糖',
            'sha' => '沙',
            'zhi' => '汁',
            'shui' => '水',
            'fei' => '肥',
            'shou' => '瘦',
            'you' => '油',
            'cu' => '醋',
            'hongyou' => '红油',
            'zouyou' => '走油',
            'baochao' => '爆炒',
            'xipao' => '细切',
            'shexiang' => '麝香',
            'feicui' => '翡翠',
            'shuxian' => '时鲜',
            'pidan' => '皮蛋',
            'wuwwei' => '五味',
            'xiangzhi' => '香汁',
            'lawei' => '辣味',
            'huotuizhushenggeng' => '火腿',
            'kaoquanyang' => '全羊',
            'yuye' => '玉液',
            'tihu' => '醍醐',
            'fengsui' => '凤髓',
            'xiongzhang' => '熊掌',
            'xingchun' => '杏',
            'zhugun' => '竹棍',
            'mubang' => '木棒',
            'tongdao' => '铜刀',
            'tongqiang' => '铜枪',
            'tongbang' => '铜棒',
            'gangbang' => '钢棒',
            'yindao' => '银刀',
            'yinjian' => '银剑',
            'yingun' => '银棍',
            'shendao' => '神刀',
            'shenjian' => '神剑',
            'shenqiang' => '神枪',
            'huotui' => '火腿',
            'zhusheng' => '竹笋',
            'geng' => '羹',
            'kao' => '烤',
            'quanyang' => '全羊',
            'xiong' => '熊',
            'zhang' => '掌',
            'zhu' => '竹',
            'gun' => '棍',
            'mu' => '木',
            'bang' => '棒',
            'tong' => '铜',
            'dao' => '刀',
            'qiang' => '枪',
            'gang' => '钢',
            'yin' => '银',
            'jian' => '剑',
            'shen' => '神',
            'shengun' => '神棍',
            'shenbang' => '神棒',
            'shengqiang' => '圣枪',
            'tiandao' => '天刀',
            'tianjian' => '天剑',
            'tianqiang' => '天枪',
            'tiangun' => '天棍',
            'tianbang' => '天棒',
            'moqiang' => '魔枪',
            'sheng' => '圣',
            'tian' => '天',
            'mo' => '魔',
            'zuiji' => '醉鸡',
        ];
        
        if (isset($pinyinToChinese[$alias])) {
            $keyword = $pinyinToChinese[$alias];
            $result = Database::queryAll("SELECT i.* FROM items i WHERE i.name LIKE ? ORDER BY LENGTH(i.name) LIMIT 1", ['%' . $keyword . '%']);
            if (!empty($result)) {
                return $result[0];
            }
        }
        
        $pinyinParts = self::splitPinyin($alias);
        foreach ($pinyinParts as $parts) {
            $keyword = '';
            foreach ($parts as $part) {
                if (isset($pinyinToChinese[$part])) {
                    $keyword .= $pinyinToChinese[$part];
                }
            }
            if (!empty($keyword)) {
                $result = Database::queryAll("SELECT i.* FROM items i WHERE i.name LIKE ? ORDER BY LENGTH(i.name) LIMIT 1", ['%' . $keyword . '%']);
                if (!empty($result)) {
                    return $result[0];
                }
            }
        }
        
        return null;
    }
    
    private static function splitPinyin(string $pinyin): array {
        $results = [];
        
        $commonPrefixes = [
            'zh', 'ch', 'sh', 'z', 'c', 's',
            'b', 'p', 'm', 'f', 'd', 't', 'n', 'l',
            'g', 'k', 'h', 'j', 'q', 'x',
            'r', 'y', 'w'
        ];
        
        $result = [];
        $i = 0;
        while ($i < strlen($pinyin)) {
            $found = false;
            foreach ($commonPrefixes as $prefix) {
                $len = strlen($prefix);
                if (substr($pinyin, $i, $len) === $prefix && ($i + $len) < strlen($pinyin)) {
                    $result[] = $prefix . $pinyin[$i + $len];
                    $i += $len + 1;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                if ($i < strlen($pinyin)) {
                    $result[] = $pinyin[$i];
                    $i++;
                } else {
                    break;
                }
            }
        }
        $results[] = $result;
        
        $twoCharResults = [];
        for ($j = 0; $j < count($result) - 1; $j++) {
            $twoCharResults[] = [$result[$j] . $result[$j+1]];
        }
        $results = array_merge($results, $twoCharResults);
        
        return $results;
    }
    
    /**
     * 生成别名变体（用于模糊匹配）
     *
     * @param string $alias 原始别名
     * @return array 别名变体列表
     */
    private static function generateAliasVariants(string $alias): array {
        $variants = [];
        
        if (strpos($alias, ' ') !== false) {
            $variants[] = str_replace(' ', '', $alias);
            $variants[] = str_replace(' ', '_', $alias);
            $variants[] = str_replace(' ', '-', $alias);
        }
        
        if (strpos($alias, '_') !== false) {
            $variants[] = str_replace('_', '', $alias);
            $variants[] = str_replace('_', ' ', $alias);
        }
        
        if (strpos($alias, '-') !== false) {
            $variants[] = str_replace('-', '', $alias);
            $variants[] = str_replace('-', ' ', $alias);
        }
        
        $variants[] = strtolower($alias);
        $variants[] = strtoupper($alias);
        
        return array_unique($variants);
    }
    
    /**
     * 通过别名获取标准化物品ID
     *
     * @param string $alias 物品别名
     * @return string|null 标准化物品ID，未找到返回null
     */
    public static function getPrimaryItemId(string $alias): ?string {
        require_once __DIR__ . '/../includes/db.php';
        
        $alias = trim($alias);
        if (empty($alias)) {
            return null;
        }
        
        $item = self::findItemByAlias($alias);
        if ($item) {
            return $item['item_id'];
        }
        
        $sql = "SELECT primary_item_id FROM item_aliases 
                WHERE alias COLLATE utf8mb4_0900_ai_ci = ? COLLATE utf8mb4_0900_ai_ci 
                LIMIT 1";
        $result = Database::queryOne($sql, [$alias]);
        if ($result) {
            return $result['primary_item_id'];
        }
        
        return null;
    }
    
    /**
     * 获取角色当前进行中的任务
     *
     * @param int $charId 角色ID
     * @param string|null $questType 任务类型（可选）
     * @return array 任务列表
     */
    public static function getPendingQuests(int $charId, ?string $questType = null): array {
        require_once __DIR__ . '/../includes/db.php';
        
        try {
            $sql = "SELECT * FROM character_quests WHERE char_id = ? AND status = 'pending'";
            $params = [$charId];
            
            if ($questType) {
                $sql .= " AND quest_type = ?";
                $params[] = $questType;
            }
            
            $result = Database::queryAll($sql, $params);
            return $result ? $result : [];
        } catch (Exception $e) {
            error_log("获取进行中任务失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取角色已完成但未删除的任务（用于回访领奖）
     */
    public static function getCompletedQuests(int $charId): array {
        require_once __DIR__ . '/../includes/db.php';
        try {
            $sql = "SELECT * FROM character_quests WHERE char_id = ? AND status = 'completed'";
            $result = Database::queryAll($sql, [$charId]);
            return $result ? $result : [];
        } catch (Exception $e) {
            error_log("获取已完成任务失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 删除任务记录（领奖后清理）
     */
    public static function deleteQuest(int $questId): void {
        require_once __DIR__ . '/../includes/db.php';
        try {
            Database::execute("DELETE FROM character_quests WHERE id = ?", [$questId]);
        } catch (Exception $e) {
            error_log("删除任务失败: " . $e->getMessage());
        }
    }
    
    /**
     * 根据角色和任务计算动态奖励     *
     * @param array $char 角色数据
     * @param array $quest 任务数据
     * @return array 奖励数组 [daoxing, potential, silver]
     */
    public static function calculateRewards(array $char, array $quest): array
    {
        $charDx = intval($char['daoxing'] ?? 0);
        $charCombatExp = intval($char['combat_exp'] ?? 0);
        $charCps = intval($char['cps'] ?? 10);
        $charInt = intval($char['int'] ?? 10);
        $charKar = intval($char['kar'] ?? 10);
        $questDx = intval($quest['daoxing_require'] ?? 0);
        
        $exp = max(1, ($charCombatExp + $charDx * 2) / 3);
        if ($exp > 2000000) {
            $exp = 2000000;
        }
        
        // 初始奖励
        $reward = 15 + rand(0, intval($charCps / 2));
        if ($reward > 30) {
            $reward = 30;
        }
        
        // 任务复杂度加成
        $definitions = self::$questDefinitions[$quest['quest_type']] ?? [];
        $questIndex = 0;
        foreach ($definitions as $i => $def) {
            if ($def['daoxing'] == $questDx) {
                $questIndex = $i;
                break;
            }
        }
        
        $maxReward = 120;
        $reward += $maxReward * (1 + $questIndex) / max(1, count($definitions));
        
        // 对数和比例调整
        $logFactor = $exp >= 10000 ? 1 + log10($exp / 10000) : 1;
        $expRatio = $exp / max(1, $exp + $questDx);
        $questRatio = $questDx / max(1, $exp + $questDx);
        
        $reward = $reward * $logFactor * $expRatio * $questRatio;
        
        // 基础属性加成
        $reward += rand(0, $charInt) + rand(0, $charKar);
        
        // 确保奖励为正
        while ($reward <= 0) {
            $reward = rand(0, $charKar);
        }
        
        if ($reward >= $maxReward) {
            $reward = $maxReward + rand(0, $charKar);
        }
        
        // 根据任务类型和道行计算最终奖励
        $baseMultiplier = max(1, $questDx / 100);
        
        $rewardDaoxing = intval($reward * $baseMultiplier);
        $rewardPotential = intval($reward * $baseMultiplier * 0.6);
        $rewardSilver = intval($reward * $baseMultiplier * 0.3);
        
        $maxDaoxingReward = 100000;
        $maxPotentialReward = 60000;
        $maxSilverReward = 30000;
        
        $rewardDaoxing = min($rewardDaoxing, $maxDaoxingReward);
        $rewardPotential = min($rewardPotential, $maxPotentialReward);
        $rewardSilver = min($rewardSilver, $maxSilverReward);
        
        return [
            'daoxing' => $rewardDaoxing,
            'potential' => $rewardPotential,
            'silver' => $rewardSilver
        ];
    }

    /**
     * 分配任务给角色（统一使用kaifeng_quests.php数据源，支持二分查找和缓存机制）
     *
     * @param int $charId 角色ID
     * @param string $questType 任务类型
     * @param string|null $npcId NPC标识（可选，用于获取祥云颜色）
     * @return array|null 分配的任务数据，失败返回null
     */
    public static function assignQuest(int $charId, string $questType, ?string $npcId = null): ?array
    {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../models/Character.php';
        
        try {
            $char = CharacterModel::find($charId);
            if (!$char) {
                error_log("assignQuest: 角色不存在，charId={$charId}");
                return null;
            }
            
            $charDx = intval($char['daoxing'] ?? 0);
            error_log("assignQuest: 角色道行={$charDx}, 任务类型={$questType}");
            
            // 检查是否已有同类型任务
            $existingQuests = self::getPendingQuests($charId, $questType);
            if (!empty($existingQuests)) {
                error_log("assignQuest: 已有同类型任务");
                return null;
            }
            
            // 获取任务池（使用kaifeng_quests.php的daoxing-keyed格式）
            $questPool = self::getQuestPool($questType);
            if (empty($questPool)) {
                error_log("assignQuest: 没有找到任务定义，类型={$questType}");
                return null;
            }
            
            // 对于ask类型任务，根据npcId过滤任务池
            if ($questType === 'ask' && $npcId) {
                $filteredPool = [];
                foreach ($questPool as $key => $quest) {
                    if (!empty($quest['ask_npc']) && $quest['ask_npc'] === $npcId) {
                        $filteredPool[$key] = $quest;
                    }
                }
                if (!empty($filteredPool)) {
                    $questPool = $filteredPool;
                }
            }
            
            $cacheSize = self::getConfigParam('cache_size', 30);
            $indexDelta = self::getConfigParam('index_delta', 20);
            
            // 将任务池按道行值排序，提取key列表用于索引
            $daoxingKeys = array_keys($questPool);
            sort($daoxingKeys);
            $totalKeys = count($daoxingKeys);
            
            // 二分查找：找到道行值 <= 角色道行值+5000 的最大索引位置
            $suitableIndex = 0;
            $low = 0;
            $high = $totalKeys - 1;
            
            while ($low <= $high) {
                $mid = intval(($low + $high) / 2);
                if ($daoxingKeys[$mid] <= $charDx + 5000) {
                    $suitableIndex = $mid;
                    $low = $mid + 1;
                } else {
                    $high = $mid - 1;
                }
            }
            
            // 加入随机偏移
            $lower = max(0, $suitableIndex - $indexDelta);
            $upper = min($totalKeys - 1, $suitableIndex + $indexDelta);
            
            // 调整下限，允许访问更简单的任务
            $lower = intval($upper / 4);
            if ($upper - $lower < $indexDelta) {
                $lower = 0;
            }
            
            // 在范围内随机选择一个任务
            $selectedIndex = $lower + rand(0, max(0, $upper - $lower));
            $selectedDaoxingKey = $daoxingKeys[$selectedIndex];
            $selectedQuest = $questPool[$selectedDaoxingKey];
            
            // 缓存检查：避免重复任务
            $cacheCheckSql = "SELECT COUNT(*) as cnt FROM quest_cache WHERE char_id = ? AND quest_type = ? AND quest_index = ?";
            $cached = Database::queryOne($cacheCheckSql, [$charId, $questType, $selectedDaoxingKey]);
            
            if ($cached && intval($cached['cnt']) > 0) {
                // 尝试选择其他任务（最多尝试5次）
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $retryIndex = $lower + rand(0, max(0, $upper - $lower));
                    $retryDaoxingKey = $daoxingKeys[$retryIndex];
                    $retryCached = Database::queryOne($cacheCheckSql, [$charId, $questType, $retryDaoxingKey]);
                    
                    if (!$retryCached || intval($retryCached['cnt']) == 0) {
                        $selectedIndex = $retryIndex;
                        $selectedDaoxingKey = $retryDaoxingKey;
                        $selectedQuest = $questPool[$selectedDaoxingKey];
                        break;
                    }
                }
            }
            
            error_log("assignQuest: 选中任务=" . json_encode($selectedQuest, JSON_UNESCAPED_UNICODE));
            
            // 计算动态奖励
            $tempQuest = ['daoxing_require' => $selectedDaoxingKey, 'quest_type' => $questType];
            $rewards = self::calculateRewards($char, $tempQuest);
            error_log("assignQuest: 奖励=" . json_encode($rewards, JSON_UNESCAPED_UNICODE));
            
            // 获取祥云颜色：优先从NPC映射获取，否则使用默认颜色
            $color = 'white';
            if ($npcId) {
                $npcMap = self::getNpcMap();
                $color = $npcMap[$npcId]['color_code'] ?? 'white';
            }
            
            // 设置任务过期时间（与开封任务保持一致：5~15分钟）
            // 原始LPC: delay = 300 + random(600)
            $delay = 300 + rand(0, 600);
            $expiresAt = date('Y-m-d H:i:s', time() + $delay);
            
            // 根据任务类型构建参数（兼容不同任务池的字段格式）
            $targetId = $selectedQuest['id'] ?? $selectedQuest['target'] ?? $selectedQuest['target_npc'] ?? '';
            $objectName = $selectedQuest['object'] ?? $selectedQuest['topic'] ?? '';
            
            $questName = $selectedQuest['name'] ?? '';
            
            if ($questType === 'ask' && !empty($selectedQuest['target_npc'])) {
                $npcMap = self::getNpcMap();
                $targetNpcId = $selectedQuest['target_npc'];
                if (isset($npcMap[$targetNpcId])) {
                    $questName = $npcMap[$targetNpcId]['name'] ?? '';
                }
            }
            
            // kill任务通用ID解析（如 yaoguai, yaojing 等）
            if ($questType === 'kill' && self::isKillGenericId($targetId)) {
                $resolved = self::resolveKillGenericId($targetId, $questName);
                if ($resolved) {
                    $targetId = $resolved['npc_id'];
                    $questName = $resolved['name'];
                }
            }
            
            $sql = "INSERT INTO character_quests 
                    (char_id, quest_type, quest_name, target_id, target_name, object_name, daoxing_require, reward_daoxing, reward_potential, reward_silver, color_code, status, created_at, expires_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), ?)";
            
            $params = [
                $charId,
                $questType,
                $questName,
                $targetId,
                $questName,
                $objectName,
                $selectedDaoxingKey,
                $rewards['daoxing'],
                $rewards['potential'],
                $rewards['silver'],
                $color,
                $expiresAt
            ];
            
            $result = Database::execute($sql, $params);
            error_log("assignQuest: 数据库执行结果{$result}");
            
            if ($result) {
                // 获取插入的ID
                $questId = Database::lastInsertId();
                error_log("assignQuest: 任务创建成功，ID={$questId}");
                
                // 添加到缓存
                self::addQuestCache($charId, $questType, $selectedDaoxingKey, $cacheSize);
                
                return [
                    'id' => $questId,
                    'type' => $questType,
                    'name' => $questName,
                    'target' => $targetId,
                    'object' => $objectName,
                    'color' => $color,
                    'reward_dx' => $rewards['daoxing'],
                    'reward_pot' => $rewards['potential'],
                    'reward_silver' => $rewards['silver'],
                ];
            }
            
            error_log("assignQuest: 数据库执行失败");
            return null;
            
        } catch (Exception $e) {
            error_log("assignQuest: 异常=" . $e->getMessage());
            error_log("assignQuest: 堆栈=" . $e->getTraceAsString());
            return null;
        }
    }
    
    /**
     * 添加任务到缓存
     *
     * @param int $charId 角色ID
     * @param string $questType 任务类型
     * @param int $questIndex 任务索引（daoxing值）
     * @param int $cacheSize 缓存大小
     */
    public static function addQuestCache(int $charId, string $questType, int $questIndex, int $cacheSize = 30): void {
        require_once __DIR__ . '/../includes/db.php';
        
        try {
            Database::execute("INSERT INTO quest_cache (char_id, quest_type, quest_index, cached_at) VALUES (?, ?, ?, NOW())",
                [$charId, $questType, $questIndex]);
            
            // 清理超出缓存大小的旧记录
            Database::execute("DELETE FROM quest_cache WHERE char_id = ? AND quest_type = ? 
                AND id NOT IN (SELECT id FROM (SELECT id FROM quest_cache WHERE char_id = ? AND quest_type = ? ORDER BY cached_at DESC LIMIT ?) as tmp)",
                [$charId, $questType, $charId, $questType, $cacheSize]);
        } catch (Exception $e) {
            error_log("addQuestCache: 异常=" . $e->getMessage());
        }
    }
    
    /**
     * 标记任务为已完成（done状态）—— 不发放奖励，需回NPC领奖
     * 
     * 原始LPC流程：完成目标 → quest/pending/xxx/done=1 → 回NPC → rewarding() → 发奖+祥云
     * 当前实现：完成目标 → status='done' → 回NPC inquiry → claimQuestReward() → 发奖+祥云+品德值
     *
     * @param int $charId 角色ID
     * @param string $questType 任务类型
     * @param string $targetId 目标ID
     * @return array|null 任务信息（非奖励），失败返回null
     */
    public static function markQuestDone(int $charId, string $questType, string $targetId): ?array {
        require_once __DIR__ . '/../includes/db.php';
        
        // 查找进行中的任务
        $sql = "SELECT * FROM character_quests 
                WHERE char_id = ? AND quest_type = ? AND target_id = ? AND status = 'pending'
                LIMIT 1";
        $quest = Database::queryOne($sql, [$charId, $questType, $targetId]);
        
        if (!$quest) {
            return null;
        }
        
        // 更新任务状态为 done（完成目标但未领奖）
        $updateSql = "UPDATE character_quests 
                     SET status = 'done', completed_at = NOW()
                     WHERE id = ?";
        Database::execute($updateSql, [$quest['id']]);
        
        return [
            'id' => $quest['id'],
            'quest_name' => $quest['quest_name'] ?? '',
            'quest_type' => $questType,
            'color_code' => $quest['color_code'] ?? 'white',
        ];
    }
    
    /**
     * 获取done状态的任务（已完成目标但未领奖）
     */
    public static function getDoneQuests(int $charId, ?string $questType = null): array {
        require_once __DIR__ . '/../includes/db.php';
        
        $sql = "SELECT * FROM character_quests WHERE char_id = ? AND status = 'done'";
        $params = [$charId];
        
        if ($questType) {
            $sql .= " AND quest_type = ?";
            $params[] = $questType;
        }
        
        $result = Database::queryAll($sql, $params);
        return $result ? $result : [];
    }
    
    /**
     * 检查并自动过期超时任务（参考原始LPC check_player delay机制）
     * 
     * 原始LPC: t >= uptime() && (t-MAXDELAY) <= uptime() 检查
     * MAXDELAY = 300 + random(600)，即5~15分钟
     * 
     * @param int $charId 角色ID
     * @return array 过期的任务列表
     */
    public static function checkExpiredQuests(int $charId): array {
        require_once __DIR__ . '/../includes/db.php';
        
        // 将过期的 pending 和 done 任务标记为 expired
        // 两种情况视为过期：
        // 1. 有 expires_at 且已过期
        // 2. 没有 expires_at 的旧任务（可能是 bug 期间创建的，数据不完整）
        $sql = "SELECT * FROM character_quests 
                WHERE char_id = ? AND status IN ('pending', 'done') 
                AND (
                    (expires_at IS NOT NULL AND expires_at < NOW())
                    OR (expires_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))
                )";
        $expired = Database::queryAll($sql, [$charId]);
        
        if (!empty($expired)) {
            $ids = array_column($expired, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = $ids;
            $params[] = $charId;
            Database::execute(
                "UPDATE character_quests SET status = 'expired' WHERE id IN ({$placeholders}) AND char_id = ?",
                $params
            );
        }
        
        return $expired ? $expired : [];
    }
    
    /**
     * 获取任务的剩余时间（秒）
     * 
     * @param array $quest 任务记录
     * @return int|null 剩余秒数，null表示无过期时间
     */
    public static function getQuestRemainingTime(array $quest): ?int {
        $expiresAt = $quest['expires_at'] ?? null;
        if (empty($expiresAt)) return null;
        
        $remaining = strtotime($expiresAt) - time();
        return max(0, $remaining);
    }
    
    /**
     * NPC发放任务奖励（回访领奖）
     * 
     * 原始LPC rewarding() 的PHP实现：
     * 1. 计算品德值（quest_reward公式）
     * 2. 累计 quest/reward 用于赴京请赏
     * 3. 发放道行/潜能/白银奖励
     * 4. 更新颜色祥云计数（color_counter）
     * 5. 更新quest_stats统计
     * 6. 标记 status='completed'
     *
     * @param int $charId 角色ID
     * @param int $questId 任务记录ID
     * @param string $npcId NPC标识（用于颜色计数）
     * @return array 奖励详情 ['success'=>bool, 'moral'=>int, 'daoxing'=>int, 'potential'=>int, 'silver'=>int, 'color'=>string, 'cloud_message'=>string]
     */
    public static function claimQuestReward(int $charId, int $questId, string $npcId = ''): array {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../models/Character.php';
        
        // 获取done状态的任务
        $sql = "SELECT * FROM character_quests WHERE id = ? AND char_id = ? AND status = 'done' LIMIT 1";
        $quest = Database::queryOne($sql, [$questId, $charId]);
        
        if (!$quest) {
            return ['success' => false, 'message' => '任务不存在或已领奖'];
        }
        
        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        $questType = $quest['quest_type'] ?? '';
        $colorCode = $quest['color_code'] ?? 'white';
        
        // 1. 计算品德值（原始LPC quest_reward 公式）
        $moralReward = self::calculateMoralReward($char, $quest);
        
        // 2. 发放道行/潜能/白银奖励
        $rewards = self::giveQuestRewards($charId, $quest);
        
        // 3. 累计 quest_reward 品德值（用于赴京请赏）
        $currentReward = intval($char['quest_reward'] ?? 0);
        $newReward = $currentReward + $moralReward;
        Database::execute("UPDATE characters SET quest_reward = ? WHERE id = ?", [$newReward, $charId]);
        
        // 4. 更新颜色祥云计数（color_counter）
        self::updateColorCounter($charId, '', $colorCode);
        
        // 5. 更新统计信息
        self::updateQuestStats($charId, $questType, $quest);
        
        // 6. 标记为 completed
        Database::execute(
            "UPDATE character_quests SET status = 'completed', completed_at = NOW() WHERE id = ?",
            [$questId]
        );
        
        // 清理超过10条的已完成任务记录
        Database::execute(
            "DELETE FROM character_quests WHERE char_id = ? AND status IN ('completed', 'expired') 
             ORDER BY completed_at DESC LIMIT 10, 1000",
            [$charId]
        );
        
        // 7. 生成祥云消息
        $cloudMessage = self::getColorCloudMessage($colorCode);
        
        return [
            'success' => true,
            'moral' => $moralReward,
            'daoxing' => $rewards['daoxing'],
            'potential' => $rewards['potential'],
            'silver' => $rewards['silver'],
            'color' => $colorCode,
            'cloud_message' => $cloudMessage,
            'quest_name' => $quest['quest_name'] ?? '',
            'quest_type' => $questType,
        ];
    }
    
    /**
     * 完成任务（兼容旧接口，内部调用 markQuestDone）
     * 
     * @deprecated 此方法已废弃，请使用 markQuestDone + claimQuestReward 两步流程
     * 注意：此方法直接发放奖励并标记completed，跳过品德值计算和祥云计数更新，会导致玩家丢失奖励
     *
     * @param int $charId 角色ID
     * @param string $questType 任务类型
     * @param string $targetId 目标ID
     * @return array|null 奖励信息，失败返回null
     */
    public static function completeQuest(int $charId, string $questType, string $targetId): ?array {
        require_once __DIR__ . '/../includes/db.php';
        
        // 查找待完成的任务
        $sql = "SELECT * FROM character_quests 
                WHERE char_id = ? AND quest_type = ? AND target_id = ? AND status = 'pending'
                LIMIT 1";
        $quest = Database::queryOne($sql, [$charId, $questType, $targetId]);
        
        if (!$quest) {
            return null;
        }
        
        // 发放奖励
        $rewards = self::giveQuestRewards($charId, $quest);
        
        if ($rewards) {
            // 更新任务状态
            $updateSql = "UPDATE character_quests 
                         SET status = 'completed', completed_at = NOW()
                         WHERE id = ?";
            Database::execute($updateSql, [$quest['id']]);
            
            // 清理超过10条的已完成任务记录
            Database::execute(
                "DELETE FROM character_quests WHERE char_id = ? AND status IN ('completed', 'expired') 
                 ORDER BY completed_at DESC LIMIT 10, 1000",
                [$charId]
            );
            
            // 更新统计信息
            self::updateQuestStats($charId, $questType, $quest);
            
            // 添加颜色祥云（简单模式，仅记录颜色）
            if (!empty($quest['color_code'])) {
                self::addColorCloud($charId, $quest['color_code']);
            }
        }
        
        return $rewards;
    }
    
    /**
     * 发放任务奖励
     *
     * @param int $charId 角色ID
     * @param array $quest 任务数据
     * @return array 奖励信息
     */
    public static function giveQuestRewards(int $charId, array $quest): array {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../models/Character.php';
        
        $rewardDx = intval($quest['reward_daoxing'] ?? 0);
        $rewardPot = intval($quest['reward_potential'] ?? 0);
        $rewardSilver = intval($quest['reward_silver'] ?? 0);
        
        $maxInt = 2147483647;
        
        if ($rewardDx > 0) {
            $char = Database::queryOne("SELECT daoxing FROM characters WHERE id = ?", [$charId]);
            if ($char) {
                $currentDx = intval($char['daoxing'] ?? 0);
                if ($currentDx + $rewardDx > $maxInt) {
                    $rewardDx = max(0, $maxInt - $currentDx);
                }
            }
        }
        
        $updateFields = [];
        $params = [];
        
        if ($rewardDx > 0) {
            $updateFields[] = "daoxing = daoxing + ?";
            $params[] = $rewardDx;
        }
        if ($rewardPot > 0) {
            $updateFields[] = "potential = potential + ?";
            $params[] = $rewardPot;
        }
        if ($rewardSilver > 0) {
            $updateFields[] = "silver = silver + ?";
            $params[] = $rewardSilver;
        }
        
        if (!empty($updateFields)) {
            $params[] = $charId;
            $sql = "UPDATE characters SET " . implode(', ', $updateFields) . " WHERE id = ?";
            Database::execute($sql, $params);
        }
        
        return [
            'daoxing' => $rewardDx,
            'potential' => $rewardPot,
            'silver' => $rewardSilver,
        ];
    }
    
    /**
     * 更新任务统计信息
     *
     * @param int $charId 角色ID
     * @param string $questType 任务类型
     * @param array $quest 任务数据
     */
    private static function updateQuestStats(int $charId, string $questType, array $quest): void {
        require_once __DIR__ . '/../includes/db.php';
        
        // 检查是否存在统计记录
        $checkSql = "SELECT char_id FROM quest_stats WHERE char_id = ?";
        $exists = Database::queryOne($checkSql, [$charId]);
        
        $typeField = $questType . '_times';
        $rewardField = $questType . '_reward';
        $totalReward = intval($quest['reward_daoxing'] ?? 0) + intval($quest['reward_potential'] ?? 0);
        
        if ($exists) {
            $sql = "UPDATE quest_stats 
                    SET total_quests = total_quests + 1,
                        {$typeField} = {$typeField} + 1,
                        {$rewardField} = {$rewardField} + ?,
                        total_daoxing_gained = total_daoxing_gained + ?,
                        total_potential_gained = total_potential_gained + ?,
                        total_silver_gained = total_silver_gained + ?,
                        updated_at = NOW()
                    WHERE char_id = ?";
            $params = [
                $totalReward,
                intval($quest['reward_daoxing'] ?? 0),
                intval($quest['reward_potential'] ?? 0),
                intval($quest['reward_silver'] ?? 0),
                $charId
            ];
        } else {
            $sql = "INSERT INTO quest_stats 
                    (char_id, total_quests, {$typeField}, {$rewardField}, 
                     total_daoxing_gained, total_potential_gained, total_silver_gained, updated_at)
                    VALUES (?, 1, 1, ?, ?, ?, ?, NOW())";
            $params = [
                $charId,
                $totalReward,
                intval($quest['reward_daoxing'] ?? 0),
                intval($quest['reward_potential'] ?? 0),
                intval($quest['reward_silver'] ?? 0)
            ];
        }
        
        Database::execute($sql, $params);
    }
    
    /**
     * 检查任务缓存
     *
     * @param int $charId 角色ID
     * @param string $questType 任务类型
     * @param int $questIndex 任务索引
     * @return bool 是否已缓存
     */
    private static function checkQuestCache(int $charId, string $questType, int $questIndex): bool {
        require_once __DIR__ . '/../includes/db.php';
        
        $sql = "SELECT char_id FROM quest_cache 
                WHERE char_id = ? AND quest_type = ? AND quest_index = ?";
        $result = Database::queryOne($sql, [$charId, $questType, $questIndex]);
        
        return $result ? true : false;
    }
    
    /**
     * 计算品德值奖励（模仿原始LPC quest_reward 公式）
     * 
     * 原始公式：
     *   reward = 15 + random(cps)/2  (上限30)
     *   reward += MAXREWARD * (1+index) / sizeof(quests)
     *   reward *= (1 + log10(exp/10000)) * exp/(exp+dx) * dx/(exp+dx)
     *   reward += random(int) + random(kar)
     *   如果 reward >= MAXREWARD: reward = MAXREWARD + random(kar)
     *
     * @param array $char 角色数据
     * @param array $quest 任务数据
     * @return int 品德值
     */
    public static function calculateMoralReward(array $char, array $quest): int {
        $combatExp = intval($char['combat_exp'] ?? 0);
        $daoxing = intval($char['daoxing'] ?? 0);
        $cps = intval($char['cps'] ?? 10);
        $charInt = intval($char['int'] ?? 10);
        $charKar = intval($char['kar'] ?? 10);
        $questDx = intval($quest['daoxing_require'] ?? 0);
        
        // exp = (combat_exp + daoxing*2) / 3
        $exp = intval(($combatExp + $daoxing * 2) / 3);
        if ($exp > 2000000) $exp = 2000000;
        if ($exp < 1) $exp = 1;
        
        $maxReward = 120;
        
        // 初始值: 15 + random(cps)/2, 上限30
        $reward = 15 + intval(rand(0, $cps) / 2);
        if ($reward > 30) $reward = 30;
        
        // 任务复杂度加成: MAXREWARD * (1+index) / sizeof(quests)
        // 根据任务类型加载对应任务池，计算实际索引位置
        $questType = $quest['quest_type'] ?? '';
        $questPoolSize = 1;
        $questIndex = 0;
        
        $configFile = __DIR__ . '/../config/kaifeng_quests.php';
        if (file_exists($configFile)) {
            $kaifengConfig = require $configFile;
            $questPools = $kaifengConfig['quest_pools'] ?? [];
            
            if (isset($questPools[$questType])) {
                $pool = $questPools[$questType];
                $questPoolSize = count($pool);
                $daoxingKeys = array_keys($pool);
                sort($daoxingKeys);
                
                $index = 0;
                foreach ($daoxingKeys as $key) {
                    if ($key <= $questDx) {
                        $questIndex = $index;
                    }
                    $index++;
                }
            }
        }
        
        if ($questPoolSize <= 1) {
            $questPoolSize = max(1, $questDx > 0 ? 100 : 1);
            $questIndex = intval($questDx / 100);
        }
        
        $reward += intval($maxReward * (1 + $questIndex) / $questPoolSize);
        
        // 对数和比例调整
        $logFactor = $exp >= 10000 ? (1 + log10($exp / 10000)) : 1;
        $expPlusDx = max(1, $exp + $questDx);
        $reward = intval($reward * $logFactor * $exp / $expPlusDx * $questDx / $expPlusDx);
        
        // 基础属性加成
        $reward += rand(0, $charInt) + rand(0, $charKar);
        
        // 确保为正
        while ($reward <= 0) {
            $reward = rand(0, $charKar);
        }
        
        // 上限突破
        if ($reward >= $maxReward) {
            $reward = $maxReward + rand(0, $charKar);
        }
        
        return max(1, $reward);
    }
    
    /**
     * 更新颜色祥云计数（模仿原始LPC color_counter）
     * 
     * 逻辑：
     * - Max_count=10
     * - 完成对应颜色任务：该颜色计数重置为 Max_count
     * - 所有其他颜色计数减1
     * - 计数≤1的颜色被移除
     * 
     * @param int $charId 角色ID
     * @param string $npcId NPC标识（用于确定颜色代码，兼容旧调用）
     * @param string $colorCode 颜色代码（优先使用，直接指定颜色）
     */
    public static function updateColorCounter(int $charId, string $npcId = '', string $colorCode = ''): void {
        require_once __DIR__ . '/../includes/db.php';
        
        // 确定颜色代码：优先使用传入的colorCode，否则通过npcId查找
        if (empty($colorCode)) {
            $colorCode = 'white';
            $configFile = __DIR__ . '/../config/kaifeng_quests.php';
            $kaifengConfig = file_exists($configFile) ? require $configFile : [];
            $npcMap = $kaifengConfig['npc_map'] ?? [];
            if (!empty($npcId) && isset($npcMap[$npcId])) {
                $colorCode = $npcMap[$npcId]['color_code'] ?? 'white';
            }
        }
        
        // 获取当前颜色计数
        $stats = Database::queryOne("SELECT quest_colors FROM quest_stats WHERE char_id = ?", [$charId]);
        $colors = [];
        if ($stats && !empty($stats['quest_colors'])) {
            $decoded = json_decode($stats['quest_colors'], true);
            if (is_array($decoded)) {
                $colors = $decoded;
            }
        }
        
        // 有效的颜色代码列表
        $validColors = array_keys(self::$colorClouds);
        
        // 更新计数：当前颜色重置为 Max_count，其他减1
        $updated = [];
        foreach ($colors as $existingColor => $count) {
            if (!in_array($existingColor, $validColors)) continue;
            
            if ($existingColor === $colorCode) {
                $updated[$existingColor] = self::MAX_COLOR_COUNT;
            } else {
                $newCount = intval($count) - 1;
                if ($newCount > 1) {
                    $updated[$existingColor] = $newCount;
                }
                // 计数≤1的颜色被移除
            }
        }
        
        // 如果该颜色还不存在，添加
        if (!isset($updated[$colorCode])) {
            $updated[$colorCode] = self::MAX_COLOR_COUNT;
        }
        
        // 同时维护简单的颜色列表（兼容旧代码）
        $colorList = array_keys($updated);
        
        Database::execute(
            "UPDATE quest_stats SET quest_colors = ? WHERE char_id = ?",
            [json_encode(['counter' => $updated, 'list' => $colorList]), $charId]
        );
    }
    
    /**
     * 获取当前祥云颜色数量和颜色列表
     * 
     * @param int $charId 角色ID
     * @return array ['count' => int, 'colors' => array]
     */
    public static function getColorCounter(int $charId): array {
        $stats = self::getQuestStats($charId);
        if (!$stats || empty($stats['quest_colors'])) {
            return ['count' => 0, 'colors' => []];
        }
        
        $data = json_decode($stats['quest_colors'], true) ?: [];
        $counter = $data['counter'] ?? [];
        $list = $data['list'] ?? [];
        
        return [
            'count' => count($counter),
            'colors' => $list,
            'counter' => $counter,
        ];
    }
    
    /**
     * 获取祥云颜色倍率（用于赴京请赏）
     * 
     * 原始LPC emperor.c test_player:
     *   1色=1倍, 2色=1倍, 3色=3倍, 4色=7倍, 5色=10倍, 6色=15倍, 7色=25倍
     *   新玩家(exp+dx)/2 < 20000 保底2色10倍
     * 
     * @param int $charId 角色ID
     * @return int 倍率
     */
    public static function getColorMultiplier(int $charId): int {
        $colorData = self::getColorCounter($charId);
        $color = $colorData['count'];
        
        switch ($color) {
            case 1: return 1;
            case 2: return 1;
            case 3: return 3;
            case 4: return 7;
            case 5: return 10;
            case 6: return 15;
            case 7: return 25;
            case 8: return 30;
            case 9: return 35;
            default: return 1;
        }
    }
    
    /**
     * 生成祥云消息
     */
    public static function getColorCloudMessage(string $colorCode): string {
        $colorName = self::$colorClouds[$colorCode]['name'] ?? '白';
        $cloudMessages = [
            "慢慢地一小团{$colorName}色祥云在你的身边升起。",
            "你的身上慢慢升起一股{$colorName}色祥云。",
            "一小股{$colorName}色祥云在你的身上缓缓升起。",
            "只见你的身上徐徐飘浮起一小团{$colorName}色祥云。",
            "一团{$colorName}色小祥云在你的身上慢慢升起。",
        ];
        return $cloudMessages[array_rand($cloudMessages)];
    }
    
    /**
     * 添加颜色祥云（简单模式，仅记录颜色列表）
     *
     * @param int $charId 角色ID
     * @param string $color 颜色代码
     */
    private static function addColorCloud(int $charId, string $color): void {
        require_once __DIR__ . '/../includes/db.php';
        
        $sql = "SELECT quest_colors FROM quest_stats WHERE char_id = ?";
        $stats = Database::queryOne($sql, [$charId]);
        
        $data = [];
        if ($stats && !empty($stats['quest_colors'])) {
            $data = json_decode($stats['quest_colors'], true) ?: [];
        }
        
        $list = $data['list'] ?? [];
        if (!in_array($color, $list)) {
            $list[] = $color;
        }
        
        $updateSql = "UPDATE quest_stats SET quest_colors = ? WHERE char_id = ?";
        Database::execute($updateSql, [json_encode(['list' => $list, 'counter' => $data['counter'] ?? []]), $charId]);
    }
    
    /**
     * 获取任务统计信息
     *
     * @param int $charId 角色ID
     * @return array|null
     */
    public static function getQuestStats(int $charId): ?array {
        require_once __DIR__ . '/../includes/db.php';
        
        $sql = "SELECT * FROM quest_stats WHERE char_id = ?";
        $stats = Database::queryOne($sql, [$charId]);
        
        return $stats;
    }
    
    /**
     * 获取颜色祥云显示信息
     *
     * @param int $charId 角色ID
     * @return array
     */
    public static function getColorCloudsDisplay(int $charId): array {
        $colorData = self::getColorCounter($charId);
        $colors = $colorData['colors'];
        $display = [];
        
        foreach ($colors as $color) {
            if (isset(self::$colorClouds[$color])) {
                $display[] = self::$colorClouds[$color];
            }
        }
        
        return $display;
    }
    
    /**
     * 获取所有支持的任务类型
     *
     * @return array
     */
    public static function getSupportedQuestTypes(): array {
        return [
            self::TYPE_KILL => '杀怪任务',
            self::TYPE_GIVE => '给予任务',
            self::TYPE_ASK => '询问任务',
            self::TYPE_FIND => '查找任务',
            self::TYPE_WEAPON => '武器任务',
            self::TYPE_ARMOR => '盔甲任务',
            self::TYPE_CLOTH => '衣服任务',
            self::TYPE_FOOD => '食物任务',
            self::TYPE_WEARING => '穿戴任务',
            self::TYPE_MISC => '杂项任务',
        ];
    }

    /**
     * 增加品德值
     *
     * @param int $charId 角色ID
     * @param int $amount 增加数量
     * @return bool 是否成功
     */
    public static function addMoralValue(int $charId, int $amount): bool {
        if ($amount <= 0) {
            return false;
        }

        require_once __DIR__ . '/../includes/db.php';

        try {
            $sql = "UPDATE characters SET moral_value = moral_value + ? WHERE id = ?";
            Database::execute($sql, [$amount, $charId]);
            return true;
        } catch (Exception $e) {
            error_log("addMoralValue failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取品德值
     *
     * @param int $charId 角色ID
     * @return int 品德值
     */
    public static function getMoralValue(int $charId): int {
        require_once __DIR__ . '/../includes/db.php';

        try {
            $sql = "SELECT moral_value FROM characters WHERE id = ?";
            $result = Database::queryOne($sql, [$charId]);
            return $result ? intval($result['moral_value']) : 0;
        } catch (Exception $e) {
            error_log("getMoralValue failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 消费品德值（赴京请赏时使用）
     *
     * @param int $charId 角色ID
     * @param int $amount 消费数量
     * @return bool 是否成功（品德值不足时返回false）
     */
    public static function consumeMoralValue(int $charId, int $amount): bool {
        if ($amount <= 0) {
            return false;
        }

        require_once __DIR__ . '/../includes/db.php';

        try {
            $current = self::getMoralValue($charId);
            if ($current < $amount) {
                return false;
            }

            $sql = "UPDATE characters SET moral_value = moral_value - ? WHERE id = ? AND moral_value >= ?";
            $result = Database::execute($sql, [$amount, $charId, $amount]);
            return $result > 0;
        } catch (Exception $e) {
            error_log("consumeMoralValue failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 赴京请赏 - 根据累计品德值(quest_reward)和祥云颜色倍率计算奖励
     * 
     * 原始LPC流程：
     *   玩家完成N个任务 → quest/reward 品德值累加 → 前往皇宫
     *   → 唐太宗 test_player() → 按祥云颜色数量计算 reward_point = quest/reward * 倍率 / 6
     *   → 加权随机选择大臣 → 大臣 reward() 发放奖励
     *
     * 奖励类型概率（原始LPC emperor.c）：
     *   - 段志贤(潜能) 53%  — rand<53
     *   - 徐茂功(道行) 47%  — else
     *   - 杜如晦(技能) 3%   — rand<3 (仅3色以上)
     *   - 张士衡(天赋) 1%   — rand==0 (仅3色以上)
     *   - 孟子如(白银) 2%   — rand<3 (仅3色以上)
     *   1色时只给技能/天赋/白银
     *
     * @param int $charId 角色ID
     * @return array 奖励信息 ['success' => bool, 'message' => string, 'rewards' => array]
     */
    public static function calculateBeijingReward(int $charId): array {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../models/Character.php';

        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在', 'rewards' => []];
        }

        $questReward = intval($char['quest_reward'] ?? 0);
        if ($questReward < 1) {
            return [
                'success' => false,
                'message' => "你没有可领取的赏赐。去开封府完成一些解谜任务再来吧！",
                'rewards' => []
            ];
        }

        // 获取祥云颜色倍率
        $colorMultiplier = self::getColorMultiplier($charId);
        $colorData = self::getColorCounter($charId);
        $colorCount = $colorData['count'];
        
        // 新玩家保护（原始LPC: (combat_exp+daoxing)/2 < 20000 保底2色10倍）
        $combatExp = intval($char['combat_exp'] ?? 0);
        $daoxing = intval($char['daoxing'] ?? 0);
        if (intval(($combatExp + $daoxing) / 2) < 20000) {
            if ($colorCount < 2) $colorCount = 2;
            if ($colorMultiplier < 10) $colorMultiplier = 10;
        }

        // 计算 reward_point = quest_reward * 倍率 / 6
        $rewardPoint = intval($questReward * $colorMultiplier / 6);
        if ($rewardPoint < 1) $rewardPoint = 1;

        $charKar = intval($char['kar'] ?? 10);
        $charInt = intval($char['int'] ?? 10);

        // 按原始LPC逻辑选择大臣
        // 1色时：只给技能/天赋/白银 (rand(6))
        // 多色时：rand(100) → 段53%/徐47%/杜3%/张1%/孟2%
        $selectedType = 'potential';
        $ministerName = '';
        $ministerTitle = '';

        if ($colorCount <= 1) {
            $rand = rand(0, 5);
            if ($rand == 0) {
                $selectedType = 'talent';
                $ministerName = '张士衡';
                $ministerTitle = '户部侍郎';
            } elseif ($rand < 3) {
                $selectedType = 'silver';
                $ministerName = '孟子如';
                $ministerTitle = '太常寺卿';
            } else {
                $selectedType = 'skill';
                $ministerName = '杜如晦';
                $ministerTitle = '吏部尚书';
            }
        } else {
            $rand = rand(0, 99);
            if ($rand == 0) {
                $selectedType = 'talent';
                $ministerName = '张士衡';
                $ministerTitle = '户部侍郎';
            } elseif ($rand < 3) {
                $selectedType = 'silver';
                $ministerName = '孟子如';
                $ministerTitle = '太常寺卿';
            } elseif ($rand < 6) {
                $selectedType = 'skill';
                $ministerName = '杜如晦';
                $ministerTitle = '吏部尚书';
            } elseif ($rand < 53) {
                $selectedType = 'potential';
                $ministerName = '段志贤';
                $ministerTitle = '礼部侍郎';
            } else {
                $selectedType = 'daoxing';
                $ministerName = '徐茂功';
                $ministerTitle = '兵部尚书';
            }
        }

        // 发放奖励
        $rewards = [];
        $rewardMessage = '';

        switch ($selectedType) {
            case 'potential':
                // 段志贤：直接加潜能
                $amount = $rewardPoint;
                Database::execute("UPDATE characters SET potential = potential + ? WHERE id = ?", [$amount, $charId]);
                $rewards = ['type' => 'potential', 'amount' => $amount, 'minister' => $ministerName, 'title' => $ministerTitle];
                $rewardMessage = "太宗准奏，赐予你 {$amount} 点潜能。";
                break;

            case 'daoxing':
                // 徐茂功：points = 40 + points*4
                $amount = 40 + $rewardPoint * 4;
                Database::execute("UPDATE characters SET daoxing = daoxing + ? WHERE id = ?", [$amount, $charId]);
                $rewards = ['type' => 'daoxing', 'amount' => $amount, 'minister' => $ministerName, 'title' => $ministerTitle];
                $rewardMessage = "太宗准奏，赐予你 {$amount} 天道行。";
                break;

            case 'skill':
                // 杜如晦：随机选技能，points = points * (kar/2+1)，do_improve
                $amount = intval($rewardPoint * ($charKar / 2 + 1));
                // 获取角色已学技能
                $skills = Database::queryAll(
                    "SELECT skill_type, level FROM character_skill_map WHERE char_id = ? AND level > 0 AND level < 50",
                    [$charId]
                );
                $skillName = '技能';
                $skillImproved = false;
                if (!empty($skills)) {
                    $skill = $skills[array_rand($skills)];
                    $skillType = $skill['skill_type'];
                    $skillLevel = intval($skill['level']);
                    
                    // do_improve: 递归提升技能等级
                    $remaining = $amount;
                    while ($remaining > 0 && $skillLevel < 50) {
                        $needed = $skillLevel * $skillLevel * 2;
                        if ($remaining >= $needed) {
                            $remaining -= $needed;
                            $skillLevel++;
                            $skillImproved = true;
                        } else {
                            break;
                        }
                    }
                    
                    if ($skillImproved) {
                        Database::execute(
                            "UPDATE character_skill_map SET level = ? WHERE char_id = ? AND skill_type = ?",
                            [$skillLevel, $charId, $skillType]
                        );
                        $skillName = $skillType;
                    }
                }
                
                // 如果没技能可升，转为潜能
                if (!$skillImproved || empty($skills)) {
                    $amount = $rewardPoint;
                    Database::execute("UPDATE characters SET potential = potential + ? WHERE id = ?", [$amount, $charId]);
                    $rewards = ['type' => 'potential', 'amount' => $amount, 'minister' => $ministerName, 'title' => $ministerTitle];
                    $rewardMessage = "太宗准奏，赐予你 {$amount} 点潜能。";
                } else {
                    $rewards = ['type' => 'skill', 'skill' => $skillName, 'level' => $skillLevel, 'points' => $amount, 'minister' => $ministerName, 'title' => $ministerTitle];
                    $rewardMessage = "太宗准奏，杜如晦亲自指导你的{$skillName}，提升至第{$skillLevel}级！";
                }
                break;

            case 'talent':
                // 张士衡：随机选一项低于40的属性 +2
                $talents = [
                    'str' => '膂力', 'cps' => '胆识', 'int' => '悟性', 
                    'per' => '灵性', 'con' => '定力', 'kar' => '福缘',
                    'cor' => '容貌', 'dex' => '身法'
                ];
                $talentField = array_rand($talents);
                $talentName = $talents[$talentField];
                $amount = 2;
                
                Database::execute(
                    "UPDATE characters SET {$talentField} = {$talentField} + ? WHERE id = ?",
                    [$amount, $charId]
                );
                $rewards = ['type' => 'talent', 'field' => $talentField, 'name' => $talentName, 'amount' => $amount, 'minister' => $ministerName, 'title' => $ministerTitle];
                $rewardMessage = "太宗准奏，张士衡赐予灵药，你的{$talentName}提升了{$amount}点。";
                break;

            case 'silver':
                // 孟子如：points*15，上限2000
                $amount = min(2000, $rewardPoint * 15);
                Database::execute("UPDATE characters SET silver = silver + ? WHERE id = ?", [$amount, $charId]);
                $rewards = ['type' => 'silver', 'amount' => $amount, 'minister' => $ministerName, 'title' => $ministerTitle];
                $rewardMessage = "太宗准奏，赐予你白银 {$amount} 两。";
                break;
        }

        // 清除 quest_reward
        Database::execute("UPDATE characters SET quest_reward = 0 WHERE id = ?", [$charId]);
        
        // 清零祥云计数（原始LPC: 赴京请赏后清空颜色祥云）
        Database::execute("UPDATE quest_stats SET quest_colors = '{}' WHERE char_id = ?", [$charId]);

        // 构建华丽的输出消息
        $speeches = [
            'potential' => '陛下，此子品行端正，屡解开封之谜，臣建议赏赐潜能以资鼓励。',
            'daoxing' => '陛下，此子道心稳固，功德无量，臣建议赐予道行修为。',
            'skill' => '陛下，此子武艺可造，臣建议赏赐技能点数，以助其精进。',
            'talent' => '陛下，此子天资聪颖，臣建议赐予天赋灵药，暂时提升资质。',
            'silver' => '陛下，此子劳苦功高，臣建议赏赐白银以作慰劳。',
        ];

        $messages = [];
        $messages[] = HTML_HIYEL . "【赴京请赏】" . HTML_NOR;
        $messages[] = "";
        $messages[] = HTML_HIWHT . "你跪于金銮殿上，太宗皇帝端坐龙椅，文武百官分列两侧。" . HTML_NOR;
        
        if ($colorCount > 1) {
            $colorDesc = $colorCount >= 2 ? self::chineseNumber($colorCount) . '彩' : '';
            $messages[] = "一进大殿，你身上涌起淡淡的{$colorDesc}祥云。";
        }
        $messages[] = "";
        $messages[] = HTML_HICYN . "{$ministerTitle}{$ministerName}" . HTML_NOR . "出班奏道：";
        $messages[] = HTML_HIWHT . "「{$speeches[$selectedType]}」" . HTML_NOR;
        $messages[] = "";
        $messages[] = HTML_HIGRN . "太宗颔首道：「准奏！」" . HTML_NOR;
        $messages[] = "";
        $messages[] = $rewardMessage;
        $messages[] = "";
        $messages[] = HTML_HIYEL . "【奖励详情】" . HTML_NOR;
        $messages[] = HTML_HIGRN . "  品德赏赐消耗：{$questReward}点（祥云倍率×{$colorMultiplier}）" . HTML_NOR;

        return [
            'success' => true,
            'message' => implode("<br>", $messages),
            'rewards' => $rewards,
        ];
    }
    
    /**
     * 中文数字转换（用于祥云颜色显示）
     */
    private static function chineseNumber(int $n): string {
        $map = ['', '一', '二', '三', '四', '五', '六', '七', '八', '九'];
        return $map[$n] ?? strval($n);
    }

    /**
     * 放弃任务（清心宫/宁心宫/静心宫）
     *
     * @param int $charId 角色ID
     * @param int|null $questId 任务ID（可选，不传则放弃所有进行中任务）
     * @return array ['success' => bool, 'message' => string]
     */
    public static function abandonQuest(int $charId, ?int $questId = null): array {
        require_once __DIR__ . '/../includes/db.php';

        if ($questId !== null) {
            // 放弃单个任务
            $sql = "SELECT quest_name, target_name FROM character_quests 
                    WHERE id = ? AND char_id = ? AND status = 'pending' LIMIT 1";
            $quest = Database::queryOne($sql, [$questId, $charId]);
            
            if (!$quest) {
                return ['success' => false, 'message' => '任务不存在或已完成。'];
            }
            
            Database::execute(
                "UPDATE character_quests SET status = 'abandoned' WHERE id = ? AND char_id = ?",
                [$questId, $charId]
            );
            
            $questName = $quest['quest_name'] ?? $quest['target_name'] ?? '未知任务';
            return [
                'success' => true,
                'message' => "你诚心跪拜，心中杂念顿消。已放弃任务：{$questName}。",
            ];
        }

        // 放弃所有进行中的任务
        $pendingQuests = self::getPendingQuests($charId);

        if (empty($pendingQuests)) {
            return ['success' => false, 'message' => '你当前没有进行中的任务。'];
        }

        $abandonedCount = 0;
        $abandonedNames = [];
        foreach ($pendingQuests as $quest) {
            $qId = $quest['id'] ?? 0;
            if ($qId > 0) {
                Database::execute(
                    "UPDATE character_quests SET status = 'abandoned' WHERE id = ? AND char_id = ?",
                    [$qId, $charId]
                );
                $abandonedCount++;
                $questName = $quest['quest_name'] ?? $quest['target_name'] ?? '未知任务';
                $abandonedNames[] = $questName;
            }
        }

        if ($abandonedCount > 0) {
            $names = implode('、', array_slice($abandonedNames, 0, 3));
            if ($abandonedCount > 3) {
                $names .= '等';
            }
            return [
                'success' => true,
                'message' => "你诚心跪拜，心中杂念顿消。已放弃 {$abandonedCount} 个任务：{$names}。",
            ];
        }

        return ['success' => false, 'message' => '放弃任务失败，请稍后再试。'];
    }
}

