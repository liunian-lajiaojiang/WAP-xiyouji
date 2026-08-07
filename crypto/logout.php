<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

// 清除所有 session 变量
$_SESSION = [];

// 销毁 session
session_destroy();

// 跳转到登录页面
header('Location: login.php');
exit;
