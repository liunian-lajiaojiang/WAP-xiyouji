<?php
/**
 * 角色选择/创建页面
 */
session_save_path(__DIR__ . '/../sessions');
session_start();

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once DAEMON_PATH . 'LoginDaemon.php';

// 要求登录
if (!isset($_SESSION['user_id'])) {
    redirect('../index.php');
}

// 检查服务器维护状态：如果正在维护且当前用户不是大巫师及以上，强制登出
$userId = intval($_SESSION['user_id'] ?? 0);
$shutdownStatus = Database::queryOne("SELECT value FROM variables WHERE var_key = 'shutdown_status'");
if ($shutdownStatus && $shutdownStatus['value'] === 'active') {
    $user = Database::queryOne("SELECT wizard_level FROM users WHERE id = ?", [$userId]);
    $wizLevel = intval($user['wizard_level'] ?? 0);
    if ($wizLevel < 5) {
        // 强制登出
        session_destroy();
        redirect('../index.php?error=maintenance');
    }
}

$error = '';
$success = '';

// 如果用户从游戏中返回角色选择页面，自动将之前的角色设为离线
if (isset($_SESSION['char_id'])) {
    $oldCharId = intval($_SESSION['char_id']);
    CharacterModel::updateOnlineStatus($oldCharId, false);
    unset($_SESSION['char_id']);
    unset($_SESSION['char_name']);
}

// 获取用户的角色列表
$userId = $_SESSION['user_id'];
$characters = CharacterModel::findByUserId($userId);

        // 处理角色选择
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['select_char'])) {
        // 选择现有角色
        $charId = intval($_POST['char_id']);
        $result = LoginDaemon::selectCharacter($charId);
        
        if ($result['success']) {
            // 不要清空消息队列，让玩家能看到历史消息
            // 旧代码：Database::execute("DELETE FROM message_queue WHERE char_id = ?", [$charId]);
            
            // 记录登录时间（用于chat_poll.php 过滤旧消息）
            $_SESSION['login_time'] = date('c');

            // 获取角色的当前位置
            $char = CharacterModel::find($charId);
            
            if ($char) {
                // 设置登录欢迎消息（MOTD）
                $motdMessage = "***  help 也许对你很有用。\n\n";
                $motdMessage .= "***  注意：\n";
                $motdMessage .= "为维护和发展本游戏，巫师有时需要监听玩家。所以希望\n";
                $motdMessage .= "不要在游戏中谈论不想被别人知道的私事，以免出现尴尬\n";
                $motdMessage .= "局面。\n\n";
                $motdMessage .= "欢迎回来，{$char['name']}！";
                
                $_SESSION['flash_message'] = [
                    'content' => $motdMessage,
                    'timestamp' => time()
                ];
                
                redirect("room.php?area={$char['current_area']}&room=" . urlencode($char['current_room']));
            } else {
                redirect('room.php?area=changan&room=changandadao');
            }
        } else {
            $error = $result['message'];
        }
    } elseif (isset($_POST['delete_char'])) {
        // 删除角色
        $charId = intval($_POST['char_id']);
        $char = CharacterModel::find($charId);
        
        if (!$char) {
            $error = '角色不存在';
        } elseif ($char['user_id'] != $userId) {
            $error = '无权删除此角色';
        } else {
            // 检查角色是否在线（使用online字段判断）
            if (isset($char['online']) && $char['online']) {
                $error = '无法删除在线角色，请先退出游戏';
            } else {
                try {
                    Database::execute('DELETE FROM characters WHERE id = ?', [$charId]);
                    log_game('DELETE_CHAR', "删除角色: {$char['name']} (ID: {$charId})");
                    $success = "角色 '{$char['name']}' 已删除";
                    // 刷新角色列表
                    $characters = CharacterModel::findByUserId($userId);
                } catch (Exception $e) {
                    $error = '删除失败: ' . $e->getMessage();
                }
            }
        }
    } elseif (isset($_POST['create_char'])) {
        // 检查是否已达到最大角色数
        if (count($characters) >= 1) {
            $error = '每个账号最多只能创建一个角色';
        } else {
            // 创建新角色
            $name = trim($_POST['char_name'] ?? '');
            $race = $_POST['race'] ?? RACE_HUMAN;
            $gender = $_POST['gender'] ?? GENDER_MALE;
            
            // 天赋属性（默认值）
            $str = intval($_POST['str'] ?? 20);  // 体格
            $con = intval($_POST['con'] ?? 20);  // 根骨
            $int = intval($_POST['int'] ?? 25);  // 悟性
            $spi = intval($_POST['spi'] ?? 25);  // 灵性
            
            // 验证天赋总和
            $giftTotal = $str + $con + $int + $spi;
            if ($giftTotal != 90) {
                $error = "天赋属性总和必须等于90(当前：{$giftTotal})";
            } elseif ($str < 10 || $str > 30 || $con < 10 || $con > 30 || $int < 10 || $int > 30 || $spi < 10 || $spi > 30) {
                $error = '每项天赋必须在10-30之间';
            } elseif (empty($name)) {
                $error = '请输入角色名';
            } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 10) {
                $error = '角色名长度必须在2-10个字符之间';
            } else {
                $result = LoginDaemon::createCharacter([
                    'name' => $name,
                    'race' => $race,
                    'gender' => $gender,
                    'str' => $str,
                    'con' => $con,
                    'int' => $int,
                    'spi' => $spi
                ]);
                
                if ($result['success']) {
                    $success = '角色创建成功';
                    // 刷新角色列表
                    $characters = CharacterModel::findByUserId($userId);
                    
                    // 自动选择新创建的角色并进入游戏
                    $newCharId = $result['char_id'];
                    $_SESSION['char_id'] = $newCharId;

                    // 不要清空消息队列，让玩家能看到历史消息
                    // 旧代码：Database::execute("DELETE FROM message_queue WHERE char_id = ?", [$newCharId]);
                    
                    // 记录登录时间（用于chat_poll.php 过滤旧消息）
                    $_SESSION['login_time'] = date('c');

                    // 设置在线状态（必须在广播前，否则broadcastToAll找不到新玩家）
                    CharacterModel::updateOnlineStatus($newCharId, true);
                    
                    // 设置初始位置为南城客栈，同时确保 food/water 为满
                    Database::execute(
                        'UPDATE characters SET current_area = ?, current_room = ?, food = max_food, water = max_water WHERE id = ?',
                        ['city', 'city/kezhan', $newCharId]
                    );
                    
                    // 全局广播新角色创建消息（系统频道，所有在线玩家都能看到）
                    require_once HELPER_PATH . 'SystemBroadcast.php';
                    SystemBroadcast::newCharacter($name, $race);
                    
                    // 获取角色信息用于生成个性化消息
                    $newChar = CharacterModel::find($newCharId);
                    
                    // 广播给房间内其他玩家的消息（仅房间可见）
                    $broadcastMessage = "只见眼前霞光一闪，{$name}来到了一个新奇的世界。";
                    require_once DAEMON_PATH . 'MessageDaemon.php';
                    MessageDaemon::broadcastToRoom(
                        'city/kezhan',  // 完整的房间ID
                        $broadcastMessage,
                        intval($newCharId)  // 排除自己（确保是int类型）
                    );
                    
                    // 新玩家的欢迎消息（通过session传递，在room.php中显示）
                    $_SESSION['flash_message'] = [
                        'content' => "……云中伸出一只巨大的佛手轻轻一翻，只见你从里面跳出来。\n你来到了南城客栈。",
                        'timestamp' => time()
                    ];
                    
                    // 跳转到南城客栈
                    redirect('room.php?area=city&room=city/kezhan');
                } else {
                    $error = $result['message'];
                }
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
    <meta name="description" content="西游记mud是源自Mud西游记的经典还原H5网页文字游戏。">
    <meta name="keywords" content="西游记mud,西游记怀旧mud,西游记h5">
    <meta name="theme-color" content="#226997">
    <title><?= h(SERVER_NAME) ?> - 角色选择</title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/index.css">
    <link rel="stylesheet" href="../assets/css/footer.css">
    <link rel="stylesheet" href="../assets/css/character_select.css">
    <style>
        .welcome-box {
            margin: 10px 0;
            padding: 15px;
            background: rgba(34, 105, 151, 0.08);
            border-radius: 8px;
            border: 1px solid rgba(34, 105, 151, 0.2);
        }
        
        .welcome-ascii {
            font-family: "SimSun", "宋体", monospace;
            font-size: 12px;
            line-height: 1.4;
            color: #226997;
            text-align: center;
            margin: 0;
            padding: 0;
            white-space: pre;
            overflow-x: auto;
        }
        
        @media (min-width: 600px) {
            .welcome-ascii {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="bg-layer"><img src="../assets/images/huyanlv.jpg" width="100%" height="100%" alt="护眼"></div>
    <div class="container">
        <h1><?= h(SERVER_NAME) ?></h1>
        <hr>
        <!-- WELCOME 欢迎信息 -->
        <div class="welcome-box">
            <pre class="welcome-ascii">
●○●○●○●○●○●○●○●○●○●○●

wap西游记2012

A JOURNEY TO the West

●○●○●○●○●○●○●○●○●○●○●

西游记巫师协会版权所有
XYJ 2000,Copyright 1996-2000 by Xi You Ji Inc.
有任何意见，请 email 给 qq554498935@163.com

天籁妙，山水雅，醉露为酒玉为花。
若人问我归何处，彩云深处是我家。

本站网页地址: http://xyj.fwh.is
西游记总站地址: xiyouji.org 6666
            </pre>
        </div>
        <p class="poem">清秋幕府井梧寒，独宿江城蜡炬残。</p>
        <?php if ($error): ?>
            <div class="error"><?= h($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?= h($success) ?></div>
        <?php endif; ?>
        
        <!-- 角色列表 -->
        <?php if (!empty($characters)): ?>
            <p class="login-title">选择你的角色</p>
            <div class="character-list">
                <?php foreach ($characters as $char): ?>
                    <div class="character-card">
                        <div class="character-name"><?= h($char['name']) ?></div>
                        <div class="character-ranks">
                            道行: <?= ansi_to_html(describe_dx($char['daoxing'] ?? 0)) ?><br>
                            武功: <?= ansi_to_html(describe_exp($char['combat_exp'] ?? 0)) ?><br>
                            法力: <?= ansi_to_html(describe_fali($char['max_mana'] ?? 0)) ?><br>
                            内力: <?= ansi_to_html(describe_neili($char['max_force'] ?? 0)) ?><br>
                        </div>
                        <br>
                        <form method="POST" action="character_select.php" style="display: inline;">
                            <input type="hidden" name="char_id" value="<?= $char['id'] ?>">
                            <button type="submit" name="select_char" class="btn-select" style="margin-bottom: 8px;">
                                进入游戏
                            </button>
                        </form>
                        <button type="button" onclick="confirmDelete(<?= $char['id'] ?>, '<?= h($char['name']) ?>')" class="btn-delete">
                            删除角色
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-characters">
                <p>你还没有角色，请创建一个新角色开始游戏。</p>
            </div>
        <?php endif; ?>
        
        <!-- 创建新角色表格-->
        <?php if (count($characters) < 1): ?>
        <form method="POST" action="character_select.php">
            <div class="form-group">
                <p class="login-title">创建新角色</p>
                <img width="100" height="50" src="../assets/images/dao.png" alt="道">
                <br>
                <label for="char_name">角色名*</label>
                <input type="text" 
                       id="char_name" 
                       name="char_name" 
                       required 
                       placeholder="请输入角色名(1-10个字符)"
                       value="<?= h($_POST['char_name'] ?? '') ?>">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="race">种族</label>
                    <select id="race" name="race">
                        <option value="human" <?= (($_POST['race'] ?? '') === 'human') ? 'selected' : '' ?>>人类</option>
                        <option value="demon" <?= (($_POST['race'] ?? '') === 'demon') ? 'selected' : '' ?>>妖族</option>
                        <option value="god" <?= (($_POST['race'] ?? '') === 'god') ? 'selected' : '' ?>>仙族</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="gender">性别</label>
                    <select id="gender" name="gender">
                        <option value="male" <?= (($_POST['gender'] ?? '') === 'male') ? 'selected' : '' ?>>男</option>
                        <option value="female" <?= (($_POST['gender'] ?? '') === 'female') ? 'selected' : '' ?>>女</option>
                    </select>
                </div>
            </div>
            
            <!-- 天赋选择 -->
            <div class="gift-selection">
                <p class="login-title">选择天赋属性</p>
                <div class="gift-info">
                    <p>在开始您的西游历程之前，首先要为自己所创造的人物选择一个合适的天赋，因为这将对您今后的发展有重大的影响。</p>
                    <p>西游记中的人物天赋共有四项，每项由一个十到三十之间的整数来表示，一般数值越大越好，但各项的总和是固定不变的90点。</p>
                </div>
                
                <div class="gift-grid">
                    <div class="gift-item">
                        <label for="str">0. 体格（力量）</label>
                        <input type="number" id="str" name="str" min="10" max="30" value="<?= intval($_POST['str'] ?? 20) ?>" onchange="updateGiftTotal()">
                    </div>
                    <div class="gift-item">
                        <label for="con">1. 根骨</label>
                        <input type="number" id="con" name="con" min="10" max="30" value="<?= intval($_POST['con'] ?? 20) ?>" onchange="updateGiftTotal()">
                    </div>
                    <div class="gift-item">
                        <label for="int">2. 悟性</label>
                        <input type="number" id="int" name="int" min="10" max="30" value="<?= intval($_POST['int'] ?? 25) ?>" onchange="updateGiftTotal()">
                    </div>
                    <div class="gift-item">
                        <label for="spi">3. 灵性</label>
                        <input type="number" id="spi" name="spi" min="10" max="30" value="<?= intval($_POST['spi'] ?? 25) ?>" onchange="updateGiftTotal()">
                    </div>
                </div>
                
                <div class="gift-total">
                    <span>天赋总和</span>
                    <span id="giftTotalDisplay">90</span>
                    <span>/ 90</span>
                    <span id="giftTotalStatus" style="color: green;">正常</span>
                </div>
            </div>
            
            <button type="submit" name="create_char" class="submit-btn">
                创建角色
            </button>
        </form>
        <?php endif; ?>
        
        <div class="back-link">
            <p>当前时间:
                <script src="../assets/js/time.js"></script>
            </p>
            <p><a href="../logout.php">退出登录</a> | <a href="javascript:window.location.reload();">刷新此页面</a></p>
        </div>
    </div>
    
    <script src="../assets/js/character_select.js"></script>
</body>
</html>

