<?php
// 如果是从 ActionRouter 命令系统加载（游戏内 admin 命令），跳过 Web 面板初始化
// ActionRouter::handleAdmin() 会定义 _ADMIN_CMD_MODE 常量
if (defined('_ADMIN_CMD_MODE') && _ADMIN_CMD_MODE === true) {
    // 仅加载命令函数所需的依赖
    require_once __DIR__ . '/../config/game.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once MODEL_PATH . 'User.php';
    require_once MODEL_PATH . 'Character.php';
    require_once __DIR__ . '/../helpers/WizardHelper.php';
    require_once __DIR__ . '/../helpers/BanHelper.php';
    return; // 跳过 Web 面板初始化，直接返回让 ActionRouter 调用 cmd_admin()
}

// 防护 PHP 启动通知泄漏到输出（如 PHP 8.5 的 "file created in the system's temporary directory"）
// 真正的修复在 .user.ini: display_startup_errors=Off + output_buffering=4096
// 此处 ob_start() 作为兜底保护，配合 JSON 块内的 ob_end_clean() 丢弃杂余输出
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

$contentType = trim(strtolower($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
$isJsonRequest = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && 
                 (strpos($contentType, 'application/json') !== false || 
                  strpos($contentType, 'application/x-json') !== false);

if ($isJsonRequest) {
    header('Content-Type: application/json; charset=utf-8');
    session_save_path(__DIR__ . '/../sessions');
    @session_start();
    
    require_once __DIR__ . '/../config/game.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once MODEL_PATH . 'User.php';
    require_once __DIR__ . '/../helpers/WizardHelper.php';
    // 丢弃启动阶段和 include 过程中产生的所有输出（清理所有缓冲层，防止 BOM 头或 PHP 启动通知污染 JSON）
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    $userId = $_SESSION['user_id'] ?? 0;
    $user = $userId ? UserModel::find($userId) : null;
    
    if (!$user || !WizardHelper::isWizard($userId)) {
        echo json_encode(['success' => false, 'message' => '权限不足']);
        exit;
    }
    
    try {
        $jsonData = file_get_contents('php://input');
        $requestData = json_decode($jsonData, true);
        if ($requestData === null && json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['success' => false, 'message' => 'JSON解析错误']);
            exit;
        }
        $requestData = $requestData ?? [];
        $action = $requestData['action'] ?? '';
        
        /*
        // [暂时注释] 导入玩家数据功能 — 待 PHP 配置问题修复后可重新启用
        if ($action === 'import_player_data') {
            if ($user['wizard_level'] < WizardHelper::LEVEL_ARCH) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要大巫师(arch)以上权限']);
                exit;
            }
            
            $data = $requestData;
            if (!$data || !isset($data['users']) || !isset($data['characters'])) {
                echo json_encode(['success' => false, 'message' => '无效的数据格式']);
                exit;
            }
            
            $successCount = 0;
            $failCount = 0;
            $errors = [];
            
            $users = is_array($data['users']) ? $data['users'] : [];
            foreach ($users as $userData) {
                try {
                    Database::execute(
                        "REPLACE INTO users (id, username, password, phone, status, vip_level, register_time, last_login, last_ip, wizard_level)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $userData['id'] ?? 0,
                            $userData['username'] ?? '',
                            $userData['password'] ?? '',
                            $userData['phone'] ?? '',
                            $userData['status'] ?? 1,
                            $userData['vip_level'] ?? 0,
                            $userData['register_time'] ?? date('Y-m-d H:i:s'),
                            $userData['last_login'] ?? null,
                            $userData['last_ip'] ?? '',
                            $userData['wizard_level'] ?? 0
                        ]
                    );
                    $successCount++;
                } catch (Throwable $e) {
                    $failCount++;
                    $errors[] = '用户[' . ($userData['username'] ?? $userData['id'] ?? '?') . ']: ' . $e->getMessage();
                }
            }
            
            $characters = is_array($data['characters']) ? $data['characters'] : [];
            foreach ($characters as $charData) {
                try {
                    $fields = [];
                    $values = [];
                    $allowedFields = [
                        'id', 'profession', 'obstacle_qujing', 'user_id', 'name', 'race', 'family_name', 'gender', 'level',
                        'max_gin', 'gin', 'max_kee', 'kee', 'max_sen', 'sen', 'str', 'int', 'con', 'dex', 'cor', 'cps',
                        'per', 'spi', 'kar', 'force_factor', 'bellicosity', 'last_fainted_from', 'donation', 'gift_modify',
                        'combat_exp', 'potential', 'learned_points', 'daoxing', 'deliver_food_time', 'qianziwen_time',
                        'daodejing_time', 'last_salary_time', 'experience', 'force', 'max_force', 'maximum_force',
                        'atman', 'max_atman', 'mana', 'max_mana', 'maximum_mana', 'skills_data', 'current_area',
                        'current_room', 'following_id', 'gold', 'silver', 'copper', 'online', 'last_save', 'balance',
                        'family', 'master_id', 'master_name', 'generation', 'family_enter_time', 'family_privs',
                        'betrayal_count', 'age', 'mud_age', 'age_modify', 'last_age_set', 'display_title',
                        'food', 'max_food', 'water', 'max_water', 'last_sleep', 'sleep_state', 'sleep_end_time',
                        'eff_sen', 'eff_kee', 'couple_id', 'couple_name', 'created_at', 'updated_at'
                    ];
                    
                    foreach ($allowedFields as $field) {
                        if (isset($charData[$field])) {
                            $fields[] = $field;
                            $values[] = $charData[$field];
                        }
                    }
                    
                    if (!empty($fields)) {
                        $placeholders = implode(',', array_fill(0, count($fields), '?'));
                        Database::execute(
                            "REPLACE INTO characters (" . implode(',', $fields) . ") VALUES (" . $placeholders . ")",
                            $values
                        );
                        $successCount++;
                    }
                } catch (Throwable $e) {
                    $failCount++;
                    $errors[] = '角色[' . ($charData['name'] ?? $charData['id'] ?? '?') . ']: ' . $e->getMessage();
                }
            }
            
            if (isset($data['character_tables']) && is_array($data['character_tables'])) {
                foreach ($data['character_tables'] as $tableName => $tableData) {
                    if (strpos($tableName, 'character_') !== 0) {
                        continue;
                    }
                    
                    foreach ($tableData as $row) {
                        try {
                            if (!empty($row) && is_array($row)) {
                                $fields = array_keys($row);
                                $values = array_values($row);
                                $fieldsStr = '`' . implode('`, `', $fields) . '`';
                                $placeholders = implode(',', array_fill(0, count($fields), '?'));
                                Database::execute(
                                    "REPLACE INTO `{$tableName}` ({$fieldsStr}) VALUES (" . $placeholders . ")",
                                    $values
                                );
                                $successCount++;
                            }
                        } catch (Throwable $e) {
                            $failCount++;
                            $errors[] = $tableName . ': ' . $e->getMessage();
                        }
                    }
                }
            }
            
            $msg = "导入完成！成功: {$successCount} 条, 失败: {$failCount} 条";
            if ($failCount > 0 && !empty($errors)) {
                $shownErrors = array_slice($errors, 0, 5);
                $msg .= "\n错误详情: " . implode('; ', $shownErrors);
                if (count($errors) > 5) {
                    $msg .= '... 等共' . count($errors) . '个错误';
                }
            }
            
            echo json_encode([
                'success' => $failCount === 0,
                'message' => $msg
            ]);
        } else {
        */
        // AI 玩家管理（JSON 请求分支）
        if (str_starts_with($action, 'ai_player_')) {
            if ($user['wizard_level'] < WizardHelper::LEVEL_ADMIN) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要管理员(admin)权限']);
                exit;
            }
            require_once __DIR__ . '/../helpers/AiPlayerHelper.php';
            require_once __DIR__ . '/../daemons/AiPlayerDaemon.php';
            switch ($action) {
                case 'ai_player_status':
                    $allAi = Database::queryAll(
                        "SELECT c.id, c.name, c.race, c.gender, c.current_area, c.current_room, 
                                c.online, c.kee, c.max_kee, c.is_ai_player, c.ai_last_action, c.ai_paused
                         FROM characters c 
                         WHERE c.is_ai_player = 1 
                         ORDER BY c.online DESC, c.id ASC"
                    );
                    $now = time();
                    foreach ($allAi as &$a) {
                        $a['online'] = intval($a['online'] ?? 0);
                        $a['is_ai_player'] = intval($a['is_ai_player'] ?? 0);
                        $a['ai_paused'] = intval($a['ai_paused'] ?? 0);
                        $lastAction = intval($a['ai_last_action'] ?? 0);
                        $a['seconds_ago'] = $lastAction > 0 ? ($now - $lastAction) : -1;
                        $a['ai_last_action'] = $lastAction;
                    }
                    echo json_encode(['success' => true, 'ai_players' => $allAi, 'count' => count($allAi)]);
                    break;
                case 'ai_player_login':
                    $charId = intval($requestData['char_id'] ?? 0);
                    if ($charId <= 0) { echo json_encode(['success' => false, 'message' => '无效的角色ID']); break; }
                    $success = AiPlayerHelper::loginAiPlayer($charId);
                    echo json_encode(['success' => $success, 'message' => $success ? 'AI 玩家已上线' : '上线失败']);
                    break;
                case 'ai_player_logout':
                    $charId = intval($requestData['char_id'] ?? 0);
                    if ($charId <= 0) { echo json_encode(['success' => false, 'message' => '无效的角色ID']); break; }
                    $success = AiPlayerHelper::logoutAiPlayer($charId);
                    echo json_encode(['success' => $success, 'message' => $success ? 'AI 玩家已下线' : '下线失败']);
                    break;
                case 'ai_player_mark':
                    $charId = intval($requestData['char_id'] ?? 0);
                    if ($charId <= 0) { echo json_encode(['success' => false, 'message' => '无效的角色ID']); break; }
                    $char = Database::queryOne("SELECT name FROM characters WHERE id = ?", [$charId]);
                    if (!$char) { echo json_encode(['success' => false, 'message' => '角色不存在']); break; }
                    $success = AiPlayerHelper::markAsAiPlayer($charId);
                    echo json_encode(['success' => $success, 'message' => $success ? "已标记「{$char['name']}」为 AI 玩家" : '标记失败']);
                    break;
                case 'ai_player_unmark':
                    $charId = intval($requestData['char_id'] ?? 0);
                    if ($charId <= 0) { echo json_encode(['success' => false, 'message' => '无效的角色ID']); break; }
                    $char = Database::queryOne("SELECT name FROM characters WHERE id = ?", [$charId]);
                    $name = $char['name'] ?? "ID:{$charId}";
                    $success = AiPlayerHelper::unmarkAiPlayer($charId);
                    echo json_encode(['success' => $success, 'message' => $success ? "已取消「{$name}」的 AI 玩家标记" : '取消失败']);
                    break;
                case 'ai_player_create':
                    $name = trim($requestData['name'] ?? '');
                    $gender = $requestData['gender'] ?? 'male';
                    $race = $requestData['race'] ?? 'human';
                    if (empty($name)) { echo json_encode(['success' => false, 'message' => '请输入角色名']); break; }
                    $result = AiPlayerDaemon::createAndLogin($name, $gender, $race);
                    echo json_encode($result);
                    break;
                case 'ai_player_tick':
                    $result = AiPlayerDaemon::runTick(10);
                    echo json_encode(['success' => true, 'result' => $result]);
                    break;
                case 'ai_player_pause':
                    $charId = intval($requestData['char_id'] ?? 0);
                    if ($charId <= 0) { echo json_encode(['success' => false, 'message' => '无效的角色ID']); break; }
                    $success = AiPlayerHelper::pauseAiPlayer($charId);
                    echo json_encode(['success' => $success, 'message' => $success ? 'AI 玩家已暂停' : '暂停失败']);
                    break;
                case 'ai_player_resume':
                    $charId = intval($requestData['char_id'] ?? 0);
                    if ($charId <= 0) { echo json_encode(['success' => false, 'message' => '无效的角色ID']); break; }
                    $success = AiPlayerHelper::resumeAiPlayer($charId);
                    echo json_encode(['success' => $success, 'message' => $success ? 'AI 玩家已恢复' : '恢复失败']);
                    break;
                case 'ai_player_pause_all':
                    $count = AiPlayerHelper::pauseAllAiPlayers();
                    echo json_encode(['success' => true, 'message' => "已暂停全部 {$count} 个 AI 玩家"]);
                    break;
                case 'ai_player_resume_all':
                    $count = AiPlayerHelper::resumeAllAiPlayers();
                    echo json_encode(['success' => true, 'message' => "已恢复全部 {$count} 个 AI 玩家"]);
                    break;
                case 'ai_player_pause_status':
                    $status = AiPlayerHelper::getAiPauseStatus();
                    echo json_encode(['success' => true, 'status' => $status]);
                    break;
                case 'ai_player_auto_tick_state':
                    $stateFile = __DIR__ . '/../data/ai_auto_tick_state.json';
                    if (isset($requestData['running'])) {
                        $running = (bool)$requestData['running'];
                        file_put_contents($stateFile, json_encode(['running' => $running]));
                        echo json_encode(['success' => true, 'running' => $running]);
                    } else {
                        $running = false;
                        if (file_exists($stateFile)) {
                            $data = json_decode(file_get_contents($stateFile), true);
                            $running = $data['running'] ?? false;
                        }
                        echo json_encode(['success' => true, 'running' => $running]);
                    }
                    break;
                default:
                    echo json_encode(['success' => false, 'message' => '未知操作']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => '未知操作']);
        //}
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => '服务器错误: ' . $e->getMessage()]);
    }
    exit;
}

// 非 JSON 请求：清理顶部所有输出缓冲层，正常渲染 HTML 页面
while (ob_get_level() > 0) {
    ob_end_clean();
}

/**
 * 管理员页面
 */

ob_start();

// 调试模式
if (isset($_GET['debug'])) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

session_save_path(__DIR__ . '/../sessions');
if (!session_start()) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '会话初始化失败']);
    exit;
}

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once MODEL_PATH . 'User.php';
require_once MODEL_PATH . 'Npc.php';
require_once __DIR__ . '/../helpers/BanHelper.php';
require_once __DIR__ . '/../helpers/WizardHelper.php';
require_once __DIR__ . '/../daemons/CommandDaemon.php';

// 检查是否是JSON请求
$contentType = trim(strtolower($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
$isJsonRequest = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && 
                 (strpos($contentType, 'application/json') !== false || 
                  strpos($contentType, 'application/x-json') !== false);

// 提前解析请求参数，用于权限检查时判断是否为AJAX请求
$action = '';
$requestData = [];

if ($isJsonRequest) {
    $jsonData = file_get_contents('php://input');
    $requestData = json_decode($jsonData, true);
    if ($requestData === null && json_last_error() !== JSON_ERROR_NONE) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'JSON解析错误: ' . json_last_error_msg()]);
        exit;
    }
    $requestData = $requestData ?? [];
    $action = $requestData['action'] ?? '';
} else {
    $requestData = $_POST;
    $action = $requestData['action'] ?? '';
}

// 权限检查
if (!isset($_SESSION['user_id'])) {
    if ($isJsonRequest || !empty($action)) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '请先登录']);
        exit;
    }
    redirect('../index.php');
}

$userId = $_SESSION['user_id'];
$user = UserModel::find($userId);
if (!$user) {
    if ($isJsonRequest || !empty($action)) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '用户不存在']);
        exit;
    }
    session_destroy();
    redirect('../index.php?error=notfound');
}
if (!WizardHelper::isWizard($userId)) {
    // AJAX请求也返回JSON格式的权限不足错误
    if ($isJsonRequest || !empty($action)) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '权限不足！只有巫师(immortal)以上才能执行此操作']);
        exit;
    }
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>权限不足</title></head><body>';
    echo '<h1>权限不足</h1><p>只有巫师(immortal)以上才能访问此页面。</p>';
    echo '<a href="room.php">返回游戏</a>';
    echo '</body></html>';
    exit;
}

$charId = $_SESSION['char_id'] ?? 0;
$char = CharacterModel::find($charId);
$message = '';

// 处理AJAX请求（请求参数已在权限检查前解析）

if (!empty($action)) {
    // 对于AJAX请求（非HTML页面），清理所有输出缓冲
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    $isExportAction = ($action === 'export_player_data');
    if (!$isExportAction) {
        header('Content-Type: application/json; charset=utf-8');
    }
    
    switch ($action) {
        case 'ban_user':
            $username = $requestData['username'] ?? '';
            $targetUser = UserModel::findByUsername($username);
            if (!$targetUser) {
                echo json_encode(['success' => false, 'message' => '用户不存在']);
            } elseif (BanHelper::banUser($targetUser['id'])) {
                echo json_encode(['success' => true, 'message' => "已封禁用户: {$username}"]);
            } else {
                echo json_encode(['success' => false, 'message' => '封禁失败']);
            }
            exit;
            
        case 'unban_user':
            $username = $requestData['username'] ?? '';
            $targetUser = UserModel::findByUsername($username);
            if (!$targetUser) {
                echo json_encode(['success' => false, 'message' => '用户不存在']);
            } elseif (BanHelper::unbanUser($targetUser['id'])) {
                $extra = ($targetUser['status'] == BanHelper::STATUS_PRISONED) ? '（角色已迁回南城客栈）' : '';
                echo json_encode(['success' => true, 'message' => "已解封用户: {$username}{$extra}"]);
            } else {
                echo json_encode(['success' => false, 'message' => '解封失败']);
            }
            exit;
            
        case 'imprison_user':
            $username = $requestData['username'] ?? '';
            $targetUser = UserModel::findByUsername($username);
            if ($targetUser && BanHelper::imprisonUser($targetUser['id'])) {
                echo json_encode(['success' => true, 'message' => "已监禁用户: {$username}"]);
            } else {
                echo json_encode(['success' => false, 'message' => '监禁失败']);
            }
            exit;
            
        case 'release_user':
            $username = $requestData['username'] ?? '';
            $targetUser = UserModel::findByUsername($username);
            if (!$targetUser) {
                echo json_encode(['success' => false, 'message' => '用户不存在']);
            } elseif (BanHelper::releaseUser($targetUser['id'])) {
                echo json_encode(['success' => true, 'message' => "已释放用户: {$username}（角色已迁回南城客栈）"]);
            } else {
                echo json_encode(['success' => false, 'message' => '释放失败']);
            }
            exit;
            
        case 'ban_ip':
            $ipPattern = $requestData['ip_pattern'] ?? '';
            $reason = $requestData['reason'] ?? '违规操作';
            if (BanHelper::banIp($ipPattern, $reason, 1, $user['username'])) {
                echo json_encode(['success' => true, 'message' => "已封禁IP: {$ipPattern}"]);
            } else {
                echo json_encode(['success' => false, 'message' => '封禁失败']);
            }
            exit;
            
        case 'unban_ip':
            $ipPattern = $requestData['ip_pattern'] ?? '';
            if (BanHelper::unbanIp($ipPattern)) {
                echo json_encode(['success' => true, 'message' => "已解封IP: {$ipPattern}"]);
            } else {
                echo json_encode(['success' => false, 'message' => '解封失败']);
            }
            exit;
            
        case 'set_wizard_level':
            $username = $requestData['username'] ?? '';
            $level = intval($requestData['level'] ?? 0);
            $targetUser = UserModel::findByUsername($username);
            
            if (!$targetUser) {
                echo json_encode(['success' => false, 'message' => '用户不存在']);
                exit;
            }
            
            // 使用WizardHelper设置巫师等级
            if (WizardHelper::setWizardLevel($targetUser['id'], $level, $userId)) {
                echo json_encode(['success' => true, 'message' => "已设置 {$username} 巫师等级为: " . WizardHelper::getLevelName($level)]);
            } else {
                echo json_encode(['success' => false, 'message' => '设置失败，权限不足或等级无效']);
            }
            exit;
            
        case 'toguest':
            $username = $requestData['username'] ?? '';
            $days = intval($requestData['days'] ?? 2);
            $reason = $requestData['reason'] ?? '等待审核';
            $result = CommandDaemon::execute($charId, 'toguest', "{$username} {$days} {$reason}");
            echo json_encode($result);
            exit;
            
        case 'approve_guest':
            $username = $requestData['username'] ?? '';
            $result = CommandDaemon::execute($charId, 'toguest', "approve {$username}");
            echo json_encode($result);
            exit;
            
        case 'court_arrest':
            $username = $requestData['username'] ?? '';
            $reason = $requestData['reason'] ?? '';
            $result = CommandDaemon::execute($charId, 'court', "arrest {$username} {$reason}");
            echo json_encode($result);
            exit;
            
        case 'court_try':
            $suspectId = intval($requestData['suspect_id'] ?? 0);
            $result = CommandDaemon::execute($charId, 'court', "try {$suspectId}");
            echo json_encode($result);
            exit;
            
        case 'court_release':
            $suspectId = intval($requestData['suspect_id'] ?? 0);
            $result = CommandDaemon::execute($charId, 'court', "release {$suspectId}");
            echo json_encode($result);
            exit;
            
        case 'court_verdict':
            $caseId = intval($requestData['case_id'] ?? 0);
            $verdict = intval($requestData['verdict'] ?? 0);
            $days = intval($requestData['days'] ?? 0);
            $notes = $requestData['notes'] ?? '';
            $result = CommandDaemon::execute($charId, 'court', "verdict {$caseId} {$verdict} {$days} {$notes}");
            echo json_encode($result);
            exit;
            
        // === 新增命令 AJAX handlers ===
        
        case 'tojail':
            $username = $requestData['username'] ?? '';
            $reason = $requestData['reason'] ?? '';
            $result = CommandDaemon::execute($charId, 'tojail', "{$username} {$reason}");
            echo json_encode($result);
            exit;
            
        case 'where':
            $query = $requestData['query'] ?? '';
            $result = CommandDaemon::execute($charId, 'where', $query);
            echo json_encode($result);
            exit;
            
        case 'whois':
            $query = $requestData['query'] ?? '';
            $result = CommandDaemon::execute($charId, 'whois', $query);
            echo json_encode($result);
            exit;
            
        case 'sameip':
            $query = $requestData['query'] ?? '';
            $result = CommandDaemon::execute($charId, 'sameip', $query);
            echo json_encode($result);
            exit;
            
        case 'wizlock':
            $status = $requestData['status'] ?? 'status';
            if ($status === 'status') {
                require_once HELPER_PATH . 'WizardHelper.php';
                $userId = intval($_SESSION['user_id'] ?? 0);
                if (!WizardHelper::canUseCommand($userId, 'where')) {
                    echo json_encode(['success' => false, 'message' => '你没有权限执行此命令。']);
                    exit;
                }
                $shutdownRow = Database::queryOne("SELECT value FROM variables WHERE var_key = 'shutdown_status'");
                $isShutdown = $shutdownRow && $shutdownRow['value'] === 'active';
                
                $wizlockRow = Database::queryOne("SELECT value FROM variables WHERE var_key = 'wizlock_status'");
                $isWizlock = $wizlockRow && $wizlockRow['value'] === '1';
                
                if ($isShutdown) {
                    $minutes = Database::queryOne("SELECT value FROM variables WHERE var_key = 'shutdown_minutes'");
                    $reason = Database::queryOne("SELECT value FROM variables WHERE var_key = 'shutdown_reason'");
                    $msg = '【服务器维护状态】';
                    $msg .= "\n状态: 维护中";
                    $msg .= "\n倒计时: " . ($minutes['value'] ?? '?') . " 分钟";
                    $msg .= "\n原因: " . ($reason['value'] ?? '未说明');
                } elseif ($isWizlock) {
                    $msg = '【服务器维护状态】';
                    $msg .= "\n状态: 维护中（wizlock模式）";
                    $msg .= "\n非巫师玩家无法登录";
                } else {
                    $msg = '【服务器维护状态】';
                    $msg .= "\n状态: 正常运行";
                    $msg .= "\n所有玩家均可正常登录";
                }
                echo json_encode(['success' => true, 'message' => $msg]);
                exit;
            }
            $result = CommandDaemon::execute($charId, 'wizlock', $status);
            echo json_encode($result);
            exit;
            
        case 'shutdown':
            $confirm = $requestData['confirm'] ?? '';
            $result = CommandDaemon::execute($charId, 'shutdown', $confirm);
            echo json_encode($result);
            exit;
            
        case 'setskill':
            $actionOverride = $requestData['action_override'] ?? '';
            if ($actionOverride === 'list') {
                $listChar = $requestData['list_char'] ?? '';
                if (!$listChar) {
                    echo json_encode(['success' => false, 'message' => '请填写角色名']);
                    exit;
                }
                $targetChar = CharacterModel::findByName($listChar);
                if (!$targetChar) {
                    $targetUser = UserModel::findByUsername($listChar);
                    if ($targetUser) {
                        $targetChar = CharacterModel::getByUserId($targetUser['id']);
                    }
                }
                if (!$targetChar) {
                    echo json_encode(['success' => false, 'message' => "找不到角色: {$listChar}"]);
                    exit;
                }
                $skills = Database::queryAll(
                    "SELECT cs.skill_id, s.name, cs.level FROM character_skills cs LEFT JOIN skills s ON cs.skill_id = s.id WHERE cs.char_id = ? ORDER BY s.name",
                    [$targetChar['id']]
                );
                $msg = "{$listChar} 的技能列表:\n";
                if (empty($skills)) {
                    $msg .= "  (无技能)";
                } else {
                    foreach ($skills as $s) {
                        $msg .= "  ID:{$s['skill_id']} {$s['name']} - 等级 {$s['level']}\n";
                    }
                }
                echo json_encode(['success' => true, 'message' => rtrim($msg)]);
                exit;
            } else {
                $charName = $requestData['char_name'] ?? '';
                $skillName = $requestData['skill_name'] ?? '';
                $level = $requestData['level'] ?? '0';
                $result = CommandDaemon::execute($charId, 'setskill', "{$charName} {$skillName} {$level}");
                echo json_encode($result);
                exit;
            }
            
        case 'clone_item':
            $charName = $requestData['char_name'] ?? '';
            $itemId = $requestData['item_id'] ?? '';
            $category = $requestData['category'] ?? '';
            $result = CommandDaemon::execute($charId, 'clone', "{$charName} {$itemId} {$category}");
            echo json_encode($result);
            exit;
            
        case 'dest_item':
            $charName = $requestData['char_name'] ?? '';
            $itemId = $requestData['item_id'] ?? '';
            $result = CommandDaemon::execute($charId, 'dest', "{$charName} {$itemId}");
            echo json_encode($result);
            exit;
            
        case 'stop_combat':
            $targetName = $requestData['target_name'] ?? '';
            if (empty($targetName)) {
                echo json_encode(['success' => false, 'message' => '请输入角色名']);
                exit;
            }
            $targetChar = Database::queryOne('SELECT id, name FROM characters WHERE LOWER(name) = LOWER(?)', [$targetName]);
            if (!$targetChar) {
                $targetUser = UserModel::findByUsername($targetName);
                if ($targetUser) {
                    $targetChar = CharacterModel::getByUserId($targetUser['id']);
                }
            }
            if (!$targetChar) {
                echo json_encode(['success' => false, 'message' => "角色不存在: {$targetName}"]);
                exit;
            }
            Database::execute("DELETE FROM active_combats WHERE char_id = ? OR target_id = ?", [$targetChar['id'], $targetChar['id']]);
            echo json_encode(['success' => true, 'message' => "已停止 {$targetChar['name']} 的所有战斗"]);
            exit;
            
        case 'clear_poison':
            $targetName = $requestData['target_name'] ?? '';
            if (empty($targetName)) {
                echo json_encode(['success' => false, 'message' => '请输入角色名']);
                exit;
            }
            $targetChar = Database::queryOne('SELECT id, name FROM characters WHERE LOWER(name) = LOWER(?)', [$targetName]);
            if (!$targetChar) {
                $targetUser = UserModel::findByUsername($targetName);
                if ($targetUser) {
                    $targetChar = CharacterModel::getByUserId($targetUser['id']);
                }
            }
            if (!$targetChar) {
                echo json_encode(['success' => false, 'message' => "角色不存在: {$targetName}"]);
                exit;
            }
            // 直接操作数据库清除毒性buff
            try {
                Database::execute("DELETE FROM character_buffs WHERE char_id = ? AND buff_type IN ('poison', 'snake_poison', 'ice_poison')", [$targetChar['id']]);
                echo json_encode(['success' => true, 'message' => "已清除 {$targetChar['name']} 的所有毒性效果"]);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'message' => "清除中毒失败: " . $e->getMessage()]);
            }
            exit;
            
        case 'clear_drunk':
            $targetName = $requestData['target_name'] ?? '';
            if (empty($targetName)) {
                echo json_encode(['success' => false, 'message' => '请输入角色名']);
                exit;
            }
            // 先查找角色
            $targetChar = Database::queryOne('SELECT id, name FROM characters WHERE LOWER(name) = LOWER(?)', [$targetName]);
            if (!$targetChar) {
                $targetUser = UserModel::findByUsername($targetName);
                if ($targetUser) {
                    $targetChar = CharacterModel::getByUserId($targetUser['id']);
                }
            }
            if (!$targetChar) {
                echo json_encode(['success' => false, 'message' => "角色不存在: {$targetName}"]);
                exit;
            }
            // 清除醉酒状态
            try {
                Database::execute("DELETE FROM character_buffs WHERE char_id = ? AND buff_type = 'drunk'", [$targetChar['id']]);
                echo json_encode(['success' => true, 'message' => "已清除 {$targetChar['name']} 的醉酒状态"]);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'message' => "清除醉酒失败: " . $e->getMessage()]);
            }
            exit;
            
        case 'add_buff':
            $targetName = $requestData['target_name'] ?? '';
            $buffType = $requestData['buff_type'] ?? '';
            $buffValue = intval($requestData['buff_value'] ?? 10);
            $buffDuration = intval($requestData['buff_duration'] ?? 10);
            if (empty($targetName)) {
                echo json_encode(['success' => false, 'message' => '请输入角色名']);
                exit;
            }
            if (empty($buffType)) {
                echo json_encode(['success' => false, 'message' => '请选择Buff类型']);
                exit;
            }
            // 先查找角色
            $targetChar = Database::queryOne('SELECT id, name FROM characters WHERE LOWER(name) = LOWER(?)', [$targetName]);
            if (!$targetChar) {
                $targetUser = UserModel::findByUsername($targetName);
                if ($targetUser) {
                    $targetChar = CharacterModel::getByUserId($targetUser['id']);
                }
            }
            if (!$targetChar) {
                echo json_encode(['success' => false, 'message' => "角色不存在: {$targetName}"]);
                exit;
            }
            // 添加Buff
            try {
                require_once __DIR__ . '/../helpers/StatusEffectHelper.php';
                StatusEffectHelper::addBuff($targetChar['id'], $buffType, $buffValue, $buffDuration, 'admin_add');
                echo json_encode(['success' => true, 'message' => "已为 {$targetChar['name']} 添加Buff: {$buffType} (值: {$buffValue}, 持续: {$buffDuration}回合)"]);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'message' => "添加Buff失败: " . $e->getMessage()]);
            }
            exit;

        case 'list_buffs':
            $targetName = $requestData['target_name'] ?? '';
            if (empty($targetName)) {
                echo json_encode(['success' => false, 'message' => '请输入角色名']);
                exit;
            }
            // 先查找角色
            $targetChar = Database::queryOne('SELECT id, name FROM characters WHERE LOWER(name) = LOWER(?)', [$targetName]);
            if (!$targetChar) {
                $targetUser = UserModel::findByUsername($targetName);
                if ($targetUser) {
                    $targetChar = CharacterModel::getByUserId($targetUser['id']);
                }
            }
            if (!$targetChar) {
                echo json_encode(['success' => false, 'message' => "角色不存在: {$targetName}"]);
                exit;
            }
            // 查看Buff列表
            try {
                require_once __DIR__ . '/../helpers/StatusEffectHelper.php';
                $buffs = StatusEffectHelper::getActiveBuffs($targetChar['id']);
                $buffDescs = [];
                foreach ($buffs as $buff) {
                    $buffDescs[] = StatusEffectHelper::getBuffDescription($buff);
                }
                if (empty($buffDescs)) {
                    echo json_encode(['success' => true, 'message' => "{$targetChar['name']} 当前没有任何Buff", 'buffs' => []]);
                } else {
                    echo json_encode(['success' => true, 'message' => "{$targetChar['name']} 的Buff列表: " . implode('、', $buffDescs), 'buffs' => $buffs]);
                }
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'message' => "查看Buff失败: " . $e->getMessage()]);
            }
            exit;

        case 'remove_buff':
            $targetName = $requestData['target_name'] ?? '';
            $buffType = $requestData['buff_type'] ?? '';
            if (empty($targetName)) {
                echo json_encode(['success' => false, 'message' => '请输入角色名']);
                exit;
            }
            if (empty($buffType)) {
                echo json_encode(['success' => false, 'message' => '请选择Buff类型']);
                exit;
            }
            // 先查找角色
            $targetChar = Database::queryOne('SELECT id, name FROM characters WHERE LOWER(name) = LOWER(?)', [$targetName]);
            if (!$targetChar) {
                $targetUser = UserModel::findByUsername($targetName);
                if ($targetUser) {
                    $targetChar = CharacterModel::getByUserId($targetUser['id']);
                }
            }
            if (!$targetChar) {
                echo json_encode(['success' => false, 'message' => "角色不存在: {$targetName}"]);
                exit;
            }
            // 移除指定Buff
            try {
                require_once __DIR__ . '/../helpers/StatusEffectHelper.php';
                StatusEffectHelper::removeBuff($targetChar['id'], $buffType);
                echo json_encode(['success' => true, 'message' => "已清除 {$targetChar['name']} 的 {$buffType} 状态"]);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'message' => "消除Buff失败: " . $e->getMessage()]);
            }
            exit;

        case 'clear_all_buffs':
            $targetName = $requestData['target_name'] ?? '';
            if (empty($targetName)) {
                echo json_encode(['success' => false, 'message' => '请输入角色名']);
                exit;
            }
            // 先查找角色
            $targetChar = Database::queryOne('SELECT id, name FROM characters WHERE LOWER(name) = LOWER(?)', [$targetName]);
            if (!$targetChar) {
                $targetUser = UserModel::findByUsername($targetName);
                if ($targetUser) {
                    $targetChar = CharacterModel::getByUserId($targetUser['id']);
                }
            }
            if (!$targetChar) {
                echo json_encode(['success' => false, 'message' => "角色不存在: {$targetName}"]);
                exit;
            }
            // 清除所有Buff
            try {
                require_once __DIR__ . '/../helpers/StatusEffectHelper.php';
                StatusEffectHelper::clearAllBuffs($targetChar['id']);
                echo json_encode(['success' => true, 'message' => "已清除 {$targetChar['name']} 的所有Buff状态"]);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'message' => "清除全部Buff失败: " . $e->getMessage()]);
            }
            exit;
            
        case 'delete_player':
            $targetName = $requestData['target_name'] ?? '';
            if (empty($targetName)) {
                echo json_encode(['success' => false, 'message' => '请输入角色名或用户名']);
                exit;
            }
            
            // 检查权限：需要管理员级别
            require_once __DIR__ . '/../helpers/WizardHelper.php';
            if (!WizardHelper::isAdmin($userId)) {
                echo json_encode(['success' => false, 'message' => '权限不足，需要管理员(admin)权限']);
                exit;
            }
            
            // 先查找角色
            $targetChar = Database::queryOne('SELECT id, name, user_id FROM characters WHERE LOWER(name) = LOWER(?)', [$targetName]);
            if (!$targetChar) {
                $targetUser = UserModel::findByUsername($targetName);
                if ($targetUser) {
                    $targetChar = CharacterModel::getByUserId($targetUser['id']);
                }
            }
            if (!$targetChar) {
                echo json_encode(['success' => false, 'message' => "玩家不存在: {$targetName}"]);
                exit;
            }
            
            $charId = $targetChar['id'];
            $charName = $targetChar['name'];
            $targetUserId = $targetChar['user_id'];
            
            // 删除玩家所有数据
            try {
                Database::beginTransaction();
                
                // ========== 角色相关数据（char_id） ==========
                
                // 删除角色buff
                Database::execute("DELETE FROM character_buffs WHERE char_id = ?", [$charId]);
                
                // 删除角色临时状态
                Database::execute("DELETE FROM character_temp_states WHERE char_id = ?", [$charId]);
                
                // 删除角色临时数据
                Database::execute("DELETE FROM character_temp WHERE char_id = ?", [$charId]);
                
                // 删除角色背包
                Database::execute("DELETE FROM character_inventory WHERE char_id = ?", [$charId]);
                
                // 删除角色技能
                Database::execute("DELETE FROM character_skills WHERE char_id = ?", [$charId]);
                
                // 删除角色技能映射
                Database::execute("DELETE FROM character_skill_map WHERE char_id = ?", [$charId]);
                
                // 删除角色任务
                Database::execute("DELETE FROM character_quests WHERE char_id = ?", [$charId]);
                
                // 删除角色法宝
                Database::execute("DELETE FROM character_fabao WHERE owner_id = ?", [$charId]);
                
                // 删除角色队伍
                Database::execute("DELETE FROM character_teams WHERE leader_id = ? OR member_id = ?", [$charId, $charId]);
                
                // 删除充值记录
                Database::execute("DELETE FROM recharge_logs WHERE char_id = ?", [$charId]);
                
                // 删除兑换记录
                Database::execute("DELETE FROM redeem_logs WHERE char_id = ?", [$charId]);
                
                // 删除战斗数据
                Database::execute("DELETE FROM active_combats WHERE char_id = ? OR target_id = ?", [$charId, $charId]);
                
                // 删除战斗记录
                Database::execute("DELETE FROM combat_records WHERE challenger_id = ? OR defender_id = ?", [$charId, $charId]);
                
                // 删除银行存款
                Database::execute("DELETE FROM bank_deposits WHERE char_id = ?", [$charId]);
                
                // 删除银行交易记录
                Database::execute("DELETE FROM bank_transactions WHERE char_id = ?", [$charId]);
                
                // 删除门派成员记录
                Database::execute("DELETE FROM sect_members WHERE character_id = ?", [$charId]);
                
                // 删除好友关系（双向）
                Database::execute("DELETE FROM friends WHERE from_character_id = ? OR to_character_id = ?", [$charId, $charId]);
                
                // 删除消息（发送和接收的）
                Database::execute("DELETE FROM messages WHERE from_char_id = ? OR to_char_id = ?", [$charId, $charId]);
                
                // 删除消息队列
                Database::execute("DELETE FROM message_queue WHERE char_id = ?", [$charId]);
                
                // 删除玩家家园（先删家园物品，再删家园）
                $home = Database::queryOne("SELECT id FROM player_homes WHERE owner_id = ?", [$charId]);
                if ($home) {
                    Database::execute("DELETE FROM home_items WHERE home_id = ?", [$home['id']]);
                    Database::execute("DELETE FROM home_guests WHERE home_id = ?", [$home['id']]);
                    Database::execute("DELETE FROM home_babies WHERE home_id = ?", [$home['id']]);
                    Database::execute("DELETE FROM player_homes WHERE id = ?", [$home['id']]);
                }
                
                // 删除交易记录
                Database::execute("DELETE FROM trades WHERE char_id = ?", [$charId]);
                
                // 删除拜师请求
                Database::execute("DELETE FROM apprentice_requests WHERE from_character_id = ? OR to_character_id = ?", [$charId, $charId]);
                
                // 删除结婚请求
                Database::execute("DELETE FROM marry_requests WHERE proposer_id = ? OR target_id = ?", [$charId, $charId]);
                
                // 删除公堂相关
                Database::execute("DELETE FROM court_suspects WHERE char_id = ?", [$charId]);
                Database::execute("DELETE FROM court_cases WHERE defendant_id = ? OR judge_id = ?", [$targetUserId, $targetUserId]);
                
                // 删除赌大小历史
                Database::execute("DELETE FROM dudaxiao_history WHERE char_id = ?", [$charId]);
                
                // 删除取经相关
                Database::execute("DELETE FROM qujing_applicants WHERE char_id = ?", [$charId]);
                Database::execute("DELETE FROM qujing_failures WHERE char_id = ?", [$charId]);
                Database::execute("DELETE FROM qujing_history WHERE char_id = ?", [$charId]);
                
                // 删除睡眠邀请
                Database::execute("DELETE FROM sleep_invitations WHERE from_char_id = ? OR to_char_id = ?", [$charId, $charId]);
                
                // 删除天魔剑借用
                Database::execute("DELETE FROM tianmojian_borrows WHERE char_id = ?", [$charId]);
                
                // 删除书籍阅读记录
                Database::execute("DELETE FROM book_read_count WHERE char_id = ?", [$charId]);
                
                // ========== 用户相关数据（user_id） ==========
                
                // 检查该用户有多少个角色
                $charCount = Database::queryOne("SELECT COUNT(*) as cnt FROM characters WHERE user_id = ?", [$targetUserId]);
                $hasOnlyOneChar = ($charCount && $charCount['cnt'] <= 1);
                
                if ($hasOnlyOneChar) {
                    // 如果用户只有这一个角色，删除用户相关数据
                    
                    // 删除用户封禁记录
                    Database::execute("DELETE FROM user_blocks WHERE user_id = ? OR blocked_by = ?", [$targetUserId, $targetUserId]);
                    
                    // 删除用户
                    Database::execute("DELETE FROM users WHERE id = ?", [$targetUserId]);
                }
                
                // ========== 最后删除角色 ==========
                Database::execute("DELETE FROM characters WHERE id = ?", [$charId]);
                
                Database::commit();
                
                $extraMsg = $hasOnlyOneChar ? "（含用户账号）" : "";
                echo json_encode(['success' => true, 'message' => "已成功删除玩家「{$charName}」及其所有数据{$extraMsg}"]);
            } catch (Throwable $e) {
                Database::rollBack();
                echo json_encode(['success' => false, 'message' => "删除玩家失败: " . $e->getMessage()]);
            }
            exit;
            
        case 'snoop':
            $targetName = $requestData['target'] ?? '';
            $limit = intval($requestData['limit'] ?? 50);
            $result = CommandDaemon::execute($charId, 'snoop', "{$targetName} {$limit}");
            echo json_encode($result);
            exit;
            
        case 'tail_log':
            $logType = $requestData['log_type'] ?? '';
            $lines = intval($requestData['lines'] ?? 50);
            $result = CommandDaemon::execute($charId, 'tail', "{$logType} {$lines}");
            echo json_encode($result);
            exit;
            
        case 'block':
            $username = $requestData['username'] ?? '';
            $feature = $requestData['feature'] ?? '';
            $reason = $requestData['reason'] ?? '';
            $subAction = $requestData['sub_action'] ?? 'block';
            if ($subAction === 'list') {
                $result = CommandDaemon::execute($charId, 'block', "list {$username}");
            } elseif ($subAction === 'unblock') {
                $result = CommandDaemon::execute($charId, 'block', "unblock {$username} {$feature}");
            } else {
                $result = CommandDaemon::execute($charId, 'block', "{$username} {$feature} {$reason}");
            }
            echo json_encode($result);
            exit;
            
        case 'smash':
            $invId = $requestData['inv_id'] ?? '';
            $isRoom = $requestData['is_room'] ?? '0';
            if ($isRoom === '1') {
                $result = CommandDaemon::execute($charId, 'smash', "room {$invId}");
            } else {
                $result = CommandDaemon::execute($charId, 'smash', $invId);
            }
            echo json_encode($result);
            exit;
            
        case 'goto_cmd':
            $target = $requestData['target'] ?? '';
            $result = CommandDaemon::execute($charId, 'goto', $target);
            echo json_encode($result);
            exit;
            
        case 'summon':
            $targetName = $requestData['target'] ?? '';
            $result = CommandDaemon::execute($charId, 'summon', $targetName);
            echo json_encode($result);
            exit;
            
        case 'list_player_items':
            require_once MODEL_PATH . 'Item.php';
            $targetName = $requestData['target_name'] ?? '';
            if (empty($targetName)) {
                echo json_encode(['success' => false, 'message' => '请输入角色名']);
                exit;
            }
            $targetChar = Database::queryOne('SELECT id, name FROM characters WHERE LOWER(name) = LOWER(?)', [$targetName]);
            if (!$targetChar) {
                $targetUser = UserModel::findByUsername($targetName);
                if ($targetUser) {
                    $targetChar = CharacterModel::getByUserId($targetUser['id']);
                }
            }
            if (!$targetChar) {
                echo json_encode(['success' => false, 'message' => "角色不存在: {$targetName}"]);
                exit;
            }
            $items = ItemModel::getCharacterItems($targetChar['id']);
            echo json_encode(['success' => true, 'data' => $items, 'char_name' => $targetChar['name']]);
            exit;
            
        case 'list_room_items':
            $area = $requestData['area'] ?? '';
            $room = $requestData['room'] ?? '';
            if (empty($area) || empty($room)) {
                echo json_encode(['success' => false, 'message' => '请输入区域和房间']);
                exit;
            }
            $fullRoomId = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
            $items = Database::queryAll("SELECT ri.*, i.name FROM room_items ri LEFT JOIN items i ON ri.item_id = i.item_id WHERE ri.room_id = ?", [$fullRoomId]);
            echo json_encode(['success' => true, 'data' => $items, 'room_id' => $fullRoomId]);
            exit;
            
        case 'add_item_to_player':
            require_once MODEL_PATH . 'Item.php';
            $targetName = $requestData['target_name'] ?? '';
            $itemId = $requestData['item_id'] ?? '';
            $category = $requestData['category'] ?? '';
            $quantity = intval($requestData['quantity'] ?? 1);
            if (empty($targetName) || empty($itemId)) {
                echo json_encode(['success' => false, 'message' => '请输入角色名和物品ID']);
                exit;
            }
            $targetChar = Database::queryOne('SELECT id, name FROM characters WHERE LOWER(name) = LOWER(?)', [$targetName]);
            if (!$targetChar) {
                $targetUser = UserModel::findByUsername($targetName);
                if ($targetUser) {
                    $targetChar = CharacterModel::getByUserId($targetUser['id']);
                }
            }
            if (!$targetChar) {
                echo json_encode(['success' => false, 'message' => "角色不存在: {$targetName}"]);
                exit;
            }
            $itemInfo = ItemModel::findByItemId($itemId, $category);
            if (!$itemInfo) {
                echo json_encode(['success' => false, 'message' => "物品不存在: {$itemId}" . ($category ? " (category: {$category})" : '')]);
                exit;
            }
            $result = ItemModel::addToInventory($targetChar['id'], $itemId, $quantity, $category);
            if ($result) {
                echo json_encode(['success' => true, 'message' => "已添加 [{$itemInfo['name']}] x{$quantity} 给 {$targetChar['name']}"]);
            } else {
                echo json_encode(['success' => false, 'message' => '添加失败']);
            }
            exit;
            
        case 'add_item_to_room':
            require_once MODEL_PATH . 'Item.php';
            $area = $requestData['area'] ?? '';
            $room = $requestData['room'] ?? '';
            $itemId = $requestData['item_id'] ?? '';
            $category = $requestData['category'] ?? '';
            $quantity = intval($requestData['quantity'] ?? 1);
            if (empty($area) || empty($room) || empty($itemId)) {
                echo json_encode(['success' => false, 'message' => '请输入区域、房间和物品ID']);
                exit;
            }
            $fullRoomId = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
            $roomExists = Database::queryOne("SELECT id FROM rooms WHERE room_id = ?", [$fullRoomId]);
            if (!$roomExists) {
                echo json_encode(['success' => false, 'message' => "房间不存在: {$fullRoomId}"]);
                exit;
            }
            $roomId = intval($roomExists['id']);
            $itemInfo = ItemModel::findByItemId($itemId, $category);
            if (!$itemInfo) {
                echo json_encode(['success' => false, 'message' => "物品不存在: {$itemId}" . ($category ? " (category: {$category})" : '')]);
                exit;
            }
            for ($i = 0; $i < $quantity; $i++) {
                Database::execute(
                    "INSERT INTO room_items (room_id, item_id, category, quantity) VALUES (?, ?, ?, 1)",
                    [$roomId, $itemId, $category]
                );
            }
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            $dropMessage = "{$itemInfo['name']}“啪”地一声从虚空中掉了下来！";
            MessageDaemon::broadcastToRoom($fullRoomId, $dropMessage, 0, 'room');
            echo json_encode(['success' => true, 'message' => "已在 {$fullRoomId} 放置 [{$itemInfo['name']}] x{$quantity}"]);
            exit;
            
        case 'create_item':
            $newItemId = $requestData['new_item_id'] ?? '';
            $newName = $requestData['new_name'] ?? '';
            $newType = $requestData['new_type'] ?? 'misc';
            $newCategory = $requestData['new_category'] ?? '';
            $newLevel = intval($requestData['new_level'] ?? 1);
            $newValue = intval($requestData['new_value'] ?? 0);
            $newDesc = $requestData['new_desc'] ?? '';
            if (empty($newItemId) || empty($newName)) {
                echo json_encode(['success' => false, 'message' => '请输入物品ID和名称']);
                exit;
            }
            $exists = Database::queryOne("SELECT id FROM items WHERE item_id = ? AND category = ?", [$newItemId, $newCategory]);
            if ($exists) {
                echo json_encode(['success' => false, 'message' => "物品已存在: {$newItemId}" . ($newCategory ? " (category: {$newCategory})" : '')]);
                exit;
            }
            Database::execute(
                "INSERT INTO items (item_id, name, type, category, level, value, weight, stackable, max_stack, unit, description) VALUES (?, ?, ?, ?, ?, ?, 0, 0, 1, '个', ?)",
                [$newItemId, $newName, $newType, $newCategory, $newLevel, $newValue, $newDesc]
            );
            echo json_encode(['success' => true, 'message' => "已创建物品: {$newName} (ID: {$newItemId})"]);
            exit;
            
        case 'list_npcs':
            $page = intval($requestData['page'] ?? 1);
            $perPage = 20;
            $offset = ($page - 1) * $perPage;
            $search = trim($requestData['search'] ?? '');
            
            if ($search) {
                $total = Database::queryOne("SELECT COUNT(*) as count FROM npcs WHERE npc_id LIKE ? OR name LIKE ? OR title LIKE ? OR description LIKE ?", ["%$search%", "%$search%", "%$search%", "%$search%"])['count'] ?? 0;
                $npcs = Database::queryAll("SELECT id, npc_id, name, title, race, class, gender, spawn_area, spawn_room, attitude FROM npcs WHERE npc_id LIKE ? OR name LIKE ? OR title LIKE ? OR description LIKE ? ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}", ["%$search%", "%$search%", "%$search%", "%$search%"]);
            } else {
                $total = Database::queryOne("SELECT COUNT(*) as count FROM npcs")['count'] ?? 0;
                $npcs = Database::queryAll("SELECT id, npc_id, name, title, race, class, gender, spawn_area, spawn_room, attitude FROM npcs ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}");
            }
            echo json_encode(['success' => true, 'npcs' => $npcs, 'total' => $total, 'page' => $page, 'pages' => ceil($total / $perPage)]);
            exit;
            
        case 'get_npc':
            $npcId = intval($requestData['npc_id'] ?? 0);
            $npc = NpcModel::find($npcId);
            if (!$npc) {
                echo json_encode(['success' => false, 'message' => 'NPC不存在']);
            } else {
                echo json_encode(['success' => true, 'npc' => $npc]);
            }
            exit;
            
        case 'create_npc':
            $npcId = $requestData['npc_id'] ?? '';
            $name = $requestData['name'] ?? '';
            $title = $requestData['title'] ?? '';
            $race = $requestData['race'] ?? 'human';
            $class = $requestData['class'] ?? '';
            $gender = $requestData['gender'] ?? 'male';
            $description = $requestData['description'] ?? '';
            $spawnArea = $requestData['spawn_area'] ?? '';
            $spawnRoom = $requestData['spawn_room'] ?? '';
            $attitude = $requestData['attitude'] ?? 'friendly';
            
            if (empty($npcId) || empty($name)) {
                echo json_encode(['success' => false, 'message' => '请输入NPC标识和名称']);
                exit;
            }
            $exists = Database::queryOne("SELECT id FROM npcs WHERE npc_id = ?", [$npcId]);
            if ($exists) {
                echo json_encode(['success' => false, 'message' => "NPC已存在: {$npcId}"]);
                exit;
            }
            Database::execute(
                "INSERT INTO npcs (npc_id, name, title, race, class, gender, description, spawn_area, spawn_room, attitude) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$npcId, $name, $title, $race, $class, $gender, $description, $spawnArea, $spawnRoom, $attitude]
            );
            echo json_encode(['success' => true, 'message' => "已创建NPC: {$name} (ID: {$npcId})"]);
            exit;
            
        case 'update_npc':
            $id = intval($requestData['id'] ?? 0);
            $name = $requestData['name'] ?? '';
            $title = $requestData['title'] ?? '';
            $race = $requestData['race'] ?? 'human';
            $class = $requestData['class'] ?? '';
            $gender = $requestData['gender'] ?? 'male';
            $description = $requestData['description'] ?? '';
            $spawnArea = $requestData['spawn_area'] ?? '';
            $spawnRoom = $requestData['spawn_room'] ?? '';
            $attitude = $requestData['attitude'] ?? 'friendly';
            
            if ($id <= 0 || empty($name)) {
                echo json_encode(['success' => false, 'message' => '请选择NPC并输入名称']);
                exit;
            }
            Database::execute(
                "UPDATE npcs SET name = ?, title = ?, race = ?, class = ?, gender = ?, description = ?, spawn_area = ?, spawn_room = ?, attitude = ? WHERE id = ?",
                [$name, $title, $race, $class, $gender, $description, $spawnArea, $spawnRoom, $attitude, $id]
            );
            echo json_encode(['success' => true, 'message' => 'NPC信息已更新']);
            exit;
            
        case 'delete_npc':
            $id = intval($requestData['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => '请选择要删除的NPC']);
                exit;
            }
            $npc = Database::queryOne("SELECT name FROM npcs WHERE id = ?", [$id]);
            if (!$npc) {
                echo json_encode(['success' => false, 'message' => 'NPC不存在']);
                exit;
            }
            Database::execute("DELETE FROM npcs WHERE id = ?", [$id]);
            echo json_encode(['success' => true, 'message' => "已删除NPC: {$npc['name']}"]);
            exit;
            
        // 新闻公告管理
        case 'add_news':
            // 权限检查：只有巫师等级5（大巫师）或6（管理员）才能发布公告
            if ($user['wizard_level'] < WizardHelper::LEVEL_ARCH) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要大巫师(arch)以上权限才能发布公告']);
                exit;
            }
            
            $title = trim($requestData['title'] ?? '');
            $content = trim($requestData['content'] ?? '');
            $isLatest = intval($requestData['is_latest'] ?? 0);
            $sortOrder = intval($requestData['sort_order'] ?? 0);
            
            if (empty($title)) {
                echo json_encode(['success' => false, 'message' => '请输入标题']);
                exit;
            }
            if (empty($content)) {
                echo json_encode(['success' => false, 'message' => '请输入内容']);
                exit;
            }
            
            if ($isLatest) {
                Database::execute("UPDATE news SET is_latest = 0");
            }
            
            Database::execute(
                "INSERT INTO news (title, content, is_latest, sort_order) VALUES (?, ?, ?, ?)",
                [$title, $content, $isLatest, $sortOrder]
            );
            
            // 发送全局消息通知所有在线玩家
            require_once HELPER_PATH . 'SystemBroadcast.php';
            $broadcastMsg = "<span style='color: #e94560;'>【系统公告】</span><span style='color: #ffd700;'>{$title}</span>\n" . strip_tags($content);
            SystemBroadcast::announce($broadcastMsg);
            
            echo json_encode(['success' => true, 'message' => "新闻公告已添加，已向所有在线玩家发送通知"]);
            exit;
            
        case 'update_news':
            // 权限检查：只有巫师等级5（大巫师）或6（管理员）才能修改公告
            if ($user['wizard_level'] < WizardHelper::LEVEL_ARCH) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要大巫师(arch)以上权限才能修改公告']);
                exit;
            }
            
            $id = intval($requestData['id'] ?? 0);
            $title = trim($requestData['title'] ?? '');
            $content = trim($requestData['content'] ?? '');
            $isLatest = intval($requestData['is_latest'] ?? 0);
            $sortOrder = intval($requestData['sort_order'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => '请选择要修改的新闻']);
                exit;
            }
            if (empty($title)) {
                echo json_encode(['success' => false, 'message' => '请输入标题']);
                exit;
            }
            if (empty($content)) {
                echo json_encode(['success' => false, 'message' => '请输入内容']);
                exit;
            }
            
            if ($isLatest) {
                Database::execute("UPDATE news SET is_latest = 0");
            }
            
            Database::execute(
                "UPDATE news SET title = ?, content = ?, is_latest = ?, sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                [$title, $content, $isLatest, $sortOrder, $id]
            );
            
            // 发送全局消息通知所有在线玩家
            require_once HELPER_PATH . 'SystemBroadcast.php';
            $broadcastMsg = "<span style='color: #e94560;'>【系统公告更新】</span><span style='color: #ffd700;'>{$title}</span>\n" . strip_tags($content);
            SystemBroadcast::announce($broadcastMsg);
            
            echo json_encode(['success' => true, 'message' => "新闻公告已更新，已向所有在线玩家发送通知"]);
            exit;
            
        case 'delete_news':
            // 权限检查：只有巫师等级5（大巫师）或6（管理员）才能删除公告
            if ($user['wizard_level'] < WizardHelper::LEVEL_ARCH) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要大巫师(arch)以上权限才能删除公告']);
                exit;
            }
            
            $id = intval($requestData['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => '请选择要删除的新闻']);
                exit;
            }
            $news = Database::queryOne("SELECT title FROM news WHERE id = ?", [$id]);
            if (!$news) {
                echo json_encode(['success' => false, 'message' => '新闻不存在']);
                exit;
            }
            Database::execute("DELETE FROM news WHERE id = ?", [$id]);
            echo json_encode(['success' => true, 'message' => "已删除新闻: {$news['title']}"]);
            exit;
            
        case 'list_news':
            $newsList = Database::queryAll("SELECT * FROM news ORDER BY sort_order DESC, created_at DESC");
            echo json_encode(['success' => true, 'news' => $newsList]);
            exit;
            
        // 玩家数据导出
        case 'export_player_data':
            if ($user['wizard_level'] < WizardHelper::LEVEL_ADMIN) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => '权限不足！需要管理员(admin)权限']);
                exit;
            }
            
            try {
                error_log('Export started: ' . date('Y-m-d H:i:s'));
                $onlineOnly = intval($requestData['online_only'] ?? 0);
                $playerName = trim($requestData['player_name'] ?? '');
                $format = trim($requestData['format'] ?? 'json');
                error_log("Export params: onlineOnly={$onlineOnly}, playerName={$playerName}, format={$format}");
            
            // 构建查询条件
            if (!empty($playerName)) {
                $users = Database::queryAll(
                    "SELECT * FROM users WHERE username LIKE ?",
                    ["%{$playerName}%"]
                );
                
                $players = Database::queryAll(
                    "SELECT c.* FROM characters c 
                     JOIN users u ON c.user_id = u.id 
                     WHERE c.name LIKE ? OR u.username LIKE ?",
                    ["%{$playerName}%", "%{$playerName}%"]
                );
            } elseif ($onlineOnly) {
                $players = Database::queryAll("SELECT * FROM characters WHERE online = 1");
                $userIds = array_unique(array_column($players, 'user_id'));
                if (!empty($userIds)) {
                    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
                    $users = Database::queryAll("SELECT * FROM users WHERE id IN ({$placeholders})", $userIds);
                } else {
                    $users = [];
                }
            } else {
                $players = Database::queryAll("SELECT * FROM characters");
                $users = Database::queryAll("SELECT * FROM users");
            }
            
            // 获取要导出的角色ID列表
            $charIds = array_column($players, 'id');
            $userIds = array_column($users, 'id');
            $isFiltered = !empty($playerName) || $onlineOnly; // 是否是筛选导出（非全部）
            
            // 角色相关表配置（表名 => char_id 字段名）
            $charRelatedTables = [
                'character_buffs' => 'char_id',
                'character_temp_states' => 'char_id',
                'character_temp' => 'char_id',
                'character_inventory' => 'char_id',
                'character_skills' => 'char_id',
                'character_skill_map' => 'char_id',
                'character_quests' => 'char_id',
                'character_fabao' => 'owner_id',
                'recharge_logs' => 'char_id',
                'redeem_logs' => 'char_id',
                'bank_deposits' => 'char_id',
                'bank_transactions' => 'char_id',
                'sect_members' => 'character_id',
                'message_queue' => 'char_id',
                'dudaxiao_history' => 'char_id',
                'qujing_applicants' => 'char_id',
                'qujing_failures' => 'char_id',
                'qujing_history' => 'char_id',
                'tianmojian_borrows' => 'char_id',
                'book_read_count' => 'char_id',
                'trades' => 'char_id',
                'court_suspects' => 'char_id',
            ];
            
            // 双向关系表（需要匹配两个字段）
            $dualCharTables = [
                'active_combats' => ['char_id', 'target_id'],
                'combat_records' => ['challenger_id', 'defender_id'],
                'friends' => ['from_character_id', 'to_character_id'],
                'messages' => ['from_char_id', 'to_char_id'],
                'apprentice_requests' => ['from_character_id', 'to_character_id'],
                'marry_requests' => ['proposer_id', 'target_id'],
                'court_cases' => ['defendant_id', 'judge_id'],
                'sleep_invitations' => ['from_char_id', 'to_char_id'],
                'character_teams' => ['leader_id', 'member_id'],
            ];
            
            // 用户相关表
            $userRelatedTables = [
                'user_blocks' => ['user_id', 'blocked_by'],
            ];
            
            // 家园相关表（需要通过 home_id 关联）
            $homeRelatedTables = ['home_items', 'home_guests', 'home_babies'];
            
            $relatedTablesData = [];
            
            if ($isFiltered && !empty($charIds)) {
                // 筛选导出：只导出指定角色的数据
                $charPlaceholders = implode(',', array_fill(0, count($charIds), '?'));
                
                // 导出角色相关表
                foreach ($charRelatedTables as $tableName => $charField) {
                    $tableExists = Database::queryOne("SHOW TABLES LIKE '{$tableName}'");
                    if ($tableExists) {
                        $relatedTablesData[$tableName] = Database::queryAll(
                            "SELECT * FROM `{$tableName}` WHERE `{$charField}` IN ({$charPlaceholders})",
                            $charIds
                        );
                    }
                }
                
                // 导出双向关系表
                foreach ($dualCharTables as $tableName => $fields) {
                    $tableExists = Database::queryOne("SHOW TABLES LIKE '{$tableName}'");
                    if ($tableExists) {
                        $field1 = $fields[0];
                        $field2 = $fields[1];
                        $relatedTablesData[$tableName] = Database::queryAll(
                            "SELECT * FROM `{$tableName}` WHERE `{$field1}` IN ({$charPlaceholders}) OR `{$field2}` IN ({$charPlaceholders})",
                            array_merge($charIds, $charIds)
                        );
                    }
                }
                
                // 导出玩家家园
                $tableExists = Database::queryOne("SHOW TABLES LIKE 'player_homes'");
                if ($tableExists) {
                    $homes = Database::queryAll(
                        "SELECT * FROM player_homes WHERE owner_id IN ({$charPlaceholders})",
                        $charIds
                    );
                    $relatedTablesData['player_homes'] = $homes;
                    
                    // 导出家园相关数据
                    $homeIds = array_column($homes, 'id');
                    if (!empty($homeIds)) {
                        $homePlaceholders = implode(',', array_fill(0, count($homeIds), '?'));
                        foreach ($homeRelatedTables as $tableName) {
                            $tableExists = Database::queryOne("SHOW TABLES LIKE '{$tableName}'");
                            if ($tableExists) {
                                $relatedTablesData[$tableName] = Database::queryAll(
                                    "SELECT * FROM `{$tableName}` WHERE home_id IN ({$homePlaceholders})",
                                    $homeIds
                                );
                            }
                        }
                    }
                }
                
                // 导出用户相关表
                if (!empty($userIds)) {
                    $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
                    foreach ($userRelatedTables as $tableName => $fields) {
                        $tableExists = Database::queryOne("SHOW TABLES LIKE '{$tableName}'");
                        if ($tableExists) {
                            $field1 = $fields[0];
                            $field2 = $fields[1];
                            $relatedTablesData[$tableName] = Database::queryAll(
                                "SELECT * FROM `{$tableName}` WHERE `{$field1}` IN ({$userPlaceholders}) OR `{$field2}` IN ({$userPlaceholders})",
                                array_merge($userIds, $userIds)
                            );
                        }
                    }
                }
            } else {
                // 全部导出：导出所有角色相关表的全部数据
                
                // 先导出 character_ 开头的表
                $characterTables = Database::queryAll(
                    "SELECT TABLE_NAME FROM information_schema.tables 
                     WHERE table_schema = DATABASE() AND TABLE_NAME LIKE 'character_%'"
                );
                
                foreach ($characterTables as $tableInfo) {
                    $tableName = $tableInfo['TABLE_NAME'] ?? $tableInfo['table_name'] ?? (isset($tableInfo[0]) ? $tableInfo[0] : null);
                    if ($tableName) {
                        $relatedTablesData[$tableName] = Database::queryAll("SELECT * FROM `{$tableName}`");
                    }
                }
                
                // 导出其他角色相关表
                $allCharTables = array_merge(
                    array_keys($charRelatedTables),
                    array_keys($dualCharTables),
                    ['player_homes'],
                    $homeRelatedTables,
                    array_keys($userRelatedTables)
                );
                
                foreach ($allCharTables as $tableName) {
                    if (!isset($relatedTablesData[$tableName])) {
                        $tableExists = Database::queryOne("SHOW TABLES LIKE '{$tableName}'");
                        if ($tableExists) {
                            $relatedTablesData[$tableName] = Database::queryAll("SELECT * FROM `{$tableName}`");
                        }
                    }
                }
            }
            
            if ($format === 'sql') {
                $sqlContent = "-- 玩家数据导出\n-- 导出时间: " . date('Y-m-d H:i:s') . "\n";
                $sqlContent .= "-- 导出范围: " . ($playerName ? "玩家: {$playerName}" : ($onlineOnly ? "在线玩家" : "所有玩家")) . "\n";
                $sqlContent .= "-- 包含表: " . implode(', ', array_keys($relatedTablesData)) . "\n\n";
                $sqlContent .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
                
                $sqlContent .= generateInsertSQL('users', $users);
                $sqlContent .= "\n";
                $sqlContent .= generateInsertSQL('characters', $players);
                
                foreach ($relatedTablesData as $tableName => $tableData) {
                    $sqlContent .= "\n";
                    $sqlContent .= generateInsertSQL($tableName, $tableData);
                }
                
                $sqlContent .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";
                
                header('Content-Type: text/plain; charset=utf-8');
                header('Content-Disposition: attachment; filename="player_data_' . date('Ymd_His') . '.sql"');
                echo $sqlContent;
            /*
            // [暂时注释] JSON格式导出 — 待 PHP 配置问题修复后可重新启用
            } else {
                $data = [
                    'export_time' => date('Y-m-d H:i:s'),
                    'online_only' => $onlineOnly,
                    'player_name' => $playerName,
                    'users' => $users,
                    'characters' => $players,
                    'character_tables' => $relatedTablesData,
                    'count' => [
                        'users' => count($users),
                        'characters' => count($players),
                        'character_tables' => count($relatedTablesData)
                    ]
                ];
                
                $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="player_data_' . date('Ymd_His') . '.json"');
                echo $jsonData;
            */
            }
            } catch (Exception $e) {
                header('Content-Type: application/json; charset=utf-8');
                error_log('导出失败: ' . $e->getMessage() . ' ' . $e->getTraceAsString());
                echo json_encode(['success' => false, 'message' => '导出失败: ' . $e->getMessage()]);
                exit;
            }
            
            exit;
            
        // ========== 表情管理 ==========
        case 'emote_list':
            $emotes = Database::queryAll(
                "SELECT * FROM emotes ORDER BY sort_order, command"
            );
            echo json_encode(['success' => true, 'emotes' => $emotes ?: []]);
            exit;
            
        case 'emote_get':
            $cmd = $requestData['command'] ?? '';
            if (!$cmd) {
                echo json_encode(['success' => false, 'message' => '请提供表情命令名']);
                exit;
            }
            $emote = Database::queryOne(
                "SELECT * FROM emotes WHERE command = ?",
                [$cmd]
            );
            echo json_encode(['success' => true, 'emote' => $emote ?: null]);
            exit;
            
        case 'emote_save':
            if ($user['wizard_level'] < WizardHelper::LEVEL_IMMORTAL) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要巫师(immortal)以上权限']);
                exit;
            }
            $cmd = trim($requestData['command'] ?? '');
            if (!$cmd) {
                echo json_encode(['success' => false, 'message' => '请提供表情命令名']);
                exit;
            }
            // 7个视角字段
            $fields = [
                'myself' => $requestData['myself'] ?? '',
                'myself_target' => $requestData['myself_target'] ?? '',
                'myself_self' => $requestData['myself_self'] ?? '',
                'target' => $requestData['target'] ?? '',
                'others' => $requestData['others'] ?? '',
                'others_target' => $requestData['others_target'] ?? '',
                'others_self' => $requestData['others_self'] ?? '',
            ];
            $description = $requestData['description'] ?? '';
            $isActive = intval($requestData['is_active'] ?? 1);
            $sortOrder = intval($requestData['sort_order'] ?? 0);
            $updatedBy = $user['username'] ?? 'wizard';
            
            // 检查是否已存在
            $existing = Database::queryOne("SELECT id FROM emotes WHERE command = ?", [$cmd]);
            if ($existing) {
                Database::execute(
                    "UPDATE emotes SET description=?, updated_by=?, myself=?, myself_target=?, myself_self=?, target=?, others=?, others_target=?, others_self=?, is_active=?, sort_order=? WHERE command=?",
                    [$description, $updatedBy, $fields['myself'], $fields['myself_target'], $fields['myself_self'], $fields['target'], $fields['others'], $fields['others_target'], $fields['others_self'], $isActive, $sortOrder, $cmd]
                );
                echo json_encode(['success' => true, 'message' => "表情 \"{$cmd}\" 已更新"]);
            } else {
                Database::execute(
                    "INSERT INTO emotes (command, description, updated_by, myself, myself_target, myself_self, target, others, others_target, others_self, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$cmd, $description, $updatedBy, $fields['myself'], $fields['myself_target'], $fields['myself_self'], $fields['target'], $fields['others'], $fields['others_target'], $fields['others_self'], $isActive, $sortOrder]
                );
                echo json_encode(['success' => true, 'message' => "表情 \"{$cmd}\" 已添加"]);
            }
            exit;
            
        case 'emote_delete':
            if ($user['wizard_level'] < WizardHelper::LEVEL_IMMORTAL) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要巫师(immortal)以上权限']);
                exit;
            }
            $cmd = trim($requestData['command'] ?? '');
            if (!$cmd) {
                echo json_encode(['success' => false, 'message' => '请提供表情命令名']);
                exit;
            }
            $existing = Database::queryOne("SELECT id FROM emotes WHERE command = ?", [$cmd]);
            if (!$existing) {
                echo json_encode(['success' => false, 'message' => "表情 \"{$cmd}\" 不存在"]);
                exit;
            }
            Database::execute("DELETE FROM emotes WHERE command = ?", [$cmd]);
            echo json_encode(['success' => true, 'message' => "表情 \"{$cmd}\" 已删除"]);
            exit;
            
        case 'emote_toggle':
            if ($user['wizard_level'] < WizardHelper::LEVEL_IMMORTAL) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要巫师(immortal)以上权限']);
                exit;
            }
            $cmd = trim($requestData['command'] ?? '');
            if (!$cmd) {
                echo json_encode(['success' => false, 'message' => '请提供表情命令名']);
                exit;
            }
            $emote = Database::queryOne("SELECT id, is_active FROM emotes WHERE command = ?", [$cmd]);
            if (!$emote) {
                echo json_encode(['success' => false, 'message' => "表情 \"{$cmd}\" 不存在"]);
                exit;
            }
            $newStatus = $emote['is_active'] ? 0 : 1;
            Database::execute("UPDATE emotes SET is_active = ? WHERE command = ?", [$newStatus, $cmd]);
            echo json_encode(['success' => true, 'message' => "表情 \"{$cmd}\" 已" . ($newStatus ? '启用' : '禁用'), 'is_active' => $newStatus]);
            exit;
            
        // ========== 表情管理结束 ==========
        
        // ========== AI 玩家管理 ==========
        case 'ai_player_status':
            if ($user['wizard_level'] < WizardHelper::LEVEL_ADMIN) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要管理员(admin)权限']);
                exit;
            }
            require_once __DIR__ . '/../helpers/AiPlayerHelper.php';
            $allAi = Database::queryAll(
                "SELECT c.id, c.name, c.race, c.gender, c.current_area, c.current_room, 
                        c.online, c.kee, c.max_kee, c.is_ai_player, c.ai_last_action, c.ai_paused
                 FROM characters c 
                 WHERE c.is_ai_player = 1 
                 ORDER BY c.online DESC, c.id ASC"
            );
            $now = time();
            foreach ($allAi as &$a) {
                $a['online'] = intval($a['online'] ?? 0);
                $a['is_ai_player'] = intval($a['is_ai_player'] ?? 0);
                $a['ai_paused'] = intval($a['ai_paused'] ?? 0);
                $lastAction = intval($a['ai_last_action'] ?? 0);
                $a['seconds_ago'] = $lastAction > 0 ? ($now - $lastAction) : -1;
                $a['ai_last_action'] = $lastAction;
            }
            echo json_encode(['success' => true, 'ai_players' => $allAi, 'count' => count($allAi)]);
            exit;
        
        case 'ai_player_login':
            if ($user['wizard_level'] < WizardHelper::LEVEL_ADMIN) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要管理员(admin)权限']);
                exit;
            }
            require_once __DIR__ . '/../helpers/AiPlayerHelper.php';
            $charId = intval($requestData['char_id'] ?? 0);
            if ($charId <= 0) {
                echo json_encode(['success' => false, 'message' => '无效的角色ID']);
                exit;
            }
            $success = AiPlayerHelper::loginAiPlayer($charId);
            echo json_encode(['success' => $success, 'message' => $success ? 'AI 玩家已上线' : '上线失败']);
            exit;
        
        case 'ai_player_logout':
            if ($user['wizard_level'] < WizardHelper::LEVEL_ADMIN) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要管理员(admin)权限']);
                exit;
            }
            require_once __DIR__ . '/../helpers/AiPlayerHelper.php';
            $charId = intval($requestData['char_id'] ?? 0);
            if ($charId <= 0) {
                echo json_encode(['success' => false, 'message' => '无效的角色ID']);
                exit;
            }
            $success = AiPlayerHelper::logoutAiPlayer($charId);
            echo json_encode(['success' => $success, 'message' => $success ? 'AI 玩家已下线' : '下线失败']);
            exit;
        
        case 'ai_player_mark':
            if ($user['wizard_level'] < WizardHelper::LEVEL_ADMIN) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要管理员(admin)权限']);
                exit;
            }
            require_once __DIR__ . '/../helpers/AiPlayerHelper.php';
            $charId = intval($requestData['char_id'] ?? 0);
            if ($charId <= 0) {
                echo json_encode(['success' => false, 'message' => '无效的角色ID']);
                exit;
            }
            $char = Database::queryOne("SELECT name FROM characters WHERE id = ?", [$charId]);
            if (!$char) {
                echo json_encode(['success' => false, 'message' => '角色不存在']);
                exit;
            }
            $success = AiPlayerHelper::markAsAiPlayer($charId);
            echo json_encode(['success' => $success, 'message' => $success ? "已标记「{$char['name']}」为 AI 玩家" : '标记失败']);
            exit;
        
        case 'ai_player_unmark':
            if ($user['wizard_level'] < WizardHelper::LEVEL_ADMIN) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要管理员(admin)权限']);
                exit;
            }
            require_once __DIR__ . '/../helpers/AiPlayerHelper.php';
            $charId = intval($requestData['char_id'] ?? 0);
            if ($charId <= 0) {
                echo json_encode(['success' => false, 'message' => '无效的角色ID']);
                exit;
            }
            $char = Database::queryOne("SELECT name FROM characters WHERE id = ?", [$charId]);
            $success = AiPlayerHelper::unmarkAiPlayer($charId);
            $name = $char['name'] ?? "ID:{$charId}";
            echo json_encode(['success' => $success, 'message' => $success ? "已取消「{$name}」的 AI 玩家标记" : '取消失败']);
            exit;
        
        case 'ai_player_create':
            // 权限检查：只有6级管理员才能创建AI玩家
            if ($user['wizard_level'] < WizardHelper::LEVEL_ADMIN) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要管理员(admin)权限才能创建AI玩家']);
                exit;
            }
            require_once __DIR__ . '/../helpers/AiPlayerHelper.php';
            require_once __DIR__ . '/../daemons/AiPlayerDaemon.php';
            $name = trim($requestData['name'] ?? '');
            $gender = $requestData['gender'] ?? 'male';
            $race = $requestData['race'] ?? 'human';
            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => '请输入角色名']);
                exit;
            }
            $result = AiPlayerDaemon::createAndLogin($name, $gender, $race);
            echo json_encode($result);
            exit;
        
        case 'ai_player_tick':
            if ($user['wizard_level'] < WizardHelper::LEVEL_ADMIN) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要管理员(admin)权限']);
                exit;
            }
            require_once __DIR__ . '/../helpers/AiPlayerHelper.php';
            require_once __DIR__ . '/../daemons/AiPlayerDaemon.php';
            $result = AiPlayerDaemon::runTick(10);
            echo json_encode(['success' => true, 'result' => $result]);
            exit;
        
        case 'ai_player_pause':
            if ($user['wizard_level'] < WizardHelper::LEVEL_ADMIN) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要管理员(admin)权限']);
                exit;
            }
            require_once __DIR__ . '/../helpers/AiPlayerHelper.php';
            $charId = intval($requestData['char_id'] ?? 0);
            if ($charId <= 0) { echo json_encode(['success' => false, 'message' => '无效的角色ID']); exit; }
            $success = AiPlayerHelper::pauseAiPlayer($charId);
            echo json_encode(['success' => $success, 'message' => $success ? 'AI 玩家已暂停' : '暂停失败']);
            exit;
        
        case 'ai_player_resume':
            if ($user['wizard_level'] < WizardHelper::LEVEL_ADMIN) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要管理员(admin)权限']);
                exit;
            }
            require_once __DIR__ . '/../helpers/AiPlayerHelper.php';
            $charId = intval($requestData['char_id'] ?? 0);
            if ($charId <= 0) { echo json_encode(['success' => false, 'message' => '无效的角色ID']); exit; }
            $success = AiPlayerHelper::resumeAiPlayer($charId);
            echo json_encode(['success' => $success, 'message' => $success ? 'AI 玩家已恢复' : '恢复失败']);
            exit;
        
        case 'ai_player_pause_all':
            if ($user['wizard_level'] < WizardHelper::LEVEL_ADMIN) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要管理员(admin)权限']);
                exit;
            }
            require_once __DIR__ . '/../helpers/AiPlayerHelper.php';
            $count = AiPlayerHelper::pauseAllAiPlayers();
            echo json_encode(['success' => true, 'message' => "已暂停全部 {$count} 个 AI 玩家"]);
            exit;
        
        case 'ai_player_resume_all':
            if ($user['wizard_level'] < WizardHelper::LEVEL_ADMIN) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要管理员(admin)权限']);
                exit;
            }
            require_once __DIR__ . '/../helpers/AiPlayerHelper.php';
            $count = AiPlayerHelper::resumeAllAiPlayers();
            echo json_encode(['success' => true, 'message' => "已恢复全部 {$count} 个 AI 玩家"]);
            exit;
        
        case 'ai_player_pause_status':
            if ($user['wizard_level'] < WizardHelper::LEVEL_ADMIN) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要管理员(admin)权限']);
                exit;
            }
            require_once __DIR__ . '/../helpers/AiPlayerHelper.php';
            $status = AiPlayerHelper::getAiPauseStatus();
            echo json_encode(['success' => true, 'status' => $status]);
            exit;
        case 'ai_player_auto_tick_state':
            if ($user['wizard_level'] < WizardHelper::LEVEL_ADMIN) {
                echo json_encode(['success' => false, 'message' => '权限不足！需要管理员(admin)权限']);
                exit;
            }
            $stateFile = __DIR__ . '/../data/ai_auto_tick_state.json';
            if (isset($requestData['running'])) {
                $running = (bool)$requestData['running'];
                file_put_contents($stateFile, json_encode(['running' => $running]));
                echo json_encode(['success' => true, 'running' => $running]);
            } else {
                $running = false;
                if (file_exists($stateFile)) {
                    $data = json_decode(file_get_contents($stateFile), true);
                    $running = $data['running'] ?? false;
                }
                echo json_encode(['success' => true, 'running' => $running]);
            }
            exit;
        
        // ========== AI 玩家管理结束 ==========
            
        default:
            echo json_encode(['success' => false, 'message' => '未知操作']);
            exit;
    }
} else {
    // 如果没有action且是JSON请求，返回错误
    if ($isJsonRequest) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '缺少action参数']);
        exit;
    }
}

function generateInsertSQL($tableName, $data) {
    if (empty($data)) {
        return "-- 表 {$tableName} 无数据\n";
    }
    
    $firstRow = $data[0];
    $fields = array_keys($firstRow);
    $fieldsStr = '`' . implode('`, `', $fields) . '`';
    
    $sql = "REPLACE INTO `{$tableName}` ({$fieldsStr}) VALUES\n";
    
    $values = [];
    foreach ($data as $row) {
        $rowValues = [];
        foreach ($fields as $field) {
            $value = $row[$field] ?? null;
            
            if ($value === null) {
                $rowValues[] = 'NULL';
            } elseif (is_numeric($value)) {
                $rowValues[] = $value;
            } elseif (is_bool($value)) {
                $rowValues[] = $value ? '1' : '0';
            } else {
                $value = str_replace("\\", "\\\\\\", $value);
                $value = str_replace("'", "\\'", $value);
                $value = str_replace("\n", "\\n", $value);
                $value = str_replace("\r", "\\r", $value);
                $value = str_replace("\t", "\\t", $value);
                $rowValues[] = "'{$value}'";
            }
        }
        $values[] = '(' . implode(', ', $rowValues) . ')';
    }
    
    $sql .= implode(",\n", $values) . ";\n";
    
    return $sql;
}

// 获取统计数据
$onlineCount = Database::queryOne("SELECT COUNT(*) as count FROM characters WHERE online = 1")['count'] ?? 0;
$totalUsers = Database::queryOne("SELECT COUNT(*) as count FROM users")['count'] ?? 0;
$totalChars = Database::queryOne("SELECT COUNT(*) as count FROM characters")['count'] ?? 0;
$bannedUsers = BanHelper::getBannedUsers();
$bannedIps = BanHelper::getBannedIps();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员面板 - <?= SERVER_NAME ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
    <div class="header">
        <h1>管理员面板</h1>
        <div>
            <span>欢迎，<?= htmlspecialchars($user['username']) ?> (<?= WizardHelper::getLevelName($user['wizard_level']) ?>)</span>
            <a href="room.php" style="margin-left: 15px;">返回游戏</a>
        </div>
    </div>
    
    <div class="container">
        <div id="message" class="message" onclick="toggleMessage()">
            <div class="message-header"><span class="msg-title">系统消息</span><span class="toggle-icon">▼</span></div>
            <div class="message-content"></div>
        </div>
        
        <!-- 统计卡片 -->
        <div class="stats">
            <div class="stat-card">
                <div class="number"><?= $onlineCount ?></div>
                <div class="label">在线玩家</div>
            </div>
            <div class="stat-card">
                <div class="number"><?= $totalUsers ?></div>
                <div class="label">总用户数</div>
            </div>
            <div class="stat-card">
                <div class="number"><?= $totalChars ?></div>
                <div class="label">总角色数</div>
            </div>
            <div class="stat-card">
                <div class="number"><?= count($bannedUsers) ?></div>
                <div class="label">封禁/监禁用户</div>
            </div>
            <div class="stat-card">
                <div class="number"><?= count($bannedIps) ?></div>
                <div class="label">封禁IP数</div>
            </div>
        </div>
        
        <!-- 标签页 -->
        <div class="tabs">
            <div class="tab" data-tab="users" onclick="switchTab(this,'users')">用户管理</div>
            <div class="tab" data-tab="ip-ban" onclick="switchTab(this,'ip-ban')">IP封禁</div>
            <div class="tab" data-tab="online" onclick="switchTab(this,'online')">所有玩家</div>
            <div class="tab" data-tab="wizard" onclick="switchTab(this,'wizard')">巫师管理</div>
            <div class="tab" data-tab="guest" onclick="switchTab(this,'guest')">欢迎室</div>
            <div class="tab" data-tab="court" onclick="switchTab(this,'court')">公堂</div>
            <div class="tab" data-tab="query" onclick="switchTab(this,'query')">查询</div>
            <div class="tab" data-tab="items" onclick="switchTab(this,'items')">物品管理</div>
            <div class="tab" data-tab="npcs" onclick="switchTab(this,'npcs')">NPC管理</div>
            <div class="tab" data-tab="emote" onclick="switchTab(this,'emote')">表情管理</div>
            <div class="tab" data-tab="system" onclick="switchTab(this,'system')">系统管理</div>
            <div class="tab" data-tab="news" onclick="switchTab(this,'news')">新闻公告</div>
            <div class="tab" data-tab="ai" onclick="switchTab(this,'ai')" style="background: #1a1a2e; border-bottom: 2px solid #e94560;">🤖 AI玩家</div>
        </div>
        
        <!-- 用户管理 -->
        <div id="tab-users" class="tab-content active">
            <div class="card">
                <h2>用户管理</h2>
                <div class="search-box">
                    <input type="text" id="search-username" placeholder="输入用户名搜索">
                    <button class="btn btn-info" onclick="searchUser()">搜索</button>
                </div>
                
                <h3 style="color: #e94560; margin: 15px 0;">封禁/监禁用户列表</h3>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>用户名</th>
                            <th>状态</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bannedUsers)): ?>
                        <tr><td colspan="4" style="text-align: center; color: #aaa;">暂无封禁/监禁用户</td></tr>
                        <?php else: ?>
                        <?php foreach ($bannedUsers as $bannedUser): ?>
                        <tr>
                            <td><?= $bannedUser['id'] ?></td>
                            <td><?= htmlspecialchars($bannedUser['username']) ?></td>
                            <td>
                                <?php if ($bannedUser['status'] == BanHelper::STATUS_BANNED): ?>
                                <span class="status-banned">封禁</span>
                                <?php elseif ($bannedUser['status'] == BanHelper::STATUS_PRISONED): ?>
                                <span class="status-prisoned">监禁</span>
                                <?php endif; ?>
                            </td>
                            <td class="action-btns">
                                <button class="btn btn-success" onclick="unbanUser('<?= htmlspecialchars($bannedUser['username']) ?>')">解封</button>
                                <?php if ($bannedUser['status'] == BanHelper::STATUS_BANNED): ?>
                                <button class="btn btn-warning" onclick="imprisonUser('<?= htmlspecialchars($bannedUser['username']) ?>')">改为监禁</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <h3 style="color: #e94560; margin: 25px 0 15px;">快速操作</h3>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <input type="text" id="action-username" placeholder="输入用户名" style="width: 200px; padding: 10px; background: #0f3460; border: 1px solid #533483; color: #eee; border-radius: 5px;">
                    <button class="btn btn-danger" onclick="banUser()">封禁</button>
                    <button class="btn btn-warning" onclick="imprisonUser(document.getElementById('action-username').value)">监禁</button>
                    <button class="btn btn-success" onclick="unbanUser(document.getElementById('action-username').value)">解封</button>
                    <button class="btn btn-info" onclick="releaseUser(document.getElementById('action-username').value)">释放</button>
                </div>
            </div>
        </div>
        
        <!-- IP封禁 -->
        <div id="tab-ip-ban" class="tab-content">
            <div class="card">
                <h2>IP封禁管理</h2>
                <div class="form-group">
                    <label>IP模式（支持通配符*）</label>
                    <input type="text" id="ip-pattern" placeholder="例如: 192.168.* 或 10.0.0.1">
                </div>
                <div class="form-group">
                    <label>封禁原因</label>
                    <input type="text" id="ip-reason" placeholder="输入封禁原因">
                </div>
                <button class="btn btn-danger" onclick="banIp()">封禁IP</button>
                
                <h3 style="color: #e94560; margin: 25px 0 15px;">已封禁IP列表</h3>
                <table>
                    <thead>
                        <tr>
                            <th>IP模式</th>
                            <th>原因</th>
                            <th>操作者</th>
                            <th>封禁时间</th>
                            <th>过期时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bannedIps)): ?>
                        <tr><td colspan="6" style="text-align: center; color: #aaa;">暂无封禁IP</td></tr>
                        <?php else: ?>
                        <?php foreach ($bannedIps as $ban): ?>
                        <tr>
                            <td><?= htmlspecialchars($ban['ip_pattern']) ?></td>
                            <td><?= htmlspecialchars($ban['reason']) ?></td>
                            <td><?= htmlspecialchars($ban['banned_by'] ?? '-') ?></td>
                            <td><?= $ban['created_at'] ?></td>
                            <td><?= $ban['expires_at'] ?? '永久' ?></td>
                            <td>
                                <button class="btn btn-success" onclick="unbanIp('<?= htmlspecialchars($ban['ip_pattern']) ?>')">解封</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- 所有玩家 -->
        <div id="tab-online" class="tab-content">
            <div class="card">
                <h2>所有玩家列表</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>角色名</th>
                            <th>用户</th>
                            <th>巫师等级</th>
                            <th>在线状态</th>
                            <th>AI</th>
                            <th>当前位置</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $allPlayers = Database::queryAll("
                            SELECT c.id, c.name, c.level, c.current_area, c.current_room, c.online, c.is_ai_player, u.username, u.wizard_level, u.last_login 
                            FROM characters c 
                            JOIN users u ON c.user_id = u.id 
                            ORDER BY c.online DESC, u.wizard_level DESC, c.level DESC
                        ");
                        $now = time();
                        ?>
                        <?php if (empty($allPlayers)): ?>
                        <tr><td colspan="8" style="text-align: center; color: #aaa;">暂无玩家</td></tr>
                        <?php else: ?>
                        <?php foreach ($allPlayers as $player): ?>
                        <?php
                            if ($player['online'] == 1) {
                                $statusHtml = '<span style="color: green;">在线</span>';
                            } else {
                                $lastLoginTs = strtotime($player['last_login'] ?? '');
                                if ($lastLoginTs > 0) {
                                    $diffSeconds = $now - $lastLoginTs;
                                    $diffDays = max(1, intval($diffSeconds / 86400));
                                    $statusHtml = '<span style="color: #aaa;">离线(' . $diffDays . '天)</span>';
                                } else {
                                    $statusHtml = '<span style="color: #aaa;">离线</span>';
                                }
                            }
                            $isAi = intval($player['is_ai_player'] ?? 0);
                            if ($isAi) {
                                $aiHtml = '<span style="color: #e94560;">🤖 AI</span>';
                            } else {
                                $aiHtml = '<span style="color: #666;">普通</span>';
                            }
                        ?>
                        <tr>
                            <td><?= $player['id'] ?></td>
                            <td><?= htmlspecialchars($player['name']) ?></td>
                            <td><?= htmlspecialchars($player['username']) ?></td>
                            <td><?= WizardHelper::LEVEL_NAMES[$player['wizard_level']] ?? '未知' ?> (<?= $player['wizard_level'] ?>级)</td>
                            <td><?= $statusHtml ?></td>
                            <td><?= $aiHtml ?></td>
                            <td><?= htmlspecialchars($player['current_area'] . '/' . $player['current_room']) ?></td>
                            <td class="action-btns">
                                <?php if ($isAi): ?>
                                <button class="btn btn-info" style="padding:3px 8px;font-size:11px;" onclick="allPlayerMarkAi(<?= $player['id'] ?>, 'unmark')">取消AI</button>
                                <?php else: ?>
                                <button class="btn btn-info" style="padding:3px 8px;font-size:11px;" onclick="allPlayerMarkAi(<?= $player['id'] ?>, 'mark')">标记AI</button>
                                <?php endif; ?>
                                <button class="btn btn-danger" onclick="banUserByName('<?= htmlspecialchars($player['username']) ?>')">封禁</button>
                                <button class="btn btn-warning" onclick="imprisonUser('<?= htmlspecialchars($player['username']) ?>')">监禁</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- 巫师管理 -->
        <div id="tab-wizard" class="tab-content">
            <div class="card">
                <h2>巫师管理</h2>
                <div class="form-group">
                    <label>用户名</label>
                    <input type="text" id="wizard-username" placeholder="输入用户名">
                </div>
                <div class="form-group">
                    <label>巫师等级（只能设置比自己低的等级）</label>
                    <select id="wizard-level">
                        <?php foreach (WizardHelper::LEVEL_NAMES as $level => $name): ?>
                        <?php if ($level < $user['wizard_level']): ?>
                        <option value="<?= $level ?>"><?= $name ?> (<?= WizardHelper::getLevelTitle($level) ?>)</option>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-warning" onclick="setWizardLevel()">设置巫师等级</button>
                
                <h3 style="color: #e94560; margin: 25px 0 15px;">巫师等级说明</h3>
                <table>
                    <thead>
                        <tr>
                            <th>等级</th>
                            <th>名称</th>
                            <th>英文称号</th>
                            <th>权限说明</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>0</td><td>玩家</td><td>(player)</td><td>普通玩家，无管理权限</td></tr>
                        <tr><td>1</td><td>长老</td><td>(elder)</td><td>高级玩家，可使用goto等命令</td></tr>
                        <tr><td>2</td><td>神仙</td><td>(immortal)</td><td>初级巫师，可使用eval、summon等命令</td></tr>
                        <tr><td>3</td><td>学徒巫师</td><td>(apprentice)</td><td>学徒巫师，可使用ban、tojail等命令</td></tr>
                        <tr><td>4</td><td>巫师</td><td>(wizard)</td><td>正式巫师，可使用clone、update等命令</td></tr>
                        <tr><td>5</td><td>大巫师</td><td>(arch)</td><td>大巫师，可使用shutdown、wizlock、court等命令</td></tr>
                        <tr><td>6</td><td>管理员</td><td>(admin)</td><td>系统管理员，所有权限</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- 欢迎室管理 -->
        <div id="tab-guest" class="tab-content">
            <div class="card">
                <h2>欢迎室管理</h2>
                <p style="color: #aaa; margin-bottom: 15px;">欢迎室用于招待可疑IP的来访者，等待巫师审核后才能进入正常游戏。</p>
                
                <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px;">
                    <input type="text" id="guest-username" placeholder="输入用户名" style="width: 200px; padding: 10px; background: #0f3460; border: 1px solid #533483; color: #eee; border-radius: 5px;">
                    <input type="number" id="guest-days" placeholder="天数" value="2" min="1" max="30" style="width: 100px; padding: 10px; background: #0f3460; border: 1px solid #533483; color: #eee; border-radius: 5px;">
                    <input type="text" id="guest-reason" placeholder="原因" style="width: 300px; padding: 10px; background: #0f3460; border: 1px solid #533483; color: #eee; border-radius: 5px;">
                    <button class="btn btn-warning" onclick="toguest()">送入欢迎室</button>
                </div>
                
                <h3 style="color: #e94560; margin: 25px 0 15px;">当前欢迎室玩家</h3>
                <?php
                require_once __DIR__ . '/../commands/toguest.php';
                $guestPlayers = [];
                $guestDebug = '';
                try {
                    $guestPlayers = getGuestRoomPlayers();
                    $guestDebug .= "查询结果: " . count($guestPlayers) . " 条记录<br>";
                } catch (Exception $e) {
                    $guestDebug .= "查询错误: " . $e->getMessage() . "<br>";
                }
                // 调试: 直接查询数据库
                try {
                    $rawRows = Database::queryAll("SELECT * FROM guest_room_config ORDER BY id DESC LIMIT 10");
                    $guestDebug .= "表中总记录: " . count($rawRows) . " 条<br>";
                    if (!empty($rawRows)) {
                        $guestDebug .= "最新记录: status=" . ($rawRows[0]['status'] ?? 'null') . ", user_id=" . ($rawRows[0]['user_id'] ?? 'null') . "<br>";
                    }
                } catch (Exception $e) {
                    $guestDebug .= "原始查询错误: " . $e->getMessage() . "<br>";
                }
                ?>
                <div style="background: #0f3460; padding: 10px; margin-bottom: 15px; border-radius: 5px; font-size: 12px; color: #f39c12;">
                    <?= $guestDebug ?>
                    <button class="btn btn-info" onclick="location.reload()" style="padding: 3px 10px; font-size: 12px; margin-top: 5px;">刷新</button>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>用户名</th>
                            <th>角色名</th>
                            <th>进入时间</th>
                            <th>释放时间</th>
                            <th>原因</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // 如果getGuestRoomPlayers()返回空但表中有数据，用原始查询
                        if (empty($guestPlayers) && !empty($rawRows)) {
                            // 只取status=1的记录
                            $guestPlayers = array_filter($rawRows, function($r) { return ($r['status'] ?? 0) == 1; });
                            // 补充用户名和角色名
                            foreach ($guestPlayers as &$gp) {
                                if (empty($gp['username'])) {
                                    $u = Database::queryOne("SELECT username FROM users WHERE id = ?", [$gp['user_id']]);
                                    $gp['username'] = $u['username'] ?? '未知';
                                }
                                if (empty($gp['char_name'])) {
                                    $c = Database::queryOne("SELECT name FROM characters WHERE id = ?", [$gp['char_id']]);
                                    $gp['char_name'] = $c['name'] ?? '未知';
                                }
                            }
                            unset($gp);
                        }
                        ?>
                        <?php if (empty($guestPlayers)): ?>
                        <tr><td colspan="7" style="text-align: center; color: #aaa;">欢迎室暂无玩家</td></tr>
                        <?php else: ?>
                        <?php foreach ($guestPlayers as $gp): ?>
                        <tr>
                            <td><?= $gp['id'] ?></td>
                            <td><?= htmlspecialchars($gp['username']) ?></td>
                            <td><?= htmlspecialchars($gp['char_name']) ?></td>
                            <td><?= $gp['enter_time'] ?></td>
                            <td><?= date('Y-m-d H:i:s', strtotime($gp['enter_time']) + ($gp['days'] * 86400)) ?></td>
                            <td><?= htmlspecialchars($gp['reason']) ?></td>
                            <td>
                                <button class="btn btn-success" onclick="approveGuest('<?= htmlspecialchars($gp['username']) ?>')">批准</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- 公堂管理 -->
        <div id="tab-court" class="tab-content">
            <div class="card">
                <h2>公堂管理</h2>
                <p style="color: #aaa; margin-bottom: 15px;">公堂用于审判违规玩家，需要大巫师(arch)以上权限。</p>
                
                <?php if (WizardHelper::isArch($userId)): ?>
                <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px;">
                    <input type="text" id="court-username" placeholder="输入用户名" style="width: 200px; padding: 10px; background: #0f3460; border: 1px solid #533483; color: #eee; border-radius: 5px;">
                    <input type="text" id="court-reason" placeholder="逮捕原因" style="width: 300px; padding: 10px; background: #0f3460; border: 1px solid #533483; color: #eee; border-radius: 5px;">
                    <button class="btn btn-danger" onclick="courtArrest()">逮捕</button>
                </div>
                
                <h3 style="color: #e94560; margin: 25px 0 15px;">待审嫌疑人</h3>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>用户名</th>
                            <th>角色名</th>
                            <th>逮捕原因</th>
                            <th>逮捕时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        require_once __DIR__ . '/../commands/court.php';
                        $suspects = Database::queryAll(
                            "SELECT cs.*, u.username, c.name as char_name 
                             FROM court_suspects cs 
                             LEFT JOIN users u ON cs.user_id = u.id 
                             LEFT JOIN characters c ON cs.char_id = c.id 
                             WHERE cs.status = 1 
                             ORDER BY cs.arrest_time DESC"
                        ) ?: [];
                        ?>
                        <?php if (empty($suspects)): ?>
                        <tr><td colspan="6" style="text-align: center; color: #aaa;">暂无待审嫌疑人</td></tr>
                        <?php else: ?>
                        <?php foreach ($suspects as $s): ?>
                        <tr>
                            <td><?= $s['id'] ?></td>
                            <td><?= htmlspecialchars($s['username']) ?></td>
                            <td><?= htmlspecialchars($s['char_name']) ?></td>
                            <td><?= htmlspecialchars($s['reason']) ?></td>
                            <td><?= $s['arrest_time'] ?></td>
                            <td>
                                <button class="btn btn-info" onclick="courtTry(<?= $s['id'] ?>)">开始审理</button>
                                <button class="btn btn-success" onclick="courtRelease(<?= $s['id'] ?>)">释放</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <h3 style="color: #e94560; margin: 25px 0 15px;">审理中的案件</h3>
                <table>
                    <thead>
                        <tr>
                            <th>案件ID</th>
                            <th>被告</th>
                            <th>罪名</th>
                            <th>审判官</th>
                            <th>创建时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $cases = Database::queryAll(
                            "SELECT cc.*, u.username as defendant_name, u2.username as judge_name
                             FROM court_cases cc 
                             LEFT JOIN users u ON cc.defendant_id = u.id 
                             LEFT JOIN users u2 ON cc.judge_id = u2.id 
                             WHERE cc.status = 2 
                             ORDER BY cc.created_at DESC"
                        ) ?: [];
                        ?>
                        <?php if (empty($cases)): ?>
                        <tr><td colspan="6" style="text-align: center; color: #aaa;">暂无审理中的案件</td></tr>
                        <?php else: ?>
                        <?php foreach ($cases as $c): ?>
                        <tr>
                            <td><?= $c['id'] ?></td>
                            <td><?= htmlspecialchars($c['defendant_name']) ?></td>
                            <td><?= htmlspecialchars($c['charge']) ?></td>
                            <td><?= htmlspecialchars($c['judge_name']) ?></td>
                            <td><?= $c['created_at'] ?></td>
                            <td>
                                <button class="btn btn-warning" onclick="showVerdictForm(<?= $c['id'] ?>)">宣判</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- 宣判表单（默认隐藏） -->
                <div id="verdict-form" style="display: none; margin-top: 20px; padding: 20px; background: #0f3460; border-radius: 10px;">
                    <h3 style="color: #e94560; margin-bottom: 15px;">宣判</h3>
                    <input type="hidden" id="verdict-case-id">
                    <div class="form-group">
                        <label>判决类型</label>
                        <select id="verdict-type">
                            <option value="1">无罪释放</option>
                            <option value="2">警告</option>
                            <option value="3">监禁</option>
                            <option value="4">封禁</option>
                        </select>
                    </div>
                    <div class="form-group" id="verdict-days-group" style="display: none;">
                        <label>刑期（天）</label>
                        <input type="number" id="verdict-days" value="3" min="1" max="30">
                    </div>
                    <div class="form-group">
                        <label>判决说明</label>
                        <input type="text" id="verdict-notes" placeholder="输入判决说明">
                    </div>
                    <button class="btn btn-danger" onclick="courtVerdict()">确认宣判</button>
                    <button class="btn btn-info" onclick="hideVerdictForm()" style="margin-left: 10px;">取消</button>
                </div>
                
                <?php else: ?>
                <p style="color: #f39c12;">公堂功能需要大巫师(arch)以上权限才能使用。</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- 查询 -->
        <div id="tab-query" class="tab-content">
            <div class="card">
                <h2>玩家查询</h2>

                <h3 style="color: #e94560; margin-bottom: 10px;">查找玩家位置 (where)</h3>
                <div class="search-box">
                    <input type="text" id="where-query" placeholder="角色名/用户名 (留空列出全部在线)">
                    <button class="btn btn-info" onclick="cmdWhere()">查询</button>
                </div>
                <div id="where-result" style="background:#0f3460;padding:10px;border-radius:5px;margin-bottom:15px;display:none;white-space:pre-wrap;font-size:12px;"></div>

                <h3 style="color: #e94560; margin-bottom: 10px;">玩家详细信息 (whois)</h3>
                <div class="search-box">
                    <input type="text" id="whois-query" placeholder="用户名或角色名">
                    <button class="btn btn-info" onclick="cmdWhois()">查询</button>
                </div>
                <div id="whois-result" style="background:#0f3460;padding:10px;border-radius:5px;margin-bottom:15px;display:none;white-space:pre-wrap;font-size:12px;"></div>

                <h3 style="color: #e94560; margin-bottom: 10px;">同IP查询 (sameip)</h3>
                <div class="search-box">
                    <input type="text" id="sameip-query" placeholder="IP地址或用户名">
                    <button class="btn btn-info" onclick="cmdSameip()">查询</button>
                </div>
                <div id="sameip-result" style="background:#0f3460;padding:10px;border-radius:5px;margin-bottom:15px;display:none;white-space:pre-wrap;font-size:12px;"></div>
            </div>
        </div>

        <!-- 物品管理 -->
        <div id="tab-items" class="tab-content">
            <div class="card">
                <h2>物品管理</h2>

                <h3 style="color: #e94560; margin-bottom: 10px;">查看玩家物品 — 需要神仙(immortal)权限</h3>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px;">
                    <input type="text" id="player-items-char" placeholder="角色名" style="width:150px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <button class="btn btn-info" onclick="cmdListPlayerItems()">查看物品</button>
                </div>
                <div id="player-items-list" style="display:none;margin-bottom:15px;"></div>

                <h3 style="color: #e94560; margin-bottom: 10px;">查看房间物品 — 需要神仙(immortal)权限</h3>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px;">
                    <input type="text" id="room-items-area" placeholder="区域" style="width:120px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <input type="text" id="room-items-room" placeholder="房间ID" style="width:150px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <button class="btn btn-info" onclick="cmdListRoomItems()">查看物品</button>
                </div>
                <div id="room-items-list" style="display:none;margin-bottom:15px;"></div>

                <h3 style="color: #e94560; margin-bottom: 10px;">添加物品到玩家 — 需要巫师(wizard)权限</h3>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px;">
                    <input type="text" id="add-to-player-char" placeholder="角色名" style="width:120px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <input type="text" id="add-to-player-item" placeholder="物品ID" style="width:120px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <input type="text" id="add-to-player-category" placeholder="分类(可选)" style="width:100px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <input type="number" id="add-to-player-qty" placeholder="数量" value="1" min="1" style="width:80px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <button class="btn btn-success" onclick="cmdAddItemToPlayer()">添加</button>
                </div>

                <h3 style="color: #e94560; margin-bottom: 10px;">添加物品到房间 — 需要巫师(wizard)权限</h3>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px;">
                    <input type="text" id="add-to-room-area" placeholder="区域" style="width:100px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <input type="text" id="add-to-room-room" placeholder="房间ID" style="width:120px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <input type="text" id="add-to-room-item" placeholder="物品ID" style="width:120px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <input type="text" id="add-to-room-category" placeholder="分类(可选)" style="width:100px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <input type="number" id="add-to-room-qty" placeholder="数量" value="1" min="1" style="width:80px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <button class="btn btn-success" onclick="cmdAddItemToRoom()">放置</button>
                </div>

                <h3 style="color: #e94560; margin-bottom: 10px;">新建物品 — 需要大巫师(arch)权限</h3>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                    <input type="text" id="new-item-id" placeholder="物品ID" style="width:120px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <input type="text" id="new-item-name" placeholder="物品名称" style="width:150px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <select id="new-item-type" style="width:100px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                        <option value="misc">杂项</option>
                        <option value="weapon">武器</option>
                        <option value="armor">护甲</option>
                        <option value="cloth">衣服</option>
                        <option value="food">食物</option>
                        <option value="medicine">药品</option>
                        <option value="treasure">宝物</option>
                    </select>
                    <input type="text" id="new-item-category" placeholder="区域分类(可选)" style="width:100px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <input type="number" id="new-item-level" placeholder="等级" value="1" min="1" style="width:80px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <input type="number" id="new-item-value" placeholder="价值" value="0" min="0" style="width:80px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <button class="btn btn-warning" onclick="cmdCreateItem()">创建</button>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px;">
                    <input type="text" id="new-item-desc" placeholder="物品描述(可选)" style="width:650px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                </div>

                <h3 style="color: #e94560; margin-bottom: 10px;">快速销毁 (smash) — 需要神仙(immortal)权限</h3>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px;">
                    <input type="text" id="smash-inv" placeholder="背包物品ID" style="width:150px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <button class="btn btn-danger" onclick="cmdSmash()">销毁背包物品</button>
                    <input type="text" id="smash-room" placeholder="房间物品ID" style="width:150px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <button class="btn btn-danger" onclick="cmdSmashRoom()">销毁房间物品</button>
                </div>
            </div>
        </div>

        <!-- NPC管理 -->
        <div id="tab-npcs" class="tab-content">
            <div class="card">
                <h2>NPC管理</h2>

                <h3 style="color: #e94560; margin-bottom: 10px;">NPC列表</h3>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px;">
                    <input type="text" id="npc-search" placeholder="搜索NPC标识/名称/称号" style="width:250px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <button class="btn btn-info" onclick="cmdSearchNpc()">搜索</button>
                    <button class="btn btn-info" onclick="loadNpcs()">加载NPC列表</button>
                    <button class="btn btn-success" onclick="showCreateNpc()">新建NPC</button>
                </div>
                <div id="npc-list" style="background:#0f3460;padding:10px;border-radius:5px;margin-bottom:15px;max-height:400px;overflow-y:auto;"></div>
                <div id="npc-pagination" style="margin-bottom:15px;text-align:center;"></div>

                <h3 style="color: #e94560; margin-bottom: 10px;">新建/编辑NPC</h3>
                <div id="npc-form" style="display:none;">
                    <input type="hidden" id="npc-edit-id">
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                        <input type="text" id="npc-id" placeholder="NPC标识" style="width:150px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                        <input type="text" id="npc-name" placeholder="NPC名称" style="width:150px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                        <input type="text" id="npc-title" placeholder="称号(可选)" style="width:150px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                        <select id="npc-race" style="width:100px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                            <option value="human">人类</option>
                            <option value="monster">野兽</option>
                            <option value="demon">妖魔</option>
                            <option value="god">神仙</option>
                        </select>
                        <select id="npc-class" style="width:120px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                            <option value="">无</option>
                            <option value="xian">神仙</option>
                            <option value="bonze">和尚</option>
                            <option value="taoist">道士</option>
                            <option value="general">将军</option>
                            <option value="scholar">书生</option>
                            <option value="merchant">商人</option>
                            <option value="beggar">乞丐</option>
                            <option value="yaomo">妖魔</option>
                            <option value="beast">兽类</option>
                        </select>
                        <select id="npc-gender" style="width:80px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                            <option value="male">男</option>
                            <option value="female">女</option>
                            <option value="unknown">未知</option>
                        </select>
                        <select id="npc-attitude" style="width:100px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                            <option value="friendly">友好</option>
                            <option value="aggressive">敌对</option>
                            <option value="cooperative">合作</option>
                        </select>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                        <input type="text" id="npc-spawn-area" placeholder="出现区域(可选)" style="width:150px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                        <input type="text" id="npc-spawn-room" placeholder="出现房间(可选)" style="width:200px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px;">
                        <textarea id="npc-description" placeholder="NPC描述(可选)" style="width:500px;height:60px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;"></textarea>
                    </div>
                    <button class="btn btn-success" onclick="cmdSaveNpc()">保存</button>
                    <button class="btn btn-secondary" onclick="hideNpcForm()">取消</button>
                </div>
            </div>
        </div>

        <!-- 系统管理 -->
        <div id="tab-system" class="tab-content">
            <div class="card">
                <h2>系统管理</h2>

                <h3 style="color: #e94560; margin-bottom: 10px;">传送 (goto) / 召唤 (summon)</h3>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px;">
                    <input type="text" id="goto-target" placeholder="角色名 或 area room_id" style="width:250px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <button class="btn btn-info" onclick="cmdGoto()">传送</button>
                    <button class="btn btn-warning" onclick="cmdSummon()">召唤到身边</button>
                </div>

                <h3 style="color: #e94560; margin-bottom: 10px;">玩家状态管理 — 需要神仙(immortal)权限</h3>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px;">
                    <input type="text" id="status-target" placeholder="角色名" style="width:150px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <button class="btn btn-danger" onclick="cmdStopCombat()">停止战斗</button>
                    <button class="btn btn-success" onclick="cmdClearPoison()">消除中毒</button>
                    <button class="btn btn-info" onclick="cmdClearDrunk()">消除酒醉</button>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px;">
                    <select id="buff-type" style="padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                        <option value="poison">中毒</option>
                        <option value="snake_poison">蛇毒</option>
                        <option value="ice_poison">寒毒</option>
                        <option value="drunk">醉酒</option>
                        <option value="stun">眩晕</option>
                        <option value="attack_up">攻击强化</option>
                        <option value="defense_up">防御强化</option>
                        <option value="regen">回复</option>
                        <option value="bandaged">绷带疗伤</option>
                        <option value="powerup">运功强化</option>
                        <option value="heal">疗伤中</option>
                        <option value="slow">迟缓</option>
                        <option value="dodge_up">闪避强化</option>
                        <option value="weaken">虚弱</option>
                        <option value="killer">杀手标记</option>
                        <option value="slumber_drug">蒙汗药</option>
                    </select>
                    <input type="number" id="buff-value" placeholder="效果值" style="width:80px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;" value="10">
                    <input type="number" id="buff-duration" placeholder="持续回合" style="width:80px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;" value="10">
                    <button class="btn btn-warning" onclick="cmdAddBuff()">添加Buff</button>
                    <button class="btn btn-danger" onclick="cmdRemoveBuff()">消除Buff</button>
                    <button class="btn btn-info" onclick="cmdListBuffs()">查看Buff</button>
                    <button class="btn btn-secondary" onclick="cmdClearAllBuffs()">清除全部</button>
                </div>

                <h3 style="color: #e94560; margin-bottom: 10px;">玩家管理 — 需要管理员(admin)权限</h3>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px;">
                    <input type="text" id="delete-player-target" placeholder="角色名 或 用户名" style="width:200px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <button class="btn btn-danger" onclick="cmdDeletePlayer()">删除玩家</button>
                    <span style="color:#aaa;font-size:12px;line-height:36px;">删除角色及其所有数据（不可恢复）</span>
                </div>

                <h3 style="color: #e94560; margin-bottom: 10px;">技能管理 (setskill) — 需要大巫师(arch)权限</h3>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px;">
                    <input type="text" id="ss-char" placeholder="角色名" style="width:120px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <input type="text" id="ss-skill" placeholder="技能ID" style="width:100px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <input type="text" id="ss-level" placeholder="等级/remove" style="width:100px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <button class="btn btn-warning" onclick="cmdSetskill()">设置</button>
                    <button class="btn btn-info" onclick="cmdSetskillList()">列出技能</button>
                </div>

                <h3 style="color: #e94560; margin-bottom: 10px;">功能封禁 (block) — 需要巫师(wizard)权限</h3>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px;">
                    <input type="text" id="block-user" placeholder="用户名" style="width:120px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <select id="block-feature" style="padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                        <option value="chat">聊天</option>
                        <option value="pk">PK</option>
                        <option value="trade">交易</option>
                        <option value="move">移动</option>
                    </select>
                    <input type="text" id="block-reason" placeholder="原因" style="width:150px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <button class="btn btn-danger" onclick="cmdBlock()">封锁</button>
                    <button class="btn btn-success" onclick="cmdUnblock()">解封</button>
                    <button class="btn btn-info" onclick="cmdBlockList()">查看</button>
                </div>

                <h3 style="color: #e94560; margin-bottom: 10px;">消息监控 (snoop) / 日志查看 (tail)</h3>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px;">
                    <input type="text" id="snoop-target" placeholder="角色名" style="width:120px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <button class="btn btn-info" onclick="cmdSnoop()">查看消息</button>
                    <input type="text" id="tail-type" placeholder="日志类型(可选)" style="width:120px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <input type="text" id="tail-lines" placeholder="行数" value="50" style="width:80px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <button class="btn btn-info" onclick="cmdTail()">查看日志</button>
                </div>
                <div id="snoop-result" style="background:#0f3460;padding:10px;border-radius:5px;margin-bottom:15px;display:none;white-space:pre-wrap;font-size:12px;max-height:300px;overflow-y:auto;"></div>

                <h3 style="color: #e94560; margin-bottom: 10px;">服务器维护 (wizlock/shutdown) — 需要大巫师(arch)权限</h3>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
                    <button class="btn btn-info" onclick="cmdWizlock('status')">查看维护状态</button>
                    <input type="text" id="shutdown-minutes" placeholder="分钟" value="10" style="width:80px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <input type="text" id="shutdown-reason" placeholder="维护原因" value="例行维护" style="width:200px;padding:8px;background:#0f3460;border:1px solid #533483;color:#eee;border-radius:4px;">
                    <button class="btn btn-danger" onclick="cmdShutdown()">设置维护</button>
                    <button class="btn btn-success" onclick="cmdShutdownCancel()">取消维护</button>
                </div>

                <?php if ($user['wizard_level'] >= WizardHelper::LEVEL_ARCH): ?>
                <h3 style="color: #e94560; margin: 25px 0 10px;">玩家数据导出 — 需要大巫师(arch)权限</h3>
                <div style="background: #0f3460; padding: 15px; border-radius: 10px; margin-bottom: 15px;">
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 8px;">导出玩家数据</label>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                            <input type="text" id="export-player-name" placeholder="输入玩家名/角色名（留空导出全部）" style="width: 250px; padding: 8px; background: #1a1a2e; border: 1px solid #533483; color: #eee; border-radius: 4px;">
                            <select id="export-format" style="padding: 8px; background: #1a1a2e; border: 1px solid #533483; color: #eee; border-radius: 4px;">
                                <!-- <option value="json">JSON格式</option> [暂时注释] JSON导出待修复 -->
                                <option value="sql">SQL格式(INSERT)</option>
                            </select>
                            <button class="btn btn-info" onclick="exportPlayerData()">导出所选玩家</button>
                            <button class="btn btn-info" onclick="exportOnlinePlayerData()">导出在线玩家</button>
                        </div>
                    </div>
                    
                    <!-- [暂时注释] 导入玩家数据功能 — 待 PHP 配置问题修复后可重新启用
                    <div style="border-top: 1px solid #533483; padding-top: 15px;">
                        <label style="display: block; margin-bottom: 8px;">导入玩家数据</label>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <input type="file" id="import-file" accept=".json" style="display: none;" onchange="handleImportFile()">
                            <button class="btn btn-warning" onclick="document.getElementById('import-file').click()">选择JSON文件</button>
                            <span id="import-file-name" style="color: #aaa;">未选择文件</span>
                            <button class="btn btn-success" onclick="importPlayerData()">开始导入</button>
                        </div>
                        <p style="color: #f39c12; font-size: 12px; margin-top: 8px;">⚠️ 警告：导入操作将覆盖现有数据，请谨慎操作！</p>
                    </div>
                    -->
                </div>
                <div id="import-result" style="display: none; padding: 10px; border-radius: 5px; margin-bottom: 15px;"></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 表情管理 -->
        <div id="tab-emote" class="tab-content">
            <div class="card">
                <h2>表情管理</h2>
                <p style="color: #aaa; margin-bottom: 15px;">管理游戏中的表情动作(emote)，包括7个视角的描述字段。</p>
                
                <?php if ($user['wizard_level'] >= WizardHelper::LEVEL_IMMORTAL): ?>
                <!-- 添加/编辑表情表单 -->
                <h3 style="color: #e94560; margin: 25px 0 15px;">添加 / 编辑表情</h3>
                <div style="background: #0f3460; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                    <input type="hidden" id="emote-edit-mode" value="add">
                    <div class="form-group">
                        <label>表情命令 (command)</label>
                        <input type="text" id="emote-command" placeholder="如: smile, hug, bow" style="width: 100%;">
                    </div>
                    <div class="form-group">
                        <label>描述 (description)</label>
                        <input type="text" id="emote-desc" placeholder="如: 微笑, 拥抱" style="width: 100%;">
                    </div>
                    <div class="form-group">
                        <label>myself - 自己无目标时看到</label>
                        <input type="text" id="emote-myself" placeholder="\$P愉快地微笑着。" style="width: 100%;">
                        <span style="font-size: 11px; color: #888;">变量: \$N=施动者, \$P=施动者代词, \$n=目标, \$p=目标代词</span>
                    </div>
                    <div class="form-group">
                        <label>myself_target - 自己对他人的描述</label>
                        <input type="text" id="emote-myself-target" placeholder="\$P对着\$n愉快地微笑着。" style="width: 100%;">
                    </div>
                    <div class="form-group">
                        <label>myself_self - 自己对自己的描述</label>
                        <input type="text" id="emote-myself-self" placeholder="" style="width: 100%;">
                    </div>
                    <div class="form-group">
                        <label>target - 目标看到的描述</label>
                        <input type="text" id="emote-target" placeholder="\$N对着\$p愉快地微笑着。" style="width: 100%;">
                    </div>
                    <div class="form-group">
                        <label>others - 旁人无目标时看到</label>
                        <input type="text" id="emote-others" placeholder="\$N愉快地微笑着。" style="width: 100%;">
                    </div>
                    <div class="form-group">
                        <label>others_target - 旁人有目标时看到</label>
                        <input type="text" id="emote-others-target" placeholder="\$N对着\$n愉快地微笑着。" style="width: 100%;">
                    </div>
                    <div class="form-group">
                        <label>others_self - 旁人看到自己对自己时</label>
                        <input type="text" id="emote-others-self" placeholder="" style="width: 100%;">
                    </div>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <div class="form-group" style="flex: 1; min-width: 120px;">
                            <label>排序 (sort_order)</label>
                            <input type="number" id="emote-sort" value="0" style="width: 100%;">
                        </div>
                        <div class="form-group" style="flex: 1; min-width: 120px;">
                            <label>启用状态</label>
                            <select id="emote-active" style="width: 100%;">
                                <option value="1">启用</option>
                                <option value="0">禁用</option>
                            </select>
                        </div>
                    </div>
                    <div style="margin-top: 10px; display: flex; gap: 10px;">
                        <button class="btn btn-primary" onclick="saveEmote()">保存表情</button>
                        <button class="btn btn-secondary" onclick="resetEmoteForm()">重置表单</button>
                    </div>
                    <div id="emote-form-msg" style="margin-top: 10px; display: none;"></div>
                </div>
                <?php endif; ?>
                
                <!-- 表情列表 -->
                <h3 style="color: #e94560; margin: 25px 0 15px;">所有表情</h3>
                <div style="margin-bottom: 15px;">
                    <button class="btn btn-secondary" onclick="loadEmoteList()">刷新列表</button>
                    <span id="emote-count" style="color: #aaa; margin-left: 10px;"></span>
                </div>
                <div style="max-height: 500px; overflow-y: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>命令</th>
                                <th>描述</th>
                                <th>更新者</th>
                                <th>状态</th>
                                <th>排序</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="emote-list-body">
                            <tr><td colspan="6" style="text-align: center; color: #666;">点击"刷新列表"加载...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- 新闻公告管理 -->
        <div id="tab-news" class="tab-content">
            <div class="card">
                <h2>新闻公告管理</h2>
                
                <?php if ($user['wizard_level'] >= WizardHelper::LEVEL_ARCH): ?>
                <!-- 添加新闻表单 -->
                <h3 style="color: #e94560; margin: 25px 0 15px;">发布新公告 <span style="font-size: 12px; color: #aaa;">(发布后将向所有在线玩家发送通知)</span></h3>
                <div style="background: #0f3460; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                    <input type="hidden" id="news-id" value="0">
                    <div class="form-group">
                        <label>标题</label>
                        <input type="text" id="news-title" placeholder="输入新闻标题" style="width: 100%;">
                    </div>
                    <div class="form-group">
                        <label>内容</label>
                        <textarea id="news-content" placeholder="输入新闻内容（支持换行）" rows="6" style="width: 100%; resize: vertical;"></textarea>
                    </div>
                    <div class="form-group" style="display: flex; gap: 20px; flex-wrap: wrap;">
                        <label style="display: flex; align-items: center; gap: 5px;">
                            <input type="checkbox" id="news-is-latest" value="1"> 设为最新
                        </label>
                        <label>
                            排序优先级: <input type="number" id="news-sort-order" value="0" min="0" max="100" style="width: 60px;">
                        </label>
                    </div>
                    <div style="display: flex; gap: 10px; margin-top: 15px;">
                        <button class="btn btn-success" onclick="saveNews()">保存</button>
                        <button class="btn btn-info" onclick="resetNewsForm()">重置</button>
                    </div>
                </div>
                <?php else: ?>
                <p style="color: #f39c12; margin: 20px 0;">新闻公告管理需要大巫师(arch)以上权限才能使用。</p>
                <?php endif; ?>
                
                <!-- 新闻列表 -->
                <h3 style="color: #e94560; margin: 25px 0 15px;">公告列表</h3>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>标题</th>
                            <th>状态</th>
                            <th>排序</th>
                            <th>创建时间</th>
                            <th>更新时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="news-list-body">
                        <?php
                        $newsList = Database::queryAll("SELECT * FROM news ORDER BY sort_order DESC, created_at DESC");
                        if (empty($newsList)): ?>
                        <tr><td colspan="7" style="text-align: center; color: #aaa;">暂无新闻公告</td></tr>
                        <?php else: ?>
                        <?php foreach ($newsList as $news): ?>
                        <tr>
                            <td><?= $news['id'] ?></td>
                            <td><?= htmlspecialchars($news['title']) ?></td>
                            <td><?= $news['is_latest'] ? '<span style="color: green;">最新</span>' : '<span style="color: #aaa;">普通</span>' ?></td>
                            <td><?= $news['sort_order'] ?></td>
                            <td><?= $news['created_at'] ?></td>
                            <td><?= $news['updated_at'] ?></td>
                            <td class="action-btns">
                                <button class="btn btn-info" onclick="editNews(<?= htmlspecialchars(json_encode($news)) ?>)">编辑</button>
                                <button class="btn btn-danger" onclick="deleteNews(<?= $news['id'] ?>, '<?= htmlspecialchars($news['title']) ?>')">删除</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- AI 玩家管理 -->
        <div id="tab-ai" class="tab-content">
            <div class="card">
                <h2>🤖 AI 玩家管理</h2>
                <p style="color: #aaa; margin-bottom: 15px;">管理游戏中的 AI 玩家，包括上线/下线、创建新 AI、手动触发行为等。</p>
                
                <!-- AI 玩家统计 -->
                <div class="stats" style="margin-bottom: 20px;">
                    <div class="stat-card" style="background: #1a1a2e;">
                        <div class="number" id="ai-total-count">-</div>
                        <div class="label">AI 玩家总数</div>
                    </div>
                    <div class="stat-card" style="background: #1a1a2e;">
                        <div class="number" id="ai-online-count">-</div>
                        <div class="label">在线 AI</div>
                    </div>
                    <div class="stat-card" style="background: #1a1a2e;" id="ai-paused-card">
                        <div class="number" id="ai-paused-count">-</div>
                        <div class="label">已暂停</div>
                    </div>
                </div>
                
                <!-- 快速操作 -->
                <h3 style="color: #e94560; margin: 20px 0 10px;">快速操作</h3>
                <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px;">
                    <button class="btn btn-success" onclick="aiPlayerAction('tick')">🔄 手动触发 Tick</button>
                    <button class="btn btn-info" onclick="showCreateAiDialog()">➕ 创建 AI 玩家</button>
                    <button class="btn btn-warning" id="btn-pause-all" onclick="aiPlayerAction('pause_all')">⏸️ 全部暂停</button>
                    <button class="btn btn-success" id="btn-resume-all" onclick="aiPlayerAction('resume_all')">▶️ 全部恢复</button>
                </div>
                
                <!-- 自动 Tick 控制 -->
                <div style="background: #0f3460; padding: 12px 15px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <span style="color: #ccc; font-weight: bold;">自动 Tick 驱动：</span>
                    <span id="auto-tick-status" style="color: #0f0;">● 运行中</span>
                    <span style="color: #aaa; font-size: 12px;">（每5秒自动处理在线 AI 玩家）</span>
                    <button class="btn btn-danger" id="btn-auto-tick-stop" onclick="stopAiAutoTick()" style="padding: 4px 12px; font-size: 12px;">⏹ 停止自动</button>
                    <button class="btn btn-success" id="btn-auto-tick-start" onclick="startAiAutoTick()" style="padding: 4px 12px; font-size: 12px; display: none;">▶ 启动自动</button>
                </div>
                
                <!-- 创建 AI 对话框 -->
                <div id="create-ai-dialog" style="display:none; background: #0f3460; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                    <h4 style="color: #e94560; margin-bottom: 10px;">创建新 AI 玩家</h4>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                        <div>
                            <label style="display:block; color: #aaa; font-size: 12px; margin-bottom: 3px;">角色名</label>
                            <input type="text" id="ai-new-name" placeholder="输入角色名" style="width: 150px; padding: 8px; background: #16213e; border: 1px solid #533483; color: #eee; border-radius: 5px;">
                        </div>
                        <div>
                            <label style="display:block; color: #aaa; font-size: 12px; margin-bottom: 3px;">性别</label>
                            <select id="ai-new-gender" style="width: 100px; padding: 8px; background: #16213e; border: 1px solid #533483; color: #eee; border-radius: 5px;">
                                <option value="male">男</option>
                                <option value="female">女</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; color: #aaa; font-size: 12px; margin-bottom: 3px;">种族</label>
                            <select id="ai-new-race" style="width: 120px; padding: 8px; background: #16213e; border: 1px solid #533483; color: #eee; border-radius: 5px;">
                                <option value="human">人族</option>
                                <option value="demon">妖族</option>
                                <option value="immortal">仙族</option>
                                <option value="monster">魔族</option>
                            </select>
                        </div>
                        <button class="btn btn-success" onclick="createAiPlayer()">创建并上线</button>
                        <button class="btn" style="background:#555; color:#fff;" onclick="document.getElementById('create-ai-dialog').style.display='none'">取消</button>
                    </div>
                </div>
                
                <!-- AI 玩家列表 -->
                <h3 style="color: #e94560; margin: 20px 0 10px;">AI 玩家列表</h3>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>名称</th>
                            <th>种族</th>
                            <th>在线</th>
                            <th>状态</th>
                            <th>HP</th>
                            <th>当前位置</th>
                            <th>最后动作</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="ai-player-list-body">
                        <tr><td colspan="9" style="text-align: center; color: #aaa;">加载中...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
    // ========== 所有玩家列表 - 标记/取消 AI ==========
    function allPlayerMarkAi(charId, action) {
        var msg = action === 'mark' ? '确定要将此角色标记为 AI 玩家吗？' : '确定要取消此角色的 AI 标记吗？';
        if (!confirm(msg)) return;
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'ai_player_' + action, char_id: charId})
        })
        .then(r => r.json())
        .then(data => {
            alert(data.message || (data.success ? '操作成功' : '操作失败'));
            if (data.success) {
                location.reload();
            }
        });
    }
    
    // ========== AI 玩家管理 JS ==========
    function loadAiPlayerStatus() {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'ai_player_status'})
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            var online = data.ai_players.filter(function(p){return p.online==1;}).length;
            var paused = data.ai_players.filter(function(p){return p.ai_paused==1;}).length;
            document.getElementById('ai-total-count').textContent = data.count;
            document.getElementById('ai-online-count').textContent = online;
            document.getElementById('ai-paused-count').textContent = paused;
            
            // 根据暂停状态调整样式
            var pausedCard = document.getElementById('ai-paused-card');
            var pausedNum = document.getElementById('ai-paused-count');
            if (paused > 0) {
                pausedCard.style.background = '#1a1a2e';
                pausedCard.style.border = '1px solid #e74c3c';
                pausedNum.style.color = '#e74c3c';
            } else {
                pausedCard.style.background = '#1a1a2e';
                pausedCard.style.border = '1px solid #333';
                pausedNum.style.color = '#aaa';
            }
            
            var tbody = document.getElementById('ai-player-list-body');
            if (data.ai_players.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#aaa;">暂无 AI 玩家</td></tr>';
                return;
            }
            var html = '';
            for (var i = 0; i < data.ai_players.length; i++) {
                var p = data.ai_players[i];
                var onlineTag = p.online == 1 ? '<span style="color:green;">在线</span>' : '<span style="color:#aaa;">离线</span>';
                var pausedTag = p.ai_paused == 1 ? '<span style="color:#e74c3c;">⏸ 已暂停</span>' : '<span style="color:#2ecc71;">▶ 活跃</span>';
                var hpBar = '<span style="color:' + (p.max_kee > 0 && p.kee/p.max_kee < 0.4 ? 'red' : 'lime') + '">' + p.kee + '/' + p.max_kee + '</span>';
                var lastAction = p.seconds_ago < 0 ? '从未' : (p.seconds_ago < 60 ? p.seconds_ago + '秒前' : Math.floor(p.seconds_ago/60) + '分前');
                var pos = (p.current_area||'') + '/' + (p.current_room||'');
                var btns = '';
                if (p.online == 1) {
                    if (p.ai_paused == 1) {
                        btns += '<button class="btn btn-success" style="padding:3px 8px;font-size:11px;" onclick="aiPlayerAction(\'resume\',' + p.id + ')">恢复</button> ';
                    } else {
                        btns += '<button class="btn btn-warning" style="padding:3px 8px;font-size:11px;" onclick="aiPlayerAction(\'pause\',' + p.id + ')">暂停</button> ';
                    }
                    btns += '<button class="btn btn-danger" style="padding:3px 8px;font-size:11px;" onclick="aiPlayerAction(\'logout\',' + p.id + ')">下线</button> ';
                } else {
                    btns += '<button class="btn btn-success" style="padding:3px 8px;font-size:11px;" onclick="aiPlayerAction(\'login\',' + p.id + ')">上线</button> ';
                }
                if (p.is_ai_player == 1) {
                    btns += '<button class="btn btn-info" style="padding:3px 8px;font-size:11px;" onclick="aiPlayerAction(\'unmark\',' + p.id + ')">取消AI</button>';
                } else {
                    btns += '<button class="btn btn-info" style="padding:3px 8px;font-size:11px;" onclick="aiPlayerAction(\'mark\',' + p.id + ')">标记AI</button>';
                }
                html += '<tr>';
                html += '<td>' + p.id + '</td>';
                html += '<td>' + escHtml(p.name) + '</td>';
                html += '<td>' + escHtml(p.race||'') + '</td>';
                html += '<td>' + onlineTag + '</td>';
                html += '<td>' + pausedTag + '</td>';
                html += '<td>' + hpBar + '</td>';
                html += '<td style="font-size:11px;">' + escHtml(pos) + '</td>';
                html += '<td style="font-size:11px;">' + lastAction + '</td>';
                html += '<td class="action-btns">' + btns + '</td>';
                html += '</tr>';
            }
            tbody.innerHTML = html;
        });
    }
    
    function aiPlayerAction(action, charId) {
        var body = {action: 'ai_player_' + action};
        if (charId) body.char_id = charId;
        if (action === 'tick') {
            body = {action: 'ai_player_tick'};
        }
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body)
        })
        .then(r => r.json())
        .then(data => {
            if (action === 'tick') {
                var res = data.result || {};
                var msg = 'Tick 完成！处理了 ' + (res.processed||0) + ' 个 AI 玩家';
                if (res.results && res.results.length > 0) {
                    msg += '\\n' + res.results.map(function(r){
                        return (r.success ? '✓' : '✗') + ' ' + (r.char_name||'?') + ' -> ' + (r.ai_detail||r.message||'');
                    }).join('\\n');
                }
                alert(msg);
            } else {
                alert(data.message || (data.success ? '操作成功' : '操作失败'));
            }
            loadAiPlayerStatus();
        });
    }
    
    function showCreateAiDialog() {
        document.getElementById('create-ai-dialog').style.display = 'block';
    }
    
    function createAiPlayer() {
        var name = document.getElementById('ai-new-name').value.trim();
        if (!name) { alert('请输入角色名'); return; }
        var gender = document.getElementById('ai-new-gender').value;
        var race = document.getElementById('ai-new-race').value;
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'ai_player_create', name: name, gender: gender, race: race})
        })
        .then(r => r.json())
        .then(data => {
            alert(data.message || (data.success ? '创建成功' : '创建失败'));
            if (data.success) {
                document.getElementById('create-ai-dialog').style.display = 'none';
                document.getElementById('ai-new-name').value = '';
            }
            loadAiPlayerStatus();
        });
    }
    
    function escHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    
    // AI 玩家自动驱动定时器：每 5 秒触发一次 tick，处理在线 AI 玩家
    // 状态持久化在服务端文件 data/ai_auto_tick_state.json，刷新不掉
    var aiAutoTickTimer = null;
    var aiAutoTickRunning = false;

    function syncServerTickState(running, cb) {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'ai_player_auto_tick_state', running: running})
        })
        .then(function(r) { return r.json(); })
        .then(function(data) { if (cb) cb(data); })
        .catch(function(){ if (cb) cb(null); });
    }

    function startAiAutoTick() {
        if (aiAutoTickRunning) return;
        // 先停掉可能存在的旧定时器
        if (aiAutoTickTimer) { clearInterval(aiAutoTickTimer); aiAutoTickTimer = null; }
        aiAutoTickRunning = true;
        syncServerTickState(true);
        aiAutoTickTimer = setInterval(function() {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'ai_player_tick'})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.result && data.result.total > 0) {
                    loadAiPlayerStatus();
                }
            })
            .catch(function(){});
        }, 5000);
        // 更新 UI
        document.getElementById('auto-tick-status').textContent = '\u25cf 运行中';
        document.getElementById('auto-tick-status').style.color = '#0f0';
        document.getElementById('btn-auto-tick-stop').style.display = '';
        document.getElementById('btn-auto-tick-start').style.display = 'none';
    }
    function stopAiAutoTick() {
        if (aiAutoTickTimer) { clearInterval(aiAutoTickTimer); aiAutoTickTimer = null; }
        aiAutoTickRunning = false;
        syncServerTickState(false);
        // 更新 UI
        document.getElementById('auto-tick-status').textContent = '\u25cf 已停止';
        document.getElementById('auto-tick-status').style.color = '#e94560';
        document.getElementById('btn-auto-tick-stop').style.display = 'none';
        document.getElementById('btn-auto-tick-start').style.display = '';
    }
    // 页面加载时从服务端读取状态，决定是否启动自动 Tick
    (function initAutoTick() {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'ai_player_auto_tick_state'})
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data && data.success && data.running) {
                startAiAutoTick();
            } else {
                // 服务端说已停止，显示停止状态（不调用 stopAiAutoTick 避免再次写服务端）
                document.getElementById('auto-tick-status').textContent = '\u25cf 已停止';
                document.getElementById('auto-tick-status').style.color = '#e94560';
                document.getElementById('btn-auto-tick-stop').style.display = 'none';
                document.getElementById('btn-auto-tick-start').style.display = '';
            }
        })
        .catch(function() {
            // 网络异常时默认不启动（安全优先），但 UI 显示停止
            document.getElementById('auto-tick-status').textContent = '\u25cf 已停止';
            document.getElementById('auto-tick-status').style.color = '#e94560';
            document.getElementById('btn-auto-tick-stop').style.display = 'none';
            document.getElementById('btn-auto-tick-start').style.display = '';
        });
    })();
    // 页面关闭/刷新时仅清理客户端定时器，不改变服务端状态
    window.addEventListener('beforeunload', function() {
        if (aiAutoTickTimer) { clearInterval(aiAutoTickTimer); aiAutoTickTimer = null; }
    });
    </script>
    
    <script src="../assets/js/admin.js"></script>
</body>
</html>
<?php
// ============================================================
// 以下是游戏内命令函数 (cmd_admin)，供 ActionRouter 调用
// 当本文件被 require_once 且定义了 CMD_ADMIN_MODE 常量时，
// 跳过顶部 Web 面板初始化逻辑
// ============================================================

/**
 * admin 命令入口
 * @param int $charId 执行者角色ID
 * @param string $param 参数字符串: "<子命令> [参数...]"
 * @return array
 */
function cmd_admin(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在。'];
    }

    $userId = intval($char['user_id']);
    $userLevel = WizardHelper::getWizardLevel($userId);

    // 必须是巫师以上才能使用 admin 命令
    if ($userLevel < WizardHelper::LEVEL_IMMORTAL) {
        return ['success' => false, 'message' => '你没有权限使用管理命令。需要神仙(immortal)以上权限。'];
    }

    $parts = preg_split('/\s+/', trim($param), 2);
    $subCommand = $parts[0] ?? '';
    $subParam = $parts[1] ?? '';

    if (empty($subCommand) || $subCommand === 'help') {
        return _adminShowHelp($userLevel);
    }

    switch ($subCommand) {
        // === 传送命令 (elder+) ===
        case 'goto':
            return _adminDelegate('goto', $charId, $subParam, $userId, WizardHelper::LEVEL_ELDER, 'goto');

        // === 召唤命令 (immortal+) ===
        case 'summon':
            return _adminDelegate('summon', $charId, $subParam, $userId, WizardHelper::LEVEL_IMMORTAL, 'summon');

        // === 克隆物品 (wizard+) ===
        case 'clone':
            return _adminDelegate('clone', $charId, $subParam, $userId, WizardHelper::LEVEL_WIZARD, 'clone');

        // === 销毁物品 (wizard+) ===
        case 'dest':
            return _adminDelegate('dest', $charId, $subParam, $userId, WizardHelper::LEVEL_WIZARD, 'dest');

        // === 快速监禁 (wizard+) ===
        case 'tojail':
            return _adminDelegate('tojail', $charId, $subParam, $userId, WizardHelper::LEVEL_WIZARD, 'tojail');

        // === 释放监禁 (wizard+) ===
        case 'release':
            return _adminHandleRelease($charId, $subParam, $userId);

        // === 欢迎室管理 (wizard+) ===
        case 'toguest':
            return _adminDelegate('toguest', $charId, $subParam, $userId, WizardHelper::LEVEL_WIZARD, 'toguest');

        // === 封禁管理 (wizard+) ===
        case 'ban':
            return _adminDelegate('ban', $charId, $subParam, $userId, WizardHelper::LEVEL_WIZARD, 'ban');

        // === 维护模式 (arch+) ===
        case 'wizlock':
            return _adminDelegate('wizlock', $charId, $subParam, $userId, WizardHelper::LEVEL_ARCH, 'wizlock');

        // === 关闭服务器 (arch+) ===
        case 'shutdown':
            return _adminDelegate('shutdown', $charId, $subParam, $userId, WizardHelper::LEVEL_ARCH, 'shutdown');

        // === 同IP检测 (immortal+) ===
        case 'sameip':
            return _adminDelegate('sameip', $charId, $subParam, $userId, WizardHelper::LEVEL_IMMORTAL, 'sameip');

        // === 用户详情 (immortal+) ===
        case 'whois':
            return _adminDelegate('whois', $charId, $subParam, $userId, WizardHelper::LEVEL_IMMORTAL, 'whois');

        // === 查看玩家行为 (wizard+) ===
        case 'snoop':
            return _adminDelegate('snoop', $charId, $subParam, $userId, WizardHelper::LEVEL_WIZARD, 'snoop');

        // === 持续跟踪 (wizard+) ===
        case 'tail':
            return _adminDelegate('tail', $charId, $subParam, $userId, WizardHelper::LEVEL_WIZARD, 'tail');

        // === 设置技能 (arch+) ===
        case 'setskill':
            return _adminDelegate('setskill', $charId, $subParam, $userId, WizardHelper::LEVEL_ARCH, 'setskill');

        // === 查看封禁列表 (wizard+) ===
        case 'banlist':
            return _adminHandleBanList($userId);

        default:
            return ['success' => false, 'message' => "未知子命令: {$subCommand}\n使用 admin help 查看可用命令。"];
    }
}

/**
 * 委托子命令到对应的独立命令文件
 */
function _adminDelegate(string $cmdName, int $charId, string $param, int $userId, int $requiredLevel, string $cmdLabel): array {
    if (!WizardHelper::canUseCommand($userId, $cmdLabel)) {
        $levelName = WizardHelper::getLevelName($requiredLevel);
        return ['success' => false, 'message' => "你没有权限使用 {$cmdName} 命令。需要 {$levelName}({$cmdLabel}) 以上权限。"];
    }

    $funcName = 'cmd_' . $cmdName;
    if (function_exists($funcName)) {
        return $funcName($charId, $param);
    }

    return ['success' => false, 'message' => "命令 {$cmdName} 暂不可用（未找到对应的处理函数）。"];
}

/**
 * 处理释放监禁用户子命令
 */
function _adminHandleRelease(int $charId, string $param, int $userId): array {
    if (!WizardHelper::canUseCommand($userId, 'ban')) {
        return ['success' => false, 'message' => '你没有权限释放用户。需要巫师(wizard)以上权限。'];
    }

    $param = trim($param);
    if (empty($param)) {
        return ['success' => false, 'message' => '用法: admin release <用户名>'];
    }

    $username = $param;
    $targetUser = UserModel::findByUsername($username);
    if (!$targetUser) {
        return ['success' => false, 'message' => "用户不存在: {$username}"];
    }

    if (!WizardHelper::canOperateOn($userId, $targetUser['id'])) {
        return ['success' => false, 'message' => '你没有权限释放该用户（对方巫师等级不低于你）。'];
    }

    if (BanHelper::releaseUser($targetUser['id'])) {
        $operatorUser = UserModel::find($userId);
        $operatorName = $operatorUser ? $operatorUser['username'] : "char#{$charId}";
        log_game('admin', "巫师 {$operatorName} 释放了用户 {$username}");
        return ['success' => true, 'message' => "已释放用户: {$username}，角色已被移至起始房间。"];
    }

    return ['success' => false, 'message' => "释放用户 {$username} 失败。"];
}

/**
 * 处理查看封禁列表子命令
 */
function _adminHandleBanList(int $userId): array {
    if (!WizardHelper::canUseCommand($userId, 'ban')) {
        return ['success' => false, 'message' => '你没有权限查看封禁列表。需要巫师(wizard)以上权限。'];
    }

    $output = "=== 封禁IP列表 ===\n";
    $bannedIps = BanHelper::getBannedIps();
    if (empty($bannedIps)) {
        $output .= "无\n";
    } else {
        foreach ($bannedIps as $ban) {
            $output .= "{$ban['ip_pattern']} - {$ban['reason']}\n";
        }
    }

    $output .= "\n=== 封禁/监禁用户列表 ===\n";
    $bannedUsers = BanHelper::getBannedUsers();
    if (empty($bannedUsers)) {
        $output .= "无\n";
    } else {
        foreach ($bannedUsers as $u) {
            $statusText = $u['status'] == BanHelper::STATUS_BANNED ? '封禁' : '监禁';
            $output .= "{$u['username']} [{$statusText}]\n";
        }
    }

    return ['success' => true, 'message' => $output];
}

/**
 * 显示帮助信息
 */
function _adminShowHelp(int $userLevel): array {
    $lines = [];
    $lines[] = '========== 巫师管理命令 ==========';
    $lines[] = '用法: admin <子命令> [参数...]';
    $lines[] = '';

    if ($userLevel >= WizardHelper::LEVEL_ELDER) {
        $lines[] = '【长老(elder)以上可用】';
        $lines[] = '  goto <area> <room>       传送到指定房间';
        $lines[] = '  goto <角色名>             传送到玩家所在位置';
        $lines[] = '';
    }

    if ($userLevel >= WizardHelper::LEVEL_IMMORTAL) {
        $lines[] = '【神仙(immortal)以上可用】';
        $lines[] = '  summon <角色名>           召唤玩家到身边';
        $lines[] = '  summon <角色名> <area> <room>  送到指定房间';
        $lines[] = '  sameip                    查看同IP多账号';
        $lines[] = '  sameip <IP前缀>           按IP搜索用户';
        $lines[] = '  whois <用户名|角色名>     查看用户详细资料';
        $lines[] = '';
    }

    if ($userLevel >= WizardHelper::LEVEL_WIZARD) {
        $lines[] = '【巫师(wizard)以上可用】';
        $lines[] = '  clone <角色名> <item_id>  克隆物品给指定角色';
        $lines[] = '  dest <角色名> <item_id>   销毁指定角色的物品';
        $lines[] = '  dest <inventory_id>       按背包ID销毁物品';
        $lines[] = '  tojail <用户名> [原因]    快速监禁用户';
        $lines[] = '  release <用户名>          释放被监禁用户';
        $lines[] = '  toguest <用户名> [天数]   将玩家送入欢迎室';
        $lines[] = '  toguest approve <用户名>  批准玩家进入正常游戏';
        $lines[] = '  toguest list              查看欢迎室玩家列表';
        $lines[] = '  ban ip <IP模式> [原因]    封禁IP';
        $lines[] = '  ban unbanip <IP模式>      解封IP';
        $lines[] = '  ban user <用户名>         封禁用户';
        $lines[] = '  ban unban <用户名>        解封用户';
        $lines[] = '  ban list / banlist        查看封禁列表';
        $lines[] = '  snoop <角色名>            查看玩家当前行为';
        $lines[] = '  tail <角色名>             持续跟踪玩家';
        $lines[] = '';
    }

    if ($userLevel >= WizardHelper::LEVEL_ARCH) {
        $lines[] = '【大巫师(arch)以上可用】';
        $lines[] = '  wizlock [on|off|status]   维护模式管理';
        $lines[] = '  shutdown [分钟] [原因]    关闭服务器维护';
        $lines[] = '  shutdown cancel           取消维护';
        $lines[] = '  shutdown status           查看维护状态';
        $lines[] = '  setskill <角色> <技能> <等级> 设置技能等级';
        $lines[] = '';
    }

    $lines[] = '=====================================';

    return ['success' => true, 'message' => implode("\n", $lines)];
}
