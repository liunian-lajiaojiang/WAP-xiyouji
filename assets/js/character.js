function showMessage(text, type) {
    var box = document.getElementById('message-box');
    box.style.display = 'block';
    box.textContent = text;
    if (type === 'success') {
        box.style.backgroundColor = '#d4edda';
        box.style.color = '#155724';
        box.style.border = '1px solid #c3e6cb';
    } else if (type === 'error') {
        box.style.backgroundColor = '#f8d7da';
        box.style.color = '#721c24';
        box.style.border = '1px solid #f5c6cb';
    }

    setTimeout(function() {
        box.style.display = 'none';
    }, 3000);
}

function startPrivateChat(targetId, targetName) {
    var message = prompt('请输入对' + targetName + '说的话：');
    if (message && message.trim() !== '') {
        fetch('action.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'action=tell&param=' + encodeURIComponent(targetName + ' ' + message)
            })
            .then(function(response) {
                var contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('服务器返回了非JSON响应，可能未登录或发生错误');
                }
                return response.json();
            })
            .then(function(data) {
                if (data.success) {
                    showMessage('消息已发送！', 'success');
                } else {
                    showMessage('发送失败：' + (data.message || '未知错误'), 'error');
                }
            })
            .catch(function(error) {
                showMessage('网络错误：' + error.message, 'error');
            });
    }
}

function doCheck(targetId) {
    fetch('action.php?action=check&target=' + targetId, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) {
            var contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('服务器返回了非JSON响应，可能未登录或发生错误');
            }
            return response.json();
        })
        .then(function(data) {
            if (data.success) {
                window.location.href = 'character.php?id=' + targetId;
            } else {
                showMessage('探查失败：' + (data.message || '未知错误'), 'error');
            }
        })
        .catch(function(error) {
            showMessage('网络错误：' + error.message, 'error');
        });
}

function doFight(targetId) {
    if (confirm('真的要与对方切磋吗？')) {
        window.location.href = 'action.php?action=fight&target=' + targetId;
    }
}

function doKill(targetId) {
    if (confirm('真的要击杀对方吗？这是PK行为！')) {
        window.location.href = 'action.php?action=kill&target=' + targetId;
    }
}

function doFollow(targetId) {
    fetch('action.php?action=follow&target=' + targetId, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) {
            var contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('服务器返回了非JSON响应，可能未登录或发生错误');
            }
            return response.json();
        })
        .then(function(data) {
            if (data.success) {
                showMessage('开始跟随', 'success');
                setTimeout(function() {
                    window.location.reload();
                }, 1000);
            } else {
                showMessage('跟随失败：' + (data.message || '未知错误'), 'error');
            }
        })
        .catch(function(error) {
            showMessage('网络错误：' + error.message, 'error');
        });
}