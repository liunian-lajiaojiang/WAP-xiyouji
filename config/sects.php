<?php
/**
 * 门派配置文件
 *
 * 阵营说明：
 *   1  = 仙阵营
 *   0  = 人阵营
 *  -1  = 妖阵营
 *
 * 门派列表（共10个）：
 *   lingtai   - 灵台方寸山（菩提祖师）    仙
 *   wudidong  - 花果山水帘洞（孙悟空/玉书）妖
 *   longgong  - 东海龙宫（敖广）           人
 *   nanhai    - 南海普陀山（观音菩萨）     仙
 *   moon      - 月宫（嫦娥）               人
 *   wzg       - 五庄观（镇元大仙）         仙
 *   yanluofu  - 阎罗地府（地藏菩萨）       人
 *   jiangjunfu- 将军府（秦琼）             人
 *   huoyun    - 火云洞（红孩儿）           妖
 *   xueshan   - 大雪山（大鹏明王）         妖
 *
 * ==================================================================
 * 【迁移说明】 npc_masters 拜师条件数据已迁移到数据库 npcs 表
 *   新增字段: is_master, sect_key, master_title, apprentice_conditions, rejection_messages
 *   ApprenticeHandler 和 SectHelper 现从数据库读取拜师条件
 *   此处 npc_masters 数组仅作为备份参考保留，不再用于运行时判断
 *   迁移脚本: database/migrate_apprentice_conditions.sql
 * ==================================================================
 */

return [

    // =========================================================
    // 各门派配置
    // =========================================================
    'sects' => [

        // ---------------------------------------------------------
        // 1. 灵台方寸山（菩提祖师）—— 仙阵营
        // ---------------------------------------------------------
        'lingtai' => [
            'name'              => '灵台方寸山',
            'short_name'        => '方寸山',
            'family_name'       => '方寸山三星洞',  // 与 family.php 对应
            'alignment'         => 1,               // 仙
            'description'       => '菩提祖师隐居于灵台方寸山斜月三星洞，传授道法武功，'
                                  . '门下弟子皆习无相之道，以柔克刚，变化莫测。',
            'master_npc'        => '菩提祖师',
            'master_npc_id'     => 342,
            'location'          => '灵台方寸山斜月三星洞',
            'location_room_id'  => null,
            'requirements'      => [
                'min_level'     => 1,
                'min_age'       => 0,
                'race'          => null,            // 无种族限制
                'gender'        => null,
            ],
            'skills'            => [
                'exclusive'     => [
                    'qianjun-bang'  => '千钧棒法',   // 大棒绝学
                    'puti-zhi'      => '菩提指',      // 指法
                    'wuxiangforce'  => '小无相功',    // 内功心法（只能学/运用）
                ],
                'bonus'         => [
                    'unarmed'       => 5,
                    'stick'         => 5,
                ],
            ],
            'npc_masters'       => [
                [
                    'npc_id'   => 342,
                    'name'     => '菩提祖师',
                    'title'    => '斜月三星',
                    'conditions' => [
                        'daoxing_min' => 350000,
                        'int_min'     => 35,
                        'skills'      => [
                            'dao' => 120,
                        ],
                    ],
                    'rejection_messages' => [
                        'daoxing' => '道行尚浅，好自修为，他日再来。',
                        'int'     => '资质太钝，再加修行也无法成道为仙。',
                        'skill'   => '习我的法术方才初成，火候不到，也非真学问。',
                    ],
                ],
                [
                    'npc_id'   => 343,
                    'name'     => '云阳真人',
                    'title'    => '小天师',
                    'conditions' => [
                        'daoxing_min' => 100000,
                    ],
                    'rejection_messages' => [
                        'daoxing' => '你的道行还不够，多加修炼吧。',
                    ],
                ],
            ],
            'ranks'             => ['外门弟子', '记名弟子', '内门弟子', '核心弟子', '长老', '掌门'],
            'bonuses'           => [
                'int_bonus'     => 3,
                'spi_bonus'     => 3,
                'force_recover' => 1.1,
                // 方寸山特色：闪避加成
                'base_dodge'    => 0.30,          // 30%基础闪避加成
                // 符箓系统支持
                'max_fu_items'  => 50,            // 最大符箓携带量
                // 阵法加成
                'formation_bonus' => [
                    'speed' => 0.15,              // 阵法速度+15%
                ],
                // 特殊道具
                'special_items' => ['papers:heavenly'],  // 天符纸
            ],
        ],

        // ---------------------------------------------------------
        // 2. 花果山水帘洞（玉书/孙悟空传承）—— 妖阵营
        // ---------------------------------------------------------
        'wudidong' => [
            'name'              => '花果山水帘洞',
            'short_name'        => '花果山',
            'family_name'       => '陷空山无底洞',  // family.php中的key（历史沿用）
            'alignment'         => -1,              // 妖
            'description'       => '花果山乃天下妖猴聚居之处，水帘洞中藏有无数秘籍，'
                                  . '以棒法称雄，以千变万化著称于江湖。',
            'master_npc'        => '玉书',
            'master_npc_id'     => 902,
            'location'          => '花果山水帘洞',
            'location_room_id'  => null,
            'requirements'      => [
                'min_level'     => 1,
                'min_age'       => 0,
                'race'          => null,
                'gender'        => null,
            ],
            'skills'            => [
                'exclusive'     => [
                    'qianjun-bang'  => '千钧棒法',   // 花果山同源棒法
                    'puti-zhi'      => '菩提指',      // 渊源于菩提
                    'wuxiangforce'  => '小无相功',
                ],
                'bonus'         => [
                    'stick'         => 5,
                    'unarmed'       => 3,
                ],
            ],
            'npc_masters'       => [
                [
                    'npc_id'   => 902,
                    'name'     => '玉书',
                    'title'    => '水帘洞总管',
                    'conditions' => [
                        'daoxing_min' => 200000,
                        'str_min'     => 30,
                        'skills'      => [
                            'unarmed' => 100,
                        ],
                    ],
                    'rejection_messages' => [
                        'daoxing' => '你的道行还不够格做我花果山弟子。',
                        'str'     => '力量不够，在花果山难以立足。',
                        'skill'   => '拳脚功夫太差，回去多练练。',
                    ],
                ],
                [
                    'npc_id'   => 911,
                    'name'     => '马流',
                    'title'    => '花果山头目',
                    'conditions' => [
                        'daoxing_min' => 50000,
                    ],
                    'rejection_messages' => [
                        'daoxing' => '修为还差得远呢，再去闯荡闯荡吧。',
                    ],
                ],
                [
                    'npc_id'   => 912,
                    'name'     => '通背',
                    'title'    => '花果山头目',
                    'conditions' => [
                        'daoxing_min' => 80000,
                        'dex_min'     => 25,
                    ],
                    'rejection_messages' => [
                        'daoxing' => '道行不够，还需苦练。',
                        'dex'     => '身法太差，跟不上我的节奏。',
                    ],
                ],
                [
                    'npc_id'   => 913,
                    'name'     => '赤面',
                    'title'    => '花果山头目',
                    'conditions' => [
                        'daoxing_min' => 120000,
                        'str_min'     => 28,
                    ],
                    'rejection_messages' => [
                        'daoxing' => '道行不足，再去修炼吧。',
                        'str'     => '力量太弱，学不了我的功夫。',
                    ],
                ],
                [
                    'npc_id'   => 914,
                    'name'     => '六耳',
                    'title'    => '六耳猕猴',
                    'conditions' => [
                        'daoxing_min' => 150000,
                        'int_min'     => 30,
                    ],
                    'rejection_messages' => [
                        'daoxing' => '道行尚浅，不足以拜在我门下。',
                        'int'     => '悟性不足，学不会我的心法。',
                    ],
                ],
            ],
            'ranks'             => ['山野散猴', '洞中弟子', '头目', '大将', '四大天王', '美猴王'],
            'bonuses'           => [
                'str_bonus'     => 3,
                'agility_bonus' => 2,
            ],
        ],

        // ---------------------------------------------------------
        // 3. 东海龙宫（敖广/敖光）—— 人阵营
        // ---------------------------------------------------------
        'longgong' => [
            'name'              => '东海龙宫',
            'short_name'        => '龙宫',
            'family_name'       => '东海龙宫',
            'alignment'         => 0,               // 人
            'description'       => '敖广执掌东海，龙宫深居海底，弟子习龙神心法与龙形武功，'
                                  . '以水为师，攻守兼备。',
            'master_npc'        => '敖广',
            'master_npc_id'     => 903,
            'location'          => '东海龙宫大殿',
            'location_room_id'  => null,
            'requirements'      => [
                'min_level'     => 1,
                'min_age'       => 0,
                'race'          => null,
                'gender'        => null,
            ],
            'skills'            => [
                'exclusive'     => [
                    'dragonforce'   => '龙神心法',   // 内功（需在法台练）
                    'dragonfight'   => '龙形搏击',   // 空手搏击
                    'fengbo-cha'    => '风波十二叉', // 龙族叉法
                    'huntian-hammer'=> '混天锤',     // 锤法
                    'seashentong'   => '碧海神通',   // 法术
                ],
                'bonus'         => [
                    'unarmed'       => 5,
                    'fork'          => 5,
                    'hammer'        => 3,
                ],
            ],
            'npc_masters'       => [
                [
                    'npc_id'   => 903,
                    'name'     => '敖广',
                    'title'    => '东海龙王',
                    'conditions' => [
                        'daoxing_min' => 250000,
                        'con_min'     => 30,
                        'skills'      => [
                            'water' => 80,
                        ],
                    ],
                    'rejection_messages' => [
                        'daoxing' => '你的道行还不足以承受龙宫的修炼。',
                        'con'     => '体质太弱，承受不了海底的压力。',
                        'skill'   => '水性太差，在龙宫如何修行？',
                    ],
                ],
            ],
            'ranks'             => ['小虾兵', '虾兵', '蟹将', '龙将', '太子', '龙王'],
            'bonuses'           => [
                'con_bonus'     => 3,
                'str_bonus'     => 2,
                'water_resist'  => 20,              // 水系抗性
            ],
        ],

        // ---------------------------------------------------------
        // 4. 南海普陀山（观音菩萨）—— 仙阵营
        // ---------------------------------------------------------
        'nanhai' => [
            'name'              => '南海普陀山',
            'short_name'        => '普陀山',
            'family_name'       => '南海普陀山',
            'alignment'         => 1,               // 仙
            'description'       => '观音菩萨慈悲为怀，坐镇南海普陀，门下弟子习莲花心法与'
                                  . '劫难指，以慈悲之心化解万难，普渡众生。',
            'master_npc'        => '观音菩萨',
            'master_npc_id'     => 904,
            'location'          => '南海普陀广场',
            'location_room_id'  => null,
            'requirements'      => [
                'min_level'     => 1,
                'min_age'       => 0,
                'race'          => null,
                'gender'        => null,
            ],
            'skills'            => [
                'exclusive'     => [
                    'lotusforce'    => '莲花心法',   // 内功（不可主动练）
                    'jienan-zhi'    => '劫难指',     // 指法
                    'lunhui-zhang'  => '轮回杖',     // 杖法
                    'buddhism'      => '大乘佛法',   // 法术（金字塔顶）
                ],
                'bonus'         => [
                    'unarmed'       => 5,
                    'staff'         => 5,
                    'spells'        => 3,
                ],
            ],
            'npc_masters'       => [
                [
                    'npc_id'   => 904,
                    'name'     => '观音菩萨',
                    'title'    => '大慈大悲',
                    'conditions' => [
                        'daoxing_min' => 400000,
                        'spi_min'     => 35,
                        'skills'      => [
                            'buddhism' => 100,
                        ],
                    ],
                    'rejection_messages' => [
                        'daoxing' => '你的修为尚浅，还需继续修行。',
                        'spi'     => '心性不够，需要潜心修行佛法。',
                        'skill'   => '佛法修为不够，多诵经念佛再来。',
                    ],
                ],
            ],
            'ranks'             => ['善信', '居士', '菩萨弟子', '罗汉', '菩萨', '大士'],
            'bonuses'           => [
                'spi_bonus'     => 4,
                'int_bonus'     => 2,
                'heal_bonus'    => 1.2,             // 治疗效果加成
                'bellicosity_max'=> 50,             // 限制最大杀气
            ],
        ],

        // ---------------------------------------------------------
        // 5. 月宫（嫦娥/太阴星君）—— 人阵营
        // ---------------------------------------------------------
        'moon' => [
            'name'              => '月宫',
            'short_name'        => '月宫',
            'family_name'       => '月宫',
            'alignment'         => 0,               // 人
            'description'       => '嫦娥居于广寒宫，月宫弟子习圆月心法与风回雪舞剑法，'
                                  . '以轻灵见长，百花掌尤为女弟子所用。',
            'master_npc'        => '嫦娥',
            'master_npc_id'     => 905,
            'location'          => '月宫广寒殿',
            'location_room_id'  => null,
            'requirements'      => [
                'min_level'     => 1,
                'min_age'       => 0,
                'race'          => null,
                'gender'        => null,
            ],
            'skills'            => [
                'exclusive'     => [
                    'moonforce'     => '圆月心法',      // 内功（不可主动练）
                    'snowsword'     => '风回雪舞剑法',  // 剑法（需圆月心法）
                    'baihua-zhang'  => '百花掌',        // 仅女性可学
                    'moonshentong'  => '晚月神通',      // 法术
                    'moondance'     => '月舞',          // 特殊技能
                ],
                'bonus'         => [
                    'sword'         => 5,
                    'unarmed'       => 3,
                    'spells'        => 2,
                ],
            ],
            'npc_masters'       => [
                [
                    'npc_id'   => 915,
                    'name'     => '西王母',
                    'title'    => '瑶池金母',
                    'conditions' => [
                        'daoxing_min' => 500000,
                    ],
                    'rejection_messages' => [
                        'daoxing' => '百年道行方有资格入我月宫，你道行尚浅。',
                    ],
                ],
                [
                    'npc_id'   => 905,
                    'name'     => '嫦娥',
                    'title'    => '广寒仙子',
                    'conditions' => [
                        'per_min'     => 20,
                        'daoxing_min' => 150000,
                    ],
                    'rejection_messages' => [
                        'per'     => '容貌不够出众，不适合月宫修行。',
                        'daoxing' => '道行不足，还需继续修炼。',
                    ],
                ],
                [
                    'npc_id'   => 916,
                    'name'     => '痴梦仙姑',
                    'title'    => '痴梦',
                    'conditions' => [
                        'special'  => 'fate',
                    ],
                    'rejection_messages' => [
                        'special'  => '你我无缘，请回吧。',
                    ],
                ],
                [
                    'npc_id'   => 917,
                    'name'     => '月奴',
                    'title'    => '月宫侍女',
                    'conditions' => [],
                    'rejection_messages' => [],
                ],
            ],
            'ranks'             => ['月宫侍女', '习艺弟子', '月宫仙子', '广寒仙君', '太阴星君', '月宫掌门'],
            'bonuses'           => [
                'kar_bonus'     => 3,               // 魅力加成
                'agility_bonus' => 3,
                'night_bonus'   => 1.1,             // 夜间战斗加成
            ],
        ],

        // ---------------------------------------------------------
        // 6. 五庄观（镇元大仙）—— 仙阵营
        // ---------------------------------------------------------
        'wzg' => [
            'name'              => '五庄观',
            'short_name'        => '五庄观',
            'family_name'       => '五庄观',
            'alignment'         => 1,               // 仙
            'description'       => '镇元大仙隐居五庄观，以人参果闻名三界，门下弟子修习'
                                  . '太乙仙法与三清剑法、晓风残月剑，以道家武学为本。',
            'master_npc'        => '镇元大仙',
            'master_npc_id'     => 906,
            'location'          => '五庄观大殿',
            'location_room_id'  => null,
            'requirements'      => [
                'min_level'     => 1,
                'min_age'       => 0,
                'race'          => null,
                'gender'        => null,
            ],
            'skills'            => [
                'exclusive'     => [
                    'taiyi'         => '太乙仙法',      // 法术
                    'sanqing-jian'  => '三清剑法',      // 剑法（内力≤50可学）
                    'xiaofeng-sword'=> '晓风残月剑',    // 高阶剑法（内力≤100）
                ],
                'bonus'         => [
                    'sword'         => 5,
                    'spells'        => 3,
                    'force'         => 3,
                ],
            ],
            'npc_masters'       => [
                [
                    'npc_id'   => 906,
                    'name'     => '镇元大仙',
                    'title'    => '地仙之祖',
                    'conditions' => [
                        'daoxing_min' => 300000,
                        'spi_min'     => 30,
                        'int_min'     => 30,
                    ],
                    'rejection_messages' => [
                        'daoxing' => '道行不够，还需苦修。',
                        'spi'     => '灵气不足，无法感悟地仙之道。',
                        'int'     => '悟性不够，修不了我的功法。',
                    ],
                ],
            ],
            'ranks'             => ['道童', '外门弟子', '内门弟子', '核心弟子', '道长', '大仙'],
            'bonuses'           => [
                'int_bonus'     => 3,
                'per_bonus'     => 2,
                'longevity'     => true,            // 人参果长生特效
            ],
        ],

        // ---------------------------------------------------------
        // 7. 阎罗地府（地藏菩萨）—— 人阵营
        // ---------------------------------------------------------
        'yanluofu' => [
            'name'              => '阎罗地府',
            'short_name'        => '地府',
            'family_name'       => '阎罗地府',
            'alignment'         => 0,               // 人
            'description'       => '地藏菩萨主掌幽冥，地府弟子习勾魂术与哭丧棒，'
                                  . '以阴鬼之道克敌，杀气渐积，威力大增。',
            'master_npc'        => '地藏菩萨',
            'master_npc_id'     => 907,
            'location'          => '阎罗殿',
            'location_room_id'  => null,
            'requirements'      => [
                'min_level'     => 1,
                'min_age'       => 0,
                'race'          => null,
                'gender'        => null,
            ],
            'skills'            => [
                'exclusive'     => [
                    'gouhunshu'     => '勾魂术',     // 法术
                    'kusang-bang'   => '哭丧棒',     // 棒法（随杀气增强）
                    'hellfire-whip' => '烈火鞭',     // 鞭法
                ],
                'bonus'         => [
                    'stick'         => 5,
                    'whip'          => 3,
                    'spells'        => 3,
                ],
            ],
            'npc_masters'       => [
                [
                    'npc_id'   => 907,
                    'name'     => '地藏菩萨',
                    'title'    => '幽冥教主',
                    'conditions' => [
                        'daoxing_min' => 350000,
                        'spi_min'     => 35,
                        'skills'      => [
                            'buddhism' => 80,
                        ],
                    ],
                    'rejection_messages' => [
                        'daoxing' => '你的道行还不够深入地府修行。',
                        'spi'     => '心性不够坚定，地府阴气会侵蚀你。',
                        'skill'   => '佛法修为不够，地府之中需以佛法护身。',
                    ],
                ],
            ],
            'ranks'             => ['小鬼', '阴差', '判官', '鬼王', '阎罗', '冥界之主'],
            'bonuses'           => [
                'bellicosity_growth' => 1.2,        // 杀气增长加成
                'undead_bonus'  => 1.1,             // 对亡灵类伤害
                'con_bonus'     => 2,
            ],
        ],

        // ---------------------------------------------------------
        // 8. 将军府（秦琼）—— 人阵营
        // ---------------------------------------------------------
        'jiangjunfu' => [
            'name'              => '将军府',
            'short_name'        => '将军府',
            'family_name'       => '将军府',
            'alignment'         => 1,               // 仙（family.php标注为仙）
            'description'       => '秦琼将军威名赫赫，将军府弟子习冷泉神功与霸王枪法、'
                                  . '八卦咒，以武将之道横行江湖，刚猛无敌。',
            'master_npc'        => '秦琼',
            'master_npc_id'     => 908,
            'location'          => '将军府大厅',
            'location_room_id'  => null,
            'requirements'      => [
                'min_level'     => 1,
                'min_age'       => 0,
                'race'          => null,
                'gender'        => null,
            ],
            'skills'            => [
                'exclusive'     => [
                    'lengquan-force'=> '冷泉神功',   // 内功（不可主动练）
                    'bawang-qiang'  => '霸王枪',     // 枪法
                    'baguazhou'     => '八卦咒',     // 法术
                    'sanban-axe'    => '三板斧',     // 斧法（战将风格）
                ],
                'bonus'         => [
                    'spear'         => 5,
                    'axe'           => 3,
                    'spells'        => 2,
                ],
            ],
            'npc_masters'       => [
                [
                    'npc_id'   => 908,
                    'name'     => '秦琼',
                    'title'    => '门神',
                    'conditions' => [
                        'daoxing_min' => 200000,
                        'str_min'     => 35,
                        'skills'      => [
                            'sword' => 100,
                        ],
                    ],
                    'rejection_messages' => [
                        'daoxing' => '修为不足，当不了将军府弟子。',
                        'str'     => '力量不够，拿不动兵器。',
                        'skill'   => '剑法太差，回去多练。',
                    ],
                ],
                [
                    'npc_id'   => 918,
                    'name'     => '尉迟恭',
                    'title'    => '门神',
                    'conditions' => [
                        'daoxing_min' => 150000,
                        'str_min'     => 30,
                    ],
                    'rejection_messages' => [
                        'daoxing' => '道行不够，再去历练。',
                        'str'     => '力量不足，学不了我的功夫。',
                    ],
                ],
                [
                    'npc_id'   => 919,
                    'name'     => '程咬金',
                    'title'    => '混世魔王',
                    'conditions' => [
                        'daoxing_min' => 100000,
                        'con_min'     => 28,
                    ],
                    'rejection_messages' => [
                        'daoxing' => '修为不够，再练练。',
                        'con'     => '身子骨太弱，扛不住我一斧。',
                    ],
                ],
                [
                    'npc_id'   => 920,
                    'name'     => '罗成',
                    'title'    => '冷面寒枪',
                    'conditions' => [
                        'daoxing_min' => 180000,
                        'dex_min'     => 30,
                    ],
                    'rejection_messages' => [
                        'daoxing' => '道行不足，回去修炼。',
                        'dex'     => '身法太慢，学不了我的枪法。',
                    ],
                ],
            ],
            'ranks'             => ['伙夫', '士兵', '队正', '校尉', '将军', '大将军'],
            'bonuses'           => [
                'str_bonus'     => 4,
                'con_bonus'     => 2,
                'armor_bonus'   => 1.1,             // 护甲效果加成
            ],
        ],

        // ---------------------------------------------------------
        // 9. 火云洞（红孩儿/三昧真火）—— 妖阵营
        // ---------------------------------------------------------
        'huoyun' => [
            'name'              => '火云洞',
            'short_name'        => '火云洞',
            'family_name'       => '火云洞',
            'alignment'         => -1,              // 妖
            'description'       => '红孩儿盘踞火云洞，精通三昧真火，门下弟子修习'
                                  . '火魔心法与火云枪，以火焰之力横扫天下。',
            'master_npc'        => '红孩儿',
            'master_npc_id'     => 909,
            'location'          => '火云洞内殿',
            'location_room_id'  => null,
            'requirements'      => [
                'min_level'     => 1,
                'min_age'       => 0,
                'race'          => null,
                'gender'        => null,
            ],
            'skills'            => [
                'exclusive'     => [
                    'huomoforce'    => '火魔心法',   // 内功（需要杀气）
                    'huoyun-qiang'  => '火云枪',     // 枪法（火焰附加）
                ],
                'bonus'         => [
                    'spear'         => 5,
                    'unarmed'       => 3,
                    'fire_spell'    => 5,
                ],
            ],
            'npc_masters'       => [
                [
                    'npc_id'   => 909,
                    'name'     => '红孩儿',
                    'title'    => '圣婴大王',
                    'conditions' => [
                        'daoxing_min' => 250000,
                        'special'     => 'introduction',
                    ],
                    'rejection_messages' => [
                        'daoxing' => '你的道行还不够格。',
                        'special' => '没有人引见，不得入洞。',
                    ],
                ],
                [
                    'npc_id'   => 921,
                    'name'     => '牛魔王',
                    'title'    => '平天大圣',
                    'conditions' => [
                        'daoxing_min' => 300000,
                        'str_min'     => 35,
                    ],
                    'rejection_messages' => [
                        'daoxing' => '道行太浅。',
                        'str'     => '力量太弱，不堪造就。',
                    ],
                ],
                [
                    'npc_id'   => 922,
                    'name'     => '九头驸马',
                    'title'    => '碧波潭驸马',
                    'conditions' => [
                        'daoxing_min' => 200000,
                        'con_min'     => 30,
                    ],
                    'rejection_messages' => [
                        'daoxing' => '修为不够。',
                        'con'     => '体质太差，受不了火云洞的修炼。',
                    ],
                ],
                [
                    'npc_id'   => 923,
                    'name'     => '铁扇公主',
                    'title'    => '罗刹女',
                    'conditions' => [
                        'daoxing_min' => 180000,
                        'int_min'     => 28,
                    ],
                    'rejection_messages' => [
                        'daoxing' => '道行不够。',
                        'int'     => '悟性不足，学不了我的芭蕉扇法。',
                    ],
                ],
            ],
            'ranks'             => ['洞中小妖', '火云弟子', '火将', '火魔', '圣婴大王', '火云洞主'],
            'bonuses'           => [
                'str_bonus'     => 3,
                'fire_resist'   => 30,              // 火系免疫
                'fire_bonus'    => 1.2,             // 火系伤害加成
                'bellicosity_growth' => 1.3,
            ],
        ],

        // ---------------------------------------------------------
        // 10. 大雪山（大鹏明王）—— 妖阵营
        // ---------------------------------------------------------
        'xueshan' => [
            'name'              => '大雪山',
            'short_name'        => '雪山派',
            'family_name'       => '大雪山',
            'alignment'         => -1,              // 妖
            'description'       => '大鹏明王盘踞大雪山，以冰雪之道称霸一方，'
                                  . '门下弟子习登仙大法与寒雪鞭法，凌厉无比。',
            'master_npc'        => '大鹏明王',
            'master_npc_id'     => 910,
            'location'          => '大雪山主峰',
            'location_room_id'  => null,
            'requirements'      => [
                'min_level'     => 1,
                'min_age'       => 0,
                'race'          => null,
                'gender'        => null,
            ],
            'skills'            => [
                'exclusive'     => [
                    'dengxian-dafa' => '登仙大法',   // 法术
                    'snowwhip'      => '寒雪鞭法',   // 鞭法
                ],
                'bonus'         => [
                    'whip'          => 5,
                    'spells'        => 3,
                    'unarmed'       => 2,
                ],
            ],
            'npc_masters'       => [
                [
                    'npc_id'   => 910,
                    'name'     => '大鹏明王',
                    'title'    => '金翅大鹏',
                    'conditions' => [
                        'daoxing_min' => 350000,
                        'str_min'     => 35,
                        'dex_min'     => 30,
                    ],
                    'rejection_messages' => [
                        'daoxing' => '道行太浅，不配做我的弟子。',
                        'str'     => '力量不够，在雪山活不下去。',
                        'dex'     => '身法太差，跟不上大鹏展翅。',
                    ],
                ],
                [
                    'npc_id'   => 924,
                    'name'     => '孔雀明王',
                    'title'    => '佛母',
                    'conditions' => [
                        'daoxing_min' => 400000,
                        'spi_min'     => 35,
                    ],
                    'rejection_messages' => [
                        'daoxing' => '你的修为还差得远。',
                        'spi'     => '灵性不够，感悟不了我的道法。',
                    ],
                ],
                [
                    'npc_id'   => 925,
                    'name'     => '青狮老魔',
                    'title'    => '青狮',
                    'conditions' => [
                        'daoxing_min' => 200000,
                        'str_min'     => 32,
                    ],
                    'rejection_messages' => [
                        'daoxing' => '道行不足。',
                        'str'     => '力量不够，跟不上我的脚步。',
                    ],
                ],
                [
                    'npc_id'   => 926,
                    'name'     => '白象尊者',
                    'title'    => '白象',
                    'conditions' => [
                        'daoxing_min' => 220000,
                        'con_min'     => 32,
                    ],
                    'rejection_messages' => [
                        'daoxing' => '道行太浅。',
                        'con'     => '体质太弱。',
                    ],
                ],
                [
                    'npc_id'   => 927,
                    'name'     => '孔雀公主',
                    'title'    => '孔雀',
                    'conditions' => [
                        'daoxing_min' => 150000,
                        'per_min'     => 25,
                    ],
                    'rejection_messages' => [
                        'daoxing' => '道行不够。',
                        'per'     => '容貌不够，不适合修习我的功法。',
                    ],
                ],
                [
                    'npc_id'   => 928,
                    'name'     => '秃鹰尊者',
                    'title'    => '秃鹰',
                    'conditions' => [
                        'daoxing_min' => 180000,
                        'dex_min'     => 28,
                    ],
                    'rejection_messages' => [
                        'daoxing' => '修为不足。',
                        'dex'     => '身法太差。',
                    ],
                ],
            ],
            'ranks'             => ['雪山游侠', '雪山弟子', '雪山武者', '雪山长老', '雪山宗主', '大鹏弟子'],
            'bonuses'           => [
                'str_bonus'     => 3,
                'ice_resist'    => 30,              // 冰系免疫
                'ice_bonus'     => 1.2,             // 冰系伤害加成
            ],
        ],

    ],

    // =========================================================
    // 通用技能（无门派限制，任何人均可修习）
    // =========================================================
    'common_skills' => [
        // 基础武器（对齐原始项目 xyj2000-php config/chinese.php）
        'unarmed'       => ['name' => '扑击格斗之技', 'type' => 'unarmed'],
        'sword'         => ['name' => '基本剑术', 'type' => 'sword'],
        'blade'         => ['name' => '基本刀法', 'type' => 'blade'],
        'stick'         => ['name' => '基本棍法', 'type' => 'stick'],
        'staff'         => ['name' => '基本杖法', 'type' => 'staff'],
        'spear'         => ['name' => '基本枪法', 'type' => 'spear'],
        'axe'           => ['name' => '基本斧法', 'type' => 'axe'],
        'whip'          => ['name' => '基础鞭术', 'type' => 'whip'],
        'bow'           => ['name' => '基本弓箭', 'type' => 'bow'],
        'dagger'        => ['name' => '短兵刃', 'type' => 'dagger'],
        'fork'          => ['name' => '基本叉法', 'type' => 'fork'],
        'rake'          => ['name' => '基本钯法', 'type' => 'rake'],
        'hammer'        => ['name' => '基本锤法', 'type' => 'hammer'],
        'mace'          => ['name' => '基本锏法', 'type' => 'mace'],
        'archery'       => ['name' => '基本弓箭', 'type' => 'archery'],
        // 通用修炼（对齐原始项目）
        'dodge'         => ['name' => '基本轻功', 'type' => 'dodge'],
        'parry'         => ['name' => '拆招卸力之法', 'type' => 'parry'],
        'force'         => ['name' => '内功心法', 'type' => 'force'],
        'spells'        => ['name' => '法术', 'type' => 'magic'],
        'stealing'      => ['name' => '妙手空空之技', 'type' => 'skill'],
        'literate'      => ['name' => '读书识字', 'type' => 'skill'],
        'makeup'        => ['name' => '养颜术', 'type' => 'skill'],
        'throwing'      => ['name' => '暗器使用', 'type' => 'skill'],
        'fuqin'         => ['name' => '抚琴之技', 'type' => 'skill'],
        'jindouyun'     => ['name' => '筋斗云', 'type' => 'movement'],
    ],

    // =========================================================
    // 背叛门派惩罚配置
    // =========================================================
    'betrayal_penalty' => [
        'exp_loss_percent'       => 30,    // 每次背叛损失经验百分比
        'skill_loss_percent'     => 20,    // 每次背叛损失技能百分比
        'multiplier_per_count'   => 1.5,   // 每多背叛一次，惩罚倍数
        'max_betrayal_count'     => 5,     // 最大可背叛次数（超过无法加入任何门派）
        'cooldown_days'          => 7,     // 背叛后冷却天数（游戏日）
    ],

    // =========================================================
    // 入门基本配置
    // =========================================================
    'join_config' => [
        'max_betrayals_allowed'  => 3,     // 允许的最大背叛次数（超过则被拒绝）
        'entry_quest_required'   => false, // 是否需要完成入门任务
        'exclusive_membership'   => true,  // 是否同一时间只能属于一个门派
    ],

    // =========================================================
    // 阵营对立关系
    // =========================================================
    'alignment_relations' => [
        // 仙（1）与妖（-1）天然对立
        'hostile_pairs' => [
            [1, -1],
        ],
        // 人（0）可与仙/妖均有外交往来
        'neutral'       => [0],
    ],

];

