function moveInMaze(targetPos) {
    var currentUrl = window.location.href;
    var baseUrl = currentUrl.split('?')[0];
    var params = currentUrl.split('?')[1] || '';
    var newParams = params.replace(/pos=[^&]*/, '').replace(/^&/, '').replace(/&$/, '');
    var finalUrl = baseUrl + '?' + (newParams ? newParams + '&' : '') + 'pos=' + targetPos;
    window.location.href = finalUrl;
}

function getShelizi(pos) {
    fetch('action.php?action=get_shelizi&pos=' + encodeURIComponent(pos), {
        method: 'GET',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || '拾取失败');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('网络错误');
    });
}

function executeAction(action, area, room, param) {
    let url = 'action.php?action=' + action;
    
    if (room.includes('/')) {
        url += '&room=' + room;
    } else {
        url += '&area=' + area + '&room=' + room;
    }
    
    if (param) {
        if (['north', 'south', 'east', 'west', 'up', 'down', 'out', 'northeast', 'northwest', 'southeast', 'southwest', 'n', 's', 'e', 'w', 'u', 'd', 'ne', 'nw', 'se', 'sw'].includes(param)) {
            url += '&direction=' + param;
        } else {
            url += '&param=' + encodeURIComponent(param);
        }
    }
    
    fetch(url, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(response) {
        var contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(function(text) {
                console.error('响应不是JSON，实际内容:', text.substring(0, 500));
                throw new Error('服务器返回了非JSON响应，可能未登录或发生错误。\n响应内容: ' + text.substring(0, 200));
            });
        }
        return response.json();
    })
    .then(function(data) {
        if (data.success) {
            if (data.redirect) {
                window.location.href = data.redirect;
            } else if (data.html) {
                showHtmlPanel(data.html);
            } else {
                if (data.output || data.message) {
                    showMessage(data.output || data.message, 'success');
                }
            }
            if (action === 'sleep' && data.sleep_duration > 0) {
                startSleepCountdown(data.sleep_duration, area, room);
            }
            if (action === 'open' || action === 'close') {
                setTimeout(function() { location.reload(); }, 800);
            }
        } else {
            if (data.html) {
                showHtmlPanel(data.html);
            } else {
                showMessage(data.message || '动作执行失败', 'error');
            }
        }
        if (data.saved_message_id > 0) {
            var sid = data.saved_message_id;
            var storageKey = 'lastDisplayedMessageId_room_' + (typeof xyjCharId !== 'undefined' ? xyjCharId : 'default');
            var stored = parseInt(localStorage.getItem(storageKey) || '0');
            if (sid > stored) {
                localStorage.setItem(storageKey, sid);
            }
        }
    })
    .catch(function(error) {
        console.error('动作执行失败:', error);
        console.error('错误详情:', error.stack);
        var debugMsg = document.createElement('div');
        debugMsg.style.cssText = 'position:fixed;top:20px;right:20px;background:#fee;color:red;padding:15px;border:2px solid red;z-index:9999;max-width:400px;';
        debugMsg.innerHTML = '<strong>❌ 动作执行失败</strong><br><small>' + error.message + '</small><br><br><button onclick="this.parentElement.remove()">关闭</button>';
        document.body.appendChild(debugMsg);
        showMessage('网络错误：' + error.message, 'error');
    });
}

var sleepTimer = null;
function startSleepCountdown(duration, area, room) {
    if (sleepTimer) clearInterval(sleepTimer);
    var remaining = duration;
    var msgLog = document.getElementById('room-msg-log');
    var sleepDiv = null;
    if (msgLog) {
        sleepDiv = document.createElement('div');
        sleepDiv.className = 'room-msg';
        sleepDiv.style.color = '#FFD700';
        msgLog.appendChild(sleepDiv);
        msgLog.scrollTop = msgLog.scrollHeight;
    }
    function tick() {
        if (sleepDiv) {
            sleepDiv.innerHTML = '💤 睡眠中... <strong>' + remaining + '</strong> 秒后醒来';
        }
        if (remaining <= 0) {
            clearInterval(sleepTimer);
            sleepTimer = null;
            if (sleepDiv) {
                sleepDiv.innerHTML = '💤 正在醒来...';
            }
            executeAction('look', area, room);
        }
        remaining--;
    }
    tick();
    sleepTimer = setInterval(tick, 1000);
}

var lastMessageText = '';
var lastMessageTime = 0;

function showMessage(text, type) {
    var now = Date.now();
    if (text === lastMessageText && now - lastMessageTime < 1000) {
        return;
    }
    lastMessageText = text;
    lastMessageTime = now;
    
    var msgLog = document.getElementById('room-msg-log');
    if (!msgLog) return;
    var div = document.createElement('div');
    div.className = 'room-msg';
    var color = type === 'error' ? '#ff0000' : 'rgba(255, 114, 0, 1.00)';
    div.style.color = color;
    div.style.transition = 'opacity 0.5s';
    div.innerHTML = text;
    msgLog.appendChild(div);
    msgLog.scrollTop = msgLog.scrollHeight;
    while (msgLog.children.length > 3) {
        msgLog.removeChild(msgLog.firstChild);
    }
    setTimeout(function() {
        div.style.opacity = '0.5';
    }, 3000);
}

function showSleepInviteDialog() {
    const dialog = document.getElementById('sleep-invite-dialog');
    if (dialog) {
        dialog.style.display = 'block';
    }
}

function hideSleepInviteDialog() {
    const dialog = document.getElementById('sleep-invite-dialog');
    if (dialog) {
        dialog.style.display = 'none';
    }
}

function showHtmlPanel(htmlContent) {
    var existing = document.getElementById('html-panel-overlay');
    if (existing) existing.remove();

    var overlay = document.createElement('div');
    overlay.id = 'html-panel-overlay';
    overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:9999;overflow-y:auto;';

    var panel = document.createElement('div');
    panel.style.cssText = 'max-width:800px;margin:40px auto;padding:20px;background:#2d2d2d;color:#fff;border-radius:8px;';
    panel.innerHTML = '<div style="text-align:right;margin-bottom:10px;"><a href="javascript:void(0)" onclick="document.getElementById(\'html-panel-overlay\').remove()" style="color:#ff6666;font-size:18px;text-decoration:none;">[ 关闭 ]</a></div>' + htmlContent;

    overlay.appendChild(panel);
    document.body.appendChild(overlay);
}

function appendRoomMessage(htmlContent) {
    var msgLog = document.getElementById('room-msg-log');
    if (!msgLog) return;
    var msgLine = document.createElement('div');
    msgLine.className = 'room-msg';
    msgLine.innerHTML = htmlContent;
    msgLine.style.borderBottom = '1px solid #333';
    msgLine.style.padding = '2px 0';
    msgLog.appendChild(msgLine);
    while (msgLog.children.length > 3) {
        msgLog.removeChild(msgLog.firstChild);
    }
    msgLog.scrollTop = msgLog.scrollHeight;
}

function openExertModal() {
    document.getElementById('exertModal').classList.add('active');
    loadExertInfo();
}

function closeExertModal() {
    document.getElementById('exertModal').classList.remove('active');
}

function loadExertInfo() {
    fetch('action.php?action=score', {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        updateExertButtons();
    })
    .catch(err => {
        console.error('加载角色信息失败:', err);
        updateExertButtons();
    });
}

function updateExertButtons() {
}

function doExert(type) {
    closeExertModal();
    showExertLoading();
    
    fetch('action.php?action=exert&param=' + type, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        hideExertLoading();
        if (data.success) {
            showFlashMessage(data.message, 'success');
        } else {
            showFlashMessage(data.message, 'error');
        }
        setTimeout(function() {
            window.location.reload();
        }, 1000);
    })
    .catch(err => {
        hideExertLoading();
        showFlashMessage('运功失败，请重试', 'error');
        console.error('运功请求失败:', err);
    });
}

function doExertTarget(type) {
    closeExertModal();
    var targetName = prompt('请输入目标玩家名字：');
    if (!targetName || targetName.trim() === '') {
        return;
    }
    targetName = targetName.trim();
    
    showExertLoading();
    
    fetch('action.php?action=exert&param=' + type + ' ' + encodeURIComponent(targetName), {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        hideExertLoading();
        if (data.success) {
            showFlashMessage(data.message, 'success');
        } else {
            showFlashMessage(data.message, 'error');
        }
        setTimeout(function() {
            window.location.reload();
        }, 1000);
    })
    .catch(err => {
        hideExertLoading();
        showFlashMessage('运功失败，请重试', 'error');
        console.error('运功请求失败:', err);
    });
}

function showExertLoading() {
    var content = document.getElementById('exertContent');
    content.innerHTML = '<div class="exert-loading">运功中，请稍候...</div>';
}

function hideExertLoading() {
}

function showFlashMessage(message, type) {
    var flashDiv = document.createElement('div');
    flashDiv.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);padding:15px 30px;border-radius:5px;z-index:10000;font-size:14px;';
    flashDiv.style.backgroundColor = type === 'success' ? '#2d5a2d' : '#5a2d2d';
    flashDiv.style.border = '1px solid ' + (type === 'success' ? '#4a8a4a' : '#8a4a4a');
    flashDiv.style.color = '#ffffff';
    flashDiv.innerHTML = message;
    document.body.appendChild(flashDiv);
    
    setTimeout(function() {
        flashDiv.style.opacity = '0';
        flashDiv.style.transition = 'opacity 0.5s ease';
        setTimeout(function() {
            document.body.removeChild(flashDiv);
        }, 500);
    }, 3000);
}

function openExerciseModal() {
    document.getElementById('exerciseModal').classList.add('active');
}

function closeExerciseModal() {
    document.getElementById('exerciseModal').classList.remove('active');
    document.getElementById('exerciseAmount').value = '';
}

function doExercise() {
    var amount = document.getElementById('exerciseAmount').value;
    if (!amount || amount.trim() === '' || isNaN(amount) || parseInt(amount) <= 0) {
        alert('请输入有效的气血数量');
        return;
    }
    
    closeExerciseModal();
    
    fetch('action.php?action=exercise&param=' + amount, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showFlashMessage(data.message, 'success');
        } else {
            showFlashMessage(data.message, 'error');
        }
        setTimeout(function() {
            window.location.reload();
        }, 1000);
    })
    .catch(err => {
        showFlashMessage('打坐失败，请重试', 'error');
        console.error('打坐请求失败:', err);
    });
}

function openMeditateModal() {
    document.getElementById('meditateModal').classList.add('active');
}

function closeMeditateModal() {
    document.getElementById('meditateModal').classList.remove('active');
    document.getElementById('meditateAmount').value = '';
}

function doMeditate() {
    var amount = document.getElementById('meditateAmount').value;
    if (!amount || amount.trim() === '' || isNaN(amount) || parseInt(amount) <= 0) {
        alert('请输入有效的精神数量');
        return;
    }
    
    closeMeditateModal();
    
    fetch('action.php?action=meditate&param=' + amount, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showFlashMessage(data.message, 'success');
        } else {
            showFlashMessage(data.message, 'error');
        }
        setTimeout(function() {
            window.location.reload();
        }, 1000);
    })
    .catch(err => {
        showFlashMessage('冥思失败，请重试', 'error');
        console.error('冥思请求失败:', err);
    });
}

// === 练功弹窗 ===
function openPracticeModal() {
    document.getElementById('practiceModal').classList.add('active');
    loadPracticeData();
}

function closePracticeModal() {
    document.getElementById('practiceModal').classList.remove('active');
}

function loadPracticeData() {
    var content = document.getElementById('practiceContent');
    content.innerHTML = '<p class="practice-loading">加载中...</p>';
    
    fetch('action.php?action=practiceData', {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (!data.success) {
            content.innerHTML = '<p class="practice-modal-desc" style="color:#ff6666;">' + (data.message || '加载失败') + '</p>';
            return;
        }
        renderPracticeContent(data);
    })
    .catch(function(err) {
        content.innerHTML = '<p class="practice-modal-desc" style="color:#ff6666;">加载失败，请重试</p>';
        console.error('练功数据加载失败:', err);
    });
}

function renderPracticeContent(data) {
    var content = document.getElementById('practiceContent');
    var html = '';
    
    // 信息栏
    html += '<div class="practice-info">';
    html += '<strong>修为：</strong>' + (data.combatExp || 0).toLocaleString() + ' &nbsp;&nbsp;';
    html += '<strong>潜能：</strong>' + (data.availablePotential || 0) + ' / ' + (data.potential || 0) + ' &nbsp;&nbsp;';
    html += '<strong>每次消耗：</strong>' + (data.potentialCost || 0) + ' 点';
    html += '</div>';
    
    // 技能列表
    if (!data.skills || data.skills.length === 0) {
        html += '<p class="practice-modal-desc">你还没有 enable 任何技能。</p>';
    } else {
        html += '<table class="practice-table">';
        html += '<tr><th>技能类型</th><th>当前映射</th><th>等级</th><th>操作</th></tr>';
        
        for (var i = 0; i < data.skills.length; i++) {
            var s = data.skills[i];
            html += '<tr>';
            html += '<td>' + escapeHtml(s.typeName) + ' (' + escapeHtml(s.type) + ')</td>';
            html += '<td class="skill-name">' + escapeHtml(s.skillName) + ' (' + escapeHtml(s.skillId) + ')</td>';
            html += '<td class="skill-level">' + s.level + '</td>';
            html += '<td>';
            if (s.canPractice) {
                html += '<button class="practice-btn" onclick="doPractice(\'' + escapeAttr(s.type) + '\')">练习</button>';
            } else {
                html += '<button class="practice-btn disabled" disabled>' + s.disabledReason + '</button>';
            }
            html += '</td>';
            html += '</tr>';
        }
        html += '</table>';
    }
    
    content.innerHTML = html;
}

function doPractice(type) {
    closePracticeModal();
    var times = prompt('请输入练习次数', '1');
    if (times === null) return;
    times = parseInt(times);
    if (isNaN(times) || times < 1) {
        alert('请输入有效的练习次数');
        return;
    }
    
    showFlashMessage('练功中，请稍候...', 'success');
    
    fetch('action.php?action=practice&type=' + encodeURIComponent(type) + '&times=' + times, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            showFlashMessage(data.message, 'success');
        } else {
            showFlashMessage(data.message, 'error');
        }
        setTimeout(function() {
            window.location.reload();
        }, 1000);
    })
    .catch(function(err) {
        showFlashMessage('练功失败，请重试', 'error');
        console.error('练功请求失败:', err);
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function escapeAttr(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

document.addEventListener('DOMContentLoaded', function() {
    var exertModal = document.getElementById('exertModal');
    if (exertModal) {
        exertModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeExertModal();
            }
        });
    }
    
    var exerciseModal = document.getElementById('exerciseModal');
    if (exerciseModal) {
        exerciseModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeExerciseModal();
            }
        });
    }
    
    var meditateModal = document.getElementById('meditateModal');
    if (meditateModal) {
        meditateModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeMeditateModal();
            }
        });
    }
    
    var practiceModal = document.getElementById('practiceModal');
    if (practiceModal) {
        practiceModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closePracticeModal();
            }
        });
    }
});

// === 天王披风传送弹窗 ===
var teleportForgetMode = false;

function openTeleportModal() {
    teleportForgetMode = false;
    document.getElementById('teleportModal').classList.add('active');
    updateForgetBtn();
    loadTeleportGrid();
}

function closeTeleportModal() {
    document.getElementById('teleportModal').classList.remove('active');
}

function toggleForgetMode() {
    teleportForgetMode = !teleportForgetMode;
    updateForgetBtn();
    loadTeleportGrid();
}

function updateForgetBtn() {
    var btn = document.getElementById('teleportForgetBtn');
    if (teleportForgetMode) {
        btn.textContent = '❌ 取消删除';
        btn.classList.add('active-mode');
    } else {
        btn.textContent = '🗑 忘记模式';
        btn.classList.remove('active-mode');
    }
}

function loadTeleportGrid() {
    fetch('action.php?action=tianwang_teleport&param=list', {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        var gridEl = document.getElementById('teleportGrid');
        if (!data.success) {
            gridEl.innerHTML = '<span style="color:#ff8888;">加载失败</span>';
            return;
        }

        var records = data.records || [];
        var max = data.max || 5;
        var html = '';

        for (var i = 0; i < max; i++) {
            if (i < records.length) {
                var r = records[i];
                if (teleportForgetMode) {
                    html += '<div class="teleport-slot filled forget-mode" onclick="doForgetSlot(' + r.id + ')">';
                    html += '<span class="slot-x">✕</span>';
                    html += '<span class="slot-name">' + escapeHtml(r.room_name) + '</span>';
                    html += '</div>';
                } else {
                    html += '<div class="teleport-slot filled" onclick="doTeleportSlot(' + r.id + ')">';
                    html += '<span class="slot-name">' + escapeHtml(r.room_name) + '</span>';
                    html += '</div>';
                }
            } else {
                html += '<div class="teleport-slot empty" onclick="doTeleport(\'save\')">';
                html += '<span class="slot-plus">+</span>';
                html += '</div>';
            }
        }
        gridEl.innerHTML = html;
    })
    .catch(function() {
        document.getElementById('teleportGrid').innerHTML = '<span style="color:#ff8888;">加载失败</span>';
    });
}

function doTeleportSlot(recordId) {
    if (teleportForgetMode) return;
    closeTeleportModal();
    doTeleport('teleport:' + recordId);
}

function doForgetSlot(recordId) {
    if (!teleportForgetMode) return;
    closeTeleportModal();
    doTeleport('forget:' + recordId);
}

function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

function doTeleport(action) {
    closeTeleportModal();

    fetch('action.php?action=tianwang_teleport&param=' + encodeURIComponent(action), {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            showFlashMessage(data.message, 'success');
            setTimeout(function() {
                window.location.href = 'room.php';
            }, 1200);
        } else {
            showFlashMessage(data.message, 'error');
        }
    })
    .catch(function() {
        showFlashMessage('操作失败，请重试', 'error');
    });
}

document.addEventListener('DOMContentLoaded', function() {
    var teleportModal = document.getElementById('teleportModal');
    if (teleportModal) {
        teleportModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeTeleportModal();
            }
        });
    }
    
    var examModal = document.getElementById('examModal');
    if (examModal) {
        examModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeExamModal();
            }
        });
    }
});

var currentExamNpcId = null;

function askNpcTopic(npcId, topic) {
    fetch('action.php?action=ask&npc_id=' + npcId + '&topic=' + encodeURIComponent(topic), {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            if (data.html) {
                showHtmlPanel(data.html);
            }
            if (data.message) {
                showFlashMessage(data.message, 'success');
            }
        } else {
            showFlashMessage(data.message || '操作失败', 'error');
        }
    })
    .catch(function() {
        showFlashMessage('请求失败，请重试', 'error');
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
    if (modal) {
        modal.classList.remove('active');
    }
    currentExamNpcId = null;
}

function submitExamAnswer() {
    var answer = document.getElementById('examAnswerInput').value.toUpperCase();
    
    if (!/^[ABCD]{3}$/.test(answer)) {
        alert('请输入正确格式的答案（如：ABC）');
        return;
    }
    
    if (!currentExamNpcId) {
        alert('考试信息丢失，请重新开始');
        closeExamModal();
        return;
    }
    
    fetch('action.php?action=ask&npc_id=' + currentExamNpcId + '&topic=' + encodeURIComponent(answer), {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: 'topic=' + encodeURIComponent(answer)
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        closeExamModal();
        if (data.success) {
            showFlashMessage(data.message, 'success');
        } else {
            showFlashMessage(data.message, 'error');
        }
    })
    .catch(function() {
        closeExamModal();
        showFlashMessage('提交失败，请重试', 'error');
    });
}