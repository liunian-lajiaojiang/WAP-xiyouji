<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 交易页面 - 显示商人物品列表并提供交易功能
 */

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once MODEL_PATH . 'Npc.php';
require_once MODEL_PATH . 'Shop.php';
require_once MODEL_PATH . 'Item.php';
require_once HELPER_PATH . 'MoneyHelper.php';

// 要求登录
require_login();

$charId = get_char_id();
$char = CharacterModel::getFullInfo($charId);

if (!$char) {
    redirect('character_select.php');
}

// 获取NPC ID
$npcId = $_GET['npc_id'] ?? 0;
$actionParam = $_GET['action'] ?? ''; // 从URL 参数获取 action（如 deposit, withdraw）
$area = $_GET['area'] ?? '';
$room = $_GET['room'] ?? '';

// 如果没有 npc_id，尝试根据area/room 查找对应的商人NPC
if (!$npcId && !empty($area) && !empty($room)) {
    // 构建完整的room_id
    $fullRoomId = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;

    // 首先尝试精确匹配房间中的商人NPC
    $sql = "SELECT id FROM npcs WHERE spawn_room = ? AND (merchant = 1 OR shop_type IS NOT NULL) LIMIT 1";
    $npc = Database::queryOne($sql, [$fullRoomId]);

    if ($npc) {
        $npcId = $npc['id'];
    } else {
        // 如果精确匹配失败，根据URL中的action推断shop_type进行查找
        $actionParam = $_GET['action'] ?? '';
        $shopTypeHint = null;

        if (in_array($actionParam, ['deposit', 'withdraw', 'account', 'convert'])) {
            $shopTypeHint = 'bank';
        } elseif ($actionParam === 'sell') {
            $shopTypeHint = 'hockshop';
        }

        if ($shopTypeHint) {
            // 按区域和商店类型查找
            $sql = "SELECT id FROM npcs WHERE spawn_area = ? AND shop_type = ? AND merchant = 1 LIMIT 1";
            $npc = Database::queryOne($sql, [$area, $shopTypeHint]);
            if ($npc) {
                $npcId = $npc['id'];
            }
        }

        // 如果仍然找不到，尝试按区域查找任意商人
        if (!$npcId) {
            $sql = "SELECT id FROM npcs WHERE spawn_area = ? AND (merchant = 1 OR shop_type IS NOT NULL) LIMIT 1";
            $npc = Database::queryOne($sql, [$area]);
            if ($npc) {
                $npcId = $npc['id'];
            }
        }
    }
}

$npc = NpcModel::find($npcId);

if (!$npc) {
    die('NPC不存在');
}

// 检查NPC是否是商人
if (!isset($npc['merchant']) || !$npc['merchant']) {
    die($npc['name'] . '不想和你做买卖。');
}

// 获取商店类型
$shopType = $npc['shop_type'] ?? 'general';

// 处理交易动作
$message = '';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 如果有URL 参数中的 action，且还没有POST 数据，则显示提示消息
if ($actionParam && empty($action)) {
    switch ($actionParam) {
        case 'deposit':
            $message = HIG . '请选择存款金额和货币类型：' . HTML_NOR;
            break;
        case 'withdraw':
            $message = HTML_HIYEL . '请选择取款金额和货币类型：' . HTML_NOR;
            break;
        case 'buy':
            $message = HTML_HICYN . '请选择要购买的物品：' . HTML_NOR;
            break;
        case 'sell':
            $message = HTML_HIWHT . '请选择要出售的物品：' . HTML_NOR;
            break;
    }
}

if ($action) {
    switch ($action) {
        case 'buy':
            // 购买物品
            $itemId = $_POST['item_id'] ?? '';
            $category = $_POST['category'] ?? '';
            $quantity = intval($_POST['quantity'] ?? 1);
            if ($itemId && $quantity > 0) {
                $userId = intval($char['user_id'] ?? 0);
                $isBlocked = Database::queryOne('SELECT id FROM user_blocks WHERE user_id = ? AND block_type = ?', [$userId, 'trade']);
                if ($isBlocked) {
                    $message = '你的交易功能已被封禁';
                    break;
                }

                $itemInfo = Database::queryOne(
                    "SELECT name FROM items WHERE item_id = ? AND category = ?",
                    [$itemId, $category]
                );

                $result = ShopModel::buyItem($charId, $npcId, $itemId, $quantity, $category);
                $message = $result['message'];
                // 刷新角色信息
                $char = CharacterModel::getFullInfo($charId);
                
                // 广播购买消息给房间内其他玩家
                if ($result['success'] && $itemInfo) {
                    require_once __DIR__ . '/../daemons/MessageDaemon.php';
                    MessageDaemon::broadcastToRoom(
                        $char['current_room'],
                        "{$char['name']}购买了{$itemInfo['name']}。\n",
                        $charId
                    );
                }
            }
            break;

        case 'sell':
            // 出售物品
            $inventoryId = intval($_POST['inventory_id'] ?? 0);
            if ($inventoryId > 0) {
                // 先获取物品信息（在出售前）
                $itemInfo = Database::queryOne(
                    "SELECT ci.*, gi.name FROM character_inventory ci LEFT JOIN items gi ON ci.item_id = gi.item_id AND ci.category = gi.category WHERE ci.id = ? AND ci.char_id = ?",
                    [$inventoryId, $charId]
                );
                
                $result = ShopModel::sellItem($charId, $npcId, $inventoryId);
                $message = $result['message'];
                $char = CharacterModel::getFullInfo($charId);
                
                // 广播出售消息给房间内其他玩家
                if ($result['success'] && $itemInfo) {
                    require_once __DIR__ . '/../daemons/MessageDaemon.php';
                    MessageDaemon::broadcastToRoom(
                        $char['current_room'],
                        "{$char['name']}出售了{$itemInfo['name']}。\n",
                        $charId
                    );
                }
            }
            break;

        case 'value':
            // 估价物品
            $inventoryId = intval($_POST['inventory_id'] ?? 0);
            if ($inventoryId > 0) {
                $result = ShopModel::valueItem($charId, $npcId, $inventoryId);
                $message = $result['message'];
            }
            break;

        case 'deposit':
            // 存款
            $amount = intval($_POST['amount'] ?? 0);
            $currencyType = $_POST['currency_type'] ?? 'coin';
            if ($amount > 0) {
                $result = ShopModel::deposit($charId, $amount, $currencyType);
                $message = $result['message'];
                $char = CharacterModel::getFullInfo($charId);
            }
            break;

        case 'withdraw':
            // 取款
            $amount = intval($_POST['amount'] ?? 0);
            $currencyType = $_POST['currency_type'] ?? 'coin';
            if ($amount > 0) {
                $result = ShopModel::withdraw($charId, $amount, $currencyType);
                $message = $result['message'];
                $char = CharacterModel::getFullInfo($charId);
            }
            break;

        case 'account':
            // 查账
            $result = ShopModel::checkAccount($charId);
            $message = $result['message'];
            break;

        case 'convert':
            // 货币兑换
            $fromType = $_POST['from_type'] ?? '';
            $toType = $_POST['to_type'] ?? '';
            $amount = intval($_POST['amount'] ?? 0);
            if ($fromType && $toType && $amount > 0) {
                $result = ShopModel::convertMoney($charId, $fromType, $toType, $amount);
                $message = $result['message'];
                $char = CharacterModel::getFullInfo($charId);
            }
            break;
    }
}

// 获取商店物品列表（所有商店类型）
$shopItems = [];
if ($shopType != 'bank') {
    $shopItems = ShopModel::getShopItems($npcId);
}

// 获取角色背包物品（用于出售）
$characterItems = ItemModel::getCharacterItems($charId);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($npc['name']) ?> - <?= $shopType == 'hockshop' ? '当铺' : ($shopType == 'bank' ? '钱庄' : '商店') ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
    <style>
        .item-row { display: flex; align-items: center; gap: 8px; }
        .form-inline { display: inline; margin: 0; }
    </style>
</head>
<body>
<div class="trade-container">
        <?php if (!empty($area) && !empty($room)): ?>
            <a href="room.php?area=<?= urlencode($area) ?>&room=<?= urlencode($room) ?>">返回房间</a>
        <?php else: ?>
            <a href="room.php?area=<?= urlencode($char['current_area']) ?>&room=<?= urlencode($char['current_room']) ?>">返回房间</a>
        <?php endif; ?>
<hr>
    <div class="npc-info">
        <p class="money-display">你带着：<?= MoneyHelper::formatMoney($charId) ?></p>
        <?php if ($shopType == 'bank'): ?>
        <p class="money-display">钱庄存款：<?= ShopModel::formatMoney($char['balance'] ?? 0) ?></p>
        <?php endif; ?>
    </div>
    
    <?php if ($message): ?>
    <div class="message"><?= nl2br(h($message)) ?></div>
    <?php endif; ?>
    
    <!-- 普通商店和当铺功能 -->
    <?php if ($shopType != 'bank'): ?>
    
    <!-- 购买物品 -->
    <div class="section">
        <div class="section-title">🛒 购买物品</div>
        <?php if (empty($shopItems)): ?>
        <div class="empty-message">暂无物品出售</div>
        <?php else: ?>
        <div class="item-list">
            <?php foreach ($shopItems as $item): ?>
            <div class="item-row">
                <span class="item-name"><?= h($item['item_name']) ?></span>
                <span class="item-price">（<?= $item['price'] ?> 铜钱）</span>
                <form method="POST" class="form-inline">
                    <input type="hidden" name="action" value="buy">
                    <input type="hidden" name="item_id" value="<?= h($item['item_id']) ?>">
                    <input type="hidden" name="category" value="<?= h($item['category'] ?? '') ?>">
                    <input type="number" name="quantity" value="1" min="1" max="<?= $item['stock'] > 0 ? $item['stock'] : 99 ?>" style="width: 60px;">
                    <button type="submit" class="btn">购买</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- 当铺功能：出售物品 -->
    <?php if ($shopType == 'hockshop'): ?>
    
    <!-- 出售物品 -->
    <div class="section">
        <div class="section-title">💰 出售物品</div>
        <?php if (empty($characterItems)): ?>
        <div class="empty-message">你身上没有任何物品</div>
        <?php else: ?>
        <div class="item-list">
            <?php 
            // 过滤掉货币物品
            $sellableItems = array_filter($characterItems, function($item) {
                return !in_array($item['item_id'], ['gold', 'silver', 'coin']);
            });
            
            if (empty($sellableItems)): 
            ?>
            <div class="empty-message">你身上没有可出售的物品</div>
            <?php else: ?>
                <?php foreach ($sellableItems as $item): ?>
                <div class="item-row">
                    <span class="item-name">
                        <?= h($item['name']) ?><?= $item['quantity'] > 1 ? " x{$item['quantity']}" : '' ?>
                        <?php if (!empty($item['equipped'])): ?> <span style="color:green;">[已装备]</span><?php endif; ?>
                    </span>
                    <form method="POST" class="form-inline">
                        <input type="hidden" name="inventory_id" value="<?= $item['id'] ?>">
                        <?php if (!empty($item['equipped'])): ?>
                        <span style="color:#666; font-size:0.9em;">装备中，不可出售</span>
                        <?php else: ?>
                        <button type="submit" name="action" value="value" class="btn" style="background: #95a5a6;">估价</button>
                        <button type="submit" name="action" value="sell" class="btn" style="background: #e67e22;">出售</button>
                        <?php endif; ?>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; // 当铺功能结束 ?>
    <?php endif; // 普通商店和当铺功能结束 ?>
    
    <!-- 钱庄功能 -->
    <?php if ($shopType == 'bank'): ?>
    
    <!-- 查账 -->
    <div class="section">
        <div class="section-title">📊 账户查询</div>
        <form method="POST">
            <input type="hidden" name="action" value="account">
            <button type="submit" class="btn">查询余额</button>
        </form>
    </div>
    
    <!-- 存款 -->
    <div class="section">
        <div class="section-title">💵 存款</div>
        <form method="POST" class="form-inline">
            <input type="hidden" name="action" value="deposit">
            <input type="number" name="amount" placeholder="金额" required min="1" style="width: 100px;">
            <select name="currency_type">
                <option value="coin" selected>铜钱</option>
                <option value="silver">银子</option>
                <option value="gold">黄金</option>
            </select>
            <button type="submit" class="btn">存入</button>
        </form>
    </div>
    
    <!-- 取款 -->
    <div class="section">
        <div class="section-title">💸 取款</div>
        <form method="POST" class="form-inline">
            <input type="hidden" name="action" value="withdraw">
            <input type="number" name="amount" placeholder="金额" required min="1" style="width: 100px;">
            <select name="currency_type">
                <option value="coin" selected>铜钱</option>
                <option value="silver">银子</option>
                <option value="gold">黄金</option>
            </select>
            <button type="submit" class="btn">取出</button>
        </form>
    </div>
    
    <!-- 货币兑换 -->
    <div class="section">
        <div class="section-title">🔄 货币兑换</div>
        <form method="POST" class="form-inline">
            <input type="hidden" name="action" value="convert">
            <input type="number" name="amount" placeholder="数量" required min="1" style="width: 100px;">
            <select name="from_type">
                <option value="gold">黄金</option>
                <option value="silver" selected>银子</option>
                <option value="coin">铜钱</option>
            </select>
            <span>兑换成</span>
            <select name="to_type">
                <option value="gold">黄金</option>
                <option value="silver">银子</option>
                <option value="coin" selected>铜钱</option>
            </select>
            <button type="submit" class="btn">兑换</button>
        </form>
        <p style="font-size: 0.9em; color: #666; margin-top: 10px;">汇率：两黄金= 100两银子= 10000铜钱</p>
    </div>
    
    <?php endif; ?>
</div>
</body>
</html>

