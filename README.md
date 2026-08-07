# 西游记MUD - 全新搭建指南

## 📋 目录
- [系统要求](#系统要求)
- [快速开始（3步完成）](#快速开始3步完成)
- [详细步骤](#详细步骤)
- [常见问题](#常见问题)
- [项目结构](#项目结构)

---

## 💻 系统要求

### 必需软件
- **PHP 8.0+**（推荐 PHP 8.5，宝塔面板自带）
- **MySQL 8.0+** 或 **MariaDB 10.3+**
- **Composer**（安装脚本会自动下载）
- **Windows 10/11** 或 **Linux**

### 推荐的 PHP 扩展
- ✅ `openssl` - HTTPS 支持
- ✅ `curl` - 网络请求
- ✅ `zip` - 解压依赖包
- ✅ `pdo_mysql` - MySQL 数据库
- ✅ `mbstring` - 多字节字符串
- ✅ `sockets` - WebSocket 支持

---

## 🚀 快速开始（3步完成）

### 步骤 1：安装依赖
```bash
# Windows - 双击运行
install_deps_fixed.bat

# 如果网络慢，使用国内镜像
install_deps_mirror.bat
```

### 步骤 2：导入数据库
```bash
# 使用 phpMyAdmin 或命令行导入
mysql -u root -p xyj < database/xyj.sql
```

### 步骤 3：启动服务
```bash
# 启动 WebSocket 服务器
start_websocket.bat

# 或后台运行
start_websocket_background.bat
```

**完成！** 访问 `http://localhost` 开始游戏。

---

## 📖 详细步骤

### 第一步：准备环境

#### 1.1 安装宝塔面板（推荐）
1. 访问 [宝塔官网](https://www.bt.cn/)
2. 下载并安装宝塔面板
3. 安装以下软件：
   - **Nginx** 或 **Apache**
   - **PHP 8.5**
   - **MySQL 5.7+**

#### 1.2 配置 PHP
如果使用宝塔面板，PHP 已预配置好。

如果使用其他环境，确保 `php.ini` 中启用了以下扩展：
```ini
extension=openssl
extension=curl
extension=zip
extension=pdo_mysql
extension=mbstring
extension=sockets
```

#### 1.3 创建网站
1. 在宝塔面板中添加网站
2. 网站根目录指向 `xyj` 文件夹
3. 设置伪静态规则（如需要）

---

### 第二步：配置数据库

#### 2.1 创建数据库
```sql
CREATE DATABASE xyj CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

#### 2.2 导入数据
**方法一：使用 phpMyAdmin**
1. 登录 phpMyAdmin
2. 选择 `xyj` 数据库
3. 点击"导入"
4. 选择 `database/xyj.sql` 文件
5. 点击"执行"

**方法二：使用命令行**
```bash
mysql -u root -p xyj < database/xyj.sql
```

#### 2.3 配置数据库连接
编辑 `config/database.php`：
```php
<?php
return [
    'host' => 'localhost',
    'port' => 3306,
    'database' => 'xyj',
    'username' => 'root',
    'password' => '你的密码',
    'charset' => 'utf8mb4',
];
```

---

### 第三步：安装 Composer 依赖

#### 3.1 自动安装（推荐）
**Windows：**
```bash
# 双击运行
install_deps_fixed.bat
```

这个脚本会：
1. ✅ 检测 PHP 环境（宝塔/系统）
2. ✅ 创建临时配置（绕过 putenv/proc_open 限制）
3. ✅ 启用 openssl、curl、zip 扩展
4. ✅ 自动下载并安装 Ratchet 依赖
5. ✅ 清理临时文件

**Linux：**
```bash
# 安装 Composer
curl -sS https://getcomposer.org/installer | php

# 安装依赖
php composer.phar install --no-dev
```

#### 3.2 手动安装
如果自动脚本失败：

```bash
# 1. 下载 Composer
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
php -r "unlink('composer-setup.php');"

# 2. 安装依赖
php composer.phar install --no-dev
```

#### 3.3 验证安装
```bash
# 检查 vendor 目录
ls vendor/autoload.php

# 测试 Ratchet
php -r "require 'vendor/autoload.php'; echo class_exists('Ratchet\Server\IoServer') ? 'OK' : 'FAIL';"
```

应该输出：`OK`

---

### 第四步：启动 WebSocket 服务器

#### 4.1 前台启动（调试用）
```bash
# Windows - 双击运行
start_websocket.bat

# Linux
php websocket_server.php
```

你会看到：
```
WebSocket服务器启动成功
监听端口: 8080
```

**保持窗口打开**，不要关闭。

#### 4.2 后台启动（生产用）
```bash
# Windows - 双击运行
start_websocket_background.bat

# Linux
nohup php websocket_server.php > ws.log 2>&1 &
```

#### 4.3 验证 WebSocket
1. 打开浏览器开发者工具（F12）
2. 切换到 Console 标签
3. 访问游戏并登录
4. 应该看到：`✅ WebSocket连接成功`

---

### 第五步：访问游戏

#### 5.1 打开浏览器
访问：`http://localhost` 或 `http://你的域名`

#### 5.2 注册账号
1. 点击"注册"
2. 填写用户名和密码
3. 点击"注册"

#### 5.3 创建角色
1. 登录后进入角色选择页面
2. 点击"创建新角色"
3. 选择门派和属性
4. 开始游戏！

#### 5.4 测试功能
- **移动**：点击方向按钮或使用命令（如 `go north`）
- **对话**：与 NPC 对话（如 `ask 某人 about 某事`）
- **聊天**：打开聊天页面，查看实时消息
- **战斗**：使用 `hit` 或 `kill` 命令

---

## ❓ 常见问题

### Q1: Composer 安装失败
**错误信息：**
```
The openssl extension is required for SSL/TLS protection
```

**解决方案：**
确保 PHP 启用了 openssl 扩展：
```bash
php -m | grep openssl
```

如果没有输出，编辑 `php.ini`：
```ini
extension=openssl
```

---

### Q2: proc_get_status 未定义
**错误信息：**
```
Call to undefined function Symfony\Component\Process\proc_get_status()
```

**原因：** PHP 的 `disable_functions` 禁用了 `proc_get_status`

**解决方案：**
使用我们提供的安装脚本，它会自动处理这个问题：
```bash
install_deps_fixed.bat
```

脚本会创建临时配置，移除 `proc_get_status` 的限制。

---

### Q3: WebSocket 连接失败
**错误信息：**
```
WebSocket connection to 'ws://localhost:8080' failed
```

**检查清单：**
1. ✅ WebSocket 服务器是否运行？
   ```bash
   # 检查进程
   tasklist | findstr php.exe  # Windows
   ps aux | grep php           # Linux
   ```

2. ✅ 端口 8080 是否被占用？
   ```bash
   netstat -ano | findstr 8080  # Windows
   lsof -i :8080                # Linux
   ```

3. ✅ 防火墙是否阻止了端口？
   - Windows：允许端口 8080 通过防火墙
   - Linux：`ufw allow 8080`

4. ✅ 浏览器控制台是否有错误？
   - 按 F12 打开开发者工具
   - 查看 Console 和 Network 标签

---

### Q4: 消息不实时推送
**症状：** 执行动作后，聊天页面没有实时更新

**检查清单：**
1. ✅ WebSocket 是否连接成功？
   - 查看浏览器控制台
   - 应该看到 "✅ WebSocket连接成功"

2. ✅ action.php 是否正确入队消息？
   - 检查 `daemons/MessageDaemon.php`
   - 确认 `queueMessageToSelf()` 被调用

3. ✅ WebSocket 服务器是否在处理队列？
   - 查看 WebSocket 服务器窗口
   - 应该每秒检查一次 `ws_push_queue.json`

4. ✅ 清除浏览器缓存
   - 按 Ctrl+F5 强制刷新

---

### Q5: 中文显示乱码
**解决方案：**
1. 确保数据库使用 `utf8mb4` 编码
2. 确保 PHP 文件保存为 UTF-8（无 BOM）
3. 在 HTML 中添加：
   ```html
   <meta charset="UTF-8">
   ```

---

### Q6: 性能优化建议

#### 6.1 PHP 优化
编辑 `php.ini`：
```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
```

#### 6.2 MySQL 优化
编辑 `my.cnf`：
```ini
[mysqld]
innodb_buffer_pool_size=256M
query_cache_size=64M
max_connections=200
```

#### 6.3 Nginx 优化
```nginx
worker_processes auto;
events {
    worker_connections 1024;
}
```

---

## 📂 项目结构

```
xyj/
├── 📦 依赖管理
│   ├── composer.json          ← 依赖配置
│   ├── composer.lock          ← 锁定文件
│   ├── composer.phar          ← Composer 工具
│   └── vendor/                ← 依赖包
│       └── cboden/ratchet/    ← Ratchet WebSocket 库
│
├── 🔧 安装脚本
│   ├── install_deps_fixed.bat ← 推荐安装脚本
│   └── install_deps_mirror.bat← 国内镜像版
│
├── 🚀 启动脚本
│   ├── start.bat              ← Windows 启动
│   ├── start.sh               ← Linux 启动
│   ├── start_websocket.bat           ← WS 前台
│   └── start_websocket_background.bat← WS 后台
│
├── 📖 文档
│   ├── README.md              ← 本文件
│   └── WEBSOCKET_SETUP.md     ← WebSocket 详细说明
│
├── 💾 运行时
│   ├── .user.ini              ← PHP 配置
│   └── ws_push_queue.json     ← 消息队列（自动生成）
│
├── 📂 核心代码
│   ├── websocket_server.php   ← WebSocket 服务器
│   ├── chat.php               ← 聊天页面（WebSocket）
│   ├── room.php               ← 房间页面
│   ├── action.php             ← 动作处理
│   ├── index.php              ← 入口文件
│   │
│   ├── daemons/               ← 守护进程
│   │   ├── MessageDaemon.php  ← 消息守护进程
│   │   ├── CombatDaemon.php   ← 战斗守护进程
│   │   └── ...
│   │
│   ├── commands/              ← 命令处理器
│   │   ├── go.php             ← 移动命令
│   │   ├── talk.php           ← 对话命令
│   │   ├── hit.php            ← 攻击命令
│   │   └── ...
│   │
│   ├── models/                ← 数据模型
│   │   ├── Character.php      ← 角色模型
│   │   ├── Room.php           ← 房间模型
│   │   └── ...
│   │
│   ├── helpers/               ← 辅助类
│   │   ├── CombatSystemHelper.php
│   │   └── ...
│   │
│   ├── config/                ← 配置文件
│   │   ├── database.php       ← 数据库配置
│   │   └── game.php           ← 游戏配置
│   │
│   └── includes/              ← 公共函数
│       ├── db.php             ← 数据库连接
│       └── functions.php      ← 通用函数
│
├── 🌐 静态页面
│   ├── index.html             ← 首页
│   ├── 404.html               ← 错误页面
│   └── about_us.html          ← 关于我们
│
├── 🗄️ 数据库
│   └── database/
│       └── xyj.sql            ← 数据库脚本
│
├── 🎨 资源文件
│   └── assets/
│       ├── css/               ← 样式表
│       ├── js/                ← JavaScript
│       └── images/            ← 图片
│
└── 📝 日志
    └── logs/                  ← 运行日志
```

---

## 🔧 技术栈

### 后端
- **PHP 8.5** - 主要编程语言
- **Ratchet** - WebSocket 服务器库
- **ReactPHP** - 异步事件循环
- **MySQL** - 数据库

### 前端
- **HTML5** - 页面结构
- **CSS3** - 样式设计
- **JavaScript (原生)** - 交互逻辑
- **WebSocket API** - 实时通信

### 架构特点
- ✅ **混合架构**：HTTP 用于动作执行，WebSocket 用于消息推送
- ✅ **事件驱动**：基于 ReactPHP 的异步事件循环
- ✅ **解耦设计**：消息入队与推送分离
- ✅ **实时推送**：毫秒级延迟的消息推送

---

## 📞 获取帮助

### 遇到问题？
1. 查看本文档的 [常见问题](#常见问题) 部分
2. 检查 WebSocket 服务器窗口日志
3. 查看浏览器开发者工具控制台
4. 查看 `logs/` 目录下的日志文件

### 反馈问题
如果遇到无法解决的问题，请提供：
1. 错误信息（完整截图）
2. 操作步骤（如何复现）
3. 环境信息（PHP 版本、操作系统等）
4. 相关日志文件

---

## 🎉 开始游戏

现在你已经完成了所有配置，可以开始享受西游记 MUD 的乐趣了！

**祝你游戏愉快！** 🐒✨

---

*最后更新：2026-05-19*
