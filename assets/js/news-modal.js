/* 新闻弹窗脚本 */
function showNewsModal() {
    document.getElementById('newsModal').style.display = 'flex';
}

function closeNewsModal() {
    document.getElementById('newsModal').style.display = 'none';
}

async function getLatestNews() {
    try {
        const response = await fetch('get_latest_news.php');
        const data = await response.json();
        if (data.success && data.news) {
            document.getElementById('newsContent').innerHTML = 
                `<h4>${data.news.title}</h4>` +
                `<div>${data.news.content.replace(/\n/g, '<br>')}</div>`;
            
            if (!sessionStorage.getItem('newsShown_main')) {
                showNewsModal();
                sessionStorage.setItem('newsShown_main', '1');
            }
        }
    } catch (error) {
        console.error('获取新闻失败:', error);
    }
}

window.addEventListener('load', getLatestNews);
