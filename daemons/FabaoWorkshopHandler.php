<?php
/**
 * FabaoWorkshop Handler
 *
 * 法宝工坊处理器
 * 处理法宝制作、列表、修炼、充能、费用查看、销毁等操作
 */

require_once __DIR__ . '/ActionHandler.php';

class FabaoWorkshopHandler extends ActionHandler {

    /** @var array|null 配置缓存 */
    private static ?array $configCache = null;

    public function getDefaultConfig(): array {
        return [
            'daoxing_min'         => 20000,   // 制作法宝最低道行
            'max_mana_min'        => 360,     // 制作法宝最低最大法力
            'max_force_min'       => 300,     // 制作法宝最低最大内力
            'force_mana_deficit'  => 100,     // 内力/法力差额上限
            'fabao_max_count'     => 3,       // 法宝最大数量
            'make_force_cost'     => 200,     // 制作消耗内力
            'make_mana_cost'      => 200,     // 制作消耗法力
            'upgrade_max_level'   => 25,      // 修炼满级
            'upgrade_daoxing_mult' => 100000, // 修炼道行需求乘数（等级×此值）
            'upgrade_daoxing_cost_mult' => 1000, // 修炼道行消耗乘数
            'upgrade_res_req_mult' => 200,    // 修炼资源需求乘数
            'upgrade_res_cost_mult' => 3,     // 修炼资源消耗乘数
            // LPC 充能：每次消耗 100 内力 + 100 法力充 1 格
            'charge_force_per'    => 100,     // 充能每格消耗内力
            'charge_mana_per'     => 100,     // 充能每格消耗法力
            'charge_busy_seconds' => 5,       // 充能冷却秒数 (LPC: cost/delay_factor = 100/20 = 5)
        ];
    }

    private function getConfig(array $action): array {
        if (self::$configCache !== null) {
            return self::$configCache;
        }
        $dbCfg = $this->parseConfig($action);
        self::$configCache = array_merge($this->getDefaultConfig(), $dbCfg);
        return self::$configCache;
    }

    /** 武器子类型 */
    private static array $weaponSubtypes = [
        1 => '斧', 2 => '刀', 3 => '叉', 4 => '锤', 5 => '锏',
        6 => '枪', 7 => '杖', 8 => '棒', 9 => '剑', 10 => '鞭',
    ];

    /** 防具子类型 */
    private static array $armorSubtypes = [
        1 => '甲', 2 => '鞋', 3 => '衣服', 4 => '指套', 5 => '护掌',
        6 => '头罩', 7 => '脖套', 8 => '盾', 9 => '披风', 10 => '护腕', 11 => '腰带',
    ];

    /**
     * 执行法宝工坊动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在', 'data' => null];
            }

            $cmd = $action['action_cmd'] ?? '';

            switch ($cmd) {
                case 'make_fabao':
                    return $this->handleMakeFabao($charId, $character, $action, $params);
                case 'list_fabao':
                    return $this->handleListFabao($charId, $character);
                case 'upgrade':
                    return $this->handleUpgrade($charId, $character, $action, $params);
                case 'charge':
                    return $this->handleCharge($charId, $character, $action, $params);
                case 'cost':
                    return $this->handleCost($charId, $character, $action);
                case 'dispose':
                    return $this->handleDispose($charId, $character, $action, $params);
                default:
                    return ['success' => false, 'message' => '未知的法宝操作: ' . $cmd, 'data' => null];
            }
        } catch (\Exception $e) {
            error_log("FabaoWorkshopHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '法宝操作执行失败', 'data' => null];
        }
    }

    // ============================================================
    // 1. 制作法宝 (make_fabao) - 多步交互
    // ============================================================

    private function handleMakeFabao(int $charId, array $character, array $action, array $params): array {
        $step = $_POST['step'] ?? $_GET['step'] ?? '';

        switch ($step) {
            case 'select_subtype':
                return $this->makeFabaoSelectSubtype($charId, $character, $action);
            case 'input_details':
                return $this->makeFabaoInputDetails($charId, $character, $action);
            case 'confirm':
                return $this->makeFabaoConfirm($charId, $character, $action);
            default:
                return $this->makeFabaoCheckPrerequisites($charId, $character, $action);
        }
    }

    /** 检查前置条件，显示类型选择 */
    private function makeFabaoCheckPrerequisites(int $charId, array $character, array $action): array {
        $cfg = $this->getConfig($action);
        $daoxing = intval($character['daoxing'] ?? 0);
        $maxMana = intval($character['max_mana'] ?? 0);
        $maxForce = intval($character['max_force'] ?? 0);
        $force = intval($character['force'] ?? 0);
        $mana = intval($character['mana'] ?? 0);

        // 查询已有法宝数
        require_once __DIR__ . '/../includes/db.php';
        $countResult = Database::queryOne(
            'SELECT COUNT(*) as cnt FROM character_fabao WHERE owner_id = ?',
            [$charId]
        );
        $fabaoCount = intval($countResult['cnt'] ?? 0);

        $deficit = $cfg['force_mana_deficit'];
        $errors = [];
        if ($daoxing < $cfg['daoxing_min']) {
            $errors[] = "道行不足{$cfg['daoxing_min']}（当前：{$daoxing}）";
        }
        if ($maxMana < $cfg['max_mana_min']) {
            $errors[] = "最大法力不足{$cfg['max_mana_min']}（当前：{$maxMana}）";
        }
        if ($maxForce < $cfg['max_force_min']) {
            $errors[] = "最大内力不足{$cfg['max_force_min']}（当前：{$maxForce}）";
        }
        if ($force < $maxForce - $deficit) {
            $errors[] = "内力未接近满值（当前：{$force}/{$maxForce}，需达到" . ($maxForce - $deficit) . "以上）";
        }
        if ($mana < $maxMana - $deficit) {
            $errors[] = "法力未接近满值（当前：{$mana}/{$maxMana}，需达到" . ($maxMana - $deficit) . "以上）";
        }
        if ($fabaoCount >= $cfg['fabao_max_count']) {
            $errors[] = "已有法宝数量已达上限（{$cfg['fabao_max_count']}件）";
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'message' => '制作法宝条件不满足：' . implode('；', $errors),
                'data' => null,
            ];
        }

        // 显示类型选择表单
        $area = $_GET['area'] ?? $_POST['area'] ?? $character['current_area'] ?? '';
        $room = $_GET['room'] ?? $_POST['room'] ?? $character['current_room'] ?? '';

        $html = '<h3>制作法宝</h3>';
        $html .= '<p>请选择法宝类型：</p>';
        $html .= '<form method="post" action="/functions/action.php">';
        $html .= '<input type="hidden" name="action" value="make_fabao">';
        $html .= '<input type="hidden" name="step" value="select_subtype">';
        $html .= '<input type="hidden" name="area" value="' . h($area) . '">';
        $html .= '<input type="hidden" name="room" value="' . h($room) . '">';
        $html .= '<p><button type="submit" name="fabao_type" value="weapon">武器类法宝</button> ';
        $html .= '<button type="submit" name="fabao_type" value="armor">防具类法宝</button></p>';
        $html .= '</form>';
        $html .= '<br><a href="room.php?area=' . urlencode($area) . '&room=' . urlencode($room) . '">返回</a>';

        return ['success' => true, 'html' => $html];
    }

    /** 选择子类型 */
    private function makeFabaoSelectSubtype(int $charId, array $character, array $action): array {
        $fabaoType = $_POST['fabao_type'] ?? $_GET['fabao_type'] ?? '';
        if (!in_array($fabaoType, ['weapon', 'armor'])) {
            return ['success' => false, 'message' => '请选择有效的法宝类型', 'data' => null];
        }

        $area = $_POST['area'] ?? $_GET['area'] ?? $character['current_area'] ?? '';
        $room = $_POST['room'] ?? $_GET['room'] ?? $character['current_room'] ?? '';

        $subtypes = ($fabaoType === 'weapon') ? self::$weaponSubtypes : self::$armorSubtypes;
        $typeName = ($fabaoType === 'weapon') ? '武器' : '防具';

        $html = '<h3>制作法宝 - 选择' . $typeName . '子类型</h3>';
        $html .= '<form method="post" action="/functions/action.php">';
        $html .= '<input type="hidden" name="action" value="make_fabao">';
        $html .= '<input type="hidden" name="step" value="input_details">';
        $html .= '<input type="hidden" name="fabao_type" value="' . h($fabaoType) . '">';
        $html .= '<input type="hidden" name="area" value="' . h($area) . '">';
        $html .= '<input type="hidden" name="room" value="' . h($room) . '">';
        $html .= '<p>请选择' . $typeName . '种类：</p>';
        $html .= '<p><select name="subtype">';
        foreach ($subtypes as $num => $name) {
            $html .= '<option value="' . $num . '">' . h($name) . '</option>';
        }
        $html .= '</select></p>';
        $html .= '<p><button type="submit">下一步</button></p>';
        $html .= '</form>';
        $html .= '<br><a href="room.php?area=' . urlencode($area) . '&room=' . urlencode($room) . '">返回</a>';

        return ['success' => true, 'html' => $html];
    }

    /** 输入详细信息 */
    private function makeFabaoInputDetails(int $charId, array $character, array $action): array {
        $fabaoType = $_POST['fabao_type'] ?? $_GET['fabao_type'] ?? '';
        $subtype = $_POST['subtype'] ?? $_GET['subtype'] ?? '';

        if (!in_array($fabaoType, ['weapon', 'armor']) || empty($subtype)) {
            return ['success' => false, 'message' => '参数不完整', 'data' => null];
        }

        $area = $_POST['area'] ?? $_GET['area'] ?? $character['current_area'] ?? '';
        $room = $_POST['room'] ?? $_GET['room'] ?? $character['current_room'] ?? '';

        $subtypes = ($fabaoType === 'weapon') ? self::$weaponSubtypes : self::$armorSubtypes;
        $subtypeName = $subtypes[intval($subtype)] ?? '未知';

        $html = '<h3>制作法宝 - 输入信息</h3>';
        $html .= '<p>类型：' . ($fabaoType === 'weapon' ? '武器' : '防具') . ' - ' . h($subtypeName) . '</p>';
        $html .= '<form method="post" action="/functions/action.php">';
        $html .= '<input type="hidden" name="action" value="make_fabao">';
        $html .= '<input type="hidden" name="step" value="confirm">';
        $html .= '<input type="hidden" name="fabao_type" value="' . h($fabaoType) . '">';
        $html .= '<input type="hidden" name="subtype" value="' . h($subtype) . '">';
        $html .= '<input type="hidden" name="area" value="' . h($area) . '">';
        $html .= '<input type="hidden" name="room" value="' . h($room) . '">';
        $html .= '<p>英文ID：<input type="text" name="item_id" required placeholder="如：qingtong_jian"></p>';
        $html .= '<p>中文名：<input type="text" name="fabao_name" required placeholder="如：青铜剑"></p>';
        $html .= '<p>描述：<input type="text" name="fabao_desc" placeholder="法宝描述"></p>';
        $html .= '<p>量词：<input type="text" name="fabao_unit" value="把" size="5"></p>';
        $html .= '<p><button type="submit">确认制作</button></p>';
        $html .= '</form>';
        $html .= '<br><a href="room.php?area=' . urlencode($area) . '&room=' . urlencode($room) . '">返回</a>';

        return ['success' => true, 'html' => $html];
    }

    /** 确认并创建法宝 */
    private function makeFabaoConfirm(int $charId, array $character, array $action): array {
        $fabaoType = $_POST['fabao_type'] ?? '';
        $subtype = $_POST['subtype'] ?? '';
        $itemId = trim($_POST['item_id'] ?? '');
        $fabaoName = trim($_POST['fabao_name'] ?? '');
        $fabaoDesc = trim($_POST['fabao_desc'] ?? '');
        $fabaoUnit = trim($_POST['fabao_unit'] ?? '把');

        if (!in_array($fabaoType, ['weapon', 'armor']) || empty($subtype)) {
            return ['success' => false, 'message' => '法宝类型参数无效', 'data' => null];
        }
        if (empty($itemId)) {
            return ['success' => false, 'message' => '请输入英文ID', 'data' => null];
        }
        if (empty($fabaoName)) {
            return ['success' => false, 'message' => '请输入中文名称', 'data' => null];
        }

        // 再次验证前置条件
        $cfg = $this->getConfig($action);
        $daoxing = intval($character['daoxing'] ?? 0);
        $maxMana = intval($character['max_mana'] ?? 0);
        $maxForce = intval($character['max_force'] ?? 0);
        $force = intval($character['force'] ?? 0);
        $mana = intval($character['mana'] ?? 0);
        $deficit = $cfg['force_mana_deficit'];

        if ($daoxing < $cfg['daoxing_min'] || $maxMana < $cfg['max_mana_min'] || $maxForce < $cfg['max_force_min'] ||
            $force < $maxForce - $deficit || $mana < $maxMana - $deficit) {
            return ['success' => false, 'message' => '制作条件不再满足', 'data' => null];
        }

        require_once __DIR__ . '/../includes/db.php';

        $countResult = Database::queryOne(
            'SELECT COUNT(*) as cnt FROM character_fabao WHERE owner_id = ?',
            [$charId]
        );
        if (intval($countResult['cnt'] ?? 0) >= $cfg['fabao_max_count']) {
            return ['success' => false, 'message' => '法宝数量已达上限', 'data' => null];
        }

        // 分配 series_no
        if ($fabaoType === 'weapon') {
            $seriesNo = '1';
        } else {
            // 防具：优先2，然后3
            $existing = Database::queryAll(
                'SELECT series_no FROM character_fabao WHERE owner_id = ? AND fabao_type = ?',
                [$charId, 'armor']
            );
            $usedSeries = array_column($existing, 'series_no');
            if (!in_array('2', $usedSeries)) {
                $seriesNo = '2';
            } elseif (!in_array('3', $usedSeries)) {
                $seriesNo = '3';
            } else {
                return ['success' => false, 'message' => '防具法宝位已满', 'data' => null];
            }
        }

        // 检查 series_no 是否已被占用
        $existCheck = Database::queryOne(
            'SELECT id FROM character_fabao WHERE owner_id = ? AND series_no = ?',
            [$charId, $seriesNo]
        );
        if ($existCheck) {
            return ['success' => false, 'message' => '法宝位' . $seriesNo . '已被占用', 'data' => null];
        }

        // 扣除内力和法力（从配置读取）
        $forceCost = $cfg['make_force_cost'];
        $manaCost = $cfg['make_mana_cost'];
        Database::execute(
            'UPDATE characters SET `force` = `force` - ?, mana = mana - ? WHERE id = ? AND `force` >= ? AND mana >= ?',
            [$forceCost, $manaCost, $charId, $forceCost, $manaCost]
        );

        // 验证扣除是否成功
        $updated = Database::queryOne('SELECT `force`, mana FROM characters WHERE id = ?', [$charId]);
        if (!$updated || intval($updated['force']) < 0 || intval($updated['mana']) < 0) {
            // 回滚
            Database::execute(
                'UPDATE characters SET `force` = `force` + ?, mana = mana + ? WHERE id = ?',
                [$forceCost, $manaCost, $charId]
            );
            return ['success' => false, 'message' => '内力或法力不足', 'data' => null];
        }

        // 构建法宝 item_id
        $subtypes = ($fabaoType === 'weapon') ? self::$weaponSubtypes : self::$armorSubtypes;
        $subtypeName = $subtypes[intval($subtype)] ?? '';
        $fullItemId = $itemId;

        // 创建法宝
        if ($fabaoType === 'weapon') {
            Database::execute(
                'INSERT INTO character_fabao (owner_id, series_no, fabao_type, sub_type, item_id, name, description, unit, attack_qi, max_attack_qi, attack_shen, max_attack_shen) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 1, 1)',
                [$charId, $seriesNo, $fabaoType, $subtype, $fullItemId, $fabaoName, $fabaoDesc, $fabaoUnit]
            );
        } else {
            Database::execute(
                'INSERT INTO character_fabao (owner_id, series_no, fabao_type, sub_type, item_id, name, description, unit, defense_qi, max_defense_qi, defense_shen, max_defense_shen, defense_shou, max_defense_shou) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 1, 1, 1, 1)',
                [$charId, $seriesNo, $fabaoType, $subtype, $fullItemId, $fabaoName, $fabaoDesc, $fabaoUnit]
            );
        }

        // 同步到背包：向 items 表插入物品定义（如不存在），再写入 character_inventory
        $fabaoCategory = 'fabao';
        $existingItem = Database::queryOne(
            "SELECT id FROM items WHERE item_id = ? AND category = ?",
            [$fullItemId, $fabaoCategory]
        );
        if (!$existingItem) {
            Database::execute(
                "INSERT INTO items (item_id, name, type, description, category, unit, weight, value, level, stackable, max_stack, fabao, series_no, quality)
                 VALUES (?, ?, ?, ?, ?, ?, 1, 0, 1, 0, 1, 1, ?, 'common')",
                [$fullItemId, $fabaoName, $fabaoType, $fabaoDesc, $fabaoCategory, $fabaoUnit, $seriesNo]
            );
        }

        // 写入背包
        Database::execute(
            "INSERT INTO character_inventory (char_id, item_id, category, quantity, equipped, equip_slot, durability, series_no)
             VALUES (?, ?, ?, 1, 0, '', 100, ?)",
            [$charId, $fullItemId, $fabaoCategory, $seriesNo]
        );

        $typeName = ($fabaoType === 'weapon') ? '武器' : '防具';
        return [
            'success' => true,
            'message' => "你成功制作了{$typeName}法宝「{$fabaoName}」！消耗内力{$forceCost}、法力{$manaCost}。",
            'data' => [
                'type' => 'make_fabao_success',
                'fabao_type' => $fabaoType,
                'name' => $fabaoName,
            ],
        ];
    }

    // ============================================================
    // 2. 法宝列表 (list_fabao)
    // ============================================================

    private function handleListFabao(int $charId, array $character): array {
        require_once __DIR__ . '/../includes/db.php';
        $fabaos = Database::queryAll(
            'SELECT * FROM character_fabao WHERE owner_id = ? ORDER BY series_no ASC',
            [$charId]
        );

        if (empty($fabaos)) {
            return [
                'success' => true,
                'message' => '你目前没有任何法宝。',
                'data' => null,
            ];
        }

        $html = '<h3>我的法宝</h3>';
        $html .= '<table border="0" cellpadding="0" cellspacing="0">';
        $html .= '<tr style="font-weight:bold;"><td style="padding:3px 10px 3px 0;">序号</td><td style="padding:3px 10px;">名称</td><td style="padding:3px 10px;">类型</td><td style="padding:3px 10px;">属性</td></tr>';

        foreach ($fabaos as $f) {
            $typeName = ($f['fabao_type'] === 'weapon') ? '武器' : '防具';
            $attrs = '';
            if ($f['fabao_type'] === 'weapon') {
                $attrs .= '气血伤害:' . intval($f['attack_qi']) . '/' . intval($f['max_attack_qi']) . ' ';
                $attrs .= '精神伤害:' . intval($f['attack_shen']) . '/' . intval($f['max_attack_shen']);
            } else {
                $attrs .= '气血保护:' . intval($f['defense_qi']) . '/' . intval($f['max_defense_qi']) . ' ';
                $attrs .= '精神保护:' . intval($f['defense_shen']) . '/' . intval($f['max_defense_shen']) . ' ';
                $attrs .= '收取力:' . intval($f['defense_shou']) . '/' . intval($f['max_defense_shou']);
            }
            $html .= '<tr>';
            $html .= '<td style="padding:3px 10px 3px 0;">' . h($f['series_no']) . '</td>';
            $html .= '<td style="padding:3px 10px;">' . h($f['name']) . '</td>';
            $html .= '<td style="padding:3px 10px;">' . h($typeName) . '</td>';
            $html .= '<td style="padding:3px 10px;">' . $attrs . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';

        $area = $character['current_area'] ?? '';
        $room = $character['current_room'] ?? '';
        $html .= '<br><a href="room.php?area=' . urlencode($area) . '&room=' . urlencode($room) . '">返回</a>';

        return ['success' => true, 'html' => $html];
    }

    // ============================================================
    // 3. 修炼法宝 (upgrade) - 多步交互
    // ============================================================

    private function handleUpgrade(int $charId, array $character, array $action, array $params): array {
        $step = $_POST['step'] ?? $_GET['step'] ?? '';

        switch ($step) {
            case 'select_attr':
                return $this->upgradeSelectAttr($charId, $character, $action);
            case 'execute':
                return $this->upgradeExecute($charId, $character, $action);
            default:
                return $this->upgradeSelectFabao($charId, $character, $action);
        }
    }

    /** 选择要修炼的法宝 */
    private function upgradeSelectFabao(int $charId, array $character, array $action): array {
        require_once __DIR__ . '/../includes/db.php';
        $fabaos = Database::queryAll(
            'SELECT * FROM character_fabao WHERE owner_id = ? ORDER BY series_no ASC',
            [$charId]
        );

        if (empty($fabaos)) {
            return ['success' => false, 'message' => '你没有任何法宝可以修炼。', 'data' => null];
        }

        $area = $_GET['area'] ?? $_POST['area'] ?? $character['current_area'] ?? '';
        $room = $_GET['room'] ?? $_POST['room'] ?? $character['current_room'] ?? '';

        $html = '<h3>修炼法宝</h3>';
        $html .= '<p>选择要修炼的法宝：</p>';
        $html .= '<form method="post" action="/functions/action.php">';
        $html .= '<input type="hidden" name="action" value="upgrade">';
        $html .= '<input type="hidden" name="step" value="select_attr">';
        $html .= '<input type="hidden" name="area" value="' . h($area) . '">';
        $html .= '<input type="hidden" name="room" value="' . h($room) . '">';
        $html .= '<p><select name="fabao_id">';
        foreach ($fabaos as $f) {
            $typeName = ($f['fabao_type'] === 'weapon') ? '武器' : '防具';
            $html .= '<option value="' . intval($f['id']) . '">' . h($f['name']) . '（' . h($typeName) . '）</option>';
        }
        $html .= '</select></p>';
        $html .= '<p><button type="submit">下一步</button></p>';
        $html .= '</form>';
        $html .= '<br><a href="room.php?area=' . urlencode($area) . '&room=' . urlencode($room) . '">返回</a>';

        return ['success' => true, 'html' => $html];
    }

    /** 选择要修炼的属性 */
    private function upgradeSelectAttr(int $charId, array $character, array $action): array {
        $fabaoId = intval($_POST['fabao_id'] ?? $_GET['fabao_id'] ?? 0);
        if ($fabaoId <= 0) {
            return ['success' => false, 'message' => '请选择法宝', 'data' => null];
        }

        $cfg = $this->getConfig($action);
        require_once __DIR__ . '/../includes/db.php';
        $fabao = Database::queryOne(
            'SELECT * FROM character_fabao WHERE id = ? AND owner_id = ?',
            [$fabaoId, $charId]
        );
        if (!$fabao) {
            return ['success' => false, 'message' => '法宝不存在', 'data' => null];
        }

        $area = $_POST['area'] ?? $_GET['area'] ?? $character['current_area'] ?? '';
        $room = $_POST['room'] ?? $_GET['room'] ?? $character['current_room'] ?? '';

        // 构建可选属性列表
        $attrs = [];
        if ($fabao['fabao_type'] === 'weapon') {
            $attrs['max_attack_qi'] = ['label' => '气血伤害力', 'current' => intval($fabao['max_attack_qi']), 'resource' => 'force'];
            $attrs['max_attack_shen'] = ['label' => '精神伤害力', 'current' => intval($fabao['max_attack_shen']), 'resource' => 'mana'];
        } else {
            $attrs['max_defense_qi'] = ['label' => '气血保护力', 'current' => intval($fabao['max_defense_qi']), 'resource' => 'force'];
            $attrs['max_defense_shen'] = ['label' => '精神保护力', 'current' => intval($fabao['max_defense_shen']), 'resource' => 'mana'];
            $attrs['max_defense_shou'] = ['label' => '法宝收取力', 'current' => intval($fabao['max_defense_shou']), 'resource' => 'both'];
        }

        $html = '<h3>修炼法宝 - ' . h($fabao['name']) . '</h3>';
        $html .= '<p>选择要修炼的属性：</p>';
        $html .= '<form method="post" action="/functions/action.php">';
        $html .= '<input type="hidden" name="action" value="upgrade">';
        $html .= '<input type="hidden" name="step" value="execute">';
        $html .= '<input type="hidden" name="fabao_id" value="' . intval($fabaoId) . '">';
        $html .= '<input type="hidden" name="area" value="' . h($area) . '">';
        $html .= '<input type="hidden" name="room" value="' . h($room) . '">';

        $maxLvl = $cfg['upgrade_max_level'];
        foreach ($attrs as $attrKey => $attrInfo) {
            $currentLevel = $attrInfo['current'];
            $nextLevel = $currentLevel + 1;
            if ($nextLevel > $maxLvl) {
                $html .= '<p>' . h($attrInfo['label']) . "：已达满级（{$maxLvl}级）</p>";
                continue;
            }
            $daoxingCost = $nextLevel * $cfg['upgrade_daoxing_cost_mult'];
            $daoxingReq = $nextLevel * $cfg['upgrade_daoxing_mult'];
            $resCost = $nextLevel * $cfg['upgrade_res_cost_mult'];
            $resReq = $nextLevel * $cfg['upgrade_res_req_mult'];
            $resName = ($attrInfo['resource'] === 'force') ? '内力' : (($attrInfo['resource'] === 'mana') ? '法力' : '内力+法力');
            $costDesc = "需道行{$daoxingReq}，消耗道行{$daoxingCost}、{$resName}{$resCost}";
            $html .= '<p><label><input type="radio" name="attr" value="' . h($attrKey) . '"> ' . h($attrInfo['label']) . '（当前等级' . $currentLevel . '，' . $costDesc . '）</label></p>';
        }

        $html .= '<p><button type="submit">修炼</button></p>';
        $html .= '</form>';
        $html .= '<br><a href="room.php?area=' . urlencode($area) . '&room=' . urlencode($room) . '">返回</a>';

        return ['success' => true, 'html' => $html];
    }

    /** 执行修炼 */
    private function upgradeExecute(int $charId, array $character, array $action): array {
        $fabaoId = intval($_POST['fabao_id'] ?? 0);
        $attr = $_POST['attr'] ?? '';

        if ($fabaoId <= 0 || empty($attr)) {
            return ['success' => false, 'message' => '参数不完整', 'data' => null];
        }

        // 允许的属性列表
        $allowedAttrs = ['max_attack_qi', 'max_attack_shen', 'max_defense_qi', 'max_defense_shen', 'max_defense_shou'];
        if (!in_array($attr, $allowedAttrs)) {
            return ['success' => false, 'message' => '无效的属性', 'data' => null];
        }

        $cfg = $this->getConfig($action);
        require_once __DIR__ . '/../includes/db.php';
        $fabao = Database::queryOne(
            'SELECT * FROM character_fabao WHERE id = ? AND owner_id = ?',
            [$fabaoId, $charId]
        );
        if (!$fabao) {
            return ['success' => false, 'message' => '法宝不存在', 'data' => null];
        }

        $currentLevel = intval($fabao[$attr]);
        $nextLevel = $currentLevel + 1;
        $maxLvl = $cfg['upgrade_max_level'];

        if ($nextLevel > $maxLvl) {
            return ['success' => false, 'message' => "该属性已达满级（{$maxLvl}级）", 'data' => null];
        }

        // 计算需求（从配置读取乘数）
        $daoxingReq = $nextLevel * $cfg['upgrade_daoxing_mult'];
        $daoxingCost = $nextLevel * $cfg['upgrade_daoxing_cost_mult'];
        $resReq = $nextLevel * $cfg['upgrade_res_req_mult'];
        $resCost = $nextLevel * $cfg['upgrade_res_cost_mult'];

        // 判断资源类型
        $resourceType = $this->getResourceType($attr);

        $daoxing = intval($character['daoxing'] ?? 0);
        $force = intval($character['force'] ?? 0);
        $mana = intval($character['mana'] ?? 0);

        // 检查道行需求
        if ($daoxing < $daoxingReq) {
            return ['success' => false, 'message' => "道行不足{$daoxingReq}（当前：{$daoxing}）", 'data' => null];
        }

        // 检查并扣除资源
        if ($resourceType === 'force') {
            if ($force < $resReq || $force < $resCost) {
                return ['success' => false, 'message' => "内力不足（需要：{$resReq}，消耗：{$resCost}，当前：{$force}）", 'data' => null];
            }
            Database::execute('UPDATE characters SET daoxing = daoxing - ?, `force` = `force` - ? WHERE id = ?', [$daoxingCost, $resCost, $charId]);
        } elseif ($resourceType === 'mana') {
            if ($mana < $resReq || $mana < $resCost) {
                return ['success' => false, 'message' => "法力不足（需要：{$resReq}，消耗：{$resCost}，当前：{$mana}）", 'data' => null];
            }
            Database::execute('UPDATE characters SET daoxing = daoxing - ?, mana = mana - ? WHERE id = ?', [$daoxingCost, $resCost, $charId]);
        } else {
            // both: 内力+法力（各消耗resCost点）
            if ($force < $resReq || $mana < $resReq || $force < $resCost || $mana < $resCost) {
                return ['success' => false, 'message' => "内力或法力不足（各需：{$resReq}，各消耗：{$resCost}，当前内力：{$force}、法力：{$mana}）", 'data' => null];
            }
            Database::execute('UPDATE characters SET daoxing = daoxing - ?, `force` = `force` - ?, mana = mana - ? WHERE id = ?', [$daoxingCost, $resCost, $resCost, $charId]);
        }

        // 更新法宝属性：max值+1，当前值同步+1
        $currentAttr = str_replace('max_', '', $attr);
        Database::execute(
            "UPDATE character_fabao SET {$attr} = {$attr} + 1, {$currentAttr} = {$currentAttr} + 1 WHERE id = ? AND owner_id = ?",
            [$fabaoId, $charId]
        );

        $attrLabels = [
            'max_attack_qi' => '气血伤害力',
            'max_attack_shen' => '精神伤害力',
            'max_defense_qi' => '气血保护力',
            'max_defense_shen' => '精神保护力',
            'max_defense_shou' => '法宝收取力',
        ];
        $attrLabel = $attrLabels[$attr] ?? $attr;

        return [
            'success' => true,
            'message' => "你修炼了「{$fabao['name']}」的{$attrLabel}，等级提升至{$nextLevel}！消耗道行{$daoxingCost}。",
            'data' => ['type' => 'upgrade_success', 'attr' => $attr, 'new_level' => $nextLevel],
        ];
    }

    // ============================================================
    // 4. 充能法宝 (charge) - 多步交互
    // ============================================================

    private function handleCharge(int $charId, array $character, array $action, array $params): array {
        $step = $_POST['step'] ?? $_GET['step'] ?? '';

        switch ($step) {
            case 'show_detail':
                return $this->chargeShowDetail($charId, $character, $action);
            case 'execute':
                return $this->chargeExecute($charId, $character, $action);
            default:
                return $this->chargeSelectFabao($charId, $character, $action);
        }
    }

    /** 选择要充能的法宝 */
    private function chargeSelectFabao(int $charId, array $character, array $action): array {
        require_once __DIR__ . '/../includes/db.php';
        $fabaos = Database::queryAll(
            'SELECT * FROM character_fabao WHERE owner_id = ? ORDER BY series_no ASC',
            [$charId]
        );

        if (empty($fabaos)) {
            return ['success' => false, 'message' => '你没有任何法宝可以充能。', 'data' => null];
        }

        $area = $_GET['area'] ?? $_POST['area'] ?? $character['current_area'] ?? '';
        $room = $_GET['room'] ?? $_POST['room'] ?? $character['current_room'] ?? '';

        $html = '<h3>充能法宝</h3>';
        $html .= '<p>选择要充能的法宝：</p>';
        $html .= '<form method="post" action="/functions/action.php">';
        $html .= '<input type="hidden" name="action" value="charge">';
        $html .= '<input type="hidden" name="step" value="show_detail">';
        $html .= '<input type="hidden" name="area" value="' . h($area) . '">';
        $html .= '<input type="hidden" name="room" value="' . h($room) . '">';
        $html .= '<p><select name="fabao_id">';
        foreach ($fabaos as $f) {
            $typeName = ($f['fabao_type'] === 'weapon') ? '武器' : '防具';
            $html .= '<option value="' . intval($f['id']) . '">' . h($f['name']) . '（' . h($typeName) . '）</option>';
        }
        $html .= '</select></p>';
        $html .= '<p><button type="submit">查看充能详情</button></p>';
        $html .= '</form>';
        $html .= '<br><a href="room.php?area=' . urlencode($area) . '&room=' . urlencode($room) . '">返回</a>';

        return ['success' => true, 'html' => $html];
    }

    /** 显示充能详情 */
    private function chargeShowDetail(int $charId, array $character, array $action): array {
        $fabaoId = intval($_POST['fabao_id'] ?? $_GET['fabao_id'] ?? 0);
        if ($fabaoId <= 0) {
            return ['success' => false, 'message' => '请选择法宝', 'data' => null];
        }

        require_once __DIR__ . '/../includes/db.php';
        $fabao = Database::queryOne(
            'SELECT * FROM character_fabao WHERE id = ? AND owner_id = ?',
            [$fabaoId, $charId]
        );
        if (!$fabao) {
            return ['success' => false, 'message' => '法宝不存在', 'data' => null];
        }

        $area = $_POST['area'] ?? $_GET['area'] ?? $character['current_area'] ?? '';
        $room = $_POST['room'] ?? $_GET['room'] ?? $character['current_room'] ?? '';

        $chargeDetails = $this->calcChargeDetails($fabao);
        $hasDeficit = false;

        $html = '<h3>充能法宝 - ' . h($fabao['name']) . '</h3>';
        $html .= '<table border="0" cellpadding="0" cellspacing="0">';
        $html .= '<tr style="font-weight:bold;"><td style="padding:3px 10px 3px 0;">属性</td><td style="padding:3px 10px;">当前/上限</td><td style="padding:3px 10px;">缺损</td><td style="padding:3px 10px;">费用</td><td style="padding:3px 10px;">操作</td></tr>';

        foreach ($chargeDetails as $key => $detail) {
            $deficit = $detail['deficit'];
            if ($deficit > 0) {
                $hasDeficit = true;
            }
            $costText = ($deficit > 0) ? $detail['cost_text'] : '-';
            $opText = ($deficit > 0)
                ? '<a href="/functions/action.php?action=charge&step=execute&fabao_id=' . intval($fabaoId) . '&charge_attr=' . h($key) . '&area=' . urlencode($area) . '&room=' . urlencode($room) . '">充能</a>'
                : '已满';
            $html .= '<tr>';
            $html .= '<td style="padding:3px 10px 3px 0;">' . h($detail['label']) . '</td>';
            $html .= '<td style="padding:3px 10px;">' . intval($detail['current']) . '/' . intval($detail['max']) . '</td>';
            $html .= '<td style="padding:3px 10px;">' . $deficit . '</td>';
            $html .= '<td style="padding:3px 10px;">' . $costText . '</td>';
            $html .= '<td style="padding:3px 10px;">' . $opText . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';

        if ($hasDeficit) {
            $html .= '<br><a href="/functions/action.php?action=charge&step=execute&fabao_id=' . intval($fabaoId) . '&charge_attr=all&area=' . urlencode($area) . '&room=' . urlencode($room) . '">全部充能</a>';
        }

        $html .= '<br><a href="room.php?area=' . urlencode($area) . '&room=' . urlencode($room) . '">返回</a>';

        return ['success' => true, 'html' => $html];
    }

    /** 执行充能（还原 LPC do_charge：每次充 1 格，消耗 100 内力+100 法力） */
    private function chargeExecute(int $charId, array $character, array $action): array {
        $fabaoId = intval($_GET['fabao_id'] ?? $_POST['fabao_id'] ?? 0);
        $chargeAttr = $_GET['charge_attr'] ?? $_POST['charge_attr'] ?? '';

        if ($fabaoId <= 0 || empty($chargeAttr)) {
            return ['success' => false, 'message' => '参数不完整', 'data' => null];
        }

        require_once __DIR__ . '/../includes/db.php';
        $fabao = Database::queryOne(
            'SELECT * FROM character_fabao WHERE id = ? AND owner_id = ?',
            [$fabaoId, $charId]
        );
        if (!$fabao) {
            return ['success' => false, 'message' => '法宝不存在', 'data' => null];
        }

        $cfg = $this->getConfig($action);
        $chargeDetails = $this->calcChargeDetails($fabao, $cfg);
        $force = intval($character['force'] ?? 0);
        $mana = intval($character['mana'] ?? 0);

        // LPC 检查：内力/法力至少要有 3*cost 才能充能
        $minForce = 3 * $cfg['charge_force_per'];
        $minMana = 3 * $cfg['charge_mana_per'];
        if ($force < $minForce) {
            return ['success' => false, 'message' => "你目前的内力太低，不能修炼法宝。（需要：{$minForce}，当前：{$force}）", 'data' => null];
        }
        if ($mana < $minMana) {
            return ['success' => false, 'message' => "你目前的法力太低，不能修炼法宝。（需要：{$minMana}，当前：{$mana}）", 'data' => null];
        }

        $totalForceCost = 0;
        $totalManaCost = 0;
        $updates = [];
        $chargedAttrs = [];

        if ($chargeAttr === 'all') {
            // LPC: 同时充所有有缺损的属性，每个属性充 1 格
            foreach ($chargeDetails as $key => $detail) {
                if ($detail['deficit'] > 0) {
                    $totalForceCost += $detail['force_cost'];
                    $totalManaCost += $detail['mana_cost'];
                    // 充 1 格：当前值 +1
                    $updates[$key] = intval($fabao[$key]) + 1;
                    $chargedAttrs[] = $detail['label'];
                }
            }
        } else {
            if (!isset($chargeDetails[$chargeAttr])) {
                return ['success' => false, 'message' => '无效的充能属性', 'data' => null];
            }
            $detail = $chargeDetails[$chargeAttr];
            if ($detail['deficit'] <= 0) {
                return ['success' => false, 'message' => '该属性已满，无需充能', 'data' => null];
            }
            $totalForceCost = $detail['force_cost'];
            $totalManaCost = $detail['mana_cost'];
            $updates[$chargeAttr] = intval($fabao[$chargeAttr]) + 1;
            $chargedAttrs[] = $detail['label'];
        }

        // 检查资源是否足够
        if ($force < $totalForceCost) {
            return ['success' => false, 'message' => "内力不足（需要：{$totalForceCost}，当前：{$force}）", 'data' => null];
        }
        if ($mana < $totalManaCost) {
            return ['success' => false, 'message' => "法力不足（需要：{$totalManaCost}，当前：{$mana}）", 'data' => null];
        }

        // 扣除资源
        if ($totalForceCost > 0 || $totalManaCost > 0) {
            Database::execute(
                'UPDATE characters SET `force` = `force` - ?, mana = mana - ? WHERE id = ?',
                [$totalForceCost, $totalManaCost, $charId]
            );
        }

        // 更新法宝属性（充 1 格）
        foreach ($updates as $currentKey => $newValue) {
            Database::execute(
                "UPDATE character_fabao SET {$currentKey} = ? WHERE id = ? AND owner_id = ?",
                [$newValue, $fabaoId, $charId]
            );
        }

        $costMsg = '';
        if ($totalForceCost > 0) $costMsg .= "内力{$totalForceCost}";
        if ($totalForceCost > 0 && $totalManaCost > 0) $costMsg .= '、';
        if ($totalManaCost > 0) $costMsg .= "法力{$totalManaCost}";

        $chargedList = implode('、', $chargedAttrs);
        return [
            'success' => true,
            'message' => "你口中念念有词，以内力/法力缓缓注入「{$fabao['name']}」中，{$chargedList}提升1格！消耗{$costMsg}。",
            'data' => ['type' => 'charge_success', 'fabao_id' => $fabaoId],
        ];
    }

    /** 计算充能详情（按 LPC 逻辑：每格消耗固定 100 内力+100 法力） */
    private function calcChargeDetails(array $fabao, array $cfg = []): array {
        if (empty($cfg)) {
            $cfg = self::$configCache ?? $this->getDefaultConfig();
        }
        $details = [];

        if ($fabao['fabao_type'] === 'weapon') {
            $deficitQi = intval($fabao['max_attack_qi']) - intval($fabao['attack_qi']);
            $details['attack_qi'] = [
                'label' => '气血伤害力',
                'current' => intval($fabao['attack_qi']),
                'max' => intval($fabao['max_attack_qi']),
                'deficit' => $deficitQi,
                'force_cost' => ($deficitQi > 0) ? $cfg['charge_force_per'] : 0,
                'mana_cost' => ($deficitQi > 0) ? $cfg['charge_mana_per'] : 0,
                'cost_text' => $deficitQi > 0 ? "内力{$cfg['charge_force_per']}、法力{$cfg['charge_mana_per']}（充1格）" : '-',
            ];
            $deficitShen = intval($fabao['max_attack_shen']) - intval($fabao['attack_shen']);
            $details['attack_shen'] = [
                'label' => '精神伤害力',
                'current' => intval($fabao['attack_shen']),
                'max' => intval($fabao['max_attack_shen']),
                'deficit' => $deficitShen,
                'force_cost' => ($deficitShen > 0) ? $cfg['charge_force_per'] : 0,
                'mana_cost' => ($deficitShen > 0) ? $cfg['charge_mana_per'] : 0,
                'cost_text' => $deficitShen > 0 ? "内力{$cfg['charge_force_per']}、法力{$cfg['charge_mana_per']}（充1格）" : '-',
            ];
        } else {
            $deficitQi = intval($fabao['max_defense_qi']) - intval($fabao['defense_qi']);
            $details['defense_qi'] = [
                'label' => '气血保护力',
                'current' => intval($fabao['defense_qi']),
                'max' => intval($fabao['max_defense_qi']),
                'deficit' => $deficitQi,
                'force_cost' => ($deficitQi > 0) ? $cfg['charge_force_per'] : 0,
                'mana_cost' => ($deficitQi > 0) ? $cfg['charge_mana_per'] : 0,
                'cost_text' => $deficitQi > 0 ? "内力{$cfg['charge_force_per']}、法力{$cfg['charge_mana_per']}（充1格）" : '-',
            ];
            $deficitShen = intval($fabao['max_defense_shen']) - intval($fabao['defense_shen']);
            $details['defense_shen'] = [
                'label' => '精神保护力',
                'current' => intval($fabao['defense_shen']),
                'max' => intval($fabao['max_defense_shen']),
                'deficit' => $deficitShen,
                'force_cost' => ($deficitShen > 0) ? $cfg['charge_force_per'] : 0,
                'mana_cost' => ($deficitShen > 0) ? $cfg['charge_mana_per'] : 0,
                'cost_text' => $deficitShen > 0 ? "内力{$cfg['charge_force_per']}、法力{$cfg['charge_mana_per']}（充1格）" : '-',
            ];
            $deficitShou = intval($fabao['max_defense_shou']) - intval($fabao['defense_shou']);
            $details['defense_shou'] = [
                'label' => '法宝收取力',
                'current' => intval($fabao['defense_shou']),
                'max' => intval($fabao['max_defense_shou']),
                'deficit' => $deficitShou,
                'force_cost' => ($deficitShou > 0) ? $cfg['charge_force_per'] : 0,
                'mana_cost' => ($deficitShou > 0) ? $cfg['charge_mana_per'] : 0,
                'cost_text' => $deficitShou > 0 ? "内力{$cfg['charge_force_per']}、法力{$cfg['charge_mana_per']}（充1格）" : '-',
            ];
        }

        return $details;
    }

    // ============================================================
    // 5. 查看费用 (cost)
    // ============================================================

    private function handleCost(int $charId, array $character, array $action = []): array {
        require_once __DIR__ . '/../includes/db.php';
        $cfg = $this->getConfig($action);
        $fabaos = Database::queryAll(
            'SELECT * FROM character_fabao WHERE owner_id = ? ORDER BY series_no ASC',
            [$charId]
        );

        $area = $character['current_area'] ?? '';
        $room = $character['current_room'] ?? '';
        $deficit = $cfg['force_mana_deficit'];

        if (empty($fabaos)) {
            $html = '<h3>法宝费用说明</h3>';
            $html .= '<p>你目前没有法宝。</p>';
            $html .= '<h4>制作法宝通用要求</h4>';
            $html .= "<p>道行 >= {$cfg['daoxing_min']}<br>最大法力 >= {$cfg['max_mana_min']}<br>最大内力 >= {$cfg['max_force_min']}<br>内力接近满值（差额不超过{$deficit}）<br>法力接近满值（差额不超过{$deficit}）<br>法宝数量 < {$cfg['fabao_max_count']}</p>";
            $html .= "<p>制作消耗：内力{$cfg['make_force_cost']}、法力{$cfg['make_mana_cost']}</p>";
            $html .= '<br><a href="room.php?area=' . urlencode($area) . '&room=' . urlencode($room) . '">返回</a>';
            return ['success' => true, 'html' => $html];
        }

        $html = '<h3>法宝费用详情</h3>';

        foreach ($fabaos as $fabao) {
            $typeName = ($fabao['fabao_type'] === 'weapon') ? '武器' : '防具';
            $html .= '<h4>' . h($fabao['name']) . '（' . h($typeName) . '）</h4>';

            // 修炼费用
            $html .= '<p><b>修炼费用：</b></p>';
            $html .= '<table border="0" cellpadding="0" cellspacing="0">';
            $html .= '<tr style="font-weight:bold;"><td style="padding:2px 10px 2px 0;">属性</td><td style="padding:2px 10px;">当前等级</td><td style="padding:2px 10px;">下一级费用</td></tr>';

            if ($fabao['fabao_type'] === 'weapon') {
                $this->appendUpgradeCostRow($html, '气血伤害力', intval($fabao['max_attack_qi']), 'force', $cfg);
                $this->appendUpgradeCostRow($html, '精神伤害力', intval($fabao['max_attack_shen']), 'mana', $cfg);
            } else {
                $this->appendUpgradeCostRow($html, '气血保护力', intval($fabao['max_defense_qi']), 'force', $cfg);
                $this->appendUpgradeCostRow($html, '精神保护力', intval($fabao['max_defense_shen']), 'mana', $cfg);
                $this->appendUpgradeCostRow($html, '法宝收取力', intval($fabao['max_defense_shou']), 'both', $cfg);
            }
            $html .= '</table>';

            // 充能费用
            $chargeDetails = $this->calcChargeDetails($fabao, $cfg);
            $hasDeficit = false;
            foreach ($chargeDetails as $detail) {
                if ($detail['deficit'] > 0) {
                    $hasDeficit = true;
                    break;
                }
            }

            if ($hasDeficit) {
                $html .= '<p><b>充能费用：</b></p>';
                $html .= '<table border="0" cellpadding="0" cellspacing="0">';
                $html .= '<tr style="font-weight:bold;"><td style="padding:2px 10px 2px 0;">属性</td><td style="padding:2px 10px;">缺损</td><td style="padding:2px 10px;">费用</td></tr>';
                foreach ($chargeDetails as $detail) {
                    if ($detail['deficit'] > 0) {
                        $html .= '<tr>';
                        $html .= '<td style="padding:2px 10px 2px 0;">' . h($detail['label']) . '</td>';
                        $html .= '<td style="padding:2px 10px;">' . $detail['deficit'] . '</td>';
                        $html .= '<td style="padding:2px 10px;">' . h($detail['cost_text']) . '</td>';
                        $html .= '</tr>';
                    }
                }
                $html .= '</table>';
            } else {
                $html .= '<p>充能费用：所有属性已满，无需充能。</p>';
            }

            $html .= '<hr style="border:none;border-top:1px solid #555;margin:10px 0;">';
        }

        $html .= '<a href="room.php?area=' . urlencode($area) . '&room=' . urlencode($room) . '">返回</a>';

        return ['success' => true, 'html' => $html];
    }

    /** 追加一行修炼费用到HTML */
    private function appendUpgradeCostRow(string &$html, string $label, int $currentLevel, string $resourceType, array $cfg): void {
        $nextLevel = $currentLevel + 1;
        $maxLvl = $cfg['upgrade_max_level'];
        $html .= '<tr>';
        $html .= '<td style="padding:2px 10px 2px 0;">' . h($label) . '</td>';
        $html .= '<td style="padding:2px 10px;">' . $currentLevel . '</td>';
        if ($nextLevel > $maxLvl) {
            $html .= '<td style="padding:2px 10px;">已满级</td>';
        } else {
            $daoxingCost = $nextLevel * $cfg['upgrade_daoxing_cost_mult'];
            $daoxingReq = $nextLevel * $cfg['upgrade_daoxing_mult'];
            $resCost = $nextLevel * $cfg['upgrade_res_cost_mult'];
            $resReq = $nextLevel * $cfg['upgrade_res_req_mult'];
            $resName = ($resourceType === 'force') ? '内力' : (($resourceType === 'mana') ? '法力' : '内力+法力');
            $html .= '<td style="padding:2px 10px;">需道行' . $daoxingReq . '，消耗道行' . $daoxingCost . '、' . $resName . $resCost . '</td>';
        }
        $html .= '</tr>';
    }

    // ============================================================
    // 6. 销毁法宝 (dispose) - 多步交互
    // ============================================================

    private function handleDispose(int $charId, array $character, array $action, array $params): array {
        $step = $_POST['step'] ?? $_GET['step'] ?? '';

        switch ($step) {
            case 'confirm':
                return $this->disposeConfirm($charId, $character, $action);
            case 'execute':
                return $this->disposeExecute($charId, $character);
            default:
                return $this->disposeSelectFabao($charId, $character, $action);
        }
    }

    /** 选择要销毁的法宝 */
    private function disposeSelectFabao(int $charId, array $character, array $action): array {
        require_once __DIR__ . '/../includes/db.php';
        $fabaos = Database::queryAll(
            'SELECT * FROM character_fabao WHERE owner_id = ? ORDER BY series_no ASC',
            [$charId]
        );

        if (empty($fabaos)) {
            return ['success' => false, 'message' => '你没有任何法宝可以销毁。', 'data' => null];
        }

        $area = $_GET['area'] ?? $_POST['area'] ?? $character['current_area'] ?? '';
        $room = $_GET['room'] ?? $_POST['room'] ?? $character['current_room'] ?? '';

        $html = '<h3>销毁法宝</h3>';
        $html .= '<p style="color:#ff6666;">警告：销毁法宝不可恢复！</p>';
        $html .= '<p>选择要销毁的法宝：</p>';
        $html .= '<form method="post" action="/functions/action.php">';
        $html .= '<input type="hidden" name="action" value="dispose">';
        $html .= '<input type="hidden" name="step" value="confirm">';
        $html .= '<input type="hidden" name="area" value="' . h($area) . '">';
        $html .= '<input type="hidden" name="room" value="' . h($room) . '">';
        $html .= '<p><select name="fabao_id">';
        foreach ($fabaos as $f) {
            $typeName = ($f['fabao_type'] === 'weapon') ? '武器' : '防具';
            $html .= '<option value="' . intval($f['id']) . '">' . h($f['name']) . '（' . h($typeName) . '）</option>';
        }
        $html .= '</select></p>';
        $html .= '<p><button type="submit">下一步</button></p>';
        $html .= '</form>';
        $html .= '<br><a href="room.php?area=' . urlencode($area) . '&room=' . urlencode($room) . '">返回</a>';

        return ['success' => true, 'html' => $html];
    }

    /** 确认销毁 */
    private function disposeConfirm(int $charId, array $character, array $action): array {
        $fabaoId = intval($_POST['fabao_id'] ?? $_GET['fabao_id'] ?? 0);
        if ($fabaoId <= 0) {
            return ['success' => false, 'message' => '请选择法宝', 'data' => null];
        }

        require_once __DIR__ . '/../includes/db.php';
        $fabao = Database::queryOne(
            'SELECT * FROM character_fabao WHERE id = ? AND owner_id = ?',
            [$fabaoId, $charId]
        );
        if (!$fabao) {
            return ['success' => false, 'message' => '法宝不存在', 'data' => null];
        }

        $area = $_POST['area'] ?? $_GET['area'] ?? $character['current_area'] ?? '';
        $room = $_POST['room'] ?? $_GET['room'] ?? $character['current_room'] ?? '';
        $typeName = ($fabao['fabao_type'] === 'weapon') ? '武器' : '防具';

        $html = '<h3>确认销毁法宝</h3>';
        $html .= '<p style="color:#ff6666;">你确定要销毁以下法宝吗？此操作不可恢复！</p>';
        $html .= '<p>名称：' . h($fabao['name']) . '<br>类型：' . h($typeName) . '</p>';

        $html .= '<form method="post" action="/functions/action.php">';
        $html .= '<input type="hidden" name="action" value="dispose">';
        $html .= '<input type="hidden" name="step" value="execute">';
        $html .= '<input type="hidden" name="fabao_id" value="' . intval($fabaoId) . '">';
        $html .= '<input type="hidden" name="area" value="' . h($area) . '">';
        $html .= '<input type="hidden" name="room" value="' . h($room) . '">';
        $html .= '<p><button type="submit" style="color:#ff6666;">确认销毁</button></p>';
        $html .= '</form>';
        $html .= '<a href="room.php?area=' . urlencode($area) . '&room=' . urlencode($room) . '">取消</a>';

        return ['success' => true, 'html' => $html];
    }

    /** 执行销毁 */
    private function disposeExecute(int $charId, array $character): array {
        $fabaoId = intval($_POST['fabao_id'] ?? 0);
        if ($fabaoId <= 0) {
            return ['success' => false, 'message' => '参数不完整', 'data' => null];
        }

        require_once __DIR__ . '/../includes/db.php';
        $fabao = Database::queryOne(
            'SELECT * FROM character_fabao WHERE id = ? AND owner_id = ?',
            [$fabaoId, $charId]
        );
        if (!$fabao) {
            return ['success' => false, 'message' => '法宝不存在', 'data' => null];
        }

        $fabaoName = $fabao['name'];
        Database::execute(
            'DELETE FROM character_fabao WHERE id = ? AND owner_id = ?',
            [$fabaoId, $charId]
        );

        return [
            'success' => true,
            'message' => "你销毁了法宝「{$fabaoName}」。",
            'data' => ['type' => 'dispose_success', 'fabao_id' => $fabaoId],
        ];
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    /** 获取属性对应的资源类型 */
    private function getResourceType(string $attr): string {
        $map = [
            'max_attack_qi' => 'force',
            'max_attack_shen' => 'mana',
            'max_defense_qi' => 'force',
            'max_defense_shen' => 'mana',
            'max_defense_shou' => 'both',
        ];
        return $map[$attr] ?? 'force';
    }
}
