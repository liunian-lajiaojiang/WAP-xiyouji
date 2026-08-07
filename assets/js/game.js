/**
 * 游戏前端JavaScript
 */

// 发送命令到服务器
function sendCommand(command, param = '') {
    const form = document.getElementById('command-form');
    const commandInput = document.getElementById('command-input');
    const paramInput = document.getElementById('param-input');
    
    commandInput.value = command;
    paramInput.value = param;
    
    form.submit();
}

// 自动聚焦输入框
document.addEventListener('DOMContentLoaded', function() {
    const commandInput = document.getElementById('command-input');
    if (commandInput) {
        commandInput.focus();
    }
});

// 处理Enter键提交
document.addEventListener('keypress', function(e) {
    if (e.key === 'Enter' && e.target.id === 'command-input') {
        e.preventDefault(); // 阻止默认行为
        
        const commandInput = document.getElementById('command-input');
        const paramInput = document.getElementById('param-input');
        const fullCommand = commandInput.value.trim();
        
        if (fullCommand) {
            // 解析命令和参数
            const parts = fullCommand.split(/\s+/);
            const command = parts[0];
            const param = parts.slice(1).join(' ');
            
            commandInput.value = command;
            paramInput.value = param;
            
            const form = document.getElementById('command-form');
            form.submit();
        }
    }
});

// 消息输出滚动到底部
window.addEventListener('load', function() {
    const messageOutput = document.getElementById('message-output');
    if (messageOutput) {
        messageOutput.scrollTop = messageOutput.scrollHeight;
    }
});
