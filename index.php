<?php
/**
 * 游戏入口/登录页面 - 整合登录功能
 */
session_save_path(__DIR__ . '/sessions');
session_start();

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/config/game.php';

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once MODEL_PATH . 'User.php';
require_once MODEL_PATH . 'Character.php';
Database::addMarriedColumn();
Database::addRoomItemsEnchantmentsColumn();
Database::addUnconsciousAndDazeColumns();
Database::addLiquidContainerColumns();
Database::addSleepInvitationsTable();
Database::addKeeZeroTimeColumn();
Database::addUserBlocksTable();

// 如果已登录且有角色ID，直接跳转到游戏房间
if (isset($_SESSION['char_id']) && !empty($_SESSION['char_id'])) {
    $char = CharacterModel::find($_SESSION['char_id']);
    if ($char) {
        redirect("functions/room.php?area={$char['current_area']}&room={$char['current_room']}");
    }
}

$error = '';

// 查询在线人数统计
$onlineStats = [
    'total' => 0,
    'wizards' => 0,
    'players' => 0
];

// 本站全部统计（非在线）
$totalStats = [
    'wizards' => 0,
    'players' => 0
];

// 统计正在 index.php 页面尝试登录的人数
$visitorsCount = 0;
if (session_status() === PHP_SESSION_ACTIVE) {
    // 记录当前未登录访客的时间戳
    if (!isset($_SESSION['char_id']) || empty($_SESSION['char_id'])) {
        $_SESSION['index_visitor_time'] = time();
    }
    // 统计 sessions 目录中最近3分钟活跃的未登录访客
    $sessionDir = __DIR__ . '/sessions';
    $cutoff = time() - 180; // 3分钟内
    if (is_dir($sessionDir)) {
        $files = glob($sessionDir . '/sess_*');
        if ($files) {
            foreach ($files as $file) {
                if (filemtime($file) >= $cutoff) {
                    $visitorsCount++;
                }
            }
        }
    }
    // 减去已登录的 session（已登录的 session 也会更新 mtime）
    // 用在线角色数作为已登录 session 的估算
    $visitorsCount = max(0, $visitorsCount - $onlineStats['total']);
}

try {
    // 总在线人数
    $totalResult = Database::queryOne("SELECT COUNT(*) as count FROM characters WHERE online = 1");
    if ($totalResult) {
        $onlineStats['total'] = intval($totalResult['count']);
    }
    
    // 在线巫师数量
    $wizardResult = Database::queryOne(
        "SELECT COUNT(*) as count 
         FROM characters c 
         INNER JOIN users u ON c.user_id = u.id 
         WHERE c.online = 1 AND u.wizard_level > 0"
    );
    if ($wizardResult) {
        $onlineStats['wizards'] = intval($wizardResult['count']);
    }
    
    // 在线普通玩家数量
    $onlineStats['players'] = $onlineStats['total'] - $onlineStats['wizards'];

    // 本站全部巫师数量（不限在线）
    $totalWizardResult = Database::queryOne(
        "SELECT COUNT(*) as count 
         FROM characters c 
         INNER JOIN users u ON c.user_id = u.id 
         WHERE u.wizard_level > 0"
    );
    if ($totalWizardResult) {
        $totalStats['wizards'] = intval($totalWizardResult['count']);
    }

    // 本站全部玩家数量 = 全部角色 - 全部巫师
    $allCharsResult = Database::queryOne("SELECT COUNT(*) as count FROM characters");
    if ($allCharsResult) {
        $totalStats['players'] = intval($allCharsResult['count']) - $totalStats['wizards'];
    }
} catch (Exception $e) {
    // 查询失败时不显示错误，保持默认值
}

// 检查维护错误
if (isset($_GET['error']) && $_GET['error'] === 'maintenance') {
    $error = '服务器正在维护中，普通玩家暂时无法登录，请稍后再试。';
}

// 处理登录请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = '请输入用户名和密码';
    } else {
        // 验证用户
        $user = UserModel::findByUsername($username);
        
        if ($user && password_verify($password, $user['password'])) {
            // 检查用户状态
            if ($user['status'] == 2) {
                // 被封禁的用户
                $error = '您的账号已被封禁，请联系管理员解封';
            } elseif ($user['status'] == 3) {
                // 被监禁的用户，允许登录但跳转到监禁室
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['imprisoned'] = true;
                redirect('functions/character_select.php');
            } elseif ($user['status'] == 4) {
                // 被送入欢迎室的用户
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['in_guest_room'] = true;
                redirect('functions/character_select.php');
            } else {
                // 正常用户
                $_SESSION['user_id'] = $user['id'];
                
                // 检查是否有角色
                $sql = "SELECT COUNT(*) as count FROM characters WHERE user_id = ?";
                $result = Database::queryOne($sql, [$user['id']]);
                
                if ($result && $result['count'] > 0) {
                    // 有角色，跳转到角色选择页面
                    redirect('functions/character_select.php');
                } else {
                    // 没有角色，也跳转到角色选择页面创建
                    redirect('functions/character_select.php');
                }
            }
        } else {
            $error = '用户名或密码错误';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-cn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,user-scalable=no">
    <meta name="description" content="wap西游记2012是源自Mud西游记2000的经典还原H5网页文字游戏">
    <meta name="keywords" content="wap西游记2012,西游记怀旧mud,西游记h5">
    <meta name="theme-color" content="#226997">
    <title>WAP西游记2012(内测中beta)</title>
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <link rel="stylesheet" href="assets/css/index.css">
    <link rel="stylesheet" href="assets/css/footer.css">
    <link rel="stylesheet" href="assets/css/news-modal.css">
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
        
        .online-stats {
            text-align: center;
            margin: 10px 0;
            padding: 8px;
            background: rgba(34, 105, 151, 0.1);
            border-radius: 5px;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .online-stats .highlight {
            color: #226997;
            font-weight: bold;
        }
        
        @media (min-width: 600px) {
            .banner-ascii {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="bg-layer"><img src="assets/images/huyanlv.jpg" width="100%" height="100%" alt="护眼"></div>
    <div class="container">
        <h1>WAP西游记2012怀旧mud上线测试中...</h1>
        <a href="gengxinsilu.html" target="_blank">近期更新思路</a>&ensp;<a href="news.php">公告</a><br>
        <hr><br>
        <p class="poem">夜半雨声惊梦起，原是窗扉半开中。</p>
        
        <?php if ($error): ?>
            <p style="color:red;text-align:center;"><?= h($error) ?></p>
        <?php endif; ?>
        
        <form action="index.php" method="POST">
            <div class="form-group">
                <p class="login-title">登录到游戏</p>
                
                <!-- BANNER 艺术字 -->
                <pre class="banner-ascii">
             @       @  @@   @@        @@   @@@@@@@@ 
@@@@@@@@@@@@@@@      @@  @   @@@@@      @@       @@  
     @@ @@           @ @@@@@@@          @        @@  
     @@ @@               @@ @   @        @       @@  
 @   @@ @@   @     @   @ @   @@@@@    @@@@@      @@  
 @@@@@@@@@@@@@@     @@ @ @ @   @@       @@  @@@@@@@  
 @@  @@ @@  @@      @ @ @@@@@ @@        @@  @@     
 @@  @@ @@  @@        @ @ @@  @         @@  @@       
 @@  @@ @@@@@@       @  @ @@  @@        @@  @@       
 @@ @@   @@@@@     @@@  @ @@@@@@@@      @@  @@       
 @@@        @@      @@  @ @@  @@        @@ @@@       
 @@         @@      @@ @@ @@  @@        @@@ @@     @ 
 @@@@@@@@@@@@@      @@ @  @@  @@       @@@  @@     @
 @@         @@      @@ @ @@@ @@@        @   @@@@@@@@@
 @          @       @ @   @   @              @@@@@@@
                </pre>
                
                <!-- 在线人数统计 -->
                <div class="online-stats">
                    目前共有 <span class="highlight"><?= $onlineStats['wizards'] ?></span> 位巫师、<span class="highlight"><?= $onlineStats['players'] ?></span> 位玩家在线上。<br>
                    本站共有 <span class="highlight"><?= $totalStats['wizards'] ?></span> 位巫师、<span class="highlight"><?= $totalStats['players'] ?></span> 位玩家。<br>
                    <span class="highlight"><?= $visitorsCount ?></span> 位尝试登录中。
                </div>
                
                <img width="100" height="50" src="assets/images/dao.png" alt="Dao">
                <br>
                <label for="username">用户名</label>
                <input type="text" name="username" id="username" placeholder="请输入用户名" required autofocus>
            </div>
            
            <div class="form-group password-group">
                <label for="password">密码</label>
                <input type="password" name="password" id="password" placeholder="请输入密码" required>
                <button type="button" class="toggle-btn" onclick="togglePassword()">显示密码</button>
            </div>
            
            <div>记住账号/密码<input type="checkbox" id="remember" checked></div>
            <a href="register.php">还没有账号？点此注册</a>
            <div style="text-align:center;margin:8px 0;"></div>
            <button type="submit" class="submit-btn">登录</button>
        </form>
        
        <div class="back-link">
            <p>当前时间:
                <script src="assets/js/time.js"></script>
            </p>
<p><a href="about_us.html">关于我们</a> | <a href="javascript:window.location.reload();">刷新此页</a></p>
        </div>
    </div>

    <!-- 新闻弹窗 -->
    <div id="newsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">最新公告</h3>
                <span class="close-btn" onclick="closeNewsModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="newsContent" style="max-width: 100%; overflow: auto;">
                    <p>加载中...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="closeNewsModal()">关闭</button>
            </div>
        </div>
    </div>
    <script src="assets/js/news-modal.js"></script>
    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleBtn = document.querySelector('.toggle-btn');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleBtn.textContent = '隐藏密码';
            } else {
                passwordInput.type = 'password';
                toggleBtn.textContent = '显示密码';
            }
        }
    </script>
</body>
</html>

