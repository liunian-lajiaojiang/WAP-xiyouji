<?php
/**
 * 自然守护进程 - 处理天气、时间、季节等自然环境
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
class NatureDaemon {
    
    /**
     * 时间段定义（来自原始项目 adm/etc/nature/day_phase）
     * length: 持续时间（分钟）
     * time_msg: 时间段开始时的消息
     * desc_msg: 该时间段的描述
     */
    private static $dayPhases = [
        [
            'length' => 240,
            'time_msg' => '东方的天空中开始出现一丝微曦。',
            'desc_msg' => '东方的天空已逐渐发白',
            'period_name' => '清晨'
        ],
        [
            'length' => 120,
            'time_msg' => '太阳从东方的地平线升起了。',
            'desc_msg' => '太阳刚从东方的地平线升起',
            'period_name' => '早晨'
        ],
        [
            'length' => 180,
            'time_msg' => '太阳已经高高地挂在东方的天空中。',
            'desc_msg' => '太阳正高挂在东方的天空中',
            'period_name' => '上午'
        ],
        [
            'length' => 180,
            'time_msg' => '已经是正午了，太阳从你正上方照耀着大地。',
            'desc_msg' => '现在是正午时分，太阳高挂在你的头顶正上方',
            'period_name' => '中午'
        ],
        [
            'length' => 180,
            'time_msg' => '太阳开始从西方的天空中慢慢西沉。',
            'desc_msg' => '太阳正高挂在西方的天空中',
            'period_name' => '下午'
        ],
        [
            'length' => 180,
            'time_msg' => '傍晚了，太阳的馀晖将西方的天空映成一片火红。',
            'desc_msg' => '一轮火红的夕阳正徘徊在西方的地平线上',
            'period_name' => '黄昏'
        ],
        [
            'length' => 120,
            'time_msg' => '夜晚降临了。',
            'desc_msg' => '夜幕笼罩着大地',
            'period_name' => '夜晚'
        ],
        [
            'length' => 240,
            'time_msg' => '已经是午夜了。',
            'desc_msg' => '夜幕低垂，满天繁星',
            'period_name' => '深夜'
        ]
    ];
    
    /**
     * 季节定义
     * 春季：3-5月，夏季：6-8月，秋季：9-11月，冬季：12-2月
     */
    private static $seasons = [
        'spring' => ['months' => [3, 4, 5], 'name' => '春天', 'desc' => '春暖花开的季节'],
        'summer' => ['months' => [6, 7, 8], 'name' => '夏天', 'desc' => '烈日炎炎的夏季'],
        'autumn' => ['months' => [9, 10, 11], 'name' => '秋天', 'desc' => '秋高气爽的时节'],
        'winter' => ['months' => [12, 1, 2], 'name' => '冬天', 'desc' => '寒风凛冽的冬季']
    ];
    
    /**
     * 获取游戏日内经过的秒数 (0-1439)
     * 
     * 原始LPC: natured.c line 40
     *   t = time() % 1440;  // 1 game day = 24 min in real time
     * 
     * 1 游戏日 = 1440 现实秒 = 24 现实分钟
     * 每个 phase 的 length 字段单位也是游戏秒（= 现实秒）
     */
    private static function getGameSeconds(): int {
        return time() % 1440;
    }

    /**
     * 获取游戏内小时 (0-23)
     * 
     * 1 游戏日 = 24 游戏小时 = 1440 秒
     * 1 游戏小时 = 60 现实秒
     * 游戏日从 0:00 开始对应 子时前段（23:00）
     * 
     * 注：chineseDate 的 epoch (850348800) 起始于子时
     * 游戏小时0 ≈ 子时前半(23:00)，游戏小时12 ≈ 午时(11:00)
     */
    public static function getGameHour(): int {
        $time = time();
        $time0 = 850348800; // chineseDate 纪元：1996/12/12 GMT
        $elapsed = $time - $time0;
        if ($elapsed <= 0) return 0;
        $daySeconds = $elapsed % 1440; // 游戏日内经过的秒数
        return intdiv($daySeconds, 60);  // 0-23
    }

    /**
     * 获取当前时间段
     * 
     * 原始LPC: t = time() % 1440，然后逐 phase 减去 length
     */
    public static function getCurrentPhase(): array {
        $gameSeconds = self::getGameSeconds();
        
        $accumulated = 0;
        foreach (self::$dayPhases as $index => $phase) {
            $accumulated += $phase['length'];
            if ($gameSeconds < $accumulated) {
                return $phase;
            }
        }
        
        // 默认返回最后一个阶段
        return end(self::$dayPhases);
    }
    
    /**
     * 判断当前是否为夜间（游戏时间）
     * 
     * 游戏内夜晚对应 day_phase 中的 "夜晚"(120s) + "深夜"(240s)
     * 即游戏日秒数 1080-1439（对应 phase 6+7）
     * 加上 "清晨"(240s) 的前半段（游戏日秒数 0-239）
     * 
     * 换算为游戏小时：约 18:00 ~ 03:59 为夜间
     */
    public static function isNight(): bool {
        $gameHour = self::getGameHour();
        // 游戏时间 18:00 ~ 03:59 为夜间
        return $gameHour >= 18 || $gameHour < 4;
    }
    
    /**
     * 获取当前季节
     */
    public static function getCurrentSeason(): array {
        $month = intval(date('n'));
        
        foreach (self::$seasons as $seasonKey => $seasonData) {
            if (in_array($month, $seasonData['months'])) {
                return [
                    'key' => $seasonKey,
                    'name' => $seasonData['name'],
                    'desc' => $seasonData['desc']
                ];
            }
        }
        
        // 默认返回春天
        return self::$seasons['spring'];
    }
    
    /**
     * 获取天气描述
     */
    public static function getWeatherDescription(): string {
        $season = self::getCurrentSeason();
        $gameHour = self::getGameHour();
        
        // 根据游戏时间判断白天/夜晚
        if ($gameHour >= 6 && $gameHour < 18) {
            // 白天（游戏时间 6-18 点）
            return self::getDaytimeWeather($season['key']);
        } else {
            // 夜晚（游戏时间 18-6 点）
            return self::getNighttimeWeather($season['key']);
        }
    }
    
    /**
     * 获取白天的天气描述（根据季节）
     */
    private static function getDaytimeWeather(string $season): string {
        $weathers = [
            // 春季天气
            'spring' => [
                '春暖花开，微风轻拂。',
                '阳光明媚，百花争艳。',
                '春雨绵绵，滋润大地。',
                '春风和煦，柳絮飞舞。',
                '桃花盛开，鸟语花香。',
                '细雨蒙蒙，空气清新。',
                '彩虹横跨天际，绚丽多彩。', // 特殊天气 - 彩虹
            ],
            
            // 夏季天气
            'summer' => [
                '烈日炎炎，热浪滚滚。',
                '天空晴朗，万里无云。',
                '暴雨倾盆，雷声隆隆。',
                '狂风大作，树叶沙沙作响。',
                '乌云密布，电闪雷鸣。',
                '冰雹突降，噼啪作响。', // 特殊天气 - 冰雹
                '沙尘暴起，天地昏黄。', // 特殊天气 - 沙尘暴
            ],
            
            // 秋季天气
            'autumn' => [
                '秋高气爽，金风送爽。',
                '白云朵朵，清风徐来。',
                '秋雨潇潇，落叶纷飞。',
                '秋风瑟瑟，寒意渐浓。',
                '碧空如洗，阳光灿烂。',
                '雾气弥漫，能见度较低。',
                '阴雨绵绵，远处的山峦若隐若现。',
            ],
            
            // 冬季天气
            'winter' => [
                '寒风刺骨，雪花飘飘。',
                '大雪纷飞，银装素裹。',
                '冰天雪地，寒气逼人。',
                '风雪交加，天地一片白茫。',
                '晴空万里，阳光温暖。',
                '鹅毛大雪，漫天飞舞。', // 特殊天气 - 大雪
                '雾凇奇观，晶莹剔透。', // 特殊天气 - 雾凇
            ]
        ];
        
        $seasonWeathers = $weathers[$season] ?? $weathers['spring'];
        return $seasonWeathers[array_rand($seasonWeathers)];
    }
    
    /**
     * 获取夜晚的天气描述（根据季节）
     */
    private static function getNighttimeWeather(string $season): string {
        $weathers = [
            // 春季夜晚
            'spring' => [
                '月朗星稀的夜，微风轻抚河畔，天空还时不时的传来各种花虫鸟兽的叫声。',
                '夜，刚刚暗下来，浓雾层层弥漫、漾开，熏染出一个平静祥和的夜。',
                '夜色如水，月光洒在大地上，银辉遍地。',
                '星光点点，夜色迷人，远处传来阵阵蛙鸣。',
                '皓月当空，繁星闪烁，夜风带来淡淡的花香。',
            ],
            
            // 夏季夜晚
            'summer' => [
                '夏夜闷热，蝉鸣不绝。',
                '萤火虫在草丛中飞舞，宛如点点星光。',
                '夜空清澈，银河横贯天际。',
                '雷雨过后，空气清新凉爽。',
                '月色朦胧，树影婆娑。',
            ],
            
            // 秋季夜晚
            'autumn' => [
                '秋夜清凉，明月高悬。',
                '星光点点，夜色迷人，远处传来阵阵虫鸣。',
                '夜幕降临，一轮明月高悬，树影婆娑。',
                '秋风习习，落叶沙沙作响。',
                '夜空深邃，繁星闪烁。',
            ],
            
            // 冬季夜晚
            'winter' => [
                '冬夜寒冷，月光清冷。',
                '雪夜静谧，万籁俱寂。',
                '极光在天际舞动，绚丽多彩。', // 特殊天气 - 极光
                '寒夜星空，格外清澈明亮。',
                '夜色深沉，伸手不见五指。',
                '漆黑一片，只有远处的灯火微弱闪烁。',
            ]
        ];
        
        $seasonWeathers = $weathers[$season] ?? $weathers['spring'];
        return $seasonWeathers[array_rand($seasonWeathers)];
    }
    
    /**
     * 获取当前时间段描述
     */
    public static function getTimePeriod(): string {
        $phase = self::getCurrentPhase();
        return $phase['period_name'];
    }
    
    /**
     * 获取完整的环境描述
     */
    public static function getEnvironmentDescription(bool $isOutdoors): string {
        if (!$isOutdoors) {
            return '';
        }
        
        $phase = self::getCurrentPhase();
        $season = self::getCurrentSeason();
        $weather = self::getWeatherDescription();
        
        return "{$season['desc']}，{$phase['desc_msg']}，{$weather}";
    }
    
    /**
     * 检查是否应该广播天气变化（每小时整点）
     */
    public static function shouldBroadcastWeatherChange(): bool {
        // 每小时的第0分钟广播
        return intval(date('i')) === 0;
    }
    
    /**
     * 获取天气变化广播消息
     */
    public static function getWeatherChangeMessage(): string {
        $season = self::getCurrentSeason();
        $phase = self::getCurrentPhase();
        $weather = self::getWeatherDescription();
        
        return "【天气】{$season['name']}，{$phase['period_name']}，{$weather}";
    }
    
    /**
     * TODO: 天气对玩家属性的影响（扩展预留）
     * 
     * 可以在这里添加天气对 gameplay 的影响，例如：
     * - 雨天：移动速度降低 10%
     * - 雾天：视野范围缩小 20%
     * - 晴天：修炼效果提升 5%
     * - 雪天：寒冷伤害，每秒损失 1 点气血
     * - 炎热：水分流失加快
     * 
     * @param int $charId 角色ID
     * @return array 属性修改数组 ['gin_delta' => 0, 'sen_delta' => 0, ...]
     */
    public static function getWeatherEffect(int $charId): array {
        // 当前返回空数组，表示没有影响
        // TODO: 根据实际需求实现天气效果
        return [
            'gin_delta' => 0,   // 精力变化
            'sen_delta' => 0,   // 心神变化
            'kee_delta' => 0,   // 气血变化
            'mana_delta' => 0,  // 法力变化
        ];
    }
}

