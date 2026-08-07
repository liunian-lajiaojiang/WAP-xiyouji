<?php
// 加载游戏配置
require_once __DIR__ . '/config/game.php';
require_once __DIR__ . '/includes/db.php';

// 分页参数
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$pageSize = 1; // 每页显示1条新闻
$offset = ($page - 1) * $pageSize;

// 获取总记录数
$totalResult = Database::queryOne("SELECT COUNT(*) as total FROM news");
$total = $totalResult['total'] ?? 0;
$totalPages = ceil($total / $pageSize);

// 获取当前页数据（按sort_order排序，页1显示新闻一，页2显示新闻二，页3显示新闻三）
$sql = "SELECT id, title, content, is_latest, created_at FROM news ORDER BY id ASC LIMIT {$pageSize} OFFSET {$offset}";
$newsList = Database::queryAll($sql);

// 生成URL参数（保留分页参数）
function getPageUrl($p) {
    return '?page=' . $p;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta http-equiv="Content-Language" content="zh-cn">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
<meta name="keywords" content="西游记mud,西游记怀旧mud，西游记h5" />
<meta name="description" content="西游记mud是源自Mud西游记2000的经典还原H5网页文字游戏。" />
<link rel="shortcut icon" href="assets/images/favicon.ico" />
<link rel="stylesheet" href="assets/css/light-theme.css">
    <script src="assets/js/theme-init.js"></script>
<link rel="stylesheet" href="assets/css/footer.css" />
<title>公告_西游记mud</title>
</head>

<body>
<a href="#" onclick="javascript:history.back(-1);">返回</a>&ensp;<a href="javascript:location.reload();">公告</a>
<hr />

<?php // 动态显示当前页新闻 ?>
<?php if (!empty($newsList)): ?>
<?php $news = $newsList[0]; ?>
<h1><?php echo htmlspecialchars($news['title']) ?: ''; ?></h1>
<?php echo nl2br(htmlspecialchars($news['content'])); ?>
<?php endif; ?>
<br>
<br>
<br>
发布时间：<?php echo htmlspecialchars($news['created_at']) ?: ''; ?>
<?php if ($totalPages > 1): ?>
<div class="pagination" style="text-align:center;margin:20px 0;">
    <?php if ($page > 1): ?>
        <a href="<?php echo getPageUrl(1); ?>">首页</a>
        <a href="<?php echo getPageUrl($page - 1); ?>">上一页</a>
    <?php endif; ?>
    
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <?php if ($i == $page): ?>
            <span style="font-weight:bold;color:#FF6600;">[<?php echo $i; ?>]</span>
        <?php else: ?>
            <a href="<?php echo getPageUrl($i); ?>">[<?php echo $i; ?>]</a>
        <?php endif; ?>
    <?php endfor; ?>
    
    <?php if ($page < $totalPages): ?>
        <a href="<?php echo getPageUrl($page + 1); ?>">下一页</a>
        <a href="<?php echo getPageUrl($totalPages); ?>">末页</a>
    <?php endif; ?>
    
    <span style="margin-left:10px;">共 <?php echo $total; ?> 条</span>
</div>
<?php endif; ?>
<hr>
<div class="back-link">当前时间:
<script type="text/javascript" src="assets/js/time.js"></script>
<br>
<a href="javascript:location.reload();">刷新此页面</a> | 
<a href="about_us.html" target="_blank">关于我们</a>
</div>
</body>
</html>
