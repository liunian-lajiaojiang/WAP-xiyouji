<?php

require_once __DIR__ . '/ansi.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../helpers/BanHelper.php';

function get_client_ip() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}

function h($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function require_login() {
    if (!isset($_SESSION['user_id'])) {
        // 检查是否是 AJAX 请求
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        
        if ($isAjax) {
            // AJAX 请求：返回 JSON 错误
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            // 普通请求：重定向到登录页
            redirect('../index.php');
        }
    }
    
    // 检查用户状态（封禁/监禁）
    $userId = $_SESSION['user_id'];
    $user = Database::queryOne("SELECT status FROM users WHERE id = ?", [$userId]);
    
    if (!$user) {
        session_destroy();
        redirect('../index.php?error=notfound');
    }
    
    // 检查是否被封禁
    if ($user['status'] == BanHelper::STATUS_BANNED) {
        session_destroy();
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        
        if ($isAjax) {
            while (ob_get_level() > 0) { ob_end_clean(); }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => '账号已被封禁'], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            redirect('../index.php?error=banned');
        }
    }
    
    // 检查是否被监禁（不踢出，但记录状态）
    if ($user['status'] == BanHelper::STATUS_PRISONED) {
        $_SESSION['imprisoned'] = true;
    } else {
        unset($_SESSION['imprisoned']);
    }
    
    // 检查是否在欢迎室
    if ($user['status'] == BanHelper::STATUS_GUEST) {
        // 检查欢迎室配置表
        $guestConfig = Database::queryOne(
            "SELECT * FROM guest_room_config WHERE user_id = ? AND status = 1 ORDER BY enter_time DESC LIMIT 1",
            [$userId]
        );
        if ($guestConfig) {
            $_SESSION['in_guest_room'] = true;
        } else {
            // 欢迎室记录已释放，清除标记
            unset($_SESSION['in_guest_room']);
        }
    } else {
        unset($_SESSION['in_guest_room']);
    }
    
    // 玩家在线：累积游戏时间
    update_online_time();
}

/**
 * 累积玩家在线时间（每次页面请求调用）
 * 使用session记录上次访问时间，计算间隔并累加到mud_age
 */
function update_online_time() {
    $charId = get_char_id();
    if (!$charId) return;
    
    $now = time();
    $lastTime = $_SESSION['last_online_time'] ?? 0;
    $_SESSION['last_online_time'] = $now;
    
    if ($lastTime <= 0) return; // 首次访问，不累积
    
    $elapsed = $now - $lastTime;
    if ($elapsed <= 0 || $elapsed > 86400) return; // 无效间隔或超过1天（可能是关服）
    
    try {
        require_once __DIR__ . '/../config/game.php';
        require_once __DIR__ . '/db.php';
        Database::execute(
            "UPDATE characters SET mud_age = mud_age + ? WHERE id = ?",
            [$elapsed, $charId]
        );
    } catch (Exception $e) {
        // 静默失败，不影响正常页面加载
    }
}

function get_char_id() {
    return $_SESSION['char_id'] ?? 0;
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function ansi_to_html($text) {
    $ansi_codes = [
        "\x1B[1;32m" => '<span style="color:green;font-weight:bold;">',
        "\x1B[1;31m" => '<span style="color:red;font-weight:bold;">',
        "\x1B[1;33m" => '<span style="color:orange;font-weight:bold;">',
        "\x1B[1;36m" => '<span style="color:cyan;font-weight:bold;">',
        "\x1B[1;34m" => '<span style="color:blue;font-weight:bold;">',
        "\x1B[1;35m" => '<span style="color:purple;font-weight:bold;">',
        "\x1B[0m" => '</span>',
        "\x1B[2;37;0m" => '</span>',
    ];
    return str_replace(array_keys($ansi_codes), array_values($ansi_codes), $text);
}

function describe_dx($daoxing) {
    // 道行等级描述（16级），立方增长 (grade+1)³ × 2000
    static $dxLevelDesc = [
        '新入道途', '闻道则喜', '初领妙道', '略通道术',
        '渐入佳境', '元神初具', '道心稳固', '一日千里',
        '道高德隆', '脱胎换骨', '霞举飞升', '道满根归',
        '不堕轮回', '已证大道', '反璞归真', '天人合一',
    ];
    $twoYear = intdiv((int)$daoxing, 2000);
    $count = count($dxLevelDesc);
    $grade = 0;
    for ($i = 0; $i < $count; $i++) {
        if ($twoYear < pow($i + 1, 3)) break;
        $grade = $i;
    }
    if ($grade >= $count) $grade = $count - 1;
    return $dxLevelDesc[$grade];
}

function describe_exp($exp) {
    // 实战经验等级描述（20级），立方增长，lvl = exp × 2 / 675
    static $expLevelDesc = [
        '初学乍练', '初窥门径', '粗通皮毛', '略知一二',
        '半生不熟', '马马虎虎', '已有小成', '渐入佳境',
        '驾轻就熟', '了然于胸', '出类拔萃', '心领神会',
        '神乎其技', '出神入化', '豁然贯通', '登峰造极',
        '举世无双', '一代宗师', '震古铄今', '深不可测',
    ];
    $lvl = intdiv((int)$exp * 2, 675);
    $count = count($expLevelDesc);
    $grade = 0;
    for ($i = 0; $i < $count; $i++) {
        if ($lvl < pow($i + 1, 3)) break;
        $grade = $i;
    }
    if ($grade >= $count) $grade = $count - 1;
    return $expLevelDesc[$grade];
}

function describe_fali($mana) {
    // 法力等级描述（14级），二次方增长 (grade+1)² × 40
    static $faliLevelDesc = [
        '初具法力', '略晓变化', '降龙伏虎', '腾云驾雾',
        '神出鬼没', '预知祸福', '妙领天机', '呼风唤雨',
        '负海担山', '移星换斗', '包罗万象', '随心所欲',
        '变换莫测', '法力无边',
    ];
    $lvl = intdiv((int)$mana, 40);
    $count = count($faliLevelDesc);
    $grade = 0;
    for ($i = 0; $i < $count; $i++) {
        if ($lvl < pow($i + 1, 2)) break;
        $grade = $i;
    }
    if ($grade >= $count) $grade = $count - 1;
    return $faliLevelDesc[$grade];
}

function describe_neili($force) {
    // 内力描述：以年/甲子格式显示，1年 = 100内力
    $year = intdiv((int)$force, 100);
    if ($year <= 0) {
        return '不到一年';
    }
    $jiazi = intdiv($year, 60);
    $remaining = $year % 60;
    if ($jiazi > 0) {
        if ($remaining != 0) {
            return $jiazi . '甲子又' . $remaining . '年';
        } else {
            return $jiazi . '甲子';
        }
    } else {
        return $year . '年';
    }
}

function log_game($type, $message) {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/' . date('Y-m-d') . '.log';
    $logEntry = date('Y-m-d H:i:s') . " [$type] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

/**
 * 生成NPC页面URL
 */
function npc_url($npcId) {
    return 'npc.php?id=' . urlencode($npcId);
}

/**
 * 生成物品页面URL
 */
function item_url($itemId, string $category = '') {
    $url = 'item.php?id=' . urlencode($itemId);
    if ($category !== '') {
        $url .= '&category=' . urlencode($category);
    }
    return $url;
}

/**
 * 生成动作URL
 */
function action_url($action, $params = []) {
    $url = 'action.php?action=' . urlencode($action);
    foreach ($params as $key => $value) {
        $url .= '&' . urlencode($key) . '=' . urlencode($value);
    }
    return $url;
}

/**
 * 生成房间页面URL
 */
function room_url($area, $roomId) {
    return 'room.php?area=' . urlencode($area) . '&room=' . urlencode($roomId);
}

/**
 * 获取玩家的显示名称（考虑变化状态）
 */
function get_char_display_name($player) {
    if (!is_array($player)) {
        return '';
    }
    $charId = $player['id'] ?? 0;
    // 优先从数据库获取变化状态
    if ($charId) {
        $transformState = get_transform_state_from_db($charId);
        if ($transformState && isset($transformState['target_name'])) {
            return $transformState['target_name'];
        }
    }
    // 尝试从 session 获取
    if ($charId && isset($_SESSION['transform_' . $charId])) {
        $data = $_SESSION['transform_' . $charId];
        if (isset($data['target_name'])) {
            return $data['target_name'];
        }
    }
    return $player['name'] ?? '';
}

/**
 * 保存变化状态（存储到session和数据库）
 */
function save_transform_state($charId, $data) {
    if ($data === null) {
        // 清除变化状态
        unset($_SESSION['transform_state_' . $charId]);
        Database::execute('DELETE FROM character_transforms WHERE character_id = ?', [$charId]);
    } else {
        $_SESSION['transform_state_' . $charId] = $data;
        $targetData = json_encode($data['target_data'] ?? []);
        Database::execute(
            'INSERT INTO character_transforms (character_id, target_id, target_type, target_name, target_data, start_time, d_mana, original_name) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?) 
             ON DUPLICATE KEY UPDATE target_id = VALUES(target_id), target_type = VALUES(target_type), target_name = VALUES(target_name), 
                                    target_data = VALUES(target_data), start_time = VALUES(start_time), d_mana = VALUES(d_mana), 
                                    original_name = VALUES(original_name), updated_at = CURRENT_TIMESTAMP',
            [$charId, $data['target_id'], $data['target_type'], $data['target_name'], $targetData, $data['start_time'], $data['d_mana'], $data['original_name']]
        );
    }
}

/**
 * 检查玩家是否处于忙碌状态
 * 
 * 还原原始LPC: me->is_busy()
 * 统一使用 $_SESSION["busy_{$charId}"] 作为忙碌状态标记。
 * 忙碌期间只能执行聊天、查看等非操作类命令。
 * 
 * @param int $charId 角色ID
 * @return bool true=正在忙碌
 */
function is_player_busy(int $charId): bool {
    return isset($_SESSION["busy_{$charId}"]) && $_SESSION["busy_{$charId}"] > time();
}

/**
 * 清除修炼DB标记
 */
function clearTrainingState(int $charId): void {
    Database::execute(
        "UPDATE characters SET training_state = NULL, training_end_time = 0 WHERE id = ?",
        [$charId]
    );
}

/**
 * 设置玩家忙碌状态
 * 
 * @param int $charId 角色ID
 * @param int $seconds 忙碌持续秒数，0=清除忙碌状态
 */
function set_player_busy(int $charId, int $seconds): void {
    if ($seconds <= 0) {
        unset($_SESSION["busy_{$charId}"]);
    } else {
        $_SESSION["busy_{$charId}"] = time() + $seconds;
    }
}

/**
 * 从存储中获取变化状态（优先从session，其次从数据库）
 */
function get_transform_state_from_db($charId) {
    // 优先从专用 session key 获取
    if (isset($_SESSION['transform_state_' . $charId])) {
        return $_SESSION['transform_state_' . $charId];
    }
    // 兼容旧的 session key
    if (isset($_SESSION['transform_' . $charId])) {
        return $_SESSION['transform_' . $charId];
    }
    // 从数据库获取（其他玩家查看时）
    $row = Database::queryOne('SELECT * FROM character_transforms WHERE character_id = ?', [$charId]);
    if ($row) {
        return [
            'target_id' => $row['target_id'],
            'target_type' => $row['target_type'],
            'target_name' => $row['target_name'],
            'target_data' => json_decode($row['target_data'], true),
            'start_time' => $row['start_time'],
            'd_mana' => $row['d_mana'],
            'original_name' => $row['original_name']
        ];
    }
    return null;
}

