# 大唐股票买卖

基于 MySQL 8.0.29 + PHP 8.5.1 + HTML5 的股票交易模拟游戏。

## 功能特性

- **初始资金**: $100
- **模拟股票**: 10只真实知名股票（苹果、谷歌、微软等）
- **做多机制**: 买入股票，价格上涨获利
- **做空机制**: 借股票卖出，价格下跌获利（需要50%保证金）
- **实时行情**: 股票价格自动波动更新
- **持仓管理**: 查看做多/做空持仓及盈亏
- **交易记录**: 完整的买卖历史

## 安装步骤

### 1. 创建数据库

```bash
mysql -u root -p < database.sql
```

### 2. 配置数据库连接

编辑 `config.php`，修改数据库连接信息：

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '你的密码');
define('DB_NAME', 'stock_game');
```

### 3. 部署到Web服务器

将项目文件放到Web服务器目录（如 Apache 的 htdocs 或 Nginx 的 html 目录）。

### 4. 访问游戏

打开浏览器访问：`http://localhost/index.html`

## 项目结构

```
├── index.html          # 前端主页面
├── config.php          # 数据库配置
├── database.sql        # 数据库结构
├── api/
│   ├── user.php        # 用户API
│   ├── stocks.php      # 股票API
│   ├── trade.php       # 交易API
│   ├── positions.php   # 持仓API
│   └── history.php     # 历史记录API
└── README.md
```

## 游戏规则

1. **做多（买入）**: 预期股票上涨，低价买入高价卖出
2. **做空**: 预期股票下跌，借股票卖出，低价买回还券
   - 做空需要50%保证金
   - 盈利 = (卖出价 - 买入价) × 数量

3. **价格波动**: 点击右下角刷新按钮或等待自动更新

## 技术栈

- **后端**: PHP 8.5.1
- **数据库**: MySQL 8.0.29
- **前端**: HTML5 + CSS3 + JavaScript (原生)
- **API**: RESTful JSON API

## 新增功能 - K线图与技术指标

### K线图功能
- **多周期切换**: 1分钟 / 5分钟 / 15分钟 / 1小时 / 日线
- **MA移动平均线**: MA5 / MA10 / MA20 / MA60
- **成交量显示**
- **数据缩放**: 支持鼠标滚轮缩放查看

### 技术指标

| 指标 | 说明 | 信号 |
|------|------|------|
| **MACD** | 指数平滑异同平均线 | DIF上穿DEA买入，下穿卖出 |
| **RSI** | 相对强弱指标 | >70超买(卖出), <30超卖(买入) |
| **KDJ** | 随机指标 | K>80超买区, K<20超卖区 |
| **MA趋势** | 均线排列 | MA5>MA20多头排列(上涨) |

### 股票详情页
- 点击股票列表的"📈 详情"按钮进入
- 查看K线图和技术指标
- 快速交易（买入/做空）

## 数据库更新

添加K线数据表后，需要重新导入数据库：

```bash
mysql -u root -p < database.sql
```

生成历史K线数据（调用API）：
```bash
curl -X POST http://localhost/api/kline.php \
  -H "Content-Type: application/json" \
  -d '{"generate_history":true,"stock_id":1,"days":7}'
```

## 后续可扩展功能

- 用户注册登录系统
- 排行榜
- 交易手续费
- 杠杆交易
- 实时行情推送(WebSocket)
- 更多技术指标（布林带、成交量等）
