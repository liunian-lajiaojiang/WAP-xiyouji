<?php
/**
 * 登录守护进程
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../helpers/BanHelper.php';

class LoginDaemon {
    
    /**
     * 用户登录
     */
    public static function login(string $username, string $password): array {
        // 检查IP封禁
        $ip = get_client_ip();
        $ipBan = BanHelper::checkIpBanned($ip);
        if ($ipBan) {
            return ['success' => false, 'message' => '您的IP地址已被封禁'];
        }
        
        // 验证用户名密码
        if (!UserModel::verifyPassword($username, $password)) {
            return ['success' => false, 'message' => '用户名或密码错误'];
        }
        
        $user = UserModel::findByUsername($username);
        
        // 检查用户状态
        if ($user['status'] == BanHelper::STATUS_BANNED) {
            return ['success' => false, 'message' => '账号已被封禁'];
        }

        // 检查 wizlock（服务器维护锁定）
        // 当 shutdown_status = 'active' 时，阻止非巫师（wizard_level < 2）登录
        $wizlock = Database::queryOne("SELECT value FROM variables WHERE var_key = 'shutdown_status'");
        if ($wizlock && $wizlock['value'] === 'active') {
            $wizLevel = intval($user['wizard_level'] ?? 0);
            if ($wizLevel < 2) {
                $reason = Database::queryOne("SELECT value FROM variables WHERE var_key = 'shutdown_reason'");
                $minutes = Database::queryOne("SELECT value FROM variables WHERE var_key = 'shutdown_minutes'");
                $msg = '服务器正在维护中';
                if ($reason) $msg .= '，原因: ' . $reason['value'];
                if ($minutes) $msg .= '，预计 ' . $minutes['value'] . ' 分钟后完成';
                $msg .= '。请稍后再试。';
                return ['success' => false, 'message' => $msg];
            }
        }

        // 检查同IP登录限制
        if (BanHelper::checkLoginLimit($ip)) {
            return ['success' => false, 'message' => '当前IP登录数量已达上限'];
        }
        
        // 更新登录信息
        UserModel::updateLastLogin($user['id'], $ip);
        
        // 获取角色列表
        $characters = CharacterModel::findByUserId($user['id']);
        
        // 存入session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['vip_level'] = $user['vip_level'];
        
        // 加载 MessageDaemon（用于清理旧消息）
        require_once __DIR__ . '/MessageDaemon.php';

        // 检查是否需要清理旧消息（每小时最多一次）
        $lastClean = $_SESSION['last_msg_clean'] ?? 0;
        $hourAgo = time() - 3600;
        if ($lastClean < $hourAgo) {
            MessageDaemon::cleanOldMessages(1);
            $_SESSION['last_msg_clean'] = time();
        }
        
        log_game('LOGIN', "用户 $username 登录成功");
        
        return [
            'success' => true,
            'user' => $user,
            'characters' => $characters
        ];
    }
    
    /**
     * 用户注册
     */
    public static function register(string $username, string $password): array {
        // 检查用户名是否已存在
        if (UserModel::findByUsername($username)) {
            return ['success' => false, 'message' => '用户名已存在'];
        }
        
        // 创建用户
        try {
            $userId = UserModel::create([
                'username' => $username,
                'password' => $password
            ]);
            
            log_game('REGISTER', "新用户注册: $username");
            
            return ['success' => true, 'user_id' => $userId];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '注册失败: ' . $e->getMessage()];
        }
    }
    
    /**
     * 选择角色进入游戏
     */
    public static function selectCharacter(int $charId): array {
        $char = CharacterModel::find($charId);
        
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        // 验证角色归属
        if ($char['user_id'] != $_SESSION['user_id']) {
            return ['success' => false, 'message' => '无权访问此角色'];
        }
        
        // 设置会话
        $_SESSION['char_id'] = $charId;
        $_SESSION['char_name'] = $char['name'];

        // 每次登录重算气血/精力/精神上限（con/str/age/max_force 可能已变化）
        CharacterModel::recalculateVitals($charId);

        // ★ 从数据库重建装备属性加成到 Session（char_apply_）
        // 防止 Session 丢失后装备属性不生效
        require_once __DIR__ . '/../helpers/WeaponHelper.php';
        require_once __DIR__ . '/../helpers/ArmorHelper.php';
        WeaponHelper::rebuildWeaponApply($charId);
        ArmorHelper::rebuildArmorApply($charId);

        // 更新在线状态
        CharacterModel::updateOnlineStatus($charId, true);
        
        // 检查天魔茧自动销毁（资格不够时消失）
        require_once __DIR__ . '/../helpers/TianmojianHelper.php';
        TianmojianHelper::checkAutoDestroy($charId);
        
        // 发送登录消息
        require_once __DIR__ . '/../helpers/SystemBroadcast.php';
        SystemBroadcast::playerLogin($char['name'], $char['id']);
        
        // 发送房间内进入消息
        require_once __DIR__ . '/MessageDaemon.php';
        $roomMessage = "{$char['name']}连线进入这个世界。";
        MessageDaemon::broadcastToRoom(
            $char['current_room'],
            $roomMessage,
            intval($charId)
        );
        
        log_game('SELECT_CHAR', "玩家 {$char['name']} 进入游戏");
        
        return ['success' => true, 'character' => $char];
    }
    
    /**
     * 创建新角色
     */
    public static function createCharacter(array $data): array {
        $userId = $_SESSION['user_id'] ?? null;
        
        if (!$userId) {
            return ['success' => false, 'message' => '请先登录'];
        }
        
        // 检查角色名是否已存在
        if (CharacterModel::findByName($data['name'])) {
            return ['success' => false, 'message' => '角色名已存在'];
        }
        
        // 检查角色数量限制
        $existingChars = CharacterModel::findByUserId($userId);
        if (count($existingChars) >= 5) {
            return ['success' => false, 'message' => '每个账号最多创建5个角色'];
        }
        
        try {
            $charId = CharacterModel::create([
                'user_id' => $userId,
                'name' => $data['name'],
                'race' => $data['race'] ?? RACE_HUMAN,
                'gender' => $data['gender'] ?? GENDER_MALE,
                'str' => $data['str'] ?? 20,
                'con' => $data['con'] ?? 20,
                'int' => $data['int'] ?? 25,
                'spi' => $data['spi'] ?? 25
            ]);
            
            log_game('CREATE_CHAR', "创建角色: {$data['name']}");
            
            return ['success' => true, 'char_id' => $charId];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '创建失败: ' . $e->getMessage()];
        }
    }
    
    /**
     * 登出
     */
    public static function logout(): void {
        $charId = get_char_id();
        
        if ($charId) {
            // 清空消息队列（离线销毁）
            try {
                Database::execute("DELETE FROM message_queue WHERE char_id = ?", [$charId]);
            } catch (Exception $e) {
                // 静默忽略
            }
            CharacterModel::updateOnlineStatus($charId, false);
            log_game('LOGOUT', "玩家登出");
        }
        
        session_destroy();
    }
}

