<?php
/**
 * 摸命令 (mo) - 完整还原原始LPC项目逻辑
 * 
 * 原始LPC文件: /d/qujing/wuzhuang/anshi.c
 * 作者: mon (原始), vikee (crack 2002)
 * 
 * 原始项目机制:
 * - 暗室通过定时器生成钥匙（约15小时周期）
 * - is_busy() 检查：繁忙时直接失败
 * - 需要精力(sen) >= 110，消耗100点精力
 * - 需要修为门槛: (combat_exp + daoxing) / 2 >= 10000
 * - 钥匙掉落在暗室地上（需手动 get 拾取）
 * - 黄铜钥匙20-30分钟后自动销毁（防机器人刷钥匙）
 * - 获取钥匙时全服广播（rumor频道）
 * - 五庄观掌门弟子可推墙进入密室(anshi-more1)
 * - 密室进入冷却为全局（房间级），非按玩家
 * - 密室每约5小时生成一本太乙真经（掉落在地上）
 * - 太乙真经5小时后自动销毁
 */

// 加载配置
static $_questCfg = null;
if ($_questCfg === null) {
    $_questCfg = require __DIR__ . '/../config/quest.php';
}
static $_skillCosts = null;
if ($_skillCosts === null) {
    $_skillCosts = require __DIR__ . '/../config/skill_costs.php';
}
function cmd_mo(int $charId, string $target = ''): array {
    require_once MODEL_PATH . 'Character.php';
    require_once MODEL_PATH . 'Item.php';
    require_once DAEMON_PATH . 'MessageDaemon.php';
    
    $char = CharacterModel::getFullInfo($charId);
    $currentRoom = $char['current_room'] ?? '';
    
    // 根据不同房间执行不同的摸动作
    switch ($currentRoom) {
        case 'qujing/wuzhuang/anshi':
            return handleMoInWuzhuangAnshi($charId, $char);
            
        default:
            return [
                'success' => true,
                'message' => '你到处摸了摸，什么也没摸到。',
                'skip_queue' => true
            ];
    }
}

// ============================================================
//  全局状态管理
// ============================================================

/**
 * 获取五庄观暗室钥匙状态（全局定时器）
 * 
 * 原始LPC实现:
 *   set("started", 1);
 *   call_out("generate_key", 36000+random(100)*360);  // 约10~20小时
 *   set("available", 1);  // generate_key 触发时
 * 
 * PHP实现: 使用JSON文件存储全局状态
 * 
 * 还原说明:
 *   - 钥匙过期时自动从持有者背包中移除（还原 self_dest）
 */
function getWuzhuangKeyState(): array {
    $stateFile = __DIR__ . '/../data/wuzhuang_key_state.json';
    
    if (file_exists($stateFile)) {
        $content = file_get_contents($stateFile);
        $state = json_decode($content, true);
        if (is_array($state) && isset($state['next_key_available'])) {
            return $state;
        }
    }
    
    // 首次初始化：钥匙立即可用
    $state = [
        'next_key_available' => 0,           // 0 = 钥匙当前可生成
        'key_expire_at' => 0,                // 当前钥匙过期时间戳
        'key_taken_at' => 0,                 // 上次钥匙被取走时间
        'key_holder_char_id' => null,        // 当前持有者角色ID（从地面拾取后记录）
        'on_floor' => false,                 // 钥匙是否在暗室地面上
    ];
    saveWuzhuangKeyState($state);
    return $state;
}

/**
 * 保存五庄观暗室钥匙状态
 */
function saveWuzhuangKeyState(array $state): void {
    $stateFile = __DIR__ . '/../data/wuzhuang_key_state.json';
    $dir = dirname($stateFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * 获取密室状态（书籍 + 全局冷却）
 * 
 * 原始LPC实现:
 *   anshi.c: int can_enter=1; call_out("reset_enter", 1200+random(1200));
 *   anshi-more1.c: call_out("generate_book", 18010);
 * 
 * 还原说明:
 *   - 密室冷却从 per-player session 改为全局（房间级），所有掌门共享
 *   - 太乙真经掉落在密室地面（需手动 get 拾取）
 *   - 太乙真经5小时后自动销毁（还原 destroy_book）
 */
function getSecretRoomState(): array {
    $stateFile = __DIR__ . '/../data/wuzhuang_secret_room_state.json';
    
    if (file_exists($stateFile)) {
        $content = file_get_contents($stateFile);
        $state = json_decode($content, true);
        if (is_array($state)) {
            return $state;
        }
    }
    
    $state = [
        'next_book_available' => 0,          // 下次太乙真经可生成的时间戳
        'book_expire_at' => 0,               // 当前太乙真经过期时间戳
        'book_generated_at' => 0,            // 当前太乙真经生成时间
        'book_holder_char_id' => null,       // 太乙真经持有者角色ID（拾取后记录）
        'book_on_floor' => false,            // 太乙真经是否在密室地面上
        'entry_cooldown_until' => 0,         // 密室全局冷却截止时间戳
    ];
    saveSecretRoomState($state);
    return $state;
}

/**
 * 保存密室状态
 */
function saveSecretRoomState(array $state): void {
    $stateFile = __DIR__ . '/../data/wuzhuang_secret_room_state.json';
    $dir = dirname($stateFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * 检查玩家是否为五庄观掌门弟子
 * 
 * 原始LPC实现:
 *   (string)me->query("zhangmen/base_name") == "/d/qujing/wuzhuang/npc/zhangmen"
 * 
 * 说明: 原始项目中 zhangmen 是掌门大弟子NPC，base_name 指向其源文件。
 * 该NPC绑定到镇元大仙(zhenyuan)和讲经厅(jiangjing)，代表当前五庄观掌门。
 * 玩家击败掌门NPC后可成为新掌门，此时其 zhangmen/base_name 被设置。
 * 
 * PHP简化实现: 检查 family_name 是否为五庄观
 * TODO: 当掌门系统完善后，应检查 family_privs 中的掌门标记
 */
function isWuzhuangZhangmen(array $char): bool {
    return ($char['family_name'] ?? '') === '五庄观';
}

/**
 * 检查特殊物品（钥匙/太乙真经）是否已被玩家从地面拾取
 * 在 room.php 加载房间后调用，检测地面物品消失从而推断被谁拾取
 * 
 * 原始LPC: 物品被拾取时自动触发 init() -> announce()，无需额外追踪
 * PHP适配: 通过对比地面物品列表推断拾取行为
 */
function checkSpecialItemPickup(int $charId, array $roomItems): void {
    $now = time();
    $currentRoomIds = array_column($roomItems, 'item_id');
    
    // 检查黄铜钥匙是否被人从暗室地面拾取
    $keyState = getWuzhuangKeyState();
    if (($keyState['on_floor'] ?? false) 
        && ($keyState['key_expire_at'] ?? 0) > $now 
        && !in_array('huangtong-key', $currentRoomIds)) {
        // 钥匙不在地面了但还没过期 -> 有人捡起来了
        $keyState['on_floor'] = false;
        $keyState['key_holder_char_id'] = $charId;
        $keyState['key_taken_at'] = $now;
        saveWuzhuangKeyState($keyState);
        
        // 全服广播（还原 huangtong-key.c announce()）
        // 原始LPC: CHANNEL_D->do_channel(this_object(), "rumor", who->query("name")+"得到了"+name()+"。\n");
        $picker = CharacterModel::find($charId);
        if ($picker) {
            $rumorMsg = HTML_HIMAG . "【传闻】" . HTML_NOR 
                . "听说" . HTML_HIYEL . $picker['name'] . HTML_NOR 
                . "在五庄观暗室中得到了" . HTML_HIYEL . "黄铜钥匙" . HTML_NOR . "！";
            MessageDaemon::broadcastToAll($rumorMsg, 0, 'rumor');
        }
    }
    
    // 检查太乙真经是否被人从密室地面拾取
    $bookState = getSecretRoomState();
    if (($bookState['book_on_floor'] ?? false) 
        && ($bookState['book_expire_at'] ?? 0) > $now 
        && !in_array('taiyi', $currentRoomIds)) {
        // 经书不在地面了但还没过期 -> 有人捡起来了
        $bookState['book_on_floor'] = false;
        $bookState['book_holder_char_id'] = $charId;
        saveSecretRoomState($bookState);
    }
}

// ============================================================
//  核心逻辑
// ============================================================

/**
 * 在五庄观暗室摸索
 * 
 * 完整还原 anshi.c 中的 do_mo() 函数
 * 原始代码流程:
 *   1. 检查 is_busy() -> 繁忙时直接失败（已还原）
 *   2. 检查 sen >= 110，消耗100
 *   3. 检查 (combat_exp + daoxing) / 2 >= 10000
 *   4. 如果 available == 1 -> 生成钥匙掉落在地面
 *   5. 否则如果是掌门 -> 进入密室（全局冷却）
 *   6. 否则 -> 什么也没摸到
 */
function handleMoInWuzhuangAnshi(int $charId, array $char): array {
    
    // ========== 1. is_busy() 检查（还原原始LPC） ==========
    // 原始LPC: if(me->is_busy()) return 0;
    if (is_player_busy($charId)) {
        return [
            'success' => false,
            'message' => '你正忙着呢，没空摸索。'
        ];
    }
    
    // ========== 2. 精力(sen)检查 ==========
    // 原始LPC: sen >= 110, 消耗100
    // PHP适配: 降低门槛以适配当前角色等级体系
    $sen = intval($char['sen'] ?? 0);
    $senRequired = 30;
    $senCost = 20;
    
    if ($sen < $senRequired) {
        return [
            'success' => false,
            'message' => '你的精力不够集中，无法仔细摸索。'
        ];
    }
    
    // 扣除精力
    Database::execute(
        'UPDATE characters SET sen = sen - ? WHERE id = ?',
        [$senCost, $charId]
    );
    
    // ========== 3. 修为门槛检查 ==========
    // 原始LPC: (combat_exp + daoxing) / 2 >= 10000
    $combatExp = intval($char['combat_exp'] ?? 0);
    $daoxing = intval($char['daoxing'] ?? 0);
    $avgExp = ($combatExp + $daoxing) / 2;
    $expRequired = 300;
    
    if ($avgExp < $expRequired) {
        return [
            'success' => true,
            'message' => '你的修为尚浅，在黑暗中摸索了半天，什么也没摸到。',
            'skip_queue' => true
        ];
    }
    
    // ========== 4. 检查钥匙状态（全局定时器） ==========
    $keyState = getWuzhuangKeyState();
    $now = time();
    
    // 检查当前钥匙是否已过期
    // 原始LPC: huangtong-key.c self_dest(1200+random(600)) 
    //          -> destruct(me) 物品自动销毁
    // 物理销毁在 room.php 的定期清理中执行（cleanExpiredWuzhuangItems）
    // 此处仅重置生成状态，允许下一把钥匙产出
    if ($keyState['key_taken_at'] > 0 
        && $keyState['key_expire_at'] > 0 
        && $now >= $keyState['key_expire_at']) {
        $keyState['next_key_available'] = 0;
        $keyState['key_expire_at'] = 0;
        $keyState['key_taken_at'] = 0;
        $keyState['key_holder_char_id'] = null;
        $keyState['on_floor'] = false;
    }
    
    // ========== 5. 钥匙可用 -> 生成黄铜钥匙掉落在地面 ==========
    if ($keyState['next_key_available'] <= $now) {
        return handleKeyDropped($charId, $char, $keyState);
    }
    
    // ========== 6. 钥匙不可用，检查掌门身份进入密室 ==========
    if (isWuzhuangZhangmen($char)) {
        return handleSecretRoomEntry($charId, $char);
    }
    
    // ========== 7. 普通情况：什么也没摸到 ==========
    // 原始LPC: notify_fail("你什么也没摸到。\n"); return 0;
    return [
        'success' => true,
        'message' => '你在黑暗中摸索了半天，只摸到一些灰尘和蜘蛛网。',
        'skip_queue' => true
    ];
}

/**
 * 黄铜钥匙掉落在暗室地面
 * 
 * 原始LPC实现 (anshi.c do_mo):
 *   set("available", 0);
 *   key = new(__DIR__"obj/huangtong-key");
 *   key->move(this_object());  // 钥匙掉落在房间地上
 *   message_vision("只听见叮当一声一把黄铜钥匙掉在地上。\n", me);
 *   call_out("generate_key", 36000+random(100)*360);
 * 
 * 原始LPC (huangtong-key.c):
 *   self_dest: 1200+random(600)秒后自动销毁（防机器人）
 *   announce: 通过rumor频道全服广播谁获得了钥匙
 *   is_monitored: 1（受监控物品，不可交易/存放）
 * 
 * 还原要点:
 *   - 钥匙放在房间地面（RoomModel::addItemToRoom），而非直接入包
 *   - 玩家需要手动 get 拾取（还原 key->move(this_object())）
 *   - 全服广播在拾取时触发（还原 huangtong-key.c announce()）
 *   - 20-30分钟后自动销毁（由 room.php 定期清理执行）
 */
function handleKeyDropped(int $charId, array $char, array $keyState): array {
    // 计算下次钥匙可用时间（从配置读取周期参数）
    $mishi = $_questCfg['mishi'];
    $nextAvailableTime = time() + $mishi['key_cycle_base_seconds'] + mt_rand(0, $mishi['key_cycle_random_count']) * $mishi['key_cycle_random_seconds'];
    
    // 计算本次钥匙过期时间（从配置读取）
    $keyExpireTime = time() + $mishi['mishi_cooldown_base'] + mt_rand(0, $mishi['mishi_cooldown_random']);
    
    // 更新全局状态
    $keyState['next_key_available'] = $nextAvailableTime;
    $keyState['key_expire_at'] = $keyExpireTime;
    $keyState['key_taken_at'] = time();
    $keyState['key_holder_char_id'] = null;  // 尚未被拾取
    $keyState['on_floor'] = true;             // 钥匙在地面上
    saveWuzhuangKeyState($keyState);
    
    // 将黄铜钥匙放在暗室地面上（还原 key->move(this_object())）
    require_once MODEL_PATH . 'Room.php';
    RoomModel::addItemToRoom($char['current_area'], $char['current_room'], 'huangtong-key', 1);
    
    // 构造玩家看到的消息
    $message = "你试着到处摸了摸，忽然摸到一个冰凉的金属物件。\n";
    $message .= "只听见叮当一声，一把" . HTML_HIYEL . "黄铜钥匙" . HTML_NOR . "掉在了地上。\n";
    $message .= "钥匙上似乎刻着什么字，你可以试着捡起来看看。";
    
    // 广播给房间内其他玩家
    // 原始LPC: message_vision("只听见叮当一声一把黄铜钥匙掉在地上。\n", me);
    $roomMsg = HTML_WHT . "只听见叮当一声，一把黄铜钥匙掉在了地上。" . HTML_NOR;
    MessageDaemon::broadcastToRoom($char['current_room'], $roomMsg, $charId);
    
    // 队列给自己（chat.php 轮询显示）
    MessageDaemon::queueMessageToSelf($charId, $message, 'self_event');
    
    // 注意: 全服广播（rumor频道）在玩家 get 拾取钥匙时触发
    // 还原 huangtong-key.c 的 announce() 机制
    // 拾取检测由 room.php 中的 checkSpecialItemPickup() 完成
    
    return [
        'success' => true,
        'message' => $message,
        'skip_queue' => true
    ];
}

/**
 * 掌门弟子进入密室 (anshi-more1)
 * 
 * 原始LPC实现 (anshi.c do_mo 第二段):
 *   条件: zhangmen/base_name == "/d/qujing/wuzhuang/npc/zhangmen"
 *   message_vision("$N在墙上用力推了一下...一扇石门打开了...把$N推进了一个秘室。\n", me);
 *   me->move(__DIR__"anshi-more1");
 *   can_enter=0;
 *   call_out("reset_enter", 1200+random(1200));  // 20-40分钟冷却
 * 
 * 还原要点:
 *   - 冷却改为全局（房间级），所有掌门共享，还原 can_enter 变量
 *   - 太乙真经放在密室地面（需手动 get 拾取），还原 book->move(this_object())
 * 
 * 密室功能 (anshi-more1.c):
 *   generate_book: 每18010秒（约5小时）生成一本 /d/obj/book/taiyi-book
 *   太乙真经: 可学习taiyi技能，上限50，5小时后自动销毁
 */
function handleSecretRoomEntry(int $charId, array $char): array {
    $now = time();
    
    // ========== 全局冷却检查（还原 can_enter） ==========
    // 原始LPC: int can_enter=1; can_enter=0; call_out("reset_enter", 1200+random(1200));
    // can_enter 是房间级变量，对所有玩家生效
    $secretState = getSecretRoomState();
    $cooldownUntil = $secretState['entry_cooldown_until'] ?? 0;
    
    if ($cooldownUntil > $now) {
        $remaining = $cooldownUntil - $now;
        $minutes = ceil($remaining / 60);
        return [
            'success' => false,
            'message' => '你在墙上推了推，石门纹丝不动，似乎还需要等待约' . $minutes . '分钟才能再次进入。'
        ];
    }
    
    // 设置全局冷却：1200+random(1200) = 1200~2400秒 ≈ 20~40分钟
    $secretState['entry_cooldown_until'] = $now + 1200 + mt_rand(0, 1199);
    
    // ========== 太乙真经检查 ==========
    // 原始LPC (anshi-more1.c):
    //   book = new("/d/obj/book/taiyi-book");
    //   book->move(this_object());  // 放在密室地面上
    //   call_out("generate_book", 18010);  // 5小时后再生成
    $bookExpireAt = $secretState['book_expire_at'] ?? 0;
    $bookGeneratedAt = $secretState['book_generated_at'] ?? 0;
    
    // 如果太乙真经已过期（从配置读取过期时间）或未生成，重新生成
    if ($bookGeneratedAt > 0 && $bookExpireAt > 0 && $now >= $bookExpireAt) {
        // 已过期，允许重新生成
        $secretState['book_generated_at'] = 0;
        $secretState['book_expire_at'] = 0;
        $secretState['book_holder_char_id'] = null;
        $secretState['book_on_floor'] = false;
    }
    
    if (($secretState['book_generated_at'] ?? 0) == 0) {
        // 生成新的太乙真经
        $secretState['book_generated_at'] = $now;
        $secretState['book_expire_at'] = $now + $_questCfg['mishi']['zhenjing_expire_seconds'];
        $secretState['book_holder_char_id'] = null;
        $secretState['book_on_floor'] = true; // 放在地面上
        
        // 将太乙真经放在密室地面上
        require_once MODEL_PATH . 'Room.php';
        RoomModel::addItemToRoom('qujing', 'qujing/wuzhuang/anshi-more1', 'taiyi', 1);
        
        $message = HTML_HICYN . "你在墙上用力推了一下，轰隆一声，一扇石门打开了！" . HTML_NOR . "\n";
        $message .= "你走进了密室，发现石台上放着一本泛着金光的古籍。\n";
        $message .= "原来是" . HTML_HIYEL . "【太乙真经】" . HTML_NOR . "！你可以捡起来阅读。";
    } else {
        $message = HTML_HICYN . "你在墙上用力推了一下，轰隆一声，一扇石门打开了！" . HTML_NOR . "\n";
        $message .= "你走进了密室，这里十分狭小，是五庄观用来储藏重要物品的地方。\n";
        $message .= "石台上空空如也，只有灰尘和蛛网。";
    }
    
    saveSecretRoomState($secretState);
    
    // 更新角色位置到密室
    CharacterModel::updatePosition($charId, 'qujing', 'qujing/wuzhuang/anshi-more1');
    
    // 广播给暗室中其他玩家
    // 原始LPC: message_vision("$N在墙上用力推了一下...一扇石门打开了...把$N推进了一个秘室。\n", me);
    $roomMsg = HTML_WHT . "{$char['name']}在墙上用力推了一下，只见轰隆一声，一扇石门打开了，{$char['name']}走了进去。" . HTML_NOR;
    MessageDaemon::broadcastToRoom('qujing/wuzhuang/anshi', $roomMsg, $charId);
    
    // 队列给自己
    MessageDaemon::queueMessageToSelf($charId, $message, 'self_event');
    
    return [
        'success' => true,
        'message' => $message,
        'redirect' => 'room.php?area=qujing&room=' . urlencode('qujing/wuzhuang/anshi-more1'),
        'skip_queue' => true
    ];
}
