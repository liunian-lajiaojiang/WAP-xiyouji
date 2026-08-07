// JavaScript Document - 新版事件处理
// 使用事件委托捕获表单提交事件

document.addEventListener('DOMContentLoaded', function() {
    // 页面加载时填充保存的数据
    const savedName = localStorage.getItem('xyj_username');
    const savedPass = localStorage.getItem('xyj_password');
    
    if (savedName) {
        document.getElementById('username').value = savedName;
    }
    if (savedPass) {
        document.getElementById('password').value = savedPass;
    }
    
    // 监听表单提交
    const loginForm = document.querySelector('form');
    if (loginForm) {
        loginForm.addEventListener('submit', function(event) {
            // 避免默认提交行为（因为我们要处理保存数据）
            event.preventDefault();
            
            // 获取输入值
            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;
            
            // 检查是否是记住账号/密码
            const remember = document.getElementById('remember').checked;
            
            // 保存用户名和密码
            localStorage.setItem('xyj_username', username);
            if (remember) {
                localStorage.setItem('xyj_password', password);
            } else {
                localStorage.removeItem('xyj_password');
            }
            
            // 手动提交表单
            this.submit();
        });
    }
});