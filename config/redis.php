<?php
/**
 * Redis 配置文件
 * 用于 TempStateHelper 的 Redis 存储后端
 * 
 * 如果不使用 Redis，可以删除此文件或保持默认配置
 * TempStateHelper 会自动回退到数据库存储
 */

return [
    // Redis 服务器地址
    'host' => '127.0.0.1',
    
    // Redis 服务器端口
    'port' => 6379,
    
    // Redis 密码（如果没有密码，留空）
    'password' => '',
    
    // Redis 数据库编号（0-15）
    'database' => 0,
    
    // 连接超时时间（秒）
    'timeout' => 2.0,
    
    // 是否启用 Redis（false 时使用数据库存储）
    'enabled' => false,
];