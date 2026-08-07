<?php
/**
 * 战斗伤害消息配置
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 *
 * 支持8种伤害类型： scratch, slash, pierce, bash, blunt, crush, internal, whip
 *
 * 消息占位符说明
 *   $n - 对方名称
 *   $p - 对方的（所属格子）
 *   $l - 肢体部位
 *   $N - 自己名称
 *   $w - 武器名称
 */
class CombatMessages {

    /**
     * 擦伤/抓伤/割伤消息（scratch类型）
     */
    private static array $scratchMessages = [
        ['max' => 10, 'msg' => '结果只是轻轻地划过$p的皮肉。'],
        ['max' => 20, 'msg' => '结果划过$p的$l，留下一道细长的血痕。'],
        ['max' => 40, 'msg' => '结果「嗤」地一声划出一道伤口！'],
        ['max' => 80, 'msg' => '结果「嗤」地一声划出一道血淋淋的伤口！'],
        ['max' => 160, 'msg' => '结果「嗤」地一声划出一道又长又深的伤口，溅满$N满脸鲜血。'],
        ['max' => PHP_INT_MAX, 'msg' => '结果只听$n一声惨嚎，$p的$l被划出一道深及见骨的可怕伤口！'],
    ];

    /**
     * 砍伤/劈伤消息（slash类型）
     */
    private static array $slashMessages = [
        ['max' => 10, 'msg' => '结果只是轻轻地砍过$n的皮肉。'],
        ['max' => 20, 'msg' => '结果砍过$n的$l，留下一道细长的血痕。'],
        ['max' => 40, 'msg' => '结果「噗嗤」一声劈出一道血淋淋的伤口！'],
        ['max' => 80, 'msg' => '结果只听「噗」地一声，$n的$l被劈得血如泉涌，痛得$p咬牙切齿。'],
        ['max' => 160, 'msg' => '结果「噗」地一声砍出一道又长又深的伤口，溅满$N满脸鲜血。'],
        ['max' => PHP_INT_MAX, 'msg' => '结果只听$n一声惨嚎，$p的$l被劈开一道深及见骨的可怕伤口！'],
    ];

    /**
     * 刺伤/枪伤消息（pierce类型）
     */
    private static array $pierceMessages = [
        ['max' => 10, 'msg' => '结果只是轻轻地刺过$p的皮肉。'],
        ['max' => 20, 'msg' => '结果刺过$p的$l，留下一道创口。'],
        ['max' => 40, 'msg' => '结果「噗」地一声刺入了$n的$l寸许。'],
        ['max' => 80, 'msg' => '结果「噗」地一声刺中了$n的$l，使$p不由自主地退了步。'],
        ['max' => 160, 'msg' => '结果「噗嗤」地一声，$w已在$p的$l刺出一个血肉模糊的血窟窿。'],
        ['max' => PHP_INT_MAX, 'msg' => '结果只听$n一声惨嚎，$w已在$p的$l对穿而出，鲜血溅得满地。'],
    ];

    /**
     * 筑伤消息（bash类型）
     */
    private static array $bashMessages = [
        ['max' => 10, 'msg' => '结果只是轻轻地一触，$n的皮肤上留下一点白痕。'],
        ['max' => 20, 'msg' => '结果$p的$l留下几道血痕。'],
        ['max' => 40, 'msg' => '结果一下子筑中了$n，顿时出现几个血孔！'],
        ['max' => 80, 'msg' => '结果一下子筑中了$n，立刻血流如注！'],
        ['max' => 120, 'msg' => '结果「哧」地一声，$n顿时鲜血飞溅。'],
        ['max' => 160, 'msg' => '结果这一下「哧」地一声，$n被筑得浑身是血。'],
        ['max' => PHP_INT_MAX, 'msg' => '结果「哧」重重地砸中了$n，被筑得千疮百孔，血肉四处横飞！'],
    ];

    /**
     * 掌伤/拳伤/瘀伤消息（blunt类型）
     */
    private static array $bluntMessages = [
        ['max' => 10, 'msg' => '结果只是轻轻地碰到，比拍苍蝇稍微重了点。'],
        ['max' => 20, 'msg' => '结果$p的$l造成一处瘀青。'],
        ['max' => 40, 'msg' => '结果一击命中，$n的$l登时肿了一块老高。'],
        ['max' => 80, 'msg' => '结果一击命中，$n闷哼了一声显然吃了不小的亏！'],
        ['max' => 120, 'msg' => '结果「砰」地一声，$n退了两步！'],
        ['max' => 160, 'msg' => '结果这一下「砰」地一声打中了$n，连退了好几步，差一点摔倒。'],
        ['max' => 240, 'msg' => '结果重重地击中，$n「哇」地一声吐出一口鲜血。'],
        ['max' => PHP_INT_MAX, 'msg' => '结果只听见「砰」地一声巨响，$n像一捆稻草般飞了出去。'],
    ];

    /**
     * 撞伤/砸伤消息（crush类型）
     */
    private static array $crushMessages = [
        ['max' => 10, 'msg' => '结果只是轻轻地碰到，等于轻轻地搔了一下痒。'],
        ['max' => 20, 'msg' => '结果$p的$l砸出一个小臌包。'],
        ['max' => 40, 'msg' => '结果砸个正着，$n的$l登时肿了一块老高。'],
        ['max' => 80, 'msg' => '结果砸个正着，$n闷哼一声显然吃了不小的亏！'],
        ['max' => 120, 'msg' => '结果「砰」地一声，$n疼得连腰都弯了！'],
        ['max' => 160, 'msg' => '结果这一下「轰」地一声砸中了$n，眼冒金星，差一点摔倒。'],
        ['max' => 240, 'msg' => '结果重重地砸中，$n眼前一黑，「哇」地一声吐出一口鲜血。'],
        ['max' => PHP_INT_MAX, 'msg' => '结果只听见「轰」地一声巨响，$n被砸得血肉模糊，惨不忍睹。'],
    ];

    /**
     * 震伤/内伤消息（internal类型）
     */
    private static array $internalMessages = [
        ['max' => 20, 'msg' => '结果$n身上一触即逝，等于轻轻地搔了一下痒。'],
        ['max' => 40, 'msg' => '结果$n晃了一晃，吃了点小亏。'],
        ['max' => 80, 'msg' => '结果$n气息一窒，显然有点呼吸不畅。'],
        ['max' => 120, 'msg' => '结果$n体内一阵剧痛，看起来内伤不轻！'],
        ['max' => 160, 'msg' => '结果「嗡」地一声，$n只觉得眼前一黑，双耳轰鸣不止！'],
        ['max' => PHP_INT_MAX, 'msg' => '结果只听见「嗡」地一声巨响，$n「哇」地一声吐出一口鲜血，五脏六腑都错了位！'],
    ];

    /**
     * 鞭伤/抽伤消息（whip类型）
     */
    private static array $whipMessages = [
        ['max' => 10, 'msg' => '结果只是轻轻地抽过$n的皮肉。'],
        ['max' => 20, 'msg' => '结果抽过$n的$l，留下一道轻微的紫痕。'],
        ['max' => 40, 'msg' => '结果「啪」地一声在$n的$l抽出一道长长的血痕！'],
        ['max' => 80, 'msg' => '结果只听「啪」地一声，$n的$l被抽得皮开肉绽，痛得$p咬牙切齿。'],
        ['max' => 160, 'msg' => '结果「啪」地一声爆响！这一下好厉害，只抽得$n皮开肉绽，血花飞溅！'],
        ['max' => PHP_INT_MAX, 'msg' => '结果只听$n一声惨嚎，$w重重地抽上了$p的$l，$n顿时血肉横飞，十命断了九条。'],
    ];

    /**
     * 中文伤害类型 -> 英文基础类型映射
     * skill_actions 表中6种中文伤害类型名映射1种基础类型
     *
     * @param string $chineseType 中文伤害类型
     * @return string 英文基础类型
     */
    public static function mapDamageType(string $chineseType): string {
        static $typeMap = [
            '刺伤' => 'pierce',
            '枪伤' => 'pierce',
            '戳伤' => 'pierce',
            '割伤' => 'scratch',
            '擦伤' => 'scratch',
            '抓伤' => 'scratch',
            '划伤' => 'scratch',
            '撕裂' => 'slash',
            '砍伤' => 'slash',
            '劈伤' => 'slash',
            '砸伤' => 'crush',
            '撞伤' => 'crush',
            '瘀伤' => 'blunt',
            '掌伤' => 'blunt',
            '拳伤' => 'blunt',
            '筑伤' => 'bash',
            '暗伤' => 'internal',
            '震伤' => 'internal',
            '内伤' => 'internal',
            '挫伤' => 'internal',
            '鞭伤' => 'whip',
            '抽伤' => 'whip',
        ];

        // 如果已经是英文类型，直接返回
        $englishTypes = ['scratch', 'slash', 'pierce', 'bash', 'blunt', 'crush', 'internal', 'whip'];
        if (in_array($chineseType, $englishTypes, true)) {
            return $chineseType;
        }

        return $typeMap[$chineseType] ?? 'blunt';
    }

    /**
     * 根据伤害值和类型获取消息
     *
      * @param int $damage 伤害值
      * @param string $type 伤害类型（英文或中文均可）
     * @return string 伤害消息
     */
    public static function getDamageMessage(int $damage, string $type = 'blunt'): string {
        $normalizedType = self::mapDamageType($type);
        $messages = self::getMessagesByType($normalizedType);

        foreach ($messages as $msgData) {
            if ($damage < $msgData['max']) {
                return $msgData['msg'];
            }
        }

        // 默认消息
        return '结果造成了严重的伤害。';
    }

    /**
     * 根据类型获取消息数组
     */
    private static function getMessagesByType(string $type): array {
        switch ($type) {
            case 'scratch':  return self::$scratchMessages;
            case 'slash':    return self::$slashMessages;
            case 'pierce':   return self::$pierceMessages;
            case 'bash':     return self::$bashMessages;
            case 'blunt':    return self::$bluntMessages;
            case 'crush':    return self::$crushMessages;
            case 'internal': return self::$internalMessages;
            case 'whip':     return self::$whipMessages;
            default:         return self::$bluntMessages;
        }
    }

    /**
     * 获取警戒消息（战斗开始时）
     */
    public static function getGuardMessage(): string {
        $messages = [
            '注视着对方的行动，企图寻找机会出手。',
            '正盯着对方的一举一动，随时准备发动攻势。',
            '缓缓地移动脚步，想要找出对方的破绽。',
            '目不转睛地盯着对方的动作，寻找进攻的最佳时机。',
            '慢慢地移动着脚步，伺机出手。',
        ];

        return $messages[array_rand($messages)];
    }

    /**
     * 获取仇杀消息
     */
    public static function getCatchHuntMessage(string $attacker, string $target): string {
        $messages = [
            "{$attacker}和{$target}仇人相见份外眼红，立刻打了起来！",
            "{$attacker}对着{$target}大喝：「可恶，又是你！」",
            "{$attacker}和{$target}一碰面，二话不说就打了起来！",
            "{$attacker}一眼瞥见{$target}，「哼」的一声冲了过来！",
            "{$attacker}一眼瞥见{$target}，「哼」的一声冲了过来！",
            "{$attacker}一见到{$target}，愣了一愣，大叫：「我宰了你！」",
            "{$attacker}喝道：「{$target}，我们的帐还没算完，看招！」",
            "{$attacker}喝道：「{$target}，看招！」",
        ];

        return $messages[array_rand($messages)];
    }

    /**
     * 获取胜利消息（切磋结束时）
     * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
     *
     * @param string $winner 胜利者名
     * @param string $loser 失败者名
     * @return string 胜负消息
     */
    public static function getWinnerMessage(string $winner, string $loser): string {
        $messages = [
            // 胜利者说话（3条）
            "\n" . HIM . $winner . NOR . "哈哈大笑，说道：承让了！\n",
            "\n" . HIM . $winner . NOR . "双手一拱，笑着说：承让！\n",
            "\n" . HIM . $winner . NOR . "胜了这招，向后跃开三尺，笑着说：承让！\n",
            // 失败者说话（3条）
            "\n" . HIM . $loser . NOR . "脸色微变，说道：佩服，佩服！\n",
            "\n" . HIM . $loser . NOR . "向后退了几步，说道：这场比试算我输了，佩服，佩服！\n",
            "\n" . HIM . $loser . NOR . "向后一纵，躬身做揖说道：阁下武艺不凡，果然高明！\n",
        ];

        return $messages[array_rand($messages)];
    }
}

