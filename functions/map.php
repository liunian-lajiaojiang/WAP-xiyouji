<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 地图页面 - 浏览静态地图文件 + 小地图
 */

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

require_login();

$charId = get_char_id();

// 获取角色的当前区域和房间（只需基本信息）
$char = Database::queryOne(
    "SELECT current_area, current_room FROM characters WHERE id = ?",
    [$charId]
);

if (!$char) {
    redirect('character_select.php');
}

$mapName = $_GET['name'] ?? '';

// ====== 地图列表 ======
$mapsDir = __DIR__ . '/../help/maps/';
$availableMaps = [];
if (is_dir($mapsDir)) {
    $files = scandir($mapsDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === '') {
            $mapKey = str_replace('map-', '', $file);
            $availableMaps[$mapKey] = $file;
        }
    }
}

/* ====== 小地图（暂时注释） ======
$miniMapHtml = '';

if (!empty($char['current_room'])) {
    $area = $char['current_area'] ?? '';
    $roomId = $char['current_room']; // 已经是 area/filename 格式
    $room = RoomModel::getFullInfo($area, $roomId);
    
    if ($room && !empty($room['exits'])) {
        // 辅助函数
        $mbw = function($s) { return mb_strwidth($s, 'UTF-8'); };
        $padCenter = function($text, $totalW) use ($mbw) {
            $tw = $mbw($text);
            $padL = intval(($totalW - $tw) / 2);
            $padR = $totalW - $tw - $padL;
            return str_repeat(' ', max($padL, 0)) . $text . str_repeat(' ', max($padR, 0));
        };
        $padRight = function($text, $totalW) use ($mbw) {
            $padL = $totalW - $mbw($text);
            return str_repeat(' ', max($padL, 0)) . $text;
        };
        $padLeft = function($text, $totalW) use ($mbw) {
            $padR = $totalW - $mbw($text);
            return $text . str_repeat(' ', max($padR, 0));
        };
        
        $miniExits = [];
        foreach ($room['exits'] as $e) {
            $d = $e['direction'];
            if (!isset($miniExits[$d])) {
                $miniExits[$d] = !empty($e['target_name']) ? $e['target_name'] : '?';
            }
        }
        
        $nText = $miniExits['north'] ?? '';
        $sText = $miniExits['south'] ?? '';
        $eText = $miniExits['east'] ?? '';
        $wText = $miniExits['west'] ?? '';
        $uText = $miniExits['up'] ?? '';
        $dText = $miniExits['down'] ?? '';
        $neText = $miniExits['northeast'] ?? '';
        $nwText = $miniExits['northwest'] ?? '';
        $seText = $miniExits['southeast'] ?? '';
        $swText = $miniExits['southwest'] ?? '';
        
        $curName = $room['name'] ?? '未知';
        $curW = $mbw($curName);
        
        $hW = $mbw($wText);
        $hE = $mbw($eText);
        $hTotalW = ($wText ? $hW + 3 : 0) + $curW + ($eText ? 3 + $hE : 0);
        $westPad = $wText ? $hW + 3 : 0;
        $centerPos = $westPad + intval($curW / 2);
        
        $nW = $mbw($nText);
        $sW = $mbw($sText);
        $neW = $mbw($neText);
        $nwW = $mbw($nwText);
        $seW = $mbw($seText);
        $swW = $mbw($swText);
        
        $maxW = $hTotalW;
        if ($nText) $maxW = max($maxW, $centerPos + intval($nW / 2) + $nW);
        if ($sText) $maxW = max($maxW, $centerPos + intval($sW / 2) + $sW);
        if ($uText) $maxW = max($maxW, $centerPos + $mbw($uText));
        if ($dText) $maxW = max($maxW, $centerPos + $mbw($dText));
        if ($neText) $maxW = max($maxW, $westPad + $curW + 3 + $neW);
        if ($seText) $maxW = max($maxW, $westPad + $curW + 3 + $seW);
        
        $miniLines = [];
        
        // 上（up）
        if ($uText) {
            $miniLines[] = str_repeat(' ', $centerPos) . '▲ ' . h($uText);
        }
        // 北
        if ($nText) {
            $miniLines[] = $padCenter(h($nText), $hTotalW);
        }
        if ($nText) {
            $miniLines[] = str_repeat(' ', $centerPos) . '│';
        }
        
        // 水平行：西 - 当前 - 东
        $hLine = '';
        if ($wText) {
            $hLine .= $padRight(h($wText), $hW) . ' ─ ';
        }
        $hLine .= '<span style="color:#ff4444;font-weight:bold;">' . h($padCenter($curName, $curW)) . '</span>';
        if ($eText) {
            $hLine .= ' ─ ' . $padLeft(h($eText), $hE);
        }
        $miniLines[] = $hLine;
        
        // 南
        if ($sText) {
            $miniLines[] = str_repeat(' ', $centerPos) . '│';
        }
        if ($sText) {
            $miniLines[] = $padCenter(h($sText), $hTotalW);
        }
        
        // 下（down）
        if ($dText) {
            $miniLines[] = str_repeat(' ', $centerPos) . '▼ ' . h($dText);
        }
        
        // 对角
        if ($nwText || $neText) {
            $diagLine = '';
            if ($nwText) $diagLine .= h($nwText) . ' ↖';
            if ($nwText && $neText) $diagLine .= str_repeat(' ', max(2, $hTotalW - $mbw($nwText . ' ↖' . $neText . '↗ ')));
            if ($neText) $diagLine .= '↗ ' . h($neText);
            $miniLines[] = $diagLine;
        }
        if ($swText || $seText) {
            $diagLine = '';
            if ($swText) $diagLine .= h($swText) . ' ↙';
            if ($swText && $seText) $diagLine .= str_repeat(' ', max(2, $hTotalW - $mbw($swText . ' ↙' . $seText . '↘ ')));
            if ($seText) $diagLine .= '↘ ' . h($seText);
            $miniLines[] = $diagLine;
        }
        
        $miniMapText = implode("\n", $miniLines);
        $hasMiniMap = $nText || $sText || $eText || $wText || $uText || $dText || $neText || $nwText || $seText || $swText;
        
        if ($hasMiniMap) {
            $miniMapHtml = '<pre style="font-family: \'Courier New\', \'SimHei\', monospace; line-height: 1.25; margin: 8px 0; padding: 10px; background: rgba(255,255,255,0.03); border-radius: 4px; display: inline-block; font-size: 14px; color: #aaa; white-space: pre;">' . $miniMapText . '</pre>';
        }
    }
}
====== 小地图（暂时注释） ======*/

// ====== 确定要显示的地图（智能匹配） ======
// 三层递进匹配：area映射 → room_id路径推导 → 房间名关键词精确定位
// 基于 xyj2000-php/config/find_map.php 的77条路径→地名映射 + help/maps/ 的54个地图文件

// ============ 第一层：area → map 基础映射表 ============
// 将角色 current_area 映射到最合适的地图文件
// 无独立地图的区域映射到其所属的大地图
$areaToMap = [
    // === 长安及周边 ===
    'city'          => 'changan',           // 长安城区 → 长安城全景
    'huanggong'     => 'changan',           // 皇宫皇城 → 长安城全景（城内）
    'changan'       => 'changan',           // 长安城外区域 → 长安城全景
    'eastway'       => 'changan-east',      // 东土路 → 长安东部
    'westway'       => 'changan',           // 西域路 → 长安全景（取经路起点）

    // === 天宫/仙界 ===
    'sky'           => 'sky',               // 天宫
    'dntg'          => 'sky',               // 兜率宫/天宫区域 → 天宫
    'pantao'        => 'pantaohui',         // 蟠桃园 → 蟠桃会
    'moon'          => 'moon',              // 月宫
    'penglai'       => 'longgong',          // 蓬莱仙岛 → 龙宫（东海区域）
    'sea'           => 'longgong',          // 东海 → 龙宫
    'nanhai'        => 'putuo',             // 南海 → 普陀山
    'lingtai'       => 'fangcun',           // 灵台山 → 方寸山
    '33tian'        => 'sky',               // 三十三天 → 天宫

    // === 地府 ===
    'death'         => 'hell',              // 阎王地府 → 地府

    // === 凡间城镇 ===
    'kaifeng'       => 'kaifeng',           // 开封府
    'gao'           => 'gao',               // 高老庄
    'huaguo'        => 'hgs',               // 花果山
    'xueshan'       => 'xueshan',           // 大雪山
    'meishan'       => 'guanjiang',         // 眉山 → 灌江口
    'jjf'           => 'jjf',               // 将军府/聚义厅
    'erlang'        => 'guanjiang',         // 二郎神 → 灌江口

    // === 取经路总图 ===
    'qujing'        => 'qujing',            // 取经路总图（无具体子站时）

    // === 取经路各站 ===
    'qujing/wuzhuang'   => 'wzg',
    'qujing/baotou'     => 'baotou',
    'qujing/baoxiang'   => 'baoxiang',
    'qujing/bibotan'    => 'bibotan',
    'qujing/biqiu'      => 'biqiu',
    'qujing/chechi'     => 'chechi',
    'qujing/dudi'       => 'dudi',
    'qujing/fengxian'   => 'fengxian',
    'qujing/firemount'  => 'firemount',
    'qujing/jilei'      => 'jilei',
    'qujing/jindou'     => 'jindou',
    'qujing/jingjiling' => 'jingjiling',
    'qujing/jinping'    => 'jinping',
    'qujing/jisaiguo'   => 'jisaiguo',
    'qujing/lingshan'   => 'lingshan',
    'qujing/maoying'    => 'maoying',
    'qujing/nuerguo'    => 'nuerguo',
    'qujing/pingding'   => 'pingding',
    'qujing/pansi'      => 'pansi',
    'qujing/tongtian'   => 'tongtian',
    'qujing/qilin'      => 'qilin',
    'qujing/qinfa'      => 'qinfa',
    'qujing/qinglong'   => 'qinglong',
    'qujing/tianzhu'    => 'tianzhu',
    'qujing/wudidong'   => 'wudidong',
    'qujing/wuji'       => 'wuji',
    'qujing/xiaoxitian' => 'xiaoxitian',
    'qujing/yinwu'      => 'yinwu',
    'qujing/yuhua'      => 'yuhua',
    'qujing/zhujie'     => 'zhujie',
    'qujing/zhuzi'      => 'zhuzi',
];

// ============ 第二层：area上下文中 room_id 路径前缀 → 子地图 ============
// 同一 area 下不同 room_id 前缀对应不同子区域
// 当 area 对应一个"大地图"时，用 room_id 的前缀来定位子地图
$roomPrefixToMap = [
    // 长安城区(city) → room_id 路径分发到4张长安子地图
    'city' => [
        'baihu'     => 'changan-west',     // 白虎大街 → 长安西部
        'zhuque'    => 'changan',          // 朱雀大街 → 长安全景
        'dongmen'   => 'changan',          // 东门 → 长安全景
        'ximen'     => 'changan-west',     // 西门 → 长安西部
        'nanmen'    => 'changan-south',    // 南门 → 长安南部
        'beimen'    => 'changan',          // 北门 → 全景
        'center'    => 'changan',          // 十字街头 → 全景
        'qinglong'  => 'changan',          // 青龙大街 → 全景
    ],
    // 长安城外(changan) → room_id 路径分发
    'changan' => [
        'east'      => 'changan-east',     // 东部区域 → 长安东部
        'west'      => 'changan-west',     // 西部区域 → 长安西部
        'south'     => 'changan-south',    // 南部区域 → 长安南部
        'broadway'  => 'changan-south',    // 大官道(长安南郊主干道) → 长安南部
        'sbridge'   => 'changan-south',    // 泾水桥南 → 长安南部
        'bridge'    => 'changan-south',    // 泾水桥 → 长安南部
        'nbridge'   => 'changan-south',    // 泾水桥北 → 长安南部
        'nanyue'    => 'changan-south',    // 南岳(衡山) → 长安南部
        'wroad'     => 'gao',              // 青石路/西域路 → 高老庄
        'eside'     => 'changan-south',    // 泾水东滨 → 长安南部
        'wside'     => 'changan-south',    // 泾水西滨 → 长安南部
        'xiaoqiu'   => 'changan-south',    // 小土丘 → 长安南部
        'pinqiting' => 'changan-south',    // 品棋亭 → 长安南部
        'ph'        => 'changan-south',    // 住宅区 → 长安南部
        'office'    => 'changan-south',    // 房管所 → 长安南部
        'eastsea'   => 'changan-south',    // 东海之滨(eastseashore) → 长安南部
        'seashore'  => 'changan-south',    // 海滨(seashore1/seashore2) → 长安南部
        'beach'     => 'changan-south',    // 海滩 → 长安南部
        'sea'       => 'changan-south',    // 海边区域 → 长安南部
        'aolai'     => 'changan-south',    // 傲来国方向 → 长安南部
        'fendui'    => 'changan-south',    // 坟堆 → 长安南部
        'mufa'      => 'changan-south',    // 木筏(东海) → 长安南部
    ],
    // 西域路(westway) → room_id 路径分发
    'westway' => [
        'west'      => 'changan-west',       // 西域路 → 长安西部
    ],
    // 天宫(sky) → room_id 路径分发到天宫子地图
    'sky' => [
        'pantao'    => 'pantaohui',        // 蟠桃园 → 蟠桃会
        'yaochi'    => 'pantaohui',        // 瑶池 → 蟠桃会
        'yanwu'     => 'yanwuchang',       // 演武场
        'lingxiao'  => 'sky',              // 凌霄殿 → 天宫
        'nantian'   => 'sky',              // 南天门 → 天宫
    ],
    // 兜率宫/天宫区域(dntg) → room_id 路径分发
    'dntg' => [
        'hgs'       => 'hgs',              // 花果山
        'sky'       => 'sky',              // 天宫部分
        'aolai'     => 'aolai',            // 傲来国
        'longgong'  => 'longgong',         // 龙宫
    ],
    // 取经路(qujing) → room_id 前缀分发到各子站地图
    'qujing' => [
        'wuzhuang'  => 'wzg',
        'baotou'    => 'baotou',
        'baoxiang'  => 'baoxiang',
        'bibotan'   => 'bibotan',
        'biqiu'     => 'biqiu',
        'chechi'    => 'chechi',
        'dudi'      => 'dudi',
        'fengxian'  => 'fengxian',
        'firemount' => 'firemount',
        'jilei'     => 'jilei',
        'jindou'    => 'jindou',
        'jingjiling'=> 'jingjiling',
        'jinping'   => 'jinping',
        'jisaiguo'  => 'jisaiguo',
        'lingshan'  => 'lingshan',
        'maoying'   => 'maoying',
        'nuerguo'   => 'nuerguo',
        'pingding'  => 'pingding',
        'pansi'     => 'pansi',
        'tongtian'  => 'tongtian',
        'qilin'     => 'qilin',
        'qinfa'     => 'qinfa',
        'qinglong'  => 'qinglong',
        'tianzhu'   => 'tianzhu',
        'wudidong'  => 'wudidong',
        'wuji'      => 'wuji',
        'xiaoxitian'=> 'xiaoxitian',
        'yinwu'     => 'yinwu',
        'yuhua'     => 'yuhua',
        'zhujie'    => 'zhujie',
        'zhuzi'     => 'zhuzi',
    ],
];

// ============ 第三层：房间名关键词 → 子地图（仅当 room_id 路径无法定位时使用） ============
// 作为 room_id 前缀匹配的补充，用于房间名能标识区域但 room_id 路径不明确的情况
$roomKeywordToMap = [
    // 长安区域（area=city 或 area=changan 时生效）
    'city' => [
        '白虎'   => 'changan-west',
        '朱雀'   => 'changan-south',
        '大雁塔' => 'changan-east',
        '碑林'   => 'changan-east',
        '兵马俑' => 'changan-east',
        '华清池' => 'changan-east',
        '始皇陵' => 'changan-east',
        '嘉峪关' => 'changan-west',
        '酒泉'   => 'changan-west',
        '昆仑山' => 'changan-west',
        '终南山' => 'changan-south',
        '灌江'   => 'changan-south',
        '高家庄' => 'changan-south',
        '东海滨' => 'changan-south',
    ],
    'changan' => [
        '大雁塔' => 'changan-east',
        '碑林'   => 'changan-east',
        '兵马俑' => 'changan-east',
        '华清池' => 'changan-east',
        '始皇陵' => 'changan-east',
        '嘉峪关' => 'changan-west',
        '酒泉'   => 'changan-west',
        '昆仑山' => 'changan-west',
        '终南山' => 'changan-south',
        '灌江'   => 'changan-south',
        '东海滨' => 'changan-south',
    ],
    // 天宫区域（area=sky 或 area=dntg 时生效）
    'sky' => [
        '蟠桃'   => 'pantaohui',
        '瑶池'   => 'pantaohui',
        '演武场' => 'yanwuchang',
        '兜率'   => 'sky',
    ],
    'dntg' => [
        '傲来'   => 'aolai',
        '花果山' => 'hgs',
        '水帘洞' => 'hgs',
        '龙宫'   => 'longgong',
        '水晶宫' => 'longgong',
    ],
    // 凡间通用
    'kaifeng' => [],
    'gao' => [],
    'xueshan' => [],
    'jjf' => [],
    'meishan' => [],
    'erlang' => [],
    // 天宫其他
    'moon' => [],
    'pantao' => [],
    'penglai' => [],
    'sea' => [],
    'nanhai' => [
        '普陀'   => 'putuo',
        '紫竹林' => 'putuo',
        '罗汉'   => 'putuo',
    ],
    'lingtai' => [
        '方寸'   => 'fangcun',
        '三星'   => 'fangcun',
    ],
    // 地府
    'death' => [],
    // 取经路各站（子站内部有更细子区域时）
    'qujing' => [
        '五庄观' => 'wzg',
        '宝象'   => 'baoxiang',
        '平顶'   => 'pingding',
        '乌鸡'   => 'wuji',
        '车迟'   => 'chechi',
        '通天'   => 'tongtian',
        '金兜'   => 'jindou',
        '女儿'   => 'nuerguo',
        '毒敌'   => 'dudi',
        '火焰'   => 'firemount',
        '积雷'   => 'jilei',
        '祭赛'   => 'jisaiguo',
        '碧波'   => 'bibotan',
        '荆棘'   => 'jingjiling',
        '小西天' => 'xiaoxitian',
        '朱紫'   => 'zhuzi',
        '麒麟'   => 'qilin',
        '盘丝'   => 'pansi',
        '比丘'   => 'biqiu',
        '无底洞' => 'wudidong',
        '钦法'   => 'qinfa',
        '隐雾'   => 'yinwu',
        '凤仙'   => 'fengxian',
        '玉华'   => 'yuhua',
        '豹头'   => 'baotou',
        '竹节'   => 'zhujie',
        '金平'   => 'jinping',
        '青龙山' => 'qinglong',
        '天竺'   => 'tianzhu',
        '毛颖'   => 'maoying',
        '灵山'   => 'lingshan',
        '雷音'   => 'lingshan',
        '火云洞' => 'huoyun',
    ],
];

// ============ 地图关联导航表 ============
// 每张地图可以关联到"上级/下级/相邻"地图，在页面上提供快捷跳转
$mapRelations = [
    // 长安城系列（层级：all > changan > changan-east/west/south）
    'all' => [
        'children' => ['changan', 'qujing', 'sky', 'hell'],
    ],
    'changan' => [
        'parent'   => 'all',
        'children' => ['changan-east', 'changan-west', 'changan-south'],
    ],
    'changan-east' => [
        'parent'   => 'changan',
        'siblings' => ['changan-west', 'changan-south'],
    ],
    'changan-west' => [
        'parent'   => 'changan',
        'siblings' => ['changan-east', 'changan-south'],
    ],
    'changan-south' => [
        'parent'   => 'changan',
        'siblings' => ['changan-east', 'changan-west'],
    ],
    // 天宫系列
    'sky' => [
        'parent'   => 'all',
        'children' => ['pantaohui', 'yanwuchang', 'moon'],
    ],
    'pantaohui' => [
        'parent'   => 'sky',
        'siblings' => ['yanwuchang', 'moon'],
    ],
    'yanwuchang' => [
        'parent'   => 'sky',
        'siblings' => ['pantaohui', 'moon'],
    ],
    'moon' => [
        'parent'   => 'sky',
    ],
    // 取经路系列
    'qujing' => [
        'parent'   => 'all',
        'children' => ['wzg', 'baoxiang', 'pingding', 'wuji', 'chechi', 'tongtian',
                       'jindou', 'nuerguo', 'dudi', 'firemount', 'jilei', 'jisaiguo',
                       'bibotan', 'jingjiling', 'xiaoxitian', 'zhuzi', 'qilin', 'pansi',
                       'biqiu', 'wudidong', 'qinfa', 'yinwu', 'fengxian', 'yuhua',
                       'baotou', 'zhujie', 'jinping', 'qinglong', 'tianzhu', 'maoying', 'lingshan'],
    ],
    // 取经路各站 → 上一站/下一站导航
    'wzg'       => ['parent' => 'qujing', 'prev' => null,       'next' => 'baoxiang'],
    'baoxiang'  => ['parent' => 'qujing', 'prev' => 'wzg',       'next' => 'pingding'],
    'pingding'  => ['parent' => 'qujing', 'prev' => 'baoxiang',  'next' => 'wuji'],
    'wuji'      => ['parent' => 'qujing', 'prev' => 'pingding',  'next' => 'chechi'],
    'chechi'    => ['parent' => 'qujing', 'prev' => 'wuji',      'next' => 'tongtian'],
    'tongtian'  => ['parent' => 'qujing', 'prev' => 'chechi',    'next' => 'jindou'],
    'jindou'    => ['parent' => 'qujing', 'prev' => 'tongtian',  'next' => 'nuerguo'],
    'nuerguo'   => ['parent' => 'qujing', 'prev' => 'jindou',    'next' => 'dudi'],
    'dudi'      => ['parent' => 'qujing', 'prev' => 'nuerguo',   'next' => 'firemount'],
    'firemount' => ['parent' => 'qujing', 'prev' => 'dudi',      'next' => 'jilei'],
    'jilei'     => ['parent' => 'qujing', 'prev' => 'firemount', 'next' => 'jisaiguo'],
    'jisaiguo'  => ['parent' => 'qujing', 'prev' => 'jilei',     'next' => 'bibotan'],
    'bibotan'   => ['parent' => 'qujing', 'prev' => 'jisaiguo',  'next' => 'jingjiling'],
    'jingjiling'=> ['parent' => 'qujing', 'prev' => 'bibotan',   'next' => 'xiaoxitian'],
    'xiaoxitian'=> ['parent' => 'qujing', 'prev' => 'jingjiling','next' => 'zhuzi'],
    'zhuzi'     => ['parent' => 'qujing', 'prev' => 'xiaoxitian','next' => 'qilin'],
    'qilin'     => ['parent' => 'qujing', 'prev' => 'zhuzi',     'next' => 'pansi'],
    'pansi'     => ['parent' => 'qujing', 'prev' => 'qilin',     'next' => 'biqiu'],
    'biqiu'     => ['parent' => 'qujing', 'prev' => 'pansi',     'next' => 'wudidong'],
    'wudidong'  => ['parent' => 'qujing', 'prev' => 'biqiu',     'next' => 'qinfa'],
    'qinfa'     => ['parent' => 'qujing', 'prev' => 'wudidong',  'next' => 'yinwu'],
    'yinwu'     => ['parent' => 'qujing', 'prev' => 'qinfa',     'next' => 'fengxian'],
    'fengxian'  => ['parent' => 'qujing', 'prev' => 'yinwu',     'next' => 'yuhua'],
    'yuhua'     => ['parent' => 'qujing', 'prev' => 'fengxian',  'next' => 'baotou'],
    'baotou'    => ['parent' => 'qujing', 'prev' => 'yuhua',     'next' => 'zhujie'],
    'zhujie'    => ['parent' => 'qujing', 'prev' => 'baotou',    'next' => 'jinping'],
    'jinping'   => ['parent' => 'qujing', 'prev' => 'zhujie',    'next' => 'qinglong'],
    'qinglong'  => ['parent' => 'qujing', 'prev' => 'jinping',   'next' => 'tianzhu'],
    'tianzhu'   => ['parent' => 'qujing', 'prev' => 'qinglong',  'next' => 'maoying'],
    'maoying'   => ['parent' => 'qujing', 'prev' => 'tianzhu',   'next' => 'lingshan'],
    'lingshan'  => ['parent' => 'qujing', 'prev' => 'maoying',   'next' => null],
    // 龙宫/四海系列
    'longgong' => [
        'parent' => 'sky',
        'siblings' => ['putuo', 'fangcun', 'hgs', 'aolai'],
    ],
    'putuo' => [
        'parent' => 'sky',
    ],
    'fangcun' => [
        'parent' => 'sky',
    ],
    'hgs' => [
        'parent' => 'sky',
        'siblings' => ['aolai', 'longgong'],
    ],
    'aolai' => [
        'parent' => 'sky',
        'siblings' => ['hgs', 'longgong'],
    ],
    // 凡间
    'kaifeng' => ['parent' => 'all'],
    'gao'     => ['parent' => 'all'],
    'xueshan' => ['parent' => 'all'],
    'guanjiang'=> ['parent' => 'all'],
    'jjf'     => ['parent' => 'all'],
    // 地府
    'hell'    => ['parent' => 'all'],
    'huoyun'  => ['parent' => 'qujing'],
];

// ============ 智能匹配引擎 ============
$currentRoomName = '';
$matchedMethod = ''; // 记录匹配方式，调试用

if (empty($mapName)) {
    $charArea = $char['current_area'] ?? '';
    $charRoom = $char['current_room'] ?? '';

    // 获取房间名
    $roomName = '';
    if ($charRoom) {
        $roomInfo = Database::queryOne(
            "SELECT name FROM rooms WHERE room_id = ?",
            [$charRoom]
        );
        if ($roomInfo && !empty($roomInfo['name'])) {
            $roomName = $roomInfo['name'];
        }
    }

    // 从 room_id 提取路径各段，用于多级匹配
    // 如 changan/eastseashore → ['changan', 'eastseashore']
    $roomIdSegments = [];
    if ($charRoom && strpos($charRoom, '/') !== false) {
        $roomIdSegments = explode('/', $charRoom);
    }

    // === 第1步：area 直接命中可用地图（仅当 area 没有子地图规则时才直接使用） ===
    // 如果 area 有 roomPrefixToMap 规则，说明需要更精细的子地图匹配，不应在此步直接用 area
    if (isset($availableMaps[$charArea]) && !isset($roomPrefixToMap[$charArea])) {
        $mapName = $charArea;
        $matchedMethod = 'area直接命中';
    }

    // === 第2步：room_id 路径前缀匹配子地图 ===
    // 当 area 映射到一个"大地图"时，用 room_id 的每一段尝试匹配子地图
    // 从最后一段开始往前匹配（最后一段最精确），如 changan/eastseashore → eastseashore → east
    if (!$mapName && !empty($roomIdSegments) && isset($roomPrefixToMap[$charArea])) {
        $prefixRules = $roomPrefixToMap[$charArea];
        // 构建候选匹配列表：完整段 → 段的前缀（递减）
        $candidates = [];
        foreach ($roomIdSegments as $seg) {
            $candidates[] = $seg;
            // 也加入段的前缀部分（如 eastseashore → east）
            foreach ($prefixRules as $prefix => $subMap) {
                if (strlen($prefix) >= 2 && strpos($seg, $prefix) === 0 && $seg !== $prefix) {
                    $candidates[] = $prefix;
                }
            }
        }
        // 从后往前匹配，最后一段优先级最高
        // 前缀拆分已在候选构建阶段完成，此处只做精确匹配避免短前缀误匹配
        $candidates = array_reverse($candidates);
        foreach ($candidates as $cand) {
            foreach ($prefixRules as $prefix => $subMap) {
                if ($cand === $prefix) {
                    if (isset($availableMaps[$subMap])) {
                        $mapName = $subMap;
                        $matchedMethod = "room_id前缀匹配: {$charRoom} → {$subMap}";
                        break 2;
                    }
                }
            }
        }
    }

    // === 第3步：通过 areaToMap 映射 ===
    if (!$mapName) {
        $resolvedArea = $areaToMap[$charArea] ?? null;
        if ($resolvedArea && isset($availableMaps[$resolvedArea])) {
            $mapName = $resolvedArea;
            $matchedMethod = "areaToMap映射: {$charArea} → {$resolvedArea}";
        }
    }

    // === 第4步：area 上下文内的关键词匹配子地图 ===
    // 只在没有精确匹配结果时，用房间名关键词模糊匹配
    // 注意：如果第2步 room_id 前缀已精确匹配，跳过关键词匹配（避免模糊覆盖精确）
    if (!$mapName && $roomName && isset($roomKeywordToMap[$charArea])) {
        $areaKeywords = $roomKeywordToMap[$charArea];
        foreach ($areaKeywords as $keyword => $subMap) {
            if (mb_strpos($roomName, $keyword) !== false && isset($availableMaps[$subMap])) {
                $mapName = $subMap;
                $matchedMethod = "关键词匹配(area={$charArea}): {$keyword} → {$subMap}";
                break;
            }
        }
    }

    // === 第5步：area 直接可用（兜底） ===
    if (!$mapName && isset($availableMaps[$charArea])) {
        $mapName = $charArea;
        $matchedMethod = 'area兜底';
    }
}

// ====== 加载并渲染地图 ======
$mapContent = '';
if (!empty($mapName) && isset($availableMaps[$mapName])) {
    $mapFile = $mapsDir . $availableMaps[$mapName];
    $mapContent = file_get_contents($mapFile);
    if (!mb_check_encoding($mapContent, 'UTF-8')) {
        $mapContent = mb_convert_encoding($mapContent, 'UTF-8', 'GBK');
    }

    // 高亮角色当前位置（用房间名在地图文本中匹配）
    if (!empty($roomName)) {
        $currentRoomName = $roomName;
        $highlighted = '<span style="color:#ff4444;font-weight:bold;">' . $currentRoomName . '</span>';
        // 只替换独立出现的房间名（避免部分匹配）
        $mapContent = preg_replace(
            '/(?<!\w)' . preg_quote($currentRoomName, '/') . '(?!\w)/u',
            $highlighted,
            $mapContent
        );
    }

    $pageTitle = '地图 - ' . h($mapName);
} else {
    $pageTitle = '地图列表';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <title><?= $pageTitle ?>_西游记MUD</title>
</head>
<body>
    <a href="map.php">地图</a>&ensp;
    <a href="fly.php">飞行</a>&ensp;
    <a href="room.php">返回</a>
    <?php if ($currentRoomName): ?>
    &ensp;<span style="color:#ff4444;">📍 当前：<?= h($currentRoomName) ?></span>
    <?php endif; ?>
    <!-- DEBUG: area=<?= h($charArea) ?> room=<?= h($charRoom) ?> map=<?= h($mapName) ?> method=<?= h($matchedMethod) ?> -->
    <hr>

<?php if ($mapContent): ?>
    <?php
    // ====== 地图关联导航栏 ======
    $navLinks = [];
    $relation = $mapRelations[$mapName] ?? [];

    // 上级地图
    if (!empty($relation['parent']) && isset($availableMaps[$relation['parent']])) {
        $parentLabel = $mapLabels[$relation['parent']] ?? $relation['parent'];
        $navLinks[] = '<a href="map.php?name=' . urlencode($relation['parent']) . '" title="返回上级">◀ ' . h($parentLabel) . '</a>';
    }
    // 上一站（取经路序列）
    if (isset($relation['prev']) && $relation['prev'] !== null && isset($availableMaps[$relation['prev']])) {
        $prevLabel = $mapLabels[$relation['prev']] ?? $relation['prev'];
        $navLinks[] = '<a href="map.php?name=' . urlencode($relation['prev']) . '" title="上一站">◀◀ ' . h($prevLabel) . '</a>';
    }
    // 兄弟地图（同级）
    if (!empty($relation['siblings'])) {
        foreach ($relation['siblings'] as $sib) {
            if (isset($availableMaps[$sib])) {
                $sibLabel = $mapLabels[$sib] ?? $sib;
                $navLinks[] = '<a href="map.php?name=' . urlencode($sib) . '">' . h($sibLabel) . '</a>';
            }
        }
    }
    // 下一站（取经路序列）
    if (isset($relation['next']) && $relation['next'] !== null && isset($availableMaps[$relation['next']])) {
        $nextLabel = $mapLabels[$relation['next']] ?? $relation['next'];
        $navLinks[] = '<a href="map.php?name=' . urlencode($relation['next']) . '" title="下一站">' . h($nextLabel) . ' ▶▶</a>';
    }
    // 子地图列表
    if (!empty($relation['children'])) {
        foreach ($relation['children'] as $child) {
            if (isset($availableMaps[$child])) {
                $childLabel = $mapLabels[$child] ?? $child;
                $navLinks[] = '<a href="map.php?name=' . urlencode($child) . '">' . h($childLabel) . '</a>';
            }
        }
    }

    if (!empty($navLinks)) {
        echo '<div style="margin-bottom:8px;padding:4px 8px;background:rgba(255,255,255,0.04);border-radius:4px;font-size:0.9em;">';
        echo '🗺️ ';
        $currentLabel = $mapLabels[$mapName] ?? $mapName;
        echo '<strong>' . h($currentLabel) . '</strong>';
        if (!empty($navLinks)) {
            echo ' &nbsp;│&nbsp; ' . implode(' &nbsp;·&nbsp; ', $navLinks);
        }
        echo '</div>';
    }
    ?>
    <pre><?= $mapContent ?></pre>
    <hr>
<?php endif; ?>

    <?php
    $mapLabels = [
        // 长安系列
        'changan'  => '长安城',     'changan-east'  => '长安东',
        'changan-south' => '长安南', 'changan-west' => '长安西',
        // 天宫/仙界
        'sky'      => '天宫',       'longgong'   => '龙宫',
        'moon'     => '月宫',       'pantaohui' => '蟠桃园',
        'putuo'    => '普陀山',     'fangcun'    => '方寸山',
        'hgs'      => '花果山',     'aolai'      => '傲来国',
        'guanjiang'=> '灌江口',     'yanwuchang' => '演武场',
        // 凡间城镇
        'kaifeng'  => '开封城',     'gao'        => '高老庄',
        'xueshan'  => '大雪山',     'jjf'        => '将军府',
        'wzg'      => '五庄观',
        // 取经路
        'baoxiang' => '宝象国',     'pingding'   => '平顶山',
        'wuji'     => '乌鸡国',     'chechi'     => '车迟国',
        'tongtian' => '通天河',     'jindou'     => '金兜山',
        'nuerguo'  => '女儿国',     'dudi'       => '毒敌山',
        'firemount'=> '火焰山',     'jilei'      => '积雷山',
        'jisaiguo' => '祭赛国',     'bibotan'    => '碧波潭',
        'jingjiling'=>'荆棘岭',     'xiaoxitian' => '小西天',
        'zhuzi'    => '朱紫国',     'qilin'      => '麒麟山',
        'pansi'    => '盘丝洞',     'biqiu'      => '比丘国',
        'wudidong' => '无底洞',     'qinfa'      => '钦法国',
        'yinwu'    => '隐雾山',     'fengxian'   => '凤仙郡',
        'yuhua'    => '玉华州',     'baotou'     => '豹头山',
        'zhujie'   => '竹节山',     'jinping'    => '金平府',
        'qinglong' => '青龙山',     'tianzhu'    => '天竺国',
        'maoying'  => '毛颖山',     'huoyun'   => '火云洞',
        'lingshan'   => '灵山',
        // 地府
        'hell'     => '地府',
    ];

    $count = 0; $cols = 4;
    echo '<table border="0" cellpadding="4" cellspacing="0" style="text-align:left;">';
    foreach ($mapLabels as $key => $name):
        if (!isset($availableMaps[$key])) continue;
        if ($count % $cols == 0) echo '<tr>';
        echo '<td style="padding: 3px 12px 3px 0;"><a href="map.php?name=' . urlencode($key) . '">' . h($name) . '</a></td>';
        if ($count % $cols == $cols - 1) echo '</tr>';
        $count++;
    endforeach;
    while ($count % $cols != 0) { echo '<td></td>'; $count++; }
    if ($count > 0) echo '</tr>';
    echo '</table>';
    ?>
    <hr>
    <a href="map.php?name=qujing">取经路线图</a>&ensp;
    <a href="map.php?name=all">总图</a>
</body>
</html>
