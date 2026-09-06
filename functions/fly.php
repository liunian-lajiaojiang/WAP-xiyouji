<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 飞行页面 - 选择飞行目的地
 */

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once MODEL_PATH . 'Room.php';
require_once DAEMON_PATH . 'MessageDaemon.php';

// 要求登录
require_login();

$charId = get_char_id();
$char = CharacterModel::getFullInfo($charId);

if (!$char) {
    redirect('character_select.php');
}

// 定义可飞行的目的地列表（参考原始项目 fly.php 和 fly2.php）
// 包含约45个目的地，分两页显示
// 注意：此数组必须在POST处理之前定义，因为随机飞行逻辑需要引用它
$destinations = [
    // ===== 第1页 =====
    [
        ['name' => '傲来国', 'room_id' => 'changan/aolaiws'],
        ['name' => '长安城', 'room_id' => 'city/center'],
        ['name' => '方寸山', 'room_id' => 'lingtai/hill'],
    ],
    [
        ['name' => '高老庄', 'room_id' => 'gao/gate'],
        ['name' => '南天门', 'room_id' => 'dntg/sky/nantian'],
        ['name' => '大雪山', 'room_id' => 'xueshan/binggu'],
    ],
    [
        ['name' => '普陀山', 'room_id' => 'nanhai/gate'],
        ['name' => '灌江梅山', 'room_id' => 'meishan/guanjiang1'],
        ['name' => '五庄观', 'room_id' => 'qujing/wuzhuang/guangchang'],
    ],
    [
        ['name' => '通天河', 'room_id' => 'qujing/tongtian/hedong1'],
        ['name' => '蓬莱岛', 'room_id' => 'penglai/penglai'],
        ['name' => '飞上云端(云彩迷宫)', 'room_id' => 'cloud/cloud0'],
    ],
    [
        ['name' => '长安东', 'room_id' => 'eastway/wangnan4'],
        ['name' => '长安南', 'room_id' => 'changan/broadway2'],
        ['name' => '长安西', 'room_id' => 'city/beiyin2'],
    ],
    [
        ['name' => '火焰山', 'room_id' => 'qujing/firemount/huoyan'],
        ['name' => '金兜山', 'room_id' => 'qujing/jindou/jindou1'],
        ['name' => '白虎岭', 'room_id' => 'qujing/baihuling/entrance'],
    ],
    [
        ['name' => '乌鸡国', 'room_id' => 'qujing/wuji/square'],
        ['name' => '车迟国', 'room_id' => 'qujing/chechi/jieshi1'],
        ['name' => '平顶山', 'room_id' => 'qujing/pingding/ping1'],
    ],
    [
        ['name' => '积雷山', 'room_id' => 'qujing/jilei/jilei1'],
        ['name' => '碧波潭', 'room_id' => 'qujing/bibotan/shuijg'],
        ['name' => '小西天', 'room_id' => 'qujing/xiaoxitian/simen'],
    ],
    [
        ['name' => '天竺国', 'room_id' => 'qujing/tianzhu/jiedao1'],
        ['name' => '开封府', 'room_id' => 'kaifeng/chengmen'],
        ['name' => '宝象国', 'room_id' => 'qujing/baoxiang/bei1'],
    ],
    [
        ['name' => '祭赛国', 'room_id' => 'qujing/jisaiguo/eastgate'],
        ['name' => '盘丝洞', 'room_id' => 'qujing/pansi/ling1'],
        ['name' => '无底洞', 'room_id' => 'qujing/wudidong/wudidong1'],
    ],
    [
        ['name' => '朱紫国', 'room_id' => 'qujing/zhuzi/zhuzi1'],
        ['name' => '比丘国', 'room_id' => 'qujing/biqiu/jie1'],
        ['name' => '峨嵋山', 'room_id' => 'southern/emei/shanjiao'],
    ],
    [
        ['name' => '钦法国', 'room_id' => 'qujing/qinfa/jiedao1'],
        ['name' => '女儿国', 'room_id' => 'qujing/nuerguo/towna1'],
        ['name' => '云楼台', 'room_id' => 'dntg/yunlou/yunloutai'],
    ],
    [
        ['name' => '玉华县', 'room_id' => 'qujing/yuhua/xiaojie1'],
        ['name' => '金平府', 'room_id' => 'qujing/jinping/xiaojie1'],
        ['name' => '荆棘岭', 'room_id' => 'qujing/jingjiling/jingji1'],
    ],
    [
        ['name' => '清华庄', 'room_id' => 'qujing/biqiu/zhuang'],
        ['name' => '压龙山', 'room_id' => 'qujing/pingding/yalong1'],
        ['name' => '五台山', 'room_id' => 'southern/wutai/shanjiao'],
    ],

    // ===== 第2页 =====
    [
        ['name' => '隐雾山', 'room_id' => 'qujing/yinwu/huangye1'],
        ['name' => '竹节山', 'room_id' => 'qujing/zhujie/shanlu1'],
        ['name' => '毛颖山', 'room_id' => 'qujing/maoying/shanpo1'],
    ],
    [
        ['name' => '麒麟山', 'room_id' => 'qujing/qilin/yutai'],
        ['name' => '青龙山', 'room_id' => 'qujing/qinglong/shanjian'],
        ['name' => '豹头山', 'room_id' => 'qujing/baotou/shanlu1'],
    ],
    [
        ['name' => '凤仙郡', 'room_id' => 'qujing/fengxian/jiedao1'],
        ['name' => '灵山', 'room_id' => 'qujing/lingshan/dalu1'],
        ['name' => '毒敌山', 'room_id' => 'qujing/dudi/dudi1'],
    ],
    [
        ['name' => '黑风洞', 'room_id' => 'heifeng/heifengdong'],
        ['name' => '云栈洞', 'room_id' => 'gao/yunzhan'],
        ['name' => '流沙河', 'room_id' => 'liusha/liushahe'],
    ],
    [
        ['name' => '鹰愁涧', 'room_id' => 'qujing/yingchou/shanlu1'],
        ['name' => '双叉岭', 'room_id' => 'shuangcha/shuangchaling'],
        ['name' => '黄风洞', 'room_id' => 'huangfeng/huangfengdong'],
    ],
    [
        ['name' => '月宫', 'room_id' => 'moon/ontop2'],
        ['name' => '东海', 'room_id' => 'changan/beach'],
        ['name' => '罗汉塔', 'room_id' => 'nanhai/luohan/luohane'],
    ],
];

// 处理POST请求 - 选择目的地后跳回原房间
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $destination = $_POST['destination'] ?? '';
    
    if ($destination) {
        // 随机飞行：从有效目的地中随机选择
        if ($destination === 'random') {
            $validDestinations = [];
            foreach ($destinations as $pageDestinations) {
                foreach ($pageDestinations as $dest) {
                    if (isset($dest['room_id']) && !empty($dest['room_id'])) {
                        $validDestinations[] = $dest['room_id'];
                    }
                }
            }
            if (!empty($validDestinations)) {
                $destination = $validDestinations[array_rand($validDestinations)];
            } else {
                redirect('room.php?msg=' . urlencode('没有可用的飞行目的地。'));
            }
        }
        
        // 检查当前房间是否为室外
        $currentRoom = RoomModel::load($char['current_area'], $char['current_room']);
        if ($currentRoom && empty($currentRoom['outdoors'])) {
            redirect('room.php?msg=' . urlencode('周围没有一片云，没办法腾云驾雾。'));
        }
        
        // 检查是否在战斗中
        require_once DAEMON_PATH . 'CombatDaemon.php';
        if (CombatDaemon::isInCombat($charId)) {
            redirect('room.php?msg=' . urlencode('你正在战斗，飞不开。'));
        }
        
        // 检查是否忙碌
        if (is_player_busy($charId)) {
            redirect('room.php?msg=' . urlencode('你正忙着呢，没工夫腾云驾雾。'));
        }
        
        // 检查道行要求（初领妙道以上，≥1000）
        $daoxing = $char['daoxing'] ?? 0;
        if ($daoxing < 1000) {
            redirect('room.php?msg=' . urlencode('你现在还初领妙道都谈不上，哪里飞得起来。'));
        }
        
        // 检查法力修为（腾云驾雾等级，≥500）
        $maxMana = $char['max_mana'] ?? 0;
        if ($maxMana < 500) {
            redirect('room.php?msg=' . urlencode('看来以你的法力修为还不能腾云驾雾。'));
        }
        
        // 检查当前法力
        $mana = $char['mana'] ?? 0;
        if ($mana < 200) {
            redirect('room.php?msg=' . urlencode('你目前法力不够充盈。'));
        }
        
        // 检查神识状态（≥50%）
        $sen = $char['sen'] ?? 0;
        $maxSen = $char['max_sen'] ?? 1;
        if ($sen * 100 / $maxSen < 50) {
            redirect('room.php?msg=' . urlencode('你现在头脑不太清醒，当心掉下来摔死。'));
        }
        
        // 检查体力状态（≥50%）
        $kee = $char['kee'] ?? 0;
        $maxKee = $char['max_kee'] ?? 1;
        if ($kee * 100 / $maxKee < 50) {
            redirect('room.php?msg=' . urlencode('你想飞起来，可是体力似乎有点不支。'));
        }
        
        // 计算法力消耗
        require_once HELPER_PATH . 'SkillManager.php';
        $spellsSkill = SkillManager::querySkill($charId, 'spells');
        $manaCost = -(100 - $spellsSkill) / 4 - 40;
        if ($manaCost > 0) {
            $manaCost = 0;
        }
        $manaCost = intval($manaCost);
        
        // 扣除法力
        Database::execute(
            "UPDATE characters SET mana = mana + ? WHERE id = ?",
            [$manaCost, $charId]
        );
        
        // 检查大雪山、蓬莱岛的地图验证
        require_once MODEL_PATH . 'Item.php';
        $charItems = ItemModel::getCharacterItems($charId);
        $itemIds = array_column($charItems, 'item_id');
        
        if ($destination === 'xueshan/binggu' && !in_array('xueshan-map', $itemIds)) {
            redirect('room.php?msg=' . urlencode('你没有雪山地图，无法找到大雪山的位置。'));
        }
        
        if ($destination === 'penglai/penglai' && !in_array('ditu', $itemIds)) {
            redirect('room.php?msg=' . urlencode('你没有地图，无法找到蓬莱岛的位置。'));
        }
        
        // destination 是完整路径（如 changan/aolaiws）
        if (strpos($destination, '/') !== false) {
            list($targetArea, $targetRoomId) = explode('/', $destination, 2);
        } else {
            redirect('room.php?msg=' . urlencode('目的地格式错误'));
        }
        
        // 检查目标房间是否存在
        // 注意：rooms 表中的 room_id 字段存储的是完整路径（如 changan/aolaiws）
        // 所以需要拼接 targetArea 和 targetRoomId
        $fullRoomId = $targetArea . '/' . $targetRoomId;
        $targetRoomInfo = RoomModel::load($targetArea, $fullRoomId);
        if (!$targetRoomInfo) {
            redirect('room.php?msg=' . urlencode('目的地不存在'));
        }
        
        // 生成起飞消息（根据角色特征）
        $race = $char['race'] ?? '';
        $gender = $char['gender'] ?? '';
        $level = $char['level'] ?? 0;
        
        if ($race === '妖' || $race === '魔') {
            $takeoffMsg = HTML_HIYEL . "{$char['name']}口中念念有词，平地间一股黑风刮起，将{$char['name']}裹了起来，" . HTML_NOR . "\n" .
                         HTML_HIYEL . "再吹一声口哨，随之飘去不见了。。。" . HTML_NOR;
        } elseif ($race === '仙' || $race === '神') {
            $takeoffMsg = HTML_HICYN . "{$char['name']}袖袍一挥，一朵祥云从脚下升起，{$char['name']}踏云而上，" . HTML_NOR . "\n" .
                         HTML_HICYN . "只见祥光万道，瑞气千条，转瞬便消失在天际。。。" . HTML_NOR;
        } elseif ($race === '佛' || $race === '僧') {
            $takeoffMsg = HTML_HIYEL . "{$char['name']}双手合十，脚下生出一朵金色莲花，缓缓升起，" . HTML_NOR . "\n" .
                         HTML_HIYEL . "金光万丈，佛音缭绕，{$char['name']}已不见踪影。。。" . HTML_NOR;
        } elseif ($race === '鬼' || $race === '魂') {
            $takeoffMsg = HTML_HIMAG . "{$char['name']}身形一晃，化作一道青烟，伴随着幽幽鬼火，" . HTML_NOR . "\n" .
                         HTML_HIMAG . "阴风阵阵，转眼便消散在空气中。。。" . HTML_NOR;
        } elseif ($level >= 50) {
            $takeoffMsg = HTML_HIRED . "{$char['name']}大喝一声，周身真气爆发，化作一道长虹直冲云霄，" . HTML_NOR . "\n" .
                         HTML_HIRED . "声势浩大，震得周围树叶纷纷落下。。。" . HTML_NOR;
        } elseif ($gender === '女') {
            $takeoffMsg = HTML_HICYN . "{$char['name']}轻挥衣袖，一朵白云悄然出现，{$char['name']}轻盈地跃上云端，" . HTML_NOR . "\n" .
                         HTML_HICYN . "衣袂飘飘，宛如仙子般冉冉升起，消失在天际。。。" . HTML_NOR;
        } else {
            $takeoffMsg = HTML_HIYEL . "{$char['name']}手一指，召来一朵云彩，高高兴兴地坐了上去，" . HTML_NOR . "\n" .
                         HTML_HIYEL . "再吹一声口哨，随之往上冉冉地升起。。。" . HTML_NOR;
        }
        
        // 云彩迷宫特殊提示
        if ($destination === 'cloud/cloud0') {
            $takeoffMsg .= "\n" . HTML_HIYEL . "你踏上了云端，四面茫茫云海，每走一步都要消耗法力……小心别迷路了！" . HTML_NOR;
        }
        
        // 设置起飞消息到 session
        $_SESSION['fly_takeoff_msg'] = [
            'content' => $takeoffMsg,
            'target_area' => $targetArea,
            'target_room' => $fullRoomId,  // 使用完整路径（如 changan/aolaiws）
            'timestamp' => time()
        ];
        
        // 跳回原房间，显示起飞消息并广播
        $currentArea = preg_replace('/^d\//', '', $char['current_area']);
        redirect(room_url($currentArea, $char['current_room']) . '&do_fly=1');
    }
}

// 获取当前房间名称用于显示
$currentRoomName = '未知地点';
$currentRoomData = RoomModel::load($char['current_area'], $char['current_room']);
if ($currentRoomData) {
    $currentRoomName = $currentRoomData['name'];
}

// 分页处理
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$totalPages = 2;

// 限制页码范围
if ($page < 1) $page = 1;
if ($page > $totalPages) $page = $totalPages;

// 根据页码选择显示的目的地
if ($page == 1) {
    // 第1页 (fly参考.php)
    $currentPageDestinations = array_slice($destinations, 0, 15);
} else {
    // 第2页 (fly2参考.php)
    $currentPageDestinations = array_slice($destinations, 15, 6);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="keywords" content="西游记mud,西游记怀旧mud，西游记h5" />
    <meta name="description" content="西游记mud是源自Mud西游记2000的经典还原H5网页文字游戏。" />
    <link rel="shortcut icon" href="../assets/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
    <title>飞行_西游记mud</title>
    <style>
        .disabled {
            color: gray;
            pointer-events: none;
            text-decoration: none;
        }
    </style>
</head>
<body>
        <a href="javascript:location.reload();">飞行</a>&ensp;
        <a href="map.php">地图</a>&ensp;
        <a href="room.php">返回</a>
    <hr>
    当前你在：<a href="room.php"><?= h($currentRoomName) ?></a>
    <p>你想飞去哪里？</p>
    <p><a href="javascript:void(0);" onclick="document.getElementById('selectedDestination').value='random'; document.getElementById('flyForm').submit();">【随机飞行】</a></p>
    
    <form action="fly.php" method="POST" id="flyForm">
    <input type="hidden" name="destination" id="selectedDestination" value="">
    <div id="fly-to">
        <table border="0" cellpadding="2" cellspacing="0" style="text-align:left;">
        <?php foreach ($currentPageDestinations as $row): ?>
            <?php 
            // 不过滤，显示所有项（包括没有 room_id 的）
            ?>
            <tr>
                <?php foreach ($row as $dest): ?>
                <td style="padding: 2px 5px;">
                    <?php if (isset($dest['room_id']) && !empty($dest['room_id'])): ?>
                        <a href="javascript:void(0);" onclick="document.getElementById('selectedDestination').value='<?= h($dest['room_id']) ?>'; document.getElementById('flyForm').submit();">【<?= h($dest['name']) ?>】</a>
                    <?php else: ?>
                        <span class="gray">【<?= h($dest['name']) ?>】</span>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </table>
        <br>
        <!-- 分页导航 -->
        <?php if ($page == 1): ?>
        <a href="fly.php?page=1" class="disabled">首页</a> 
        <a href="fly.php?page=1" class="disabled">上一页</a> 
        <a href="fly.php?page=2">下一页</a> 
        <a href="fly.php?page=2">尾页</a> 
        第1/2页
        <?php else: ?>
        <a href="fly.php?page=1">首页</a> 
        <a href="fly.php?page=1">上一页</a> 
        <a href="fly.php?page=2" class="disabled">下一页</a> 
        <a href="fly.php?page=2" class="disabled">尾页</a> 
        第2/2页
        <?php endif; ?>
    </div>
    </form>
    <hr>
    <a href="room.php">返回游戏</a>
</body>
</html>

