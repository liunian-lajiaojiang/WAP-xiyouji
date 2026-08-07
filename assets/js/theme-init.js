/**
 * 主题初始化脚本
 * 读取 localStorage 中的主题偏好，在页面加载时将 light-theme.css 替换为 dark-theme.css
 * 所有引用 light-theme.css 的页面都应包含此脚本（放在 link 标签之后）
 * room.php 有自己的 switchTheme 机制，此脚本与其兼容不冲突
 */
(function() {
    var THEME_KEY = 'xyj_theme';
    var savedTheme = localStorage.getItem(THEME_KEY) || 'light';
    if (savedTheme === 'dark') {
        var links = document.querySelectorAll('link[rel="stylesheet"]');
        for (var i = 0; i < links.length; i++) {
            var href = links[i].getAttribute('href');
            if (href && href.indexOf('light-theme.css') !== -1) {
                links[i].setAttribute('href', href.replace('light-theme.css', 'dark-theme.css'));
                break;
            }
        }
    }
})();
