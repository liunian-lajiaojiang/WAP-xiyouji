<?php
/**
 * 白虎岭迷宫模板房间
 * 
 * 动态显示迷宫房间内容，根据Session中的迷宫数据和当前位置参数
 */

session_save_path(__DIR__ . '/../../../../sessions');
session_start();

require_once __DIR__ . '/../../../../config/game.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/db.php';
require_once __DIR__ . '/../../../../models/Character.php';
require_once __DIR__ . '/../../../../models/Room.php';
require_once __DIR__ . '/../../../../daemons/MessageDaemon.php';

// 检查登录
if (!isset($_SESSION['char_id'])) {
    header('Location: ../index.php');
    exit;
}

$charId = $_SESSION['char_id'];
$char = CharacterModel::find($charId);

if (!$char) {
    die('角色不存在');
}

// 获取当前位置参数
$pos = $_GET['pos'] ?? $_SESSION['baihuling_current_pos_' . $charId] ?? '0,0,0';

// 获取迷宫数据
$mazeKey = 'baihuling_maze_' . $charId;
if (!isset($_SESSION[$mazeKey])) {
    // 没有迷宫数据，传送回入口
    CharacterModel::updatePosition($charId, 'qujing', 'baihuling/entrance');
    header('Location: room.php?area=qujing&room=baihuling/entrance');
    exit;
}

$mazeData = $_SESSION[$mazeKey];
$mazeLayout = $mazeData['layout'];
$mazeType = $mazeData['type'];
$dimensions = $mazeData['dimensions'];
$exitRoom = $mazeData['exit_room'] ?? '';

list($i, $j, $k) = explode(',', $pos);
$i = intval($i);
$j = intval($j);
$k = intval($k);

// 保存当前位置到Session
$_SESSION['baihuling_current_pos_' . $charId] = $pos;

// 查询当前房间的出口
$max_i = $dimensions[0];
$max_j = $dimensions[1];
$max_k = $dimensions[2];

// 调用BaihulingHandler的queryExit方法
require_once __DIR__ . '/../../../../daemons/BaihulingHandler.php';
$exits = BaihulingHandler::queryExitStatic($i, $j, $k, $mazeLayout, $max_i, $max_j, $max_k);

// 生成房间描述
$roomName = ($mazeType === 'main') ? '洞穴' : '小洞穴';
$roomDesc = "这是一个阴暗潮湿的{$roomName}，四周石壁上长满了青苔。\n";

// 检查是否是出口房间
$isExit = ($pos === $exitRoom);
if ($isExit) {
    if ($mazeType === 'main') {
        $roomDesc .= HTML_HIYEL . "\n你发现前方有一个洞口，似乎是出口！" . HTML_NOR . "\n";
        $exits['out'] = 'baihuling/entrance';
    } else {
        $roomDesc .= HTML_HIYEL . "\n你发现前面有一条石缝，钻过去应该能离开！" . HTML_NOR . "\n";
        $exits['out'] = 'baihuling/small_exit';
    }
}

// 随机生成舍利子（33%概率）
$hasShelizi = false;
$sheliziKey = 'baihuling_shelizi_' . $charId . '_' . $pos;
if (!isset($_SESSION[$sheliziKey])) {
    if (mt_rand(1, 100) <= 33) {
        $count = mt_rand(1, 5);
        $_SESSION[$sheliziKey] = $count;
        $hasShelizi = true;
        $roomDesc .= HTML_HIGRN . "\n你在角落里发现了{$count}颗舍利子！" . HTML_NOR . "\n";
    }
}

// 构建HTML输出
$html = '<div class="room-description">';
$html .= '<h3>' . htmlspecialchars($roomName) . '</h3>';
$html .= '<p>' . nl2br(htmlspecialchars($roomDesc)) . '</p>';
$html .= '</div>';

// 显示出口
if (!empty($exits)) {
    $html .= '<div class="room-exits">';
    $html .= '<p><strong>你可以：</strong></p>';
    $html .= '<ul class="action-list">';
    
    $directionNames = [
        'north' => '北',
        'south' => '南',
        'east' => '东',
        'west' => '西',
        'up' => '上',
        'down' => '下',
        'out' => '出去',
    ];
    
    foreach ($exits as $dir => $target) {
        $dirName = $directionNames[$dir] ?? $dir;
        if (strpos($target, ',') !== false) {
            // 迷宫内部移动
            $html .= '<li><a href="#" onclick="moveInMaze(\'' . $target . '\')" class="action-link">' . $dirName . '</a></li>';
        } else {
            // 离开迷宫
            $html .= '<li><a href="room.php?area=qujing&room=' . urlencode($target) . '" class="action-link">' . $dirName . '</a></li>';
        }
    }
    
    $html .= '</ul>';
    $html .= '</div>';
}

// 如果有舍利子，显示拾取按钮
if ($hasShelizi) {
    $html .= '<div class="room-items">';
    $html .= '<p><strong>物品：</strong></p>';
    $html .= '<button onclick="getShelizi()" class="btn btn-sm btn-success">拾取舍利子</button>';
    $html .= '</div>';
}

// 添加JavaScript函数
$html .= '<script>
function moveInMaze(targetPos) {
    var currentUrl = window.location.href;
    var newUrl = currentUrl.replace(/pos=[^&]*/, \'pos=\' + targetPos);
    window.location.href = newUrl;
}

function getShelizi() {
    fetch(\'action.php?action=get&item_id=sheli_zi&area=qujing&room=baihuling/maze\', {
        method: \'GET\',
        headers: {\'X-Requested-With\': \'XMLHttpRequest\'}
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || \'拾取失败\');
        }
    })
    .catch(error => {
        console.error(\'Error:\', error);
        alert(\'网络错误\');
    });
}
</script>';

// 渲染页面
include __DIR__ . '/../../../../templates/room_layout.php';
