// switchTab —— 支持两种调用方式：switchTab(this, 'tabname') 或 switchTab('tabname')
function switchTab(elOrName, tabName) {
    // 兼容：如果第一个参数是 DOM 元素，用第二个参数作为 tabName
    if (typeof elOrName === 'object' && elOrName !== null && elOrName.nodeType) {
        tabName = tabName || elOrName.getAttribute('data-tab');
    } else {
        tabName = elOrName;
    }

    if (!tabName) return;

    console.log('switchTab called, tabName=', tabName);

    // 步骤1：移除所有 active
    var i;
    var tabs = document.querySelectorAll('.tab');
    for (i = 0; i < tabs.length; i++) {
        tabs[i].classList.remove('active');
    }
    var contents = document.querySelectorAll('.tab-content');
    for (i = 0; i < contents.length; i++) {
        contents[i].classList.remove('active');
    }

    // 步骤2：激活目标标签按钮
    var activeBtn = document.querySelector('.tab[data-tab="' + tabName + '"]');
    console.log('activeBtn found:', !!activeBtn);
    if (activeBtn) activeBtn.classList.add('active');

    // 步骤3：激活目标内容区
    var content = document.getElementById('tab-' + tabName);
    console.log('content found:', !!content, 'id=tab-' + tabName);
    if (content) {
        content.classList.add('active');
        console.log('content classList after add:', content.className);
    }

    // 步骤4：保存到 localStorage
    try { localStorage.setItem('admin_tab', tabName); } catch(e) {}

    // 步骤5：特殊回调
    if (tabName === 'emote' && typeof loadEmoteList === 'function') {
        loadEmoteList();
    }
    if (tabName === 'ai' && typeof loadAiPlayerStatus === 'function') {
        loadAiPlayerStatus();
    }
}

function toggleMessage() {
    const msg = document.getElementById('message');
    msg.classList.toggle('expanded');
}

function showMessage(text, type) {
    const msg = document.getElementById('message');
    msg.className = 'message ' + type;
    document.querySelector('.message-header .msg-title').textContent = type === 'success' ? '成功' : '错误';
    document.querySelector('.message-content').textContent = text;
    msg.classList.remove('expanded');
    setTimeout(() => msg.classList.add('expanded'), 10);
}

async function ajaxPost(data) {
    const response = await fetch('admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(data).toString()
    });
    const text = await response.text();
    try {
        return JSON.parse(text);
    } catch {
        return { success: false, message: text.substring(0, 500) };
    }
}

async function banUser(username = null) {
    const target = username || document.getElementById('action-username').value;
    if (!target) { showMessage('请输入用户名', 'error'); return; }
    const result = await ajaxPost({ action: 'ban_user', username: target });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) setTimeout(() => location.reload(), 1000);
}

async function banUserByName(username) {
    if (!confirm('确认封禁用户: ' + username + '？')) return;
    const result = await ajaxPost({ action: 'ban_user', username });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) setTimeout(() => location.reload(), 1000);
}

async function unbanUser(username) {
    if (!confirm('确认解封用户: ' + username + '？')) return;
    const result = await ajaxPost({ action: 'unban_user', username });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) setTimeout(() => location.reload(), 1000);
}

async function imprisonUser(username) {
    if (!confirm('确认监禁用户: ' + username + '？')) return;
    const result = await ajaxPost({ action: 'imprison_user', username });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) setTimeout(() => location.reload(), 1000);
}

async function releaseUser(username) {
    if (!confirm('确认释放用户: ' + username + '？')) return;
    const result = await ajaxPost({ action: 'release_user', username });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) setTimeout(() => location.reload(), 1000);
}

async function banIp() {
    const ipPattern = document.getElementById('ip-pattern').value;
    const reason = document.getElementById('ip-reason').value || '违规操作';
    if (!ipPattern) { showMessage('请输入IP模式', 'error'); return; }
    const result = await ajaxPost({ action: 'ban_ip', ip_pattern: ipPattern, reason });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) setTimeout(() => location.reload(), 1000);
}

async function unbanIp(ipPattern) {
    const result = await ajaxPost({ action: 'unban_ip', ip_pattern: ipPattern });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) setTimeout(() => location.reload(), 1000);
}

async function setWizardLevel() {
    const username = document.getElementById('wizard-username').value;
    const level = document.getElementById('wizard-level').value;
    if (!username) { showMessage('请输入用户名', 'error'); return; }
    const result = await ajaxPost({ action: 'set_wizard_level', username, level });
    showMessage(result.message, result.success ? 'success' : 'error');
}

function searchUser() {
    const username = document.getElementById('search-username').value;
    if (username) {
        document.getElementById('action-username').value = username;
    }
}

async function toguest() {
    const username = document.getElementById('guest-username').value;
    const days = document.getElementById('guest-days').value;
    const reason = document.getElementById('guest-reason').value || '等待审核';
    if (!username) { showMessage('请输入用户名', 'error'); return; }
    const result = await ajaxPost({ action: 'toguest', username, days, reason });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) setTimeout(() => location.reload(), 1000);
}

async function approveGuest(username) {
    if (!username) { showMessage('请输入用户名', 'error'); return; }
    const result = await ajaxPost({ action: 'approve_guest', username });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) setTimeout(() => location.reload(), 1000);
}

async function courtArrest() {
    const username = document.getElementById('court-username').value;
    const reason = document.getElementById('court-reason').value;
    if (!username || !reason) { showMessage('请输入用户名和逮捕原因', 'error'); return; }
    const result = await ajaxPost({ action: 'court_arrest', username, reason });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) setTimeout(() => location.reload(), 1000);
}

async function courtTry(suspectId) {
    const result = await ajaxPost({ action: 'court_try', suspect_id: suspectId });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) setTimeout(() => location.reload(), 1000);
}

async function courtRelease(suspectId) {
    const result = await ajaxPost({ action: 'court_release', suspect_id: suspectId });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) setTimeout(() => location.reload(), 1000);
}

function showVerdictForm(caseId) {
    document.getElementById('verdict-case-id').value = caseId;
    document.getElementById('verdict-form').style.display = 'block';
    document.getElementById('verdict-type').onchange = function() {
        document.getElementById('verdict-days-group').style.display = 
            this.value === '3' ? 'block' : 'none';
    };
}

function hideVerdictForm() {
    document.getElementById('verdict-form').style.display = 'none';
}

async function courtVerdict() {
    const caseId = document.getElementById('verdict-case-id').value;
    const verdict = document.getElementById('verdict-type').value;
    const days = document.getElementById('verdict-days').value;
    const notes = document.getElementById('verdict-notes').value;
    const result = await ajaxPost({ action: 'court_verdict', case_id: caseId, verdict, days, notes });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) setTimeout(() => location.reload(), 1000);
}

async function cmdWhere() {
    const query = document.getElementById('where-query').value;
    const result = await ajaxPost({ action: 'where', query });
    const el = document.getElementById('where-result');
    el.style.display = 'block';
    el.textContent = result.message || '(无结果)';
    if (!result.success) el.style.color = '#e94560';
    else el.style.color = '#eee';
}

async function cmdWhois() {
    const query = document.getElementById('whois-query').value;
    if (!query) { showMessage('请输入查询内容', 'error'); return; }
    const result = await ajaxPost({ action: 'whois', query });
    const el = document.getElementById('whois-result');
    el.style.display = 'block';
    el.textContent = result.message || '(无结果)';
    if (!result.success) el.style.color = '#e94560';
    else el.style.color = '#eee';
}

async function cmdSameip() {
    const query = document.getElementById('sameip-query').value;
    if (!query) { showMessage('请输入IP或用户名', 'error'); return; }
    const result = await ajaxPost({ action: 'sameip', query });
    const el = document.getElementById('sameip-result');
    el.style.display = 'block';
    el.textContent = result.message || '(无结果)';
    if (!result.success) el.style.color = '#e94560';
    else el.style.color = '#eee';
}

async function cmdClone() {
    const charName = document.getElementById('clone-char').value;
    const itemId = document.getElementById('clone-item').value;
    const category = document.getElementById('clone-category').value;
    if (!charName || !itemId) { showMessage('请填写角色名和物品ID', 'error'); return; }
    const result = await ajaxPost({ action: 'clone_item', char_name: charName, item_id: itemId, category });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdDest() {
    const charName = document.getElementById('dest-char').value;
    const itemId = document.getElementById('dest-item').value;
    if (!charName || !itemId) { showMessage('请填写角色名和物品ID', 'error'); return; }
    const result = await ajaxPost({ action: 'dest_item', char_name: charName, item_id: itemId });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdSmash() {
    const invId = document.getElementById('smash-inv').value;
    if (!invId) { showMessage('请填写背包物品ID', 'error'); return; }
    if (!confirm('确认销毁背包物品ID: ' + invId + '？')) return;
    const result = await ajaxPost({ action: 'smash', inv_id: invId, is_room: '0' });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdSmashRoom() {
    const roomId = document.getElementById('smash-room').value;
    if (!roomId) { showMessage('请填写房间物品ID', 'error'); return; }
    if (!confirm('确认销毁房间物品ID: ' + roomId + '？')) return;
    const result = await ajaxPost({ action: 'smash', inv_id: roomId, is_room: '1' });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdListPlayerItems() {
    const charName = document.getElementById('player-items-char').value;
    if (!charName) { showMessage('请输入角色名', 'error'); return; }
    const result = await ajaxPost({ action: 'list_player_items', target_name: charName });
    const container = document.getElementById('player-items-list');
    if (result.success) {
        let html = '<div style="background:#0f3460;padding:10px;border-radius:5px;">';
        html += '<strong style="color:#e94560;">' + result.char_name + ' 的背包物品:</strong><br><br>';
        if (result.data.length === 0) {
            html += '<span style="color:#aaa;">暂无物品</span>';
        } else {
            html += '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
            html += '<tr style="background:#16213e;"><th style="padding:5px;text-align:left;">ID</th><th style="padding:5px;text-align:left;">物品名</th><th style="padding:5px;text-align:left;">数量</th><th style="padding:5px;text-align:left;">分类</th><th style="padding:5px;text-align:left;">等级</th><th style="padding:5px;text-align:center;">操作</th></tr>';
            result.data.forEach(item => {
                const name = item.name || item.item_id;
                html += '<tr style="border-bottom:1px solid #533483;">';
                html += '<td style="padding:5px;">' + item.id + '</td>';
                html += '<td style="padding:5px;">' + name + '</td>';
                html += '<td style="padding:5px;">' + item.quantity + '</td>';
                html += '<td style="padding:5px;">' + (item.category || '-') + '</td>';
                html += '<td style="padding:5px;">' + (item.level || 0) + '</td>';
                html += '<td style="padding:5px;text-align:center;">';
                html += '<button class="btn btn-danger" style="padding:2px 6px;font-size:10px;" onclick="cmdSmashById(' + item.id + ', 0)">销毁</button>';
                html += '</td></tr>';
            });
            html += '</table>';
        }
        html += '</div>';
        container.innerHTML = html;
        container.style.display = 'block';
    } else {
        showMessage(result.message, 'error');
    }
}

async function cmdListRoomItems() {
    const area = document.getElementById('room-items-area').value;
    const room = document.getElementById('room-items-room').value;
    if (!area || !room) { showMessage('请输入区域和房间', 'error'); return; }
    const result = await ajaxPost({ action: 'list_room_items', area, room });
    const container = document.getElementById('room-items-list');
    if (result.success) {
        let html = '<div style="background:#0f3460;padding:10px;border-radius:5px;">';
        html += '<strong style="color:#e94560;">' + result.room_id + ' 的地上物品:</strong><br><br>';
        if (result.data.length === 0) {
            html += '<span style="color:#aaa;">暂无物品</span>';
        } else {
            html += '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
            html += '<tr style="background:#16213e;"><th style="padding:5px;text-align:left;">ID</th><th style="padding:5px;text-align:left;">物品名</th><th style="padding:5px;text-align:left;">数量</th><th style="padding:5px;text-align:left;">分类</th><th style="padding:5px;text-align:center;">操作</th></tr>';
            result.data.forEach(item => {
                const name = item.name || item.item_id;
                html += '<tr style="border-bottom:1px solid #533483;">';
                html += '<td style="padding:5px;">' + item.id + '</td>';
                html += '<td style="padding:5px;">' + name + '</td>';
                html += '<td style="padding:5px;">' + (item.quantity || 1) + '</td>';
                html += '<td style="padding:5px;">' + (item.category || '-') + '</td>';
                html += '<td style="padding:5px;text-align:center;">';
                html += '<button class="btn btn-danger" style="padding:2px 6px;font-size:10px;" onclick="cmdSmashById(' + item.id + ', 1)">销毁</button>';
                html += '</td></tr>';
            });
            html += '</table>';
        }
        html += '</div>';
        container.innerHTML = html;
        container.style.display = 'block';
    } else {
        showMessage(result.message, 'error');
    }
}

async function cmdSmashById(id, isRoom) {
    if (!confirm('确认销毁物品ID: ' + id + '？')) return;
    const result = await ajaxPost({ action: 'smash', inv_id: id, is_room: isRoom ? '1' : '0' });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) {
        cmdListPlayerItems();
        cmdListRoomItems();
    }
}

async function cmdAddItemToPlayer() {
    const charName = document.getElementById('add-to-player-char').value;
    const itemId = document.getElementById('add-to-player-item').value;
    const category = document.getElementById('add-to-player-category').value;
    const quantity = parseInt(document.getElementById('add-to-player-qty').value) || 1;
    if (!charName || !itemId) { showMessage('请输入角色名和物品ID', 'error'); return; }
    const result = await ajaxPost({ action: 'add_item_to_player', target_name: charName, item_id: itemId, category, quantity });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdAddItemToRoom() {
    const area = document.getElementById('add-to-room-area').value;
    const room = document.getElementById('add-to-room-room').value;
    const itemId = document.getElementById('add-to-room-item').value;
    const category = document.getElementById('add-to-room-category').value;
    const quantity = parseInt(document.getElementById('add-to-room-qty').value) || 1;
    if (!area || !room || !itemId) { showMessage('请输入区域、房间和物品ID', 'error'); return; }
    const result = await ajaxPost({ action: 'add_item_to_room', area, room, item_id: itemId, category, quantity });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdCreateItem() {
    const itemId = document.getElementById('new-item-id').value;
    const name = document.getElementById('new-item-name').value;
    const type = document.getElementById('new-item-type').value;
    const category = document.getElementById('new-item-category').value;
    const level = parseInt(document.getElementById('new-item-level').value) || 1;
    const value = parseInt(document.getElementById('new-item-value').value) || 0;
    const desc = document.getElementById('new-item-desc').value;
    if (!itemId || !name) { showMessage('请输入物品ID和名称', 'error'); return; }
    const result = await ajaxPost({ action: 'create_item', new_item_id: itemId, new_name: name, new_type: type, new_category: category, new_level: level, new_value: value, new_desc: desc });
    showMessage(result.message, result.success ? 'success' : 'error');
}

let currentNpcPage = 1;

function cmdSearchNpc() {
    loadNpcs(1);
}

async function loadNpcs(page = 1) {
    currentNpcPage = page;
    const search = document.getElementById('npc-search').value;
    const result = await ajaxPost({ action: 'list_npcs', page, search });
    const listEl = document.getElementById('npc-list');
    const paginationEl = document.getElementById('npc-pagination');
    
    if (!result.success) {
        listEl.innerHTML = '<span style="color:#e94560;">加载失败: ' + result.message + '</span>';
        paginationEl.innerHTML = '';
        return;
    }
    
    const npcs = result.npcs;
    if (npcs.length === 0) {
        listEl.innerHTML = '<span style="color:#999;">暂无NPC</span>';
        paginationEl.innerHTML = '';
        return;
    }
    
    let html = '<table style="width:100%;border-collapse:collapse;">';
    html += '<tr style="color:#aaa;font-weight:bold;"><th style="border-bottom:1px solid #533483;padding:5px;">ID</th><th style="border-bottom:1px solid #533483;padding:5px;">标识</th><th style="border-bottom:1px solid #533483;padding:5px;">名称</th><th style="border-bottom:1px solid #533483;padding:5px;">称号</th><th style="border-bottom:1px solid #533483;padding:5px;">种族</th><th style="border-bottom:1px solid #533483;padding:5px;">职业</th><th style="border-bottom:1px solid #533483;padding:5px;">性别</th><th style="border-bottom:1px solid #533483;padding:5px;">态度</th><th style="border-bottom:1px solid #533483;padding:5px;">操作</th></tr>';
    
    const raceMap = { 'human': '人类', 'monster': '野兽', 'demon': '妖魔', 'god': '神仙' };
    const classMap = { '': '无', 'xian': '神仙', 'bonze': '和尚', 'taoist': '道士', 'general': '将军', 'scholar': '书生', 'merchant': '商人', 'beggar': '乞丐', 'yaomo': '妖魔', 'beast': '兽类' };
    const genderMap = { 'male': '男', 'female': '女', 'unknown': '未知' };
    const attitudeMap = { 'friendly': '友好', 'aggressive': '敌对', 'cooperative': '合作' };
    
    npcs.forEach(npc => {
        html += '<tr>';
        html += '<td style="border-bottom:1px solid #533483;padding:5px;">' + npc.id + '</td>';
        html += '<td style="border-bottom:1px solid #533483;padding:5px;">' + npc.npc_id + '</td>';
        html += '<td style="border-bottom:1px solid #533483;padding:5px;">' + npc.name + '</td>';
        html += '<td style="border-bottom:1px solid #533483;padding:5px;">' + (npc.title || '') + '</td>';
        html += '<td style="border-bottom:1px solid #533483;padding:5px;">' + (raceMap[npc.race] || npc.race) + '</td>';
        html += '<td style="border-bottom:1px solid #533483;padding:5px;">' + (classMap[npc.class] || npc.class) + '</td>';
        html += '<td style="border-bottom:1px solid #533483;padding:5px;">' + (genderMap[npc.gender] || npc.gender) + '</td>';
        html += '<td style="border-bottom:1px solid #533483;padding:5px;">' + (attitudeMap[npc.attitude] || npc.attitude) + '</td>';
        html += '<td style="border-bottom:1px solid #533483;padding:5px;">';
        html += '<button style="margin:2px;padding:2px 6px;font-size:12px;" onclick="editNpc(' + npc.id + ')">编辑</button>';
        html += '<button style="margin:2px;padding:2px 6px;font-size:12px;color:#e94560;" onclick="deleteNpc(' + npc.id + ', \'' + npc.name + '\')">删除</button>';
        html += '</td>';
        html += '</tr>';
    });
    html += '</table>';
    listEl.innerHTML = html;
    
    if (result.pages > 1) {
        let pagination = '';
        if (page > 1) pagination += '<button onclick="loadNpcs(' + (page - 1) + ')" style="margin:2px;">上一页</button>';
        pagination += '<span style="margin:0 10px;">第 ' + page + ' / ' + result.pages + ' 页</span>';
        if (page < result.pages) pagination += '<button onclick="loadNpcs(' + (page + 1) + ')" style="margin:2px;">下一页</button>';
        paginationEl.innerHTML = pagination;
    } else {
        paginationEl.innerHTML = '';
    }
}

function showCreateNpc() {
    document.getElementById('npc-edit-id').value = '';
    document.getElementById('npc-id').value = '';
    document.getElementById('npc-name').value = '';
    document.getElementById('npc-title').value = '';
    document.getElementById('npc-race').value = 'human';
    document.getElementById('npc-class').value = '';
    document.getElementById('npc-gender').value = 'male';
    document.getElementById('npc-attitude').value = 'friendly';
    document.getElementById('npc-spawn-area').value = '';
    document.getElementById('npc-spawn-room').value = '';
    document.getElementById('npc-description').value = '';
    document.getElementById('npc-form').style.display = 'block';
}

function hideNpcForm() {
    document.getElementById('npc-form').style.display = 'none';
}

async function editNpc(id) {
    const result = await ajaxPost({ action: 'get_npc', npc_id: id });
    if (!result.success) {
        showMessage(result.message, 'error');
        return;
    }
    const npc = result.npc;
    document.getElementById('npc-edit-id').value = npc.id;
    document.getElementById('npc-id').value = npc.npc_id;
    document.getElementById('npc-name').value = npc.name;
    document.getElementById('npc-title').value = npc.title || '';
    document.getElementById('npc-race').value = npc.race || 'human';
    document.getElementById('npc-class').value = npc.class || '';
    document.getElementById('npc-gender').value = npc.gender || 'male';
    document.getElementById('npc-attitude').value = npc.attitude || 'friendly';
    document.getElementById('npc-spawn-area').value = npc.spawn_area || '';
    document.getElementById('npc-spawn-room').value = npc.spawn_room || '';
    document.getElementById('npc-description').value = npc.description || '';
    document.getElementById('npc-form').style.display = 'block';
}

async function cmdSaveNpc() {
    const id = document.getElementById('npc-edit-id').value;
    const npcId = document.getElementById('npc-id').value;
    const name = document.getElementById('npc-name').value;
    const title = document.getElementById('npc-title').value;
    const race = document.getElementById('npc-race').value;
    const classVal = document.getElementById('npc-class').value;
    const gender = document.getElementById('npc-gender').value;
    const description = document.getElementById('npc-description').value;
    const spawnArea = document.getElementById('npc-spawn-area').value;
    const spawnRoom = document.getElementById('npc-spawn-room').value;
    const attitude = document.getElementById('npc-attitude').value;
    
    if (id) {
        const result = await ajaxPost({ action: 'update_npc', id, name, title, race, class: classVal, gender, description, spawn_area: spawnArea, spawn_room: spawnRoom, attitude });
        showMessage(result.message, result.success ? 'success' : 'error');
        if (result.success) { hideNpcForm(); loadNpcs(currentNpcPage); }
    } else {
        const result = await ajaxPost({ action: 'create_npc', npc_id: npcId, name, title, race, class: classVal, gender, description, spawn_area: spawnArea, spawn_room: spawnRoom, attitude });
        showMessage(result.message, result.success ? 'success' : 'error');
        if (result.success) { hideNpcForm(); loadNpcs(); }
    }
}

async function deleteNpc(id, name) {
    if (!confirm('确定要删除NPC "' + name + '" 吗？')) return;
    const result = await ajaxPost({ action: 'delete_npc', id });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) loadNpcs(currentNpcPage);
}

async function cmdGoto() {
    const target = document.getElementById('goto-target').value;
    if (!target) { showMessage('请输入目标', 'error'); return; }
    const result = await ajaxPost({ action: 'goto_cmd', target });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) setTimeout(() => location.href = 'room.php', 500);
}

async function cmdSummon() {
    const target = document.getElementById('goto-target').value;
    if (!target) { showMessage('请输入要召唤的角色名', 'error'); return; }
    const result = await ajaxPost({ action: 'summon', target });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdStopCombat() {
    const targetName = document.getElementById('status-target').value;
    if (!targetName) { showMessage('请输入角色名', 'error'); return; }
    const result = await ajaxPost({ action: 'stop_combat', target_name: targetName });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdClearPoison() {
    const targetName = document.getElementById('status-target').value;
    if (!targetName) { showMessage('请输入角色名', 'error'); return; }
    const result = await ajaxPost({ action: 'clear_poison', target_name: targetName });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdClearDrunk() {
    const targetName = document.getElementById('status-target').value;
    if (!targetName) { showMessage('请输入角色名', 'error'); return; }
    const result = await ajaxPost({ action: 'clear_drunk', target_name: targetName });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdAddBuff() {
    const targetName = document.getElementById('status-target').value;
    const buffType = document.getElementById('buff-type').value;
    const buffValue = document.getElementById('buff-value').value;
    const buffDuration = document.getElementById('buff-duration').value;
    if (!targetName) { showMessage('请输入角色名', 'error'); return; }
    const result = await ajaxPost({ action: 'add_buff', target_name: targetName, buff_type: buffType, buff_value: buffValue, buff_duration: buffDuration });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdListBuffs() {
    const targetName = document.getElementById('status-target').value;
    if (!targetName) { showMessage('请输入角色名', 'error'); return; }
    const result = await ajaxPost({ action: 'list_buffs', target_name: targetName });
    if (result.success) {
        showMessage(result.message, 'success');
        if (result.buffs && result.buffs.length > 0) {
            console.log('角色Buff列表:', result.buffs);
        }
    } else {
        showMessage(result.message, 'error');
    }
}

async function cmdRemoveBuff() {
    const targetName = document.getElementById('status-target').value;
    const buffType = document.getElementById('buff-type').value;
    if (!targetName) { showMessage('请输入角色名', 'error'); return; }
    if (!buffType) { showMessage('请选择Buff类型', 'error'); return; }
    const result = await ajaxPost({ action: 'remove_buff', target_name: targetName, buff_type: buffType });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdClearAllBuffs() {
    const targetName = document.getElementById('status-target').value;
    if (!targetName) { showMessage('请输入角色名', 'error'); return; }
    if (!confirm('确定要清除该角色的所有Buff吗？')) return;
    const result = await ajaxPost({ action: 'clear_all_buffs', target_name: targetName });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdDeletePlayer() {
    const targetName = document.getElementById('delete-player-target').value;
    if (!targetName) { showMessage('请输入角色名或用户名', 'error'); return; }
    if (!confirm('⚠️ 确定要删除玩家「' + targetName + '」吗？\n\n这将删除该角色的所有数据，包括：\n- 角色信息\n- 背包物品\n- 技能和任务\n- 状态效果\n- 战斗数据\n- 充值和兑换记录\n- 好友关系\n- 消息记录\n- 银行存款\n- 门派记录\n- 家园数据\n- 等等...\n\n如果该用户只有这一个角色，用户账号也会被删除。\n\n此操作不可恢复！')) return;
    if (!confirm('再次确认：真的要删除「' + targetName + '」吗？')) return;
    const result = await ajaxPost({ action: 'delete_player', target_name: targetName });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdSetskill() {
    const charName = document.getElementById('ss-char').value;
    const skillName = document.getElementById('ss-skill').value;
    const level = document.getElementById('ss-level').value;
    if (!charName || !skillName) { showMessage('请填写角色名和技能ID', 'error'); return; }
    const result = await ajaxPost({ action: 'setskill', char_name: charName, skill_name: skillName, level: level || '0' });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdSetskillList() {
    const charName = document.getElementById('ss-char').value;
    if (!charName) { showMessage('请填写角色名', 'error'); return; }
    const result = await ajaxPost({ action: 'setskill', char_name: '', skill_name: '', level: 0, action_override: 'list', list_char: charName });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdBlock() {
    const username = document.getElementById('block-user').value;
    const feature = document.getElementById('block-feature').value;
    const reason = document.getElementById('block-reason').value;
    if (!username) { showMessage('请填写用户名', 'error'); return; }
    const result = await ajaxPost({ action: 'block', username, feature, reason, sub_action: 'block' });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdUnblock() {
    const username = document.getElementById('block-user').value;
    const feature = document.getElementById('block-feature').value;
    if (!username) { showMessage('请填写用户名', 'error'); return; }
    const result = await ajaxPost({ action: 'block', username, feature, reason: '', sub_action: 'unblock' });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdBlockList() {
    const username = document.getElementById('block-user').value;
    const result = await ajaxPost({ action: 'block', username, feature: '', reason: '', sub_action: 'list' });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdSnoop() {
    const target = document.getElementById('snoop-target').value;
    if (!target) { showMessage('请填写角色名', 'error'); return; }
    const result = await ajaxPost({ action: 'snoop', target, limit: 50 });
    const el = document.getElementById('snoop-result');
    el.style.display = 'block';
    el.textContent = result.message || '(无结果)';
    if (!result.success) el.style.color = '#e94560';
    else el.style.color = '#eee';
}

async function cmdTail() {
    const logType = document.getElementById('tail-type').value;
    const lines = document.getElementById('tail-lines').value || '50';
    const result = await ajaxPost({ action: 'tail_log', log_type: logType, lines });
    const el = document.getElementById('snoop-result');
    el.style.display = 'block';
    el.textContent = result.message || '(无结果)';
    if (!result.success) el.style.color = '#e94560';
    else el.style.color = '#eee';
}

async function cmdWizlock(status) {
    const result = await ajaxPost({ action: 'wizlock', status });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdShutdown() {
    const minutes = document.getElementById('shutdown-minutes').value;
    const reason = document.getElementById('shutdown-reason').value || '例行维护';
    if (!confirm('确认设置服务器维护: ' + minutes + '分钟后，原因: ' + reason + '？')) return;
    const result = await ajaxPost({ action: 'shutdown', confirm: minutes + ' ' + reason });
    showMessage(result.message, result.success ? 'success' : 'error');
}

async function cmdShutdownCancel() {
    if (!confirm('确认取消服务器维护？')) return;
    const result = await ajaxPost({ action: 'shutdown', confirm: 'cancel' });
    showMessage(result.message, result.success ? 'success' : 'error');
}

function resetNewsForm() {
    document.getElementById('news-id').value = '0';
    document.getElementById('news-title').value = '';
    document.getElementById('news-content').value = '';
    document.getElementById('news-is-latest').checked = false;
    document.getElementById('news-sort-order').value = '0';
}

function editNews(news) {
    document.getElementById('news-id').value = news.id;
    document.getElementById('news-title').value = news.title;
    document.getElementById('news-content').value = news.content;
    document.getElementById('news-is-latest').checked = news.is_latest == 1;
    document.getElementById('news-sort-order').value = news.sort_order;
}

async function saveNews() {
    const id = document.getElementById('news-id').value;
    const title = document.getElementById('news-title').value;
    const content = document.getElementById('news-content').value;
    const isLatest = document.getElementById('news-is-latest').checked ? 1 : 0;
    const sortOrder = document.getElementById('news-sort-order').value;
    
    if (!title.trim()) { showMessage('请输入标题', 'error'); return; }
    if (!content.trim()) { showMessage('请输入内容', 'error'); return; }
    
    const action = id === '0' ? 'add_news' : 'update_news';
    const result = await ajaxPost({ action, id, title, content, is_latest: isLatest, sort_order: sortOrder });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) {
        setTimeout(() => location.reload(), 1000);
    }
}

async function deleteNews(id, title) {
    if (!confirm('确定要删除新闻 "' + title + '" 吗？')) return;
    const result = await ajaxPost({ action: 'delete_news', id });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) {
        setTimeout(() => location.reload(), 1000);
    }
}

async function exportPlayerData() {
    const playerName = document.getElementById('export-player-name').value.trim();
    const format = document.getElementById('export-format').value;
    
    if (playerName) {
        if (!confirm('确定要导出玩家 "' + playerName + '" 的数据吗？')) return;
    } else {
        if (!confirm('确定要导出所有玩家数据吗？这将导出所有用户和角色信息。')) return;
    }
    
    const formData = new URLSearchParams();
    formData.append('action', 'export_player_data');
    formData.append('online_only', '0');
    formData.append('format', format);
    if (playerName) {
        formData.append('player_name', playerName);
    }
    
    const response = await fetch('admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    });
    
    if (response.ok) {
        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        const filename = playerName ? 'player_data_' + playerName + '_' : 'player_data_';
        a.download = filename + new Date().toISOString().slice(0, 19).replace(/:/g, '-') + '.' + format;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        showMessage(playerName ? '玩家 "' + playerName + '" 数据导出成功' : '数据导出成功', 'success');
    } else {
        showMessage('导出失败', 'error');
    }
}

async function exportOnlinePlayerData() {
    const format = document.getElementById('export-format').value;
    if (!confirm('确定要导出在线玩家数据吗？')) return;
    
    const formData = new URLSearchParams();
    formData.append('action', 'export_player_data');
    formData.append('online_only', '1');
    formData.append('format', format);
    
    const response = await fetch('admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    });
    
    if (response.ok) {
        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'online_player_data_' + new Date().toISOString().slice(0, 19).replace(/:/g, '-') + '.' + format;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        showMessage('在线玩家数据导出成功', 'success');
    } else {
        showMessage('导出失败', 'error');
    }
}

// ========== 表情管理 ==========

function resetEmoteForm() {
    document.getElementById('emote-edit-mode').value = 'add';
    document.getElementById('emote-command').value = '';
    document.getElementById('emote-command').disabled = false;
    document.getElementById('emote-desc').value = '';
    document.getElementById('emote-myself').value = '';
    document.getElementById('emote-myself-target').value = '';
    document.getElementById('emote-myself-self').value = '';
    document.getElementById('emote-target').value = '';
    document.getElementById('emote-others').value = '';
    document.getElementById('emote-others-target').value = '';
    document.getElementById('emote-others-self').value = '';
    document.getElementById('emote-sort').value = '0';
    document.getElementById('emote-active').value = '1';
    const msgEl = document.getElementById('emote-form-msg');
    msgEl.style.display = 'none';
    msgEl.textContent = '';
}

async function loadEmoteList() {
    const result = await ajaxPost({ action: 'emote_list' });
    const tbody = document.getElementById('emote-list-body');
    const countEl = document.getElementById('emote-count');
    
    if (!result.success) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: #e94560;">加载失败: ' + result.message + '</td></tr>';
        countEl.textContent = '';
        return;
    }
    
    const emotes = result.emotes;
    countEl.textContent = '(共 ' + emotes.length + ' 个)';
    
    if (emotes.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: #666;">暂无表情</td></tr>';
        return;
    }
    
    let html = '';
    emotes.forEach(em => {
        const activeClass = em.is_active == 1 ? 'color: #4ade80;' : 'color: #e94560;';
        const activeText = em.is_active == 1 ? '启用' : '禁用';
        html += '<tr>';
        html += '<td style="font-weight: bold; color: #e94560;">' + em.command + '</td>';
        html += '<td>' + (em.description || '') + '</td>';
        html += '<td>' + (em.updated_by || '') + '</td>';
        html += '<td style="' + activeClass + '">' + activeText + '</td>';
        html += '<td>' + (em.sort_order || 0) + '</td>';
        html += '<td>';
        html += '<button class="btn" style="padding:2px 8px; font-size:11px; margin-right:4px;" onclick="editEmote(\'' + em.command + '\')">编辑</button>';
        html += '<button class="btn" style="padding:2px 8px; font-size:11px; margin-right:4px;" onclick="toggleEmote(\'' + em.command + '\')">' + (em.is_active == 1 ? '禁用' : '启用') + '</button>';
        html += '<button class="btn btn-danger" style="padding:2px 8px; font-size:11px;" onclick="deleteEmote(\'' + em.command + '\')">删除</button>';
        html += '</td>';
        html += '</tr>';
    });
    tbody.innerHTML = html;
}

async function editEmote(command) {
    const result = await ajaxPost({ action: 'emote_get', command });
    if (!result.success || !result.emote) {
        showMessage('获取表情失败: ' + (result.message || '不存在'), 'error');
        return;
    }
    const em = result.emote;
    document.getElementById('emote-edit-mode').value = 'edit';
    document.getElementById('emote-command').value = em.command;
    document.getElementById('emote-command').disabled = true;  // 编辑时不允许修改命令名
    document.getElementById('emote-desc').value = em.description || '';
    document.getElementById('emote-myself').value = em.myself || '';
    document.getElementById('emote-myself-target').value = em.myself_target || '';
    document.getElementById('emote-myself-self').value = em.myself_self || '';
    document.getElementById('emote-target').value = em.target || '';
    document.getElementById('emote-others').value = em.others || '';
    document.getElementById('emote-others-target').value = em.others_target || '';
    document.getElementById('emote-others-self').value = em.others_self || '';
    document.getElementById('emote-sort').value = em.sort_order || 0;
    document.getElementById('emote-active').value = em.is_active == 1 ? '1' : '0';
    
    // 滚动到表单
    document.getElementById('tab-emote').scrollTop = 0;
}

async function saveEmote() {
    const command = document.getElementById('emote-command').value.trim();
    const description = document.getElementById('emote-desc').value.trim();
    const myself = document.getElementById('emote-myself').value.trim();
    const myselfTarget = document.getElementById('emote-myself-target').value.trim();
    const myselfSelf = document.getElementById('emote-myself-self').value.trim();
    const target = document.getElementById('emote-target').value.trim();
    const others = document.getElementById('emote-others').value.trim();
    const othersTarget = document.getElementById('emote-others-target').value.trim();
    const othersSelf = document.getElementById('emote-others-self').value.trim();
    const sortOrder = document.getElementById('emote-sort').value;
    const isActive = document.getElementById('emote-active').value;
    
    if (!command) {
        showMessage('请输入表情命令名', 'error');
        return;
    }
    
    const result = await ajaxPost({
        action: 'emote_save',
        command, description,
        myself, myself_target: myselfTarget, myself_self: myselfSelf,
        target, others, others_target: othersTarget, others_self: othersSelf,
        sort_order: sortOrder, is_active: isActive
    });
    
    const msgEl = document.getElementById('emote-form-msg');
    msgEl.style.display = 'block';
    msgEl.textContent = result.message;
    msgEl.style.color = result.success ? '#4ade80' : '#e94560';
    
    if (result.success) {
        const mode = document.getElementById('emote-edit-mode').value;
        if (mode === 'add') {
            // 新增成功后重置表单
            resetEmoteForm();
        }
        loadEmoteList();
        showMessage(result.message, 'success');
    } else {
        showMessage(result.message, 'error');
    }
}

async function toggleEmote(command) {
    if (!confirm('确认切换表情 "' + command + '" 的启用状态？')) return;
    const result = await ajaxPost({ action: 'emote_toggle', command });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) {
        loadEmoteList();
    }
}

async function deleteEmote(command) {
    if (!confirm('确定要删除表情 "' + command + '" 吗？此操作不可恢复！')) return;
    const result = await ajaxPost({ action: 'emote_delete', command });
    showMessage(result.message, result.success ? 'success' : 'error');
    if (result.success) {
        loadEmoteList();
    }
}

// 表情管理的 loadEmoteList 已集成到 switchTab 函数中

// 页面加载时恢复上次 tab
(function initTab() {
    // 如果 DOM 已就绪，直接执行；否则等 DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTab);
        return;
    }
    var savedTab = '';
    try { savedTab = localStorage.getItem('admin_tab'); } catch(e) {}
    if (savedTab) {
        switchTab(savedTab);
    }
    // 如果没有保存的 tab，HTML 中默认的 active（用户管理）保持不变
})();
