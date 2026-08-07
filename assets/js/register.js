// assets/js/register.js
// 注册页面脚本

// 密码显示/隐藏功能
function togglePassword() {
    const passwordInput = document.getElementById('password');
    const toggleBtn = document.querySelector('.toggle-btn');

    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleBtn.textContent = '隐藏密码';
    } else {
        passwordInput.type = 'password';
        toggleBtn.textContent = '显示密码';
    }
}

// 表单验证
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function(event) {
            // 表单验证逻辑
        });
    }
});