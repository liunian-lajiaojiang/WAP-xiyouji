<?php
/**
 * 数据库配置
 */
return [
    'host' => 'localhost',
    'port' => 3306,
    'database' => 'xyj',
    'username' => 'xyj',
    'password' => '123456',
    'charset' => 'utf8mb4',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // InfinityFree 免费主机不支持原生预处理，用模拟模式
        PDO::ATTR_EMULATE_PREPARES => true,
    ]
];

