<?php
/**
 * 通天河/陈家庄 区域事件处理器
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 核心机制：
 * 1. 冰道破冰：玩家进入冰道房间后，需变化为"小童"并携带"避水咒"才能安全沉入河底
 * 2. 河底寒冷：河底房间持续扣减气血(kee)
 * 3. Boss死亡：金鳞怪死亡时检查紫竹篮，决定是否召唤陈长老完成任务
 * 4. 化身NPC：黄发小童(化身)提供任务线索，推进obstacle状态
 * 
 * obstacle/tongtian 状态流转：
 *   (未设置) → won (首次击败Boss但无篮) → guanyin (询问化身获知线索) → ready (观音赐篮) → done (携篮击败Boss，陈长老确认)
 */

require_once __DIR__ . '/ActionHandler.php';

class TongtianHandler extends ActionHandler
{
    // ==================== 常量定义 ====================

    /** 冰道房间列表 */
    private const ICE_ROOMS = [
        'qujing/tongtian/bing1',
        'qujing/tongtian/bing2',
        'qujing/tongtian/bing3',
        'qujing/tongtian/bing4',
    ];

    /** 河底房间 */
    private const HEDI_ROOM = 'qujing/tongtian/hedi';

    /** 河底寒冷伤害间隔（秒） */
    private const COLD_INTERVAL = 10;

    /** 河底每次寒冷伤害值 */
    private const COLD_DAMAGE = 50;

    /** 妖怪NPC名称列表 (对应原版 yao.c names 数组) */
    private const YAO_NAMES = [
        '鲤鱼精', '鲫鱼精', '鳖精', '鲇鱼精', '水螺精',
        '蚌壳精', '鳌精', '水鲑精', '河鳗精', '鲞精',
    ];

    /** 通天河鱼妖可选武器 (对应原版 weapon0~3.c) */
    private const YAO_WEAPONS = [
        ['item_id' => 'fengbeng-dao', 'name' => '风崩刀', 'skill' => 'blade'],
        ['item_id' => 'xieao-qian',  'name' => '蟹螯钳', 'skill' => 'fork'],
        ['item_id' => 'manwei-bian', 'name' => '鳗尾鞭', 'skill' => 'whip'],
        ['item_id' => 'shimu-chui',  'name' => '石母锤', 'skill' => 'hammer'],
    ];

    /** 通天河鱼妖可选防具 (对应原版 armor.c) */
    private const YAO_ARMORS = [
        ['item_id' => 'wugui-jia',  'name' => '乌龟甲'],
        ['item_id' => 'wangba-jia', 'name' => '王八甲'],
        ['item_id' => 'qianling-jia', 'name' => '千鳞甲'],
        ['item_id' => 'juxie-ke',   'name' => '巨蟹壳'],
    ];

    /** 鱼妖NPC的 npc_id 列表 */
    private const FISH_DEMON_IDS = [
        'tongtian_yuyao_1', 'tongtian_yuyao_2', 'tongtian_yuyao_3',
    ];

    /** 鱼妖随机化刷新间隔（秒） */
    private const YAO_REFRESH_INTERVAL = 300;

    /** 化身NPC神仙名单 */
    private const DEITY_NAMES = [
        '太白金星',
        '时值功曹',
        '日值功曹',
        '月值功曹',
        '年值功曹',
        '惠岸行者',
        '净瓶使者',
        '云阳真人',
    ];

    /** 陈家庄男性居民名字池 (对应原版 people.c mnames) */
    private const CHEN_MALE_NAMES = [
        '陈康', '陈禄', '陈溯', '陈鸠', '陈蜀', '陈焘', '陈戛', '陈笮',
        '陈子虬', '陈龙大', '陈大头', '陈小个',
        '陈老大', '陈老二', '陈老三', '陈老四',
        '陈大伯', '陈大叔', '陈大舅', '陈大哥', '陈大爷',
        '陈二伯', '陈二叔', '陈二舅', '陈二哥', '陈二爷',
        '陈三伯', '陈三叔', '陈三舅', '陈三哥', '陈三爷',
        '陈四伯', '陈四叔', '陈四舅', '陈四哥', '陈四爷',
    ];

    /** 陈家庄女性居民名字池 (对应原版 people.c fnames) */
    private const CHEN_FEMALE_NAMES = [
        '陈娘', '陈氏', '陈婆', '陈妈', '陈嫂', '陈婶',
        '陈大娘', '陈大婆', '陈大妈', '陈大嫂', '陈大婶',
        '陈二娘', '陈二婆', '陈二妈', '陈二嫂', '陈二婶',
        '陈三娘', '陈三婆', '陈三妈', '陈三嫂', '陈三婶',
        '陈四娘', '陈四婆', '陈四妈', '陈四嫂', '陈四婶',
    ];

    /** 居民NPC npc_id 前缀 */
    private const PEOPLE_PREFIX = 'tongtian_people_';
    private const PEOPLE_COUNT = 10;

    /** 小童NPC npc_id 前缀 */
    private const KID_PREFIX = 'tongtian_kid_';
    private const KID_COUNT = 6;

    /** 民居房间列表 */
    private const MINJU_ROOMS = [
        'qujing/tongtian/minju1',
        'qujing/tongtian/minju2',
        'qujing/tongtian/minju3',
        'qujing/tongtian/minju4',
        'qujing/tongtian/minju5',
        'qujing/tongtian/minju6',
    ];

    // ==================== 配置加载 ====================

    /**
     * 配置缓存
     */
    private static ?array $tongtianConfig = null;

    /**
     * 加载通天河配置（优先从 config/tongtian.php 读取，带 fallback）
     */
    private static function loadTongtianConfig(): array {
        if (self::$tongtianConfig === null) {
            $configFile = __DIR__ . '/../config/tongtian.php';
            if (file_exists($configFile)) {
                self::$tongtianConfig = require $configFile;
            } else {
                self::$tongtianConfig = [];
            }
        }
        return self::$tongtianConfig;
    }

    // 通过方法读取配置（fallback 到类常量，避免无限递归）
    private static function getIceRooms(): array        { return self::loadTongtianConfig()['ice_rooms'] ?? self::ICE_ROOMS; }
    private static function getHediRoom(): string       { return self::loadTongtianConfig()['hedi_room'] ?? self::HEDI_ROOM; }
    private static function getColdInterval(): int      { return self::loadTongtianConfig()['cold_interval'] ?? self::COLD_INTERVAL; }
    private static function getColdDamage(): int        { return self::loadTongtianConfig()['cold_damage'] ?? self::COLD_DAMAGE; }
    private static function getYaoNames(): array        { return self::loadTongtianConfig()['yao_names'] ?? self::YAO_NAMES; }
    private static function getYaoWeapons(): array      { return self::loadTongtianConfig()['yao_weapons'] ?? self::YAO_WEAPONS; }
    private static function getYaoArmors(): array       { return self::loadTongtianConfig()['yao_armors'] ?? self::YAO_ARMORS; }
    private static function getFishDemonIds(): array    { return self::loadTongtianConfig()['fish_demon_ids'] ?? self::FISH_DEMON_IDS; }
    private static function getYaoRefreshInterval(): int{ return self::loadTongtianConfig()['yao_refresh_interval'] ?? self::YAO_REFRESH_INTERVAL; }
    private static function getDeityNames(): array      { return self::loadTongtianConfig()['deity_names'] ?? self::DEITY_NAMES; }
    private static function getChenMaleNames(): array   { return self::loadTongtianConfig()['chen_male_names'] ?? self::CHEN_MALE_NAMES; }
    private static function getChenFemaleNames(): array { return self::loadTongtianConfig()['chen_female_names'] ?? self::CHEN_FEMALE_NAMES; }
    private static function getMinjuRooms(): array      { return self::loadTongtianConfig()['minju_rooms'] ?? self::MINJU_ROOMS; }
    private static function getPeoplePrefix(): string   { return self::loadTongtianConfig()['people_prefix'] ?? self::PEOPLE_PREFIX; }
    private static function getPeopleCount(): int       { return self::loadTongtianConfig()['people_count'] ?? self::PEOPLE_COUNT; }
    private static function getKidPrefix(): string      { return self::loadTongtianConfig()['kid_prefix'] ?? self::KID_PREFIX; }
    private static function getKidCount(): int          { return self::loadTongtianConfig()['kid_count'] ?? self::KID_COUNT; }

    // ==================== ActionHandler 入口 ====================

    /**
     * 执行通天河区域动作
     * 根据 action_cmd 分发到具体处理方法
     */
    public function execute(int $charId, array $action, array $params = []): array
    {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../models/Character.php';
        require_once __DIR__ . '/../models/Room.php';
        require_once __DIR__ . '/MessageDaemon.php';

        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }

        $actionCmd = $action['action_cmd'] ?? '';

        switch ($actionCmd) {
            case 'cross_ice':
                return $this->handleCrossIce($charId, $char);
            case 'hedi_cold':
                return $this->handleHediCold($charId, $char);
            case 'exit_hedi':
                return $this->handleExitHedi($charId, $char);
            case 'ask_huashen':
                return $this->handleAskHuashen($charId, $char);
            case 'ask_guanyin_basket':
                return $this->handleAskGuanyinBasket($charId, $char);
            default:
                return ['success' => false, 'message' => '这里不能这样做。'];
        }
    }

    // ==================== 冰道破冰机制 ====================

    /**
     * 处理冰面穿越（cross_ice）
     * 
     * 原始LPC逻辑（bing1~4.c 共用）：
     * - test_player() 检查玩家是否变化为"小童"
     * - 如果是"小童"且携带"zhou"（避水咒）→ 安全沉入河底(hedi)
     * - 如果是"小童"但没有避水咒 → 掉入冰水，昏迷
     * - 如果不是"小童" → 冰面安全（仅环境音效）
     * 
     * H5版本适配：
     * - 通过角色变化状态(transform)检查名称是否为"小童"
     * - 通过背包检查是否持有避水咒(zhou)
     */
    public function handleCrossIce(int $charId, array $char): array
    {
        $roomId = $char['current_room'];

        // 验证是否在冰道房间
        if (!in_array($roomId, self::getIceRooms())) {
            return ['success' => false, 'message' => '这里没有冰面可以穿越。'];
        }

        $charName = $char['name'];

        // 检查玩家是否变化为"小童"
        $isKid = $this->isTransformedAs($charId, '小童');

        if (!$isKid) {
            // 不是小童形态 → 冰面安全，仅播放环境音效
            $msg = HTML_HICYN . '冰面上发出咔咔的响声，但你稳稳地站在上面，安然无事。' . HTML_NOR;
            MessageDaemon::broadcastToRoom($roomId, 
                HTML_HIYEL . $charName . '小心翼翼地走在冰面上，冰发出几声轻响。' . HTML_NOR, 
                $charId, 'room');
            return ['success' => true, 'message' => $msg];
        }

        // 是小童形态 → 检查是否携带避水咒
        $hasBishui = $this->hasItem($charId, 'zhou');

        if ($hasBishui) {
            // 有小童变化 + 避水咒 → 安全沉入河底
            return $this->sinkToHedi($charId, $char, true);
        } else {
            // 有小童变化但无避水咒 → 掉入冰水，昏迷
            return $this->sinkToHedi($charId, $char, false);
        }
    }

    /**
     * 沉入河底处理
     * 
     * @param int $charId 角色ID
     * @param array $char 角色数据
     * @param bool $safe 是否安全沉入（有避水咒）
     */
    private function sinkToHedi(int $charId, array $char, bool $safe): array
    {
        $roomId = $char['current_room'];
        $charName = $this->getDisplayName($char, $charId);

        // 广播：冰面裂开
        $crackMsg = HTML_HIYEL . '冰面上裂开一道裂缝，' . $charName . '一个趔趄不由自主地摔进水中！' . HTML_NOR;
        MessageDaemon::broadcastToRoom($roomId, $crackMsg, $charId, 'room');

        if (!$safe) {
            // 无避水咒 → 被拖出冰水，昏迷
            $rescueMsg = HTML_HIYEL . '众人赶来，连忙将' . $charName . '冰棍一般拖出冰水。' . HTML_NOR;
            MessageDaemon::broadcastToRoom($roomId, $rescueMsg, $charId, 'room');

            // 设置昏迷状态
            $this->setUnconscious($charId);

            return [
                'success' => false,
                'message' => HTML_HIRED . '冰面裂开，你摔进冰冷的河水中，浑身冻僵，被人拖了上来……你昏迷了过去。' . HTML_NOR,
            ];
        }

        // 有避水咒 → 安全沉入河底
        $targetArea = 'qujing';
        $targetRoom = 'qujing/tongtian/hedi';
        CharacterModel::updatePosition($charId, $targetArea, $targetRoom);

        $hediRoom = RoomModel::load($targetArea, $targetRoom);
        $arriveMsg = HTML_HIYEL . $charName . '身影一闪，沉入了冰面之下。' . HTML_NOR;
        MessageDaemon::broadcastToRoom($roomId, $arriveMsg, $charId, 'room');

        // 河底到达消息
        $personalMsg = HTML_HICYN . ($hediRoom['name'] ?? '河底') . HTML_NOR . "\n";
        $personalMsg .= ($hediRoom['description'] ?? '……冰冷的河底……') . "\n";
        $personalMsg .= HTML_HIBLU . '你借助避水咒的力量，安全穿过冰层，来到了通天河河底。四周一片幽暗冰冷。' . HTML_NOR;

        // 广播到达河底
        $hediArriveMsg = HTML_HIYEL . '只见冰面上泛起一串气泡，' . $charName . '的身影从水下浮现。' . HTML_NOR;
        MessageDaemon::broadcastToRoom($targetRoom, $hediArriveMsg, $charId, 'room');

        return [
            'success' => true,
            'type' => 'move',
            'message' => $personalMsg,
            'redirect' => 'room.php?area=qujing&room=qujing/tongtian/hedi',
        ];
    }

    // ==================== 河底环境伤害 ====================

    /**
     * 处理河底寒冷伤害（hedi_cold）
     * 
     * 原始LPC逻辑（hedi.c）：
     * - 每5-15秒检测一次
     * - 如果kee > 50，扣减50点气血
     * - 循环触发直到玩家离开河底
     * 
     * H5版本适配：
     * - 作为主动动作触发（玩家点击或进入房间时自动调用）
     * - 使用 character_temp_states 记录上次受寒时间，控制间隔
     */
    public function handleHediCold(int $charId, array $char): array
    {
        $roomId = $char['current_room'];

        // 验证是否在河底房间
        if ($roomId !== self::getHediRoom()) {
            return ['success' => false, 'message' => '你不在河底。'];
        }

        // 检查受寒间隔（防止频繁触发）
        $lastColdTime = $this->getTempState($charId, 'tongtian_last_cold');
        $now = time();
        if ($lastColdTime && ($now - intval($lastColdTime)) < self::getColdInterval()) {
            return ['success' => true, 'message' => '']; // 间隔未到，静默返回
        }

        // 记录本次受寒时间
        $this->setTempState($charId, 'tongtian_last_cold', strval($now));

        $kee = intval($char['kee'] ?? 0);
        $damage = 0;

        if ($kee > self::getColdDamage()) {
            $damage = self::getColdDamage();
            Database::execute(
                'UPDATE characters SET kee = kee - ? WHERE id = ?',
                [$damage, $charId]
            );
        }

        $msg = HTML_HIBLU . '冰冷的河水包围着你，你冻得浑身发抖。' . HTML_NOR;
        if ($damage > 0) {
            $msg .= "\n" . HTML_HIRED . '【气血 -' . $damage . '】' . HTML_NOR;
        }

        // 广播环境消息
        MessageDaemon::broadcastToRoom($roomId,
            HTML_HIBLU . '河底寒气逼人，冰冷刺骨。' . HTML_NOR,
            $charId, 'room');

        return ['success' => true, 'message' => $msg];
    }

    /**
     * 处理从河底上浮（exit_hedi）
     * 原始LPC：河底唯一出口是 up → bing2
     */
    public function handleExitHedi(int $charId, array $char): array
    {
        $roomId = $char['current_room'];
        if ($roomId !== self::getHediRoom()) {
            return ['success' => false, 'message' => '你不在河底。'];
        }

        $charName = $this->getDisplayName($char, $charId);
        $targetArea = 'qujing';
        $targetRoom = 'qujing/tongtian/bing2';

        // 广播离开
        MessageDaemon::broadcastToRoom($roomId,
            HTML_HIYEL . $charName . '奋力向上游去，身影消失在冰层裂缝中。' . HTML_NOR,
            $charId, 'room');

        CharacterModel::updatePosition($charId, $targetArea, $targetRoom);

        // 广播到达
        MessageDaemon::broadcastToRoom($targetRoom,
            HTML_HIYEL . '冰面上突然冒出一串气泡，' . $charName . '从水中浮了上来。' . HTML_NOR,
            $charId, 'room');

        $bingRoom = RoomModel::load($targetArea, $targetRoom);
        $personalMsg = HTML_HICYN . ($bingRoom['name'] ?? '冰道') . HTML_NOR . "\n";
        $personalMsg .= ($bingRoom['description'] ?? '') . "\n";
        $personalMsg .= HTML_HIYEL . '你从河底浮上来，重新回到了冰面上。' . HTML_NOR;

        // 清除河底受寒时间记录
        $this->deleteTempState($charId, 'tongtian_last_cold');

        return [
            'success' => true,
            'type' => 'move',
            'message' => $personalMsg,
            'redirect' => 'room.php?area=qujing&room=qujing/tongtian/bing2',
        ];
    }

    // ==================== 金鳞怪Boss死亡逻辑 ====================

    /**
     * 处理金鳞怪死亡事件
     * 由 CombatDaemon::handleNpcDeath() 调用
     * 
     * 原始LPC逻辑（jinyu.c die()）：
     * 1. 检查击杀者是否有 quest/pending/kill 任务匹配
     * 2. 检查击杀者是否携带紫竹篮(devine basket)
     *    - 有篮子：播放金鱼现原形→收入竹篮→飞向南诲动画，召唤陈长老
     *    - 无篮子：金鳞怪使障眼法溜掉，设置 obstacle/tongtian = "won"
     *    - 有篮子时不设置状态，由 chenAnnounceSuccess 直接设为 "done"
     */
    public static function handleJinyuBossDeath(int $npcId, array $npc, ?int $killerId, ?string $killerName): void
    {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../models/Character.php';
        require_once __DIR__ . '/MessageDaemon.php';

        if (!$killerId) {
            return;
        }

        $roomId = $npc['spawn_room'] ?? 'qujing/tongtian/hedi';
        $killer = CharacterModel::find($killerId);
        if (!$killer) {
            return;
        }

        // 检查灭妖任务是否匹配
        $pendingKill = self::getTempStateStatic($killerId, 'quest/pending/kill/name');
        if ($pendingKill && $pendingKill === ($npc['name'] ?? '')) {
            self::setTempStateStatic($killerId, 'quest/pending/kill/done', '1');
        }

        // 检查是否携带紫竹篮
        $hasBasket = self::hasItemStatic($killerId, 'devine_basket');

        // 只有未持有紫竹篮时才设置 "won"（击败Boss但无法收妖）
        // 持有紫竹篮时状态为 "ready"，chenAnnounceSuccess 会将其设为 "done"
        if (!$hasBasket) {
            self::setObstacleState($killerId, 'tongtian', 'won');
        }
        self::setTempStateStatic($killerId, 'obstacle/tongtian_killed', '1');

        if ($hasBasket) {
            // 有紫竹篮 → 播放完整收妖动画
            self::playBasketCaptureAnimation($killerId, $killerName, $roomId, $npc);
            // 召唤陈长老完成任务
            self::chenAnnounceSuccess($killerId, $killer);
        } else {
            // 无紫竹篮 → Boss逃跑
            $escapeMsg = HTML_HIYEL . $npc['name'] . '一见不敌，趁机使个障眼法溜掉了。' . HTML_NOR;
            MessageDaemon::broadcastToRoom($roomId, $escapeMsg, $killerId, 'room');
            if ($killerId > 0) {
                MessageDaemon::sendToPlayer($killerId, $escapeMsg, 'combat');
            }
        }

        log_game('TONGTIAN_BOSS', "金鳞怪被{$killerName}击败，hasBasket=" . ($hasBasket ? '1' : '0'));
    }

    /**
     * 播放紫竹篮收妖动画
     * 
     * 原始LPC消息序列：
     * 1. "只见紫竹篮刹那间飞上天..."
     * 2. "一甩尾巴，现出金鱼的原身。"
     * 3. "眼看金鱼乖乖地一纵身扭着腰摇晃着头游进了竹篮中"
     * 4. "目从竹篮向南海方向飞去了..."
     */
    private static function playBasketCaptureAnimation(int $killerId, string $killerName, string $roomId, array $npc): void
    {
        require_once __DIR__ . '/MessageDaemon.php';

        $npcName = $npc['name'] ?? '金鳞怪';
        $messages = [
            HTML_HIMAG . "\n你只见紫竹篮刹那间飞上天．．．" . HTML_NOR,
            HTML_HIYEL . "\n" . $npcName . "一甩尾巴，现出金鱼的原身。" . HTML_NOR,
            HTML_HICYN . "你眼看金鱼乖乖地一纵身扭着腰摇晃着头游进了竹篮中。" . HTML_NOR,
            HTML_HIGRN . "紫竹篮向南海方向飞去了．．．" . HTML_NOR,
        ];

        $fullMsg = implode("\n", $messages);

        // 广播给房间（让其他玩家也能看到）
        MessageDaemon::broadcastToRoom($roomId, $fullMsg, $killerId, 'room');

        // 发送给击杀者
        if ($killerId > 0) {
            MessageDaemon::sendToPlayer($killerId, $fullMsg, 'combat');
        }

        // 全服广播
        $broadcastMsg = HTML_HIGRN . '【通天河】' . HTML_HIYEL . $killerName . '通天河救童男女，水宅降鱼精！' . HTML_NOR;
        MessageDaemon::broadcastToAll($broadcastMsg);
    }

    /**
     * 陈长老确认任务完成
     * 
     * 原始LPC逻辑（chen.c announce_success()）：
     * - 检查 combat_exp >= 10000
     * - 检查 obstacle/tongtian 不等于 "done"
     * - 检查 obstacle/tongtian_killed 临时标记
     * - obstacle/number += 1
     * - obstacle/tongtian = "done"
     * - 道行奖励已被注释禁用
     */
    private static function chenAnnounceSuccess(int $killerId, array $killer): void
    {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/MessageDaemon.php';

        // 战斗经验检查
        $combatExp = intval($killer['combat_exp'] ?? 0);
        if ($combatExp < 10000) {
            return;
        }

        // 已完成检查
        $currentState = self::getObstacleState($killerId, 'tongtian');
        if ($currentState === 'done') {
            return;
        }

        // 击杀标记检查
        $killedFlag = self::getTempStateStatic($killerId, 'obstacle/tongtian_killed');
        if (!$killedFlag) {
            return;
        }

        // 通关计数+1
        $currentNumber = intval(self::getTempStateStatic($killerId, 'obstacle/number') ?? 0);
        self::setTempStateStatic($killerId, 'obstacle/number', strval($currentNumber + 1));

        // 设置完成状态
        self::setObstacleState($killerId, 'tongtian', 'done');

        // 陈长老出现并宣布
        $chenMsg = HTML_HIGRN . '陈长老出现在你面前，向你合十道：' . HTML_NOR . "\n" .
                   HTML_HIYEL . '"多谢' . $killer['name'] . '降服了那金鳞怪，救了我陈家庄的童男童女！' . "\n" .
                   '此怪原来是一条金鱼精，通天河从此太平了。"' . HTML_NOR;
        MessageDaemon::sendToPlayer($killerId, $chenMsg, 'self_event');

        // 全服广播
        $globalMsg = HTML_HIGRN . '【通天河】' . HTML_HIYEL . $killer['name'] . '顺利闯过西行又一关！' . HTML_NOR;
        MessageDaemon::broadcastToAll($globalMsg);

        log_game('TONGTIAN_DONE', "{$killer['name']} 完成通天河障碍，总通关数=" . ($currentNumber + 1));
    }

    // ==================== 化身NPC对话逻辑 ====================

    /**
     * 处理化身NPC问话（ask_huashen）
     * 
     * 原始LPC逻辑（huashen.c tongtian()）：
     * - combat_exp < 4000：
     *   揭示真身，提示"取经路上降妖除魔，要凭真本事啊！"，消失
     * - obstacle/tongtian == "won"：
     *   揭示真身，告知金鳞怪是观音莲池金鲤，设置 obstacle/tongtian = "guanyin"，消失
     * - 其他情况：不回应
     */
    public function handleAskHuashen(int $charId, array $char): array
    {
        $roomId = $char['current_room'];
        $charName = $char['name'];

        // 获取随机神仙名
        $deityName = self::getDeityNames()[array_rand(self::getDeityNames())] . '的化身';

        // 获取尊重称呼（简化版）
        $respect = $this->getRespectTitle($char);

        $obstacleState = $this->getObstacleState($charId, 'tongtian');
        $combatExp = intval($char['combat_exp'] ?? 0);

        // 条件1：经验不足，提示需凭真本事
        if ($combatExp < 4000) {
            $msg = HTML_HIYEL . $deityName . '说道：这位' . $respect . '，取经路上降妖除魔，要凭真本事啊！' . HTML_NOR . "\n";
            $msg .= HTML_HICYN . $deityName . '化作一道白光不见了。' . HTML_NOR;

            MessageDaemon::broadcastToRoom($roomId,
                HTML_HIYEL . $charName . '向黄发小童打听通天河的消息。' . HTML_NOR,
                $charId, 'room');

            return ['success' => true, 'message' => $msg];
        }

        // 条件2：已获胜，告知观音线索
        if ($obstacleState === 'won') {
            $msg = HTML_HIYEL . $deityName . '说道：这位' . $respect . '有所不知，此怪乃昔年南海观音莲池里的金鲤——' . "\n" .
                   '被它成了精出来在下界为恶．．．观音必有降它之法也。' . HTML_NOR . "\n";
            $msg .= HTML_HICYN . $deityName . '化作一道白光不见了。' . HTML_NOR;

            // 推进 obstacle 状态到 "guanyin"
            $this->setObstacleState($charId, 'tongtian', 'guanyin');

            MessageDaemon::broadcastToRoom($roomId,
                HTML_HIYEL . $charName . '向黄发小童打听通天河的消息。' . HTML_NOR,
                $charId, 'room');

            // 任务提示
            $hintMsg = HTML_HIGRN . '【通天河】你得知金鳞怪原来是南海观音莲池里的金鲤成精，也许可以去南海普陀山求助观音菩萨。' . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $hintMsg, 'self_event');

            return ['success' => true, 'message' => $msg];
        }

        // 其他情况：不回应
        return [
            'success' => true,
            'message' => HTML_HIYEL . '黄发小童看了看你，笑而不语。' . HTML_NOR,
        ];
    }

    // ==================== 观音赐篮 ====================

    /**
     * 处理观音赐篮（ask_guanyin_basket）
     * 
     * 当 obstacle/tongtian == "guanyin" 时，观音菩萨赐予紫竹篮
     * 玩家携带紫竹篮后再去打 Boss，触发完整收妖动画
     * 
     * 原著对应：西游记第四十九回，观音用紫竹篮收服金鱼精
     */
    public function handleAskGuanyinBasket(int $charId, array $char): array
    {
        require_once __DIR__ . '/MessageDaemon.php';

        // 验证是否在潮音洞
        $roomId = $char['current_room'] ?? '';
        if ($roomId !== 'nanhai/chaoyindong') {
            return ['success' => false, 'message' => '这里不能这样做。'];
        }

        $obstacleState = self::getObstacleState($charId, 'tongtian');

        // 必须处于 "guanyin" 状态才能获得紫竹篮
        if ($obstacleState !== 'guanyin') {
            if ($obstacleState === null) {
                return [
                    'success' => true,
                    'message' => HTML_HIYEL . '观音菩萨端坐莲台之上，慈目微垂，似在入定。你不敢打扰。' . HTML_NOR,
                ];
            }
            if ($obstacleState === 'done') {
                return [
                    'success' => true,
                    'message' => HTML_HIGRN . '观音菩萨微笑道：通天河之事已了，不必再来。' . HTML_NOR,
                ];
            }
            // 其他状态（won/ready等）：时机未到或已完成前置步骤
            return [
                'success' => true,
                'message' => HTML_HIYEL . '观音菩萨端坐莲台，慈目微垂。你感觉自己修为尚浅，也许该先去通天河打听些消息。' . HTML_NOR,
            ];
        }

        // 检查是否已经有紫竹篮
        $hasBasket = self::hasItemStatic($charId, 'devine_basket');
        if ($hasBasket) {
            return [
                'success' => true,
                'message' => HTML_HIGRN . '观音菩萨微笑道：紫竹篮已在你处，快去降妖吧。' . HTML_NOR,
            ];
        }

        // 赐予紫竹篮
        require_once MODEL_PATH . 'Item.php';
        ItemModel::addToInventory($charId, 'devine_basket', 1);

        // 推进 obstacle 状态：从 guanyin 变为 ready（准备好去打 Boss）
        self::setObstacleState($charId, 'tongtian', 'ready');

        // 构建消息
        $msg = HTML_HIMAG . "\n观音菩萨缓缓睁开慧目，微微一笑道：\n" . HTML_NOR;
        $msg .= HTML_HIYEL . '"那通天河中的妖怪，原是南海莲池里的金鱼，偷跑下界为祸。"' . HTML_NOR . "\n";
        $msg .= HTML_HIYEL . '"今赐你紫竹篮一只，可收服此妖。切记，需在它现出原形时使用。"' . HTML_NOR . "\n\n";
        $msg .= HTML_HICYN . '观音菩萨从袖中取出一只紫竹编织的精巧篮子，轻轻递给你。' . HTML_NOR . "\n";
        $msg .= HTML_HIGRN . '（获得：紫竹篮）' . HTML_NOR . "\n";
        $msg .= HTML_HIGRN . '【通天河】带着紫竹篮回到通天河河底，击败金鳞怪即可收服此妖！' . HTML_NOR;

        // 广播
        $roomId = $char['current_room'] ?? '';
        if (!empty($roomId)) {
            MessageDaemon::broadcastToRoom($roomId,
                HTML_HIMAG . '观音菩萨从袖中取出一只紫竹篮，递给了' . $char['name'] . '。' . HTML_NOR,
                $charId, 'room');
        }

        // 写入聊天
        MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');

        log_game('TONGTIAN_GUANYIN', "{$char['name']} 获得紫竹篮，准备收妖");

        return [
            'success' => true,
            'message' => $msg,
        ];
    }

    // ==================== 化身NPC被攻击消失 ====================

    /**
     * 处理化身NPC被攻击事件
     * 
     * 原版LPC huashen.c kill_ob()：
     * - 揭示神仙身份（随机从8个神仙名中选一个）
     * - 播放"一声冷笑,刹那间就不见了踪影"消息
     * - NPC消失（destruct）
     * 
     * H5版本适配：
     * - 将NPC临时移到 void 房间
     * - 设置重生倒计时（5分钟后自动恢复）
     * 
     * @param int $charId 攻击者角色ID
     * @param array $char 角色数据
     * @param array $npc 化身NPC数据
     * @return array 处理结果
     */
    public static function handleHuashenAttack(int $charId, array $char, array $npc): array
    {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../models/Character.php';
        require_once __DIR__ . '/MessageDaemon.php';

        // 随机神仙名
        $deityName = self::getDeityNames()[array_rand(self::getDeityNames())] . '的化身';

        $roomId = $char['current_room'];
        $charName = $char['name'];

        // 广播：玩家试图攻击化身
        MessageDaemon::broadcastToRoom($roomId,
            HTML_HIYEL . $charName . '拔刀向黄发小童砍去！' . HTML_NOR,
            $charId, 'room');

        // 广播：化身揭示身份并消失
        $vanishMsg = HTML_HICYN . $deityName . '一声冷笑，刹那间就不见了踪影。' . HTML_NOR;
        MessageDaemon::broadcastToRoom($roomId, $vanishMsg, $charId, 'room');

        // 个人消息
        $personalMsg = HTML_HIRED . '你一刀砍去，黄发小童身影一晃，化作一道白光消失不见。' . HTML_NOR . "\n";
        $personalMsg .= HTML_HICYN . '只听空中隐约传来："' . $deityName . '"的声音……' . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $personalMsg, 'self_event');

        // 将化身NPC临时移到void房间（模拟消失）
        $npcDbId = intval($npc['id']);
        Database::execute(
            "UPDATE npcs SET spawn_room = '_void_huashen_temp' WHERE id = ?",
            [$npcDbId]
        );

        // 设置恢复计时器（5分钟后自动恢复到原位置）
        self::setTempStateStatic($charId, 'tongtian_huashen_vanished', strval(time()));

        log_game('HUASHEN_ATTACK', "{$char['name']} 攻击化身NPC，化身消失于 {$roomId}");

        // 重定向回房间
        $area = $char['current_area'] ?? 'qujing';
        $room = $char['current_room'] ?? '';

        return [
            'success' => true,
            'type' => 'message',
            'message' => $personalMsg,
            'redirect' => "room.php?area=" . urlencode($area) . "&room=" . urlencode($room),
        ];
    }

    // ==================== 鱼妖动态随机生成 ====================

    /**
     * 随机化河底鱼妖NPC（名称、属性、装备、技能）
     * 
     * 参考原版LPC yao.c：
     * - 10种鱼精名随机选一个
     * - i = random(5)+1 作为倍率(1~5)，影响所有属性和技能
     * - 固定穿一件壳甲（4选1）
     * - 随机装备一把通天河武器（4选1）
     * 
     * 由 Room::getNpcsInRoom() 在加载 hedi 房间时调用
     * 使用Session存储种子，保证一次访问内NPC属性一致
     * 
     * @param int $charId 当前角色ID
     * @param array $npcs NPC列表（引用传递，就地修改）
     */
    public static function randomizeFishDemons(int $charId, array &$npcs): void
    {
        // 仅处理鱼妖NPC
        $yaoIndices = [];
        foreach ($npcs as $idx => $npc) {
            if (in_array($npc['npc_id'] ?? '', self::getFishDemonIds())) {
                $yaoIndices[] = $idx;
            }
        }

        if (empty($yaoIndices)) {
            return;
        }

        // Session种子管理：每次进入房间刷新（间隔5分钟）
        $seedKey = 'tongtian_yao_seed_' . $charId;
        $timeKey = 'tongtian_yao_time_' . $charId;
        $now = time();
        $lastTime = $_SESSION[$timeKey] ?? 0;

        if (!isset($_SESSION[$seedKey]) || ($now - $lastTime) > self::getYaoRefreshInterval()) {
            $_SESSION[$seedKey] = mt_rand();
            $_SESSION[$timeKey] = $now;
        }

        $seed = $_SESSION[$seedKey];

        require_once __DIR__ . '/../includes/db.php';

        foreach ($yaoIndices as $idx) {
            // 每个NPC用不同的种子偏移
            $npcSeed = $seed + $npcs[$idx]['id'];
            mt_srand($npcSeed);

            // 随机倍率 1~5（对应原版 random(5)+1）
            $mult = mt_rand(1, 5);

            // 随机名称
            $name = self::getYaoNames()[mt_rand(0, count(self::getYaoNames()) - 1)];
            $npcs[$idx]['name'] = $name;

            // 随机属性（倍率缩放）
            $npcs[$idx]['age'] = 10 * $mult;
            $npcs[$idx]['combat_exp'] = 40000 * $mult;
            $npcs[$idx]['max_kee'] = 200 * $mult;
            $npcs[$idx]['kee'] = 200 * $mult;
            $npcs[$idx]['max_gin'] = 200 * $mult;
            $npcs[$idx]['gin'] = 200 * $mult;
            $npcs[$idx]['max_sen'] = 200 * $mult;
            $npcs[$idx]['sen'] = 200 * $mult;
            $npcs[$idx]['force'] = 200 * $mult;
            $npcs[$idx]['max_force'] = 200 * $mult;
            $npcs[$idx]['max_mana'] = 200 * $mult;
            $npcs[$idx]['force_factor'] = 10 * $mult;

            // 随机武器（4选1）
            $weapon = self::getYaoWeapons()[mt_rand(0, count(self::getYaoWeapons()) - 1)];

            // 随机防具（4选1）
            $armor = self::getYaoArmors()[mt_rand(0, count(self::getYaoArmors()) - 1)];

            // 更新数据库（供NPC详情页和战斗系统使用）
            $npcDbId = intval($npcs[$idx]['id']);
            Database::execute(
                "UPDATE npcs SET name = ?, combat_exp = ?, max_kee = ?, kee = ?, 
                 max_gin = ?, gin = ?, max_sen = ?, sen = ?, `force` = ?, max_force = ?, 
                 max_mana = ?, force_factor = ?, age = ?
                 WHERE id = ?",
                [$name, 40000 * $mult, 200 * $mult, 200 * $mult,
                 200 * $mult, 200 * $mult, 200 * $mult, 200 * $mult,
                 200 * $mult, 200 * $mult, 200 * $mult, 10 * $mult, 10 * $mult,
                 $npcDbId]
            );

            // 更新 npc_equipment（先清理旧装备）
            Database::execute("DELETE FROM npc_equipment WHERE npc_id = ?", [$npcDbId]);
            Database::execute(
                "INSERT INTO npc_equipment (npc_id, item_id, equip_slot, worn) VALUES (?, ?, 'armor', 1)",
                [$npcDbId, $armor['item_id']]
            );
            Database::execute(
                "INSERT INTO npc_equipment (npc_id, item_id, equip_slot, worn) VALUES (?, ?, 'weapon', 1)",
                [$npcDbId, $weapon['item_id']]
            );

            // 更新 npc_skills（先清理旧技能，再按武器类型设置）
            Database::execute("DELETE FROM npc_skills WHERE npc_id = ?", [$npcDbId]);
            $baseSkill = 20 * $mult;
            $dodgeSkill = 50 * $mult;

            // 闪避技能（原版 dodge = 50*i）
            Database::execute(
                "INSERT INTO npc_skills (npc_id, skill_name, skill_level) VALUES (?, 'dodge', ?)",
                [$npcDbId, $dodgeSkill]
            );
            // 招架技能（原版 parry = 20*i）
            Database::execute(
                "INSERT INTO npc_skills (npc_id, skill_name, skill_level) VALUES (?, 'parry', ?)",
                [$npcDbId, $baseSkill]
            );
            // 拳脚技能（原版 unarmed = 20*i）
            Database::execute(
                "INSERT INTO npc_skills (npc_id, skill_name, skill_level) VALUES (?, 'unarmed', ?)",
                [$npcDbId, $baseSkill]
            );
            // 武器技能（根据所选武器类型）
            Database::execute(
                "INSERT INTO npc_skills (npc_id, skill_name, skill_level) VALUES (?, ?, ?)",
                [$npcDbId, $weapon['skill'], $baseSkill]
            );

            // 装备信息附加到NPC数组（供房间显示用）
            $npcs[$idx]['equipped_weapon'] = $weapon['name'];
            $npcs[$idx]['equipped_armor'] = $armor['name'];

            log_game('TONGTIAN_YAO', "鱼妖#{$npcDbId} 随机化: {$name} 倍率={$mult} 武器={$weapon['name']} 防具={$armor['name']}");
        }

        // 恢复随机种子
        mt_srand();
    }

    // ==================== 陈家庄居民动态随机生成 ====================

    /**
     * 随机化陈家庄居民和小童NPC
     * 
     * 参考原版LPC people.c / kid.c：
     * - 居民：随机性别 → 从男/女名字池选名，随机属性和技能
     * - 小童：随机性别，随机年龄(3~8)，低属性
     * 
     * 由 Room::getNpcsInRoom() 在加载 minju 房间时调用
     * 
     * @param int $charId 当前角色ID
     * @param array $npcs NPC列表（引用传递，就地修改）
     * @param string $roomId 当前房间ID
     */
    public static function randomizeVillageNpcs(int $charId, array &$npcs, string $roomId): void
    {
        // 仅处理民居房间
        if (!in_array($roomId, self::getMinjuRooms())) {
            return;
        }

        // Session种子管理（与鱼妖共用同一套刷新机制）
        $seedKey = 'tongtian_village_seed_' . $charId;
        $timeKey = 'tongtian_village_time_' . $charId;
        $now = time();
        $lastTime = $_SESSION[$timeKey] ?? 0;

        if (!isset($_SESSION[$seedKey]) || ($now - $lastTime) > self::getYaoRefreshInterval()) {
            $_SESSION[$seedKey] = mt_rand();
            $_SESSION[$timeKey] = $now;
        }

        $seed = $_SESSION[$seedKey];

        require_once __DIR__ . '/../includes/db.php';

        foreach ($npcs as $idx => &$npc) {
            $npcId = $npc['npc_id'] ?? '';

            // 处理居民NPC
            if (strpos($npcId, self::getPeoplePrefix()) === 0) {
                $npcSeed = $seed + $npc['id'];
                mt_srand($npcSeed);

                // 随机性别
                $isMale = mt_rand(0, 1);
                $gender = $isMale ? 'male' : 'female';

                // 从名字池随机选名
                if ($isMale) {
                    $name = self::getChenMaleNames()[mt_rand(0, count(self::getChenMaleNames()) - 1)];
                } else {
                    $name = self::getChenFemaleNames()[mt_rand(0, count(self::getChenFemaleNames()) - 1)];
                }

                // 随机属性（对应原版 people.c）
                $combatExp = 1000 + mt_rand(0, 20000);
                $age = 40 + mt_rand(0, 20);
                $per = 14 + mt_rand(0, 20);
                $unarmedSkill = 10 + mt_rand(0, 90);
                $dodgeSkill = 10 + mt_rand(0, 90);
                $parrySkill = 10 + mt_rand(0, 90);

                $npc['name'] = $name;
                $npc['gender'] = $gender;
                $npc['age'] = $age;
                $npc['per'] = $per;
                $npc['combat_exp'] = $combatExp;
                $npc['force_factor'] = 2;
                $npc['max_gin'] = 200; $npc['gin'] = 200;
                $npc['max_kee'] = 200; $npc['kee'] = 200;
                $npc['max_sen'] = 200; $npc['sen'] = 200;
                $npc['max_force'] = 300; $npc['max_mana'] = 300;

                // 更新数据库
                $npcDbId = intval($npc['id']);
                Database::execute(
                    "UPDATE npcs SET name = ?, gender = ?, age = ?, per = ?, combat_exp = ?,
                     force_factor = 2, max_gin = 200, gin = 200, max_kee = 200, kee = 200,
                     max_sen = 200, sen = 200, max_force = 300, max_mana = 300
                     WHERE id = ?",
                    [$name, $gender, $age, $per, $combatExp, $npcDbId]
                );

                // 更新技能
                Database::execute("DELETE FROM npc_skills WHERE npc_id = ?", [$npcDbId]);
                Database::execute(
                    "INSERT INTO npc_skills (npc_id, skill_name, skill_level) VALUES (?, 'unarmed', ?)",
                    [$npcDbId, $unarmedSkill]
                );
                Database::execute(
                    "INSERT INTO npc_skills (npc_id, skill_name, skill_level) VALUES (?, 'dodge', ?)",
                    [$npcDbId, $dodgeSkill]
                );
                Database::execute(
                    "INSERT INTO npc_skills (npc_id, skill_name, skill_level) VALUES (?, 'parry', ?)",
                    [$npcDbId, $parrySkill]
                );

                // 更新装备（男穿绸袍，女穿布衣）
                $clothId = $isMale ? 'choupao' : 'p_cloth';
                Database::execute("DELETE FROM npc_equipment WHERE npc_id = ?", [$npcDbId]);
                Database::execute(
                    "INSERT INTO npc_equipment (npc_id, item_id, equip_slot, worn) VALUES (?, ?, 'armor', 1)",
                    [$npcDbId, $clothId]
                );

                log_game('VILLAGE_PEOPLE', "居民#{$npcDbId}: {$name} {$gender} age={$age} exp={$combatExp}");
            }

            // 处理小童NPC
            if (strpos($npcId, self::getKidPrefix()) === 0) {
                $npcSeed = $seed + $npc['id'];
                mt_srand($npcSeed);

                $gender = mt_rand(0, 1) ? 'male' : 'female';
                $age = 3 + mt_rand(0, 5);
                $combatExp = mt_rand(0, 1000);
                $per = 14 + mt_rand(0, 20);

                $npc['name'] = '小童';
                $npc['gender'] = $gender;
                $npc['age'] = $age;
                $npc['per'] = $per;
                $npc['combat_exp'] = $combatExp;
                $npc['force_factor'] = 2;
                $npc['max_gin'] = 100; $npc['gin'] = 100;
                $npc['max_kee'] = 100; $npc['kee'] = 100;
                $npc['max_sen'] = 100; $npc['sen'] = 100;

                $npcDbId = intval($npc['id']);
                Database::execute(
                    "UPDATE npcs SET gender = ?, age = ?, per = ?, combat_exp = ?,
                     force_factor = 2, max_gin = 100, gin = 100, max_kee = 100, kee = 100,
                     max_sen = 100, sen = 100
                     WHERE id = ?",
                    [$gender, $age, $per, $combatExp, $npcDbId]
                );

                log_game('VILLAGE_KID', "小童#{$npcDbId}: {$gender} age={$age} exp={$combatExp}");
            }
        }
        unset($npc); // 解除引用

        mt_srand();
    }

    // ==================== 辅助方法 ====================

    /**
     * 检查玩家是否变化为指定名称
     * 参考 ActionRouter::getDisplayName() 的实现模式
     */
    private function isTransformedAs(int $charId, string $targetName): bool
    {
        // 检查数据库中的变化状态
        if (function_exists('get_transform_state_from_db')) {
            $transformData = get_transform_state_from_db($charId);
            if ($transformData && isset($transformData['target_name'])) {
                return $transformData['target_name'] === $targetName;
            }
        }

        // 检查Session中的变化状态
        if (isset($_SESSION['transform_' . $charId])) {
            $transformData = $_SESSION['transform_' . $charId];
            if (isset($transformData['target_name'])) {
                return $transformData['target_name'] === $targetName;
            }
        }

        return false;
    }

    /**
     * 获取角色的显示名称（考虑变化状态）
     */
    private function getDisplayName(array $char, int $charId): string
    {
        if (function_exists('get_transform_state_from_db')) {
            $transformData = get_transform_state_from_db($charId);
            if ($transformData && isset($transformData['target_name'])) {
                return $transformData['target_name'];
            }
        }
        if (isset($_SESSION['transform_' . $charId])) {
            $transformData = $_SESSION['transform_' . $charId];
            if (isset($transformData['target_name'])) {
                return $transformData['target_name'];
            }
        }
        return $char['name'];
    }

    /**
     * 检查角色背包中是否有指定物品
     */
    private function hasItem(int $charId, string $itemId): bool
    {
        $item = Database::queryOne(
            "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = ? AND quantity > 0",
            [$charId, $itemId]
        );
        return !empty($item);
    }

    /**
     * 静态版 hasItem（用于静态方法上下文）
     */
    private static function hasItemStatic(int $charId, string $itemId): bool
    {
        $item = Database::queryOne(
            "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = ? AND quantity > 0",
            [$charId, $itemId]
        );
        return !empty($item);
    }

    /**
     * 设置角色昏迷状态
     * 参考 WaterfallHandler::setUnconscious()
     */
    private function setUnconscious(int $charId): void
    {
        Database::execute(
            'UPDATE characters SET kee = 1 WHERE id = ?',
            [$charId]
        );

        $_SESSION['unconscious_' . $charId] = [
            'timestamp' => time(),
            'duration' => 30,
        ];

        MessageDaemon::queueMessageToSelf(
            $charId,
            HTML_HIRED . '你冻得浑身僵硬，昏迷了过去……需要休息片刻才能恢复。' . HTML_NOR,
            'self_event'
        );
    }

    /**
     * 获取角色临时状态
     */
    private function getTempState(int $charId, string $key): ?string
    {
        $row = Database::queryOne(
            "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?",
            [$charId, $key]
        );
        return $row ? ($row['state_value'] ?? null) : null;
    }

    /**
     * 设置角色临时状态
     */
    private function setTempState(int $charId, string $key, string $value): void
    {
        Database::execute(
            'INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) 
             VALUES (?, ?, ?, NOW(), NOW()) 
             ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = NOW()',
            [$charId, $key, $value]
        );
    }

    /**
     * 删除角色临时状态
     */
    private function deleteTempState(int $charId, string $key): void
    {
        Database::execute(
            "DELETE FROM character_temp_states WHERE char_id = ? AND state_key = ?",
            [$charId, $key]
        );
    }

    /**
     * 静态版 getTempState
     */
    private static function getTempStateStatic(int $charId, string $key): ?string
    {
        $row = Database::queryOne(
            "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?",
            [$charId, $key]
        );
        return $row ? ($row['state_value'] ?? null) : null;
    }

    /**
     * 静态版 setTempState
     */
    private static function setTempStateStatic(int $charId, string $key, string $value): void
    {
        Database::execute(
            'INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) 
             VALUES (?, ?, ?, NOW(), NOW()) 
             ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = NOW()',
            [$charId, $key, $value]
        );
    }

    // ==================== 障碍物状态管理 ====================

    /**
     * 获取障碍物状态
     * @param int $charId 角色ID
     * @param string $obstacle 障碍物标识（如 'tongtian'）
     * @return string|null 当前状态
     */
    public static function getObstacleState(int $charId, string $obstacle): ?string
    {
        $row = Database::queryOne(
            "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?",
            [$charId, 'obstacle/' . $obstacle]
        );
        return $row ? ($row['state_value'] ?? null) : null;
    }

    /**
     * 设置障碍物状态
     * @param int $charId 角色ID
     * @param string $obstacle 障碍物标识
     * @param string $state 状态值
     */
    public static function setObstacleState(int $charId, string $obstacle, string $state): void
    {
        $key = 'obstacle/' . $obstacle;
        Database::execute(
            'INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) 
             VALUES (?, ?, ?, NOW(), NOW()) 
             ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = NOW()',
            [$charId, $key, $state]
        );
    }

    /**
     * 获取所有障碍物定义
     * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
     */
    public static function getObstacleDefinitions(): array
    {
        return [
            'huangfeng'  => '  1. 黄风岭',
            'wuzhuang'   => '  2. 五庄观',
            'baigu'      => '  3. 白骨洞',
            'baoxiang'   => '  4. 宝象国',
            'pingding'   => '  5. 平顶山',
            'wuji'       => '  6. 乌鸡国',
            'huoyun'     => '  7. 火云洞',
            'heishui'    => '  8. 黑水河',
            'chechi'     => '  9. 车迟国',
            'tongtian'   => ' 10. 通天河',
            'jindou'     => ' 11. 金兜山',
            'nuerguo'    => ' 12. 女儿国',
            'dudi'       => ' 13. 毒敌山',
            'firemount'  => ' 14. 火焰山',
            'jilei'      => ' 15. 积雷山',
            'jisaiguo'   => ' 16. 祭赛国',
            'jingjiling' => ' 17. 荆棘岭',
            'xiaoxitian' => ' 18. 小西天',
            'zhuzi'      => ' 19. 朱紫国',
            'pansi'      => ' 20. 盘丝岭',
            'biqiu'      => ' 21. 比丘国',
            'wudidong'   => ' 22. 无底洞',
            'qinfa'      => ' 23. 钦法国',
            'yinwu'      => ' 24. 隐雾山',
            'fengxian'   => ' 25. 凤仙郡',
            'yuhua'      => ' 26. 玉华县',
            'jinping'    => ' 27. 金平府',
            'tianzhu'    => ' 28. 天竺国',
        ];
    }

    /**
     * 获取尊重称呼（简化版）
     */
    private function getRespectTitle(array $char): string
    {
        $daoxing = intval($char['daoxing'] ?? 0);
        if ($daoxing >= 1000000) return '大仙';
        if ($daoxing >= 500000) return '上仙';
        if ($daoxing >= 100000) return '仙长';
        if ($daoxing >= 50000) return '道长';
        if ($daoxing >= 10000) return '壮士';
        return '施主';
    }
}
