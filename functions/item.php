<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/../config/game.php';

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
Database::addRoomItemsEnchantmentsColumn();
require_once MODEL_PATH . 'Item.php';
require_once MODEL_PATH . 'Character.php';
require_once MODEL_PATH . 'Room.php';
require_once HELPER_PATH . 'LiquidContainerHelper.php';
require_once __DIR__ . '/../commands/read.php';
require_once MODEL_PATH . 'ContainerModel.php';

// 要求登录
require_login();

// inv_id 优先路径：通过背包记录主键定位物品
$invId = intval($_GET['inv_id'] ?? 0);
if ($invId > 0) {
    $invItem = ItemModel::findInInventoryById($invId);
    if ($invItem) {
        // 用背包记录中的 item_id 和 category 来查找 items 表的完整定义
        $itemId = $invItem['item_id'];
        $category = $invItem['category'] ?? '';
        // findInInventoryById 已经 JOIN 了 items 表，可以直接用返回的数据
        // 但 item.php 详情页可能需要 items 表的原始定义
        $item = ItemModel::findByItemId($itemId, $category);
        if (!$item) {
            // fallback：直接使用 JOIN 后的数据
            $item = $invItem;
        }
    }
}

if (!isset($item)) {
    $itemId = $_GET['id'] ?? '';
    $category = $_GET['category'] ?? '';
    $item = ItemModel::findByItemId($itemId, $category);

    if (!$item) {
        if ($category !== '') {
            $item = ItemModel::findByItemId($itemId, '');
        }

        if (!$item) {
            $npc = Database::queryOne('SELECT id, npc_id, name FROM npcs WHERE npc_id = ?', [$itemId]);
            if ($npc) {
                header("Location: npc.php?id={$npc['id']}");
                exit;
            }

            die('物品不存在');
        }
    }
}

$charId = get_char_id();
$char = CharacterModel::getFullInfo($charId);

$sourceItem = null;
if ($item['item_id'] === 'photo') {
    $roomItems = [];
    if ($char) {
        $roomItems = RoomModel::getItemsInRoom($char['current_area'], $char['current_room']);
    }
    
    foreach ($roomItems as $ri) {
        if ($ri['item_id'] === $itemId && ($category === '' || ($ri['category'] ?? '') === $category)) {
            $sourceItem = $ri;
            break;
        }
    }
    
    if (!$sourceItem && $char) {
        $charItems = ItemModel::getCharacterItems($charId);
        foreach ($charItems as $ci) {
            if ($ci['item_id'] === $itemId && ($category === '' || ($ci['category'] ?? '') === $category)) {
                $sourceItem = $ci;
                break;
            }
        }
    }
    
    if ($sourceItem && !empty($sourceItem['category'])) {
        $item['name'] = $sourceItem['category'] . '照片';
    } elseif ($category !== '') {
        $item['name'] = $category . '照片';
    }
    
    if ($sourceItem && !empty($sourceItem['enchantments'])) {
        $ench = json_decode($sourceItem['enchantments'], true);
        if ($ench && !empty($ench['photo_desc'])) {
            $item['description'] = $ench['photo_desc'];
        }
    } elseif ($category !== '') {
        $room = RoomModel::load($char['current_area'], $char['current_room']);
        if ($room) {
            $roomDesc = $room['description'] ?? '';
            $item['description'] = "{$category}照片\n\n{$roomDesc}";
        } else {
            $item['description'] = "{$category}照片";
        }
    }
}

// Type映射为中文
$typeMap = [
    'weapon' => '武器',
    'armor' => '防具',
    'food' => '食物',
    'drug' => '药品',
    'fabao' => '法宝',
    'book' => '书籍',
    'misc' => '杂物',
    'flower' => '花卉',
    'magic' => '魔法物品',
    'container' => '容器',
    'npc' => 'NPC物品',
];

// 优先使用物品自身的类型（items表），避免使用通用模板
$itemType = $item['type'] ?? 'misc';

// 如果 items 表中该物品为通用类型（misc），但背包中有更精确的分类，则使用背包分类
if ($itemType === 'misc' && $char) {
    $invItemForType = null;
    $charItemsForType = ItemModel::getCharacterItems($charId);
    foreach ($charItemsForType as $ci) {
        if ($ci['item_id'] === $itemId && ($category === '' || ($ci['category'] ?? '') === $category)) {
            $invItemForType = $ci;
            break;
        }
    }
    if ($invItemForType && ($invItemForType['item_type'] ?? 'misc') !== 'misc') {
        $itemType = $invItemForType['item_type'];
    } elseif ($category !== '') {
        // 使用请求中的 category 作为类型
        $itemType = $category;
    }
}

$chineseType = $typeMap[$itemType] ?? $itemType;

// 检查物品是否在房间里（地上物品）
$isOnGround = false;
$groundItemQuantity = 0;
$groundItem = null;

if ($char) {
    $roomItems = RoomModel::getItemsInRoom($char['current_area'], $char['current_room']);
    foreach ($roomItems as $roomItem) {
        if ($roomItem['item_id'] === $itemId && ($category === '' || ($roomItem['category'] ?? '') === $category)) {
            $isOnGround = true;
            $groundItemQuantity = $roomItem['quantity'];
            $groundItem = $roomItem;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
    <title><?php echo h($item['name']); ?>_西游记mud</title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
    <link rel="stylesheet" href="../assets/css/item.css">
</head>
<body>
<div class="item-content">
<div class="item-title">【<?php echo h($chineseType); ?> 】<?php 
$displayName = $item['name'];
if ($item['item_id'] === 'photo' && $isOnGround && $groundItem && !empty($groundItem['category'])) {
    $displayName = $groundItem['category'] . '照片';
}
echo h($displayName);
?>
</div>

<?php if ($isOnGround): ?>
<div class="item-ground-warning">
    ⚠️ 这是地上的物品（入<?php echo $groundItemQuantity; ?> 个）
</div>
<?php endif; ?>

<?php
// 获取背包中该物品的详细信息（用于照片描述、液体状态等）
$invItem = null;
if (!$isOnGround && $char) {
    $charItems = ItemModel::getCharacterItems($charId);
    foreach ($charItems as $ci) {
        if ($ci['item_id'] === $itemId && ($category === '' || ($ci['category'] ?? '') === $category)) {
            $invItem = $ci;
            break;
        }
    }
}
?>

<div class="item-description">
<?php 
$desc = $item['description'] ?? '';
if ($item['item_id'] === 'photo') {
    $sourceItem = $isOnGround ? $groundItem : $invItem;
    if (!empty($sourceItem['enchantments'])) {
        $ench = json_decode($sourceItem['enchantments'], true);
        if ($ench && !empty($ench['photo_desc'])) {
            $desc = $ench['photo_desc'];
        }
    }
}
echo nl2br(h($desc));
?>
</div>

<?php if (LiquidContainerHelper::isLiquidContainer($item)): ?>
<?php
$maxL = (int)($item['max_liquid'] ?? 0);
if ($invItem && LiquidContainerHelper::shouldShowLiquidStatus($invItem)) {
    $remL = isset($invItem['liquid_remaining']) && $invItem['liquid_remaining'] !== null && $invItem['liquid_remaining'] !== ''
        ? (int)$invItem['liquid_remaining']
        : $maxL;
    $nameL = $invItem['liquid_name'] ?? '';
    if (!$nameL) {
        $def = LiquidContainerHelper::getDefaultLiquid($invItem['item_id'] ?? $itemId);
        $nameL = $def['name'] ?? '液体';
    }
    $statusText = LiquidContainerHelper::getStatusText($remL, $maxL, $nameL);
    echo "<div class=\"item-info hint-text\">{$statusText}。 ({$remL}/{$maxL})</div>\n";
} else {
    echo "<div class=\"item-info hint-text\">容量：{$maxL} 份</div>\n";
}
?>
<?php endif; ?>

<?php
// 容器物品：显示容器内的物品
$isContainer = !empty($item['is_container']) && intval($item['is_container']) > 0;
if ($isContainer && $invItem):
    $containerType = 'character_inventory';
    $containerId = intval($invItem['id']);
    $containerItems = ContainerModel::getContainerItems($containerType, $containerId);
    $itemCount = ContainerModel::getItemCount($containerType, $containerId);
    $maxItems = intval($item['max_items'] ?? 10);
    $currentEncumbrance = ContainerModel::getCurrentEncumbrance($containerType, $containerId);
    $maxEncumbrance = intval($item['max_encumbrance'] ?? 0);
?>
<div class="item-info">
    <div class="container-stats">
        <span class="stat-text">容量：<?php echo $itemCount; ?>/<?php echo $maxItems; ?> 个物品</span><br>
        <?php if ($maxEncumbrance > 0): ?>
        <span class="stat-text">负重：<?php echo $currentEncumbrance; ?>/<?php echo $maxEncumbrance; ?></span><br>
        <?php endif; ?>
    </div>
    <?php if (!empty($containerItems)): ?>
    <div class="container-items">
        <div class="container-title">里面有：</div>
        <?php foreach ($containerItems as $ci): ?>
        <div class="container-item">
            <?php if (!empty($ci['description']) || !empty($ci['type'])): ?>
            <a href="item.php?id=<?php echo urlencode($ci['item_id']); ?>&category=<?php echo urlencode($ci['category'] ?? ''); ?>">
                <?php echo h($ci['name'] ?? $ci['item_id']); ?>
            </a>
            <?php else: ?>
            <span><?php echo h($ci['name'] ?? $ci['item_id']); ?></span>
            <?php endif; ?>
            <?php if (intval($ci['quantity']) > 1): ?>
            <span class="item-quantity"> x<?php echo intval($ci['quantity']); ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="container-empty hint-text">里面空空如也。</div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
// 装备属性展示
$equipStats = [];
// 武器类型
if ($item['type'] === 'weapon' && !empty($item['weapon_type'])) {
    $weaponTypeNames = [
        'sword'=>'剑','blade'=>'刀','stick'=>'棍','staff'=>'杖',
        'whip'=>'鞭','hammer'=>'锤','fork'=>'叉','spear'=>'枪',
        'axe'=>'斧','dagger'=>'匕首','mace'=>'锏','rake'=>'耙',
        'archery'=>'弓','throwing'=>'暗器',
    ];
    $equipStats[] = '类型：' . ($weaponTypeNames[$item['weapon_type']] ?? $item['weapon_type']);
}
if ($item['type'] === 'weapon' && intval($item['weapon_damage'] ?? 0) > 0) {
    $equipStats[] = '伤害：' . intval($item['weapon_damage']);
}
// 防具部位
if ($item['type'] === 'armor' && !empty($item['armor_type'])) {
    $slotNames = [
        'head'=>'头部','neck'=>'颈部','cloth'=>'身体','surcoat'=>'披风',
        'waist'=>'腰部','wrists'=>'手腕','hands'=>'手部','finger'=>'手指',
        'boots'=>'脚部','shield'=>'盾牌','armor'=>'护甲',
    ];
    $equipStats[] = '部位：' . ($slotNames[$item['armor_type']] ?? $item['armor_type']);
}
if ($item['type'] === 'armor' && intval($item['armor_value'] ?? 0) > 0) {
    $equipStats[] = '防御：' . intval($item['armor_value']);
}
$bonusMap = [
    'str_bonus' => '臂力', 'con_bonus' => '根骨', 'dex_bonus' => '身法',
    'int_bonus' => '悟性', 'spi_bonus' => '灵性',
    'dodge_bonus' => '闪避', 'parry_bonus' => '招架',
    'qi_defense' => '气防', 'shen_defense' => '神防',
];
foreach ($bonusMap as $field => $label) {
    $v = intval($item[$field] ?? 0);
    if ($v != 0) {
        $equipStats[] = $label . '：' . ($v > 0 ? '+' . $v : $v);
    }
}
// 材质
if (!empty($item['material']) && $item['material'] !== 'none') {
    $materialNames = [
        'cloth'=>'布','silk'=>'丝绸','leather'=>'皮革','iron'=>'铁',
        'steel'=>'钢','copper'=>'铜','gold'=>'金','silver'=>'银',
        'wood'=>'木','jade'=>'玉','bone'=>'骨','stone'=>'石',
        'paper'=>'纸','bamboo'=>'竹',
    ];
    $equipStats[] = '材质：' . ($materialNames[$item['material']] ?? $item['material']);
}
// 品质
$qualityNames = ['normal'=>'普通','fine'=>'精良','rare'=>'稀有','epic'=>'史诗','legendary'=>'传说'];
if (!empty($item['quality']) && $item['quality'] !== 'normal') {
    $equipStats[] = '品质：' . ($qualityNames[$item['quality']] ?? $item['quality']);
}
// 价值
if (intval($item['value'] ?? 0) > 0) {
    $equipStats[] = '价值：' . intval($item['value']) . ' 文钱';
}
?>
<?php if (!empty($equipStats) || intval($item['weight'] ?? 0) > 0): ?>
<div class="item-info">
<?php foreach ($equipStats as $stat): ?>
    <span class="stat-text"><?php echo $stat; ?></span><br>
<?php endforeach; ?>
<?php if (intval($item['weight'] ?? 0) > 0): ?>
    <span class="stat-text">重量：<?php echo intval($item['weight']); ?></span><br>
<?php endif; ?>
</div>
<?php endif; ?>
</div>

<a href="#" onclick="javascript:history.back(-1);">返回</a>
<hr />

<div class="actions">
<?php if ($isOnGround): ?>
<!-- 地上物品：显示捡起链接-->
<a href="<?php echo action_url('get', ['item_id' => $itemId]); ?>">捡起</a>
<?php else: ?>
<!-- 背包物品：显示装备丢弃链接 -->
<?php if ($item['type'] === 'armor' || $item['type'] === 'clothing'): ?>
<a href="<?php echo action_url('wear', ['item_id' => $itemId, 'inv_id' => $invId]); ?>">穿上</a>
<?php endif; ?>

<?php if ($item['type'] === 'weapon'): ?>
<a href="<?php echo action_url('wield', ['item_id' => $itemId, 'inv_id' => $invId]); ?>">拿起</a>
<?php endif; ?>
        
<!-- 玩具物品互动链接 -->
<?php if ($item['item_id'] === 'buwawa'): ?>
<a href="<?php echo action_url('niedoll', ['param' => $itemId . ':' . ($category ?: 'obj')]); ?>">捏</a>
<a href="javascript:void(0)" onclick="var t=prompt('你要把娃娃耍向谁？');if(t){var url='<?php echo action_url('shuadoll', ['param' => $itemId . ':' . ($category ?: 'obj')]); ?>';window.location.href=url+'&target='+encodeURIComponent(t);}else{alert('请输入目标名称！');}">耍</a>
<?php elseif ($item['item_id'] === 'mallet'): ?>
<a href="javascript:void(0)" onclick="var t=prompt('你要用锤子砸谁？');if(t){var url='<?php echo action_url('hammer', ['param' => $itemId . ':' . ($category ?: 'obj')]); ?>';window.location.href=url+'&target='+encodeURIComponent(t);}else{alert('请输入目标名称！');}">砸</a>
<?php elseif ($item['item_id'] === 'camera'): ?>
<a href="<?php echo action_url('shoot', ['param' => $itemId . ':' . ($category ?: 'obj'), 'category' => $category]); ?>">拍照</a>
<?php elseif ($item['item_id'] === 'poison_dust'): ?>
<a href="javascript:void(0)" onclick="var t=prompt('你要倒入哪个容器？');if(t){var url='<?php echo action_url('pour', ['param' => $item['name'] . ' in ']); ?>';window.location.href=url+encodeURIComponent(t);}else{alert('请输入容器名称！');}">倒入</a>
<?php endif; ?>

<?php if (preg_match('/^longzhu\d$|^longzhureal$/', $item['item_id'])): ?>
<a href="<?php echo action_url('touch', ['item_id' => $itemId]); ?>">摸</a>
<?php if ($item['item_id'] === 'longzhu1'): ?>
<a href="<?php echo action_url('combine', ['item_id' => $itemId]); ?>">合成</a>
<?php endif; ?>
<?php endif; ?>

<?php if (LiquidContainerHelper::isLiquidContainer($item)): ?>
<a href="action.php?action=drink&param=<?php echo urlencode($itemId); ?>">喝</a>
<a href="action.php?action=fill&param=<?php echo urlencode($itemId); ?>">装</a>
<a href="action.php?action=pour&param=<?php echo urlencode($itemId); ?>">倒掉</a>
<?php endif; ?>

<?php if ($isContainer && $invItem): ?>
<a href="javascript:void(0)" onclick="openPutModal()">放入</a>
<a href="javascript:void(0)" onclick="openGetModal()">拿出</a>
<?php endif; ?>

<?php if (isReadableItem($item)): ?>
<a href="action.php?action=read&param=<?php echo urlencode($itemId); ?>">阅读</a>
<?php endif; ?>

<a href="<?php echo action_url('drop', ['item_id' => $itemId, 'category' => $category, 'inv_id' => $invId]); ?>">丢弃</a>
<?php endif; ?>

<a href="inventory.php">返回背包</a>
<a href="room.php">返回房间</a>
</div>

<!-- 放入物品弹窗 -->
<div id="putModal" class="modal-overlay">
    <div class="modal" style="max-width: 360px;">
        <h3>【放入物品到<?php echo htmlspecialchars($item['name']); ?>】</h3>
        <div class="modal-item-list">
            <?php
            // 确保$charItems可用
            if (!isset($charItems) && $char) {
                $charItems = ItemModel::getCharacterItems($charId);
            }
            // 货币物品ID列表
            $moneyItemIds = ['gold', 'silver', 'coin'];
            ?>
            <?php if (empty($charItems)): ?>
                <p class="modal-empty">背包里没有可放入的物品</p>
            <?php else: ?>
                <?php 
                $hasPuttableItem = false;
                foreach ($charItems as $inv): 
                    // 排除容器本身
                    if ($inv['id'] == $invId) continue;
                    // 排除已经装备的物品
                    if (!empty($inv['equipped'])) continue;
                    // 排除货币物品
                    if (in_array($inv['item_id'], $moneyItemIds)) continue;
                    $hasPuttableItem = true;
                ?>
                    <div class="modal-item">
                        <span class="modal-item-name"><?php echo htmlspecialchars($inv['name']); ?></span>
                        <?php if (!empty($inv['quantity']) && $inv['quantity'] > 1): ?>
                            <span class="modal-item-count">x<?php echo $inv['quantity']; ?></span>
                        <?php endif; ?>
                        <a href="action.php?action=put&param=<?php echo urlencode($inv['name'] . ' in ' . $item['name']); ?>" class="modal-item-link">放入</a>
                    </div>
                <?php endforeach; ?>
                <?php if (!$hasPuttableItem): ?>
                    <p class="modal-empty">背包里没有可放入的物品</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="modal-buttons">
            <button class="modal-btn secondary" onclick="closePutModal()">关闭</button>
        </div>
    </div>
</div>

<!-- 拿出物品弹窗 -->
<div id="getModal" class="modal-overlay">
    <div class="modal" style="max-width: 360px;">
        <h3>【从<?php echo htmlspecialchars($item['name']); ?>拿出物品】</h3>
        <div class="modal-item-list">
            <?php
            // 确保$containerItems可用
            if (!isset($containerItems) && $isContainer && $invItem) {
                $containerType = 'character_inventory';
                $containerId = intval($invItem['id']);
                $containerItems = ContainerModel::getContainerItems($containerType, $containerId);
            }
            ?>
            <?php if (empty($containerItems)): ?>
                <p class="modal-empty">容器里空空如也</p>
            <?php else: ?>
                <?php foreach ($containerItems as $ci): ?>
                    <div class="modal-item">
                        <span class="modal-item-name"><?php echo htmlspecialchars($ci['name'] ?? $ci['item_id']); ?></span>
                        <?php if (!empty($ci['quantity']) && intval($ci['quantity']) > 1): ?>
                            <span class="modal-item-count">x<?php echo intval($ci['quantity']); ?></span>
                        <?php endif; ?>
                        <a href="action.php?action=get&param=<?php echo urlencode(($ci['name'] ?? $ci['item_id']) . ' from ' . $item['name']); ?>" class="modal-item-link">拿出</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="modal-buttons">
            <button class="modal-btn secondary" onclick="closeGetModal()">关闭</button>
        </div>
    </div>
</div>

<style>
/* 弹窗样式 */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.3s, visibility 0.3s;
}

.modal-overlay.active {
    opacity: 1;
    visibility: visible;
}

.modal {
    background: #1a1a2e;
    border: 2px solid #4a90d9;
    border-radius: 8px;
    padding: 15px;
    min-width: 280px;
    max-width: 360px;
    max-height: 70vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 4px 20px rgba(74, 144, 217, 0.3);
    transform: scale(0.9);
    transition: transform 0.3s;
}

.modal-overlay.active .modal {
    transform: scale(1);
}

.modal h3 {
    margin: 0 0 12px 0;
    color: #4a90d9;
    font-size: 1em;
    text-align: center;
    flex-shrink: 0;
}

.modal-item-list {
    flex: 1;
    overflow-y: auto;
    margin-bottom: 12px;
    padding-right: 5px;
}

.modal-item {
    display: flex;
    align-items: center;
    padding: 6px 8px;
    margin-bottom: 4px;
    background: #0f0f1a;
    border-radius: 4px;
    font-size: 0.9em;
}

.modal-item-name {
    flex: 1;
    color: #ccc;
}

.modal-item-count {
    color: #888;
    font-size: 0.85em;
    margin-right: 10px;
}

.modal-item-link {
    color: #4a90d9;
    text-decoration: none;
    font-size: 0.85em;
}

.modal-item-link:hover {
    color: #6ab0f3;
    text-decoration: underline;
}

.modal-empty {
    color: #888;
    text-align: center;
    padding: 20px 0;
    font-size: 0.9em;
}

.modal-buttons {
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-shrink: 0;
}

.modal-btn {
    padding: 6px 16px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.85em;
    transition: background 0.2s;
}

.modal-btn.primary {
    background: #4a90d9;
    color: #fff;
}

.modal-btn.primary:hover {
    background: #357abd;
}

.modal-btn.secondary {
    background: #333;
    color: #ccc;
}

.modal-btn.secondary:hover {
    background: #444;
}
</style>

<script>
var containerName = '<?php echo addslashes($item['name']); ?>';

// 放入弹窗
function openPutModal() {
    document.getElementById('putModal').classList.add('active');
}

function closePutModal() {
    document.getElementById('putModal').classList.remove('active');
}

function doPut() {
    var itemName = document.getElementById('putItemName').value.trim();
    if (!itemName) {
        alert('请输入物品名称！');
        return;
    }
    window.location.href = 'action.php?action=put&param=' + encodeURIComponent(itemName + ' in ' + containerName);
}

// 拿出弹窗
function openGetModal() {
    document.getElementById('getModal').classList.add('active');
}

function closeGetModal() {
    document.getElementById('getModal').classList.remove('active');
}

// 点击遮罩关闭弹窗
document.getElementById('putModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePutModal();
    }
});

document.getElementById('getModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeGetModal();
    }
});

// ESC键关闭弹窗
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePutModal();
        closeGetModal();
    }
    // 回车确认
    if (e.key === 'Enter') {
        if (document.getElementById('putModal').classList.contains('active')) {
            doPut();
        } else if (document.getElementById('getModal').classList.contains('active')) {
            doGet();
        }
    }
});
</script>

</body>
</html>

