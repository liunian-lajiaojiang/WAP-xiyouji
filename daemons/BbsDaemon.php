<?php
/**
 * BBS 留言板系统守护进程
 * 处理留言板的读取、发帖、回帖等功能
 */

require_once __DIR__ . '/../includes/db.php';

class BbsDaemon {
    
    /**
     * 获取留言板信息
     * @param string $boardId 留言板ID
     * @return array|null 留言板信息
     */
    public static function getBoard(string $boardId): ?array {
        try {
            $board = Database::queryOne(
                "SELECT * FROM boards WHERE board_id = ? AND is_active = 1",
                [$boardId]
            );
            
            return $board ?: null;
        } catch (Exception $e) {
            error_log("获取留言板失败: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 获取留言板的所有帖子列表
     * @param string $boardId 留言板ID
     * @param int $page 页码（每页20条）
     * @return array 帖子列表
     */
    public static function getPostList(string $boardId, int $page = 1): array {
        try {
            $perPage = 20;
            $offset = ($page - 1) * $perPage;
            
            // 获取总数
            $total = Database::queryOne(
                "SELECT COUNT(*) as count FROM posts WHERE board_id = ?",
                [$boardId]
            );
            
            $totalCount = intval($total['count']);
            $totalPages = ceil($totalCount / $perPage);
            
            // 获取帖子列表（按序号排序）
            $posts = Database::queryAll(
                "SELECT id, post_number, title, author, post_time, view_count, is_pinned, is_locked
                 FROM posts 
                 WHERE board_id = ?
                 ORDER BY is_pinned DESC, post_number DESC
                 LIMIT {$perPage} OFFSET {$offset}",
                [$boardId]
            );
            
            return [
                'posts' => $posts ?: [],
                'total_count' => $totalCount,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'per_page' => $perPage
            ];
        } catch (Exception $e) {
            error_log("获取帖子列表失败: " . $e->getMessage());
            return [
                'posts' => [],
                'total_count' => 0,
                'total_pages' => 0,
                'current_page' => $page,
                'per_page' => 20
            ];
        }
    }
    
    /**
     * 获取单个帖子的详细内容
     * @param int $postId 帖子ID
     * @return array|null 帖子详情
     */
    public static function getPost(int $postId): ?array {
        try {
            $post = Database::queryOne(
                "SELECT * FROM posts WHERE id = ?",
                [$postId]
            );
            
            if (!$post) {
                return null;
            }
            
            // 增加浏览次数
            Database::execute(
                "UPDATE posts SET view_count = view_count + 1 WHERE id = ?",
                [$postId]
            );
            
            return $post;
        } catch (Exception $e) {
            error_log("获取帖子详情失败: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 根据序号获取帖子
     * @param string $boardId 留言板ID
     * @param int $postNumber 帖子序号
     * @return array|null 帖子详情
     */
    public static function getPostByNumber(string $boardId, int $postNumber): ?array {
        try {
            $post = Database::queryOne(
                "SELECT * FROM posts WHERE board_id = ? AND post_number = ?",
                [$boardId, $postNumber]
            );
            
            if (!$post) {
                return null;
            }
            
            // 增加浏览次数
            Database::execute(
                "UPDATE posts SET view_count = view_count + 1 WHERE id = ?",
                [$post['id']]
            );
            
            return $post;
        } catch (Exception $e) {
            error_log("获取帖子失败: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 发布新帖子
     * @param string $boardId 留言板ID
     * @param string $title 标题
     * @param string $content 内容
     * @param string $author 作者名字
     * @param int|null $authorId 作者角色ID
     * @return array 结果
     */
    public static function createPost(string $boardId, string $title, string $content, string $author, ?int $authorId = null): array {
        try {
            // 检查留言板是否存在
            $board = self::getBoard($boardId);
            if (!$board) {
                return ['success' => false, 'message' => '留言板不存在'];
            }
            
            // 获取当前最大序号
            $maxPost = Database::queryOne(
                "SELECT MAX(post_number) as max_num FROM posts WHERE board_id = ?",
                [$boardId]
            );
            
            $nextNumber = intval($maxPost['max_num']) + 1;
            
            // 检查是否超过容量
            if ($nextNumber > $board['capacity']) {
                return ['success' => false, 'message' => '留言板已满，请联系管理员清理'];
            }
            
            // 插入新帖子
            Database::execute(
                "INSERT INTO posts 
                (board_id, title, author, author_id, content, post_number, post_time)
                VALUES (?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP())",
                [$boardId, $title, $author, $authorId, $content, $nextNumber]
            );
            
            return [
                'success' => true,
                'message' => '发帖成功',
                'post_number' => $nextNumber
            ];
        } catch (Exception $e) {
            error_log("发帖失败: " . $e->getMessage());
            return ['success' => false, 'message' => '发帖失败：' . $e->getMessage()];
        }
    }
    
    /**
     * 回复帖子
     * @param int $replyToId 回复的帖子ID
     * @param string $content 回复内容
     * @param string $author 作者名字
     * @param int|null $authorId 作者角色ID
     * @return array 结果
     */
    public static function replyPost(int $replyToId, string $content, string $author, ?int $authorId = null): array {
        try {
            // 获取原帖信息
            $originalPost = self::getPost($replyToId);
            if (!$originalPost) {
                return ['success' => false, 'message' => '原帖不存在'];
            }
            
            // 检查是否被锁定
            if ($originalPost['is_locked']) {
                return ['success' => false, 'message' => '该帖子已被锁定，无法回复'];
            }
            
            // 获取当前最大序号
            $maxPost = Database::queryOne(
                "SELECT MAX(post_number) as max_num FROM posts WHERE board_id = ?",
                [$originalPost['board_id']]
            );
            
            $nextNumber = intval($maxPost['max_num']) + 1;
            
            // 插入回复
            Database::execute(
                "INSERT INTO posts 
                (board_id, title, author, author_id, content, post_number, post_time, reply_to)
                VALUES (?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP(), ?)",
                [
                    $originalPost['board_id'],
                    'Re: ' . $originalPost['title'],
                    $author,
                    $authorId,
                    $content,
                    $nextNumber,
                    $replyToId
                ]
            );
            
            return [
                'success' => true,
                'message' => '回复成功',
                'post_number' => $nextNumber
            ];
        } catch (Exception $e) {
            error_log("回复失败: " . $e->getMessage());
            return ['success' => false, 'message' => '回复失败：' . $e->getMessage()];
        }
    }
    
    /**
     * 删除帖子
     * @param int $postId 帖子ID
     * @param int $charId 操作者角色ID（需要权限检查）
     * @return array 结果
     */
    public static function deletePost(int $postId, int $charId): array {
        try {
            // TODO: 添加权限检查（只有作者或管理员可以删除）
            
            Database::execute(
                "DELETE FROM posts WHERE id = ?",
                [$postId]
            );
            
            return ['success' => true, 'message' => '删除成功'];
        } catch (Exception $e) {
            error_log("删除帖子失败: " . $e->getMessage());
            return ['success' => false, 'message' => '删除失败'];
        }
    }
    
    /**
     * 格式化时间戳为可读格式
     * @param int $timestamp Unix 时间戳
     * @return string 格式化后的时间
     */
    public static function formatTime(int $timestamp): string {
        return date('Y-m-d H:i:s', $timestamp);
    }
}

