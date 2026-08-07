<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 留言板系统 - 基于数据库存储
 */

// 显示错误（调试用）
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../models/Character.php';

// 确保 boards 和 posts 表存在
Database::addBoardsAndPostsTables();

// 获取当前用户信息（可选）
$charId = get_char_id();
$char = $charId ? CharacterModel::find($charId) : null;

$action = $_GET['action'] ?? 'list';
$page = intval($_GET['page'] ?? 1);
$postId = intval($_GET['id'] ?? 0);

// 留言板ID（从URL参数获取）
$boardId = $_GET['board'] ?? 'nancheng_b';

// 每页显示数量
$perPage = 20;

?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>留言板 - 西游记MUD</title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
    <link rel="stylesheet" href="../assets/css/board.css">
</head>

<body>
    <?php
    // 获取留言板信息
    try {
        $board = Database::queryOne(
            "SELECT * FROM boards WHERE board_id = ? AND is_active = 1",
            [$boardId]
        );
    } catch (Exception $e) {
        $board = null;
    }
    ?>

    <?php if (!$board): ?>
        📋 留言板
        <p>留言板不存在或未激活</p>
    <?php else: ?>
        📋 <?= h($board['name']) ?>
        <p>
            <?= nl2br(h(str_replace('\\n', "\n", $board['description']))) ?>
            <?php if ($board['location']): ?>
                <br>位置：<?= h($board['location']) ?>
            <?php endif; ?>
        </p>

        <p>
            <?php if ($action === 'view'): ?>
                <a href="?board=<?= urlencode($boardId) ?>&action=list">« 返回列表</a>
            <?php endif; ?>
            <?php if ($charId): ?>
                <a href="?board=<?= urlencode($boardId) ?>&action=new">发表留言</a>
            <?php else: ?>
                <span style="color: #999;">发表留言（需登录）</span>
            <?php endif; ?>
            <a href="room.php">返回房间</a>
        </p>

        <?php if ($action === 'list'): ?>
            <!-- 留言列表 -->
            <?php
            try {
                $offset = ($page - 1) * $perPage;

                // 获取总数
                $totalResult = Database::queryOne(
                    "SELECT COUNT(*) as count FROM posts WHERE board_id = ? AND (reply_to IS NULL OR reply_to = 0)",
                    [$boardId]
                );
                $totalCount = intval($totalResult['count'] ?? 0);
                $totalPages = ceil($totalCount / $perPage);

                // 获取留言列表
                $posts = Database::queryAll(
                    "SELECT id, title, author, post_time, view_count, is_pinned 
                         FROM posts 
                         WHERE board_id = ? AND (reply_to IS NULL OR reply_to = 0)
                         ORDER BY is_pinned DESC, post_time DESC
                         LIMIT {$perPage} OFFSET {$offset}",
                    [$boardId]
                );
            } catch (Exception $e) {
                $posts = [];
                $totalCount = 0;
                $totalPages = 0;
            }
            ?>

            <?php if (empty($posts)): ?>
                <p style="color: #999; text-align: center;">暂无留言，快来发表第一条吧！</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($posts as $post): ?>
                        <li>
                            <?php if ($post['is_pinned']): ?>
                                <span style="color: #ff6600;">[置顶]</span>
                            <?php endif; ?>
                            <a href="?board=<?= urlencode($boardId) ?>&action=view&id=<?= $post['id'] ?>">
                                <?= h($post['title']) ?>
                            </a>
                            <small>
                                - 作者：<?= h($post['author']) ?> |
                                <?= date('Y-m-d H:i', $post['post_time']) ?> |
                                浏览：<?= intval($post['view_count']) ?>次
                            </small>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <!-- 分页 -->
                <?php if ($totalPages > 1): ?>
                    <p>
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <?php if ($i == $page): ?>
                                <span><?= $i ?></span>
                            <?php else: ?>
                                <a href="?board=<?= urlencode($boardId) ?>&action=list&page=<?= $i ?>"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>

        <?php elseif ($action === 'view' && $postId > 0): ?>
            <!-- 查看留言 -->
            <?php
            try {
                $post = Database::queryOne(
                    "SELECT * FROM posts WHERE id = ?",
                    [$postId]
                );

                if ($post) {
                    // 增加浏览次数
                    Database::execute(
                        "UPDATE posts SET view_count = view_count + 1 WHERE id = ?",
                        [$postId]
                    );

                    // 获取回复
                    $replies = Database::queryAll(
                        "SELECT * FROM posts WHERE reply_to = ? ORDER BY post_time ASC",
                        [$postId]
                    );
                }
            } catch (Exception $e) {
                $post = null;
                $replies = [];
            }
            ?>

            <?php if (!$post): ?>
                <p style="color: red;">留言不存在</p>
            <?php else: ?>
                <h2><?= h($post['title']) ?></h2>
                <p>
                    <small>
                        作者：<?= h($post['author']) ?> |
                        <?= date('Y-m-d H:i', $post['post_time']) ?> |
                        浏览：<?= intval($post['view_count']) ?>次
                    </small>
                </p>
                <p><?= nl2br(h($post['content'])) ?></p>

                <?php if (!empty($replies)): ?>
                    <h3>回复 (<?= count($replies) ?>)</h3>
                    <?php foreach ($replies as $reply): ?>
                        <blockquote>
                            <p>
                                <strong><?= h($reply['author']) ?></strong> |
                                <small><?= date('Y-m-d H:i', $reply['post_time']) ?></small>
                            </p>
                            <p><?= nl2br(h($reply['content'])) ?></p>
                        </blockquote>
                    <?php endforeach; ?>
                <?php endif; ?>

                <p>
                    <?php if ($charId): ?>
                        <a href="?board=<?= urlencode($boardId) ?>&action=reply&id=<?= $post['id'] ?>">回复此留言</a>
                    <?php else: ?>
                        <span style="color: #999;">回复此留言（需登录）</span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

        <?php elseif ($action === 'new'): ?>
            <!-- 发表新留言 -->
            <?php
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // 检查登录状态
                if (!$charId) {
                    echo '<p style="color: red;">请先登录后再发表留言</p>';
                } else {
                    $title = trim($_POST['title'] ?? '');
                    $content = trim($_POST['content'] ?? '');

                    if (empty($title) || empty($content)) {
                        echo '<p>标题和内容不能为空</p>';
                    } else {
                        try {
                            // 检查容量
                            $countResult = Database::queryOne(
                                "SELECT COUNT(*) as count FROM posts WHERE board_id = ? AND (reply_to IS NULL OR reply_to = 0)",
                                [$boardId]
                            );
                            $currentCount = intval($countResult['count']);

                            if ($currentCount >= $board['capacity']) {
                                // 删除最旧的20%留言
                                $deleteCount = intval($board['capacity'] / 5);
                                Database::execute(
                                    "DELETE FROM posts WHERE board_id = ? AND (reply_to IS NULL OR reply_to = 0) ORDER BY post_time ASC LIMIT {$deleteCount}",
                                    [$boardId]
                                );
                            }

                            // 获取当前最大序号
                            $maxPost = Database::queryOne(
                                "SELECT MAX(post_number) as max_num FROM posts WHERE board_id = ?",
                                [$boardId]
                            );

                            $nextNumber = intval($maxPost['max_num']) + 1;

                            // 插入新留言
                            Database::execute(
                                "INSERT INTO posts 
                                    (board_id, title, author, author_id, content, post_number, post_time)
                                    VALUES (?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP())",
                                [$boardId, $title, $char['name'], $charId, $content, $nextNumber]
                            );

                            echo '<div>留言发表成功！</div>';
                            echo '<p><a href="?board=' . urlencode($boardId) . '&action=list">返回列表</a></p>';
                        } catch (Exception $e) {
                            echo '<p>发表失败：' . h($e->getMessage()) . '</p>';
                        }
                    }
                }
            }
            ?>

            <form method="POST">
                <div>
                    <label for="title">标题：</label>
                    <input type="text" id="title" name="title" required maxlength="200">
                </div>

                <div>
                    <label for="content">内容：</label>
                    <textarea id="content" name="content" required></textarea>
                </div>

                <button type="submit">发表</button>
            </form>

        <?php elseif ($action === 'reply' && $postId > 0): ?>
            <!-- 回复留言 -->
            <?php
            try {
                $originalPost = Database::queryOne(
                    "SELECT * FROM posts WHERE id = ?",
                    [$postId]
                );
            } catch (Exception $e) {
                $originalPost = null;
            }
            ?>

            <?php if (!$originalPost): ?>
                <p style="color: red;">原留言不存在</p>
            <?php else: ?>
                <h3>回复：<?= h($originalPost['title']) ?></h3>

                <?php
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    // 检查登录状态
                    if (!$charId) {
                        echo '<p style="color: red;">请先登录后再回复</p>';
                    } else {
                        $content = trim($_POST['content'] ?? '');

                        if (empty($content)) {
                            echo '<div>内容不能为空</div>';
                        } else {
                            try {
                                // 获取当前最大序号
                                $maxPost = Database::queryOne(
                                    "SELECT MAX(post_number) as max_num FROM posts WHERE board_id = ?",
                                    [$boardId]
                                );

                                $nextNumber = intval($maxPost['max_num']) + 1;

                                // 插入回复
                                Database::execute(
                                    "INSERT INTO posts 
                                        (board_id, title, author, author_id, content, post_number, post_time, reply_to)
                                        VALUES (?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP(), ?)",
                                    [
                                        $boardId,
                                        'Re: ' . $originalPost['title'],
                                        $char['name'],
                                        $charId,
                                        $content,
                                        $nextNumber,
                                        $postId
                                    ]
                                );

                                echo '<p>回复成功！</p>';
                                echo '<p><a href="?board=' . urlencode($boardId) . '&action=view&id=' . $postId . '">查看留言</a></p>';
                            } catch (Exception $e) {
                                echo '<p>回复失败：' . h($e->getMessage()) . '</p>';
                            }
                        }
                    }
                }
                ?>

                <form method="POST">
                    <div>
                        <label for="content">回复内容：</label>
                        <textarea id="content" name="content" required></textarea>
                    </div>

                    <button type="submit">提交回复</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</body>

</html>

