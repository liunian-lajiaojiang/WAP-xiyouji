<?php
/**
 * get_latest_news.php
 * 获取最新新闻接口
 */
require_once __DIR__ . '/config/game.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/json');

try {
    $id = $_GET['id'] ?? null;
    $date = $_GET['date'] ?? null;
    
    if ($id) {
        // 按指定ID查询新闻
        $sql = "SELECT title, content FROM news WHERE id = ?";
        $news = Database::queryOne($sql, [$id]);
    } elseif ($date) {
        // 按指定日期查询新闻
        $sql = "SELECT title, content FROM news WHERE DATE(created_at) = ? ORDER BY created_at DESC LIMIT 1";
        $news = Database::queryOne($sql, [$date]);
    } else {
        // 获取最新的新闻（标记为is_latest=1）
        $sql = "SELECT title, content FROM news WHERE is_latest = 1 ORDER BY created_at DESC LIMIT 1";
        $news = Database::queryOne($sql);
    }
    
    if ($news) {
        // 返回新闻数据
        echo json_encode([
            'success' => true,
            'news' => [
                'title' => $news['title'],
                'content' => $news['content']
            ]
        ]);
    } else {
        // 没有找到最新新闻
        echo json_encode([
            'success' => false,
            'message' => 'No recent news found.'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
