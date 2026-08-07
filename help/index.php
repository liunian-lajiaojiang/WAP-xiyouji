<?php
/**
 * 帮助系统首页
 */
require_once __DIR__ . '/../includes/functions.php';

$helpDir = __DIR__;

// 获取主要帮助分类
$categories = [
    'newbie' => '新手指南',
    'start' => '开始游戏',
    'topics' => '帮助主题',
    'theme' => '游戏背景',
    'rules' => '游戏规则',
    'wiz_rules' => '巫师规则',
];

// 子目录分类
$subdirs = [
    'concepts' => '游戏概念',
    'hints' => '游戏技巧',
    'maps' => '地图指南',
    'menpais' => '门派介绍',
    'misc' => '其他帮助',
    'qujing' => '取经任务',
    'specials' => '特殊功能',
];

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>帮助系统 - WAP西游记2012</title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <style>
        .help-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .help-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            text-align: center;
        }
        .help-header h1 {
            margin: 0;
            font-size: 32px;
        }
        .help-header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
        }
        .help-section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .help-section h2 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
            margin-top: 0;
        }
        .help-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .help-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            border-left: 4px solid #3498db;
            transition: all 0.3s;
        }
        .help-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        .help-item a {
            color: #2c3e50;
            text-decoration: none;
            font-weight: bold;
            display: block;
        }
        .help-item a:hover {
            color: #3498db;
        }
        .help-item .desc {
            color: #7f8c8d;
            font-size: 14px;
            margin-top: 5px;
        }
        .book-link {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin-top: 20px;
        }
        .book-link a {
            color: white;
            text-decoration: none;
            font-size: 18px;
            font-weight: bold;
        }
        .book-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
<div class="help-container">
    <div class="help-header">
        <h1>📚 西游记MUD 帮助系统</h1>
        <p>探索西游世界，从这里开始</p>
    </div>

    <div class="help-section">
        <h2>🎯 快速入门</h2>
        <div class="help-grid">
            <?php foreach ($categories as $file => $title): ?>
                <?php if (file_exists($helpDir . '/' . $file)): ?>
                <div class="help-item">
                    <a href="?topic=<?php echo urlencode($file); ?>"><?php echo h($title); ?></a>
                    <div class="desc">查看<?php echo h($title); ?></div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="help-section">
        <h2>📖 详细指南</h2>
        <div class="help-grid">
            <?php foreach ($subdirs as $dir => $title): ?>
                <?php if (is_dir($helpDir . '/' . $dir)): ?>
                <div class="help-item">
                    <a href="?category=<?php echo urlencode($dir); ?>"><?php echo h($title); ?></a>
                    <div class="desc"><?php 
                        $count = count(glob($helpDir . '/' . $dir . '/*'));
                        echo "共 {$count} 个主题";
                    ?></div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="book-link">
        <a href="?topic=BOOK.TXT">📕 查看完整帮助手册 (BOOK.TXT)</a>
    </div>
</div>

<?php
// 显示具体帮助内容
$topic = $_GET['topic'] ?? '';
$category = $_GET['category'] ?? '';

if ($topic) {
    // 处理子目录文件 (例如: qujing/baoxiang)
    $topicParts = explode('/', $topic);
    if (count($topicParts) > 1) {
        // 有子目录
        $subdir = basename($topicParts[0]);
        $filename = basename($topicParts[1]);
        $filePath = $helpDir . '/' . $subdir . '/' . $filename;
    } else {
        // 没有子目录
        $filePath = $helpDir . '/' . basename($topic);
    }
    
    // 检查是否是目录
    if (is_dir($filePath)) {
        // 如果是目录，显示目录中的文件列表
        $dirName = basename($topic);
        echo '<div class="help-container">';
        echo '<div class="help-section">';
        echo '<h2>📁 ' . h($subdirs[$dirName] ?? $dirName) . '</h2>';
        echo '<div class="help-grid">';
        
        $files = glob($filePath . '/*');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    $filename = basename($file);
                    echo '<div class="help-item">';
                    echo '<a href="?topic=' . urlencode($topic . '/' . $filename) . '">' . h($filename) . '</a>';
                    echo '</div>';
                }
            }
        } else {
            echo '<p>该目录下暂无文件。</p>';
        }
        
        echo '</div>';
        echo '</div>';
        echo '</div>';
        return;
    }
    
    if (file_exists($filePath) && is_file($filePath)) {
        // 读取文件内容
        $content = file_get_contents($filePath);
        
        // 尝试检测并转换编码（从GBK到UTF-8）
        $encoding = detect_encoding($content);
        if ($encoding && $encoding !== 'UTF-8') {
            $content = convert_to_utf8($content, $encoding);
        }
        
        // 生成友好的标题
        $displayTitle = $topic;
        if (count($topicParts) > 1) {
            $subdirName = $subdirs[$topicParts[0]] ?? $topicParts[0];
            $displayTitle = $subdirName . ' - ' . $filename;
        }
        
        echo '<div class="help-container">';
        echo '<div class="help-section">';
        echo '<h2>' . h($displayTitle) . '</h2>';
        echo '<pre style="white-space: pre-wrap; word-wrap: break-word; line-height: 1.8; font-family: monospace;">';
        echo h($content);
        echo '</pre>';
        echo '<p><a href="index.php">← 返回帮助首页</a></p>';
        echo '</div>';
        echo '</div>';
    }
} elseif ($category) {
    $catDir = $helpDir . '/' . basename($category);
    if (is_dir($catDir)) {
        echo '<div class="help-container">';
        echo '<div class="help-section">';
        echo '<h2>' . h($subdirs[$category] ?? $category) . '</h2>';
        echo '<div class="help-grid">';
        
        $files = glob($catDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                $filename = basename($file);
                echo '<div class="help-item">';
                echo '<a href="?topic=' . urlencode($category . '/' . $filename) . '">' . h($filename) . '</a>';
                echo '</div>';
            }
        }
        
        echo '</div>';
        echo '<p><a href="index.php">← 返回帮助首页</a></p>';
        echo '</div>';
        echo '</div>';
    }
}
?>

</body>
</html>

<?php
/**
 * 检测字符串编码（不依赖mbstring扩展）
 * 
 * @param string $str 要检测的字符串
 * @return string|null 编码名称，无法检测则返回null
 */
function detect_encoding(string $str): ?string {
    // 先尝试使用mb_detect_encoding（如果可用）
    if (function_exists('mb_detect_encoding')) {
        $encoding = mb_detect_encoding($str, ['UTF-8', 'GBK', 'GB2312'], true);
        if ($encoding) {
            return $encoding;
        }
    }
    
    // 手动检测UTF-8
    if (is_utf8($str)) {
        return 'UTF-8';
    }
    
    // 检测GBK/GB2312（中文编码）
    if (contains_chinese($str)) {
        // 简单判断：如果不是UTF-8且包含中文，可能是GBK
        return 'GBK';
    }
    
    return null;
}

/**
 * 判断字符串是否为UTF-8编码
 */
function is_utf8(string $str): bool {
    $len = strlen($str);
    for ($i = 0; $i < $len; $i++) {
        $c = ord($str[$i]);
        if ($c < 0x80) {
            // 单字节
            continue;
        } elseif (($c & 0xE0) == 0xC0) {
            // 双字节
            if ($i + 1 >= $len) return false;
            $c2 = ord($str[$i + 1]);
            if (($c2 & 0xC0) != 0x80) return false;
            $i++;
        } elseif (($c & 0xF0) == 0xE0) {
            // 三字节
            if ($i + 2 >= $len) return false;
            $c2 = ord($str[$i + 1]);
            $c3 = ord($str[$i + 2]);
            if (($c2 & 0xC0) != 0x80 || ($c3 & 0xC0) != 0x80) return false;
            $i += 2;
        } elseif (($c & 0xF8) == 0xF0) {
            // 四字节
            if ($i + 3 >= $len) return false;
            $c2 = ord($str[$i + 1]);
            $c3 = ord($str[$i + 2]);
            $c4 = ord($str[$i + 3]);
            if (($c2 & 0xC0) != 0x80 || ($c3 & 0xC0) != 0x80 || ($c4 & 0xC0) != 0x80) return false;
            $i += 3;
        } else {
            return false;
        }
    }
    return true;
}

/**
 * 判断字符串是否包含中文字符
 */
function contains_chinese(string $str): bool {
    for ($i = 0; $i < strlen($str); $i++) {
        $c = ord($str[$i]);
        // 中文GBK范围：0x81-0xFE (第一个字节), 0x40-0xFE (第二个字节)
        if ($c >= 0x81 && $c <= 0xFE) {
            if ($i + 1 < strlen($str)) {
                $c2 = ord($str[$i + 1]);
                if ($c2 >= 0x40 && $c2 <= 0xFE) {
                    return true;
                }
            }
        }
    }
    return false;
}

/**
 * 转换字符串编码到UTF-8（不依赖mbstring扩展）
 * 
 * @param string $str 源字符串
 * @param string $fromEncoding 源编码
 * @return string 转换后的UTF-8字符串
 */
function convert_to_utf8(string $str, string $fromEncoding): string {
    // 如果mb_convert_encoding可用，使用它
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($str, 'UTF-8', $fromEncoding);
    }
    
    // 手动实现GBK到UTF-8的转换
    if (strtoupper($fromEncoding) === 'GBK' || strtoupper($fromEncoding) === 'GB2312') {
        return gbk_to_utf8($str);
    }
    
    return $str;
}

/**
 * GBK到UTF-8的转换（手动实现）
 */
function gbk_to_utf8(string $gbk): string {
    $utf8 = '';
    $len = strlen($gbk);
    $i = 0;
    
    while ($i < $len) {
        $c = ord($gbk[$i]);
        if ($c < 0x80) {
            // ASCII字符
            $utf8 .= chr($c);
            $i++;
        } elseif ($c >= 0x81 && $c <= 0xFE) {
            // GBK双字节字符
            if ($i + 1 < $len) {
                $c2 = ord($gbk[$i + 1]);
                $gbk_code = ($c << 8) | $c2;
                
                // 使用iconv（如果可用）
                if (function_exists('iconv')) {
                    $utf8 .= iconv('GBK', 'UTF-8', chr($c) . chr($c2));
                } else {
                    // 简单回退：保留原样
                    $utf8 .= chr($c) . chr($c2);
                }
            }
            $i += 2;
        } else {
            $utf8 .= chr($c);
            $i++;
        }
    }
    
    return $utf8;
}


