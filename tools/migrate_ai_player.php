<?php
/**
 * AI 玩家数据库迁移
 * 
 * 添加 characters 表所需的 AI 玩家字段
 * 
 * 用法：
 *   C:\BtSoft\php\85\php.exe U:\xyj\tools\migrate_ai_player.php
 */

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/db.php';

echo "=== AI 玩家数据库迁移 ===\n\n";

// 1. 检查并添加 is_ai_player 字段
echo "1. 检查 is_ai_player 字段...\n";
$columns = Database::queryAll("SHOW COLUMNS FROM characters LIKE 'is_ai_player'");
if (empty($columns)) {
    // 注意：不使用 AFTER 子句，避免字段名含斜杠导致的语法问题
    Database::execute(
        "ALTER TABLE characters ADD COLUMN is_ai_player TINYINT(1) DEFAULT 0 COMMENT '是否为AI玩家：0=否, 1=是'"
    );
    echo "   ✓ 已添加 is_ai_player 字段\n";
} else {
    echo "   ✓ is_ai_player 字段已存在\n";
}

// 2. 检查并添加 ai_last_action 字段
echo "2. 检查 ai_last_action 字段...\n";
$columns = Database::queryAll("SHOW COLUMNS FROM characters LIKE 'ai_last_action'");
if (empty($columns)) {
    Database::execute(
        "ALTER TABLE characters ADD COLUMN ai_last_action INT DEFAULT 0 COMMENT 'AI玩家最后动作时间戳' AFTER is_ai_player"
    );
    echo "   ✓ 已添加 ai_last_action 字段\n";
} else {
    echo "   ✓ ai_last_action 字段已存在\n";
}

// 3. 创建 ai_player_logs 日志表（可选）
echo "3. 检查 ai_player_logs 表...\n";
$exists = Database::queryOne(
    "SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_player_logs'"
);
if (!$exists) {
    Database::execute(
        "CREATE TABLE ai_player_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            char_id INT UNSIGNED NOT NULL COMMENT '角色ID',
            char_name VARCHAR(50) NOT NULL COMMENT '角色名',
            action_type VARCHAR(30) NOT NULL COMMENT '行为类型: move, rest, train, chat, combat, stay',
            action_detail VARCHAR(255) DEFAULT '' COMMENT '行为详情',
            success TINYINT(1) DEFAULT 1 COMMENT '是否成功',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '时间',
            INDEX idx_char_id (char_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI玩家行为日志表'"
    );
    echo "   ✓ 已创建 ai_player_logs 表\n";
} else {
    echo "   ✓ ai_player_logs 表已存在\n";
}

echo "\n=== 迁移完成 ===\n";
echo "\n提示：\n";
echo "  - 使用 AiPlayerHelper::markAsAiPlayer(\$charId) 标记角色为 AI 玩家\n";
echo "  - 使用 AiPlayerDaemon::createAndLogin('角色名') 创建新的 AI 玩家\n";
echo "  - 运行定时任务: php tasks/AiPlayerTickTask.php\n";
