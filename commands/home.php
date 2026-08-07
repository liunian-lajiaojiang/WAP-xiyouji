<?php
/**
 * 房产命令 (home)
 * 
 * 功能：管理玩家房产，包括房间/床铺定制、物品存取、访客管理、婴儿管理
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../helpers/HomeHelper.php';

function cmd_home($charId, $param = '') {
    $me = CharacterModel::find($charId);
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    $arg = trim(urldecode($param));

    if (empty($arg) || $arg === 'enter') {
        return homeEnter($charId, $me);
    }

    $parts = preg_split('/\s+/', $arg, 2);
    $subCmd = strtolower($parts[0]);
    $subArg = $parts[1] ?? '';

    switch ($subCmd) {
        case 'info':     return homeInfo($charId);
        case 'name':     return homeSetName($charId, $subArg);
        case 'desc':     return homeSetDesc($charId, $subArg);
        case 'bedname':  return homeSetBedName($charId, $subArg);
        case 'beddesc':  return homeSetBedDesc($charId, $subArg);
        case 'store':    return homeStore($charId, $subArg);
        case 'retrieve': return homeRetrieve($charId, $subArg);
        case 'items':    return homeItems($charId);
        case 'invite':   return homeInvite($charId, $subArg);
        case 'kick':     return homeKick($charId, $subArg);
        case 'baby':     return homeBaby($charId, $subArg);
        case 'feedbaby': return homeFeedBaby($charId, $subArg);
        case 'leave':    return homeLeave($charId, $me);
        default:         return homeHelp();
    }
}

/** 进入房产 */
function homeEnter($charId, $me) {
    $home = HomeHelper::getHome($charId);
    if (!$home) {
        return ['success' => false, 'message' => '你还没有房产，结婚后才能购置房产。'];
    }
    
    // 保存进入家之前的位置到 session
    $lastArea = $me['current_area'] ?? '';
    $lastRoom = $me['current_room'] ?? '';
    if (!empty($lastArea) && !empty($lastRoom) && $lastArea !== 'home') {
        $_SESSION['last_home_position'] = [
            'area' => $lastArea,
            'room' => $lastRoom
        ];
    }
    
    CharacterModel::updatePosition($charId, 'home', 'home/' . $home['id']);
    return [
        'success' => true,
        'message' => '你回到了家中。',
        'redirect' => 'home.php',
    ];
}

/** 查看房产信息 */
function homeInfo($charId) {
    $home = HomeHelper::getHome($charId);
    if (!$home) {
        return ['success' => false, 'message' => '你还没有房产。'];
    }
    $output = [];
    $output[] = HTML_HIYEL . '【房产信息】' . HTML_NOR;
    $output[] = '房间名：' . $home['room_name'];
    $output[] = '描述：' . $home['room_desc'];
    $output[] = '床铺名：' . $home['bed_name'];
    $output[] = '床铺描述：' . $home['bed_desc'];
    $output[] = '最大物品数：' . ($home['max_items'] ?? 20);
    $babies = HomeHelper::getBabies($home['id']);
    $output[] = '孩子：' . count($babies) . '/3';
    return ['success' => true, 'message' => implode("\n", $output)];
}

/** 修改房间名 */
function homeSetName($charId, $name) {
    $name = trim($name);
    if (empty($name)) {
        return ['success' => false, 'message' => '请输入新的房间名。用法：home name <新名称>'];
    }
    if (mb_strlen($name) > 50) {
        return ['success' => false, 'message' => '房间名最多50个字符。'];
    }
    $home = HomeHelper::getHome($charId);
    if (!$home) {
        return ['success' => false, 'message' => '你还没有房产。'];
    }
    $result = HomeHelper::updateRoom($home['id'], $name);
    if (!$result) {
        return ['success' => false, 'message' => '修改房间名失败，请稍后重试。'];
    }
    return ['success' => true, 'message' => '房间名已修改为：' . $name];
}

/** 修改房间描述 */
function homeSetDesc($charId, $desc) {
    $desc = trim($desc);
    if (empty($desc)) {
        return ['success' => false, 'message' => '请输入新的房间描述。用法：home desc <新描述>'];
    }
    $home = HomeHelper::getHome($charId);
    if (!$home) {
        return ['success' => false, 'message' => '你还没有房产。'];
    }
    $result = HomeHelper::updateRoom($home['id'], null, $desc);
    if (!$result) {
        return ['success' => false, 'message' => '修改房间描述失败，请稍后重试。'];
    }
    return ['success' => true, 'message' => '房间描述已修改。'];
}

/** 修改床铺名 */
function homeSetBedName($charId, $name) {
    $name = trim($name);
    if (empty($name)) {
        return ['success' => false, 'message' => '请输入新的床铺名。用法：home bedname <新名称>'];
    }
    if (mb_strlen($name) > 50) {
        return ['success' => false, 'message' => '床铺名最多50个字符。'];
    }
    $home = HomeHelper::getHome($charId);
    if (!$home) {
        return ['success' => false, 'message' => '你还没有房产。'];
    }
    $result = HomeHelper::updateBed($home['id'], $name);
    if (!$result) {
        return ['success' => false, 'message' => '修改床铺名失败，请稍后重试。'];
    }
    return ['success' => true, 'message' => '床铺名已修改为：' . $name];
}

/** 修改床铺描述 */
function homeSetBedDesc($charId, $desc) {
    $desc = trim($desc);
    if (empty($desc)) {
        return ['success' => false, 'message' => '请输入新的床铺描述。用法：home beddesc <新描述>'];
    }
    $home = HomeHelper::getHome($charId);
    if (!$home) {
        return ['success' => false, 'message' => '你还没有房产。'];
    }
    $result = HomeHelper::updateBed($home['id'], null, $desc);
    if (!$result) {
        return ['success' => false, 'message' => '修改床铺描述失败，请稍后重试。'];
    }
    return ['success' => true, 'message' => '床铺描述已修改。'];
}

/** 存入物品到房产 */
function homeStore($charId, $itemIdStr) {
    $itemId = intval($itemIdStr);
    if ($itemId <= 0) {
        return ['success' => false, 'message' => '请指定要存放的物品ID。用法：home store <物品ID>'];
    }
    $home = HomeHelper::getHome($charId);
    if (!$home) {
        return ['success' => false, 'message' => '你还没有房产。'];
    }
    return HomeHelper::storeItem($home['id'], $itemId);
}

/** 从房产取出物品 */
function homeRetrieve($charId, $itemIdStr) {
    $itemId = intval($itemIdStr);
    if ($itemId <= 0) {
        return ['success' => false, 'message' => '请指定要取出的物品ID。用法：home retrieve <物品ID>'];
    }
    $home = HomeHelper::getHome($charId);
    if (!$home) {
        return ['success' => false, 'message' => '你还没有房产。'];
    }
    return HomeHelper::retrieveItem($home['id'], $charId, $itemId);
}

/** 查看房产存放的物品 */
function homeItems($charId) {
    $home = HomeHelper::getHome($charId);
    if (!$home) {
        return ['success' => false, 'message' => '你还没有房产。'];
    }
    $items = HomeHelper::getStoredItems($home['id']);
    if (empty($items)) {
        return ['success' => true, 'message' => '房间里空空如也，什么物品都没有。'];
    }
    $output = [];
    $output[] = HTML_HIYEL . '【房中物品】' . HTML_NOR;
    foreach ($items as $item) {
        $name = $item['name'] ?? '未知物品';
        $qty = $item['quantity'] ?? 1;
        $output[] = '  ' . $name . ' x' . $qty . ' (ID:' . $item['id'] . ')';
    }
    return ['success' => true, 'message' => implode("\n", $output)];
}

/** 邀请访客 */
function homeInvite($charId, $playerName) {
    $playerName = trim($playerName);
    if (empty($playerName)) {
        return ['success' => false, 'message' => '请指定要邀请的玩家名。用法：home invite <玩家名>'];
    }
    $home = HomeHelper::getHome($charId);
    if (!$home) {
        return ['success' => false, 'message' => '你还没有房产。'];
    }
    $guest = CharacterModel::findByName($playerName);
    if (!$guest) {
        return ['success' => false, 'message' => '找不到名为"' . $playerName . '"的玩家。'];
    }
    $result = HomeHelper::inviteGuest($home['id'], $guest['id'], $charId);
    if ($result['success']) {
        $result['message'] = '你邀请了' . $playerName . '到家中做客。';
    }
    return $result;
}

/** 移除访客 */
function homeKick($charId, $playerName) {
    $playerName = trim($playerName);
    if (empty($playerName)) {
        return ['success' => false, 'message' => '请指定要移除的玩家名。用法：home kick <玩家名>'];
    }
    $home = HomeHelper::getHome($charId);
    if (!$home) {
        return ['success' => false, 'message' => '你还没有房产。'];
    }
    $guest = CharacterModel::findByName($playerName);
    if (!$guest) {
        return ['success' => false, 'message' => '找不到名为"' . $playerName . '"的玩家。'];
    }
    $result = HomeHelper::removeGuest($home['id'], $guest['id']);
    if ($result['success']) {
        $result['message'] = '你将' . $playerName . '从访客列表中移除了。';
    }
    return $result;
}

/** 婴儿管理 */
function homeBaby($charId, $arg) {
    $home = HomeHelper::getHome($charId);
    if (!$home) {
        return ['success' => false, 'message' => '你还没有房产。'];
    }
    $arg = trim($arg);
    if (empty($arg)) {
        $babies = HomeHelper::getBabies($home['id']);
        if (empty($babies)) {
            return ['success' => true, 'message' => '家中还没有孩子。用法：home baby <名字> <male/female>'];
        }
        $output = [];
        $output[] = HTML_HIYEL . '【家中孩子】' . HTML_NOR;
        foreach ($babies as $baby) {
            $gender = ($baby['gender'] === 'male') ? '男' : '女';
            $output[] = '  ' . $baby['name'] . '（' . $gender . '） - 出生于 ' . $baby['born_at'];
        }
        return ['success' => true, 'message' => implode("\n", $output)];
    }
    $parts = preg_split('/\s+/', $arg, 2);
    if (count($parts) < 2) {
        return ['success' => false, 'message' => '用法：home baby <名字> <male/female>'];
    }
    $babyName = trim($parts[0]);
    $gender = strtolower(trim($parts[1]));
    if (empty($babyName)) {
        return ['success' => false, 'message' => '请输入孩子的名字。'];
    }
    if (mb_strlen($babyName) > 20) {
        return ['success' => false, 'message' => '孩子名字最多20个字符。'];
    }
    if (!in_array($gender, ['male', 'female'])) {
        return ['success' => false, 'message' => '性别只能是 male 或 female。'];
    }
    return HomeHelper::addBaby($home['id'], $babyName, $gender);
}

/** 喂养孩子 */
function homeFeedBaby($charId, $babyName) {
    $babyName = trim($babyName);
    if (empty($babyName)) {
        return ['success' => false, 'message' => '请指定要喂养的孩子名字。用法：home feedbaby <孩子名字>'];
    }
    $home = HomeHelper::getHome($charId);
    if (!$home) {
        return ['success' => false, 'message' => '你还没有房产。'];
    }
    return HomeHelper::feedBaby($home['id'], $babyName, $charId);
}

/** 离开家，返回上次所在的房间 */
function homeLeave($charId, $me) {
    $home = HomeHelper::getHome($charId);
    $isVisitor = false;
    
    if (!$home) {
        $visitedHome = HomeHelper::getVisitableHome($charId);
        if (!$visitedHome) {
            return ['success' => false, 'message' => '你不在任何家中。'];
        }
        $isVisitor = true;
        $homeId = $visitedHome['id'];
    }
    
    $lastPosition = $_SESSION['last_home_position'] ?? [];
    if (!empty($lastPosition['area']) && !empty($lastPosition['room'])) {
        $area = $lastPosition['area'];
        $roomId = $lastPosition['room'];
        unset($_SESSION['last_home_position']);
    } else {
        $area = 'city';
        $roomId = 'city/kezhan';
    }
    
    CharacterModel::updatePosition($charId, $area, $roomId);
    
    if ($isVisitor) {
        HomeHelper::removeGuest($homeId, $charId);
    }
    
    return [
        'success' => true,
        'message' => $isVisitor ? '你离开了别人的家。' : '你离开了家。',
        'redirect' => 'room.php?area=' . urlencode($area) . '&room=' . urlencode($roomId),
    ];
}

/** 显示房产帮助信息 */
function homeHelp() {
    $output = [];
    $output[] = HTML_HIYEL . '【房产管理】' . HTML_NOR;
    $output[] = '你可以管理自己的房产，包括装修、存取物品、邀请访客等。';
    $output[] = '';
    $output[] = '可用命令：';
    $output[] = '  home / home enter      - 回到家中';
    $output[] = '  home leave             - 离开家，返回上次所在的房间';
    $output[] = '  home info              - 查看房产信息';
    $output[] = '  home name <名称>       - 修改房间名（最多50字符）';
    $output[] = '  home desc <描述>       - 修改房间描述';
    $output[] = '  home bedname <名称>    - 修改床铺名';
    $output[] = '  home beddesc <描述>    - 修改床铺描述';
    $output[] = '  home store <物品ID>    - 存入物品到房产';
    $output[] = '  home retrieve <物品ID> - 从房产取出物品';
    $output[] = '  home items             - 查看房产存放的物品';
    $output[] = '  home invite <玩家名>   - 邀请访客';
    $output[] = '  home kick <玩家名>     - 移除访客';
    $output[] = '  home baby              - 查看婴儿列表';
    $output[] = '  home baby <名> <male/female> - 生育新婴儿';
    $output[] = '  home feedbaby <名字>   - 喂养孩子';
    $output[] = '';
    $output[] = '提示：结婚后才能购置房产。';
    return ['success' => false, 'message' => implode("\n", $output)];
}
