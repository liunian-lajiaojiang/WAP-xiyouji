<?php
/**
 * 注册页面
 */
session_save_path(__DIR__ . '/sessions');
session_start();

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/config/game.php';

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once MODEL_PATH . 'User.php';
require_once DAEMON_PATH . 'LoginDaemon.php';

// 如果已登录，跳转到角色选择页面
if (is_logged_in()) {
    redirect('functions/character_select.php');
}

$error = '';
$success = '';
$username = '';

// 检查服务器维护状态
$isMaintenance = false;
$maintenanceMsg = '';
$shutdownStatus = Database::queryOne("SELECT value FROM variables WHERE var_key = 'shutdown_status'");
if ($shutdownStatus && $shutdownStatus['value'] === 'active') {
    $isMaintenance = true;
    $reason = Database::queryOne("SELECT value FROM variables WHERE var_key = 'shutdown_reason'");
    $minutes = Database::queryOne("SELECT value FROM variables WHERE var_key = 'shutdown_minutes'");
    $maintenanceMsg = '<span style="color:red;">服务器正在维护中';
    if ($reason) $maintenanceMsg .= '，原因: ' . $reason['value'];
    $maintenanceMsg .= '。暂时无法注册新账号，请稍后再试。</span>';
}

// 处理注册请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 维护期间禁止注册
    if ($isMaintenance) {
        $error = $maintenanceMsg;
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // 验证输入
        if (empty($username) || empty($password) || empty($confirmPassword)) {
            $error = '请填写所有必填字段';
        } elseif (!preg_match('/^[a-zA-Z]{3,8}$/', $username)) {
            $error = '用户名必须是3-8个英文字母（不支持数字、中文和特殊字符）';
        } elseif (strlen($password) < 1) {
            $error = '密码长度至少1个字符';
        } elseif ($password !== $confirmPassword) {
            $error = '两次输入的密码不一致';
        } else {
            $result = LoginDaemon::register($username, $password);
            
            if ($result['success']) {
                $success = true;
            } else {
                $error = $result['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,user-scalable=no">
    <meta name="description" content="西游记mud是源自Mud西游记2000的经典还原H5网页文字游戏。">
    <meta name="keywords" content="西游记mud,西游记怀旧mud，西游记h5">
    <meta name="theme-color" content="#226997">
    <title><?= $success ? '注册成功' : '注册' ?> - <?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <link rel="stylesheet" href="assets/css/footer.css">
    <link rel="stylesheet" href="assets/css/index.css">
    <style>
        .banner-ascii {
            font-family: monospace;
            font-size: 10px;
            line-height: 1.2;
            color: #226997;
            text-align: center;
            margin: 10px auto;
            padding: 10px;
            background: rgba(255,255,255,0.1);
            border-radius: 5px;
            overflow-x: auto;
            white-space: pre;
        }
        
        @media (min-width: 600px) {
            .banner-ascii {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="bg-layer"><img src="assets/images/huyanlv.jpg" width="100%" height="100%" alt="护眼绿"></div>
    <div class="container">
        <?php if ($success): ?>
            <h1>注册成功！</h1>
            <hr>
            <br>
            <p class="poem">春风得意马蹄疾，一日看尽长安花。</p>
            <br>
            <p>恭喜您已成功注册账号！</p>
            <p>用户名：<?= h($username) ?></p>
            <br>
            <a href="index.php" class="submit-btn">立即登录</a>
        <?php else: ?>
            <h1><?= h(SERVER_NAME) ?></h1>
            <hr>
            <!-- BANNER 艺术字 -->
            <pre class="banner-ascii">  
        @   @      @        @       @
             @      @  @@   @@       @@   @@@@@@@@ 
@@@@@@@@@@@@@@@     @@  @   @@@@@     @@       @@  
     @@ @@          @ @@@@@@@         @        @@  
     @@ @@              @@ @   @       @       @@  
 @   @@ @@   @    @   @ @   @@@@@   @@@@@      @@  
 @@@@@@@@@@@@@@    @@ @ @ @   @@      @@  @@@@@@@  
 @@  @@ @@  @@     @ @ @@@@@ @@       @@  @@   @   
 @@  @@ @@  @@       @ @ @@  @        @@  @@       
 @@  @@ @@@@@@      @  @ @@  @@       @@  @@       
 @@ @@   @@@@@    @@@  @ @@@@@@@@     @@  @@       
 @@@        @@     @@  @ @@  @@       @@ @@@       
 @@         @@     @@ @@ @@  @@       @@@ @@     @ 
 @@@@@@@@@@@@@     @@ @  @@  @@      @@@  @@     @
 @@         @@     @@ @ @@@ @@@       @   @@@@@@@@@
 @          @      @ @   @   @             @@@@@@@
            </pre>
            
            <p class="poem">清秋幕府井梧寒，独宿江城蜡炬残。</p>
            <?php if ($error): ?>
                <div class="error"><?= h($error) ?></div>
            <?php endif; ?>
            
            <?php if ($isMaintenance): ?>
                <div class="error"><?= $maintenanceMsg ?></div>
            <?php endif; ?>
            
            <form method="POST" action="register.php" <?= $isMaintenance ? 'disabled' : '' ?>>
                <div class="form-group">
                    <p class="login-title">注册新账号</p>
                    <img width="100" height="50" src="assets/images/dao.png" alt="道">
                    <br>
                    <label for="username">用户名</label>
                    <input type="text" 
                           name="username" 
                           value="<?= h($username) ?>" 
                           placeholder="请输入用户名（3-8个英文字母）" 
                           pattern="[a-zA-Z]{3,8}"
                           title="用户名必须是3-8个英文字母"
                           required 
                           autofocus 
                           id="username">
                </div>
                <div class="form-group password-group">
                    <label for="password">密码</label>
                    <input type="password" 
                           id="password" 
                           name="password" 
                           placeholder="请输入密码（至少1字符）" 
                           required>
                    <button type="button" class="toggle-btn" onclick="togglePassword()">显示密码</button>
                </div>
                <div class="form-group">
                    <label for="confirm_password">确认密码</label>
                    <input type="password" 
                           id="confirm_password" 
                           name="confirm_password" 
                           placeholder="再次输入密码" 
                           required>
                </div>
                <a href="index.php">已有账号？去登录吧！</a>
                <button type="submit" class="submit-btn" <?= $isMaintenance ? 'disabled' : '' ?>>
                    <?= $isMaintenance ? '维护中...' : '注册' ?>
                </button>
            </form>
        <?php endif; ?>
        
        <div class="back-link">
            <p>当前时间:
                <script src="assets/js/time.js"></script>
            </p>
            <p><a href="about_us.html">关于我们</a> | <a href="javascript:window.location.reload();">刷新此页面</a></p>
        </div>
    </div>
    
    <script src="assets/js/register.js"></script>
</body>
</html>

