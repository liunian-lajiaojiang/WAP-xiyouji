<?php
/**
 * 数据库连接类
 */
class Database {
    private static ?PDO $instance = null;
    
    /**
     * 获取数据库实例（单例模式）
     */
    public static function getInstance() {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config/database.php';
            
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            );
            
            try {
                self::$instance = new PDO(
                    $dsn,
                    $config['username'],
                    $config['password'],
                    $config['options']
                );
                // 允许大查询（共享主机可能不支持，静默忽略）
                try {
                    self::$instance->exec('SET SQL_BIG_SELECTS=1');
                } catch (PDOException $e) {
                    // InfinityFree 等免费主机无 SUPER 权限，忽略
                }
            } catch (PDOException $e) {
                error_log('数据库连接失败: ' . $e->getMessage());
                $isJsonRequest = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && 
                               strpos($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '', 'application/json') !== false;
                if ($isJsonRequest) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'message' => '数据库连接失败']);
                } else {
                    die('数据库连接失败，请稍后重试');
                }
                exit;
            }
        }
        
        return self::$instance;
    }
    
    /**
     * 执行查询并返回所有结果
     */
    public static function queryAll($sql, $params = []) {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * 执行查询并返回单条结果
     */
    public static function queryOne($sql, $params = []) {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * 执行查询并返回单个标量值
     */
    public static function queryValue(string $sql, array $params = [], $default = null) {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : $value;
    }
    
    /**
     * 执行插入/更新/删除操作
     */
    public static function execute($sql, $params = []) {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
    
    /**
     * 获取最后插入的ID
     */
    public static function lastInsertId() {
        return self::getInstance()->lastInsertId();
    }
    
    /**
     * 开始事务
     */
    public static function beginTransaction(): bool {
        return self::getInstance()->beginTransaction();
    }
    
    /**
     * 提交事务
     */
    public static function commit(): bool {
        return self::getInstance()->commit();
    }
    
    /**
     * 回滚事务
     */
    public static function rollBack(): bool {
        return self::getInstance()->rollBack();
    }

    public static function addMarriedColumn(): void {
        // 检查列是否已存在
        $columns = self::queryAll("SHOW COLUMNS FROM characters LIKE 'married'");
        if (empty($columns)) {
            $sql = "ALTER TABLE characters ADD COLUMN married tinyint(1) DEFAULT 0, ADD COLUMN married_at datetime DEFAULT NULL;";
            self::execute($sql);
        }
    }
    
    public static function addRoomItemsEnchantmentsColumn(): void {
        $columns = self::queryAll("SHOW COLUMNS FROM room_items LIKE 'enchantments'");
        if (empty($columns)) {
            $sql = "ALTER TABLE room_items ADD COLUMN enchantments TEXT NULL DEFAULT NULL AFTER description";
            self::execute($sql);
        }
    }
    
    public static function addUnconsciousAndDazeColumns(): void {
        $columns = self::queryAll("SHOW COLUMNS FROM characters LIKE 'unconscious_state'");
        if (empty($columns)) {
            $sql = "ALTER TABLE characters ADD COLUMN unconscious_state tinyint(1) DEFAULT 0, ADD COLUMN unconscious_end_time int DEFAULT 0, ADD COLUMN daze_state tinyint(1) DEFAULT 0, ADD COLUMN daze_end_time int DEFAULT 0;";
            self::execute($sql);
        }
    }
    
    public static function addLiquidContainerColumns(): void {
        $columns = self::queryAll("SHOW COLUMNS FROM character_inventory LIKE 'liquid_remaining'");
        if (empty($columns)) {
            $sql = "ALTER TABLE character_inventory
                    ADD COLUMN liquid_remaining int DEFAULT 0,
                    ADD COLUMN liquid_type varchar(20) DEFAULT '',
                    ADD COLUMN liquid_name varchar(50) DEFAULT '';";
            self::execute($sql);
        }

        $roomColumns = self::queryAll("SHOW COLUMNS FROM room_items LIKE 'liquid_remaining'");
        if (empty($roomColumns)) {
            $sql = "ALTER TABLE room_items
                    ADD COLUMN liquid_remaining int DEFAULT 0,
                    ADD COLUMN liquid_type varchar(20) DEFAULT '',
                    ADD COLUMN liquid_name varchar(50) DEFAULT '';";
            self::execute($sql);
        }
    }

    public static function addSleepInvitationsTable(): void {
        // 检查表是否已存在
        $exists = self::queryOne(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sleep_invitations'"
        );

        if ($exists) {
            return;
        }

        $sql = "CREATE TABLE `sleep_invitations` (
            `id` int NOT NULL AUTO_INCREMENT,
            `from_char_id` int UNSIGNED NOT NULL COMMENT '发起邀请的角色ID',
            `to_char_id` int UNSIGNED NOT NULL COMMENT '被邀请的角色ID',
            `status` enum('pending','accepted','rejected','expired') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'pending' COMMENT '邀请状态',
            `created_at` datetime NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
            `expire_at` datetime NULL DEFAULT NULL COMMENT '过期时间',
            `resolved_at` datetime NULL DEFAULT NULL COMMENT '处理时间',
            PRIMARY KEY (`id`) USING BTREE,
            INDEX `idx_to_char`(`to_char_id` ASC) USING BTREE,
            INDEX `idx_from_char`(`from_char_id` ASC) USING BTREE,
            INDEX `idx_status`(`status` ASC) USING BTREE,
            INDEX `idx_expire_at`(`expire_at` ASC) USING BTREE
        ) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '双人睡眠邀请表' ROW_FORMAT = DYNAMIC";

        self::execute($sql);
    }

    public static function addKeeZeroTimeColumn(): void {
        $columns = self::queryAll("SHOW COLUMNS FROM characters LIKE 'near_death_time'");
        if (empty($columns)) {
            // 先检查是否有旧的 kee_zero_time 列，如果有则重命名
            $oldColumns = self::queryAll("SHOW COLUMNS FROM characters LIKE 'kee_zero_time'");
            if (!empty($oldColumns)) {
                $sql = "ALTER TABLE characters CHANGE COLUMN kee_zero_time near_death_time int DEFAULT 0 COMMENT '濒死时间戳，二次受伤触发死亡'";
                self::execute($sql);
            } else {
                $sql = "ALTER TABLE characters ADD COLUMN near_death_time int DEFAULT 0 COMMENT '濒死时间戳，二次受伤触发死亡'";
                self::execute($sql);
            }
        }
    }

    public static function addUserBlocksTable(): void {
        $exists = self::queryOne(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_blocks'"
        );
        if ($exists) {
            return;
        }
        $sql = "CREATE TABLE user_blocks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            block_type VARCHAR(20) NOT NULL COMMENT 'chat/pk/trade/move',
            blocked_by INT UNSIGNED NOT NULL,
            reason VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_user_block (user_id, block_type),
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='功能级封禁表'";
        self::execute($sql);
    }

    public static function addGuestStatusColumn(): void {
        try {
            self::execute("ALTER TABLE home_guests ADD COLUMN status VARCHAR(20) DEFAULT 'invited' AFTER invited_at");
        } catch (Exception $e) {
            // 字段已存在，忽略
        }
    }

    public static function addBabyColumns(): void {
        try {
            self::execute("ALTER TABLE home_babies ADD COLUMN hunger INT DEFAULT 0 AFTER born_at");
        } catch (Exception $e) {
            // 字段已存在，忽略
        }
        try {
            self::execute("ALTER TABLE home_babies ADD COLUMN age INT DEFAULT 0 AFTER hunger");
        } catch (Exception $e) {
            // 字段已存在，忽略
        }
    }

    /**
     * 自动创建 boards 和 posts 表（如果不存在）
     */
    public static function addBoardsAndPostsTables(): void {
        // boards 表
        $exists = self::queryOne(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boards'"
        );
        if (!$exists) {
            $sql = "CREATE TABLE `boards` (
                `id` int NOT NULL AUTO_INCREMENT,
                `board_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '留言板标识',
                `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '留言板名称',
                `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT '描述',
                `location` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '所在位置路径',
                `capacity` int NOT NULL DEFAULT 100 COMMENT '容量',
                `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`) USING BTREE,
                UNIQUE KEY `uk_board_id` (`board_id`) USING BTREE
            ) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci";
            self::execute($sql);
        }

        // posts 表
        $exists = self::queryOne(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts'"
        );
        if (!$exists) {
            $sql = "CREATE TABLE `posts` (
                `id` int NOT NULL AUTO_INCREMENT,
                `board_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '所属留言板',
                `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '标题',
                `author` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '作者',
                `author_id` int UNSIGNED DEFAULT NULL COMMENT '作者角色ID',
                `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT '内容',
                `post_number` int NOT NULL DEFAULT 0 COMMENT '帖子序号',
                `post_time` int UNSIGNED NOT NULL DEFAULT 0 COMMENT '发帖时间戳',
                `reply_to` int DEFAULT 0 COMMENT '回复的帖子ID',
                `is_pinned` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否置顶',
                `view_count` int NOT NULL DEFAULT 0 COMMENT '浏览次数',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`) USING BTREE,
                INDEX `idx_board_id` (`board_id` ASC) USING BTREE,
                INDEX `idx_reply_to` (`reply_to` ASC) USING BTREE,
                INDEX `idx_post_time` (`post_time` ASC) USING BTREE
            ) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci";
            self::execute($sql);
        }
    }
}

