<?php
/**
 * 排行榜系统页面
 * 支持：道行榜、法力榜、武学榜、任务榜、综合实力榜、二十八星宿
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../helpers/RankHelper.php';
require_once __DIR__ . '/../helpers/ProfessionHelper.php';

// 获取排行榜类型
$type = $_GET['type'] ?? 'daoxing';
$valid_types = ['daoxing', 'fali', 'wuxue', 'overall', 'stars', 'mieyao', 'jiemi', 'wuzhuangyuan', 'fengshen'];
if (!in_array($type, $valid_types)) {
    $type = 'daoxing';
}

// 门派名称映射
$sect_names = [
    'lingtai'    => '灵台方寸山',
    'wudidong'   => '花果山水帘洞',
    'longgong'   => '东海龙宫',
    'nanhai'     => '南海普陀山',
    'moon'       => '月宫',
    'wzg'        => '五庄观',
    'yanluofu'   => '阎罗地府',
    'jiangjunfu' => '将军府',
    'huoyun'     => '火云洞',
    'xueshan'    => '大雪山',
];

// 二十八星宿名称
$star_names = ['角','亢','氐','房','心','尾','箕','斗','牛','女','虚','危','室','壁','奎','娄','胃','昴','毕','觜','参','井','鬼','柳','星','张','翼','轸'];

/**
 * 根据race和family获取职业类型（优先读取数据库profession字段）
 */
function getCareerType(string $race, string $family, string $profession = ''): string {
    // 优先使用数据库中存储的profession（手动设置的，如dancer、scholar）
    if (!empty($profession) && in_array($profession, ['xian','yaomo','dragon','youling','bonze','taoist','fighter','scholar','dancer'])) {
        return $profession;
    }
    // 否则动态推算
    return ProfessionHelper::inferProfession($race, $family);
}

/**
 * 职业类型中文名
 */
function getCareerName(string $career): string {
    return ProfessionHelper::getProfessionName($career);
}

/**
 * 道行等级描述
 */
function getDaoxingDesc(int $daoxing): string {
    if ($daoxing <= 0) return '初入道途';
    if ($daoxing < 2000) return '闻道则喜';
    if ($daoxing < 16000) return '初领妙道';
    if ($daoxing < 56000) return '略通道行';
    if ($daoxing < 128000) return '渐入佳境';
    if ($daoxing < 250000) return '元神初具';
    if ($daoxing < 432000) return '道心稳固';
    if ($daoxing < 686000) return '一日千里';
    if ($daoxing < 1024000) return '道高德隆';
    if ($daoxing < 1458000) return '脱胎换骨';
    if ($daoxing < 2000000) return '霞举飞升';
    if ($daoxing < 2662000) return '道满根归';
    if ($daoxing < 3456000) return '不堕轮回';
    if ($daoxing < 4394000) return '已证大道';
    if ($daoxing < 5688000) return '反璞归真';
    return '天人合一';
}

/**
 * 法力等级描述
 */
function getFaliDesc(int $max_mana): string {
    if ($max_mana < 40) return '初具法力';
    if ($max_mana < 160) return '略晓变化';
    if ($max_mana < 360) return '降龙伏虎';
    if ($max_mana < 640) return '腾云驾雾';
    if ($max_mana < 1000) return '神出鬼没';
    if ($max_mana < 1440) return '预知祸福';
    if ($max_mana < 1960) return '妙领天机';
    if ($max_mana < 2560) return '呼风唤雨';
    if ($max_mana < 3240) return '负海担山';
    if ($max_mana < 4000) return '移星换斗';
    if ($max_mana < 4840) return '包罗万象';
    if ($max_mana < 5760) return '随心所欲';
    if ($max_mana < 6760) return '变换莫测';
    return '法力无边';
}

/**
 * 战斗经验等级描述
 */
function getCombatExpDesc(int $combat_exp): string {
    if ($combat_exp < 338) return '初学乍练';
    if ($combat_exp < 1350) return '初窥门径';
    if ($combat_exp < 3038) return '略通皮毛';
    if ($combat_exp < 5400) return '小有所成';
    if ($combat_exp < 8438) return '有所成就';
    if ($combat_exp < 12150) return '已有小成';
    if ($combat_exp < 16538) return '中级高手';
    if ($combat_exp < 21600) return '高手级别';
    if ($combat_exp < 27338) return '内外兼修';
    if ($combat_exp < 33750) return '融会贯通';
    if ($combat_exp < 40838) return '出神入化';
    if ($combat_exp < 48600) return '登堂入室';
    if ($combat_exp < 57038) return '炉火纯青';
    if ($combat_exp < 66150) return '渐悟天道';
    if ($combat_exp < 75938) return '超凡入圣';
    if ($combat_exp < 86400) return '登峰造极';
    if ($combat_exp < 97538) return '惊世骇俗';
    if ($combat_exp < 109350) return '前无古人';
    if ($combat_exp < 121838) return '举世无双';
    return '深不可测';
}

/**
 * 获取玩家称号（去除ANSI颜色码）
 */
function getCleanRank(array $char): string {
    $career = getCareerType($char['race'] ?? 'human', $char['family'] ?? '', $char['profession'] ?? '');
    // 构造rankd需要的race字段（中文职业类型）
    $rankChar = $char;
    $rankChar['race'] = getCareerName($career);
    $rank = RankHelper::queryRank($rankChar, '', false, null);
    // 去除ANSI颜色码
    $rank = preg_replace('/<[^>]+>/', '', $rank);// 去除多余空格
    $rank = preg_replace('/\s+/', ' ', trim($rank));
    return $rank;
}

/**
 * 获取排行榜数据
 */
function getRankData(string $type, int $limit = 50): array {
    switch ($type) {
        case 'daoxing':
            $sql = "SELECT id, name, gender, race, family, profession, daoxing, combat_exp, max_mana, max_force, level, experience
                    FROM characters ORDER BY daoxing DESC LIMIT {$limit}";
            break;
        case 'fali':
            $sql = "SELECT id, name, gender, race, family, profession, daoxing, combat_exp, max_mana, max_force, level, experience
                    FROM characters ORDER BY max_mana DESC LIMIT {$limit}";
            break;
        case 'wuxue':
            $sql = "SELECT id, name, gender, race, family, profession, daoxing, combat_exp, max_mana, max_force, level, experience
                    FROM characters ORDER BY combat_exp DESC LIMIT {$limit}";
            break;
        case 'mieyao':
            $sql = "SELECT c.id, c.name, c.gender, c.race, c.family, c.profession, c.daoxing, c.combat_exp, c.max_mana, c.max_force, c.level, c.experience,
                    COUNT(my.id) AS mieyao_kills,
                    COALESCE(SUM(my.exp_reward), 0) AS total_exp_reward,
                    COALESCE(SUM(my.pot_reward), 0) AS total_pot_reward
                    FROM characters c
                    LEFT JOIN mieyao_yaoguai my ON my.owner_id = c.id AND my.is_killed = 1
                    GROUP BY c.id, c.name, c.gender, c.race, c.family, c.profession, c.daoxing, c.combat_exp, c.max_mana, c.max_force, c.level, c.experience
                    HAVING mieyao_kills > 0
                    ORDER BY total_exp_reward + total_pot_reward DESC
                    LIMIT {$limit}";
            break;
        case 'jiemi':
            $sql = "SELECT id, name, gender, race, family, profession, daoxing, combat_exp, max_mana, max_force, level, experience, dntg_quest_stage
                    FROM characters WHERE dntg_quest_stage > 0 ORDER BY dntg_quest_stage DESC LIMIT {$limit}";
            break;
        case 'overall':
            $sql = "SELECT id, name, gender, race, family, profession, daoxing, combat_exp, max_mana, max_force, level, experience,
                    (daoxing * 0.3 + combat_exp * 0.3 + max_mana * 0.2 + max_force * 0.2) AS overall_score
                    FROM characters ORDER BY overall_score DESC LIMIT {$limit}";
            break;
        case 'stars':
            $sql = "SELECT xr.rank_level, xr.char_id, xr.char_name, xr.daoxing, xr.status,
                    cr.name AS star_name, cr.title AS star_title, cr.group AS star_group
                    FROM xingxiu_ranks xr
                    LEFT JOIN (
                        SELECT 'jiao' AS rank_level, '角木蛟星君' AS name, '角木蛟' AS title, 'qinglong' AS `group` UNION ALL
                        SELECT 'kang', '亢金龙星君', '亢金龙', 'qinglong' UNION ALL
                        SELECT 'di', '氐土貉星君', '氐土貉', 'qinglong' UNION ALL
                        SELECT 'fang', '房日兔星君', '房日兔', 'qinglong' UNION ALL
                        SELECT 'xin', '心月狐星君', '心月狐', 'qinglong' UNION ALL
                        SELECT 'wei', '尾火虎星君', '尾火虎', 'qinglong' UNION ALL
                        SELECT 'ji', '箕水豹星君', '箕水豹', 'qinglong' UNION ALL
                        SELECT 'dou', '斗木獬星君', '斗木獬', 'xuanwu' UNION ALL
                        SELECT 'niu', '牛金牛星君', '牛金牛', 'xuanwu' UNION ALL
                        SELECT 'nv', '女土蝠星君', '女土蝠', 'xuanwu' UNION ALL
                        SELECT 'xu', '虚日鼠星君', '虚日鼠', 'xuanwu' UNION ALL
                        SELECT 'wei2', '危月燕星君', '危月燕', 'xuanwu' UNION ALL
                        SELECT 'shi', '室火猪星君', '室火猪', 'xuanwu' UNION ALL
                        SELECT 'bi', '壁水貐星君', '壁水貐', 'xuanwu' UNION ALL
                        SELECT 'kui', '奎木狼星君', '奎木狼', 'baihu' UNION ALL
                        SELECT 'lou', '娄金狗星君', '娄金狗', 'baihu' UNION ALL
                        SELECT 'wei3', '胃土雉星君', '胃土雉', 'baihu' UNION ALL
                        SELECT 'mao', '昴日鸡星君', '昴日鸡', 'baihu' UNION ALL
                        SELECT 'bi2', '毕月乌星君', '毕月乌', 'baihu' UNION ALL
                        SELECT 'zi', '觜火猴星君', '觜火猴', 'baihu' UNION ALL
                        SELECT 'shen', '参水猿星君', '参水猿', 'baihu' UNION ALL
                        SELECT 'jing', '井木犴星君', '井木犴', 'zhuque' UNION ALL
                        SELECT 'gui', '鬼金羊星君', '鬼金羊', 'zhuque' UNION ALL
                        SELECT 'liu', '柳土獐星君', '柳土獐', 'zhuque' UNION ALL
                        SELECT 'xing', '星日马星君', '星日马', 'zhuque' UNION ALL
                        SELECT 'zhang', '张月鹿星君', '张月鹿', 'zhuque' UNION ALL
                        SELECT 'yi', '翼火蛇星君', '翼火蛇', 'zhuque' UNION ALL
                        SELECT 'zhen', '轸水蚓星君', '轸水蚓', 'zhuque'
                    ) cr ON xr.rank_level = cr.rank_level
                    ORDER BY FIELD(cr.group, 'qinglong', 'xuanwu', 'baihu', 'zhuque'),
                             FIELD(xr.rank_level, 'jiao','kang','di','fang','xin','wei','ji','dou','niu','nv','xu','wei2','shi','bi','kui','lou','wei3','mao','bi2','zi','shen','jing','gui','liu','xing','zhang','yi','zhen')";
            break;
        case 'wuzhuangyuan':
            $sql = "SELECT wr.rank_level, wr.rank_position, wr.char_id, wr.char_name, wr.daoxing,
                    rl.name AS level_name, rl.title AS rank_title
                    FROM wuzhuangyuan_ranks wr
                    LEFT JOIN (
                        SELECT 'gold' AS rank_level, 1 AS rank_position, '金榜' AS name, '武状元' AS title UNION ALL
                        SELECT 'gold', 2, '金榜', '武榜眼' UNION ALL
                        SELECT 'gold', 3, '金榜', '武探花' UNION ALL
                        SELECT 'silver', 1, '银榜', '第一' UNION ALL
                        SELECT 'silver', 2, '银榜', '第二' UNION ALL
                        SELECT 'silver', 3, '银榜', '第三' UNION ALL
                        SELECT 'copper', 1, '铜榜', '第一' UNION ALL
                        SELECT 'copper', 2, '铜榜', '第二' UNION ALL
                        SELECT 'copper', 3, '铜榜', '第三' UNION ALL
                        SELECT 'iron', 1, '铁榜', '第一' UNION ALL
                        SELECT 'iron', 2, '铁榜', '第二' UNION ALL
                        SELECT 'iron', 3, '铁榜', '第三' UNION ALL
                        SELECT 'tin', 1, '锡榜', '第一' UNION ALL
                        SELECT 'tin', 2, '锡榜', '第二' UNION ALL
                        SELECT 'tin', 3, '锡榜', '第三'
                    ) rl ON wr.rank_level = rl.rank_level AND wr.rank_position = rl.rank_position
                    ORDER BY FIELD(wr.rank_level, 'gold', 'silver', 'copper', 'iron', 'tin'), wr.rank_position";
            break;
        case 'fengshen':
            $sql = "SELECT pr.rank_level, pr.char_id, pr.char_name, pr.daoxing, pr.status,
                    rl.name AS god_name, rl.title AS god_title
                    FROM pantaohui_ranks pr
                    LEFT JOIN (
                        SELECT 'sun' AS rank_level, '太阳神位' AS name, '日神' AS title UNION ALL
                        SELECT 'moon', '太阴神位', '月神' UNION ALL
                        SELECT 'metal', '太白神位', '金神' UNION ALL
                        SELECT 'wood', '岁星神位', '木神' UNION ALL
                        SELECT 'water', '辰星神位', '水神' UNION ALL
                        SELECT 'fire', '荧惑神位', '火神' UNION ALL
                        SELECT 'earth', '镇星神位', '土神'
                    ) rl ON pr.rank_level = rl.rank_level
                    ORDER BY FIELD(pr.rank_level, 'sun', 'moon', 'metal', 'wood', 'water', 'fire', 'earth')";
            break;
        default:
            $sql = "SELECT id, name, gender, race, family, profession, daoxing, combat_exp, max_mana, max_force, level, experience
                    FROM characters ORDER BY daoxing DESC LIMIT {$limit}";
    }
    return Database::queryAll($sql);
}

/**
 * 获取排行榜数值
 */
function getRankValue(array $char, string $type): int {
    switch ($type) {
        case 'daoxing': return (int)($char['daoxing'] ?? 0);
        case 'fali':    return (int)($char['max_mana'] ?? 0);
        case 'wuxue':   return (int)($char['combat_exp'] ?? 0);
        case 'mieyao':  return (int)($char['total_exp_reward'] ?? 0) + (int)($char['total_pot_reward'] ?? 0);
        case 'jiemi':   return (int)($char['dntg_quest_stage'] ?? 0);
        case 'overall':
        case 'stars':   return (int)($char['overall_score'] ?? 0);
        default:        return 0;
    }
}

/**
 * 获取等级描述
 */
/**
 * 解迷关卡名称
 */
function getJiemiStageName(int $stage): string {
    $stageNames = [
        1  => '第一关：花果山称王',
        2  => '第二关：龙宫借宝',
        3  => '第三关：地府除名',
        4  => '第四关：初闯南天',
        5  => '第五关：齐天大圣',
        6  => '第六关：蟠桃园',
        7  => '第七关：搅乱蟠桃会',
        8  => '第八关：盗取仙丹',
        9  => '第九关：大战二郎神',
        10 => '第十关：大闹天宫',
    ];
    if ($stage >= 10) return '大闹天宫（已通关）';
    return $stageNames[$stage] ?? '尚未开始';
}

function getLevelDesc(array $char, string $type): string {
    switch ($type) {
        case 'daoxing': return getDaoxingDesc((int)($char['daoxing'] ?? 0));
        case 'fali':    return getFaliDesc((int)($char['max_mana'] ?? 0));
        case 'wuxue':   return getCombatExpDesc((int)($char['combat_exp'] ?? 0));
        case 'mieyao':  return '灭妖' . (int)($char['mieyao_kills'] ?? 0) . '只，经验+' . number_format((int)($char['total_exp_reward'] ?? 0)) . '，潜能+' . number_format((int)($char['total_pot_reward'] ?? 0));
        case 'jiemi':   return getJiemiStageName((int)($char['dntg_quest_stage'] ?? 0));
        case 'overall':
        case 'stars':   return '综合' . number_format((int)($char['overall_score'] ?? 0));
        default:        return '';
    }
}

/**
 * 数值单位格式化
 */
function formatValue(int $value, string $type): string {
    if ($type === 'mieyao') {
        return number_format($value);
    }
    if ($type === 'jiemi') {
        return $value . '/10 关';
    }
    if ($type === 'overall' || $type === 'stars') {
        return number_format($value) . ' 分';
    }
    return number_format($value);
}

/**
 * 排行榜标题
 */
function getRankTitle(string $type): string {
    switch ($type) {
        case 'daoxing': return '道行榜';
        case 'fali':    return '法力榜';
        case 'wuxue':   return '武学榜';
        case 'mieyao':  return '灭妖奖励榜';
        case 'jiemi':   return '解迷任务榜';
        case 'overall': return '综合实力榜';
        case 'stars':   return '二十八星宿';
        case 'wuzhuangyuan': return '武状元榜';
        case 'fengshen': return '封神榜';
        default:        return '排行榜';
    }
}

/**
 * 排行值标题
 */
function getRankValueTitle(string $type): string {
    switch ($type) {
        case 'daoxing': return '道行';
        case 'fali':    return '法力';
        case 'wuxue':   return '武学经验';
        case 'mieyao':  return '奖励总值';
        case 'jiemi':   return '通关进度';
        case 'overall': return '综合评分';
        case 'stars':   return '综合评分';
        case 'wuzhuangyuan': return '道行';
        case 'fengshen': return '道行';
        default:        return '数值';
    }
}

// 获取数据
$rankData = getRankData($type);
$rankTitle = getRankTitle($type);

// Tab配置
$tabs = [
    ['type' => 'daoxing', 'name' => '道行榜'],
    ['type' => 'fali',    'name' => '法力榜'],
    ['type' => 'wuxue',   'name' => '武学榜'],
    ['type' => 'mieyao',  'name' => '灭妖榜'],
    ['type' => 'jiemi',   'name' => '解迷榜'],
    ['type' => 'overall', 'name' => '综合实力榜'],
    ['type' => 'stars',   'name' => '二十八星宿'],
    ['type' => 'wuzhuangyuan', 'name' => '武状元榜'],
    ['type' => 'fengshen', 'name' => '封神榜'],
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $rankTitle ?>_西游记MUD</title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <style>
        .disabled { color: gray; pointer-events: none; text-decoration: none; }
    </style>
</head>
<body>
    <a href="javascript:location.reload();"><?= $rankTitle ?></a>&ensp;
    <a href="../functions/room.php">返回</a>
    <hr>
    [
    <?php foreach ($tabs as $i => $tab): ?>
    <?php if ($i > 0): ?> | <?php endif; ?>
    <a href="?type=<?= $tab['type'] ?>"<?= $type === $tab['type'] ? ' class="disabled"' : '' ?>><?= $tab['name'] ?></a>
    <?php endforeach; ?>
    ]
    <hr>

    <?php if ($type === 'stars'): ?>
    <!-- 二十八星宿 -->
    <?php
    $star_groups = [
        ['name' => '东方青龙', 'key' => 'qinglong'],
        ['name' => '北方玄武', 'key' => 'xuanwu'],
        ['name' => '西方白虎', 'key' => 'baihu'],
        ['name' => '南方朱雀', 'key' => 'zhuque'],
    ];
    ?>
    <?php foreach ($star_groups as $group): ?>
    <p><b><?= $group['name'] ?>七宿</b></p>
    <?php $groupData = array_filter($rankData, function($row) use ($group) { return $row['star_group'] === $group['key']; }); ?>
    <?php foreach ($groupData as $row): ?>
        <?php $isOccupied = $row['status'] === 'occupied'; ?>
        <?php if ($isOccupied && $row['char_id']): ?>
        <p>&nbsp;&nbsp;<b><?= $row['star_title'] ?></b> - <a href="../functions/character.php?id=<?= $row['char_id'] ?>"><?= htmlspecialchars($row['char_name'] ?? '未知') ?></a>（<?= number_format((int)($row['daoxing'] ?? 0)) ?> 年道行）</p>
        <?php else: ?>
        <p>&nbsp;&nbsp;<b><?= $row['star_title'] ?></b> - 虚位以待</p>
        <?php endif; ?>
    <?php endforeach; ?>
    <br>
    <?php endforeach; ?>

    <?php elseif ($type === 'wuzhuangyuan'): ?>
    <!-- 武状元榜 -->
    <?php
    $rank_groups = [
        ['name' => '金榜', 'key' => 'gold'],
        ['name' => '银榜', 'key' => 'silver'],
        ['name' => '铜榜', 'key' => 'copper'],
        ['name' => '铁榜', 'key' => 'iron'],
        ['name' => '锡榜', 'key' => 'tin'],
    ];
    ?>
    <?php foreach ($rank_groups as $group): ?>
    <p><b><?= $group['name'] ?></b></p>
    <?php $groupData = array_filter($rankData, function($row) use ($group) { return $row['rank_level'] === $group['key']; }); ?>
    <?php foreach ($groupData as $row): ?>
        <?php if ($row['char_id']): ?>
        <p>&nbsp;&nbsp;第<?= $row['rank_position'] ?>名 <b><?= $row['rank_title'] ?></b> - <a href="../functions/character.php?id=<?= $row['char_id'] ?>"><?= htmlspecialchars($row['char_name'] ?? '未知') ?></a>（<?= number_format((int)($row['daoxing'] ?? 0)) ?> 年道行）</p>
        <?php else: ?>
        <p>&nbsp;&nbsp;第<?= $row['rank_position'] ?>名 <b><?= $row['rank_title'] ?></b> - 虚位以待</p>
        <?php endif; ?>
    <?php endforeach; ?>
    <br>
    <?php endforeach; ?>

    <?php elseif ($type === 'fengshen'): ?>
    <!-- 封神榜 -->
    <table border="0" cellpadding="2" cellspacing="0" style="text-align:left;">
        <tr>
            <td>神位</td>
            <td>星君</td>
            <td>道行</td>
        </tr>
        <?php foreach ($rankData as $row): ?>
        <tr>
            <td><b><?= $row['god_title'] ?></b></td>
            <?php if ($row['status'] === 'occupied' && $row['char_id']): ?>
            <td><a href="../functions/character.php?id=<?= $row['char_id'] ?>"><?= htmlspecialchars($row['char_name'] ?? '未知') ?></a></td>
            <td><?= number_format((int)($row['daoxing'] ?? 0)) ?></td>
            <?php else: ?>
            <td>虚位以待</td>
            <td>-</td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </table>

    <?php else: ?>
    <!-- 普通排行榜 -->
    <?php if (empty($rankData)): ?>
    <p>暂无排行数据，三界尚待群雄逐鹿……</p>
    <?php else: ?>
    <table border="0" cellpadding="2" cellspacing="0" style="text-align:left;">
        <tr>
            <td>名次</td>
            <td>姓名</td>
            <td><?= getRankValueTitle($type) ?></td>
            <td>等级描述</td>
        </tr>
        <?php foreach ($rankData as $idx => $char): ?>
        <?php
            $rank = $idx + 1;
            $value = formatValue(getRankValue($char, $type), $type);
            $name = htmlspecialchars($char['name'] ?? '未知');
            $levelDesc = htmlspecialchars(getLevelDesc($char, $type));
        ?>
        <tr>
            <td><?= $rank ?></td>
            <td><a href="../functions/character.php?id=<?= $char['id'] ?>"><?= $name ?></a></td>
            <td><?= $value ?></td>
            <td><?= $levelDesc ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
    <?php endif; ?>

    <hr>
    <a href="../functions/room.php">返回</a>
</body>
</html>

