<?php
/**
 * 大唐股票买卖 - 整合版主游戏配置
 * 使用主游戏的数据库、认证和黄金货币
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 清空输出缓冲区，防止任何意外输出（如BOM）干扰 JSON
if (ob_get_level()) ob_clean();

// 抑制警告，避免干扰 JSON 输出
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 加载主游戏系统
session_save_path(__DIR__ . '/../../sessions');
session_start();

require_once __DIR__ . '/../../config/game.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../models/Character.php';
require_once __DIR__ . '/../../models/Item.php';
require_once __DIR__ . '/../../helpers/MoneyHelper.php';

/**
 * 获取当前登录角色ID（支持 AJAX 请求）
 */
function getCurrentUserId() {
    if (isset($_SESSION['char_id']) && $_SESSION['char_id'] > 0) {
        return intval($_SESSION['char_id']);
    }
    // AJAX 请求返回 JSON 错误
    error('请先登录并选择角色', 401);
}

/**
 * 检查是否已登录
 */
function isLoggedIn() {
    return isset($_SESSION['char_id']) && $_SESSION['char_id'] > 0;
}

/**
 * 获取用户黄金余额
 */
function getGoldBalance(int $charId): float {
    $money = MoneyHelper::getMoneyInventory($charId);
    return floatval($money['gold']);
}

/**
 * 扣除黄金（以金为单位，内部换算为铜钱）
 */
function deductGold(int $charId, float $goldAmount): bool {
    $coinAmount = intval(round($goldAmount * 10000)); // 1金=10000铜
    if ($coinAmount <= 0) return true;
    return MoneyHelper::deductMoney($charId, $coinAmount);
}

/**
 * 添加黄金
 */
function addGold(int $charId, float $goldAmount): void {
    $coinAmount = intval(round($goldAmount * 10000));
    if ($coinAmount <= 0) return;
    MoneyHelper::addMoney($charId, $coinAmount);
}

/**
 * 获取数据库PDO实例（兼容旧API调用方式）
 */
function getDB(): PDO {
    return Database::getInstance();
}

// 统一响应格式
function response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// 错误响应
function error($message, $code = 400) {
    response(['success' => false, 'error' => $message], $code);
}

// 成功响应
function success($data = []) {
    response(array_merge(['success' => true], $data));
}
