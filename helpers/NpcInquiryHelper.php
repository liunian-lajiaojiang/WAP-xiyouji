<?php
/**
 * NPC询问回调处理器
 * 处理inquiry中["callable", "method_name"]格式的动态回答
 * 
 * 当NPC的inquiry配置值为callable类型时，由本类负责分发和处理。
 * callable格式: ["callable", "method_name"] 或 ["callable", "method_name", "extra_param"]
 * 
 * 使用方式:
 * 1. 在NPC的inquiry JSON中配置: {"治疗": ["callable", "ask_cure"]}
 * 2. 在本类的$handlers映射中注册对应的处理方法
 * 3. 处理方法接收 ($npc, $char, $topic) 参数，返回字符串或null
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../daemons/QujingHandler.php';
require_once __DIR__ . '/ProfessionHelper.php';
require_once __DIR__ . '/SkillManager.php';

class NpcInquiryHelper {
    
    /**
     * 处理callable类型的inquiry
     * 
     * @param array $callableConfig ["callable", "method_name"] 或 ["callable", "method_name", "extra_param"]
     * @param array $npc NPC数据
     * @param array $char 玩家数据
     * @param string $topic 询问话题
     * @return string|array|null 回答内容，null表示无法处理，数组表示包含重定向信息
     */
    public static function handleCallable(array $callableConfig, array $npc, array $char, string $topic)
    {
        // 获取method名称
        $method = $callableConfig[1] ?? '';
        if (empty($method)) {
            return null;
        }
        
        // 获取额外参数（可选）
        $extraParam = $callableConfig[2] ?? null;
        
        // 从预定义handler映射中查找
        $handlers = self::getHandlers();
        
        if (isset($handlers[$method])) {
            $result = call_user_func($handlers[$method], $npc, $char, $topic, $extraParam);
            // 允许返回字符串或数组，null表示处理失败
            if ($result === null) {
                return null;
            }
            return $result;
        }
        
        // 如果没有找到预定义handler，返回null
        return null;
    }
    
    /**
     * 获取已注册的handler映射表
     * 每个handler签名为: function(array $npc, array $char, string $topic, $extraParam = null): ?string
     * 
     * @return array handler映射表
     */
    protected static function getHandlers(): array
    {
        return [
            // 通用handler
            'ask_cure'   => [self::class, 'handleCure'],
            'ask_sell'   => [self::class, 'handleSell'],
            'ask_quest'  => [self::class, 'handleQuest'],
            'work_me'    => [self::class, 'handleWork'],
            // 门派相关
            'ask_sect'   => [self::class, 'handleSect'],
            'ask_apprentice' => [self::class, 'handleApprentice'],
            // 商店/交易相关
            'ask_shop'   => [self::class, 'handleShop'],
            'ask_bank'   => [self::class, 'handleBank'],
            // 通用信息
            'ask_name'   => [self::class, 'handleName'],
            'ask_here'   => [self::class, 'handleHere'],
            // 邮差千里眼相关
            'send_mail'   => [self::class, 'handleSendMail'],
            'receive_mail' => [self::class, 'handleReceiveMail'],
            'send_dianbo' => [self::class, 'handleSendDianbo'],
            // 医疗系统
            'ask_medicine' => [self::class, 'handleMedicine'],
            'heal_me' => [self::class, 'handleMedicine'],
            // 灭妖系统
            'ask_demon_hunt' => [self::class, 'handleDemonHunt'],
            // 转业/离开门派
            'ask_career_change' => [self::class, 'handleCareerChange'],
            // 俸银系统
            'ask_salary' => [self::class, 'handleSalary'],
            // 功名/科举系统
            'ask_fame' => [self::class, 'handleFame'],
            'apply_gongming' => [self::class, 'handleFame'],
            // 申请儒生（贺知章处）
            'ask_scholar' => [self::class, 'handleScholar'],
            // 送饭任务
            'ask_deliver_food' => [self::class, 'handleDeliverFood'],
            // 李玉娘送饭给袁天罡
            'fan_me' => [self::class, 'handleFanMe'],
            // 送书
            'give_book' => [self::class, 'handleGiveBook'],
            'ask_give_book' => [self::class, 'handleGiveBook'],
            // 取经人申请系统（疥顶小僧）
            'apply_qujingren' => [self::class, 'handleApplyQujingren'],
            'qujingren_status' => [self::class, 'handleQujingrenStatus'],
            'qujingren_candidates' => [self::class, 'handleQujingrenCandidates'],
            // 舞妓/歌妓（公孙大娘：apply_dancer=申请舞妓, answer_leaving=离开舞妓坊）
            'ask_dancer' => [self::class, 'handleDancer'],
            'apply_dancer' => [self::class, 'handleDancer'],
            // 离开（公孙大娘：离开舞妓坊/秦琼&阎罗王：离开门派）
            'ask_leaving' => [self::class, 'handleLeaving'],
            'answer_leaving' => [self::class, 'handleLeaving'],
            'expell_me' => [self::class, 'handleCareerChange'],
            // 治病诊断/朱紫国国王任务（根据NPC身份分发）
            'test_player' => [self::class, 'handleTestPlayer'],
            // 拱猪游戏 (ask_pig 和 ask_pig_game 均映射到同一handler)
            'ask_pig_game' => [self::class, 'handlePigGame'],
            'ask_pig'      => [self::class, 'handlePigGame'],
            // 皤不分传送 / 秦安发放俸银（根据NPC身份分发）
            'try_me' => [self::class, 'handleTryMe'],
            // 阎罗王地狱传送
            'send_me' => [self::class, 'handleSendMe'],
            // 掌门大弟子申请
            'zm_apply' => [self::class, 'handleZmApply'],
            // 广羲子讲经
            'ask_qianziwen' => [self::class, 'handleQianZiWen'],
            'ask_daodejing' => [self::class, 'handleDaoDeJing'],
            // 精卫填海
            'jingwei_fill_sea' => [self::class, 'handleJingweiFillSea'],
            // 取经系统
            'qujing_ask_for_help' => [self::class, 'handleQujingAskForHelp'],
            'qujing_apply_escort' => [self::class, 'handleQujingApplyEscort'],
            // 青髯老人给书
            'give_it' => [self::class, 'handleGiveIt'],
            'ask_tianmojian' => [self::class, 'handleTianmojian'],
            'ask_laoren' => [self::class, 'handleLaoren'],
            // 喜福会喜宴系统
            'ask_party' => [self::class, 'handleAskParty'],
            'ask_money' => [self::class, 'handleAskMoney'],
            // 唐僧取经对话系统
            'qujing' => [self::class, 'handleQujingTopic'],
            'husong' => [self::class, 'handleQujingEscort'],
            'xitian' => [self::class, 'handleQujingXitian'],
            'lingshan' => [self::class, 'handleQujingXitian'],
            'rulai' => [self::class, 'handleQujingRulai'],
            'nan' => [self::class, 'handleQujingNan'],
            // 各关卡话题
            'yingchou' => [self::class, 'handleQujingObstacle'],
            'baoxiang' => [self::class, 'handleQujingObstacle'],
            'pingding' => [self::class, 'handleQujingObstacle'],
            'wuji' => [self::class, 'handleQujingObstacle'],
            'chechi' => [self::class, 'handleQujingObstacle'],
            'tongtian' => [self::class, 'handleQujingObstacle'],
            'jindou' => [self::class, 'handleQujingObstacle'],
            'nuerguo' => [self::class, 'handleQujingObstacle'],
            'firemount' => [self::class, 'handleQujingObstacle'],
            'jisaiguo' => [self::class, 'handleQujingObstacle'],
            'xiaoxitian' => [self::class, 'handleQujingObstacle'],
            'zhuzi' => [self::class, 'handleQujingObstacle'],
            'pansi' => [self::class, 'handleQujingObstacle'],
            'biqiu' => [self::class, 'handleQujingObstacle'],
            'wudidong' => [self::class, 'handleQujingObstacle'],
            'qinfa' => [self::class, 'handleQujingObstacle'],
            'tianzhu' => [self::class, 'handleQujingObstacle'],
            // 袁守诚算命系统
            'suanming' => [self::class, 'handleSuanming'],
            'suan_fuyuan' => [self::class, 'handleSuanFuyuan'],
            'suan_rsg' => [self::class, 'handleSuanRsg'],
            // 广羲子借书系统
            'borrow_me' => [self::class, 'handleBorrowMe'],
            'borr_me' => [self::class, 'handleBorrMe'],
            // 寿星碧藕挑战系统
            'ask_me' => [self::class, 'handleAskMe'],
            // 火焰山相关
            'firemount_tudi_bone' => [self::class, 'handleFiremountTudiBone'],
            'firemount_brother_introduce' => [self::class, 'handleFiremountBrotherIntroduce'],
            'firemount_brother_bone' => [self::class, 'handleFiremountBrotherBone'],
            'firemount_princess_fan' => [self::class, 'handleFiremountPrincessFan'],
            // 武状元系统（房玄龄）
            'ask_wuzhuangyuan' => [self::class, 'handleWuzhuangyuan'],
            'wuzhuangyuan' => [self::class, 'handleWuzhuangyuan'],
            // 蟠桃会系统（太白金星）
            'ask_pantaohui' => [self::class, 'handlePantaohui'],
            'pantaohui' => [self::class, 'handlePantaohui'],
            'fantao' => [self::class, 'handlePantaohui'],
            'pantaohui_challenge' => [self::class, 'handlePantaohuiChallenge'],
            // 星宿系统
            'ask_xingxiu' => [self::class, 'handleXingxiu'],
            'xingxiu' => [self::class, 'handleXingxiu'],
            'xingxiu_challenge' => [self::class, 'handleXingxiuChallenge'],
            // 龙珠系统（龙女）
            'ask_longzhu' => [self::class, 'handleLongzhu'],
            'longzhu' => [self::class, 'handleLongzhu'],
            // 通用execute处理（太白金星等NPC使用）
            'execute_help' => [self::class, 'handleExecuteHelp'],
            'execute_ask' => [self::class, 'handleExecuteAsk'],
        ];
    }
    
    /**
     * 注册自定义handler
     * 允许外部代码动态添加handler而不修改本文件
     * 
     * @param string $method 方法名
     * @param callable $handler 处理函数
     */
    public static function registerHandler(string $method, callable $handler): void
    {
        // 通过静态属性支持动态注册
        self::$customHandlers[$method] = $handler;
    }
    
    /** @var array 自定义handler存储 */
    private static array $customHandlers = [];
    
    // =========================================================
    // 预定义通用handlers
    // =========================================================
    
    /**
     * 处理毒伤诊断（五福道长专用）
     * 对应原始 LPC：/d/city/npc/Daozhang.php → test_player()
     *
     * 列出玩家身上的中毒和伤势详情，计算治疗费用
     * 原始项目支持的毒种类：蛇毒、虫毒、蝎毒、瘴毒、尸毒、花毒等
     */
    private static function handleCureDiagnosis(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '五福道长';
        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';

        // 已知的毒种类
        $poisonTypes = [
            'snake_poison' => ['name' => '蛇毒', 'base' => 5],
            'insect_poison' => ['name' => '虫毒', 'base' => 5],
            'scorpion_poison' => ['name' => '蝎毒', 'base' => 5],
            'miasma' => ['name' => '瘴毒', 'base' => 8],
            'corpse_poison' => ['name' => '尸毒', 'base' => 10],
            'flower_poison' => ['name' => '花毒', 'base' => 5],
        ];

        $msg = HTML_HICYN . "{$npcName}拿出纸笔，仔细诊断{$charName}身上的各种毒伤。" . HTML_NOR . "\n";
        $msg .= HTML_HICYN . "片刻之间，{$npcName}开口说话了。" . HTML_NOR . "\n\n";

        $total = 0;
        $hasIssue = false;

        // 查询玩家的中毒状态（从 character_temp_states 表）
        foreach ($poisonTypes as $key => $info) {
            $state = Database::queryOne(
                "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?",
                [$charId, 'poison_' . $key]
            );
            $duration = $state ? intval($state['state_value']) : 0;

            if ($duration > 0) {
                $amount = $info['base'] * $duration;
                $msg .= HTML_HICYN . "{$info['name']}共" . self::chineseNumber($amount) . "点中毒量。" . HTML_NOR . "\n";
                $total += $amount;
                $hasIssue = true;
            }
        }

        // 检查气血伤势
        $effKee = intval($char['eff_kee'] ?? 0);
        $maxKee = intval($char['max_kee'] ?? 100);
        if ($effKee < $maxKee) {
            $damage = $maxKee - $effKee;
            $msg .= HTML_HICYN . "伤气血为" . self::chineseNumber($damage) . "点。" . HTML_NOR . "\n";
            $total += $damage;
            $hasIssue = true;
        }

        // 检查精神伤势
        $effSen = intval($char['eff_sen'] ?? 0);
        $maxSen = intval($char['max_sen'] ?? 100);
        if ($effSen < $maxSen) {
            $damage = $maxSen - $effSen;
            $msg .= HTML_HICYN . "伤精神为" . self::chineseNumber($damage) . "点。" . HTML_NOR . "\n";
            $total += $damage;
            $hasIssue = true;
        }

        if (!$hasIssue) {
            $msg .= HTML_HICYN . "{$charName}身上没有多少伤痕。" . HTML_NOR . "\n";
            return $msg . "{$npcName}说道：一路小心。";
        }

        // 存储治疗费用（供后续 give 银子时使用）
        self::setCharState($charId, 'cure_payment', (string)$total);

        $msg .= "\n" . HTML_HIGRN . "一共要" . self::chineseNumber($total) . "两银子。" . HTML_NOR;
        $msg .= "\n{$npcName}说道：贫医药物已备齐。";

        return $msg;
    }

    /**
     * 中文数字转换（1-9999）
     */
    private static function chineseNumber(int $num): string
    {
        if ($num <= 0) return '零';
        $units = ['', '十', '百', '千'];
        $digits = ['零', '一', '二', '三', '四', '五', '六', '七', '八', '九'];
        $result = '';
        $str = (string)$num;
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $d = intval($str[$i]);
            $unit = $len - $i - 1;
            if ($d === 0) {
                if ($i < $len - 1 && intval($str[$i + 1]) !== 0) {
                    $result .= '零';
                }
            } else {
                $result .= $digits[$d] . $units[$unit];
            }
        }
        // 处理十的特殊情况：一十 → 十
        if ($len === 2 && $str[0] === '1') {
            $result = '十' . ($str[1] === '0' ? '' : $digits[intval($str[1])]);
        }
        return $result ?: '零';
    }

    /**
     * 处理"治疗"询问
     * 检查NPC是否为医者类，根据玩家状态给出治疗相关信息
     */
    private static function handleCure(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '医者';
        $charName = $char['name'] ?? '你';
        
        // 检查玩家当前生命值
        $kee = intval($char['kee'] ?? 100);
        $maxKee = intval($char['max_kee'] ?? 100);
        $gin = intval($char['gin'] ?? 100);
        $maxGin = intval($char['max_gin'] ?? 100);
        $sen = intval($char['sen'] ?? 100);
        $maxSen = intval($char['max_sen'] ?? 100);
        
        $isHurt = ($kee < $maxKee) || ($gin < $maxGin) || ($sen < $maxSen);
        
        if (!$isHurt) {
            return "{$npcName}看了看你，说道：你身体很好，不需要治疗。";
        }
        
        $hurtPercent = intval((1 - $kee / max($maxKee, 1)) * 100);
        
        if ($hurtPercent > 50) {
            return "{$npcName}皱了皱眉，说道：你伤得不轻啊，快去休息吧，或者服用些药物调理。";
        } elseif ($hurtPercent > 20) {
            return "{$npcName}点了点头，说道：有些小伤，多休息便会痊愈。";
        } else {
            return "{$npcName}微微一笑，说道：只是皮外伤，无碍无碍。";
        }
    }
    
    /**
     * test_player 分发器：根据NPC身份调用不同handler
     * 朱紫国国王（npc_id=king 或 id=718）→ handleZhuziKing
     * 五福道长（车迟国，id=43）→ handleCureDiagnosis（毒伤诊断）
     * 其他NPC → handleCure
     */
    private static function handleTestPlayer(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcId = $npc['npc_id'] ?? '';
        $npcDbId = intval($npc['id'] ?? 0);

        // 朱紫国国王
        if ($npcId === 'king' || $npcDbId === 718) {
            return self::handleZhuziKing($npc, $char, $topic, $extraParam);
        }

        // 五福道长（车迟国三清观）：毒伤诊断
        if ($npcDbId === 43 || $npcId === 'daozhang' || stripos($npc['name'] ?? '', '五福') !== false) {
            return self::handleCureDiagnosis($npc, $char, $topic, $extraParam);
        }

        // 默认：通用治病诊断
        return self::handleCure($npc, $char, $topic, $extraParam);
    }

    /**
     * 处理"出售/买卖"询问
     * 检查NPC是否为商人，给出交易相关信息
     */
    private static function handleSell(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '商人';
        $isMerchant = !empty($npc['merchant']) || !empty($npc['shop_type']);
        
        if (!$isMerchant) {
            return "{$npcName}摇了摇头，说道：我不做买卖。";
        }
        
        $shopType = $npc['shop_type'] ?? '';
        
        switch ($shopType) {
            case 'hockshop':
                return "{$npcName}笑道：想卖东西？把东西给我看看吧，价格公道。";
            case 'bank':
                return "{$npcName}正色道：这里是钱庄，存取金银都在此办理。";
            case 'vendor':
                return "{$npcName}热情地说：来来来，看看有什么需要的，今日特价！";
            default:
                return "{$npcName}说道：你有何物要买卖？尽管拿出来看看。";
        }
    }
    
    /**
     * 处理"任务"询问
     * 检查是否有可接任务
     */
    private static function handleQuest(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? 'NPC';
        $charLevel = intval($char['level'] ?? 1);
        $charFamily = $char['family'] ?? '';
        
        // 根据NPC类型给出不同的任务提示
        $npcClass = $npc['class'] ?? '';
        
        if ($npcClass === 'bonze') {
            return "{$npcName}双手合十，说道：阿弥陀佛，施主若想历练，可去附近降妖除魔，功德无量。";
        }
        
        if ($npcClass === 'taoist') {
            return "{$npcName}捋了捋胡须，说道：修行之道，在于历练。你可用 quest 命令查看当前任务。";
        }
        
        if (!empty($charFamily)) {
            return "{$npcName}说道：身为门下弟子，当以降妖除魔为己任。你可以去问掌门，或许有任务交代给你。";
        }
        
        return "{$npcName}想了想，说道：若要历练，不妨先拜入门派，自然会有师长指点。";
    }
    
    /**
     * 处理"工作/帮工"询问
     * 袁天罡的灭妖任务也通过work_me调用，需要特殊处理
     */
    private static function handleWork(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? 'NPC';
        $npcId = intval($npc['id'] ?? 0);
        $charLevel = intval($char['level'] ?? 1);
        
        // 袁天罡灭妖任务特殊处理
        if ($npcId === 136 || ($npc['npc_id'] ?? '') === 'yuantiangang') {
            return self::handleDemonHunt($npc, $char, $topic, $extraParam);
        }
        
        if ($charLevel < 5) {
            return "{$npcName}摇摇头，说道：你年纪尚小，还是先好好修炼吧。";
        }
        
        return "{$npcName}点了点头，说道：想要帮忙？你可以去附近看看有什么活计，或者帮忙跑跑腿也行。";
    }

    /**
     * 处理"喜宴"询问 - 办喜宴
     */
    private static function handleAskParty(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        // 检查玩家是否是喜福会老板
        $npcName = $npc['name'] ?? 'NPC';
        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';
        
        // 原始LPC代码逻辑：检查是否已经在办喜宴 (host_of_party)
        $isHost = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'host_of_party'",
            [$charId]
        );
        if ($isHost) {
            return "{$npcName}说：你已经在办喜宴了，还办什么？";
        }
        
        // 原始LPC代码逻辑：检查NPC是否准备好办喜宴 (ready_to_party)
        $readyToParty = Database::queryOne(
            "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'ready_to_party'",
            [$npc['id'] ?? 0]
        );
        if ($readyToParty && $readyToParty['temp_value'] == '1') {
            return "{$npcName}说：现在正忙着办喜宴，您稍后再来吧。";
        }
        
        // 原始LPC代码逻辑：检查是否在喜福会地点
        $currentRoom = $char['current_room'] ?? '';
        if ($currentRoom !== 'city/xifuhui') {
            return "{$npcName}说：这儿不是办喜宴的地儿，您请到喜福会来吧。";
        }
        
        // 原始LPC代码逻辑：检查是否已经付过钱 (party_paid / ready_to_pay)
        $readyToPay = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'ready_to_pay'",
            [$charId]
        );

        if ($readyToPay && $readyToPay['temp_value'] == '1') {
            return "{$npcName}说：您已经付过钱了，这就开始办喜宴吧。";
        }
        
        // 计算价格并提示玩家
        $price = self::calculatePrice();
        
        return "{$npcName}说：办喜宴啊，先请付" . $price . "两金子。\n" .
                 "(点击「给予」支付金子，或再次询问「喜宴」开始办宴)";
    }

    /**
     * 处理"财务"询问 - 查询资金
     */
    private static function handleAskMoney(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? 'NPC';
        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';
        
        // 原始LPC代码逻辑：只有ID为"bula"的玩家才能查询
        $playerId = $char['user_id'] ?? '';
        if ($playerId !== 'bula') {
            return "{$npcName}想了想，不知道你说的是什么。";
        }
        
        // 获取喜福会的资金（存储在npc_temp中）
        $npcMoney = Database::queryOne(
            "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'money'",
            [$npc['id'] ?? 0]
        );
        
        $money = $npcMoney ? intval($npcMoney['temp_value']) : 0;
        $total = $money + 160; // 原始代码：i = query("money") + 160
        
        return "{$npcName}悄悄告诉你：你这个月总收入差了" . self::chineseNumber($total) . "两金子了。\n";
    }
    
    /**
     * 计算喜宴价格
     */
    private static function calculatePrice(): int
    {
        return 5000; // 基础价格5000两金子
    }
    
    /**
     * 处理"门派"询问
     * 调用SectHelper获取门派信息
     */
    private static function handleSect(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? 'NPC';
        $npcId = intval($npc['id'] ?? 0);
        
        // 检查NPC是否为掌门
        require_once HELPER_PATH . 'SectHelper.php';
        $sect = SectHelper::getSectByNpcId($npcId);
        
        if (!$sect) {
            return "{$npcName}摇了摇头，说道：我并不掌管任何门派。";
        }
        
        $sectName = $sect['name'] ?? $sect['key'];
        $charFamily = $char['family'] ?? '';
        
        if ($charFamily === $sect['key']) {
            return "{$npcName}微笑道：你已是{$sectName}弟子，当勤修本门武学，为门派争光。";
        }
        
        $skills = $sect['skills']['exclusive'] ?? [];
        $skillList = !empty($skills) ? '，本门绝学包括' . implode('、', array_values($skills)) : '';
        
        return "{$sectName}——{$sectName}乃修真名门{$skillList}。你若有心向道，可用 apprentice 拜师入门。";
    }
    
    /**
     * 处理"拜师"询问
     */
    private static function handleApprentice(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? 'NPC';
        $charFamily = $char['family'] ?? '';
        
        if (!empty($charFamily)) {
            return "{$npcName}说道：你已有门派，若要改投他门，需先脱离现门派。";
        }
        
        $charLevel = intval($char['level'] ?? 1);
        if ($charLevel < 1) {
            return "{$npcName}摇摇头，说道：你的修为尚浅，先历练历练再说吧。";
        }
        
        return "{$npcName}点了点头，说道：若想拜师，可以直接使用 apprentice 命令向我拜师。";
    }
    
    /**
     * 处理"商店"询问
     */
    private static function handleShop(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '商人';
        $shopType = $npc['shop_type'] ?? '';
        
        if (empty($shopType)) {
            return "{$npcName}说道：我这里不是商店。";
        }
        
        return "{$npcName}热情地招呼道：欢迎光临！看看有什么合意的，尽管开口。";
    }
    
    /**
     * 处理"钱庄/银行"询问
     */
    private static function handleBank(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '掌柜';
        $shopType = $npc['shop_type'] ?? '';
        
        if ($shopType !== 'bank') {
            return "{$npcName}摇摇头，说道：我这里不是钱庄。";
        }
        
        $gold = intval($char['gold'] ?? 0);
        $silver = intval($char['silver'] ?? 0);
        
        return "{$npcName}翻了翻账本，说道：你身上现有黄金{$gold}两，白银{$silver}两。需要存取金银，尽管吩咐。";
    }
    
    /**
     * 处理"名字/姓名"询问
     */
    private static function handleName(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? 'NPC';
        $npcTitle = $npc['title'] ?? '';
        
        if (!empty($npcTitle)) {
            return "{$npcName}微微一笑，说道：在下{$npcTitle}{$npcName}，有失远迎。";
        }
        
        return "{$npcName}拱了拱手，说道：在下{$npcName}，敢问阁下尊姓大名？";
    }
    
    /**
     * 处理"这里/地方/位置"询问
     */
    private static function handleHere(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? 'NPC';
        $spawnRoom = $npc['spawn_room'] ?? '';
        
        if (empty($spawnRoom)) {
            return "{$npcName}四下看了看，说道：这里是个好地方。";
        }
        
        return "{$npcName}环顾四周，说道：此处我已待了些时日，你若想了解周围环境，不妨四处走走看看。";
    }
    
    /**
     * 处理"发信"询问（千里眼邮差）
     * 给玩家发放信箱，用于寄信
     */
    private static function handleSendMail(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        // 信箱系统尚未实现，给出在角色回复
        return "哦．．．要寄信是吗？邮路暂时不通，改日再来吧。";
    }
    
    /**
     * 处理"收信/信件/信/mail/mailbox"询问（千里眼邮差）
     * 给玩家发放信箱，用于收信
     */
    private static function handleReceiveMail(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        // 信箱系统尚未实现，给出在角色回复
        return "好，待我找找．．．邮路暂时不通，改日再来吧。";
    }
    
    /**
     * 处理"小喇叭"询问（千里眼播音）
     * 检查玩家是否为小喇叭成员，如果是则传送到播音室
     */
    private static function handleSendDianbo(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '千里眼';
        
        // 检查玩家是否为小喇叭成员
        $isXiaolaba = !empty($char['xiaolaba_member']);
        
        if (!$isXiaolaba) {
            return "{$npcName}想了一会儿，说道：对不起，你问的事我实在没有印象。";
        }
        
        return "{$npcName}对着你点了点头。";
    }
    
    // =========================================================
    // 新增handlers
    // =========================================================
    
    /**
     * 处理"医疗"询问 - 治伤/疗伤/开药
     */
    private static function handleMedicine(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '医者';
        $charId = intval($char['id'] ?? 0);
        
        $kee = intval($char['kee'] ?? 100);
        $maxKee = intval($char['max_kee'] ?? 100);
        $sen = intval($char['sen'] ?? 100);
        $maxSen = intval($char['max_sen'] ?? 100);
        
        // 检查是否受伤
        if ($kee >= $maxKee && $sen >= $maxSen) {
            return "{$npcName}看了看你，说道：你没有受伤，不需要治疗。";
        }
        
        try {
            if ($topic === '开药') {
                // 开药：恢复气血+精神到满
                $cost = ($maxKee - $kee + $maxSen - $sen) * 3;
                $silver = intval($char['silver'] ?? 0);
                
                if ($silver < $cost) {
                    return HTML_HIRED . "{$npcName}摇了摇头，说道：开药需要{$cost}两银子，你身上的银两不够。" . HTML_NOR;
                }
                
                // 扣除银两，恢复气血和精神
                Database::execute(
                    "UPDATE characters SET silver = silver - ?, kee = ?, sen = ? WHERE id = ?",
                    [$cost, $maxKee, $maxSen, $charId]
                );
                
                return HTML_HIGRN . "{$npcName}给你开了一副药，你服下后感觉神清气爽，气血精神全部恢复！花费银两：{$cost}" . HTML_NOR;
            } else {
                // 治伤/疗伤：只恢复气血
                $cost = ($maxKee - $kee) * 2;
                $silver = intval($char['silver'] ?? 0);
                
                if ($silver < $cost) {
                    return HTML_HIRED . "{$npcName}摇了摇头，说道：治伤需要{$cost}两银子，你身上的银两不够。" . HTML_NOR;
                }
                
                // 扣除银两，恢复气血
                Database::execute(
                    "UPDATE characters SET silver = silver - ?, kee = ? WHERE id = ?",
                    [$cost, $maxKee, $charId]
                );
                
                return HTML_HIGRN . "{$npcName}为你疗伤，你的气血已经恢复如初！花费银两：{$cost}" . HTML_NOR;
            }
        } catch (\Exception $e) {
            error_log('NpcInquiryHelper::handleMedicine error: ' . $e->getMessage());
            return "{$npcName}叹了口气，说道：药铺今日缺药，改日再来吧。";
        }
    }
    
    /**
     * 处理"灭妖"询问
     */
    private static function handleDemonHunt(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? 'NPC';
        $charId = intval($char['id'] ?? 0);
        
        try {
            // 查询玩家是否有活跃的灭妖任务
            $activeTask = Database::queryOne(
                "SELECT * FROM mieyao_yaoguai WHERE owner_id = ? AND is_killed = 0 AND expires_at > NOW() LIMIT 1",
                [$charId]
            );
            
            if ($activeTask) {
                $yaoguaiName = $activeTask['npc_name'] ?? '妖怪';
                $area = $activeTask['area'] ?? '某处';
                return HTML_HIYEL . "{$npcName}说道：你还有未完成的灭妖任务，目标是" . HTML_HICYN . "{$yaoguaiName}" . HTML_NOR . HTML_HIYEL . "，在{$area}一带等着你。" . HTML_NOR;
            }
            
            // 没有活跃任务，直接调用MieyaoHandler接取任务
            require_once __DIR__ . '/../daemons/MieyaoHandler.php';
            $handler = new MieyaoHandler();
            $result = $handler->execute($charId, [], []);
            
            $message = $result['message'] ?? '灭妖任务处理出现异常。';
            
            // 如果消息中没有NPC说话格式，加上当前NPC名字前缀
            if (strpos($message, '说道') === false && strpos($message, '笑道') === false && strpos($message, '冷哼') === false) {
                $message = HTML_HICYN . "{$npcName}说道：{$message}" . HTML_NOR;
            } else {
                // MieyaoHandler返回的消息已包含NPC说话格式，直接使用并添加颜色
                $message = HTML_HIYEL . $message . HTML_NOR;
            }
            
            return $message;
        } catch (\Exception $e) {
            error_log('NpcInquiryHelper::handleDemonHunt error: ' . $e->getMessage());
            return HTML_HICYN . "{$npcName}叹了口气，说道：灭妖任务暂时无法接取，请稍后再来。" . HTML_NOR;
        }
    }
    
    /**
     * 处理"转业/离开门派"询问
     */
    private static function handleCareerChange(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? 'NPC';
        $charId = intval($char['id'] ?? 0);
        $sect = $char['family'] ?? $char['sect'] ?? '';
        
        // 检查是否有门派
        if (empty($sect)) {
            return "{$npcName}摇了摇头，说道：你目前还没有门派，无需转业。若想加入门派，可以找各门派掌门拜师。";
        }
        
        // 检查等级
        $level = intval($char['level'] ?? 1);
        if ($level < 20) {
            return "{$npcName}皱了皱眉，说道：你的修为尚浅，至少需要20级才能转业。";
        }
        
        // 检查银两
        $silver = intval($char['silver'] ?? 0);
        if ($silver < 100000) {
            return HTML_HIRED . "{$npcName}摇了摇头，说道：转业需要缴纳十万两银子的手续费，你的银两不够。" . HTML_NOR;
        }
        
        try {
            // 获取门派名称
            require_once HELPER_PATH . 'SectHelper.php';
            $sectName = SectHelper::getSectName($sect);
            if (empty($sectName)) {
                $sectName = $sect;
            }
            
            // 获取门派专属技能列表
            $sectSkills = SectHelper::getSectSkills($sect);
            $exclusiveSkills = $sectSkills['exclusive'] ?? [];
            $importantSkills = $sectSkills['important'] ?? [];
            $skillIds = array_merge(array_keys($exclusiveSkills), array_keys($importantSkills));
            
            // 执行转业：清除门派，扣除银两
            Database::execute(
                "UPDATE characters SET family = '', silver = silver - 100000 WHERE id = ?",
                [$charId]
            );
            
            // 清除职业（对应原始项目的 delete("class")）
            ProfessionHelper::clearProfession($charId);
            
            // 删除门派技能
            if (!empty($skillIds)) {
                $placeholders = implode(',', array_fill(0, count($skillIds), '?'));
                $params = array_merge([$charId], $skillIds);
                Database::execute(
                    "DELETE FROM character_skills WHERE char_id = ? AND skill_id IN ({$placeholders})",
                    $params
                );
            }
            
            return HTML_HICYN . "好，既然你去意已决..." . HTML_NOR . HTML_HIYEL . "（从此你就不再是{$sectName}的弟子了。）" . HTML_NOR;
        } catch (\Exception $e) {
            error_log('NpcInquiryHelper::handleCareerChange error: ' . $e->getMessage());
            return "{$npcName}叹了口气，说道：转业手续暂时无法办理，改日再来吧。";
        }
    }
    
    /**
     * 处理"俸银"询问
     */
    private static function handleSalary(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? 'NPC';
        $charId = intval($char['id'] ?? 0);
        $officialRank = intval($char['official_rank'] ?? 0);
        
        // 官阶名映射
        $rankNames = [
            1 => '秀才',
            2 => '举人',
            3 => '进士',
            4 => '翰林',
            5 => '侍郎',
        ];
        
        if ($officialRank <= 0) {
            return "{$npcName}摇了摇头，说道：你目前没有官职，无法领取俸银。请先去找贺知章考取功名。";
        }
        
        try {
            // 检查上次领取时间
            $charData = Database::queryOne(
                "SELECT last_salary_time FROM characters WHERE id = ?",
                [$charId]
            );
            
            $lastSalaryTime = $charData['last_salary_time'] ?? null;
            if ($lastSalaryTime && (time() - strtotime($lastSalaryTime)) < 86400) {
                return "{$npcName}摇了摇头，说道：你今天已经领过俸银了，明天再来吧。";
            }
            
            // 计算俸银金额
            $amount = $officialRank * 500;
            $rankName = $rankNames[$officialRank] ?? '官人';
            
            // 发放俸银到背包（character_inventory表）
            $existingSilver = Database::queryValue(
                "SELECT COALESCE(SUM(quantity), 0) FROM character_inventory WHERE char_id = ? AND item_id = 'silver'",
                [$charId],
                0
            );
            
            if ($existingSilver > 0) {
                Database::execute(
                    "UPDATE character_inventory SET quantity = quantity + ? WHERE char_id = ? AND item_id = 'silver'",
                    [$amount, $charId]
                );
            } else {
                Database::execute(
                    "INSERT INTO character_inventory (char_id, item_id, quantity) VALUES (?, 'silver', ?)",
                    [$charId, $amount]
                );
            }
            
            // 更新领取时间
            Database::execute(
                "UPDATE characters SET last_salary_time = NOW() WHERE id = ?",
                [$charId]
            );
            
            return HTML_HIGRN . "{$rankName}大人，这是你本月的俸银{$amount}两白银，请收好。" . HTML_NOR;
        } catch (\Exception $e) {
            error_log('NpcInquiryHelper::handleSalary error: ' . $e->getMessage());
            return "{$npcName}翻了翻账本，说道：俸银暂时无法发放，改日再来吧。";
        }
    }
    
    /**
     * 处理"功名/科举"询问
     */
    private static function handleFame(array $npc, array $char, string $topic, $extraParam = null): mixed
    {
        $npcName = $npc['name'] ?? 'NPC';
        $charId = intval($char['id'] ?? 0);
        $officialRank = intval($char['official_rank'] ?? 0);
        
        // 官阶名映射
        $rankNames = [
            0 => '白丁',
            1 => '秀才',
            2 => '举人',
            3 => '进士',
            4 => '翰林',
            5 => '侍郎',
        ];
        
        // 已是最高
        if ($officialRank >= 5) {
            return HTML_HICYN . "{$npcName}微笑道：你已经是侍郎了，本朝最高功名莫过于此。" . HTML_NOR;
        }
        
        // 计算下一级需求
        $nextRank = $officialRank + 1;
        $levelReq = 15 + $officialRank * 5;
        $silverCost = 10000 * $nextRank;
        $potReq = 1000 * $nextRank;
        
        $level = SkillManager::querySkill($charId, 'literate', true);
        
        // 读取背包中的所有货币
        $inventoryGold = Database::queryValue(
            "SELECT COALESCE(SUM(quantity), 0) FROM character_inventory WHERE char_id = ? AND item_id = 'gold'",
            [$charId],
            0
        );
        $inventorySilver = Database::queryValue(
            "SELECT COALESCE(SUM(quantity), 0) FROM character_inventory WHERE char_id = ? AND item_id = 'silver'",
            [$charId],
            0
        );
        $inventoryCoin = Database::queryValue(
            "SELECT COALESCE(SUM(quantity), 0) FROM character_inventory WHERE char_id = ? AND item_id = 'coin'",
            [$charId],
            0
        );
        
        // 换算为银两：1黄金=100银两，1铜钱=0.01银两
        $gold = intval($inventoryGold);
        $silver = intval($inventorySilver);
        $coin = intval($inventoryCoin);
        $totalSilver = $gold * 100 + $silver + intval($coin / 100);
        
        $potential = intval($char['potential'] ?? 0);
        
        // 检查条件
        if ($level < $levelReq || $totalSilver < $silverCost || $potential < $potReq) {
            $nextRankName = $rankNames[$nextRank] ?? '下一级';
            return [
                'message' => HTML_HIYEL . "{$npcName}说道：考取{$nextRankName}需要：读书识字>={$levelReq}级，银两>={$silverCost}，潜能>={$potReq}。" . HTML_NOR . "\n" .
                    HTML_HIRED . "你目前：读书识字{$level}级，{$gold}两黄金，{$silver}两白银，{$coin}铜钱，潜能{$potential}." . HTML_NOR,
                'redirect' => 'room.php'
            ];
        }
        
        try {
            // 从题库中抽取3道对应难度的题目
            $questions = Database::queryAll(
                "SELECT id, question, options, answer FROM exam_questions WHERE difficulty = ? ORDER BY RAND() LIMIT 3",
                [$nextRank]
            );
            
            if (empty($questions)) {
                return "{$npcName}叹了口气，说道：科举考试题目尚未准备好，改日再来吧。";
            }
            
            // 构建题目HTML
            $questionHtml = "\n";
            $questionIds = [];
            foreach ($questions as $idx => $q) {
                $options = json_decode($q['options'], true) ?: [];
                $questionIds[] = $q['id'];
                $questionHtml .= HTML_HIYEL . "【第" . ($idx + 1) . "题】" . HTML_NOR . "{$q['question']}\n";
                foreach (['A', 'B', 'C', 'D'] as $optKey => $optLetter) {
                    $optionText = $options[$optKey] ?? '';
                    if (!empty($optionText)) {
                        $questionHtml .= "  {$optLetter}. {$optionText}\n";
                    }
                }
                $questionHtml .= "\n";
            }
            
            // 将题目ID存入session，供答题验证使用
            $_SESSION['exam_questions'] = [
                'char_id' => $charId,
                'rank' => $nextRank,
                'questions' => $questionIds,
                'silver_cost' => $silverCost,
                'pot_req' => $potReq,
                'gold' => $gold,
                'silver' => $silver,
                'coin' => $coin,
                'timestamp' => time()
            ];
            file_put_contents(__DIR__ . '/../debug_exam.log', date('Y-m-d H:i:s') . " | Session set: charId=$charId questions=" . implode(',', $questionIds) . "\n", FILE_APPEND);
            
            // 构建题目数据供弹窗使用
            $examQuestions = [];
            foreach ($questions as $q) {
                $examQuestions[] = [
                    'id' => $q['id'],
                    'question' => $q['question'],
                    'options' => json_decode($q['options'], true) ?: []
                ];
            }
            
            $nextRankName = $rankNames[$nextRank] ?? '下一级';
            $npcId = $npc['id'] ?? 0;
            
            return [
                'message' => HTML_HICYN . "{$npcName}肃然道：好！现在开始科举考试，请回答以下3道题目。" . HTML_NOR,
                'exam_data' => [
                    'npc_id' => $npcId,
                    'questions' => $examQuestions
                ]
            ];
        } catch (\Exception $e) {
            error_log('NpcInquiryHelper::handleFame error: ' . $e->getMessage());
            return "{$npcName}叹了口气，说道：科举考试暂时无法进行，改日再来吧。";
        }
    }
    
    /**
     * 处理科举答题验证
     */
    public static function processExamAnswer(int $charId, string $userAnswer, string $npcName, array $rankNames): ?string
    {
        $examData = $_SESSION['exam_questions'];
        $nextRank = $examData['rank'];
        $questionIds = $examData['questions'];
        $silverCost = $examData['silver_cost'];
        $potReq = $examData['pot_req'];
        $gold = $examData['gold'];
        $silver = $examData['silver'];
        $coin = $examData['coin'];
        
        // 验证答案
        $correctCount = 0;
        $resultHtml = "\n";
        foreach ($questionIds as $idx => $qId) {
            $question = Database::queryOne(
                "SELECT question, options, answer FROM exam_questions WHERE id = ?",
                [$qId]
            );
            if ($question) {
                $correctAnswer = $question['answer'];
                $userChoice = $userAnswer[$idx];
                $isCorrect = ($userChoice === $correctAnswer);
                if ($isCorrect) {
                    $correctCount++;
                    $resultHtml .= HTML_HIGRN . "【第" . ($idx + 1) . "题】正确！" . HTML_NOR . "\n";
                } else {
                    $resultHtml .= HTML_HIRED . "【第" . ($idx + 1) . "题】错误！你的答案：{$userChoice}，正确答案：{$correctAnswer}" . HTML_NOR . "\n";
                }
            }
        }
        
        // 3题中答对2题以上通过
        if ($correctCount >= 2) {
            // 升级官阶
            Database::execute(
                "UPDATE characters SET official_rank = official_rank + 1, potential = potential - ? WHERE id = ?",
                [$potReq, $charId]
            );
            
            // 扣除银两：先扣白银，再扣黄金，最后扣铜钱
            $remaining = $silverCost;
            
            // 先扣白银
            if ($silver > 0 && $remaining > 0) {
                $deductSilver = min($silver, $remaining);
                Database::execute(
                    "UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = 'silver'",
                    [$deductSilver, $charId]
                );
                $remaining -= $deductSilver;
            }
            
            // 再扣黄金（1黄金=100银两）
            if ($gold > 0 && $remaining > 0) {
                $deductGold = intval(ceil($remaining / 100));
                $deductGold = min($gold, $deductGold);
                Database::execute(
                    "UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = 'gold'",
                    [$deductGold, $charId]
                );
                $remaining -= $deductGold * 100;
            }
            
            // 最后扣铜钱（100铜钱=1银两）
            if ($coin > 0 && $remaining > 0) {
                $deductCoin = $remaining * 100;
                $deductCoin = min($coin, $deductCoin);
                Database::execute(
                    "UPDATE character_inventory SET quantity = quantity - ? WHERE char_id = ? AND item_id = 'coin'",
                    [$deductCoin, $charId]
                );
            }
            
            $newRankName = $rankNames[$nextRank] ?? '官人';
            unset($_SESSION['exam_questions']);
            
            return HTML_HIGRN . "恭喜！你答对了{$correctCount}/3题，已通过科举考试，获封{$newRankName}！" . HTML_NOR . "\n" . $resultHtml;
        } else {
            unset($_SESSION['exam_questions']);
            return HTML_HIYEL . "很遗憾，你只答对了{$correctCount}/3题，未能通过科举考试。下次再来吧。" . HTML_NOR . "\n" . $resultHtml;
        }
    }
    
    /**
     * 处理贺知章"读书识字/学习/申请儒生"询问
     *
     * 对应原始项目何知章处申请 scholar 职业
     */
    private static function handleScholar(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '贺知章';
        $charId = intval($char['id'] ?? 0);
        $profession = ProfessionHelper::getProfession($char);
        
        if ($profession === 'scholar') {
            return "{$npcName}微笑道：你已是儒门中人，好生读书吧。";
        }
        
        $result = ProfessionHelper::applyScholar($char);
        if ($result['success']) {
            return HTML_HIGRN . "{$npcName}捋须微笑：好！从今日起，你便是我贺知章门下弟子。勤学苦读，日后必成大器。" . HTML_NOR;
        }
        return "{$npcName}说道：{$result['message']}";
    }
    
    /**
     * 处理"送饭"询问
     */
    private static function handleDeliverFood(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? 'NPC';
        $charId = intval($char['id'] ?? 0);
        
        try {
            // 检查冷却时间（1小时CD）
            $charData = Database::queryOne(
                "SELECT deliver_food_time FROM characters WHERE id = ?",
                [$charId]
            );
            
            $lastTime = $charData['deliver_food_time'] ?? null;
            if ($lastTime && (time() - strtotime($lastTime)) < 3600) {
                $remaining = 3600 - (time() - strtotime($lastTime));
                $mins = ceil($remaining / 60);
                return "{$npcName}摆了摆手，说道：你不久前才帮我送过饭，过会儿再来吧。还需等待{$mins}分钟。";
            }
            
            // 检查背包是否已有"饭菜"物品
            $existingItem = Database::queryOne(
                "SELECT * FROM character_inventory WHERE char_id = ? AND item_id = 'fan_cai' LIMIT 1",
                [$charId]
            );
            
            if ($existingItem) {
                return "{$npcName}说道：你手上不是还有饭菜吗？快去送给却俟大师吧，他在大雄宝殿。";
            }
            
            // 确保items表中有fan_cai物品
            $itemExists = Database::queryOne("SELECT id FROM items WHERE item_id = 'fan_cai' LIMIT 1");
            if (!$itemExists) {
                $maxId = Database::queryOne("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM items");
                Database::execute(
                    "INSERT INTO items (id, item_id, name, type, weight, description, unit) VALUES (?, 'fan_cai', '饭菜', 'quest', 1, '李玉娘托你送给却俟大师的饭菜，还热乎着呢。', '份')",
                    [$maxId['next_id']]
                );
            }
            
            // 检查背包是否已有
            $existing = Database::queryOne(
                "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = 'fan_cai' LIMIT 1",
                [$charId]
            );
            if ($existing) {
                Database::execute(
                    "UPDATE character_inventory SET quantity = quantity + 1 WHERE id = ?",
                    [$existing['id']]
                );
            } else {
                Database::execute(
                    "INSERT INTO character_inventory (char_id, item_id, quantity) VALUES (?, 'fan_cai', 1)",
                    [$charId]
                );
            }
            
            return HTML_HICYN . "{$npcName}说道：太好了！麻烦你帮我把这份饭菜送到大雄宝殿的却俟大师那里，快去快回！" . HTML_NOR . "\n" .
                HTML_HIGRN . "（奖励：500银两+200经验）" . HTML_NOR;
        } catch (\Exception $e) {
            error_log('NpcInquiryHelper::handleDeliverFood error: ' . $e->getMessage());
            return "{$npcName}叹了口气，说道：饭菜还没准备好，改日再来吧。";
        }
    }
    
    /**
     * 处理李玉娘"送饭"询问
     * 原始LPC逻辑：将饭盒给玩家，让玩家送给袁天罡
     */
    private static function handleFanMe(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '李玉娘';
        $charId = intval($char['id'] ?? 0);
        
        // 检查玩家是否已经有饭盒
        $hasFanhe = Database::queryOne(
            "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = 'fanhe' LIMIT 1",
            [$charId]
        );
        
        if ($hasFanhe) {
            return HTML_HICYN . "{$npcName}四下打量了一番。\n" .
                "{$npcName}说道：你手上不是还有饭盒吗？快去送给天监台的袁天罡吧！" . HTML_NOR;
        }
        
        // 确保fanhe物品在items表中存在
        $itemExists = Database::queryOne("SELECT id FROM items WHERE item_id = 'fanhe' LIMIT 1");
        if (!$itemExists) {
            $maxId = Database::queryOne("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM items");
            Database::execute(
                "INSERT INTO items (id, item_id, name, type, weight, description, unit) VALUES (?, 'fanhe', '饭盒', 'food', 1, '一个小巧的饭盒，摸着还热腾腾的。', '个')",
                [$maxId['next_id']]
            );
        }
        
        // 给予玩家饭盒
        $existing = Database::queryOne(
            "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = 'fanhe' LIMIT 1",
            [$charId]
        );
        if ($existing) {
            Database::execute(
                "UPDATE character_inventory SET quantity = quantity + 1 WHERE id = ?",
                [$existing['id']]
            );
        } else {
            Database::execute(
                "INSERT INTO character_inventory (char_id, item_id, category, quantity) VALUES (?, 'fanhe', 'food', 1)",
                [$charId]
            );
        }
        
        return HTML_HICYN . "{$npcName}四下打量了一番，将一个热腾腾的饭盒塞到你手中。\n" .
            "{$npcName}低声说道：劳烦了，帮我把饭送给天监台的袁天罡吧。" . HTML_NOR;
    }
    
    /**
     * 处理疥顶小僧"送书"询问
     * 原始LPC逻辑：检查books数量，发完摇头
     */
    private static function handleGiveBook(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '疥顶小僧';
        $npcId = intval($npc['id'] ?? 0);
        $charId = intval($char['id'] ?? 0);
        $npcKey = $npc['npc_id'] ?? '';
        
        try {
            // 根据NPC类型选择要给的书
            if ($npcKey === 'ludongbin') {
                // 吕洞宾给纯阳心得
                $bookItemId = 'chunyang';
                $bookName = '纯阳心得';
                $bookDesc = '吕洞宾所赠，记载着纯阳功法的修炼心得。';
                $dialogue = "{$npcName}微微一笑，说道：此乃贫道毕生所学，望施主勤加修炼，早日成仙。";
                $broadcastMsgFormat = "{$npcName}给{$charName}一本纯阳心得。";
            } else {
                // 默认给取经指南（疥顶小僧）
                $bookItemId = 'book_qujing';
                $bookName = '取经指南';
                $bookDesc = '疥顶小僧所赠，记载着西天取经的路线和注意事项。';
                $dialogue = "{$npcName}双手合十，说道：施主既有此心，贫僧赠你一本取经指南，望你早日修成正果。";
                $broadcastMsgFormat = "{$npcName}给{$charName}一本取经指南。";
            }
            
            // 1. 检查NPC的剩余书本数量（限制3本，每3天重置）
            $maxBooks = 3;
            $resetDays = 3;
            $now = time();
            
            $booksState = Database::queryOne(
                "SELECT temp_value, updated_at FROM npc_temp WHERE npc_id = ? AND temp_key = 'books'",
                [$npcId]
            );
            
            if (!$booksState) {
                // 首次，初始化为满额
                Database::execute(
                    "INSERT INTO npc_temp (npc_id, temp_key, temp_value, updated_at) VALUES (?, 'books', ?, ?)",
                    [$npcId, $maxBooks, $now]
                );
                $booksLeft = $maxBooks;
            } else {
                $lastUpdate = intval($booksState['updated_at'] ?? 0);
                $booksLeft = intval($booksState['temp_value']);
                
                // 超过3天重置
                if ($now - $lastUpdate >= $resetDays * 86400) {
                    Database::execute(
                        "UPDATE npc_temp SET temp_value = ?, updated_at = ? WHERE npc_id = ? AND temp_key = 'books'",
                        [$maxBooks, $now, $npcId]
                    );
                    $booksLeft = $maxBooks;
                }
            }
            
            if ($booksLeft <= 0) {
                return "{$npcName}摇了摇头，经书已发完了。";
            }
            
            // 2. 检查玩家背包是否已有此书
            $existingItem = Database::queryOne(
                "SELECT * FROM character_inventory WHERE char_id = ? AND item_id = ? LIMIT 1",
                [$charId, $bookItemId]
            );
            
            if ($existingItem) {
                return "{$npcName}微笑道：你手上不是已经有一本了吗？好好研读吧。";
            }
            
            // 3. 确保items表中有此书
            $itemExists = Database::queryOne("SELECT id FROM items WHERE item_id = ? LIMIT 1", [$bookItemId]);
            if (!$itemExists) {
                $maxId = Database::queryOne("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM items");
                Database::execute(
                    "INSERT INTO items (id, item_id, name, type, weight, description, unit) VALUES (?, ?, ?, 'book', 1, ?, '本')",
                    [$maxId['next_id'], $bookItemId, $bookName, $bookDesc]
                );
            }
            
            // 4. 给予玩家书
            $existing = Database::queryOne(
                "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = ? LIMIT 1",
                [$charId, $bookItemId]
            );
            if ($existing) {
                Database::execute(
                    "UPDATE character_inventory SET quantity = quantity + 1 WHERE id = ?",
                    [$existing['id']]
                );
            } else {
                Database::execute(
                    "INSERT INTO character_inventory (char_id, item_id, category, quantity) VALUES (?, ?, 'book', 1)",
                    [$charId, $bookItemId]
                );
            }
            
            // 5. 扣减NPC剩余书本数量
            Database::execute(
                "UPDATE npc_temp SET temp_value = temp_value - 1, updated_at = ? WHERE npc_id = ? AND temp_key = 'books'",
                [$now, $npcId]
            );
            
            // 6. 广播消息（房间内其他人也能看到）
            $charName = $char['name'] ?? '某人';
            $broadcastMsg = HTML_HICYN . sprintf($broadcastMsgFormat, $npcName, $charName) . HTML_NOR;
            $roomId = $char['current_room'] ?? '';
            if (!empty($roomId) && class_exists('MessageDaemon')) {
                try {
                    MessageDaemon::broadcastToRoom($roomId, $broadcastMsg, $charId);
                } catch (\Exception $e) {
                    // 静默失败
                }
            }
            
            return HTML_HICYN . $dialogue . HTML_NOR;
        } catch (\Exception $e) {
            error_log('NpcInquiryHelper::handleGiveBook error: ' . $e->getMessage());
            return "{$npcName}双手合十，说道：经书暂时不在身边，施主改日再来吧。";
        }
    }
    
    /**
     * 处理公孙大娘"舞妓/歌妓"询问
     */
    private static function handleDancer(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '公孙大娘';
        $gender = $char['gender'] ?? '';
        $profession = ProfessionHelper::getProfession($char);
        $age = intval($char['age'] ?? 20);
        
        if ($gender === 'male' || $gender === '男') {
            return "{$npcName}哼了一声，说道：这就要看阁下的本事了，我可帮不上多少忙。";
        }
        
        // 已经是舞者
        if ($profession === 'dancer') {
            return "{$npcName}笑道：好好干吧，以后不愁嫁不上好人家。";
        }
        
        // 年龄限制
        if ($age >= 30) {
            return "{$npcName}叹了口气，说道：岁月不饶人，姑娘还是另寻它路吧。";
        }
        
        // 申请成为舞者
        $result = ProfessionHelper::applyDancer($char);
        if ($result['success']) {
            return HTML_HIGRN . "{$npcName}打量了你一番，满意地点了点头，说道：好，从今日起你就是我公孙大娘的人了！好好修炼舞技吧。" . HTML_NOR;
        }
        return "{$npcName}打量了你一番，说道：{$result['message']}";
    }
    
    /**
     * 处理公孙大娘"离开"询问
     */
    private static function handleLeaving(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '公孙大娘';
        $gender = $char['gender'] ?? '';
        $profession = ProfessionHelper::getProfession($char);
        
        if ($gender === 'male' || $gender === '男') {
            return HTML_HIRED . "{$npcName}怒道：快滚，滚得远远的！老娘这地方还怕没人来吗？" . HTML_NOR;
        }
        
        // 女性
        if ($profession === 'dancer') {
            return "{$npcName}叹了口气，说道：既入此门，大家都知道了，离不离开又有什么分别呢？";
        }
        
        return "{$npcName}挥了挥手，说道：快走吧，这里本来就不是女人玩的地方。";
    }
    
    /**
     * 处理"拱猪游戏"询问
     */
    private static function handlePigGame(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? 'NPC';
        
        // 排行榜相关话题
        if (in_array($topic, ['rank', '排名', '等级分'])) {
            try {
                $rankings = Database::queryAll(
                    "SELECT p.char_id, p.char_name, p.rank_points, p.hands_played, p.heads_received
                     FROM piggy_rankings p
                     ORDER BY p.rank_points DESC LIMIT 10"
                );

                if (empty($rankings)) {
                    return "{$npcName}说道：目前还没有人上榜，你来做第一个吧！";
                }

                $rankStr = HTML_HIYEL . "【拱猪排行榜】" . HTML_NOR . "\n";
                foreach ($rankings as $i => $r) {
                    $rank = $i + 1;
                    $name = $r['char_name'] ?? '无名';
                    $rating = intval($r['rank_points'] ?? 0);
                    $played = intval($r['hands_played'] ?? 0);
                    $heads = intval($r['heads_received'] ?? 0);
                    $wins = $played - $heads;
                    $rankStr .= "{$rank}. {$name} - 等级分{$rating} (胜{$wins}/猪头{$heads})\n";
                }

                return rtrim($rankStr);
            } catch (\Exception $e) {
                error_log('NpcInquiryHelper::handlePigGame rank error: ' . $e->getMessage());
                return "{$npcName}说道：排行榜暂时无法查看，改日再来吧。";
            }
        }
        
        // 拱猪游戏说明
        return HTML_HICYN . "{$npcName}笑道：你想玩拱猪？好！去拱猪北房点击「拱猪」即可入座开拱！" . HTML_NOR . "\n" .
            HTML_HIYEL . "规则：黑桃Q(猪)=-100分，方片J(羊)=+100分，草花T(变压器)=分数翻倍，收全红可反败为胜！入场费50文铜钱。" . HTML_NOR;
    }
    
    /**
     * 处理广羲子"千字文"询问
     * 向玩家讲解千字文，给予少量潜能奖励
     */
    public static function handleQianZiWen(array $npc, array $char, string $topic, $extraParam = null)
    {
        $npcName = $npc['name'] ?? '广羲子';
        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';

        try {
            // 检查冷却时间（2小时）
            $charData = Database::queryOne(
                "SELECT qianziwen_time FROM characters WHERE id = ?",
                [$charId]
            );

            $lastTime = $charData['qianziwen_time'] ?? null;
            if ($lastTime && (time() - strtotime($lastTime)) < 7200) {
                $remaining = 7200 - (time() - strtotime($lastTime));
                $mins = ceil($remaining / 60);
                return HTML_HIYEL . "{$npcName}摇头道：千字文你方才读过，过会儿再来自有体悟。还需等待{$mins}分钟。" . HTML_NOR;
            }

            // 给予潜能奖励
            $potentialGain = 50 + mt_rand(0, 50); // 50-100潜能

            // 检查是否有qianziwen_time字段
            $columns = Database::queryAll("DESCRIBE characters");
            $hasField = false;
            foreach ($columns as $col) {
                if ($col['Field'] === 'qianziwen_time') {
                    $hasField = true;
                    break;
                }
            }

            if ($hasField) {
                Database::execute(
                    "UPDATE characters SET potential = potential + ?, qianziwen_time = NOW() WHERE id = ?",
                    [$potentialGain, $charId]
                );
            } else {
                Database::execute(
                    "UPDATE characters SET potential = potential + ? WHERE id = ?",
                    [$potentialGain, $charId]
                );
            }

            $broadcastMsg = HTML_HICYN . "{$npcName}点头道：天地玄黄，宇宙洪荒...且听我细细讲来。" . HTML_NOR;
            $roomId = $char['current_room'] ?? '';
            if (!empty($roomId)) {
                MessageDaemon::broadcastToRoom($roomId, $broadcastMsg, $charId);
            }

            return HTML_HICYN . "{$npcName}从书架上取下一本《千字文》，缓缓讲道：天地玄黄，宇宙洪荒。日月盈昃，辰宿列张..." . HTML_NOR . "\n" .
                   HTML_HIGRN . "（你听了{$npcName}的讲解，似有所悟，获得了{$potentialGain}点潜能！）" . HTML_NOR;
        } catch (\Exception $e) {
            error_log('NpcInquiryHelper::handleQianZiWen error: ' . $e->getMessage());
            return HTML_HICYN . "{$npcName}摇头道：今日精神不济，改日再讲吧。" . HTML_NOR;
        }
    }

    /**
     * 处理广羲子"道德经"询问
     * 向玩家讲解道德经，给予道行奖励
     */
    public static function handleDaoDeJing(array $npc, array $char, string $topic, $extraParam = null)
    {
        $npcName = $npc['name'] ?? '广羲子';
        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';

        try {
            // 检查冷却时间（3小时）
            $charData = Database::queryOne(
                "SELECT daodejing_time FROM characters WHERE id = ?",
                [$charId]
            );

            $lastTime = $charData['daodejing_time'] ?? null;
            if ($lastTime && (time() - strtotime($lastTime)) < 10800) {
                $remaining = 10800 - (time() - strtotime($lastTime));
                $mins = ceil($remaining / 60);
                return HTML_HIYEL . "{$npcName}摇头道：道德经你方才听过，道可道，非常道。过会儿再来体悟吧。还需等待{$mins}分钟。" . HTML_NOR;
            }

            // 给予道行奖励
            $daoxingGain = 3 + mt_rand(0, 5); // 3-8道行

            // 检查是否有daoxing字段
            $columns = Database::queryAll("DESCRIBE characters");
            $hasDaoxing = false;
            $hasTimeField = false;
            foreach ($columns as $col) {
                if ($col['Field'] === 'daoxing') {
                    $hasDaoxing = true;
                }
                if ($col['Field'] === 'daodejing_time') {
                    $hasTimeField = true;
                }
            }

            if ($hasDaoxing) {
                if ($hasTimeField) {
                    Database::execute(
                        "UPDATE characters SET daoxing = daoxing + ?, daodejing_time = NOW() WHERE id = ?",
                        [$daoxingGain, $charId]
                    );
                } else {
                    Database::execute(
                        "UPDATE characters SET daoxing = daoxing + ? WHERE id = ?",
                        [$daoxingGain, $charId]
                    );
                }
            } else {
                // 如果没有daoxing字段，给潜能
                Database::execute(
                    "UPDATE characters SET potential = potential + ? WHERE id = ?",
                    [$daoxingGain * 20, $charId]
                );
                $gainText = "潜能" . ($daoxingGain * 20);
            }

            $broadcastMsg = HTML_HICYN . "{$npcName}抚须道：道可道，非常道。名可名，非常名..." . HTML_NOR;
            $roomId = $char['current_room'] ?? '';
            if (!empty($roomId)) {
                MessageDaemon::broadcastToRoom($roomId, $broadcastMsg, $charId);
            }

            $gainText = $gainText ?? "道行{$daoxingGain}";
            return HTML_HICYN . "{$npcName}从书架上取下一本《道德经》，缓缓讲道：道可道，非常道。名可名，非常名。无名天地之始，有名万物之母..." . HTML_NOR . "\n" .
                   HTML_HIGRN . "（你听了{$npcName}的讲解，心神俱醉，获得了{$gainText}点！）" . HTML_NOR;
        } catch (\Exception $e) {
            error_log('NpcInquiryHelper::handleDaoDeJing error: ' . $e->getMessage());
            return HTML_HICYN . "{$npcName}摇头道：今日精神不济，改日再讲吧。" . HTML_NOR;
        }
    }

    /**
     * 处理"try_me"询问（根据NPC身份分发）
     * 秦安（将军府总管，npc_id=qin_an 或 id=61）→ 发放俸银 → handleSalary
     * 皤不分（菩提祖师座下弟子）→ 传送到灵台山丘
     */
    public static function handleTryMe(array $npc, array $char, string $topic, $extraParam = null)
    {
        $npcId = $npc['npc_id'] ?? '';
        $npcDbId = intval($npc['id'] ?? 0);
        $npcName = $npc['name'] ?? '皤不分';

        // 秦安：将军府总管，发放俸银
        if ($npcId === 'qin_an' || stripos($npcName, '秦安') !== false || $npcDbId === 61) {
            return self::handleSalary($npc, $char, $topic, $extraParam);
        }

        // 默认：皤不分传送逻辑
        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';
        
        // 广播消息到当前房间
        $broadcastMsg = HTML_HIYEL . "{$npcName}双掌在{$charName}头上拍了一下不知搞什么鬼！" . HTML_NOR;
        $roomId = $char['current_room'] ?? '';
        
        if (!empty($roomId)) {
            MessageDaemon::broadcastToRoom($roomId, $broadcastMsg, $charId);
        }
        
        // 移动玩家到山丘房间
        try {
            require_once MODEL_PATH . 'Character.php';
            CharacterModel::updatePosition($charId, 'lingtai', 'lingtai/hill');
            
            // 返回移动指令给调用者
            return [
                'message' => HTML_HICYN . "{$npcName}说道：我也弄不懂唉。" . HTML_NOR . "\n" .
                           HTML_HIYEL . "{$npcName}双掌在{$charName}头上拍了一下不知搞什么鬼！" . HTML_NOR,
                'redirect' => room_url('lingtai', 'lingtai/hill'),
                'new_area' => 'lingtai',
                'new_room' => 'lingtai/hill'
            ];
        } catch (\Exception $e) {
            error_log('NpcInquiryHelper::handleTryMe error: ' . $e->getMessage());
            return "{$npcName}摇了摇头，说道：传送失败了，请稍后再试。";
        }
    }
    
    // =========================================================
    // 取经系统 handlers
    // =========================================================
    
    /**
     * 处理取经人询问"取经"话题
     * 原始LPC逻辑：ask_for_help() 函数
     */
    private static function handleQujingAskForHelp(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        require_once __DIR__ . '/../daemons/QujingHandler.php';
        return QujingHandler::handleAskForHelp($npc, $char, $topic);
    }
    
    /**
     * 处理申请护送取经人
     */
    private static function handleQujingApplyEscort(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        require_once __DIR__ . '/../daemons/QujingHandler.php';
        $result = QujingHandler::applyForEscort($char['id'], $npc['id']);
        
        if ($result['success']) {
            return $result['message'];
        }
        return null;
    }
    
    /**
     * 处理蒸笼老人天魔茧借用
     * 原始LPC逻辑：ask_fabao() 函数
     */
    private static function handleTianmojian(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '蒸笼老人';
        $charName = $char['name'] ?? '你';
        $charId = $char['id'];
        
        // 检查是否是取经人身份
        if (($char['obstacle/qujing'] ?? '') === 'ren') {
            return "{$npcName}说：想借宝你不够资格";
        }
        
        // 检查战斗经验
        $combatExp = intval($char['combat_exp'] ?? 0);
        if ($combatExp < 500000) {
            return "{$npcName}说：想借宝你不够资格";
        }
        
        // 检查是否有no_qujing标记
        $noQujing = intval($char['obstacle/no_qujing'] ?? 0);
        if ($noQujing) {
            $lastAskTime = intval($char['last_ask_fabao'] ?? 0);
            if ((time() - $lastAskTime) < 86400) { // 24小时
                return "{$npcName}对{$charName}露出不信任的眼神";
            }
        }
        
        // 检查是否已有天魔茧外借
        $lastJieId = Database::queryOne(
            "SELECT last_jie_id FROM obstacled WHERE id = 1 LIMIT 1"
        );
        
        if ($lastJieId && $lastJieId['last_jie_id']) {
            return "{$npcName}说：你来迟一步，已经被借走了";
        }
        
        // 检查是否已经有待确认的借宝请求
        $pendingVar = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'tianmojian_pending'",
            [$charId]
        );
        
        if ($pendingVar && !empty($pendingVar['temp_value'])) {
            $pendingData = json_decode($pendingVar['temp_value'], true);
            if ($pendingData && isset($pendingData['expire_time']) && $pendingData['expire_time'] > time()) {
                // 已经有待确认的请求，提示输入accept
                return "{$npcName}说：你已经申请借宝了，如果你确定要入魔道，请输入 accept 确认！";
            }
        }
        
        // 设置待确认状态（60秒内有效）
        $pendingData = json_encode([
            'request_time' => time(),
            'expire_time' => time() + 60, // 60秒内确认有效
        ]);
        
        Database::execute(
            "INSERT INTO character_temp (char_id, temp_key, temp_value) 
             VALUES (?, 'tianmojian_pending', ?)
             ON DUPLICATE KEY UPDATE temp_value = ?",
            [$charId, $pendingData, $pendingData]
        );
        
        // 记录询问时间
        Database::execute(
            "UPDATE characters SET last_ask_fabao = ? WHERE id = ?",
            [time(), $charId]
        );
        
        return "{$npcName}说：要想借我的天魔茧，可是要入我魔道的，从此以后你就不能参加取经了。如果你同意，请输入 accept 确认！";
    }
    
    /**
     * 处理蒸笼老人对话
     * 原始LPC逻辑：inquiry 映射
     */
    private static function handleLaoren(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '蒸笼老人';
        $charName = $char['name'] ?? '你';
        
        switch (strtolower($topic)) {
            case '借宝':
            case 'jiebao':
                return self::handleTianmojian($npc, $char, $topic);
                
            case '宝贝':
            case '法宝':
            case 'fabao':
                return "{$npcName}说：我这天魔茧可以缠住如何的东西，包括人";
                
            case 'back':
            case '回去':
                return self::handleLaorenBack($npc, $char);
                
            default:
                return null;
        }
    }
    
    private static function handleLaorenBack(array $npc, array $char): ?string
    {
        $npcName = $npc['name'] ?? '蒸笼老人';
        $charName = $char['name'] ?? '你';
        $charId = $char['id'];
            
        // 检查是否是护送取经人
        if (($char['obstacle/qujing'] ?? '') !== 'ren') {
            return "{$npcName}说：你又不是取经人，回去作甚？";
        }
            
        // 检查取经人是否在房间
        $quest = Database::queryOne(
            "SELECT quest_id FROM character_quests WHERE char_id = ? AND quest_type = 'qujing_escort' AND status = 'active' LIMIT 1",
            [$charId]
        );
        
        if (!$quest) {
            return "{$npcName}说：你又不是取经人，回去作甚？";
        }
        
        // 获取最后环境
        $lastEnv = Database::queryOne(
            "SELECT last_env FROM obstacled WHERE id = 1 LIMIT 1"
        );
        
        if (!$lastEnv || !$lastEnv['last_env']) {
            return "{$npcName}说：回不去了...";
        }
        
        // 传送玩家和取经人回去
        Database::execute(
            "UPDATE characters SET current_area = SUBSTRING_INDEX(?, '/', 1), current_room = ? WHERE id = ?",
            [$lastEnv['last_env'], $lastEnv['last_env'], $charId]
        );
        
        // 清理状态
        Database::execute(
            "UPDATE obstacled SET last_env = NULL, open_door = 0 WHERE id = 1"
        );

        return [
            'message' => "{$npcName}说：好小子，有本事，别让我再遇到你\n" .
                       HTML_HIYEL . "你忽然被一阵烟雾笼罩住...慢慢地什么都消失了" . HTML_NOR,
            'redirect' => $lastEnv['last_env'],
            'new_area' => substr_count($lastEnv['last_env'], '/') > 0 ?
                         substr($lastEnv['last_env'], 0, strpos($lastEnv['last_env'], '/')) :
                         $lastEnv['last_env'],
            'new_room' => $lastEnv['last_env']
        ];
    }

    // =========================================================
    // 唐僧取经对话系统 handlers
    // =========================================================

    /**
     * 处理"取经"话题
     */
    private static function handleQujingTopic(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '贫僧';

        return HTML_HICYN . "{$npcName}双手合十道：阿弥陀佛！贫僧奉唐王之命，前往西天大雷音寺求取真经，以救东土众生脱离苦海。\n" .
               "路上妖魔横行，需有缘人护送方能到达。如有意护送，可询问「护送」。" . HTML_NOR;
    }

    /**
     * 处理"护送"话题
     */
    private static function handleQujingEscort(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '贫僧';
        $charId = intval($char['id'] ?? 0);

        // 检查是否已有护送任务
        $activeQuest = Database::queryOne(
            "SELECT * FROM character_quests WHERE char_id = ? AND quest_type = 'qujing_escort' AND status = 'active' LIMIT 1",
            [$charId]
        );

        if ($activeQuest) {
            $currentStage = $activeQuest['current_stage'] ?? 1;
            $nextLocation = $activeQuest['next_location'] ?? '';
            
            if (empty($nextLocation)) {
                $currentQuestId = $activeQuest['quest_id'] ?? '';
                if ($currentQuestId) {
                    $nextQuestId = QujingHandler::getNextQuest($currentQuestId);
                    if ($nextQuestId) {
                        $nextQuestDef = QujingHandler::getQuestDefinition($nextQuestId);
                        $nextLocation = $nextQuestDef['name'] ?? '下一关';
                    } else {
                        $nextLocation = '灵山';
                    }
                } else {
                    $nextLocation = '未知';
                }
            }
            
            return HTML_HICYN . "{$npcName}说道：多谢施主护送！我们继续上路吧，下一站是「{$nextLocation}」。" . HTML_NOR;
        }

        // 检查是否在长安城
        $currentRoom = $char['current_room'] ?? '';
        if (strpos($currentRoom, 'city/entrance') === false) {
            return HTML_HICYN . "{$npcName}说道：施主若要护送贫僧取经，请到长安城门口来。" . HTML_NOR;
        }

        // 触发护送任务
        require_once __DIR__ . '/../daemons/QujingHandler.php';
        $result = QujingHandler::startEscortQuest($charId);

        return $result['message'] ?? HTML_HICYN . "{$npcName}说道：多谢施主护送，我们上路吧！" . HTML_NOR;
    }

    /**
     * 处理"西天/灵山"话题
     */
    private static function handleQujingXitian(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '贫僧';

        return HTML_HICYN . "{$npcName}遥望西方，双手合十道：西天大雷音寺，乃如来佛祖所在。\n" .
               "从大唐长安出发，需经过九九八十一难，方能到达灵山取得真经。\n" .
               "路上有妖怪阻拦，需施主们协力降服。" . HTML_NOR;
    }

    /**
     * 处理"如来"话题
     */
    private static function handleQujingRulai(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '贫僧';

        return HTML_HICYN . "{$npcName}恭敬道：如来佛祖乃西方极乐世界之主，法力无边。\n" .
               "求取真经后，可向佛祖求取无上法宝与神通。" . HTML_NOR;
    }

    /**
     * 处理"八十一难"话题
     */
    private static function handleQujingNan(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '贫僧';

        $obstacles = [
            '鹰愁涧', '大雪山', '黄风岭', '流沙河', '白骨岭',
            '宝象国', '平顶山', '乌鸡国', '火云洞', '黑水河',
            '车迟国', '通天河', '金兜山', '女儿国', '毒敌山',
            '火焰山', '积雷山', '祭赛国', '荆棘岭', '小西天',
            '朱紫国', '盘丝岭', '比丘国', '无底洞', '钦法国',
            '隐雾山', '凤仙郡', '玉华县', '金平府', '天竺国'
        ];

        return HTML_HICYN . "{$npcName}叹道：取经之路艰难险阻，共有九九八十一难。\n" .
               "主要关卡有：\n" . implode('、', array_slice($obstacles, 0, 10)) . "等。\n" .
               HTML_HIYEL . "每一关都有妖魔把守，需施主们齐心协力方能通过。" . HTML_NOR;
    }

    /**
     * 处理各关卡话题
     */
    private static function handleQujingObstacle(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '贫僧';

        $obstacleInfo = [
            'yingchou' => ['鹰愁涧', '说此地，路途艰险，需护送贫僧通过。'],
            'baoxiang' => ['宝象国', '百花羞公主被黄袍怪掳走，需救出公主。'],
            'pingding' => ['平顶山', '金角银角大王在此为妖，法宝众多。'],
            'wuji' => ['乌鸡国', '国王被青毛狮子冒充，文殊菩萨指点。'],
            'chechi' => ['车迟国', '三位大仙与和尚斗法，云禅台坐禅定输赢。'],
            'tongtian' => ['通天河', '金鳞大王霸占水域，需踏冰而过。'],
            'jindou' => ['金兜山', '独角兕大王持有金刚琢，刀枪不入。'],
            'nuerguo' => ['女儿国', '全国皆女，子母河河水可令人有孕。'],
            'firemount' => ['火焰山', '熊熊烈火阻路，需借铁扇公主的芭蕉扇。'],
            'jisaiguo' => ['祭赛国', '金光塔舍利被盗，需找回宝物。'],
            'xiaoxitian' => ['小西天', '黄眉老佛设下假雷音寺，需破金铙。'],
            'zhuzi' => ['朱紫国', '国王因射伤孔雀而相思成疾，需赛太岁金毛犼的破解。'],
            'pansi' => ['盘丝岭', '七只蜘蛛精在此结网，需小心应对。'],
            'biqiu' => ['比丘国', '国王受白鹿精蛊惑，需揭穿其阴谋。'],
            'wudidong' => ['无底洞', '老鼠精地窖复杂，需找到入口。'],
            'qinfa' => ['钦法国', '此地无人设宴款待，需自己想办法。'],
            'tianzhu' => ['天竺国', '取经终点，如来佛祖所在之地。'],
        ];

        // 中文话题名 -> 英文key 映射，用于 $topic 传入中文名时正确查找
        $topicKeyMap = [
            '鹰愁涧' => 'yingchou',
            '宝象国' => 'baoxiang',
            '平顶山' => 'pingding',
            '乌鸡国' => 'wuji',
            '车迟国' => 'chechi',
            '通天河' => 'tongtian',
            '金兜山' => 'jindou',
            '女儿国' => 'nuerguo',
            '火焰山' => 'firemount',
            '祭赛国' => 'jisaiguo',
            '小西天' => 'xiaoxitian',
            '朱紫国' => 'zhuzi',
            '盘丝岭' => 'pansi',
            '比丘国' => 'biqiu',
            '无底洞' => 'wudidong',
            '钦法国' => 'qinfa',
            '天竺国' => 'tianzhu',
        ];

        // 优先用英文key查找，若是中文话题则先转换
        $key = isset($obstacleInfo[$topic]) ? $topic : ($topicKeyMap[$topic] ?? null);

        if ($key && isset($obstacleInfo[$key])) {
            $info = $obstacleInfo[$key];
            return HTML_HICYN . "{$npcName}说道：说到" . $info[0] . "，" . $info[1] . "\n" .
                   "施主若能助我通过此关，必有重谢。" . HTML_NOR;
        }

        return null; // 未知关卡，降级处理
    }

    /**
     * 处理NPC询问
     * 
     * @param array $npc NPC数据
     * @param array $char 玩家数据
     * @param string $topic 询问话题
     * @return string|array|null 回答内容
     */
    public static function handleInquiry(array $npc, array $char, string $topic)
    {
        $inquiryData = !empty($npc['inquiry']) ? json_decode($npc['inquiry'], true) : [];
        
        if (!is_array($inquiryData)) {
            $inquiryData = [];
        }
        
        // 检查是否为可处理的询问话题
        if (isset($inquiryData[$topic])) {
            $response = $inquiryData[$topic];
            
            // 如果是callable类型，则调用对应的处理方法
            if (is_array($response) && $response[0] === 'callable') {
                $result = self::handleCallable($response, $npc, $char, $topic);
                
                // 如果返回数组（例如包含重定向信息），直接返回
                if (is_array($result)) {
                    return $result;
                }
                
                // 如果返回字符串，作为消息返回
                if (is_string($result)) {
                    return $result;
                }
                
                // 如果无法处理，返回null
                return null;
            } else {
                // 直接返回响应内容
                return $response;
            }
        }
        
        // 如果找不到特定话题，返回默认响应
        return null;
    }

    // =========================================================
    // 取经人申请系统 handlers（疥顶小僧）
    // =========================================================

    /**
     * 处理"申请"话题 - 申请成为取经人
     */
    private static function handleApplyQujingren(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '疥顶小僧';
        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';

        require_once __DIR__ . '/../daemons/QujingHandler.php';

        // 检查是否已有取经人
        $obstacled = Database::queryOne("SELECT * FROM obstacled WHERE id = 1 LIMIT 1");
        if ($obstacled && $obstacled['haved_qujingren'] == 1) {
            return HTML_HIYEL . "{$npcName}说道：已有取经人在路上，暂不接受新的申请。" . HTML_NOR;
        }

        // 检查是否在申请期
        if ($obstacled && $obstacled['choose_qjr'] == 0) {
            // 开启申请期
            Database::execute(
                "UPDATE obstacled SET choose_qjr = 1, this_qj_time = UNIX_TIMESTAMP() WHERE id = 1"
            );
        }

        // 检查申请条件
        $daoxing = intval($char['daoxing'] ?? 0);
        if ($daoxing < QujingHandler::MIN_DAOXING) {
            $required = intval(QujingHandler::MIN_DAOXING / 10000);
            $current = intval($daoxing / 10000);
            return HTML_HIRED . "{$npcName}摇摇头：你的道行不足{$required}万年，无法申请成为取经人。目前道行：{$current}万年。" . HTML_NOR;
        }

        // 检查是否是妖魔
        $profession = ProfessionHelper::getProfession($char);
        if ($profession === 'yaomo' || $profession === '妖魔') {
            return HTML_HIRED . "{$npcName}说道：妖魔不能成为取经人！" . HTML_NOR;
        }

        // 检查是否已经申请
        $existing = Database::queryOne(
            "SELECT * FROM qujing_applicants WHERE char_id = ? AND status = 'pending' LIMIT 1",
            [$charId]
        );

        if ($existing) {
            return HTML_HIYEL . "{$npcName}说道：你已经申请了，等待竞选结果吧。" . HTML_NOR;
        }

        // 添加申请
        try {
            // 获取当前最大的sequence值，然后+1
            $maxSeq = Database::queryOne("SELECT COALESCE(MAX(sequence), 0) as max_seq FROM qujing_applicants");
            $sequence = intval($maxSeq['max_seq'] ?? 0) + 1;
            
            Database::execute(
                "INSERT INTO qujing_applicants (char_id, char_name, daoxing, apply_time, status, sequence) 
                 VALUES (?, ?, ?, NOW(), 'pending', ?)",
                [$charId, $charName, $daoxing, $sequence]
            );

            // 更新参选人数
            Database::execute(
                "UPDATE obstacled SET number = number + 1 WHERE id = 1"
            );

            // 广播消息
            $broadcastMsg = HTML_HIYEL . "{$charName}申请成为取经人！" . HTML_NOR;
            MessageDaemon::broadcastToRoom('city/zhuque-s1', $broadcastMsg, $charId);

            return HTML_HIGRN . "{$npcName}双手合十：阿弥陀佛！施主已成功申请成为取经人候选人。\n" .
                   "竞选期为1天，届时将选出最终取经人。你可以询问「候选人」查看当前申请者。" . HTML_NOR;

        } catch (\Exception $e) {
            error_log('NpcInquiryHelper::handleApplyQujingren error: ' . $e->getMessage());
            return "{$npcName}叹了口气：申请失败，请稍后再试。";
        }
    }

    /**
     * 处理"竞选"话题 - 查看竞选状态
     */
    private static function handleQujingrenStatus(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '疥顶小僧';

        $obstacled = Database::queryOne("SELECT * FROM obstacled WHERE id = 1 LIMIT 1");

        if (!$obstacled) {
            return "{$npcName}说道：取经系统尚未初始化。";
        }

        // 已有取经人
        if ($obstacled['haved_qujingren'] == 1) {
            $qujingrenId = $obstacled['current_qujingren_id'] ?? $obstacled['husong'] ?? 0;
            $qujingrenName = '未知';
            
            if ($qujingrenId > 0) {
                $qujingren = Database::queryOne("SELECT name FROM characters WHERE id = ? LIMIT 1", [$qujingrenId]);
                $qujingrenName = $qujingren ? $qujingren['name'] : '未知';
            }
            
            return HTML_HICYN . "{$npcName}说道：当前取经人是{$qujingrenName}，正在路上取经。" . HTML_NOR;
        }

        // 申请期
        if ($obstacled['choose_qjr'] == 1) {
            $applyTime = intval($obstacled['this_qj_time'] ?? 0);
            $elapsed = time() - $applyTime;
            $remaining = QujingHandler::CHOOSE_INTERVAL - $elapsed;

            if ($remaining > 0) {
                $hours = intval($remaining / 3600);
                $mins = intval(($remaining % 3600) / 60);
                return HTML_HIYEL . "{$npcName}说道：竞选期进行中，剩余{$hours}小时{$mins}分钟。\n" .
                       "候选人可以互相PK竞争，最终胜者将成为取经人。" . HTML_NOR;
            } else {
                // 竞选期结束，自动选出取经人
                $result = QujingHandler::endChoosePeriod();
                if ($result['success']) {
                    return HTML_HICYN . "{$npcName}说道：竞选期已结束，即将选出取经人...\n" . 
                           $result['message'] . HTML_NOR;
                } else {
                    return HTML_HICYN . "{$npcName}说道：竞选期已结束，但系统选择出现问题。" . HTML_NOR;
                }
            }
        }

        // 等待下一轮
        $lastTime = intval($obstacled['this_qj_time'] ?? 0);
        $elapsed = time() - $lastTime;
        $remaining = QujingHandler::QJR_INTERVAL - $elapsed;

        if ($remaining > 0) {
            $days = intval($remaining / 86400);
            $hours = intval(($remaining % 86400) / 3600);
            return HTML_HIYEL . "{$npcName}说道：下一轮申请将在{$days}天{$hours}小时后开放。" . HTML_NOR;
        }

        return HTML_HICYN . "{$npcName}说道：申请期即将开放，请询问「申请」加入竞选。" . HTML_NOR;
    }

    /**
     * 处理"候选人"话题 - 查看候选人列表
     */
    private static function handleQujingrenCandidates(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '疥顶小僧';

        // 先检查是否需要执行选举
        $obstacled = Database::queryOne("SELECT * FROM obstacled WHERE id = 1 LIMIT 1");
        if ($obstacled && $obstacled['choose_qjr'] == 1) {
            $applyTime = intval($obstacled['this_qj_time'] ?? 0);
            $elapsed = time() - $applyTime;
            $remaining = QujingHandler::CHOOSE_INTERVAL - $elapsed;
            
            if ($remaining <= 0 && $obstacled['haved_qujingren'] == 0) {
                // 竞选期已结束，执行选举
                $result = QujingHandler::endChoosePeriod();
                if ($result['success']) {
                    return HTML_HICYN . "{$npcName}说道：竞选期已结束，即将选出取经人...\n" . 
                           $result['message'] . HTML_NOR;
                }
            }
        }

        $candidates = Database::queryAll(
            "SELECT char_name, daoxing, apply_time FROM qujing_applicants WHERE status = 'pending' ORDER BY daoxing DESC LIMIT 10"
        );

        if (empty($candidates)) {
            return HTML_HIYEL . "{$npcName}说道：目前还没有候选人申请。" . HTML_NOR;
        }

        $list = HTML_HICYN . "{$npcName}说道：当前候选人如下：\n" . HTML_NOR;
        foreach ($candidates as $i => $c) {
            $rank = $i + 1;
            $name = $c['char_name'] ?? '未知';
            $daoxing = intval($c['daoxing'] / 10000);
            $list .= HTML_HIYEL . "{$rank}. {$name} - 道行{$daoxing}万年\n" . HTML_NOR;
        }

        $list .= HTML_HICYN . "\n候选人可以互相PK竞争，道行最高者将优先成为取经人。" . HTML_NOR;

        return $list;
    }

    /**
     * 处理青髯老人"给书"询问
     * 原始LPC逻辑：玩家问book/guide/story/书/传说时，给一本《西游记西行求取真经指南》
     */
    private static function handleGiveIt(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '青髯老人';
        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';

        if ($charId <= 0) {
            return null;
        }

        try {
            // 检查是否已经给过书
            $given = Database::queryOne(
                "SELECT * FROM character_temp_states WHERE char_id = ? AND state_key = 'laoren_has_given'",
                [$charId]
            );

            if ($given) {
                // 已经给过了
                return HTML_HICYN . "{$npcName}轻轻向{$charName}摇了摇头：已经给过你了。\n" . HTML_NOR;
            }

            // 给玩家一本《西游记西行求取真经指南》
            $bookItemId = 'book-qujing';
            $bookName = '《西游记西行求取真经指南》';

            // 检查玩家背包中是否已有
            $existing = Database::queryOne(
                "SELECT * FROM character_inventory WHERE char_id = ? AND item_id = ?",
                [$charId, $bookItemId]
            );

            if ($existing) {
                // 已有这本书，也标记为已给过
                Database::execute(
                    "INSERT INTO character_temp_states (char_id, state_key, state_value, expire_time) VALUES (?, 'laoren_has_given', '1', DATE_ADD(NOW(), INTERVAL 30 DAY))
                     ON DUPLICATE KEY UPDATE state_value = '1', expire_time = DATE_ADD(NOW(), INTERVAL 30 DAY)",
                    [$charId]
                );
                return HTML_HICYN . "{$npcName}轻轻向{$charName}摇了摇头：已经给过你了。\n" . HTML_NOR;
            }

            // 添加到背包
            Database::execute(
                "INSERT INTO character_inventory (char_id, item_id, quantity, category) VALUES (?, ?, 1, 'obj')",
                [$charId, $bookItemId]
            );

            // 标记已给过
            Database::execute(
                "INSERT INTO character_temp_states (char_id, state_key, state_value, expire_time) VALUES (?, 'laoren_has_given', '1', DATE_ADD(NOW(), INTERVAL 30 DAY))
                 ON DUPLICATE KEY UPDATE state_value = '1', expire_time = DATE_ADD(NOW(), INTERVAL 30 DAY)",
                [$charId]
            );

            // 返回消息
            return HTML_HICYN . "{$npcName}在洞壁角落拿出一卷东西，递给{$charName}，然后慢慢闭上眼。\n" .
                   "你获得了一本{$bookName}。" . HTML_NOR;

        } catch (\Exception $e) {
            error_log("handleGiveIt error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 检查算命付费状态
     * @param int $charId 角色ID
     * @return array ['paid' => bool, 'asked' => bool]
     */
    private static function checkSuanmingPayment(int $charId): array {
        $result = Database::queryOne(
            'SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?',
            [$charId, 'suanming/paid']
        );
        
        $paid = false;
        if ($result) {
            $stateData = json_decode($result['state_value'], true);
            if (isset($stateData['expire_time']) && strtotime($stateData['expire_time']) >= time()) {
                $paid = true;
            }
        }
        
        $asked = Database::queryOne(
            'SELECT id FROM character_temp_states WHERE char_id = ? AND state_key = ?',
            [$charId, 'suanming/asked']
        ) !== null;
        
        return ['paid' => $paid, 'asked' => $asked];
    }

    /**
     * 设置算命询问标记
     */
    private static function setSuanmingAsked(int $charId): void {
        Database::execute(
            'INSERT INTO character_temp_states (char_id, state_key, state_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE updated_at = NOW()',
            [$charId, 'suanming/asked', json_encode(['set' => true])]
        );
    }

    /**
     * 清除算命状态
     */
    private static function clearSuanmingStatus(int $charId): void {
        Database::execute(
            'DELETE FROM character_temp_states WHERE char_id = ? AND state_key LIKE ?',
            [$charId, 'suanming/%']
        );
    }

    /**
     * 处理袁守诚"算命/算卦"询问 - 算寿命
     */
    private static function handleSuanming(array $npc, array $char, string $topic, $extraParam = null): ?string {
        $npcName = $npc['name'] ?? '袁守诚';
        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';
        
        if ($charId <= 0) {
            return null;
        }
        
        try {
            // 检查付费状态
            $paymentStatus = self::checkSuanmingPayment($charId);
            
            if (!$paymentStatus['paid']) {
                if ($paymentStatus['asked']) {
                    return "{$npcName}说道：在下正需一条金色鲤鱼，不知人兄能否搞来。";
                } else {
                    self::setSuanmingAsked($charId);
                    return "{$npcName}说道：这个．．．天机不可泄露啊。";
                }
            }
            
            // 已付费，计算寿命
            // 简化处理：基于年龄和根骨推算
            $age = intval($char['age'] ?? 14);
            $con = intval($char['con'] ?? 10);
            
            // 基础寿命80岁，每点根骨加1岁
            $totalLife = 80 + $con;
            $remaining = $totalLife - $age;
            
            if ($remaining <= 0) {
                $result = "你阳寿已尽，赶紧准备后事吧。";
            } else {
                $result = "你还有{$remaining}岁的寿命。";
            }
            
            // 清除付费状态
            self::clearSuanmingStatus($charId);
            
            return "{$npcName}掐指一算，对{$charName}说道：{$result}";
            
        } catch (\Exception $e) {
            error_log('NpcInquiryHelper::handleSuanming error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 处理袁守诚"福缘"询问 - 算福缘等级
     */
    private static function handleSuanFuyuan(array $npc, array $char, string $topic, $extraParam = null): ?string {
        $npcName = $npc['name'] ?? '袁守诚';
        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';
        
        if ($charId <= 0) {
            return null;
        }
        
        try {
            // 检查付费状态
            $paymentStatus = self::checkSuanmingPayment($charId);
            
            if (!$paymentStatus['paid']) {
                if ($paymentStatus['asked']) {
                    return "{$npcName}说道：在下正需一条金色鲤鱼，不知人兄能否搞来。";
                } else {
                    self::setSuanmingAsked($charId);
                    return "{$npcName}说道：这个．．．天机不可泄露啊。";
                }
            }
            
            // 已付费，计算福缘等级
            $kar = intval($char['kar'] ?? 10);
            $donation = intval($char['donation'] ?? 0);
            
            // 公式: (kar + donation/1000000) / 5 - 2
            $level = intval(($kar + $donation / 1000000) / 5 - 2);
            $level = max(0, min(6, $level));
            
            // 中文等级描述
            $levelDescriptions = [
                0 => '薄福之人',
                1 => '下福之人',
                2 => '中福之人',
                3 => '上福之人',
                4 => '厚福之人',
                5 => '鸿福之人',
                6 => '洪福齐天'
            ];
            
            $result = $levelDescriptions[$level] ?? '未知';
            
            // 清除付费状态
            self::clearSuanmingStatus($charId);
            
            return "{$npcName}掐指一算，对{$charName}说道：依我看，你乃是{$result}。";
            
        } catch (\Exception $e) {
            error_log('NpcInquiryHelper::handleSuanFuyuan error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 处理袁守诚"人参果"询问 - 算吃过的人参果数量
     */
    private static function handleSuanRsg(array $npc, array $char, string $topic, $extraParam = null): ?string {
        $npcName = $npc['name'] ?? '袁守诚';
        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';
        
        if ($charId <= 0) {
            return null;
        }
        
        try {
            // 检查付费状态
            $paymentStatus = self::checkSuanmingPayment($charId);
            
            if (!$paymentStatus['paid']) {
                if ($paymentStatus['asked']) {
                    return "{$npcName}说道：在下正需一条金色鲤鱼，不知人兄能否搞来。";
                } else {
                    self::setSuanmingAsked($charId);
                    return "{$npcName}说道：这个．．．天机不可泄露啊。";
                }
            }
            
            // 已付费，检查人参果食用数量
            $rsgState = Database::queryOne(
                'SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?',
                [$charId, 'rsg_eaten']
            );
            
            $rsgCount = 0;
            if ($rsgState) {
                $stateData = json_decode($rsgState['state_value'], true);
                $rsgCount = intval($stateData['count'] ?? $stateData['eaten'] ?? 0);
            }
            
            if ($rsgCount <= 0) {
                $result = "你还没吃过人参果吧？";
            } else {
                $result = "你已经吃了{$rsgCount}个人参果，真是福缘不浅啊。";
            }
            
            // 清除付费状态
            self::clearSuanmingStatus($charId);
            
            return "{$npcName}掐指一算，对{$charName}说道：{$result}";
            
        } catch (\Exception $e) {
            error_log('NpcInquiryHelper::handleSuanRsg error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 处理广羲子"千字文"借书询问
     * 还原原始项目逻辑：借千字文物品
     */
    private static function handleBorrowMe(array $npc, array $char, string $topic, $extraParam = null): ?string {
        return self::handleBorrowBook($npc, $char, 'qian', '千字文');
    }
    
    /**
     * 处理广羲子"道德经"借书询问
     * 还原原始项目逻辑：借道德经物品
     */
    private static function handleBorrMe(array $npc, array $char, string $topic, $extraParam = null): ?string {
        return self::handleBorrowBook($npc, $char, 'daode', '道德经');
    }
    
    /**
     * 通用借书处理逻辑
     */
    private static function handleBorrowBook(array $npc, array $char, string $bookId, string $bookName): ?string {
        $npcName = $npc['name'] ?? '广羲子';
        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';
        $npcId = intval($npc['id'] ?? 0);
        
        try {
            // 1. 检查是否是方寸山三星洞弟子（直接从数据库查询，确保数据准确）
            $charData = Database::queryOne(
                'SELECT family_name FROM characters WHERE id = ?',
                [$charId]
            );
            $familyName = $charData['family_name'] ?? '';
            
            if ($familyName !== '方寸山三星洞') {
                return "{$npcName}说：我们这里的书不外借！";
            }
            
            // 2. 检查是否有未还的书
            $pendingBook = Database::queryOne(
                'SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?',
                [$charId, 'pending/book']
            );
            
            if ($pendingBook && !empty($pendingBook['state_value'])) {
                return "{$npcName}对{$charName}说：上次借的还没还，怎么好再借给你呢？";
            }
            
            // 3. 检查识字技能等级（≥10级）
            require_once __DIR__ . '/SkillManager.php';
            $literateLevel = SkillManager::getSkillLevel($charId, 'literate');
            if ($literateLevel < 10) {
                return "{$npcName}对{$charName}说：你读书写字太差，借给你恐怕也看不懂啊。";
            }
            
            // 4. 检查书是否已经被借出去了（NPC状态）
            $npcBookStateKey = "npc_book_{$bookId}";
            $npcBookState = Database::queryOne(
                'SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?',
                [$npcId, $npcBookStateKey]
            );
            
            if ($npcBookState && !empty($npcBookState['state_value'])) {
                return "{$npcName}一拱手，说：刚借出去，明天再来吧！";
            }
            
            // 5. 给玩家书
            require_once __DIR__ . '/../models/Item.php';
            ItemModel::addToInventory($charId, $bookId, 1, 'obj');
            
            // 6. 设置玩家借书标记
            Database::execute(
                'INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) 
                 VALUES (?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = NOW()',
                [$charId, 'pending/book', $bookId]
            );
            
            // 7. 设置NPC借书标记
            Database::execute(
                'INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) 
                 VALUES (?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = NOW()',
                [$npcId, $npcBookStateKey, '1']
            );
            
            return "{$npcName}从架上拿下本书来递给{$charName}，说：记住要还唷！";
            
        } catch (\Exception $e) {
            error_log('NpcInquiryHelper::handleBorrowBook error: ' . $e->getMessage());
            return "{$npcName}说道：今日经阁不便借书，改日再来吧。";
        }
    }

    // =========================================================
    // 蓬莱三老挑战系统（禄星-交梨、寿星-碧藕、福星-火枣）
    // =========================================================

    /**
     * 处理蓬莱三老的挑战询问
     * 原始LPC逻辑：禄星(cross_me)、寿星(ask_me)、福星(ask_me)
     * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
     * 
     * 流程：
     * 1. 验证玩家combat_exp >= 要求值
     * 2. 检查玩家是否已有该物品
     * 3. 检查NPC是否有存货
     * 4. 如果满足条件，发起切磋（击败NPC到HP50%以下获得物品）
     */
    private static function handleAskMe(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        // 蓬莱三老配置
        $sanxingConfig = [
            'luxing' => [
                'name' => '禄星',
                'item_id' => 'jiaoli',
                'item_name' => '交梨',
                'exp_required' => 20000,
                'fight_mark' => 'luxing_fight',
                'stock_key' => 'luxing_jiaoli_stock',
                'cooldown_key' => 'luxing_jiaoli_cooldown',
                'last_winner_key' => 'luxing_last_winner',
            ],
            'shouxing' => [
                'name' => '寿星',
                'item_id' => 'biou',
                'item_name' => '碧藕',
                'exp_required' => 50000,
                'fight_mark' => 'shouxing_fight',
                'stock_key' => 'shouxing_biou_stock',
                'cooldown_key' => 'shouxing_biou_cooldown',
                'last_winner_key' => 'shouxing_last_winner',
            ],
            'fuxing' => [
                'name' => '福星',
                'item_id' => 'huozao',
                'item_name' => '火枣',
                'exp_required' => 30000,
                'fight_mark' => 'fuxing_fight',
                'stock_key' => 'fuxing_huozao_stock',
                'cooldown_key' => 'fuxing_huozao_cooldown',
                'last_winner_key' => 'fuxing_last_winner',
            ],
        ];

        $npcStringId = $npc['npc_id'] ?? '';
        $config = $sanxingConfig[$npcStringId] ?? null;

        if (!$config) {
            // 不在配置中的NPC，返回null表示无法处理
            return null;
        }

        $npcName = $npc['name'] ?? $config['name'];
        $itemName = $config['item_name'];
        $itemId = $config['item_id'];
        $expRequired = $config['exp_required'];
        $fightMark = $config['fight_mark'];
        $stockKey = $config['stock_key'];
        $cooldownKey = $config['cooldown_key'];

        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';
        $npcId = intval($npc['id'] ?? 0);
        
        if ($charId <= 0) {
            return null;
        }
        
        try {
            // 1. 检查实战经验是否达标
            $combatExp = intval($char['combat_exp'] ?? 0);
            if ($combatExp < $expRequired) {
                return HTML_HIYEL . "{$npcName}看了看你，摇头道：你的功夫还差得远呢，等修炼够了再来吧。" . HTML_NOR;
            }
            
            // 2. 检查玩家是否已经有该物品
            $hasItem = Database::queryOne(
                "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = ? LIMIT 1",
                [$charId, $itemId]
            );
            if ($hasItem) {
                return HTML_HIYEL . "{$npcName}笑道：你手里不是还有吗？别贪心啊。" . HTML_NOR;
            }
            
            // 3. 检查NPC是否有存货（使用 variables 表存储状态）
            $stockState = Database::queryOne(
                "SELECT `value` FROM variables WHERE var_key = ?",
                [$stockKey]
            );
            
            if (!$stockState) {
                // 首次初始化：有存货
                Database::execute(
                    "INSERT INTO variables (var_key, `value`, updated_at) VALUES (?, '1', NOW())",
                    [$stockKey]
                );
                $hasStock = true;
            } else {
                $hasStock = (intval($stockState['value']) > 0);
            }
            
            // 检查冷却时间（上次给予后需要等待）
            $cooldownState = Database::queryOne(
                "SELECT `value` FROM variables WHERE var_key = ?",
                [$cooldownKey]
            );
            
            if ($cooldownState) {
                $cooldownTime = intval($cooldownState['value']);
                if ($cooldownTime > 0 && time() < $cooldownTime) {
                    $remaining = $cooldownTime - time();
                    $mins = ceil($remaining / 60);
                    return HTML_HIYEL . "{$npcName}叹道：没了，没了，我也没了。过会儿再来吧。" . HTML_NOR;
                }
            }
            
            if (!$hasStock) {
                // 检查是否已过期（冷却结束后自动恢复）
                if ($cooldownState && intval($cooldownState['value']) > 0 && time() >= intval($cooldownState['value'])) {
                    // 冷却结束，恢复存货
                    Database::execute(
                        "UPDATE variables SET `value` = '1', updated_at = NOW() WHERE var_key = ?",
                        [$stockKey]
                    );
                } else {
                    return HTML_HIYEL . "{$npcName}叹道：没了，没了，我也没了。" . HTML_NOR;
                }
            }
            
            // 4. 检查玩家是否已在战斗中
            require_once DAEMON_PATH . 'CombatDaemon.php';
            if (CombatDaemon::isInCombat($charId)) {
                return HTML_HIYEL . "{$npcName}说道：你还在打斗中，等会儿再来找我吧。" . HTML_NOR;
            }
            
            // 5. 设置临时标记，允许与该NPC切磋
            Database::execute(
                "INSERT INTO character_temp (char_id, temp_key, temp_value) VALUES (?, ?, '1')
                 ON DUPLICATE KEY UPDATE temp_value = '1'",
                [$charId, $fightMark]
            );
            
            // 6. 发起切磋（不是击杀！）
            $result = CombatDaemon::startFight($charId, $npcId, 'npc');
            
            if ($result['success']) {
                // 切磋开始成功
                $msg = HTML_HICYN . "{$npcName}说道：好！那就让老夫试试你的功夫！" . HTML_NOR . "\n";
                $msg .= HTML_HIYEL . "{$npcName}微微一笑，摆开架势。" . HTML_NOR . "\n";
                $msg .= $result['message'] ?? '';
                
                return $msg;
            }
            
            // 切磋发起失败，清理标记
            Database::execute("DELETE FROM character_temp WHERE char_id = ? AND temp_key = ?", [$charId, $fightMark]);
            return HTML_HIYEL . "{$npcName}摇头道：现在不方便，改日再来吧。" . HTML_NOR;
            
        } catch (\Exception $e) {
            error_log('NpcInquiryHelper::handleAskMe error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return "{$npcName}叹道：今日不便，改日再来吧。";
        }
    }

    // =========================================================
    // 朱紫国国王任务系统
    // =========================================================

    /**
     * 处理朱紫国国王的 inquiry（test_player）
     * 对应原始 LPC：/d/qujing/zhuzi/npc/king.c → test_player()
     *
     * 流程：
     * 1. 战斗经验 < 10000 → 赶走
     * 2. 已完成任务或已治愈 → 感谢
     * 3. 国王已被治愈 → "朕躬已安"
     * 4. 默认 → 请玩家寻找乌金丹，设置 zhuzi_asked 标记，启动300秒等待
     */
    private static function handleZhuziKing(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '国王';
        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';
        $respect = self::getRespectName($char);

        // 条件1：战斗经验 < 10000 → 赶走
        $combatExp = intval($char['combat_exp'] ?? 0);
        if ($combatExp < 10000) {
            return HTML_HICYN . "{$npcName}说：「这位{$respect}你年纪轻轻，未经世事，懂什么医术？也来捣乱？」\n"
                . "{$npcName}勃然大怒，喝令侍从。\n"
                . "几位太监冲上前来，对你说道：「请吧。」" . HTML_NOR;
        }

        // 条件2：玩家已完成朱紫国任务 或 已治愈国王
        $obstacleDone = self::checkCharState($charId, 'zhuzi_cured');

        if ($obstacleDone) {
            return HTML_HICYN . "{$npcName}说：「这位{$respect}多谢多谢，无需再拜多礼。」" . HTML_NOR;
        }

        // 条件3：国王已被其他人治愈（全局标记）
        $kingCured = self::checkNpcTemp($npc['id'], 'cured');
        if ($kingCured) {
            return HTML_HICYN . "{$npcName}说：「朕躬已安，不劳费心。」" . HTML_NOR;
        }

        // 默认：请玩家寻找乌金丹
        // 设置玩家临时标记：已向国王询问过
        self::setCharState($charId, 'zhuzi_asked', '1');

        return HTML_HICYN . "{$npcName}说：「这位{$respect}你可否为朕寻找乌金丹？」" . HTML_NOR;
    }

    /**
     * 获取对玩家的尊称
     */
    private static function getRespectName(array $char): string
    {
        $gender = $char['gender'] ?? '';
        if ($gender === 'female') {
            return '女施主';
        }
        return '壮士';
    }

    /**
     * 检查玩家的临时状态（character_temp_states 表）
     */
    private static function checkCharState(int $charId, string $key): bool
    {
        $state = Database::queryOne(
            "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?",
            [$charId, $key]
        );
        return $state && $state['state_value'] == '1';
    }

    /**
     * 设置玩家的临时状态
     */
    private static function setCharState(int $charId, string $key, string $value): void
    {
        Database::execute(
            "INSERT INTO character_temp_states (char_id, state_key, state_value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE state_value = ?",
            [$charId, $key, $value, $value]
        );
    }

    /**
     * 检查NPC的临时状态（npc_temp 表）
     */
    private static function checkNpcTemp($npcId, string $key): bool
    {
        if (empty($npcId)) {
            return false;
        }
        $temp = Database::queryOne(
            "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = ?",
            [intval($npcId), $key]
        );
        return $temp && $temp['temp_value'] == '1';
    }

    // =========================================================
    // 阎罗王地狱传送系统
    // =========================================================

    /**
     * 处理阎罗王 send_me（传送至背阴山后参观）
     * 对应原始 LPC：/d/death/npc/yanluowang.c → send_me()
     */
    private static function handleSendMe(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '阎罗王';
        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';

        try {
            require_once MODEL_PATH . 'Character.php';
            CharacterModel::updatePosition($charId, 'death', 'death/emptyroom');

            return [
                'message' => HTML_HICYN . "{$npcName}对{$charName}点了点头，说道：你自己要去看守所，可不要怨别人。" . HTML_NOR,
                'redirect' => room_url('death', 'death/emptyroom'),
                'new_area' => 'death',
                'new_room' => 'death/emptyroom'
            ];
        } catch (\Exception $e) {
            error_log('NpcInquiryHelper::handleSendMe error: ' . $e->getMessage());
            return "{$npcName}挥了挥手，阴风四起，传送失败了……";
        }
    }

    // =========================================================
    // 掌门大弟子申请系统
    // =========================================================

    /**
     * 处理掌门大弟子申请（zm_apply）
     * 对应原始 LPC：各门派 /d/xxx/npc/zhangmen.c → zm_apply()
     *
     * 流程：
     * 1. 检查玩家是否与NPC同门派
     * 2. 检查玩家是否曾叛师
     * 3. 检查玩家是否已是掌门弟子
     * 4. 设置挑战标记，提示玩家挑战
     */
    private static function handleZmApply(array $npc, array $char, string $topic, $extraParam = null): ?string
    {
        $npcName = $npc['name'] ?? '大弟子';
        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';

        $npcFamily = $npc['family_name'] ?? $npc['sect_key'] ?? '';
        $charFamily = $char['family_name'] ?? $char['sect_key'] ?? '';

        // 条件1：同门派检查
        if (empty($charFamily) || $charFamily !== $npcFamily) {
            return HTML_HICYN . "{$npcName}看了看你，说道：你非我派中人，何必在此多问。" . HTML_NOR;
        }

        // 条件2：叛师检查
        if (!empty($char['betrayer']) || !empty($char['betray_count'])) {
            return HTML_HICYN . "{$npcName}冷哼一声：你曾叛师欺祖，言无信行不轨，岂能出任掌门弟子一职！" . HTML_NOR;
        }

        // 条件3：已是掌门弟子
        $isZm = self::checkCharState($charId, 'zm_applied');
        if ($isZm) {
            // 检查是否已经是掌门弟子
            $zmMaster = Database::queryOne(
                "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = 'zm_master'",
                [$charId]
            );
            if ($zmMaster && $zmMaster['state_value'] == '1') {
                return HTML_HICYN . "{$npcName}说道：你已是掌门弟子了，无需再问。" . HTML_NOR;
            }
        }

        // 默认：设置挑战申请标记
        $sectKey = $npc['sect_key'] ?? 'xueshan';
        self::setCharState($charId, 'zm_applied', '1');
        self::setCharState($charId, 'zm_sect', $sectKey);

        return HTML_HICYN . "{$npcName}目光如炬，打量着你。\n{$npcName}说道：对掌门弟子这个位子有兴趣？那就放马一战吧！" . HTML_NOR;
    }
    
    // =========================================================
    // 火焰山系统 handlers
    // =========================================================
    
    private static function handleFiremountTudiBone(array $npc, array $char, string $topic, $extraParam = null): ?string {
        require_once __DIR__ . '/FiremountNpcHelper.php';
        $charId = intval($char['id'] ?? 0);
        return FiremountNpcHelper::handleTudi($charId, $topic)['message'];
    }
    
    private static function handleFiremountBrotherIntroduce(array $npc, array $char, string $topic, $extraParam = null): ?string {
        require_once __DIR__ . '/FiremountNpcHelper.php';
        $charId = intval($char['id'] ?? 0);
        $npcId = $npc['npc_id'] ?? '';
        $result = FiremountNpcHelper::handleBrothers($charId, $npcId, $topic);
        return $result['message'];
    }
    
    private static function handleFiremountBrotherBone(array $npc, array $char, string $topic, $extraParam = null): ?string {
        require_once __DIR__ . '/FiremountNpcHelper.php';
        $charId = intval($char['id'] ?? 0);
        $npcId = $npc['npc_id'] ?? '';
        $result = FiremountNpcHelper::handleBrothers($charId, $npcId, $topic);
        return $result['message'];
    }
    
    private static function handleFiremountPrincessFan(array $npc, array $char, string $topic, $extraParam = null): ?string {
        require_once __DIR__ . '/FiremountNpcHelper.php';
        $charId = intval($char['id'] ?? 0);
        $result = FiremountNpcHelper::handlePrincess($charId, $topic);
        return $result['message'];
    }

    /**
     * 处理房玄龄"武状元"询问
     * 委托给WuzhuangyuanHandler处理
     */
    private static function handleWuzhuangyuan(array $npc, array $char, string $topic, $extraParam = null): ?string {
        require_once DAEMON_PATH . 'WuzhuangyuanHandler.php';
        return WuzhuangyuanHandler::handleInquiry($npc, $char, $topic, $extraParam);
    }

    /**
     * 处理太白金星"蟠桃会"询问
     * 委托给PantaohuiHandler处理
     */
    private static function handlePantaohui(array $npc, array $char, string $topic, $extraParam = null): mixed {
        require_once DAEMON_PATH . 'PantaohuiHandler.php';
        return PantaohuiHandler::handleInquiry($npc, $char, $topic, $extraParam);
    }

    private static function handlePantaohuiChallenge(array $npc, array $char, string $topic, $extraParam = null): mixed {
        require_once DAEMON_PATH . 'PantaohuiHandler.php';
        return PantaohuiHandler::handleChallengeInquiry($npc['name'], $char['id'], $char['name'], $char['daoxing'], $extraParam);
    }

    private static function handleXingxiu(array $npc, array $char, string $topic, $extraParam = null): ?string {
        require_once DAEMON_PATH . 'XingxiuHandler.php';
        return XingxiuHandler::handleInquiry($npc, $char, $topic, $extraParam);
    }

    private static function handleXingxiuChallenge(array $npc, array $char, string $topic, $extraParam = null): mixed {
        require_once DAEMON_PATH . 'XingxiuHandler.php';
        return XingxiuHandler::handleChallengeInquiry($npc['name'], $char['id'], $char['name'], $char['daoxing'], $extraParam);
    }

    /**
     * 处理龙珠系统询问（龙女NPC）
     * 委托给LongzhuHandler处理
     */
    private static function handleLongzhu(array $npc, array $char, string $topic, $extraParam = null): ?string {
        require_once DAEMON_PATH . 'LongzhuHandler.php';
        return LongzhuHandler::handleInquiry($npc, $char, $topic, $extraParam);
    }

    /**
     * 处理execute_help - 太白金星帮助信息
     */
    private static function handleExecuteHelp(array $npc, array $char, string $topic, $extraParam = null): ?string {
        $npcName = $npc['name'] ?? '太白金星';
        $msg = HTML_HICYN . "{$npcName}微微一笑，说道：" . HTML_NOR;
        $msg .= "你可以问我关于以下话题：\n\n";
        $msg .= HTML_HIYEL . "【蟠桃会相关】\n" . HTML_NOR;
        $msg .= "  蟠桃会 - 了解七曜神位\n";
        $msg .= "  封神榜 - 查看封神榜排名\n";
        $msg .= "  申请 - 申请神位\n";
        $msg .= "  御批 - 查询申请状态\n";
        $msg .= "  挑战 - 挑战神位替身\n";
        return $msg;
    }

    /**
     * 处理execute_ask - 太白金星通用询问
     */
    private static function handleExecuteAsk(array $npc, array $char, string $topic, $extraParam = null): ?string {
        $npcName = $npc['name'] ?? '太白金星';
        $lowerTopic = strtolower($topic);
        
        if (strpos($lowerTopic, '蟠桃') !== false || strpos($lowerTopic, '封神') !== false || 
            strpos($lowerTopic, '申请') !== false || strpos($lowerTopic, '御批') !== false || 
            strpos($lowerTopic, '挑战') !== false) {
            require_once DAEMON_PATH . 'PantaohuiHandler.php';
            return PantaohuiHandler::handleInquiry($npc, $char, $topic, $extraParam);
        }
        
        return HTML_HICYN . "{$npcName}想了想，说道：" . HTML_NOR . "你想问什么？关于蟠桃会、封神榜还是神位申请？";
    }

    /**
     * 精卫填海对话
     * 对应原始项目 jingwei.c 的 inquiry "填海"
     * 根据昼夜和精卫位置返回不同提示
     */
    public static function handleJingweiFillSea(array $npc, array $char, string $topic, $extraParam = null)
    {
        require_once DAEMON_PATH . 'JingweiDaemon.php';
        require_once DAEMON_PATH . 'NatureDaemon.php';

        $isNight = NatureDaemon::isNight();
        $state = JingweiDaemon::getState();

        if (!$isNight) {
            return '精卫说：天还太亮了，等天黑再来吧。夜晚我才能带你去填海。';
        }

        $charId = intval($char['id'] ?? 0);
        $charRoom = JingweiDaemon::normalizeRoom($char['current_area'] ?? '', $char['current_room'] ?? '');

        if ($charRoom === 'changan/beach') {
            $fillResult = JingweiDaemon::fillSea($charId);
            if ($fillResult['success']) {
                return $fillResult['message'];
            }
            return '精卫说：' . $fillResult['message'];
        }

        return '精卫说：填海是件艰苦危险的事，你要是觉得本领够了，可以随(sui)我一起去。';
    }
}
