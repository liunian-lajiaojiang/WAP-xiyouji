<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

// 加载项目配置和模型
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Character.php';

// 如果已登录，直接跳转到合约页面
if (isset($_SESSION['char_id']) && !empty($_SESSION['char_id'])) {
    header('Location: crypto.php');
    exit;
}

$error = '';

// 处理注册请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = '请填写用户名和密码';
    } elseif (!preg_match('/^[a-zA-Z]{3,8}$/', $username)) {
        $error = '用户名必须是3-8个英文字母';
    } elseif (strlen($password) < 1) {
        $error = '密码长度至少1个字符';
    } elseif ($password !== $confirmPassword) {
        $error = '两次输入的密码不一致';
    } elseif (UserModel::findByUsername($username)) {
        $error = '用户名已存在，请选择其他用户名';
    } else {
        try {
            Database::beginTransaction();
            
            // 创建用户
            $userId = UserModel::create([
                'username' => $username,
                'password' => $password
            ]);
            
            // 创建角色
            $charId = CharacterModel::create([
                'user_id' => $userId,
                'name' => $username . '的角色'
            ]);
            
            // 给角色初始1000 gold
            $sql = "INSERT INTO character_inventory (char_id, item_id, quantity) VALUES (?, 'gold', 1000)";
            Database::execute($sql, [$charId]);
            
            Database::commit();
            
            // 自动登录
            $_SESSION['user_id'] = $userId;
            $_SESSION['char_id'] = $charId;
            
            header('Location: crypto.php');
            exit;
        } catch (Exception $e) {
            Database::rollBack();
            $error = '注册失败：' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>注册 - 事件合约</title>
    <link rel="stylesheet" href="css/trade.css">
    <style>
        .auth-container {
            max-width: 400px;
            margin: 100px auto;
            padding: 40px;
            background: #0B0E11;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
        }
        .auth-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .auth-header h1 {
            color: #EAECEF;
            margin: 0;
            font-size: 28px;
        }
        .auth-header p {
            color: #848E9C;
            margin: 10px 0 0;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            color: #EAECEF;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            background: #1E2329;
            border: 1px solid #2B3139;
            border-radius: 5px;
            color: #EAECEF;
            font-size: 16px;
            box-sizing: border-box;
        }
        .form-group input:focus {
            outline: none;
            border-color: #F0B90B;
        }
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: #F0B90B;
            border: none;
            border-radius: 5px;
            color: #0B0E11;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-submit:hover {
            background: #FFCA2F;
        }
        .auth-footer {
            text-align: center;
            margin-top: 25px;
            color: #848E9C;
        }
        .auth-footer a {
            color: #F0B90B;
            text-decoration: none;
        }
        .auth-footer a:hover {
            text-decoration: underline;
        }
        .error {
            background: rgba(246, 70, 93, 0.2);
            color: #F6465D;
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid rgba(246, 70, 93, 0.3);
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-header">
            <h1>注册</h1>
            <p>创建您的账号，开始交易</p>
        </div>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" placeholder="至少3个字符">
            </div>

            <div class="form-group">
                <label for="password">密码</label>
                <input type="password" id="password" name="password" required placeholder="至少6个字符">
            </div>

            <div class="form-group">
                <label for="confirm_password">确认密码</label>
                <input type="password" id="confirm_password" name="confirm_password" required placeholder="再次输入密码">
            </div>

            <button type="submit" class="btn-submit">注册</button>
        </form>

        <div class="auth-footer">
            <p>已有账号？<a href="login.php">立即登录</a></p>
        </div>
    </div>
</body>
</html>
