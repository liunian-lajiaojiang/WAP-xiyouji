<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

// 加载项目配置和模型
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Character.php';

// 如果已登录，直接跳转到合约页面
if (isset($_SESSION['char_id']) && !empty($_SESSION['char_id'])) {
    header('Location: crypto.php');
    exit;
}

$error = '';

// 处理登录请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = '请输入用户名和密码';
    } else {
        // 使用项目的 UserModel 验证用户
        $user = UserModel::findByUsername($username);
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            
            // 检查是否有角色
            $chars = CharacterModel::findByUserId($user['id']);
            
            if (!empty($chars)) {
                // 有角色，选择第一个角色
                $_SESSION['char_id'] = $chars[0]['id'];
                header('Location: crypto.php');
                exit;
            } else {
                // 没有角色，提示
                $error = '该账号未创建角色，请先在游戏中创建角色';
            }
        } else {
            $error = '用户名或密码错误';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - 事件合约</title>
    <link rel="stylesheet" href="css/trade.css">
    <link rel="stylesheet" href="css/auth.css">
</head>
<body>
    <div class="auth-container">
        <div class="auth-header">
            <h1>登录</h1>
            <p>欢迎回来，继续您的交易之旅</p>
        </div>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="password">密码</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit" class="btn-submit">登录</button>
        </form>

        <div class="auth-footer">
            <p>还没有账号？<a href="register.php">立即注册</a></p>
            <p style="margin-top:10px;"><a href="../index.php">返回游戏</a></p>
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
                <button class="btn-news" onclick="closeNewsModal()">关闭</button>
            </div>
        </div>
    </div>

    <script>
        function showNewsModal() {
            document.getElementById('newsModal').style.display = 'flex';
        }
        function closeNewsModal() {
            document.getElementById('newsModal').style.display = 'none';
        }
        async function getLatestNews() {
            try {
                const response = await fetch('../get_latest_news.php?id=154');
                if (!response.ok) throw new Error('请求失败');
                const data = await response.json();
                if (data.success && data.news && data.news.title) {
                    document.getElementById('newsContent').innerHTML =
                        `<h4>${data.news.title}</h4>` +
                        `<div>${data.news.content.replace(/\n/g, '<br>')}</div>`;
                    // 有新闻就弹窗，不记忆是否显示过
                    showNewsModal();
                }
            } catch (error) {
                console.error('获取新闻失败:', error);
            }
        }
        window.addEventListener('load', getLatestNews);
    </script>
</body>
</html>
