var currentAction = '';
var currentParam = '';

function showMessage(msg, type) {
    var area = document.getElementById('message-area');
    area.innerHTML = '<div class="message ' + type + '">' + msg + '</div>';
    setTimeout(function() {
        area.innerHTML = '';
    }, 3000);
}

function showInputModal(action, title, prompt) {
    currentAction = action;
    document.getElementById('modal-title').textContent = title;
    document.getElementById('modal-prompt').textContent = prompt;
    document.getElementById('modal-input').value = '';
    document.getElementById('modal-input').focus();
    document.getElementById('input-modal').classList.add('show');
}

function closeModal() {
    document.getElementById('input-modal').classList.remove('show');
}

function submitModalInput() {
    var input = document.getElementById('modal-input').value.trim();
    var action = currentAction;
    closeModal();
    currentAction = '';
    executeHomeAction(action, input);
}

function executeHomeAction(action, param) {
    var url = 'action.php?action=home';
    if (param) {
        url += '&param=' + encodeURIComponent(action + ' ' + param);
    } else {
        url += '&param=' + encodeURIComponent(action);
    }

    console.log('执行动作:', action, '参数:', param);
    console.log('请求URL:', url);

    fetch(url, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(response) {
        console.log('响应状态:', response.status);
        console.log('响应头:', response.headers.get('content-type'));
        if (!response.ok) {
            throw new Error('HTTP错误: ' + response.status);
        }
        return response.text();
    })
    .then(function(text) {
        console.log('响应内容:', text);
        try {
            var data = JSON.parse(text);
            if (data.success) {
                showMessage(data.message || '操作成功！', 'success');
                if (data.redirect) {
                    setTimeout(function() {
                        window.location.href = data.redirect;
                    }, 500);
                } else {
                    setTimeout(function() {
                        window.location.reload();
                    }, 500);
                }
            } else {
                showMessage(data.message || '操作失败！', 'error');
            }
        } catch (e) {
            console.error('JSON解析失败:', e);
            showMessage('操作失败：' + text.substring(0, 100), 'error');
        }
    })
    .catch(function(error) {
        console.error('执行失败:', error);
        showMessage('执行失败：' + error.message, 'error');
    });
}

document.getElementById('modal-input').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        submitModalInput();
    }
});