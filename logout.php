<?php
/**
 * 退出登录
 */
session_save_path(__DIR__ . '/sessions');
session_start();

// 加载游戏配置（定义MODEL_PATH等常量）
require_once __DIR__ . '/config/game.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once MODEL_PATH . 'Character.php';

// 将角色设为离线
$charId = intval($_SESSION['char_id'] ?? 0);
if ($charId > 0) {
    CharacterModel::updateOnlineStatus($charId, false);
}

// 清空会话
$_SESSION = [];

// 销毁会话
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// 跳转到首页
header("Location: index.php");
exit;
?>