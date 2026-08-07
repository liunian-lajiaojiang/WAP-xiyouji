/* skills_learn.js - 技能学习页面脚本（师徒授业 + NPC 门派学习共用） */

// 师徒授业：点击技能卡片提交学习表单
function learnSkill(skillId) {
    var input = document.getElementById('learn-skill-id');
    if (input) {
        input.value = skillId;
        document.getElementById('learn-form').submit();
    }
}

// NPC 门派学习：设为当前练习技能
function enableSkill(skillId) {
    fetch('action.php?action=enableSkill&skill_id=' + encodeURIComponent(skillId), {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || '设置失败');
        }
    })
    .catch(function(e) { alert('请求失败：' + e); });
}

// NPC 门派学习：修炼技能
function practiceSkill(skillId) {
    fetch('action.php?action=practiceSkill&skill_id=' + encodeURIComponent(skillId), {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            window.location.href = 'room.php';
        } else {
            alert(data.message || '修炼失败');
        }
    })
    .catch(function(e) { alert('请求失败：' + e); });
}
