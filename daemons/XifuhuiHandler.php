<?php
/**
 * 喜福会喜宴服务处理器
 * 
 * 处理喜福会老板的办喜宴相关逻辑
 * 参考原始LPC代码 boss.c
 */

require_once __DIR__ . '/ActionHandler.php';

class XifuhuiHandler extends ActionHandler {
    
    /**
     * 基础价格（base_price = 50000铜钱 = 5000两金子）
     * 实际价格 = base_price * query_price() / 10
     * query_price() 返回倍数，原始默认为1
     */
    private const BASE_PRICE = 5000; // 金子
    
    /**
     * 配置缓存
     */
    private ?array $configCache = null;
    
    /**
     * 获取配置（优先从 room_actions.config JSON 读取）
     */
    private function getXifuhuiConfig(array $action): array {
        if ($this->configCache === null) {
            $dbConfig = $this->parseConfig($action);
            $this->configCache = [
                'base_price' => $dbConfig['base_price'] ?? self::BASE_PRICE,
            ];
        }
        return $this->configCache;
    }
    
    /**
     * 实现父类抽象方法
     */
    public function execute(int $charId, array $action, array $params = []): array {
        $config = $this->getXifuhuiConfig($action);
        $cmd = $action['action_cmd'] ?? '';
        switch ($cmd) {
            case 'ask_party':
                return $this->handleAskParty($charId, $params['npc_id'] ?? 0);
            case 'ask_money':
                return $this->handleAskMoney($charId, $params['npc_id'] ?? 0);
            case 'start_party':
                return $this->handleStartParty($charId, $params['npc_id'] ?? 0);
            case 'serve':
                return $this->handleServe($charId, $params['npc_id'] ?? 0);
            case 'finish':
                return $this->handleFinish($charId, $params['npc_id'] ?? 0);
            default:
                return ['success' => false, 'message' => '未知的喜宴动作。'];
        }
    }
    
    /**
     * 获取NPC信息
     */
    protected function getNpc(int $npcId): ?array {
        require_once __DIR__ . '/../models/Npc.php';
        return NpcModel::find($npcId);
    }
    
    /**
     * 处理询问办喜宴 (ask_party)
     * 
     * 原始LPC代码逻辑：
     * - 检查是否已经在办喜宴 (host_of_party)
     * - 检查NPC是否准备好办喜宴 (ready_to_party)
     * - 检查是否在喜福会地点
     * - 检查是否已经付过钱 (party_paid / ready_to_pay)
     * - 如果没付钱，提示价格并设置 ready_to_pay
     */
    public function handleAskParty(int $charId, int $npcId): array {
        $char = $this->getCharacter($charId);
        $npc = $this->getNpc($npcId);
            
        if (!$char) {
            error_log("XifuhuiHandler: 角色不存在，charId=$charId");
            return ['success' => false, 'message' => '角色不存在 (ID: ' . $charId . ')'];
        }
            
        if (!$npc) {
            error_log("XifuhuiHandler: NPC 不存在，npcId=$npcId");
            return ['success' => false, 'message' => 'NPC 不存在 (ID: ' . $npcId . ')'];
        }
        
        // 检查是否已经是喜宴的主人
        $isHost = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'host_of_party'",
            [$charId]
        );
        if ($isHost) {
            return ['success' => false, 'message' => '喜福会老板说：你已经在办喜宴了，还办什么？'];
        }
        
        // 检查NPC是否准备好办喜宴
        $readyToParty = Database::queryOne(
            "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'ready_to_party'",
            [$npcId]
        );
        if ($readyToParty && $readyToParty['temp_value'] == '1') {
            return ['success' => false, 'message' => '喜福会老板说：现在正忙着办喜宴，您稍后再来吧。'];
        }
        
        // 检查是否在喜福会地点
        $currentRoom = $char['current_room'] ?? '';
        if ($currentRoom !== 'city/xifuhui') {
            return ['success' => false, 'message' => '喜福会老板说：这儿不是办喜宴的地儿，您请到喜福会来吧。'];
        }
        
        // 检查是否已经付过钱
        $readyToPay = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'ready_to_pay'",
            [$charId]
        );
        if ($readyToPay && $readyToPay['temp_value'] == '1') {
            return ['success' => false, 'message' => '喜福会老板说：您已经付过钱了，这就开始办喜宴吧。'];
        }
        
        // 计算价格并提示玩家
        $price = $this->calculatePrice();
        
        return [
            'success' => true, 
            'message' => "喜福会老板说：办喜宴啊，先请付" . $price . "两金子。\n" .
                         "(点击「给予」支付金子，或再次询问「喜宴」开始办宴)",
            'skip_queue' => true
        ];
    }
    
    /**
     * 处理询问财务 (ask_money)
     * 
     * 原始LPC代码逻辑：
     * - 只有 ID 为 "bula" 的玩家才能查询
     * - 返回喜福会当前的资金
     */
    public function handleAskMoney(int $charId, int $npcId): array {
        $char = $this->getCharacter($charId);
        
        if (!$char) {
            error_log("XifuhuiHandler: 角色不存在，charId=$charId");
            return ['success' => false, 'message' => '角色不存在 (ID: ' . $charId . ')'];
        }
        
        // 检查玩家是否为巫师或管理员
        require_once MODEL_PATH . 'User.php';
        $user = UserModel::find($char['user_id'] ?? 0);
        $allowedRoles = ['wizard', 'admin', 'developer'];
        if (!$user || !in_array($user['role'] ?? '', $allowedRoles)) {
            return ['success' => false, 'message' => '喜福会老板警惕地看着你：这可不是随便能打听的事！'];
        }
        
        // 获取喜福会的资金（存储在npc_temp中）
        $npcMoney = Database::queryOne(
            "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'money'",
            [$npcId]
        );
        
        $money = $npcMoney ? intval($npcMoney['temp_value']) : 0;
        $total = $money + 160; // 原始代码：i = query("money") + 160
        
        return [
            'success' => true,
            'message' => "喜福会老板悄悄告诉你：你这个月总收入差了" . $this->chineseNumber($total) . "两金子了。\n",
            'skip_queue' => true
        ];
    }
    
    /**
     * 处理开始喜宴 (start_party)
     * 
     * 原始LPC代码逻辑 (do_start):
     * - 检查玩家是否是喜宴的主人 (host_of_party)
     * - 检查NPC是否准备好 (ready_to_party)
     * - 检查喜宴是否已经开始 (party_start_already)
     * - 生成食物和歌舞女子NPC
     */
    public function handleStartParty(int $charId, int $npcId): array {
        error_log("[XifuhuiHandler] handleStartParty called: charId=$charId, npcId=$npcId");
        $char = $this->getCharacter($charId);
        $npc = $this->getNpc($npcId);
            
        if (!$char) {
            error_log("XifuhuiHandler: 角色不存在，charId=$charId");
            return ['success' => false, 'message' => '角色不存在 (ID: ' . $charId . ')'];
        }
            
        if (!$npc) {
            error_log("XifuhuiHandler: NPC 不存在，npcId=$npcId");
            return ['success' => false, 'message' => 'NPC 不存在 (ID: ' . $npcId . ')'];
        }
        
        $npcName = $npc['name'];
        $charName = $char['name'];
        $currentRoom = $char['current_room'];
        $currentArea = $char['current_area'] ?? '';
        
        // 检查玩家是否是喜宴的主人
        $isHost = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'host_of_party'",
            [$charId]
        );
        if (!$isHost) {
            $_SESSION['flash_message'] = [
                'content' => HTML_HIRED . "{$npcName}对你说：你又不是新郎官！" . HTML_NOR,
                'timestamp' => time()
            ];
            return ['success' => false, 'message' => "{$npcName}对你说：你又不是新郎官！", 'redirect' => 'room.php?r=' . time()];
        }
        
        // 检查NPC是否准备好办喜宴
        $readyToParty = Database::queryOne(
            "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'ready_to_party'",
            [$npcId]
        );
        if (!$readyToParty || $readyToParty['temp_value'] != '1') {
            $_SESSION['flash_message'] = [
                'content' => HTML_HIRED . "{$npcName}对你说：开始什么呀，现在又没人结婚！" . HTML_NOR,
                'timestamp' => time()
            ];
            return ['success' => false, 'message' => "{$npcName}对你说：开始什么呀，现在又没人结婚！", 'redirect' => 'room.php?r=' . time()];
        }
        
        // 检查喜宴是否已经开始
        $partyStarted = Database::queryOne(
            "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'party_start_already'",
            [$npcId]
        );
        if ($partyStarted && $partyStarted['temp_value'] == '1') {
            $_SESSION['flash_message'] = [
                'content' => HTML_HIRED . "{$npcName}生气的对你说：这不是已经开始了嘛！" . HTML_NOR,
                'timestamp' => time()
            ];
            return ['success' => false, 'message' => "{$npcName}生气的对你说：这不是已经开始了嘛！", 'redirect' => 'room.php?r=' . time()];
        }
        
        // 设置喜宴已开始状态
        Database::execute(
            "INSERT INTO npc_temp (npc_id, temp_key, temp_value) VALUES (?, 'party_start_already', '1') ON DUPLICATE KEY UPDATE temp_value = '1'",
            [$npcId]
        );
        
        // 清除 ready_to_party 状态
        Database::execute(
            "DELETE FROM npc_temp WHERE npc_id = ? AND temp_key = 'ready_to_party'",
            [$npcId]
        );
        
        // 设置房间属性（女儿红）- 需要 room_temp 表
        try {
            Database::execute(
                "INSERT INTO room_temp (room_id, temp_key, temp_value) VALUES (?, 'resource/nuerhong', '1') ON DUPLICATE KEY UPDATE temp_value = '1'",
                [$currentRoom]
            );
        } catch (\Exception $e) {
            // 如果 room_temp 表不存在，忽略错误
            if (strpos($e->getMessage(), 'room_temp') === false) {
                throw $e;
            }
        }
        
        // 生成歌舞女子NPC到房间（girla=55, girlb=56, girlc=57, girld=58）
        $girlNpcIds = [55, 56, 57, 58];
        $locationJson = json_encode(['area' => $currentArea, 'room' => $currentRoom]);
        foreach ($girlNpcIds as $girlId) {
            Database::execute(
                "INSERT INTO npc_temp (npc_id, temp_key, temp_value) VALUES (?, 'current_location', ?) ON DUPLICATE KEY UPDATE temp_value = ?",
                [$girlId, $locationJson, $locationJson]
            );
        }
        
        // 记录喜宴开始时间（用于自动结束，原版500秒）
        Database::execute(
            "INSERT INTO npc_temp (npc_id, temp_key, temp_value) VALUES (?, 'party_start_time', ?) ON DUPLICATE KEY UPDATE temp_value = ?",
            [$npcId, time(), time()]
        );
        
        error_log("[XifuhuiHandler] handleStartParty checks passed, creating items");
        $cupItem = $this->createJiuZhanItem();
        
        error_log("[XifuhuiHandler] jiu_zhan created: " . json_encode($cupItem));
        
        // 将白玉酒盏放到房间地上
        $this->moveItemToRoom($cupItem, $currentRoom);
        
        // 给房间内所有玩家发放白玉酒盏到背包
        $roomCharacters = Database::queryAll(
            "SELECT c.id, c.name FROM characters c WHERE c.current_room = ?",
            [$currentRoom]
        );
        
        foreach ($roomCharacters as $roomChar) {
            $this->giveItemToCharacter($cupItem, $roomChar['id']);
        }
        
        // 广播消息（对齐原版 do_start）
        require_once DAEMON_PATH . 'MessageDaemon.php';
        
        $roomMsg = HTML_HIYEL;
        $roomMsg .= "{$charName}大声对{$npcName}说：老板，开席！\n\n";
        $roomMsg .= "{$npcName}大声喊着：开～～席～～喽～～\n\n";
        $roomMsg .= "旁边四个唢呐手大声的吹起了欢快的唢呐。\n";
        $roomMsg .= "内堂走出几个漂亮的小姑娘。\n";
        $roomMsg .= "每人得到了一个白玉酒盏。" . HTML_NOR;
        
        MessageDaemon::broadcastToRoom($currentRoom, $roomMsg, 0);
        
        // 设置flash message用于客户端提示
        $_SESSION['flash_message'] = [
            'content' => $roomMsg,
            'timestamp' => time()
        ];
        
        error_log("[XifuhuiHandler] handleStartParty returning success with redirect");
        return [
            'success' => true,
            'message' => $roomMsg,
            'redirect' => 'room.php?r=' . time(),
            'skip_queue' => true
        ];
    }
    
    /**
     * 处理上菜 (serve)
     * 
     * 原始LPC代码逻辑 (do_serve):
     * - 检查玩家是否是喜宴的主人
     * - 检查喜宴是否已经开始
     * - 检查是否已经有食物
     * - 生成食物
     */
    public function handleServe(int $charId, int $npcId): array {
        $char = $this->getCharacter($charId);
        $npc = $this->getNpc($npcId);
            
        if (!$char) {
            error_log("XifuhuiHandler: 角色不存在，charId=$charId");
            return ['success' => false, 'message' => '角色不存在 (ID: ' . $charId . ')'];
        }
            
        if (!$npc) {
            error_log("XifuhuiHandler: NPC 不存在，npcId=$npcId");
            return ['success' => false, 'message' => 'NPC 不存在 (ID: ' . $npcId . ')'];
        }
        
        $npcName = $npc['name'];
        
        // 检查玩家是否是喜宴的主人
        $isHost = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'host_of_party'",
            [$charId]
        );
        if (!$isHost) {
            $_SESSION['flash_message'] = [
                'content' => HTML_HIRED . '喜福会老板说：您不是办喜宴的东家，不能上菜。' . HTML_NOR,
                'timestamp' => time()
            ];
            return ['success' => false, 'redirect' => 'room.php'];
        }
        
        // 检查喜宴是否已经开始
        $partyStarted = Database::queryOne(
            "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'party_start_already'",
            [$npcId]
        );
        if (!$partyStarted || $partyStarted['temp_value'] != '1') {
            $_SESSION['flash_message'] = [
                'content' => HTML_HIRED . '喜福会老板说：喜宴还没开始呢，您先开始喜宴吧。' . HTML_NOR,
                'timestamp' => time()
            ];
            return ['success' => false, 'redirect' => 'room.php'];
        }
        
        // 检查是否已经有食物（这里简化为直接上菜）
        // 原始代码：if (present("food 2", environment(me))) return notify_fail
        
        // 广播消息
        require_once DAEMON_PATH . 'MessageDaemon.php';
        
        $currentRoom = $char['current_room'];
        $roomMsg = $npcName . "说：小二们从厨房鱼贯而出，端上了热气腾腾的菜肴。\n";
        
        // 生成食物对象
        $foodItems = [];
        for ($i = 0; $i < 4; $i++) {
            $foodItems[] = $this->createFoodItem();
        }
        
        // 将食物移动到房间
        foreach ($foodItems as $food) {
            $this->moveItemToRoom($food, $currentRoom);
        }
        $roomMsg .= "香气扑鼻，让人食指大动！";
        
        MessageDaemon::broadcastToRoom($currentRoom, HTML_HIYEL . $roomMsg . HTML_NOR, 0);
        
        // 设置flash message
        $_SESSION['flash_message'] = [
            'content' => HTML_HIYEL . $roomMsg . HTML_NOR,
            'timestamp' => time()
        ];
        
        return [
            'success' => true,
            'redirect' => 'room.php?r=' . time(),
            'skip_queue' => true
        ];
    }
    
    /**
     * 处理结束喜宴 (finish)
     * 
     * 原始LPC代码逻辑 (do_finish):
     * - 检查玩家是否是喜宴的主人
     * - 检查喜宴是否已经开始
     * - 清理喜宴相关状态
     */
    public function handleFinish(int $charId, int $npcId): array {
        $char = $this->getCharacter($charId);
        $npc = $this->getNpc($npcId);
            
        if (!$char) {
            error_log("XifuhuiHandler: 角色不存在，charId=$charId");
            return ['success' => false, 'message' => '角色不存在 (ID: ' . $charId . ')'];
        }
            
        if (!$npc) {
            error_log("XifuhuiHandler: NPC 不存在，npcId=$npcId");
            return ['success' => false, 'message' => 'NPC 不存在 (ID: ' . $npcId . ')'];
        }
        
        // 检查玩家是否是喜宴的主人
        $isHost = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'host_of_party'",
            [$charId]
        );
        if (!$isHost) {
            $_SESSION['flash_message'] = [
                'content' => HTML_HIRED . '喜福会老板说：您不是办喜宴的东家，不能结束喜宴。' . HTML_NOR,
                'timestamp' => time()
            ];
            return ['success' => false, 'redirect' => 'room.php'];
        }
        
        // 检查喜宴是否已经开始
        $partyStarted = Database::queryOne(
            "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'party_start_already'",
            [$npcId]
        );
        if (!$partyStarted || $partyStarted['temp_value'] != '1') {
            $_SESSION['flash_message'] = [
                'content' => HTML_HIRED . '喜福会老板说：喜宴还没开始呢，结束什么？' . HTML_NOR,
                'timestamp' => time()
            ];
            return ['success' => false, 'redirect' => 'room.php'];
        }
        
        // 清理状态
        Database::execute(
            "DELETE FROM npc_temp WHERE npc_id = ? AND temp_key = 'party_start_already'",
            [$npcId]
        );
        
        Database::execute(
            "DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'host_of_party'",
            [$charId]
        );
        
        // 清除 ready_to_pay 状态
        Database::execute(
            "DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'ready_to_pay'",
            [$charId]
        );
        
        // 清理歌舞女子NPC
        $girlNpcIds = [55, 56, 57, 58];
        foreach ($girlNpcIds as $girlId) {
            Database::execute("DELETE FROM npc_temp WHERE npc_id = ? AND temp_key = 'current_location'", [$girlId]);
        }
        
        // 清除房间的女儿红属性 - 需要 room_temp 表
        try {
            $currentRoom = $char['current_room'];
            Database::execute(
                "DELETE FROM room_temp WHERE room_id = ? AND temp_key = 'resource/nuerhong'",
                [$currentRoom]
            );
        } catch (\Exception $e) {
            // 如果 room_temp 表不存在，忽略错误
            if (strpos($e->getMessage(), 'room_temp') === false) {
                throw $e;
            }
        }
        
        // 广播消息
        require_once DAEMON_PATH . 'MessageDaemon.php';
        
        $roomMsg = "喜福会老板说：喜宴圆满结束，多谢各位赏光！\n";
        $roomMsg .= "宾客们纷纷散去，歌舞女子也退回了内堂。";
        
        MessageDaemon::broadcastToRoom($currentRoom, HTML_HIYEL . $roomMsg . HTML_NOR, 0);
        
        // 设置flash message
        $_SESSION['flash_message'] = [
            'content' => HTML_HIYEL . $roomMsg . HTML_NOR,
            'timestamp' => time()
        ];
        
        return [
            'success' => true,
            'redirect' => 'room.php?r=' . time(),
            'skip_queue' => true
        ];
    }
    
    /**
     * 创建食物对象（随机从 dish01-dish22 中选择）
     */
    private function createFoodItem(): array {
        // 随机选择 dish01 到 dish22
        $dishNum = str_pad(rand(1, 22), 2, '0', STR_PAD_LEFT);
        $dishId = 'dish' . $dishNum;
        
        $item = Database::queryOne(
            "SELECT id, item_id, name, type, description, unit, weight, value, food_value FROM items WHERE item_id = ? LIMIT 1",
            [$dishId]
        );
        if (!$item) {
            // 如果随机选择的菜肴不存在，回退到 wedding_food
            $item = Database::queryOne(
                "SELECT id, item_id, name, type, description, unit, weight, value, food_value FROM items WHERE item_id = ? LIMIT 1",
                ['wedding_food']
            );
            if (!$item) {
                throw new \Exception('物品模板 wedding_food 不存在，请先在 items 表中添加');
            }
        }
        return $item;
    }

    /**
     * 将物品移动到房间
     */
    private function moveItemToRoom(array $item, string $room): void {
        // room_id 在 rooms 表中存储为完整路径（如 'city/xifuhui'）
        $roomRow = Database::queryOne(
            "SELECT id FROM rooms WHERE room_id = ? LIMIT 1",
            [$room]
        );
        $roomDbId = $roomRow && isset($roomRow['id']) ? $roomRow['id'] : 0;
        if ($roomDbId > 0) {
            Database::execute(
                "INSERT INTO room_items (room_id, item_id, item_name, quantity, dropped_at) VALUES (?, ?, ?, ?, NOW())",
                [$roomDbId, $item['item_id'], $item['name'], 1]
            );
        }
    }



    /**
     * 计算喜宴价格
     * 原始代码：base_price * query_price() / 10
     */
    private function calculatePrice(): int {
        // 优先从配置读取，fallback 到常量
        return $this->configCache['base_price'] ?? self::BASE_PRICE;
    }
    
    /**
     * 数字转中文数字
     */
    private function chineseNumber(int $num): string {
        $units = ['', '十', '百', '千', '万'];
        $digits = ['零', '一', '二', '三', '四', '五', '六', '七', '八', '九'];
        
        if ($num == 0) return '零';
        
        $result = '';
        $numStr = strval($num);
        $len = strlen($numStr);
        
        for ($i = 0; $i < $len; $i++) {
            $digit = intval($numStr[$i]);
            $pos = $len - $i - 1;
            
            if ($digit != 0) {
                $result .= $digits[$digit] . $units[$pos];
            } elseif ($result != '' && substr($result, -1) != '零') {
                $result .= '零';
            }
        }
        
        return $result ?: '零';
    }
    
    /**
     * 创建白玉酒盏物品（从 items 表获取已有模板）
     * 参考原始LPC代码：/d/obj/food/dishes/cup.c
     */
    private function createJiuZhanItem(): array {
        $item = Database::queryOne(
            "SELECT id, item_id, name, type, description, unit, weight, value, food_value FROM items WHERE item_id = ? LIMIT 1",
            ['jiu_zhan']
        );
        if (!$item) {
            throw new \Exception('物品模板 jiu_zhan 不存在，请先在 items 表中添加');
        }
        return $item;
    }
    
    /**
     * 将物品给角色
     * 液体容器每个独立一行，不堆叠
     */
    private function giveItemToCharacter(array $item, int $charId): void {
        // 液体容器总是插入新行，每个容器有独立的液体状态
        Database::execute(
            "INSERT INTO character_inventory (char_id, item_id, quantity, liquid_remaining, liquid_type, liquid_name) VALUES (?, ?, 1, 10, 'alcohol', '女儿红')",
            [$charId, $item['item_id']]
        );
    }
}
