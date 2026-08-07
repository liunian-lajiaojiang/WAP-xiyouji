<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/../config/game.php';

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once MODEL_PATH . 'Item.php';
require_once MODEL_PATH . 'Corpse.php';
require_once HELPER_PATH . 'MoneyHelper.php';
require_once __DIR__ . '/../helpers/WeightHelper.php';
require_once __DIR__ . '/../commands/food_water.php'; // 加载 isDrinkLikeItem()
require_once __DIR__ . '/../helpers/LiquidContainerHelper.php'; // 液体容器系统
require_once __DIR__ . '/../commands/read.php'; // 阅读系统 isReadableItem()
require_once __DIR__ . '/../commands/study.php'; // 学习系统 isStudyableItem()

// 要求登录
require_login();

$charId = get_char_id();
$char = CharacterModel::getFullInfo($charId);
$items = ItemModel::getCharacterItems($charId);
$money = MoneyHelper::getMoneyInventory($charId);

// 检查是否是给予模式
$giveTo = intval($_GET['give_to'] ?? 0);
$giveToName = $_GET['give_to_name'] ?? '';
$giveToType = $_GET['give_to_type'] ?? 'player'; // 'player' or 'npc'
$isGiveMode = ($giveTo > 0 && !empty($giveToName));




// Type映射为中文
$typeMap = [
    'weapon' => '武器',
    'armor' => '防具',
    'food' => '食物',
    'drink' => '饮品',
    'drug' => '药品',
    'fabao' => '法宝',
    'book' => '书籍',
    'misc' => '杂物',
    'flower' => '花卉',
    'magic' => '魔法物品',
    'container' => '容器',
    'currency' => '货币',
    'treasure' => '宝物',
    'material' => '材料',
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
    <title>背包_WAP西游记2012</title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
</head>
<body>
<div class="inventory-content">
【 <?php echo h($char['name']); ?> 的背包】
<br>
<?php
$currentWeight = WeightHelper::getCurrentCarryWeight($charId);
$maxWeight = WeightHelper::getMaxCarryWeight($char);
$weightPercent = $maxWeight > 0 ? round($currentWeight / $maxWeight * 100) : 0;
?>
携带重量：<?php echo $currentWeight; ?>/<?php echo $maxWeight; ?> 克<?php if ($weightPercent >= 90) echo ' <span style="color:red;">(负重过高)</span>'; ?>
<br>
<?php
// 显示背着的尸体
$carriedCorpses = Corpse::getCarriedCorpses($charId);
if (!empty($carriedCorpses)):
?>
你背着：
<?php
$carriedCorpseList = [];
foreach ($carriedCorpses as $corpse) {
    $carriedCorpseList[] = '<a href="corpse.php?id=' . intval($corpse['id']) . '">' . h(Corpse::getCorpseDisplayName($corpse)) . '</a>';
}
echo implode(',', $carriedCorpseList);
?>
<br>
<?php endif; ?>
<?php 
// 在给予模式下，先将characters表中的货币迁移到character_inventory，确保货币物品出现在列表中
if ($isGiveMode) {
    MoneyHelper::migrateTableMoneyToInventory($charId);
    $items = ItemModel::getCharacterItems($charId);
    $money = MoneyHelper::getMoneyInventory($charId);
}

// 显示货币信息
$hasMoney = false;
$moneyParts = [];
if ($money['gold'] > 0) {
    $moneyParts[] = "<span style='color:#ffd700;'>{$money['gold']} 两黄金</span>";
    $hasMoney = true;
}
if ($money['silver'] > 0) {
    $moneyParts[] = "<span style='color:#c0c0c0;'>{$money['silver']} 两白银</span>";
    $hasMoney = true;
}
if ($money['coin'] > 0) {
    $moneyParts[] = "<span style='color:#cd7f32;'>{$money['coin']} 铜钱</span>";
    $hasMoney = true;
}

if ($hasMoney) {
    echo "<div style='margin:10px 0; padding:5px; background:#333; border-radius:5px;'>";
    echo "<span style='color:#666;'>【货币】</span> ";
    echo implode("，", $moneyParts);
    echo "</div>";
}
?>
<?php if ($isGiveMode): ?>
<br>
<span style="color: #ff6600;">【给予模式】正在选择要给予 <?php echo h($giveToName); ?> 的物品</span>
<br>
<?php endif; ?>
<br>

<?php if ($isGiveMode && $hasMoney): ?>
<div style='margin-top:10px; color:#666;'>【货币】</div>
<?php
// 在给予模式下显示货币的给予选项
$moneyItems = array_filter($items, function($item) {
    return in_array($item['item_id'], ['gold', 'silver', 'coin']);
});
foreach ($moneyItems as $mItem):
?>
<div class="item-row">
<span class="item-name">
<?php echo h($mItem['name']); ?>
<?php if ($mItem['quantity'] > 1): ?> x<?php echo $mItem['quantity']; ?><?php endif; ?>
</span>
<span class="item-actions">
<?php if ($giveToType === 'npc'): ?>
<?php if ($mItem['quantity'] > 1): ?>
[<input type="text" size="2" maxlength="5" placeholder="1" onkeyup="var v=this.value;if(v&&!isNaN(v)&&v>0&&v<=<?php echo $mItem['quantity']; ?>){this.style.color='black';}else{this.style.color='red';}" id="give_qty_<?php echo $mItem['item_id']; ?>">
<a href="javascript:void(0)" onclick="var qty=document.getElementById('give_qty_<?php echo $mItem['item_id']; ?>').value;if(qty&&!isNaN(qty)&&qty>0&&qty<=<?php echo $mItem['quantity']; ?>){window.location.href='action.php?action=give&item_id=<?php echo $mItem['item_id']; ?>&npc_id=<?php echo $giveTo; ?>&item_name=<?php echo urlencode($mItem['name']); ?>&quantity='+qty;}else{alert('请输入有效数量');}">给予</a>]
<?php else: ?>
[<a href="action.php?action=give&item_id=<?php echo $mItem['item_id']; ?>&npc_id=<?php echo $giveTo; ?>&item_name=<?php echo urlencode($mItem['name']); ?>&quantity=1">给予</a>]
<?php endif; ?>
<?php else: ?>
<?php if ($mItem['quantity'] > 1): ?>
[<input type="text" size="2" maxlength="5" placeholder="1" onkeyup="var v=this.value;if(v&&!isNaN(v)&&v>0&&v<=<?php echo $mItem['quantity']; ?>){this.style.color='black';}else{this.style.color='red';}" id="give_qty_<?php echo $mItem['item_id']; ?>">
<a href="javascript:void(0)" onclick="var qty=document.getElementById('give_qty_<?php echo $mItem['item_id']; ?>').value;if(qty&&!isNaN(qty)&&qty>0&&qty<=<?php echo $mItem['quantity']; ?>){window.location.href='action.php?action=give&item_id=<?php echo $mItem['item_id']; ?>&target=<?php echo $giveTo; ?>&target_name=<?php echo urlencode($giveToName); ?>&quantity='+qty;}else{alert('请输入有效数量');}">给予</a>]
<?php else: ?>
[<a href="action.php?action=give&item_id=<?php echo $mItem['item_id']; ?>&target=<?php echo $giveTo; ?>&target_name=<?php echo urlencode($giveToName); ?>&quantity=1">给予</a>]
<?php endif; ?>
<?php endif; ?>
</span>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php 
// 过滤掉货币物品，不显示在普通物品列表中
$nonMoneyItems = array_filter($items, function($item) {
    return !in_array($item['item_id'], ['gold', 'silver', 'coin', 'copper']);
});

if (count($nonMoneyItems) === 0 && !$hasMoney): ?>
<p>你身上没有任何物品。</p>
<?php else: ?>
<?php 
// 按类型分组
$groupedItems = [];
foreach ($nonMoneyItems as $item) {
    $type = $item['item_type'] ?? 'misc';
    if (!isset($groupedItems[$type])) {
        $groupedItems[$type] = [];
    }
    $groupedItems[$type][] = $item;
}

// 按类型顺序显示
$typeOrder = ['weapon', 'armor', 'fabao', 'drug', 'food', 'drink', 'book', 'container', 'magic', 'flower', 'treasure', 'material', 'misc'];
foreach ($typeOrder as $type) {
    if (!isset($groupedItems[$type])) continue;
    
    $chineseType = $typeMap[$type] ?? $type;
    echo "<div style='margin-top:10px; color:#666;'>【{$chineseType}】</div>\n";
    
    foreach ($groupedItems[$type] as $item): 
?>

<div class="item-row">
<span class="item-name">
<a href="item.php?id=<?php echo $item['item_id']; ?>&category=<?php echo urlencode($item['category'] ?? ''); ?>&inv_id=<?php echo $item['id']; ?>"><?php echo h($item['name']); ?></a>
<?php if ($item['quantity'] > 1): ?> x<?php echo $item['quantity']; ?><?php endif; ?>
<?php if ($item['equipped']): ?> <span style="color:green;">[已装备]</span><?php endif; ?>
<?php
// 装备属性简要摘要
if (($item['item_type'] ?? '') === 'weapon' && intval($item['weapon_damage'] ?? 0) > 0) {
    echo ' <span style="color:#aaa;">伤' . intval($item['weapon_damage']) . '</span>';
}
if (($item['item_type'] ?? '') === 'armor' && intval($item['armor_value'] ?? 0) > 0) {
    echo ' <span style="color:#aaa;">防' . intval($item['armor_value']) . '</span>';
}
?>
<?php if (LiquidContainerHelper::shouldShowLiquidStatus($item)): ?>
<?php
$lm = (int)($item['max_liquid'] ?? 0);
$lrRaw = $item['liquid_remaining'] ?? null;
// 如果 liquid_remaining 未设置（null 或空字符串），则使用 max_liquid 作为默认值
// 如果 liquid_remaining 是 0，则表示喝完了，显示为 0
$lr = ($lrRaw === null || $lrRaw === '') ? $lm : (int)$lrRaw;
$ln = $item['liquid_name'] ?? '';
if (!$ln && !empty($item['item_id'])) {
    require_once __DIR__ . '/../helpers/LiquidContainerHelper.php';
    $def = LiquidContainerHelper::getDefaultLiquid($item['item_id']);
    $ln = $def['name'] ?? '液体';
}
?>
 <span style="color:#888;">(<?php echo $ln; ?> <?php echo $lr; ?>/<?php echo $lm; ?>)</span>
<?php endif; ?>
<?php if (!empty($item['is_container']) && intval($item['is_container']) > 0): ?>
<?php
require_once MODEL_PATH . 'ContainerModel.php';
$containerType = 'character_inventory';
$containerId = intval($item['id']);
$containerItemCount = ContainerModel::getItemCount($containerType, $containerId);
$maxItems = intval($item['max_items'] ?? 10);
?>
 <span style="color:#888;">(<?php echo $containerItemCount; ?>/<?php echo $maxItems; ?> 件物品)</span>
<?php endif; ?>
</span>

<span class="item-actions">
<?php if ($item['item_type'] === 'armor' || $item['item_type'] === 'clothing'): ?>
<?php if (!$item['equipped']): ?>
[<a href="<?php echo action_url('wear', ['item_id' => $item['item_id'], 'category' => $item['category'] ?? '', 'inv_id' => $item['id']]); ?>">穿</a>]
<?php else: ?>
[<a href="<?php echo action_url('remove', ['item_id' => $item['item_id'], 'category' => $item['category'] ?? '', 'inv_id' => $item['id']]); ?>">脱</a>]
[<a href="action.php?action=repair&param=<?php echo urlencode($item['name']); ?>">修</a>]
<?php endif; ?>
<?php endif; ?>

<?php if ($item['item_type'] === 'weapon'): ?>
<?php if (!$item['equipped']): ?>
[<a href="<?php echo action_url('wield', ['item_id' => $item['item_id'], 'category' => $item['category'] ?? '', 'inv_id' => $item['id']]); ?>">拿</a>]
<?php else: ?>
[<a href="<?php echo action_url('unwield', ['item_id' => $item['item_id'], 'category' => $item['category'] ?? '', 'inv_id' => $item['id']]); ?>">放</a>]
[<a href="action.php?action=repair&param=<?php echo urlencode($item['name']); ?>">修</a>]
<?php endif; ?>
<?php endif; ?>

<?php if (LiquidContainerHelper::isLiquidContainer($item)): ?>
[<a href="action.php?action=drink&param=<?php echo urlencode($item['item_id']); ?>&category=<?php echo urlencode($item['category'] ?? ''); ?>">喝</a>]
[<a href="action.php?action=fill&param=<?php echo urlencode($item['item_id']); ?>">装</a>]
[<a href="action.php?action=pour&param=<?php echo urlencode($item['item_id']); ?>">倒</a>]
<?php elseif ($item['item_type'] === 'food'): ?>
<?php if (isDrinkLikeItem($item)): ?>
[<a href="action.php?action=drink&param=<?php echo urlencode($item['item_id']); ?>&category=<?php echo urlencode($item['category'] ?? ''); ?>">喝</a>]
<?php else: ?>
[<a href="action.php?action=eat&param=<?php echo urlencode($item['item_id']); ?>&category=<?php echo urlencode($item['category'] ?? ''); ?>">吃</a>]
<?php endif; ?>
<?php elseif ($item['item_type'] === 'drink' || $item['item_type'] === 'water'): ?>
[<a href="action.php?action=drink&param=<?php echo urlencode($item['item_id']); ?>&category=<?php echo urlencode($item['category'] ?? ''); ?>">喝</a>]
<?php endif; ?>

<?php if ($item['item_type'] === 'fabao'): ?>
<?php if (!$item['equipped']): ?>
[<a href="<?php echo action_url('wield', ['item_id' => $item['item_id'], 'category' => $item['category'] ?? '', 'inv_id' => $item['id']]); ?>">装</a>]
<?php else: ?>
[<a href="<?php echo action_url('remove', ['item_id' => $item['item_id'], 'category' => $item['category'] ?? '', 'inv_id' => $item['id']]); ?>">卸</a>]
<?php endif; ?>
<?php endif; ?>

<?php if ($item['item_type'] === 'drug'): ?>
[<a href="action.php?action=use&param=<?php echo urlencode($item['item_id']); ?>">用</a>]
<?php endif; ?>

<?php if (isStudyableItem($item)): ?>
[<a href="action.php?action=study&param=<?php echo urlencode($item['item_id']); ?>">学</a>]
<?php elseif (isReadableItem($item)): ?>
[<a href="action.php?action=read&param=<?php echo urlencode($item['item_id']); ?>">阅</a>]
<?php endif; ?>

<?php if ($item['item_id'] === 'nowords'): ?>
[<a href="action.php?action=tear&param=<?php echo urlencode($item['item_id']); ?>" onclick="return confirm('确定要撕开这本古书吗？撕开后无法复原。');">撕</a>]
<?php endif; ?>

<?php if ($item['item_id'] === 'sengxie'): ?>
[<a href="action.php?action=tear&param=<?php echo urlencode($item['item_id']); ?>" onclick="return confirm('确定要撕破这双僧鞋吗？撕开后无法复原。');">撕</a>]
<?php endif; ?>

<?php if (preg_match('/^longzhu\d$|^longzhureal$/', $item['item_id'])): ?>
[<a href="<?php echo action_url('touch', ['item_id' => $item['item_id']]); ?>">摸</a>]
<?php if ($item['item_id'] === 'longzhu1'): ?>
[<a href="<?php echo action_url('combine', ['item_id' => $item['item_id']]); ?>">合成</a>]
<?php endif; ?>
<?php endif; ?>

<?php
$itemQty = intval($item['quantity']);
$isStackable = !isset($item['stackable']) || $item['stackable'] > 0;
$dropBaseUrl = action_url('drop', ['item_id' => $item['item_id'], 'quantity' => '', 'category' => $item['category'] ?? '', 'inv_id' => $item['id']]);
if ($itemQty > 1 && $isStackable): ?>
[<input type="text" size="2" maxlength="3" placeholder="数量" style="width:36px;" id="drop_qty_<?php echo $item['id']; ?>" onkeyup="var v=this.value;if(v&&!isNaN(v)&&v>0&&v<=<?php echo $itemQty; ?>){this.style.color='black';}else{this.style.color='red';}">
<a href="javascript:void(0)" onclick="var el=document.getElementById('drop_qty_<?php echo $item['id']; ?>');var qty=el.value;if(qty&&!isNaN(qty)&&qty>0&&qty<=<?php echo $itemQty; ?>){window.location.href='<?php echo $dropBaseUrl; ?>'+qty;}else if(!qty){if(confirm('确定要丢弃全部 <?php echo $itemQty; ?> 个吗？')){window.location.href='<?php echo $dropBaseUrl . $itemQty; ?>';}}else{alert('请输入有效数量(1-<?php echo $itemQty; ?>)');}">丢</a>
<a href="<?php echo $dropBaseUrl . $itemQty; ?>" onclick="return confirm('确定要丢弃全部 <?php echo $itemQty; ?> 个吗？');" style="font-size:10px;">全丢</a>]
<?php elseif ($isStackable): ?>
[<a href="<?php echo action_url('drop', ['item_id' => $item['item_id'], 'quantity' => 1, 'category' => $item['category'] ?? '', 'inv_id' => $item['id']]); ?>" onclick="return confirm('确定要丢弃吗？');">丢</a>]
<?php else: ?>
[<a href="<?php echo action_url('drop', ['item_id' => $item['item_id'], 'quantity' => 1, 'category' => $item['category'] ?? '', 'inv_id' => $item['id']]); ?>" onclick="return confirm('确定要丢弃吗？');">丢</a>]
<?php endif; ?>

<?php if ($isGiveMode): ?>
<?php if ($giveToType === 'npc'): ?>
<?php if ($item['quantity'] > 1 && (!isset($item['stackable']) || $item['stackable'] > 0)): ?>
[<input type="text" size="2" maxlength="3" placeholder="1" onkeyup="var v=this.value;if(v&&!isNaN(v)&&v>0&&v<=<?php echo $item['quantity']; ?>){this.style.color='black';}else{this.style.color='red';}" id="give_qty_<?php echo $item['item_id']; ?>">
<a href="javascript:void(0)" onclick="var qty=document.getElementById('give_qty_<?php echo $item['item_id']; ?>').value;if(qty&&!isNaN(qty)&&qty>0&&qty<=<?php echo $item['quantity']; ?>){window.location.href='action.php?action=give&item_id=<?php echo $item['item_id']; ?>&npc_id=<?php echo $giveTo; ?>&item_name=<?php echo urlencode($item['name']); ?>&category=<?php echo urlencode($item['category'] ?? ''); ?>&quantity='+qty;}else{alert('请输入有效数量');}">给予</a>]
<?php else: ?>
[<a href="action.php?action=give&item_id=<?php echo $item['item_id']; ?>&npc_id=<?php echo $giveTo; ?>&item_name=<?php echo urlencode($item['name']); ?>&category=<?php echo urlencode($item['category'] ?? ''); ?>&quantity=1">给予</a>]
<?php endif; ?>
<?php else: ?>
<?php if ($item['quantity'] > 1 && (!isset($item['stackable']) || $item['stackable'] > 0)): ?>
[<input type="text" size="2" maxlength="3" placeholder="1" onkeyup="var v=this.value;if(v&&!isNaN(v)&&v>0&&v<=<?php echo $item['quantity']; ?>){this.style.color='black';}else{this.style.color='red';}" id="give_qty_<?php echo $item['item_id']; ?>">
<a href="javascript:void(0)" onclick="var qty=document.getElementById('give_qty_<?php echo $item['item_id']; ?>').value;if(qty&&!isNaN(qty)&&qty>0&&qty<=<?php echo $item['quantity']; ?>){window.location.href='action.php?action=give&item_id=<?php echo $item['item_id']; ?>&target=<?php echo $giveTo; ?>&target_name=<?php echo urlencode($giveToName); ?>&category=<?php echo urlencode($item['category'] ?? ''); ?>&quantity='+qty;}else{alert('请输入有效数量');}">给予</a>]
<?php else: ?>
[<a href="action.php?action=give&item_id=<?php echo $item['item_id']; ?>&target=<?php echo $giveTo; ?>&target_name=<?php echo urlencode($giveToName); ?>&category=<?php echo urlencode($item['category'] ?? ''); ?>&quantity=1">给予</a>]
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</span>
</div>
<?php 
    endforeach;
}
endif; 
?>
</div>
<br>
<a href="#" onclick="javascript:history.back(-1);">返回</a>
<hr>
<a href="room.php">返回游戏</a>
</body>
</html>
