function confirmDelete(charId, charName) {
    if (confirm(`确定要删除角色「${charName}」吗？\n\n⚠️ 此操作不可恢复！`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'character_select.php';
        
        const inputCharId = document.createElement('input');
        inputCharId.type = 'hidden';
        inputCharId.name = 'char_id';
        inputCharId.value = charId;
        
        const inputAction = document.createElement('input');
        inputAction.type = 'hidden';
        inputAction.name = 'delete_char';
        inputAction.value = '1';
        
        form.appendChild(inputCharId);
        form.appendChild(inputAction);
        document.body.appendChild(form);
        form.submit();
    }
}

function updateGiftTotal() {
    const str = parseInt(document.getElementById('str').value) || 0;
    const con = parseInt(document.getElementById('con').value) || 0;
    const int_val = parseInt(document.getElementById('int').value) || 0;
    const spi = parseInt(document.getElementById('spi').value) || 0;
    
    const total = str + con + int_val + spi;
    const display = document.getElementById('giftTotalDisplay');
    const status = document.getElementById('giftTotalStatus');
    
    display.textContent = total;
    
    if (total === 90) {
        display.style.color = 'var(--primary)';
        status.textContent = '正常';
        status.style.color = 'green';
    } else if (total > 90) {
        display.style.color = '#ff0000';
        status.textContent = '超出' + (total - 90) + '点';
        status.style.color = '#ff0000';
    } else {
        display.style.color = '#ff9800';
        status.textContent = '还差' + (90 - total) + '点';
        status.style.color = '#ff9800';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updateGiftTotal();
});