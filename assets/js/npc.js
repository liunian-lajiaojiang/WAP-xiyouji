/*
 * NPC页面脚本
 * 打听问题 + 科举考试弹窗 + 法宝选择弹窗 + NPC动作执行
 * 
 * 依赖页面变量：window.npcPageData = { npcName, npcId }
 */

var currentExamNpcId = null;

function askNpcTopic(npcId, topic) {
    console.log('askNpcTopic called, npcId:', npcId, 'topic:', topic);
    var url = 'action.php?action=ask&npc_id=' + npcId + '&topic=' + encodeURIComponent(topic);
    console.log('Request URL:', url);
    
    fetch(url, {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => {
        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers);
        return response.text();
    })
    .then(text => {
        console.log('Raw response:', text);
        try {
            var data = JSON.parse(text);
            console.log('Parsed data:', data);
            if (data.redirect) {
                window.location.href = data.redirect;
                return;
            }
            if (data.success) {
                if (data.exam_data && data.exam_data.npc_id && data.exam_data.questions) {
                    openExamModal(data.exam_data.npc_id, data.exam_data.questions);
                }
                const msgEl = document.getElementById('npc-action-result');
                if (msgEl) {
                    msgEl.innerHTML = (data.message || '').replace(/\n/g, '<br>');
                    msgEl.style.display = 'block';
                    msgEl.style.color = '#00ff00';
                }
            } else {
                const msgEl = document.getElementById('npc-action-result');
                if (msgEl) {
                    msgEl.innerHTML = (data.message || '操作失败').replace(/\n/g, '<br>');
                    msgEl.style.display = 'block';
                    msgEl.style.color = '#ff6600';
                }
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            const msgEl = document.getElementById('npc-action-result');
            if (msgEl) {
                msgEl.innerHTML = '响应解析失败: ' + text.substring(0, 200);
                msgEl.style.display = 'block';
                msgEl.style.color = '#ff6600';
            }
        }
    })
    .catch(err => {
        console.error('Request error:', err);
        const msgEl = document.getElementById('npc-action-result');
        if (msgEl) {
            msgEl.innerHTML = '请求失败，请重试: ' + err.message;
            msgEl.style.display = 'block';
            msgEl.style.color = '#ff6600';
        }
    });
}

function openExamModal(npcId, questions) {
    currentExamNpcId = npcId;
    var modal = document.getElementById('examModal');
    var questionsDiv = document.getElementById('examQuestions');
    if (!modal || !questionsDiv) return;
    
    var html = '';
    questions.forEach(function(q, index) {
        html += '<div class="exam-question">';
        html += '<div class="exam-question-number">第' + (index + 1) + '题</div>';
        html += '<div class="exam-question-text">' + escapeHtml(q.question) + '</div>';
        q.options.forEach(function(opt, optIndex) {
            var letter = ['A', 'B', 'C', 'D'][optIndex];
            html += '<div class="exam-option"><span>' + letter + '</span>' + escapeHtml(opt) + '</div>';
        });
        html += '</div>';
    });
    
    questionsDiv.innerHTML = html;
    document.getElementById('examAnswerInput').value = '';
    modal.classList.add('active');
}

function closeExamModal() {
    var modal = document.getElementById('examModal');
    if (modal) modal.classList.remove('active');
    currentExamNpcId = null;
}

function submitExamAnswer() {
    var answer = document.getElementById('examAnswerInput').value.toUpperCase();
    console.log('submitExamAnswer called, answer:', answer, 'npcId:', currentExamNpcId);
    
    if (!/^[ABCD]{3}$/.test(answer)) {
        alert('请输入正确格式的答案（如：ABC）');
        return;
    }
    if (!currentExamNpcId) {
        alert('考试信息丢失，请重新开始');
        closeExamModal();
        return;
    }
    
    var formData = new FormData();
    formData.append('topic', answer);
    formData.append('npc_id', currentExamNpcId);
    
    fetch('action.php?action=ask', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        return response.json();
    })
    .then(data => {
        console.log('Response data:', data);
        closeExamModal();
        window.location.href = 'room.php';
    })
    .catch(err => {
        console.error('Submit error:', err);
        closeExamModal();
        window.location.href = 'room.php';
    });
}

function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

// NPC页面的动作执行函数（AJAX方式）
function npcAction(action, area, room, param, npcId) {
    let url = 'action.php?action=' + encodeURIComponent(action) + '&area=' + encodeURIComponent(area) + '&room=' + encodeURIComponent(room);
    if (param) {
        url += '&param=' + encodeURIComponent(param);
    }
    if (npcId) {
        url += '&npc_id=' + encodeURIComponent(npcId);
    }
    fetch(url, {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.redirect) {
            // 隐藏消息框并立即重定向
            const msgEl = document.getElementById('npc-action-result');
            if (msgEl) {
                msgEl.style.display = 'none';
            }
            window.location.href = data.redirect;
            return;
        }
        const msgEl = document.getElementById('npc-action-result');
        if (msgEl) {
            const msg = data.message || data.output || (data.success ? '操作成功' : '操作失败');
            msgEl.innerHTML = msg.replace(/\n/g, '<br>');
            msgEl.style.display = 'block';
            msgEl.style.color = data.success ? '#00ff00' : '#ff6600';
        }
    })
    .catch(err => {
        console.error('NPC action error:', err);
    });
}

// 法宝选择弹窗
function showFabaoModal() {
    const overlay = document.getElementById('fabao-modal-overlay');
    if (overlay) {
        overlay.classList.add('active');
    }
}
function hideFabaoModal(e) {
    // 点击遮罩层空白处也关闭（e.target === overlay 时）
    if (e && e.target && e.target.id !== 'fabao-modal-overlay') return;
    const overlay = document.getElementById('fabao-modal-overlay');
    if (overlay) {
        overlay.classList.remove('active');
    }
}
function submitFabaoJi(fabaoId, fabaoName) {
    var npcName = window.npcPageData && window.npcPageData.npcName ? window.npcPageData.npcName : '';
    var npcId = window.npcPageData && window.npcPageData.npcId ? window.npcPageData.npcId : 0;
    const url = 'action.php?action=ji&param=' + encodeURIComponent(npcName) + '&fabao_id=' + fabaoId + '&npc_id=' + npcId;
    hideFabaoModal();
    // 用 AJAX 执行祭法宝，结果由 action.php 存入 flash_message
    // 执行后跳转到 room.php 显示结果（和"使用"动作一致）
    fetch(url, {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.redirect) {
            window.location.href = data.redirect;
        } else {
            window.location.href = 'room.php';
        }
    })
    .catch(err => {
        console.error('祭法宝 error:', err);
        window.location.href = 'room.php';
    });
}
