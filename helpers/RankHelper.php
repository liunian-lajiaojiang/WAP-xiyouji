<?php
/**
 * 头衔系统辅助类
 * 基于原始项目 rankd.c (xyj2000/adm/daemons/rankd.c)
 *
 * 提供基于职业（profession）、道行、武学的头衔称呼系统
 * 颜色配置存储在 config 表（config_key='rank_colors'）
 */

require_once __DIR__ . '/ProfessionHelper.php';
require_once __DIR__ . '/SkillManager.php';

class RankHelper {

    /** @var array|null 颜色配置缓存 */
    private static $colors = null;

    /**
     * 从数据库加载颜色配置
     */
    private static function loadColors(): void {
        $defaults = [
            'female'         => 'HTML_GRN',
            'male'           => 'HTML_HIRED',
            'ghost'          => 'HTML_HIBLU',
            'wiz_female'     => 'HTML_GRN',
            'wiz_male'       => 'HTML_HIWHT',
            'female_default' => 'HTML_MAG',
            'male_default'   => '',
        ];
        $row = Database::queryOne("SELECT config_value FROM config WHERE config_key = 'rank_colors'");
        if ($row && !empty($row['config_value'])) {
            $decoded = json_decode($row['config_value'], true);
            if (is_array($decoded)) {
                self::$colors = array_merge($defaults, $decoded);
                return;
            }
        }
        self::$colors = $defaults;
    }

    /**
     * 用颜色包裹内容
     * @param string $category 颜色类别（female/male/ghost/wiz_female/wiz_male/female_default/male_default）
     * @param string $content 头衔内容
     * @return string 带颜色的头衔
     */
    private static function colorWrap(string $category, string $content): string {
        if (self::$colors === null) {
            self::loadColors();
        }
        $constName = self::$colors[$category] ?? '';
        if ($constName === '' || !defined($constName)) {
            return $content;
        }
        $closeTag = defined('HTML_NOR') ? HTML_NOR : '';
        return constant($constName) . $content . $closeTag;
    }

    /**
     * 解析 rank_info JSON 字符串
     */
    private static function parseRankInfo($char): array {
        if (empty($char['rank_info']) || !is_string($char['rank_info'])) {
            return [];
        }
        $decoded = json_decode($char['rank_info'], true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 获取 literate 技能等级（用于 scholar 头衔判定）
     * 原始项目: ob->query_skill("literate", 1) — 1 表示返回基础等级
     */
    private static function getLiterateLevel(array $char): int {
        $charId = intval($char['id'] ?? 0);
        if ($charId <= 0) {
            return 0;
        }
        return SkillManager::getSkillLevel($charId, 'literate');
    }

    /**
     * 查询玩家称号（核心功能，基于原始项目 rankd.c）
     *
     * @param array $char 玩家数据
     * @param string|null $addedTitle 附加称号（如"英俊"、"聪明"等）
     * @param bool $isGhost 是否为鬼魂
     * @param string|null $wizLevel 巫师等级
     * @return string 玩家称号
     */
    public static function queryRank(
        $char,
        $addedTitle = null,
        $isGhost = false,
        $wizLevel = null
    ) {
        // 鬼魂特殊处理
        if ($isGhost) {
            return self::colorWrap('ghost', '【 鬼  魂 】');
        }

        // 巫师特殊处理
        if ($wizLevel) {
            $gender = $char['gender'] ?? 'male';
            if ($gender === 'female' || $gender === '女性') {
                switch ($wizLevel) {
                    case '(admin)':
                    case '(arch)':
                    case '(wizard)':
                        return self::colorWrap('wiz_female', '【 巫  女 】');
                    case '(apprentice)':
                        return self::colorWrap('wiz_female', '【见习巫女】');
                    case '(immortal)':
                        return self::colorWrap('wiz_female', '【客座巫女】');
                    case '(elder)':
                        return self::colorWrap('wiz_female', '【荣誉玩家】');
                    default:
                        return self::colorWrap('wiz_female', '【 巫  女 】');
                }
            } else {
                switch ($wizLevel) {
                    case '(admin)':
                    case '(arch)':
                    case '(wizard)':
                        return self::colorWrap('wiz_male', '【 巫  师 】');
                    case '(apprentice)':
                        return self::colorWrap('wiz_male', '【见习巫师】');
                    case '(immortal)':
                        return self::colorWrap('wiz_male', '【客座巫师】');
                    case '(elder)':
                        return self::colorWrap('wiz_male', '【荣誉玩家】');
                    default:
                        return self::colorWrap('wiz_male', '【 巫  师 】');
                }
            }
        }

        $gender = $char['gender'] ?? 'male';
        $daoxing = $char['daoxing'] ?? 0;
        $combatExp = $char['combat_exp'] ?? 0;
        $avg = intval(($daoxing + $combatExp) / 2);
        $profession = ProfessionHelper::getProfession($char);
        $literateLevel = self::getLiterateLevel($char);

        // 默认 addedTitle
        $addedTitle = $addedTitle ?? '无名';

        if ($gender === 'female' || $gender === '女性') {
            return self::getFemaleRank($profession, $daoxing, $combatExp, $avg, $addedTitle, $literateLevel);
        } else {
            return self::getMaleRank($profession, $daoxing, $combatExp, $avg, $addedTitle, $literateLevel);
        }
    }

    /**
     * 获取女性称号（对应原始项目 rankd.c 女性 case）
     */
    private static function getFemaleRank(
        string $profession,
        int $daoxing,
        int $combatExp,
        int $avg,
        string $addedTitle,
        int $literateLevel = 0
    ): string {
        switch ($profession) {
            case 'xian':
                if ($daoxing < 1000) {
                    return self::colorWrap('female', '【 玉  女 】');
                } elseif ($daoxing < 10000) {
                    return self::colorWrap('female', '【 小仙姑 】');
                } elseif ($daoxing < 100000) {
                    return self::colorWrap('female', '【 仙  女 】');
                } elseif ($daoxing < 500000) {
                    return self::colorWrap('female', '【' . $addedTitle . ' · 仙子】');
                } else {
                    return self::colorWrap('female', '【' . $addedTitle . ' · 娘娘】');
                }

            case 'yaomo':
                if ($avg < 1000) {
                    return self::colorWrap('female', '【 小妖女 】');
                } elseif ($avg < 10000) {
                    return self::colorWrap('female', '【 妖  女 】');
                } elseif ($avg < 100000) {
                    return self::colorWrap('female', '【 妖  精 】');
                } elseif ($avg < 500000) {
                    return self::colorWrap('female', '【' . $addedTitle . ' · 魔女】');
                } else {
                    return self::colorWrap('female', '【' . $addedTitle . ' · 公主】');
                }

            case 'bonze':
                if ($daoxing < 1000) {
                    return self::colorWrap('female', '【 小尼姑 】');
                } elseif ($daoxing < 10000) {
                    return self::colorWrap('female', '【 小师太 】');
                } elseif ($daoxing < 100000) {
                    return self::colorWrap('female', '【 师  太 】');
                } elseif ($daoxing < 500000) {
                    return self::colorWrap('female', '【' . $addedTitle . ' · 神尼】');
                } else {
                    return self::colorWrap('female', '【' . $addedTitle . ' · 菩萨】');
                }

            case 'taoist':
                if ($daoxing < 1000) {
                    return self::colorWrap('female', '【 女道童 】');
                } elseif ($daoxing < 10000) {
                    return self::colorWrap('female', '【 小道姑 】');
                } elseif ($daoxing < 100000) {
                    return self::colorWrap('female', '【 玄  女 】');
                } elseif ($daoxing < 500000) {
                    return self::colorWrap('female', '【' . $addedTitle . ' · 玄女】');
                } else {
                    return self::colorWrap('female', '【' . $addedTitle . ' · 圣母】');
                }

            case 'dragon':
                if ($avg < 1000) {
                    return self::colorWrap('female', '【 小宫娥 】');
                } elseif ($avg < 10000) {
                    return self::colorWrap('female', '【 宫  女 】');
                } elseif ($avg < 100000) {
                    return self::colorWrap('female', '【 小龙女 】');
                } elseif ($avg < 500000) {
                    return self::colorWrap('female', '【' . $addedTitle . ' · 龙女】');
                } else {
                    return self::colorWrap('female', '【' . $addedTitle . ' · 公主】');
                }

            case 'scholar':
                if ($addedTitle === '无名') {
                    if ($literateLevel < 20) {
                        return self::colorWrap('female', '【 女学童 】');
                    } elseif ($literateLevel < 100) {
                        return self::colorWrap('female', '【 才  女 】');
                    } else {
                        return self::colorWrap('female', '【 女学士 】');
                    }
                } else {
                    if ($literateLevel < 20) {
                        return self::colorWrap('female', '【 女学童 】');
                    } elseif ($literateLevel < 100) {
                        return self::colorWrap('female', '【' . $addedTitle . ' · 秀才】');
                    } else {
                        return self::colorWrap('female', '【' . $addedTitle . ' · 学士】');
                    }
                }

            case 'fighter':
                if ($combatExp < 1000) {
                    return self::colorWrap('female', '【 女  兵 】');
                } elseif ($combatExp < 10000) {
                    return self::colorWrap('female', '【 女参将 】');
                } elseif ($combatExp < 100000) {
                    return self::colorWrap('female', '【 女将军 】');
                } elseif ($combatExp < 500000) {
                    return self::colorWrap('female', '【' . $addedTitle . ' · 大将军】');
                } else {
                    return self::colorWrap('female', '【' . $addedTitle . ' · 大元帅】');
                }

            case 'youling':
                if ($avg < 1000) {
                    return self::colorWrap('female', '【阴曹小鬼】');
                } elseif ($avg < 10000) {
                    return self::colorWrap('female', '【迷魂女鬼】');
                } elseif ($avg < 100000) {
                    return self::colorWrap('female', '【幽冥女使】');
                } elseif ($avg < 500000) {
                    return self::colorWrap('female', '【' . $addedTitle . ' · 无常】');
                } else {
                    return self::colorWrap('female', '【' . $addedTitle . ' · 女王】');
                }

            case 'dancer':
                return self::colorWrap('female', '【 舞  妓 】');

            default:
                return self::colorWrap('female_default', '【 平  民 】');
        }
    }

    /**
     * 获取男性称号（对应原始项目 rankd.c 男性 case）
     */
    private static function getMaleRank(
        string $profession,
        int $daoxing,
        int $combatExp,
        int $avg,
        string $addedTitle,
        int $literateLevel = 0
    ): string {
        switch ($profession) {
            case 'xian':
                if ($daoxing < 1000) {
                    return self::colorWrap('male', '【 仙  童 】');
                } elseif ($daoxing < 10000) {
                    return self::colorWrap('male', '【 散  仙 】');
                } elseif ($daoxing < 100000) {
                    return self::colorWrap('male', '【 大  仙 】');
                } elseif ($daoxing < 500000) {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 大仙】');
                } elseif ($daoxing < 1000000) {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 真君】');
                } else {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 天尊】');
                }

            case 'yaomo':
                if ($avg < 1000) {
                    return self::colorWrap('male', '【 小  妖 】');
                } elseif ($avg < 10000) {
                    return self::colorWrap('male', '【 妖  怪 】');
                } elseif ($avg < 100000) {
                    return self::colorWrap('male', '【 妖  仙 】');
                } elseif ($avg < 500000) {
                    return self::colorWrap('male', '【 ' . $addedTitle . ' · 怪 】');
                } elseif ($avg < 1000000) {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 老魔】');
                } else {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 魔王】');
                }

            case 'bonze':
                if ($daoxing < 1000) {
                    return self::colorWrap('male', '【 小和尚 】');
                } elseif ($daoxing < 10000) {
                    return self::colorWrap('male', '【 和  尚 】');
                } elseif ($daoxing < 100000) {
                    return self::colorWrap('male', '【 圣  僧 】');
                } elseif ($daoxing < 500000) {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 尊者】');
                } elseif ($daoxing < 1000000) {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 罗汉】');
                } else {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 菩萨】');
                }

            case 'taoist':
                if ($daoxing < 1000) {
                    return self::colorWrap('male', '【 小道士 】');
                } elseif ($daoxing < 10000) {
                    return self::colorWrap('male', '【 道  士 】');
                } elseif ($daoxing < 100000) {
                    return self::colorWrap('male', '【 道  长 】');
                } elseif ($daoxing < 500000) {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 天师】');
                } elseif ($daoxing < 1000000) {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 真人】');
                } else {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 天尊】');
                }

            case 'dragon':
                if ($avg < 1000) {
                    return self::colorWrap('male', '【 虾  兵 】');
                } elseif ($avg < 10000) {
                    return self::colorWrap('male', '【 蟹  将 】');
                } elseif ($avg < 100000) {
                    return self::colorWrap('male', '【巡海夜叉】');
                } elseif ($avg < 500000) {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 夜叉】');
                } elseif ($avg < 1000000) {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 龙】');
                } else {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 龙王】');
                }

            case 'scholar':
                if ($addedTitle === '无名') {
                    if ($literateLevel < 20) {
                        return self::colorWrap('male', '【 童  生 】');
                    } elseif ($literateLevel < 100) {
                        return self::colorWrap('male', '【 秀  才 】');
                    } else {
                        return self::colorWrap('male', '【 大学士 】');
                    }
                } else {
                    if ($literateLevel < 20) {
                        return self::colorWrap('male', '【 童  生 】');
                    } elseif ($literateLevel < 100) {
                        return self::colorWrap('male', '【' . $addedTitle . ' · 秀才】');
                    } else {
                        return self::colorWrap('male', '【' . $addedTitle . ' · 学士】');
                    }
                }

            case 'fighter':
                if ($combatExp < 1000) {
                    return self::colorWrap('male', '【 小  兵 】');
                } elseif ($combatExp < 10000) {
                    return self::colorWrap('male', '【 小  校 】');
                } elseif ($combatExp < 100000) {
                    return self::colorWrap('male', '【 参  将 】');
                } elseif ($combatExp < 500000) {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 将军】');
                } elseif ($combatExp < 1000000) {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 大将军】');
                } else {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 大元帅】');
                }

            case 'youling':
                if ($avg < 1000) {
                    return self::colorWrap('male', '【阴曹小鬼】');
                } elseif ($avg < 10000) {
                    return self::colorWrap('male', '【勾魂使者】');
                } elseif ($avg < 100000) {
                    return self::colorWrap('male', '【地府判官】');
                } elseif ($avg < 500000) {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 无常】');
                } elseif ($avg < 1000000) {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 鬼王】');
                } else {
                    return self::colorWrap('male', '【' . $addedTitle . ' · 王】');
                }

            default:
                return self::colorWrap('male_default', '【 平  民 】');
        }
    }

    /**
     * 查询尊敬称呼（用于切磋）
     * @param array $target 目标角色/NPC数据
     * @return string 尊敬称呼
     */
    public static function queryRespect($target) {
        // 优先使用自定义称呼
        $rankInfo = self::parseRankInfo($target);
        if (!empty($rankInfo['respect'])) {
            return $rankInfo['respect'];
        }

        $gender = $target['gender'] ?? 'male';
        $age = intval($target['age'] ?? 30);
        $profession = ProfessionHelper::getProfession($target);

        if ($gender === 'female' || $gender === '女性') {
            switch ($profession) {
                case 'bonze':
                    return $age < 18 ? '小师太' : '师太';
                case 'taoist':
                    return $age < 18 ? '小仙姑' : '仙姑';
                case 'xian':
                    return $age < 18 ? '小仙姑' : '仙姑';
                default:
                    if ($age < 18) return '小姑娘';
                    if ($age < 50) return '姑娘';
                    return '婆婆';
            }
        } else {
            switch ($profession) {
                case 'xian':
                    if ($age < 18) return '小神仙';
                    if ($age < 50) return '仙兄';
                    return '老神仙';
                case 'bonze':
                    if ($age < 18) return '小师父';
                    if ($age < 50) return '大师';
                    return '长老';
                case 'taoist':
                    if ($age < 18) return '小道爷';
                    if ($age < 50) return '道兄';
                    return '道长';
                case 'fighter':
                    if ($age < 18) return '小将军';
                    if ($age < 50) return '大将军';
                    return '老将军';
                case 'scholar':
                    if ($age < 18) return '小相公';
                    if ($age < 50) return '相公';
                    return '老先生';
                case 'swordsman':
                    if ($age < 18) return '小老弟';
                    if ($age < 50) return '壮士';
                    return '老前辈';
                default:
                    if ($age < 18) return '小兄弟';
                    if ($age < 50) return '壮士';
                    return '老爷子';
            }
        }
    }

    /**
     * 查询粗鲁称呼（用于击杀）
     * @param array $target 目标角色/NPC数据
     * @return string 粗鲁称呼
     */
    public static function queryRude($target) {
        // 优先使用自定义称呼
        $rankInfo = self::parseRankInfo($target);
        if (!empty($rankInfo['rude'])) {
            return $rankInfo['rude'];
        }

        $gender = $target['gender'] ?? 'male';
        $age = intval($target['age'] ?? 30);
        $profession = ProfessionHelper::getProfession($target);

        if ($gender === 'female' || $gender === '女性') {
            switch ($profession) {
                case 'xian':
                    if ($age < 30) return '小妖精';
                    if ($age < 50) return '妖女';
                    return '老妖婆';
                case 'yaomo':
                    if ($age < 30) return '小妖女';
                    if ($age < 50) return '妖女';
                    return '老妖婆';
                case 'bonze':
                    if ($age < 30) return '小贼尼';
                    if ($age < 50) return '贼尼';
                    return '老贼尼';
                case 'taoist':
                    if ($age < 30) return '小妖女';
                    if ($age < 50) return '妖女';
                    return '老妖婆';
                default:
                    if ($age < 30) return '小贱人';
                    if ($age < 50) return '贱人';
                    return '死老太婆';
            }
        } else {
            switch ($profession) {
                case 'xian':
                    return $age < 50 ? '死妖怪' : '老妖怪';
                case 'yaomo':
                    return $age < 50 ? '死妖怪' : '老妖怪';
                case 'bonze':
                    return $age < 50 ? '死秃驴' : '老秃驴';
                case 'taoist':
                    return '死牛鼻子';
                case 'scholar':
                    if ($age < 18) return '小书呆子';
                    if ($age < 50) return '臭书呆子';
                    return '老童生';
                default:
                    if ($age < 18) return '小王八蛋';
                    if ($age < 50) return '臭贼';
                    return '老匹夫';
            }
        }
    }

    /**
     * 查询自称（用于切磋）
     * @param array $attacker 攻击者数据
     * @return string 自称
     */
    public static function querySelf($attacker) {
        // 优先使用自定义称呼
        $rankInfo = self::parseRankInfo($attacker);
        if (!empty($rankInfo['self'])) {
            return $rankInfo['self'];
        }

        $gender = $attacker['gender'] ?? 'male';
        $age = intval($attacker['age'] ?? 30);
        $profession = ProfessionHelper::getProfession($attacker);

        if ($gender === 'female' || $gender === '女性') {
            switch ($profession) {
                case 'bonze':
                    return $age < 50 ? '贫尼' : '老尼';
                default:
                    return $age < 30 ? '小女子' : '妾身';
            }
        } else {
            switch ($profession) {
                case 'bonze':
                    return $age < 50 ? '贫僧' : '老纳';
                case 'taoist':
                    return '贫道';
                case 'scholar':
                    return $age < 30 ? '晚生' : '不才';
                default:
                    return $age < 50 ? '在下' : '老头子';
            }
        }
    }

    /**
     * 查询粗鲁自称（用于击杀）
     * @param array $attacker 攻击者数据
     * @return string 粗鲁自称
     */
    public static function querySelfRude($attacker) {
        // 优先使用自定义称呼
        $rankInfo = self::parseRankInfo($attacker);
        if (!empty($rankInfo['self_rude'])) {
            return $rankInfo['self_rude'];
        }

        $gender = $attacker['gender'] ?? 'male';
        $age = intval($attacker['age'] ?? 30);
        $profession = ProfessionHelper::getProfession($attacker);

        if ($gender === 'female' || $gender === '女性') {
            switch ($profession) {
                case 'bonze':
                    return $age < 50 ? '贫尼' : '老尼';
                default:
                    return $age < 50 ? '本姑娘' : '老娘';
            }
        } else {
            switch ($profession) {
                case 'bonze':
                    return $age < 50 ? '大和尚我' : '老和尚我';
                case 'taoist':
                    return '本山人';
                case 'scholar':
                    return $age < 50 ? '本相公' : '老夫子我';
                default:
                    if ($age < 18) return '你家小爷我';
                    if ($age < 50) return '大爷我';
                    return '你爷爷我';
            }
        }
    }
}
