<?php
/**
 * 工具函数
 */

/**
 * 格式化日期时间
 */
function formatDateTime(string $dateStr, string $format = 'Y-m-d H:i:s'): string {
    if (empty($dateStr)) return '--';
    try {
        $date = new DateTime($dateStr);
        return $date->format($format);
    } catch (Exception $e) {
        return $dateStr;
    }
}

/**
 * 格式化短日期时间（用于显示）
 */
function formatShortDateTime(string $dateStr): string {
    return formatDateTime($dateStr, 'm-d H:i');
}

/**
 * 格式化金额
 */
function formatAmount(float $amount, int $decimals = 2): string {
    return number_format($amount, $decimals);
}

/**
 * JSON响应
 */
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * 获取当前角色ID
 */
function getCurrentCharId(): int {
    session_start();
    return $_SESSION['char_id'] ?? 21;
}

/**
 * 安全输出HTML
 */
function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * 格式化交易状态
 */
function formatTradeStatus(string $status): string {
    return match ($status) {
        'pending' => '待结算',
        'win' => '盈利',
        'lose' => '亏损',
        default => $status
    };
}

/**
 * 获取方向图标和文本
 */
function getDirectionInfo(string $direction): array {
    return match ($direction) {
        'up' => ['icon' => '↗', 'text' => '买涨', 'class' => 'up'],
        'down' => ['icon' => '↘', 'text' => '买跌', 'class' => 'down'],
        default => ['icon' => '?', 'text' => '未知', 'class' => '']
    };
}

/**
 * API调用封装
 */
function fetchApi(string $url, array $options = []): ?array {
    $ch = curl_init();
    
    $defaultOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ];
    
    curl_setopt_array($ch, $defaultOptions);
    curl_setopt($ch, CURLOPT_URL, $url);
    
    if (isset($options['headers'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
    }
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return null;
    }
    
    return json_decode($response, true);
}
