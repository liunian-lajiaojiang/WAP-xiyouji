<?php
/**
 * 飞行命令 (fly) - 腾云驾雾快速移动
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 用法: fly <目的地>
 */

require_once DAEMON_PATH . 'CombatDaemon.php';
require_once HELPER_PATH . 'SkillManager.php';

/**
 * 检查玩家背包中是否有指定物品
 * @param int $charId 角色ID
 * @param string $itemName 物品名称或item_id
 * @return array|null 找到的物品信息，未找到返回null
 */
function presentItem(int $charId, string $itemName): ?array {
    $inventory = CharacterModel::getInventory($charId);
    
    foreach ($inventory as $item) {
        // 检查item_id或item_name是否匹配
        if ($item['item_id'] === $itemName || $item['item_name'] === $itemName) {
            return $item;
        }
    }
    
    return null;
}

/**
 * 飞行命令 (fly) - 腾云驾雾快速移动
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 用法: fly <目的地>
 */

// 目的地映射
$destinations = [
    'stone'     => 'dntg_hgs_entrance',      // 花果山仙石
    'kaifeng'   => 'kaifeng_tieta',          // 开封城
    'moon'      => 'moon_ontop2',            // 昆仑山月宫
    'lingtai'   => 'lingtai_gate',           // 灵台方寸山
    'putuo'     => 'nanhai_gate',            // 南海普陀山
    'changan'   => 'city_center',            // 长安城
    'sky'       => 'dntg_sky_nantian',       // 南天门
    'wuzhuang'  => 'qujing_wuzhuang_gate',   // 万寿山五庄观
    'meishan'   => 'meishan_erlangwai',      // 梅山灌江口
    'baoxiang'  => 'qujing_baoxiang_bei1',   // 宝象国
    'pingding'  => 'qujing_pingding_ping1',  // 平顶山
    'yalong'    => 'qujing_pingding_yalong1',// 压龙山
    'wuji'      => 'qujing_wuji_square',     // 乌鸡国
    'chechi'    => 'qujing_chechi_jieshi1',  // 车迟国
    'tongtian'  => 'qujing_tongtian_hedong1',// 通天河
    'jindou'    => 'qujing_jindou_jindou1',  // 金兜山
    'nuerguo'   => 'qujing_nuerguo_towna1',  // 女儿国
    'dudi'      => 'qujing_dudi_dudi1',      // 毒敌山
    'firemount' => 'qujing_firemount_cuiyun1',// 火焰山
    'jilei'     => 'qujing_jilei_jilei1',    // 积雷山
    'jisaiguo'  => 'qujing_jisaiguo_east1',  // 祭赛国
    'jingjiling'=> 'qujing_jingjiling_jingji1',// 荆棘岭
    'xiaoxitian'=> 'qujing_xiaoxitian_simen',// 小西天
    'zhuzi'     => 'qujing_zhuzi_zhuzi1',    // 朱紫国
    'qilin'     => 'qujing_qilin_yutai',     // 麒麟山
    'pansi'     => 'qujing_pansi_ling1',     // 盘丝岭
    'biqiu'     => 'qujing_biqiu_jie1',      // 比丘国
    'qinghua'   => 'qujing_biqiu_zhuang',    // 清华庄
    'wudidong'  => 'qujing_wudidong_firemount-wudidong3',// 无底洞
    'qinfa'     => 'qujing_qinfa_jiedao1',   // 钦法国
    'yinwu'     => 'qujing_yinwu_huangye1',  // 隐雾山
    'fengxian'  => 'qujing_fengxian_jiedao1',// 凤仙郡
    'yuhua'     => 'qujing_yuhua_xiaojie1',  // 玉华县
    'baotou'    => 'qujing_baotou_shanlu1',  // 豹头山
    'zhujie'    => 'qujing_zhujie_shanlu11', // 竹节山
    'jinping'   => 'qujing_jinping_xiaojie1',// 金平府
    'qinglong'  => 'qujing_qinglong_shanjian',// 青龙山
    'tianzhu'   => 'qujing_tianzhu_jiedao11',// 天竺国
    'maoying'   => 'qujing_maoying_shanpo1', // 毛颖山
    'lingshan'  => 'qujing_lingshan_dalu1',  // 灵山
];

function cmd_fly(int $charId, string $param = ''): array {
    if (empty($param)) {
        return ['success' => false, 'message' => '你要飞到哪里去？'];
    }
    
    // 获取角色信息
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 检查是否被监禁
    $user = Database::queryOne("SELECT status FROM users WHERE id = ?", [$char['user_id']]);
    if ($user && $user['status'] == BanHelper::STATUS_PRISONED) {
        return ['success' => false, 'message' => '你被关在监禁房间里，无法飞行！'];
    }
    
    // is_busy() 检查（统一使用 is_player_busy）
    if (is_player_busy($charId)) {
        return ['success' => false, 'message' => '你正忙着呢，没工夫腾云驾雾。'];
    }
    
    // 检查是否在战斗中
    if (CombatDaemon::isInCombat($charId)) {
        return ['success' => false, 'message' => '你正在战斗，飞不开。'];
    }
    
    // 检查当前房间是否为室外
    $currentRoom = RoomModel::load($char['current_area'], $char['current_room']);
    if ($currentRoom && empty($currentRoom['outdoors'])) {
        return ['success' => false, 'message' => '周围没有一片云，没办法腾云驾雾。'];
    }
    
    // 检查是否被定住
    if (isset($_SESSION["no_move_{$charId}"]) && $_SESSION["no_move_{$charId}"]) {
        return ['success' => false, 'message' => '你被定住了，哪里飞得起来！'];
    }
    
    // 检查道行要求(初领妙道以上)
    $daoxing = $char['daoxing'] ?? 0;
    if ($daoxing < 1000) { // 假设初领妙道需要1000道行
        $messages = [];
        $messages[] = HIY . "{$char['name']}奋力望上一跳，似乎想飞起来。结果离地不到三尺就一个倒栽葱摔了下来。" . NOR;
        $messages[] = "你现在还初领妙道都谈不上，哪里飞得起来。";
        return ['success' => false, 'message' => implode("\n", $messages)];
    }
    
    // 检查法力修为(腾云驾雾等级)
    $maxMana = $char['max_mana'] ?? 0;
    if ($maxMana < 500) { // 假设腾云驾雾需要500最大法力
        $messages = [];
        $messages[] = HIY . "{$char['name']}奋力望上一跳，似乎想飞起来。结果离地不到三尺就一个倒栽葱摔了下来。" . NOR;
        $messages[] = "看来以你的法力修为还不能腾云驾雾。";
        return ['success' => false, 'message' => implode("\n", $messages)];
    }
    
    // 检查当前法力
    $mana = $char['mana'] ?? 0;
    if ($mana < 200) {
        $messages = [];
        $messages[] = HIY . "{$char['name']}奋力望上一跳，似乎想飞起来。结果离地不到三尺就一个倒栽葱摔了下来。" . NOR;
        $messages[] = "你目前法力不够充盈。";
        return ['success' => false, 'message' => implode("\n", $messages)];
    }
    
    // 检查神识状态
    $sen = $char['sen'] ?? 0;
    $maxSen = $char['max_sen'] ?? 1;
    if ($sen * 100 / $maxSen < 50) {
        return ['success' => false, 'message' => '你现在头脑不太清醒，当心掉下来摔死。'];
    }
    
    // 检查体力状态
    $kee = $char['kee'] ?? 0;
    $maxKee = $char['max_kee'] ?? 1;
    if ($kee * 100 / $maxKee < 50) {
        return ['success' => false, 'message' => '你想飞起来，可是体力似乎有点不支。'];
    }
    
    // 计算法力消耗
    $spellsSkill = SkillManager::querySkill($charId, 'spells');
    $manaCost = -(100 - $spellsSkill) / 4 - 40;
    if ($manaCost > 0) {
        $manaCost = 0;
    }
    $manaCost = intval($manaCost);
    
    // 获取门派信息
    $familyName = $char['family_name'] ?? '';
    
    // 显示起飞消息
    $messages = [];
    switch ($familyName) {
        case '灵台方寸山':
            $messages[] = HIY . "{$char['name']}手一指，一朵七彩祥云从天而降，{$char['name']}脚踏祥云，瑞气千条，再吹一声口哨，随之冉冉升起。。。" . NOR;
            break;
        case '花果山水帘洞':
        case '陷空山无底洞':
            $messages[] = HIY . "{$char['name']}一个筋斗翻上云端，「呼」地一阵狂风卷起，再吹一声口哨，随之飘去不见了。。。" . NOR;
            break;
        case '东海龙宫':
            $messages[] = HIY . "{$char['name']}双手一划，一道水柱冲天而起，{$char['name']}踏浪而去，再吹一声口哨，水花飞溅间消失不见。。。" . NOR;
            break;
        case '南海普陀山':
            $messages[] = HIY . "{$char['name']}双手合十，脚下生出一朵白莲花，缓缓升起，佛光普照间{$char['name']}已消失在天际。。。" . NOR;
            break;
        case '月宫':
            $messages[] = HIY . "{$char['name']}轻抬素手，月华流转间银辉笼罩身形，再吹一声口哨，随月光冉冉升起。。。" . NOR;
            break;
        case '五庄观':
            $messages[] = HIY . "{$char['name']}袍袖一挥，清风拂面，仙气缭绕间{$char['name']}随风而起，飘然远去。。。" . NOR;
            break;
        case '阎罗地府':
            $messages[] = HIY . "{$char['name']}手一指，平地间一股阴风刮起，将{$char['name']}裹了起来，" . NOR;
            $messages[] = HIY . "再吹一声口哨，随之飘去不见了。。。" . NOR;
            break;
        case '将军府':
            $messages[] = HIY . "{$char['name']}大喝一声，金光护体，威势冲天，脚踏金光直冲云霄，转瞬间消失不见。。。" . NOR;
            break;
        case '火云洞':
            $messages[] = HIY . "{$char['name']}手一招，脚下烈焰翻滚，火光冲天，{$char['name']}踏火而起，再吹一声口哨，火光中消失不见。。。" . NOR;
            break;
        case '大雪山':
            $messages[] = HIY . "{$char['name']}手一指，「呼」地一阵狂风刮起，吹的人都睁不开眼睛，" . NOR;
            $messages[] = HIY . "再吹一声口哨，随之飘去不见了。。。" . NOR;
            break;
        default:
            $messages[] = HIY . "{$char['name']}手一指，召来一朵云彩，高高兴兴地坐了上去，" . NOR;
            $messages[] = HIY . "再吹一声口哨，随之往上冉冉地升起。。。" . NOR;
            break;
    }
    
    // 扣除法力
    Database::execute(
        "UPDATE characters SET mana = mana + ? WHERE id = ?",
        [$manaCost, $charId]
    );
    
    // 查找目的地
    global $destinations;
    $roomId = $destinations[$param] ?? null;
    
    // 特殊地图需要地图物品
    if ($param === 'penglai') {
        // VIP3及以上可以跳过地图检查
        if (!check_vip_permission(3)) {
            // 需要东海蓬莱山地图(ditu)
            $map = presentItem($charId, 'ditu');
            if (!$map) {
                $messages[] = "你在天上转了半天也不知该往那边飞。。。";
                $messages[] = HIY . "{$char['name']}失望地从云上跳了下来！" . NOR;
                return ['success' => true, 'message' => implode("\n", $messages)];
            }
            // 检查地图单位是否为"张"
            if (($map['unit'] ?? '') !== '张') {
                $messages[] = "你在天上转了半天也不知该往那边飞。。。";
                $messages[] = HIY . "{$char['name']}失望地从云上跳了下来！" . NOR;
                return ['success' => true, 'message' => implode("\n", $messages)];
            }
        }
        $roomId = 'penglai_penglai'; // 蓬莱仙岛
    } elseif ($param === 'xueshan') {
        // VIP3及以上可以跳过地图检查
        if (!check_vip_permission(3)) {
            // 需要雪山地图(xueshan map)
            $map = presentItem($charId, 'xueshan_map');
            if (!$map) {
                $messages[] = "你在天上转了半天也不知该往那边飞。。。";
                $messages[] = HIY . "{$char['name']}失望地从云上跳了下来！" . NOR;
                return ['success' => true, 'message' => implode("\n", $messages)];
            }
            // 检查地图单位是否为"张"
            if (($map['unit'] ?? '') !== '张') {
                $messages[] = "你在天上转了半天也不知该往那边飞。。。";
                $messages[] = HIY . "{$char['name']}失望地从云上跳了下来！" . NOR;
                return ['success' => true, 'message' => implode("\n", $messages)];
            }
        }
        $roomId = 'xueshan_binggu'; // 大雪山寒冰谷
    }
    
    if (!$roomId) {
        $messages[] = "\n\n到了！你按下云头跳了下来。";
        $messages[] = "咦？．．．怎么还在原来的地方？";
        return ['success' => true, 'message' => implode("\n", $messages)];
    }
    
    // 移动到目的地
    $targetRoom = RoomModel::findByAlias($roomId);
    if (!$targetRoom) {
        $messages[] = "\n\n到了！你按下云头跳了下来。";
        $messages[] = "咦？．．．目的地不存在。";
        return ['success' => true, 'message' => implode("\n", $messages)];
    }
    
    // 黄风捕获：飞行时 1/5000 概率静默触发
    // 参考 xyj2000-php/cmds/std/Fly.php: MISC_D->random_capture($me, 0, 1)
    // chance=0 时使用默认值 5000, silent=1 静默模式
    require_once __DIR__ . '/../daemons/Miscd.php';
    $captured = Miscd::randomCapture($charId, 0, true);
    if ($captured) {
        $captureMsg = Miscd::getCaptureMessage($charId);
        return [
            'success' => true,
            'message' => $captureMsg ?? '你被一阵黄风卷走了！',
            'redirect' => 'room.php?area=qujing&room=baihuling/jail',
        ];
    }
    
    // 更新角色位置
    Database::execute(
        "UPDATE characters SET current_area = ?, current_room = ? WHERE id = ?",
        [$targetRoom['area'], $targetRoom['room_id'], $charId]
    );
    
    $messages[] = "\n\n到了！你按下云头跳了下来。";
    
    // 显示到达消息
    if ($familyName === '阎罗地府') {
        $messages[] = HIY . "只见平地吹起一阵阴风，{$char['name']}从里面走了出来。" . NOR;
    } elseif ($familyName === '大雪山' || $familyName === '陷空山无底洞') {
        $messages[] = HIY . "只见狂风大作，{$char['name']}从里面走了出来。" . NOR;
    } else {
        $messages[] = HIY . "只见半空中降下一朵云彩，{$char['name']}从里面走了出来。" . NOR;
    }
    
    // 记录日志
    log_action($charId, 'FLY', "飞到 {$targetRoom['room_name']}");
    
    return [
        'success' => true,
        'message' => implode("\n", $messages),
        'new_room_id' => $targetRoom['id']
    ];
}

