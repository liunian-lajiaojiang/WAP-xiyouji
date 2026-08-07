<?php
// 参考 xyj2000-php 重构，已修复无名XX占位符
// 物品ID已标准化：去除空格，统一小写
return array (
  'npc_map' => 
  array (
    'zhubajie' => 
    array (
      'name' => '猪八戒',
      'quest_type' => 'food',
      'room' => '/d/kaifeng/tianpeng',
      'topic' => '食物',
      'color_code' => 'red',
    ),
    'xgong' => 
    array (
      'name' => '相公',
      'quest_type' => 'weapon',
      'room' => '/d/kaifeng/bingqi',
      'topic' => '武器',
      'color_code' => 'blue',
    ),
    'xpo' => 
    array (
      'name' => '相婆',
      'quest_type' => 'armor',
      'room' => '/d/kaifeng/kuijia',
      'topic' => '盔甲',
      'color_code' => 'purple',
    ),
    'xianglan' => 
    array (
      'name' => '香兰',
      'quest_type' => 'cloth',
      'room' => '/d/kaifeng/xianglan',
      'topic' => '衣物',
      'color_code' => 'white',
    ),
    'yulan' => 
    array (
      'name' => '玉兰',
      'quest_type' => 'wearing',
      'room' => '/d/kaifeng/yulan',
      'topic' => '首饰',
      'color_code' => 'red',
    ),
    'cuilan' => 
    array (
      'name' => '翠兰',
      'quest_type' => 'misc',
      'room' => '/d/kaifeng/cuilan',
      'topic' => '家具',
      'color_code' => 'purple',
    ),
    'yin' => 
    array (
      'name' => '殷夫人',
      'quest_type' => 'give',
      'room' => '/d/kaifeng/pudu',
      'topic' => '求签',
      'color_code' => 'cyan',
    ),
    'chen' => 
    array (
      'name' => '陈光蕊',
      'quest_type' => 'ask',
      'room' => '/d/kaifeng/jixian',
      'topic' => '祭祖',
      'color_code' => 'green',
    ),
    'hu' => 
    array (
      'name' => '胡敬德',
      'quest_type' => 'kill',
      'room' => '/d/kaifeng/ee',
      'topic' => '妖魔',
      'color_code' => 'yellow',
    ),
  ),
  'quest_pools' => 
  array (
    'food' => 
    array (
      5 => 
      array (
        'type' => 'find',
        'name' => '花生',
        'id' => 'huasheng',
      ),
      7 => 
      array (
        'type' => 'find',
        'name' => '猪肉',
        'id' => 'zhurou',
      ),
      10 => 
      array (
        'type' => 'find',
        'name' => '炸鸡腿',
        'id' => 'jitui',
      ),
      15 => 
      array (
        'type' => 'find',
        'name' => '花酒袋',
        'id' => 'jiudai',
      ),
      20 => 
      array (
        'type' => 'find',
        'name' => '狗肉',
        'id' => 'gourou',
      ),
      50 => 
      array (
        'type' => 'find',
        'name' => '坛子',
        'id' => 'tanzi',
      ),
      60 => 
      array (
        'type' => 'find',
        'name' => '瓶子酒',
        'id' => 'jiuping',
      ),
      70 => 
      array (
        'type' => 'find',
        'name' => '茶壶',
        'id' => 'chahu',
      ),
      80 => 
      array (
        'type' => 'find',
        'name' => '茶碗',
        'id' => 'chagan',
      ),
      90 => 
      array (
        'type' => 'find',
        'name' => '猪头',
        'id' => 'pighead',
      ),
      100 => 
      array (
        'type' => 'find',
        'name' => '素菜包子',
        'id' => 'sucaibao',
      ),
      110 => 
      array (
        'type' => 'find',
        'name' => '猪肉包子',
        'id' => 'zhuroubao',
      ),
      120 => 
      array (
        'type' => 'find',
        'name' => '海鲜包子',
        'id' => 'haixianbao',
      ),
      150 => 
      array (
        'type' => 'find',
        'name' => '云南白药',
        'id' => 'yunnanbaiyao',
      ),
      200 => 
      array (
        'type' => 'find',
        'name' => '水果',
        'id' => 'egg',
      ),
      210 => 
      array (
        'type' => 'find',
        'name' => '肉饼',
        'id' => 'roubing',
      ),
      220 => 
      array (
        'type' => 'find',
        'name' => '酒皮囊',
        'id' => 'jiunang',
      ),
      250 => 
      array (
        'type' => 'find',
        'name' => '猕猴桃干',
        'id' => 'mihoutaogan',
      ),
      290 => 
      array (
        'type' => 'find',
        'name' => '牛皮袋',
        'id' => 'jiudai',
      ),
      300 => 
      array (
        'type' => 'find',
        'name' => '包子',
        'id' => 'baozi',
      ),
      310 => 
      array (
        'type' => 'find',
        'name' => '葫芦',
        'id' => 'hulu',
      ),
      320 => 
      array (
        'type' => 'find',
        'name' => '红萝卜',
        'id' => 'carrot',
      ),
      330 => 
      array (
        'type' => 'find',
        'name' => '豆子',
        'id' => 'beans',
      ),
      331 => 
      array (
        'type' => 'find',
        'name' => '小白菜',
        'id' => 'xbc',
      ),
      400 => 
      array (
        'type' => 'find',
        'name' => '羊皮袋',
        'id' => 'jiudai',
      ),
      500 => 
      array (
        'type' => 'find',
        'name' => '雪梨',
        'id' => 'xueli',
      ),
      530 => 
      array (
        'type' => 'find',
        'name' => '桂花羹',
        'id' => 'guihuageng',
      ),
      550 => 
      array (
        'type' => 'find',
        'name' => '雪莲',
        'id' => 'xuelian',
      ),
      600 => 
      array (
        'type' => 'find',
        'name' => '粽子',
        'id' => 'zongzi',
      ),
      700 => 
      array (
        'type' => 'find',
        'name' => '鸡',
        'id' => 'chicken',
      ),
      710 => 
      array (
        'type' => 'find',
        'name' => '葡萄干',
        'id' => 'putaogan',
      ),
      800 => 
      array (
        'type' => 'find',
        'name' => '米酒',
        'id' => 'mijiu',
      ),
      810 => 
      array (
        'type' => 'find',
        'name' => '饭',
        'id' => 'fan',
      ),
      820 => 
      array (
        'type' => 'find',
        'name' => '年糕',
        'id' => 'niangao',
      ),
      830 => 
      array (
        'type' => 'find',
        'name' => '油葫芦',
        'id' => 'youhulu',
      ),
      840 => 
      array (
        'type' => 'find',
        'name' => '盐',
        'id' => 'yan',
      ),
      1010 => 
      array (
        'type' => 'find',
        'name' => '好茶碗',
        'id' => 'chagan',
      ),
      1020 => 
      array (
        'type' => 'find',
        'name' => '瓜子',
        'id' => 'guazi',
      ),
      1030 => 
      array (
        'type' => 'find',
        'name' => '花生米',
        'id' => 'huashengmi',
      ),
      1040 => 
      array (
        'type' => 'find',
        'name' => '尖椒',
        'id' => 'jianjiao',
      ),
      1050 => 
      array (
        'type' => 'find',
        'name' => '饺子',
        'id' => 'shuijiao',
      ),
      1060 => 
      array (
        'type' => 'find',
        'name' => '面片',
        'id' => 'mianpian',
      ),
      1070 => 
      array (
        'type' => 'find',
        'name' => '面汤',
        'id' => 'miantang',
      ),
      1080 => 
      array (
        'type' => 'find',
        'name' => '面条',
        'id' => 'miantiao',
      ),
      1100 => 
      array (
        'type' => 'find',
        'name' => '细面',
        'id' => 'miantiao',
      ),
      1130 => 
      array (
        'type' => 'find',
        'name' => '牛肉面',
        'id' => 'miantiao',
      ),
      1300 => 
      array (
        'type' => 'find',
        'name' => '薰腊肠',
        'id' => 'xunlachang',
      ),
      1310 => 
      array (
        'type' => 'find',
        'name' => '醉鸡',
        'id' => 'zuiziji',
      ),
      1320 => 
      array (
        'type' => 'find',
        'name' => '五花肉',
        'id' => 'wuhuamenrou',
      ),
      1330 => 
      array (
        'type' => 'find',
        'name' => '青丝熏鱼',
        'id' => 'qingsixunyu',
      ),
      1340 => 
      array (
        'type' => 'find',
        'name' => '糖醋排骨',
        'id' => 'tangcupaigu',
      ),
      1350 => 
      array (
        'type' => 'find',
        'name' => '酱汁肉丝',
        'id' => 'jingjiangrousi',
      ),
      1360 => 
      array (
        'type' => 'find',
        'name' => '油焖大虾',
        'id' => 'youqiangdaxia',
      ),
      1370 => 
      array (
        'type' => 'find',
        'name' => '脆皮烤鸭',
        'id' => 'cuipikaoya',
      ),
      1380 => 
      array (
        'type' => 'find',
        'name' => '红烧肉',
        'id' => 'hongshaosue',
      ),
      1390 => 
      array (
        'type' => 'find',
        'name' => '宫保鸡丁',
        'id' => 'gongbaojiding',
      ),
      1400 => 
      array (
        'type' => 'find',
        'name' => '麻辣肚丝',
        'id' => 'maladusi',
      ),
      1410 => 
      array (
        'type' => 'find',
        'name' => '红油肥片',
        'id' => 'hongyoufeipian',
      ),
      1420 => 
      array (
        'type' => 'find',
        'name' => '嫩炒竹竿',
        'id' => 'nenqiangzhugan',
      ),
      1430 => 
      array (
        'type' => 'find',
        'name' => '走油肥肠',
        'id' => 'zouyoucuichang',
      ),
      1440 => 
      array (
        'type' => 'find',
        'name' => '爆炒腰花',
        'id' => 'baochaoyaohua',
      ),
      1450 => 
      array (
        'type' => 'find',
        'name' => '细切头肉',
        'id' => 'xipatourou',
      ),
      1460 => 
      array (
        'type' => 'find',
        'name' => '麝香冬笋',
        'id' => 'shexiangdongsun',
      ),
      1470 => 
      array (
        'type' => 'find',
        'name' => '翡翠豆腐',
        'id' => 'feicuidoufu',
      ),
      1480 => 
      array (
        'type' => 'find',
        'name' => '三鲜腐竹',
        'id' => 'sanxianfuzhu',
      ),
      1490 => 
      array (
        'type' => 'find',
        'name' => '时鲜蔬菜',
        'id' => 'shuxianshucai',
      ),
      1500 => 
      array (
        'type' => 'find',
        'name' => '皮蛋瘦肉粥',
        'id' => 'pidanshourouzhou',
      ),
      1510 => 
      array (
        'type' => 'find',
        'name' => '火腿竹笋羹',
        'id' => 'huotuizhushenggeng',
      ),
      1600 => 
      array (
        'type' => 'find',
        'name' => '茶盏',
        'id' => 'chazhan',
      ),
      1610 => 
      array (
        'type' => 'find',
        'name' => '煎豆腐',
        'id' => 'jiandoufu',
      ),
      1620 => 
      array (
        'type' => 'find',
        'name' => '烧面巾',
        'id' => 'shaomianjin',
      ),
      2000 => 
      array (
        'type' => 'find',
        'name' => '羊头',
        'id' => 'yangtou',
      ),
      2010 => 
      array (
        'type' => 'find',
        'name' => '泡馍',
        'id' => 'paomo',
      ),
      2020 => 
      array (
        'type' => 'find',
        'name' => '杂碎',
        'id' => 'zasui',
      ),
      3010 => 
      array (
        'type' => 'find',
        'name' => '哈密瓜',
        'id' => 'hamigua',
      ),
      3020 => 
      array (
        'type' => 'find',
        'name' => '草莓',
        'id' => 'caomei',
      ),
      3030 => 
      array (
        'type' => 'find',
        'name' => '苹果',
        'id' => 'pingguo',
      ),
      3040 => 
      array (
        'type' => 'find',
        'name' => '水蜜桃',
        'id' => 'putao',
      ),
      3100 => 
      array (
        'type' => 'find',
        'name' => '五味烧鹅',
        'id' => 'wuweishaoe',
      ),
      3110 => 
      array (
        'type' => 'find',
        'name' => '香汁蒸鸭',
        'id' => 'xiangzhizhengya',
      ),
      3120 => 
      array (
        'type' => 'find',
        'name' => '辣味牛汤',
        'id' => 'laweiniutang',
      ),
      3130 => 
      array (
        'type' => 'find',
        'name' => '牛肉汤面',
        'id' => 'niuroutangmian',
      ),
      4010 => 
      array (
        'type' => 'find',
        'name' => '蒸素',
        'id' => 'zhengsu',
      ),
      4011 => 
      array (
        'type' => 'find',
        'name' => '肉夹膜',
        'id' => 'roujiamo',
      ),
      4020 => 
      array (
        'type' => 'find',
        'name' => '粗米饭',
        'id' => 'caomifan',
      ),
      4030 => 
      array (
        'type' => 'find',
        'name' => '酱牛肉',
        'id' => 'jiangniurou',
      ),
      4040 => 
      array (
        'type' => 'find',
        'name' => '菜糠团子',
        'id' => 'caikangtuanzi',
      ),
      4050 => 
      array (
        'type' => 'find',
        'name' => '酒壶',
        'id' => 'jiuhu',
      ),
      4060 => 
      array (
        'type' => 'find',
        'name' => '供果',
        'id' => 'gongguo',
      ),
      4100 => 
      array (
        'type' => 'find',
        'name' => '醉泥螺',
        'id' => 'zuiniluo',
      ),
      4200 => 
      array (
        'type' => 'find',
        'name' => '薰鱼',
        'id' => 'xunyu',
      ),
      4300 => 
      array (
        'type' => 'find',
        'name' => '蒸螃蟹',
        'id' => 'zhengpangxie',
      ),
      4400 => 
      array (
        'type' => 'find',
        'name' => '烧龙虾',
        'id' => 'shaolongxia',
      ),
      5010 => 
      array (
        'type' => 'find',
        'name' => '小吃',
        'id' => 'xqc',
      ),
      5020 => 
      array (
        'type' => 'find',
        'name' => '灯花糕',
        'id' => 'dhg',
      ),
      5100 => 
      array (
        'type' => 'find',
        'name' => '烤全羊',
        'id' => 'kaoquanyang',
      ),
      5110 => 
      array (
        'type' => 'find',
        'name' => '烤乳猪',
        'id' => 'kaoruzhu',
      ),
      6010 => 
      array (
        'type' => 'find',
        'name' => '琼浆玉液',
        'id' => 'qiongyao',
      ),
      6020 => 
      array (
        'type' => 'find',
        'name' => '油炸花生',
        'id' => 'huasheng',
      ),
      6100 => 
      array (
        'type' => 'find',
        'name' => '油炸鸡',
        'id' => 'youzhaji',
      ),
      6110 => 
      array (
        'type' => 'find',
        'name' => '油豆腐',
        'id' => 'youdoufu',
      ),
      6120 => 
      array (
        'type' => 'find',
        'name' => '油面巾',
        'id' => 'youmianjin',
      ),
      6130 => 
      array (
        'type' => 'find',
        'name' => '油糕',
        'id' => 'yougao',
      ),
      6140 => 
      array (
        'type' => 'find',
        'name' => '油饼',
        'id' => 'youbing',
      ),
      7010 => 
      array (
        'type' => 'find',
        'name' => '香酥',
        'id' => 'xiangsu',
      ),
      7020 => 
      array (
        'type' => 'find',
        'name' => '糕',
        'id' => 'tanggao',
      ),
      7030 => 
      array (
        'type' => 'find',
        'name' => '饼食',
        'id' => 'youshi',
      ),
      7040 => 
      array (
        'type' => 'find',
        'name' => '羊腿',
        'id' => 'yangtui',
      ),
      8010 => 
      array (
        'type' => 'find',
        'name' => '斋果',
        'id' => 'zhaiguo',
      ),
      9000 => 
      array (
        'type' => 'find',
        'name' => '瓷茶碗',
        'id' => 'cucichawan',
      ),
      9010 => 
      array (
        'type' => 'find',
        'name' => '小菜',
        'id' => 'xiaocai',
      ),
      9020 => 
      array (
        'type' => 'find',
        'name' => '稀饭',
        'id' => 'xifan',
      ),
      9100 => 
      array (
        'type' => 'find',
        'name' => '炒豆',
        'id' => 'chaodou',
      ),
      9110 => 
      array (
        'type' => 'find',
        'name' => '瓜子',
        'id' => 'guazi',
      ),
      9120 => 
      array (
        'type' => 'find',
        'name' => '卤味干',
        'id' => 'luweigan',
      ),
      9130 => 
      array (
        'type' => 'find',
        'name' => '臭干',
        'id' => 'chougan',
      ),
      9140 => 
      array (
        'type' => 'find',
        'name' => '油炸干',
        'id' => 'youzhagan',
      ),
      9150 => 
      array (
        'type' => 'find',
        'name' => '豆腐羹',
        'id' => 'doufugeng',
      ),
      9160 => 
      array (
        'type' => 'find',
        'name' => '小蛋糕',
        'id' => 'dangao',
      ),
      9200 => 
      array (
        'type' => 'find',
        'name' => '白兰瓜',
        'id' => 'bailangua',
      ),
      9210 => 
      array (
        'type' => 'find',
        'name' => '番木瓜',
        'id' => 'fanmugua',
      ),
      9220 => 
      array (
        'type' => 'find',
        'name' => '木瓜',
        'id' => 'mugua',
      ),
      9230 => 
      array (
        'type' => 'find',
        'name' => '柑子',
        'id' => 'ganzi',
      ),
      9240 => 
      array (
        'type' => 'find',
        'name' => '橘子',
        'id' => 'juzi',
      ),
      9250 => 
      array (
        'type' => 'find',
        'name' => '柚子',
        'id' => 'youzi',
      ),
      9260 => 
      array (
        'type' => 'find',
        'name' => '豆芽',
        'id' => 'douya',
      ),
      9270 => 
      array (
        'type' => 'find',
        'name' => '花菜',
        'id' => 'huacai',
      ),
      9280 => 
      array (
        'type' => 'find',
        'name' => '蘑菇',
        'id' => 'mogu',
      ),
      9290 => 
      array (
        'type' => 'find',
        'name' => '黑木耳',
        'id' => 'heimuer',
      ),
      9300 => 
      array (
        'type' => 'find',
        'name' => '山笋',
        'id' => 'shansun',
      ),
      9310 => 
      array (
        'type' => 'find',
        'name' => '红蔬菜',
        'id' => 'hongxiancai',
      ),
      9320 => 
      array (
        'type' => 'find',
        'name' => '红茶',
        'id' => 'hongcha',
      ),
      9330 => 
      array (
        'type' => 'find',
        'name' => '绿茶',
        'id' => 'lucha',
      ),
      9340 => 
      array (
        'type' => 'find',
        'name' => '水壶',
        'id' => 'jiuguan',
      ),
      9350 => 
      array (
        'type' => 'find',
        'name' => '酒壶',
        'id' => 'jiuhu',
      ),
      9360 => 
      array (
        'type' => 'find',
        'name' => '酒盅',
        'id' => 'jiuzhong',
      ),
      20010 => 
      array (
        'type' => 'find',
        'name' => '猪肉',
        'id' => 'rou',
      ),
      30000 => 
      array (
        'type' => 'find',
        'name' => '雪花梨',
        'id' => 'snowglass',
      ),
      40010 => 
      array (
        'type' => 'find',
        'name' => '白饭',
        'id' => 'mifan',
      ),
      40020 => 
      array (
        'type' => 'find',
        'name' => '面巾',
        'id' => 'mianjin',
      ),
      50000 => 
      array (
        'type' => 'find',
        'name' => '猕猴桃',
        'id' => 'mihoutao',
      ),
      100000 => 
      array (
        'type' => 'find',
        'name' => '净瓶',
        'id' => 'jingping',
      ),
      800010 => 
      array (
        'type' => 'find',
        'name' => '火枣',
        'id' => 'huozao',
      ),
      800020 => 
      array (
        'type' => 'find',
        'name' => '碧藕',
        'id' => 'biou',
      ),
      800030 => 
      array (
        'type' => 'find',
        'name' => '交梨',
        'id' => 'jiaoli',
      ),
      1000010 => 
      array (
        'type' => 'find',
        'name' => '琼浆',
        'id' => 'qiongjiang',
      ),
      1000020 => 
      array (
        'type' => 'find',
        'name' => '玉液',
        'id' => 'yuye',
      ),
      1000030 => 
      array (
        'type' => 'find',
        'name' => '醍醐',
        'id' => 'tihu',
      ),
      1000040 => 
      array (
        'type' => 'find',
        'name' => '药露',
        'id' => 'yaolu',
      ),
      1000050 => 
      array (
        'type' => 'find',
        'name' => '龙肝',
        'id' => 'longgan',
      ),
      1000060 => 
      array (
        'type' => 'find',
        'name' => '凤髓',
        'id' => 'fengsui',
      ),
      1000070 => 
      array (
        'type' => 'find',
        'name' => '熊掌',
        'id' => 'xiongzhang',
      ),
      1000080 => 
      array (
        'type' => 'find',
        'name' => '杏春',
        'id' => 'xingchun',
      ),
      1500010 => 
      array (
        'type' => 'find',
        'name' => '蛇胆内丹',
        'id' => 'shelizineidan',
      ),
      1500020 => 
      array (
        'type' => 'find',
        'name' => '无极精',
        'id' => 'wujijing',
      ),
      2000010 => 
      array (
        'type' => 'find',
        'name' => '仙桃',
        'id' => 'xiantao',
      ),
      2000020 => 
      array (
        'type' => 'find',
        'name' => '仙酒',
        'id' => 'xianjiu',
      ),
    ),
    'weapon' => 
    array (
      5 => 
      array (
        'type' => 'find',
        'name' => '菜刀',
        'id' => 'caidao',
      ),
      10 => 
      array (
        'type' => 'find',
        'name' => '铁锤',
        'id' => 'tiechui',
      ),
      15 => 
      array (
        'type' => 'find',
        'name' => '铁锤',
        'id' => 'tiechui',
      ),
      20 => 
      array (
        'type' => 'find',
        'name' => '铁尺',
        'id' => 'tiechi',
      ),
      30 => 
      array (
        'type' => 'find',
        'name' => '竹棍',
        'id' => 'zhugun',
      ),
      40 => 
      array (
        'type' => 'find',
        'name' => '铁剑',
        'id' => 'tiejian',
      ),
      50 => 
      array (
        'type' => 'find',
        'name' => '菜刀',
        'id' => 'caidao',
      ),
      60 => 
      array (
        'type' => 'find',
        'name' => '竹剑',
        'id' => 'zhujian',
      ),
      70 => 
      array (
        'type' => 'find',
        'name' => '铁枪',
        'id' => 'tieqiang',
      ),
      80 => 
      array (
        'type' => 'find',
        'name' => '铁刀',
        'id' => 'tiewandao',
      ),
      90 => 
      array (
        'type' => 'find',
        'name' => '木棒',
        'id' => 'mubang',
      ),
      100 => 
      array (
        'type' => 'find',
        'name' => '铁锤',
        'id' => 'tiechui',
      ),
      150 => 
      array (
        'type' => 'find',
        'name' => '鬼树枝',
        'id' => 'guishuzhi',
      ),
      200 => 
      array (
        'type' => 'find',
        'name' => '冰铁枪',
        'id' => 'bintiegun',
      ),
      250 => 
      array (
        'type' => 'find',
        'name' => '铜刀',
        'id' => 'tongdao',
      ),
      300 => 
      array (
        'type' => 'find',
        'name' => '雪剑',
        'id' => 'xuejian',
      ),
      350 => 
      array (
        'type' => 'find',
        'name' => '铜棍',
        'id' => 'tonggun',
      ),
      400 => 
      array (
        'type' => 'find',
        'name' => '托天叉',
        'id' => 'tuotiancha',
      ),
      450 => 
      array (
        'type' => 'find',
        'name' => '钢刀',
        'id' => 'gangdao',
      ),
      500 => 
      array (
        'type' => 'find',
        'name' => '梅花锤',
        'id' => 'meihuachui',
      ),
      550 => 
      array (
        'type' => 'find',
        'name' => '钢枪',
        'id' => 'gangqiang',
      ),
      600 => 
      array (
        'type' => 'find',
        'name' => '伐木斧',
        'id' => 'famufu',
      ),
      700 => 
      array (
        'type' => 'find',
        'name' => '马棒',
        'id' => 'mabang',
      ),
      800 => 
      array (
        'type' => 'find',
        'name' => '刀',
        'id' => 'blade',
      ),
      900 => 
      array (
        'type' => 'find',
        'name' => '药锄',
        'id' => 'yaochu',
      ),
      1000 => 
      array (
        'type' => 'find',
        'name' => 'mi ji',
        'id' => 'miji',
      ),
      1500 => 
      array (
        'type' => 'find',
        'name' => '银棍',
        'id' => 'yingun',
      ),
      2000 => 
      array (
        'type' => 'find',
        'name' => '金刀',
        'id' => 'jindao',
      ),
      2500 => 
      array (
        'type' => 'find',
        'name' => '金剑',
        'id' => 'jinjian',
      ),
      3000 => 
      array (
        'type' => 'find',
        'name' => '金枪',
        'id' => 'jinqiang',
      ),
      3500 => 
      array (
        'type' => 'find',
        'name' => '金棍',
        'id' => 'jingun',
      ),
      4000 => 
      array (
        'type' => 'find',
        'name' => '金棒',
        'id' => 'jinbang',
      ),
      5000 => 
      array (
        'type' => 'find',
        'name' => '鸡刀',
        'id' => 'jidao',
      ),
      6000 => 
      array (
        'type' => 'find',
        'name' => '宝剑',
        'id' => 'baojian',
      ),
      7000 => 
      array (
        'type' => 'find',
        'name' => '银匕首',
        'id' => 'yinbishou',
      ),
      8000 => 
      array (
        'type' => 'find',
        'name' => '剃刀',
        'id' => 'tidao',
      ),
      9000 => 
      array (
        'type' => 'find',
        'name' => '巨斧',
        'id' => 'jufu',
      ),
      10000 => 
      array (
        'type' => 'find',
        'name' => '神刀',
        'id' => 'shendao',
      ),
      11000 => 
      array (
        'type' => 'find',
        'name' => '紫薇剑',
        'id' => 'ziweijian',
      ),
      12000 => 
      array (
        'type' => 'find',
        'name' => '枯松杖',
        'id' => 'kusongzhang',
      ),
      13000 => 
      array (
        'type' => 'find',
        'name' => '碧落枪',
        'id' => 'biluoqiang',
      ),
      14000 => 
      array (
        'type' => 'find',
        'name' => '花刺叉',
        'id' => 'huaicha',
      ),
      15000 => 
      array (
        'type' => 'find',
        'name' => '柳叶刀',
        'id' => 'liuyedao',
      ),
      16000 => 
      array (
        'type' => 'find',
        'name' => '鹿皮鞭',
        'id' => 'lupibian',
      ),
      17000 => 
      array (
        'type' => 'find',
        'name' => '仙枪',
        'id' => 'xianqiang',
      ),
      18000 => 
      array (
        'type' => 'find',
        'name' => '仙棍',
        'id' => 'xiangun',
      ),
      19000 => 
      array (
        'type' => 'find',
        'name' => '仙棒',
        'id' => 'xianbang',
      ),
      20000 => 
      array (
        'type' => 'find',
        'name' => '圣刀',
        'id' => 'shengdao',
      ),
      21000 => 
      array (
        'type' => 'find',
        'name' => '燕云刀',
        'id' => 'yanyundaodao',
      ),
      22000 => 
      array (
        'type' => 'find',
        'name' => '钢叉',
        'id' => 'gangcha',
      ),
      23000 => 
      array (
        'type' => 'find',
        'name' => '叉',
        'id' => 'cha',
      ),
      24000 => 
      array (
        'type' => 'find',
        'name' => '叉',
        'id' => 'cha',
      ),
      25000 => 
      array (
        'type' => 'find',
        'name' => '青石锤',
        'id' => 'qingshichui',
      ),
      26000 => 
      array (
        'type' => 'find',
        'name' => '百节鞭',
        'id' => 'baijielian',
      ),
      27000 => 
      array (
        'type' => 'find',
        'name' => '天枪',
        'id' => 'tianqiang',
      ),
      28000 => 
      array (
        'type' => 'find',
        'name' => '天棍',
        'id' => 'tiangun',
      ),
      29000 => 
      array (
        'type' => 'find',
        'name' => '天棒',
        'id' => 'tianbang',
      ),
      30000 => 
      array (
        'type' => 'find',
        'name' => '魔刀',
        'id' => 'modao',
      ),
      31000 => 
      array (
        'type' => 'find',
        'name' => '八角鞭',
        'id' => 'bajiaobian',
      ),
      32000 => 
      array (
        'type' => 'find',
        'name' => '杨树拐',
        'id' => 'yangshuguai',
      ),
      33000 => 
      array (
        'type' => 'find',
        'name' => '柳条鞭',
        'id' => 'liutiaobian',
      ),
      34000 => 
      array (
        'type' => 'find',
        'name' => '鹿角叉',
        'id' => 'lujiacha',
      ),
      35000 => 
      array (
        'type' => 'find',
        'name' => '红缨枪',
        'id' => 'hongyingqiang',
      ),
      36000 => 
      array (
        'type' => 'find',
        'name' => '豹皮鞭',
        'id' => 'bopibian',
      ),
      37000 => 
      array (
        'type' => 'find',
        'name' => '青石锤',
        'id' => 'qingshichui',
      ),
      38000 => 
      array (
        'type' => 'find',
        'name' => '混铁杖',
        'id' => 'huntiezhang',
      ),
      39000 => 
      array (
        'type' => 'find',
        'name' => '牛角刃',
        'id' => 'niujiaoren',
      ),
      40000 => 
      array (
        'type' => 'find',
        'name' => '鬼刀',
        'id' => 'guidao',
      ),
      41000 => 
      array (
        'type' => 'find',
        'name' => '金锤',
        'id' => 'jinchui',
      ),
      42000 => 
      array (
        'type' => 'find',
        'name' => '短弯刀',
        'id' => 'duanwandao',
      ),
      43000 => 
      array (
        'type' => 'find',
        'name' => '牛骨棒',
        'id' => 'niugubang',
      ),
      44000 => 
      array (
        'type' => 'find',
        'name' => '牛角叉',
        'id' => 'niujiacha',
      ),
      45000 => 
      array (
        'type' => 'find',
        'name' => '牛皮鞭',
        'id' => 'niupibian',
      ),
      46000 => 
      array (
        'type' => 'find',
        'name' => '牛角刃',
        'id' => 'niujiaoren',
      ),
      47000 => 
      array (
        'type' => 'find',
        'name' => '蝎螯钳',
        'id' => 'xieaoqian',
      ),
      48000 => 
      array (
        'type' => 'find',
        'name' => '石墨锤',
        'id' => 'shimuchui',
      ),
      49000 => 
      array (
        'type' => 'find',
        'name' => '龙棒',
        'id' => 'longbang',
      ),
      50000 => 
      array (
        'type' => 'find',
        'name' => '凤刀',
        'id' => 'fengdao',
      ),
      51000 => 
      array (
        'type' => 'find',
        'name' => '金刀',
        'id' => 'jindao',
      ),
      52000 => 
      array (
        'type' => 'find',
        'name' => '斩头刀',
        'id' => 'zhantoudao',
      ),
      53000 => 
      array (
        'type' => 'find',
        'name' => '铁链',
        'id' => 'tielian',
      ),
      54000 => 
      array (
        'type' => 'find',
        'name' => '铁爪',
        'id' => 'tiezhua',
      ),
      55000 => 
      array (
        'type' => 'find',
        'name' => '象牙剑',
        'id' => 'xiangyajian',
      ),
      56000 => 
      array (
        'type' => 'find',
        'name' => '龙须叉',
        'id' => 'longxucha',
      ),
      57000 => 
      array (
        'type' => 'find',
        'name' => '凤尾鞭',
        'id' => 'fengweibian',
      ),
      58000 => 
      array (
        'type' => 'find',
        'name' => '鸳鸯棍',
        'id' => 'yuanyanggun',
      ),
      59000 => 
      array (
        'type' => 'find',
        'name' => '虎棒',
        'id' => 'hubang',
      ),
      60000 => 
      array (
        'type' => 'find',
        'name' => '鹰刀',
        'id' => 'yingdao',
      ),
      61000 => 
      array (
        'type' => 'find',
        'name' => '鹰剑',
        'id' => 'yingjian',
      ),
      62000 => 
      array (
        'type' => 'find',
        'name' => '拂尘',
        'id' => 'fuchen',
      ),
      63000 => 
      array (
        'type' => 'find',
        'name' => '七尺耙',
        'id' => 'qichipa',
      ),
      64000 => 
      array (
        'type' => 'find',
        'name' => '戒刀',
        'id' => 'jiedao',
      ),
      65000 => 
      array (
        'type' => 'find',
        'name' => '青铜叉',
        'id' => 'qingtongcha',
      ),
      66000 => 
      array (
        'type' => 'find',
        'name' => '法锤',
        'id' => 'fachui',
      ),
      67000 => 
      array (
        'type' => 'find',
        'name' => '紫云剑',
        'id' => 'ziyunjian',
      ),
      68000 => 
      array (
        'type' => 'find',
        'name' => '烧火棍',
        'id' => 'shaohuogun',
      ),
      69000 => 
      array (
        'type' => 'find',
        'name' => '蛇棒',
        'id' => 'shebang',
      ),
      70000 => 
      array (
        'type' => 'find',
        'name' => '鹤刀',
        'id' => 'hedao',
      ),
      71000 => 
      array (
        'type' => 'find',
        'name' => '银叉',
        'id' => 'yincha',
      ),
      72000 => 
      array (
        'type' => 'find',
        'name' => '猪形耙',
        'id' => 'zhuxingpa',
      ),
      73000 => 
      array (
        'type' => 'find',
        'name' => '双头矛',
        'id' => 'shuangtoumao',
      ),
      74000 => 
      array (
        'type' => 'find',
        'name' => '戈剑',
        'id' => 'gejian',
      ),
      75000 => 
      array (
        'type' => 'find',
        'name' => '新月刀',
        'id' => 'xinyuedao',
      ),
      76000 => 
      array (
        'type' => 'find',
        'name' => '熊剑',
        'id' => 'xiongjian',
      ),
      77000 => 
      array (
        'type' => 'find',
        'name' => '熊枪',
        'id' => 'xiongqiang',
      ),
      78000 => 
      array (
        'type' => 'find',
        'name' => '熊棍',
        'id' => 'xionggun',
      ),
      79000 => 
      array (
        'type' => 'find',
        'name' => '熊棒',
        'id' => 'xiongbang',
      ),
      80000 => 
      array (
        'type' => 'find',
        'name' => '狼刀',
        'id' => 'langdao',
      ),
      81000 => 
      array (
        'type' => 'find',
        'name' => '五凤剑',
        'id' => 'wufengjian',
      ),
      82000 => 
      array (
        'type' => 'find',
        'name' => '竹杖',
        'id' => 'zhuzhang',
      ),
      83000 => 
      array (
        'type' => 'find',
        'name' => '龙头拐',
        'id' => 'longtouguai',
      ),
      84000 => 
      array (
        'type' => 'find',
        'name' => '铁扇',
        'id' => 'tieshan',
      ),
      85000 => 
      array (
        'type' => 'find',
        'name' => '豹刀',
        'id' => 'baodao2',
      ),
      86000 => 
      array (
        'type' => 'find',
        'name' => '豹剑',
        'id' => 'baojian2',
      ),
      87000 => 
      array (
        'type' => 'find',
        'name' => '豹枪',
        'id' => 'baoqiang2',
      ),
      88000 => 
      array (
        'type' => 'find',
        'name' => '豹棍',
        'id' => 'baogun2',
      ),
      89000 => 
      array (
        'type' => 'find',
        'name' => '豹棒',
        'id' => 'baobang2',
      ),
      90000 => 
      array (
        'type' => 'find',
        'name' => '鲨刀',
        'id' => 'shadao',
      ),
      91000 => 
      array (
        'type' => 'find',
        'name' => '三尺钢叉',
        'id' => 'sanchigangcha',
      ),
      92000 => 
      array (
        'type' => 'find',
        'name' => '鲨枪',
        'id' => 'shaqiang',
      ),
      93000 => 
      array (
        'type' => 'find',
        'name' => '鲨棍',
        'id' => 'shagun',
      ),
      94000 => 
      array (
        'type' => 'find',
        'name' => '鲨棒',
        'id' => 'shabang',
      ),
      95000 => 
      array (
        'type' => 'find',
        'name' => '鲸刀',
        'id' => 'jingdao',
      ),
      96000 => 
      array (
        'type' => 'find',
        'name' => '鲸剑',
        'id' => 'jingjian',
      ),
      97000 => 
      array (
        'type' => 'find',
        'name' => '鲸枪',
        'id' => 'jingqiang',
      ),
      98000 => 
      array (
        'type' => 'find',
        'name' => '鲸棍',
        'id' => 'jinggun',
      ),
      99000 => 
      array (
        'type' => 'find',
        'name' => '鲸棒',
        'id' => 'jingbang',
      ),
      100000 => 
      array (
        'type' => 'find',
        'name' => '小金箍棒',
        'id' => 'xiaojingubang',
      ),
    ),
    'armor' => 
    array (
      5 => 
      array (
        'type' => 'find',
        'name' => '铁盾',
        'id' => 'tiedun',
      ),
      10 => 
      array (
        'type' => 'find',
        'name' => '铁甲',
        'id' => 'tiejia',
      ),
      15 => 
      array (
        'type' => 'find',
        'name' => '铁盔',
        'id' => 'tiekui',
      ),
      20 => 
      array (
        'type' => 'find',
        'name' => '麻布',
        'id' => 'mabu',
      ),
      25 => 
      array (
        'type' => 'find',
        'name' => '铁手套',
        'id' => 'tieshoutao',
      ),
      30 => 
      array (
        'type' => 'find',
        'name' => '铁护腕',
        'id' => 'tiehuwan',
      ),
      35 => 
      array (
        'type' => 'find',
        'name' => '铁护腿',
        'id' => 'tiehutui',
      ),
      40 => 
      array (
        'type' => 'find',
        'name' => '皮背心',
        'id' => 'pibeixin',
      ),
      45 => 
      array (
        'type' => 'find',
        'name' => '铁护心',
        'id' => 'tiehuxin',
      ),
      50 => 
      array (
        'type' => 'find',
        'name' => '铜盾',
        'id' => 'tongdun',
      ),
      60 => 
      array (
        'type' => 'find',
        'name' => '皮袍',
        'id' => 'pipao',
      ),
      70 => 
      array (
        'type' => 'find',
        'name' => '铜盔',
        'id' => 'tongkui',
      ),
      80 => 
      array (
        'type' => 'find',
        'name' => '铜靴',
        'id' => 'tongxue',
      ),
      90 => 
      array (
        'type' => 'find',
        'name' => '铜手套',
        'id' => 'tongshoutao',
      ),
      100 => 
      array (
        'type' => 'find',
        'name' => '布',
        'id' => 'cloth',
      ),
      110 => 
      array (
        'type' => 'find',
        'name' => '铜护腿',
        'id' => 'tonghutui',
      ),
      120 => 
      array (
        'type' => 'find',
        'name' => '铜护肩',
        'id' => 'tonghujian',
      ),
      130 => 
      array (
        'type' => 'find',
        'name' => '铜护心',
        'id' => 'tonghuxin',
      ),
      150 => 
      array (
        'type' => 'find',
        'name' => '霓裳',
        'id' => 'nichang',
      ),
      200 => 
      array (
        'type' => 'find',
        'name' => '马褂',
        'id' => 'magua',
      ),
      250 => 
      array (
        'type' => 'find',
        'name' => '袄',
        'id' => 'ao',
      ),
      300 => 
      array (
        'type' => 'find',
        'name' => '绸袍',
        'id' => 'choupao',
      ),
      350 => 
      array (
        'type' => 'find',
        'name' => '长袍',
        'id' => 'changpao',
      ),
      400 => 
      array (
        'type' => 'find',
        'name' => '皮盾',
        'id' => 'pidun',
      ),
      450 => 
      array (
        'type' => 'find',
        'name' => '藤甲',
        'id' => 'tengjia',
      ),
      500 => 
      array (
        'type' => 'find',
        'name' => '皮靴',
        'id' => 'pixue',
      ),
      550 => 
      array (
        'type' => 'find',
        'name' => '花靴',
        'id' => 'huaxue',
      ),
      600 => 
      array (
        'type' => 'find',
        'name' => '布',
        'id' => 'cloth',
      ),
      700 => 
      array (
        'type' => 'find',
        'name' => '霓裳',
        'id' => 'nichang',
      ),
      800 => 
      array (
        'type' => 'find',
        'name' => '银盔',
        'id' => 'yinkui',
      ),
      900 => 
      array (
        'type' => 'find',
        'name' => '银靴',
        'id' => 'yinxue',
      ),
      1000 => 
      array (
        'type' => 'find',
        'name' => '官袍',
        'id' => 'guanpao',
      ),
      1200 => 
      array (
        'type' => 'find',
        'name' => '粉布',
        'id' => 'fenbu',
      ),
      1400 => 
      array (
        'type' => 'find',
        'name' => '皮毯',
        'id' => 'pitan',
      ),
      1600 => 
      array (
        'type' => 'find',
        'name' => '彩色皮毯',
        'id' => 'caiseptan',
      ),
      1800 => 
      array (
        'type' => 'find',
        'name' => '长袍',
        'id' => 'changpao',
      ),
      2000 => 
      array (
        'type' => 'find',
        'name' => '裙',
        'id' => 'qun',
      ),
      2500 => 
      array (
        'type' => 'find',
        'name' => '金甲',
        'id' => 'jinjia',
      ),
      3000 => 
      array (
        'type' => 'find',
        'name' => '犀牛皮衣',
        'id' => 'xiniupiyi',
      ),
      3500 => 
      array (
        'type' => 'find',
        'name' => '金靴',
        'id' => 'jinxue',
      ),
      4000 => 
      array (
        'type' => 'find',
        'name' => '金手套',
        'id' => 'jinshoutao',
      ),
      4500 => 
      array (
        'type' => 'find',
        'name' => '金护腕',
        'id' => 'jinhuwan',
      ),
      5000 => 
      array (
        'type' => 'find',
        'name' => '犀皮背心',
        'id' => 'xipibeixin',
      ),
      5500 => 
      array (
        'type' => 'find',
        'name' => '牛皮衣',
        'id' => 'niupiyi',
      ),
      6000 => 
      array (
        'type' => 'find',
        'name' => '熊皮短袍',
        'id' => 'xiongpiduampao',
      ),
      7000 => 
      array (
        'type' => 'find',
        'name' => '战袍',
        'id' => 'zhanpao',
      ),
      8000 => 
      array (
        'type' => 'find',
        'name' => '兽皮裙',
        'id' => 'shoupiiqun',
      ),
      9000 => 
      array (
        'type' => 'find',
        'name' => '宝盔',
        'id' => 'baokui',
      ),
      10000 => 
      array (
        'type' => 'find',
        'name' => '铁甲',
        'id' => 'tiejia',
      ),
      11000 => 
      array (
        'type' => 'find',
        'name' => 'baipao',
        'id' => 'baipao',
      ),
      12000 => 
      array (
        'type' => 'find',
        'name' => '铜甲',
        'id' => 'tongjia',
      ),
      13000 => 
      array (
        'type' => 'find',
        'name' => '狼皮裙',
        'id' => 'langpiqun',
      ),
      14000 => 
      array (
        'type' => 'find',
        'name' => '宝护肩',
        'id' => 'baohujian',
      ),
      15000 => 
      array (
        'type' => 'find',
        'name' => '金甲',
        'id' => 'jinjia',
      ),
      16000 => 
      array (
        'type' => 'find',
        'name' => '神盾',
        'id' => 'shendun',
      ),
      18000 => 
      array (
        'type' => 'find',
        'name' => '神甲',
        'id' => 'shenjia',
      ),
      20000 => 
      array (
        'type' => 'find',
        'name' => '黑皮皮',
        'id' => 'heipipi',
      ),
      22000 => 
      array (
        'type' => 'find',
        'name' => '青背皮',
        'id' => 'qingbeipi',
      ),
      24000 => 
      array (
        'type' => 'find',
        'name' => '神手套',
        'id' => 'shenshoutao',
      ),
      26000 => 
      array (
        'type' => 'find',
        'name' => '神护腕',
        'id' => 'shenhuwan',
      ),
      28000 => 
      array (
        'type' => 'find',
        'name' => '神护腿',
        'id' => 'shenhutui',
      ),
      30000 => 
      array (
        'type' => 'find',
        'name' => '铁头靴',
        'id' => 'tietouxue',
      ),
      32000 => 
      array (
        'type' => 'find',
        'name' => '铁爪套',
        'id' => 'tiezhuatao',
      ),
      34000 => 
      array (
        'type' => 'find',
        'name' => '铁手套',
        'id' => 'tieshoutao',
      ),
      36000 => 
      array (
        'type' => 'find',
        'name' => '铁头盔',
        'id' => 'tietoukui',
      ),
      38000 => 
      array (
        'type' => 'find',
        'name' => '铁护肩',
        'id' => 'tiehujian',
      ),
      40000 => 
      array (
        'type' => 'find',
        'name' => '铁护腰',
        'id' => 'tiehuyao',
      ),
      42000 => 
      array (
        'type' => 'find',
        'name' => '铁护腕',
        'id' => 'tiehuwan',
      ),
      44000 => 
      array (
        'type' => 'find',
        'name' => '仙护腕',
        'id' => 'xianhuwan',
      ),
      46000 => 
      array (
        'type' => 'find',
        'name' => '虎皮大氅',
        'id' => 'hupidachang',
      ),
      48000 => 
      array (
        'type' => 'find',
        'name' => '野象皮',
        'id' => 'yexiangpi',
      ),
      50000 => 
      array (
        'type' => 'find',
        'name' => '王八甲',
        'id' => 'wangbajia',
      ),
      55000 => 
      array (
        'type' => 'find',
        'name' => '铠甲',
        'id' => 'kaijia',
      ),
      60000 => 
      array (
        'type' => 'find',
        'name' => '铠甲',
        'id' => 'kaijia',
      ),
      65000 => 
      array (
        'type' => 'find',
        'name' => '黑流藤甲',
        'id' => 'heiliutengjia',
      ),
      70000 => 
      array (
        'type' => 'find',
        'name' => '犀牛皮盾',
        'id' => 'xiniupidun',
      ),
      75000 => 
      array (
        'type' => 'find',
        'name' => '圣手套',
        'id' => 'shengshoutao',
      ),
      80000 => 
      array (
        'type' => 'find',
        'name' => '裙',
        'id' => 'qun',
      ),
      85000 => 
      array (
        'type' => 'find',
        'name' => '圣护腿',
        'id' => 'shenghutui',
      ),
      90000 => 
      array (
        'type' => 'find',
        'name' => '圣护肩',
        'id' => 'shenghujian',
      ),
      95000 => 
      array (
        'type' => 'find',
        'name' => '圣护心',
        'id' => 'shenghuxin',
      ),
      100000 => 
      array (
        'type' => 'find',
        'name' => '天盾',
        'id' => 'tiandun',
      ),
    ),
    'cloth' => 
    array (
      5 => 
      array (
        'type' => 'find',
        'name' => '布衣',
        'id' => 'buyi',
      ),
      10 => 
      array (
        'type' => 'find',
        'name' => '麻布',
        'id' => 'mabu',
      ),
      15 => 
      array (
        'type' => 'find',
        'name' => '缎衣',
        'id' => 'duanyi',
      ),
      20 => 
      array (
        'type' => 'find',
        'name' => '皮背心',
        'id' => 'pibeixin',
      ),
      25 => 
      array (
        'type' => 'find',
        'name' => '丝衣',
        'id' => 'siyi',
      ),
      30 => 
      array (
        'type' => 'find',
        'name' => '皮袍',
        'id' => 'pipao',
      ),
      40 => 
      array (
        'type' => 'find',
        'name' => '棉衣',
        'id' => 'mianyi',
      ),
      50 => 
      array (
        'type' => 'find',
        'name' => '皮衣',
        'id' => 'piyi',
      ),
      60 => 
      array (
        'type' => 'find',
        'name' => '单衣',
        'id' => 'danyi',
      ),
      70 => 
      array (
        'type' => 'find',
        'name' => '夹衣',
        'id' => 'jiayi',
      ),
      80 => 
      array (
        'type' => 'find',
        'name' => '短衣',
        'id' => 'duanyi2',
      ),
      90 => 
      array (
        'type' => 'find',
        'name' => '长衣',
        'id' => 'changyi',
      ),
      100 => 
      array (
        'type' => 'find',
        'name' => '布',
        'id' => 'cloth',
      ),
      150 => 
      array (
        'type' => 'find',
        'name' => '布裙',
        'id' => 'buqun',
      ),
      200 => 
      array (
        'type' => 'find',
        'name' => '霓裳',
        'id' => 'nichang',
      ),
      250 => 
      array (
        'type' => 'find',
        'name' => '缎裙',
        'id' => 'duanqun',
      ),
      300 => 
      array (
        'type' => 'find',
        'name' => '马褂',
        'id' => 'magua',
      ),
      350 => 
      array (
        'type' => 'find',
        'name' => '丝裙',
        'id' => 'siqun',
      ),
      400 => 
      array (
        'type' => 'find',
        'name' => '麻裙',
        'id' => 'maqun',
      ),
      450 => 
      array (
        'type' => 'find',
        'name' => '棉裙',
        'id' => 'mianqun',
      ),
      500 => 
      array (
        'type' => 'find',
        'name' => '袄',
        'id' => 'ao',
      ),
      600 => 
      array (
        'type' => 'find',
        'name' => '绸袍',
        'id' => 'choupao',
      ),
      700 => 
      array (
        'type' => 'find',
        'name' => '长袍',
        'id' => 'changpao',
      ),
      800 => 
      array (
        'type' => 'find',
        'name' => '短裙',
        'id' => 'duanqun2',
      ),
      900 => 
      array (
        'type' => 'find',
        'name' => '长裙',
        'id' => 'changqun',
      ),
      1000 => 
      array (
        'type' => 'find',
        'name' => '官袍',
        'id' => 'guanpao',
      ),
      1500 => 
      array (
        'type' => 'find',
        'name' => '布裤',
        'id' => 'buku',
      ),
      2000 => 
      array (
        'type' => 'find',
        'name' => '粉布',
        'id' => 'fenbu',
      ),
      2500 => 
      array (
        'type' => 'find',
        'name' => '缎裤',
        'id' => 'duanku',
      ),
      3000 => 
      array (
        'type' => 'find',
        'name' => '粉裙',
        'id' => 'fenqun',
      ),
      3500 => 
      array (
        'type' => 'find',
        'name' => '丝裤',
        'id' => 'siku',
      ),
      4000 => 
      array (
        'type' => 'find',
        'name' => '长袍',
        'id' => 'changpao',
      ),
      4500 => 
      array (
        'type' => 'find',
        'name' => '棉裤',
        'id' => 'mianku',
      ),
      5000 => 
      array (
        'type' => 'give',
        'name' => 'zai min',
        'id' => 'zaimin',
      ),
      6000 => 
      array (
        'type' => 'find',
        'name' => '单裤',
        'id' => 'danku',
      ),
      7000 => 
      array (
        'type' => 'find',
        'name' => '夹裤',
        'id' => 'jiaku',
      ),
      8000 => 
      array (
        'type' => 'find',
        'name' => '短裤',
        'id' => 'duanku2',
      ),
      9000 => 
      array (
        'type' => 'find',
        'name' => '战袍',
        'id' => 'zhanpao',
      ),
      10000 => 
      array (
        'type' => 'find',
        'name' => '八卦袍',
        'id' => 'baguapao',
      ),
      15000 => 
      array (
        'type' => 'find',
        'name' => '布袍',
        'id' => 'bupao',
      ),
      20000 => 
      array (
        'type' => 'find',
        'name' => '狼皮裙',
        'id' => 'langpiqun',
      ),
      25000 => 
      array (
        'type' => 'find',
        'name' => '缎袍',
        'id' => 'duanpao',
      ),
      30000 => 
      array (
        'type' => 'find',
        'name' => '锦袍',
        'id' => 'jinpao',
      ),
      35000 => 
      array (
        'type' => 'find',
        'name' => '丝袍',
        'id' => 'sipao',
      ),
      40000 => 
      array (
        'type' => 'find',
        'name' => '麻袍',
        'id' => 'mapao',
      ),
      45000 => 
      array (
        'type' => 'find',
        'name' => '柴皮',
        'id' => 'chaipi',
      ),
      50000 => 
      array (
        'type' => 'find',
        'name' => '皮袍',
        'id' => 'pipao',
      ),
      60000 => 
      array (
        'type' => 'find',
        'name' => '虎皮袄',
        'id' => 'hupiao',
      ),
      70000 => 
      array (
        'type' => 'find',
        'name' => '蟒袍',
        'id' => 'mangpao',
      ),
      80000 => 
      array (
        'type' => 'find',
        'name' => '裙',
        'id' => 'qun',
      ),
      90000 => 
      array (
        'type' => 'find',
        'name' => '布',
        'id' => 'cloth',
      ),
      100000 => 
      array (
        'type' => 'find',
        'name' => '大袍',
        'id' => 'dapao',
      ),
    ),
    'wearing' => 
    array (
      5 => 
      array (
        'type' => 'find',
        'name' => '玉佩',
        'id' => 'yupei',
      ),
      10 => 
      array (
        'type' => 'find',
        'name' => '金钗',
        'id' => 'jinchai',
      ),
      15 => 
      array (
        'type' => 'find',
        'name' => '银簪',
        'id' => 'yinzan',
      ),
      20 => 
      array (
        'type' => 'find',
        'name' => '皮靴',
        'id' => 'pixue',
      ),
      25 => 
      array (
        'type' => 'find',
        'name' => '翡翠',
        'id' => 'feicui',
      ),
      30 => 
      array (
        'type' => 'find',
        'name' => '花靴',
        'id' => 'huaxue',
      ),
      35 => 
      array (
        'type' => 'find',
        'name' => '琥珀',
        'id' => 'hupo',
      ),
      40 => 
      array (
        'type' => 'find',
        'name' => '五灵巾',
        'id' => 'wulingjin',
      ),
      45 => 
      array (
        'type' => 'find',
        'name' => '水晶',
        'id' => 'shuijing',
      ),
      50 => 
      array (
        'type' => 'find',
        'name' => '钻石',
        'id' => 'zuanshi',
      ),
      60 => 
      array (
        'type' => 'find',
        'name' => '红宝石',
        'id' => 'hongbaoshi',
      ),
      70 => 
      array (
        'type' => 'find',
        'name' => '蓝宝石',
        'id' => 'lanbaoshi',
      ),
      80 => 
      array (
        'type' => 'find',
        'name' => '绿宝石',
        'id' => 'lvbaoshi',
      ),
      90 => 
      array (
        'type' => 'find',
        'name' => '紫宝石',
        'id' => 'zibaoshi',
      ),
      100 => 
      array (
        'type' => 'find',
        'name' => '帽子',
        'id' => 'maozi',
      ),
      150 => 
      array (
        'type' => 'find',
        'name' => '金戒指',
        'id' => 'jinjiezhi',
      ),
      200 => 
      array (
        'type' => 'find',
        'name' => '银戒指',
        'id' => 'yinjiezhi',
      ),
      250 => 
      array (
        'type' => 'find',
        'name' => '玉戒指',
        'id' => 'yujiezhi',
      ),
      300 => 
      array (
        'type' => 'find',
        'name' => '种子',
        'id' => 'zhongzi',
      ),
      350 => 
      array (
        'type' => 'find',
        'name' => '橙发',
        'id' => 'chengfa',
      ),
      400 => 
      array (
        'type' => 'find',
        'name' => '玉项链',
        'id' => 'yuxianglian',
      ),
      450 => 
      array (
        'type' => 'find',
        'name' => '金手镯',
        'id' => 'jinshouzhuo',
      ),
      500 => 
      array (
        'type' => 'find',
        'name' => '银手镯',
        'id' => 'yinshouzhuo',
      ),
      550 => 
      array (
        'type' => 'find',
        'name' => '玉手镯',
        'id' => 'yushouzhuo',
      ),
      600 => 
      array (
        'type' => 'find',
        'name' => '金耳环',
        'id' => 'jinerhuan',
      ),
      700 => 
      array (
        'type' => 'find',
        'name' => '银耳环',
        'id' => 'yinerhuan',
      ),
      800 => 
      array (
        'type' => 'find',
        'name' => '玉耳环',
        'id' => 'yuerhuan',
      ),
      900 => 
      array (
        'type' => 'find',
        'name' => '金步摇',
        'id' => 'jinbuyao',
      ),
      1000 => 
      array (
        'type' => 'find',
        'name' => 'mi ji',
        'id' => 'miji',
      ),
      1500 => 
      array (
        'type' => 'find',
        'name' => '流金腕链',
        'id' => 'liujinwanlian',
      ),
      2000 => 
      array (
        'type' => 'find',
        'name' => '金花',
        'id' => 'jinhua',
      ),
      2500 => 
      array (
        'type' => 'find',
        'name' => '银花',
        'id' => 'yinhua',
      ),
      3000 => 
      array (
        'type' => 'find',
        'name' => '腰牌',
        'id' => 'yaopai',
      ),
      3500 => 
      array (
        'type' => 'find',
        'name' => '金冠',
        'id' => 'jingguan',
      ),
      4000 => 
      array (
        'type' => 'find',
        'name' => '银冠',
        'id' => 'yingguan',
      ),
      4500 => 
      array (
        'type' => 'find',
        'name' => '丝帽',
        'id' => 'simao',
      ),
      5000 => 
      array (
        'type' => 'give',
        'name' => 'zai min',
        'id' => 'zaimin',
      ),
      6000 => 
      array (
        'type' => 'find',
        'name' => '银带',
        'id' => 'yindai',
      ),
      7000 => 
      array (
        'type' => 'find',
        'name' => '玉带',
        'id' => 'yudai',
      ),
      8000 => 
      array (
        'type' => 'find',
        'name' => '瞌睡虫',
        'id' => 'keshuihong',
      ),
      9000 => 
      array (
        'type' => 'find',
        'name' => '银靴',
        'id' => 'yinxue',
      ),
      10000 => 
      array (
        'type' => 'find',
        'name' => '玉佩',
        'id' => 'yupei',
      ),
      15000 => 
      array (
        'type' => 'find',
        'name' => '金披风',
        'id' => 'jinpifeng',
      ),
      20000 => 
      array (
        'type' => 'find',
        'name' => '银披风',
        'id' => 'yinpifeng',
      ),
      25000 => 
      array (
        'type' => 'find',
        'name' => '玉披风',
        'id' => 'yupifeng',
      ),
      30000 => 
      array (
        'type' => 'find',
        'name' => '金腰带',
        'id' => 'jinyaodai',
      ),
      35000 => 
      array (
        'type' => 'find',
        'name' => '银腰带',
        'id' => 'yinyaodai',
      ),
      40000 => 
      array (
        'type' => 'find',
        'name' => '玉腰带',
        'id' => 'yuyaodai',
      ),
      45000 => 
      array (
        'type' => 'find',
        'name' => '金护符',
        'id' => 'jinhufu',
      ),
      50000 => 
      array (
        'type' => 'find',
        'name' => '银护符',
        'id' => 'yinhufu',
      ),
      55000 => 
      array (
        'type' => 'find',
        'name' => '玉护符',
        'id' => 'yuhufu',
      ),
      60000 => 
      array (
        'type' => 'find',
        'name' => '金铃铛',
        'id' => 'jinlingdang',
      ),
      65000 => 
      array (
        'type' => 'find',
        'name' => '银铃铛',
        'id' => 'yinlingdang',
      ),
      70000 => 
      array (
        'type' => 'find',
        'name' => '玉铃铛',
        'id' => 'yulingdang',
      ),
      75000 => 
      array (
        'type' => 'find',
        'name' => '金扇',
        'id' => 'jinshan',
      ),
      80000 => 
      array (
        'type' => 'find',
        'name' => '珍珠',
        'id' => 'zhenzhu',
      ),
      85000 => 
      array (
        'type' => 'find',
        'name' => '玉扇',
        'id' => 'yushan',
      ),
      90000 => 
      array (
        'type' => 'find',
        'name' => '金伞',
        'id' => 'jinsan',
      ),
      95000 => 
      array (
        'type' => 'find',
        'name' => '银伞',
        'id' => 'yinsan',
      ),
      100000 => 
      array (
        'type' => 'find',
        'name' => '玉圭',
        'id' => 'yugui',
      ),
    ),
    'misc' => 
    array (
      50 => 
      array (
        'type' => 'find',
        'name' => '刀',
        'id' => 'blade',
      ),
      110 => 
      array (
        'type' => 'find',
        'name' => '坛子',
        'id' => 'tanzi',
      ),
      120 => 
      array (
        'type' => 'find',
        'name' => '油瓶',
        'id' => 'youping',
      ),
      130 => 
      array (
        'type' => 'find',
        'name' => '包',
        'id' => 'bag',
      ),
      140 => 
      array (
        'type' => 'find',
        'name' => '饭盒',
        'id' => 'fanhe',
      ),
      210 => 
      array (
        'type' => 'find',
        'name' => '扫帚',
        'id' => 'broom',
      ),
      220 => 
      array (
        'type' => 'find',
        'name' => '雪花梨',
        'id' => 'snowglass',
      ),
      330 => 
      array (
        'type' => 'find',
        'name' => '尺',
        'id' => 'ruler',
      ),
      1100 => 
      array (
        'type' => 'find',
        'name' => '挂毯',
        'id' => 'guatan',
      ),
      1200 => 
      array (
        'type' => 'find',
        'name' => '西湖',
        'id' => 'xihu',
      ),
      1300 => 
      array (
        'type' => 'find',
        'name' => '西碗',
        'id' => 'xiwan',
      ),
      1400 => 
      array (
        'type' => 'find',
        'name' => '椅子',
        'id' => 'yizi',
      ),
      1500 => 
      array (
        'type' => 'find',
        'name' => '桌子',
        'id' => 'zhuozi',
      ),
      2000 => 
      array (
        'type' => 'find',
        'name' => '郁金',
        'id' => 'yujin',
      ),
      3000 => 
      array (
        'type' => 'find',
        'name' => '水罐',
        'id' => 'shuiguan',
      ),
      4100 => 
      array (
        'type' => 'find',
        'name' => '油葫芦',
        'id' => 'youhulu',
      ),
      4200 => 
      array (
        'type' => 'find',
        'name' => '青竹筒',
        'id' => 'qingzhutong',
      ),
      5100 => 
      array (
        'type' => 'find',
        'name' => '竹楼',
        'id' => 'zhulou',
      ),
      5200 => 
      array (
        'type' => 'find',
        'name' => '水壶',
        'id' => 'jiuguan',
      ),
      5300 => 
      array (
        'type' => 'find',
        'name' => '酒壶',
        'id' => 'jiuhu',
      ),
      5400 => 
      array (
        'type' => 'find',
        'name' => '瓷花盆',
        'id' => 'huapen',
      ),
      5500 => 
      array (
        'type' => 'find',
        'name' => '玉石瓶',
        'id' => 'huaping',
      ),
      5600 => 
      array (
        'type' => 'find',
        'name' => '竹篮',
        'id' => 'zhulan',
      ),
      5700 => 
      array (
        'type' => 'find',
        'name' => '细竹楼',
        'id' => 'zhulou',
      ),
      5800 => 
      array (
        'type' => 'find',
        'name' => '银药盏',
        'id' => 'yinyaozhan',
      ),
      5850 => 
      array (
        'type' => 'find',
        'name' => '白布',
        'id' => 'baibu',
      ),
      5900 => 
      array (
        'type' => 'find',
        'name' => '花布',
        'id' => 'huabu',
      ),
      6000 => 
      array (
        'type' => 'find',
        'name' => '彩灯笼',
        'id' => 'denglong',
      ),
      7000 => 
      array (
        'type' => 'find',
        'name' => '椅子',
        'id' => 'chair',
      ),
      8001 => 
      array (
        'type' => 'find',
        'name' => '方木桌',
        'id' => 'table',
      ),
      9000 => 
      array (
        'type' => 'find',
        'name' => '兽皮',
        'id' => 'shoupi',
      ),
      25000 => 
      array (
        'type' => 'find',
        'name' => '净瓶',
        'id' => 'jingping',
      ),
      30000 => 
      array (
        'type' => 'find',
        'name' => '木槌',
        'id' => 'mallet',
      ),
      35000 => 
      array (
        'type' => 'find',
        'name' => '照相机',
        'id' => 'camera',
      ),
      45000 => 
      array (
        'type' => 'find',
        'name' => '盘子',
        'id' => 'panzi',
      ),
      200000 => 
      array (
        'type' => 'find',
        'name' => '铁钥匙',
        'id' => 'tieyaoshi',
      ),
      1800000 => 
      array (
        'type' => 'find',
        'name' => '翡翠玛瑙',
        'id' => 'xiazi',
      ),
    ),
    'ask' => 
    array (
      5 => 
      array (
        'type' => 'ask',
        'name' => '孔方兄',
        'id' => 'kongfang',
        'topic' => '送经书',
      ),
      10 => 
      array (
        'type' => 'ask',
        'name' => '疥顶小僧',
        'id' => 'jiedingxiaoseng',
        'topic' => '取经之道',
      ),
      15 => 
      array (
        'type' => 'ask',
        'name' => '李白',
        'id' => 'libai',
        'topic' => '诗歌',
      ),
      20 => 
      array (
        'type' => 'ask',
        'name' => '游方僧人',
        'id' => 'sengren',
        'topic' => '佛道',
      ),
      25 => 
      array (
        'type' => 'ask',
        'name' => '袁守诚',
        'id' => 'yuanshoucheng',
        'topic' => '算卦术',
      ),
      35 => 
      array (
        'type' => 'ask',
        'name' => '杨中顺',
        'id' => 'yangzhongshun',
        'topic' => '讲授医术',
      ),
      40 => 
      array (
        'type' => 'ask',
        'name' => '范青屏',
        'id' => 'fanqingping',
        'topic' => '讲拳法',
      ),
      45 => 
      array (
        'type' => 'ask',
        'name' => '袁天罡',
        'id' => 'yuantiangang',
        'topic' => '法术',
      ),
      50 => 
      array (
        'type' => 'ask',
        'name' => '雾渊道长',
        'id' => 'wuyuandaozhang',
        'topic' => '讲玄道',
      ),
      55 => 
      array (
        'type' => 'ask',
        'name' => '法明长老',
        'id' => 'famingzhanglao',
        'topic' => '呼风唤雨',
      ),
      65 => 
      array (
        'type' => 'ask',
        'name' => '公孙大娘',
        'id' => 'gongsundaniang',
        'topic' => '歌艺舞艺',
      ),
      70 => 
      array (
        'type' => 'ask',
        'name' => '贺知章',
        'id' => 'hezhizhang',
        'topic' => '国子监',
      ),
      75 => 
      array (
        'type' => 'ask',
        'name' => '青鬏龟童',
        'id' => 'guitong',
        'topic' => '讲授龟艺',
      ),
      80 => 
      array (
        'type' => 'ask',
        'name' => '白髯鸡仙',
        'id' => 'jixian',
        'topic' => '斗鸡术',
      ),
      85 => 
      array (
        'type' => 'ask',
        'name' => '张果老',
        'id' => 'zhangguolao',
        'topic' => '太乙仙法',
      ),
      90 => 
      array (
        'type' => 'ask',
        'name' => '铁算盘',
        'id' => 'tiesuanpan',
        'topic' => '生财之道',
      ),
      95 => 
      array (
        'type' => 'ask',
        'name' => '李玉娘',
        'id' => 'liyuniang',
        'topic' => '烹调术',
      ),
      100 => 
      array (
        'type' => 'ask',
        'name' => '皤不分',
        'id' => 'bobufen',
        'topic' => '移行换位术',
      ),
      120 => 
      array (
        'type' => 'ask',
        'name' => '罗成',
        'id' => 'luocheng',
        'topic' => '比武',
      ),
      140 => 
      array (
        'type' => 'ask',
        'name' => '云阳真人',
        'id' => 'masteryunyang',
        'topic' => '比武',
      ),
      160 => 
      array (
        'type' => 'ask',
        'name' => '罗春',
        'id' => 'luochun',
        'topic' => '比武',
      ),
      180 => 
      array (
        'type' => 'ask',
        'name' => '卢生',
        'id' => 'lusheng',
        'topic' => '黄梁一梦',
      ),
      200 => 
      array (
        'type' => 'ask',
        'name' => '广筠子',
        'id' => 'guangyunzi',
        'topic' => '比武',
      ),
      220 => 
      array (
        'type' => 'ask',
        'name' => '广羲子',
        'id' => 'guangxizi',
        'topic' => '比武',
      ),
      240 => 
      array (
        'type' => 'ask',
        'name' => '黑熊怪',
        'id' => 'bearmonster',
        'topic' => '比武',
      ),
      260 => 
      array (
        'type' => 'ask',
        'name' => '吴刚',
        'id' => 'wugang',
        'topic' => '砍树',
      ),
      280 => 
      array (
        'type' => 'ask',
        'name' => '月下老人',
        'id' => 'yuexialaoren',
        'topic' => '姻缘术',
      ),
      300 => 
      array (
        'type' => 'ask',
        'name' => '观音菩萨',
        'id' => 'guanyinpusa',
        'topic' => '大乘佛法',
      ),
      320 => 
      array (
        'type' => 'ask',
        'name' => '惠岸行者',
        'id' => 'huianxingzhe',
        'topic' => '大乘佛法',
      ),
      340 => 
      array (
        'type' => 'ask',
        'name' => '净瓶使者',
        'id' => 'shizhe',
        'topic' => '大乘佛法',
      ),
      360 => 
      array (
        'type' => 'ask',
        'name' => '掌厨僧',
        'id' => 'zhangchuseng',
        'topic' => '烹调术',
      ),
      380 => 
      array (
        'type' => 'ask',
        'name' => '掌禅僧',
        'id' => 'zhangchanseng',
        'topic' => '修禅',
      ),
      400 => 
      array (
        'type' => 'ask',
        'name' => '知客僧',
        'id' => 'zhikeseng',
        'topic' => '待客之方',
      ),
      430 => 
      array (
        'type' => 'ask',
        'name' => '鳊提督',
        'id' => 'biantidu',
        'topic' => '比武',
      ),
      460 => 
      array (
        'type' => 'ask',
        'name' => '鲸无敌',
        'id' => 'jingwudi',
        'topic' => '比武',
      ),
      490 => 
      array (
        'type' => 'ask',
        'name' => '龙表弟',
        'id' => 'longbiaodi',
        'topic' => '比武',
      ),
      530 => 
      array (
        'type' => 'ask',
        'name' => '鲤总兵',
        'id' => 'lizongbing',
        'topic' => '比武',
      ),
      560 => 
      array (
        'type' => 'ask',
        'name' => '鲭都司',
        'id' => 'qingdusi',
        'topic' => '比武',
      ),
      590 => 
      array (
        'type' => 'ask',
        'name' => '青贝宫女',
        'id' => 'gongnu',
        'topic' => '采贝术',
      ),
      630 => 
      array (
        'type' => 'ask',
        'name' => '黄贝宫女',
        'id' => 'gongnu',
        'topic' => '采贝术',
      ),
      660 => 
      array (
        'type' => 'ask',
        'name' => '白贝宫女',
        'id' => 'gongnu',
        'topic' => '采贝术',
      ),
      690 => 
      array (
        'type' => 'ask',
        'name' => '紫贝宫女',
        'id' => 'gongnu',
        'topic' => '采贝术',
      ),
      730 => 
      array (
        'type' => 'ask',
        'name' => '银贝宫女',
        'id' => 'gongnu',
        'topic' => '采贝术',
      ),
      760 => 
      array (
        'type' => 'ask',
        'name' => '金贝宫女',
        'id' => 'gongnu',
        'topic' => '采贝术',
      ),
      790 => 
      array (
        'type' => 'ask',
        'name' => '青鳝力士',
        'id' => 'lishi',
        'topic' => '潜海术',
      ),
      830 => 
      array (
        'type' => 'ask',
        'name' => '乌鳅力士',
        'id' => 'lishi',
        'topic' => '潜海术',
      ),
      860 => 
      array (
        'type' => 'ask',
        'name' => '赤鲤力士',
        'id' => 'lishi',
        'topic' => '潜海术',
      ),
      890 => 
      array (
        'type' => 'ask',
        'name' => '黄鲳力士',
        'id' => 'lishi',
        'topic' => '潜海术',
      ),
      930 => 
      array (
        'type' => 'ask',
        'name' => '白鲨力士',
        'id' => 'lishi',
        'topic' => '潜海术',
      ),
      960 => 
      array (
        'type' => 'ask',
        'name' => '紫鲭力士',
        'id' => 'lishi',
        'topic' => '潜海术',
      ),
      990 => 
      array (
        'type' => 'ask',
        'name' => '金鳌力士',
        'id' => 'lishi',
        'topic' => '潜海术',
      ),
      1030 => 
      array (
        'type' => 'ask',
        'name' => '银蛎力士',
        'id' => 'lishi',
        'topic' => '潜海术',
      ),
      1060 => 
      array (
        'type' => 'ask',
        'name' => '蒲牢',
        'id' => 'pulao',
        'topic' => '比武',
      ),
      1090 => 
      array (
        'type' => 'ask',
        'name' => '狴犴',
        'id' => 'bian',
        'topic' => '比武',
      ),
      1130 => 
      array (
        'type' => 'ask',
        'name' => '睚眦',
        'id' => 'yazi',
        'topic' => '比武',
      ),
      1160 => 
      array (
        'type' => 'ask',
        'name' => '霸下',
        'id' => 'baxia',
        'topic' => '比武',
      ),
      1190 => 
      array (
        'type' => 'ask',
        'name' => '螭吻',
        'id' => 'chiwen',
        'topic' => '比武',
      ),
      1230 => 
      array (
        'type' => 'ask',
        'name' => '饕餮',
        'id' => 'taotie',
        'topic' => '比武',
      ),
      1260 => 
      array (
        'type' => 'ask',
        'name' => '蚣蝮',
        'id' => 'gongfu',
        'topic' => '比武',
      ),
      1290 => 
      array (
        'type' => 'ask',
        'name' => '金猊',
        'id' => 'jinni',
        'topic' => '比武',
      ),
      1330 => 
      array (
        'type' => 'ask',
        'name' => '椒图',
        'id' => 'shutu',
        'topic' => '比武',
      ),
      1360 => 
      array (
        'type' => 'ask',
        'name' => '敖广',
        'id' => 'aoguang',
        'topic' => '降雨',
      ),
      1400 => 
      array (
        'type' => 'ask',
        'name' => '青髯老人',
        'id' => 'laoren',
        'topic' => '西域',
      ),
      1500 => 
      array (
        'type' => 'ask',
        'name' => '嫦娥',
        'id' => 'change',
        'topic' => '比武',
      ),
      1520 => 
      array (
        'type' => 'ask',
        'name' => '抱琴',
        'id' => 'baoqin',
        'topic' => '舞技',
      ),
      1540 => 
      array (
        'type' => 'ask',
        'name' => '茗烟',
        'id' => 'mingyan',
        'topic' => '舞技',
      ),
      1560 => 
      array (
        'type' => 'ask',
        'name' => '锄药',
        'id' => 'chuyao',
        'topic' => '舞技',
      ),
      1580 => 
      array (
        'type' => 'ask',
        'name' => '扫红',
        'id' => 'shaohong',
        'topic' => '舞技',
      ),
      1600 => 
      array (
        'type' => 'ask',
        'name' => '蝶衣',
        'id' => 'butterfly',
        'topic' => '舞技',
      ),
      1620 => 
      array (
        'type' => 'ask',
        'name' => '菊影',
        'id' => 'shade',
        'topic' => '舞技',
      ),
      1700 => 
      array (
        'type' => 'ask',
        'name' => '西王母',
        'id' => 'xiwangmu',
        'topic' => '月宫参加水陆大会',
      ),
      1800 => 
      array (
        'type' => 'ask',
        'name' => '秦琼',
        'id' => 'qinqiong',
        'topic' => '将军府参加水陆大会',
      ),
      1900 => 
      array (
        'type' => 'ask',
        'name' => '菩提祖师',
        'id' => 'masterputi',
        'topic' => '方寸山参加水陆大会',
      ),
      2100 => 
      array (
        'type' => 'ask',
        'name' => '浣花',
        'id' => 'huanhua',
        'topic' => '下山比武',
      ),
      2200 => 
      array (
        'type' => 'ask',
        'name' => '孔雀公主',
        'id' => 'kongquegongzhu',
        'topic' => '比武',
      ),
      2300 => 
      array (
        'type' => 'ask',
        'name' => '乌鸦先生',
        'id' => 'wuyaxiansheng',
        'topic' => '比武',
      ),
      2400 => 
      array (
        'type' => 'ask',
        'name' => '鹦鹉将军',
        'id' => 'yingwujiangjun',
        'topic' => '传授口技',
      ),
      2500 => 
      array (
        'type' => 'ask',
        'name' => '白象尊者',
        'id' => 'baixiangzunzhe',
        'topic' => '比武',
      ),
      2600 => 
      array (
        'type' => 'ask',
        'name' => '青狮老魔',
        'id' => 'qingshilaomo',
        'topic' => '比武',
      ),
      2700 => 
      array (
        'type' => 'ask',
        'name' => '秃鹰尊者',
        'id' => 'tuyingzunzhe',
        'topic' => '比武',
      ),
      3100 => 
      array (
        'type' => 'ask',
        'name' => '国王',
        'id' => 'guowang',
        'topic' => '派使来访',
      ),
      3200 => 
      array (
        'type' => 'ask',
        'name' => '太监',
        'id' => 'taijian',
        'topic' => '讲授宫政',
      ),
      3300 => 
      array (
        'type' => 'ask',
        'name' => '宫妃',
        'id' => 'gongfei',
        'topic' => '宫中舞艺',
      ),
      3400 => 
      array (
        'type' => 'ask',
        'name' => '宫女',
        'id' => 'girl',
        'topic' => '宫中舞艺',
      ),
      4100 => 
      array (
        'type' => 'ask',
        'name' => '大使',
        'id' => 'dashi',
        'topic' => '来访水陆大会',
      ),
      4200 => 
      array (
        'type' => 'ask',
        'name' => '黄门官',
        'id' => 'huangmenguan',
        'topic' => '来访水陆大会',
      ),
      4300 => 
      array (
        'type' => 'ask',
        'name' => '梅鸳鸯',
        'id' => 'meiyuanyan',
        'topic' => '来访水陆大会',
      ),
      4400 => 
      array (
        'type' => 'ask',
        'name' => '坊主',
        'id' => 'fangzhu',
        'topic' => '订购家什',
      ),
      4500 => 
      array (
        'type' => 'ask',
        'name' => '锡匠',
        'id' => 'xijiang',
        'topic' => '讲授锡艺',
      ),
      4600 => 
      array (
        'type' => 'ask',
        'name' => '管家',
        'id' => 'guanjia',
        'topic' => '家政',
      ),
      4700 => 
      array (
        'type' => 'ask',
        'name' => '小管家',
        'id' => 'guanjia',
        'topic' => '家政',
      ),
      4800 => 
      array (
        'type' => 'ask',
        'name' => '叶大嫂',
        'id' => 'yedasao',
        'topic' => '园艺',
      ),
      4900 => 
      array (
        'type' => 'ask',
        'name' => '酒保',
        'id' => 'jiubao',
        'topic' => '传授酿酒术',
      ),
      5000 => 
      array (
        'type' => 'ask',
        'name' => '说书老',
        'id' => 'shuoshulao',
        'topic' => '来大唐说书',
      ),
      5100 => 
      array (
        'type' => 'ask',
        'name' => '戏子',
        'id' => 'xizi',
        'topic' => '来大唐唱戏',
      ),
      5200 => 
      array (
        'type' => 'ask',
        'name' => '元先生',
        'id' => 'yuanxiansheng',
        'topic' => '来大唐教外语',
      ),
      5300 => 
      array (
        'type' => 'ask',
        'name' => '祭赛国武士',
        'id' => 'wushi',
        'topic' => '交流武艺',
      ),
      5400 => 
      array (
        'type' => 'ask',
        'name' => '小公主',
        'id' => 'xiliangprincess',
        'topic' => '比武',
      ),
      5500 => 
      array (
        'type' => 'ask',
        'name' => '皇宫伺卫',
        'id' => 'siwei',
        'topic' => '交流武艺',
      ),
      5600 => 
      array (
        'type' => 'ask',
        'name' => '伺卫官',
        'id' => 'siweiguan',
        'topic' => '交流武艺',
      ),
      5700 => 
      array (
        'type' => 'ask',
        'name' => '护宫卫士',
        'id' => 'weishi',
        'topic' => '交流武艺',
      ),
      5800 => 
      array (
        'type' => 'ask',
        'name' => '士兵',
        'id' => 'shibing',
        'topic' => '交流武艺',
      ),
      5900 => 
      array (
        'type' => 'ask',
        'name' => '校尉',
        'id' => 'xiaowei',
        'topic' => '交流武艺',
      ),
      8100 => 
      array (
        'type' => 'ask',
        'name' => '仙官',
        'id' => 'xian',
        'topic' => '恭请光临',
      ),
      8200 => 
      array (
        'type' => 'ask',
        'name' => '仙女',
        'id' => 'xian',
        'topic' => '邀请下凡',
      ),
      8300 => 
      array (
        'type' => 'ask',
        'name' => '仙妃',
        'id' => 'xian',
        'topic' => '邀请下凡',
      ),
      8400 => 
      array (
        'type' => 'ask',
        'name' => '仙子',
        'id' => 'xian',
        'topic' => '邀请下凡',
      ),
      8500 => 
      array (
        'type' => 'ask',
        'name' => '仙将',
        'id' => 'xian',
        'topic' => '恭请光临',
      ),
      8600 => 
      array (
        'type' => 'ask',
        'name' => '仙卿',
        'id' => 'xian',
        'topic' => '恭请光临',
      ),
      8700 => 
      array (
        'type' => 'ask',
        'name' => '仙卿',
        'id' => 'xian',
        'topic' => '恭请光临',
      ),
      9100 => 
      array (
        'type' => 'ask',
        'name' => '祭赛国国王',
        'id' => 'jisaiguowang',
        'topic' => '派使来访',
      ),
      9200 => 
      array (
        'type' => 'ask',
        'name' => '太白金星',
        'id' => 'taibai',
        'topic' => '恭请光临',
      ),
      9300 => 
      array (
        'type' => 'ask',
        'name' => '监斩官',
        'id' => 'jianzhanguan',
        'topic' => '讲授西域律法',
      ),
      9400 => 
      array (
        'type' => 'ask',
        'name' => '道童',
        'id' => 'daotong',
        'topic' => '清理道场',
      ),
      9500 => 
      array (
        'type' => 'ask',
        'name' => '道士',
        'id' => 'daoshi',
        'topic' => '做道场',
      ),
      9600 => 
      array (
        'type' => 'ask',
        'name' => '道人',
        'id' => 'daoren',
        'topic' => '做道场',
      ),
      9700 => 
      array (
        'type' => 'ask',
        'name' => '僧侣',
        'id' => 'senglu',
        'topic' => '做法场',
      ),
      9800 => 
      array (
        'type' => 'ask',
        'name' => '五福道长',
        'id' => 'daozhang',
        'topic' => '传授道教',
      ),
      9900 => 
      array (
        'type' => 'ask',
        'name' => '苦宿禅师',
        'id' => 'kusuchanshi',
        'topic' => '传授佛教',
      ),
      10000 => 
      array (
        'type' => 'ask',
        'name' => '陈小二',
        'id' => 'chenxiaoer',
        'topic' => '宣传佛教',
      ),
      10100 => 
      array (
        'type' => 'ask',
        'name' => '孙小二',
        'id' => 'sunxiaoer',
        'topic' => '宣传佛教',
      ),
      10200 => 
      array (
        'type' => 'ask',
        'name' => '周小二',
        'id' => 'zhouxiaoer',
        'topic' => '宣传佛教',
      ),
      10300 => 
      array (
        'type' => 'ask',
        'name' => '赵寡妇',
        'id' => 'zhaoguafu',
        'topic' => '宣传佛教',
      ),
      10400 => 
      array (
        'type' => 'ask',
        'name' => '李寡妇',
        'id' => 'liguafu',
        'topic' => '宣传佛教',
      ),
      10500 => 
      array (
        'type' => 'ask',
        'name' => '钱寡妇',
        'id' => 'qianguafu',
        'topic' => '宣传佛教',
      ),
      10600 => 
      array (
        'type' => 'ask',
        'name' => '郑寡妇',
        'id' => 'zhengguafu',
        'topic' => '宣传佛教',
      ),
      10700 => 
      array (
        'type' => 'ask',
        'name' => '太子',
        'id' => 'taizi',
        'topic' => '宫亭内政',
      ),
      10800 => 
      array (
        'type' => 'ask',
        'name' => '王后',
        'id' => 'wanghou',
        'topic' => '宫事',
      ),
      10900 => 
      array (
        'type' => 'ask',
        'name' => '公主',
        'id' => 'gongzhu',
        'topic' => '宫事',
      ),
      11100 => 
      array (
        'type' => 'ask',
        'name' => '差官',
        'id' => 'chaiguan',
        'topic' => '西域国政',
      ),
      11200 => 
      array (
        'type' => 'ask',
        'name' => '殿官',
        'id' => 'dianguan',
        'topic' => '西域国政',
      ),
      11300 => 
      array (
        'type' => 'ask',
        'name' => '文官',
        'id' => 'wenguan',
        'topic' => '西域国政',
      ),
      11400 => 
      array (
        'type' => 'ask',
        'name' => '官客',
        'id' => 'guanke',
        'topic' => '官僚术',
      ),
      11500 => 
      array (
        'type' => 'ask',
        'name' => '臣子',
        'id' => 'chenzi',
        'topic' => '官僚术',
      ),
      11600 => 
      array (
        'type' => 'ask',
        'name' => '碧奴慕伊',
        'id' => 'binumuyi',
        'topic' => '插花艺',
      ),
      11700 => 
      array (
        'type' => 'ask',
        'name' => '坎都力',
        'id' => 'kanduli',
        'topic' => '烹饪术',
      ),
      11800 => 
      array (
        'type' => 'ask',
        'name' => '库司库司',
        'id' => 'kusikusi',
        'topic' => '烹饪术',
      ),
      11900 => 
      array (
        'type' => 'ask',
        'name' => '拉苏律司',
        'id' => 'lasulusi',
        'topic' => '金银匠艺',
      ),
      12000 => 
      array (
        'type' => 'ask',
        'name' => '索哈娜',
        'id' => 'suohana',
        'topic' => '西域语',
      ),
      12100 => 
      array (
        'type' => 'ask',
        'name' => '阿依娜',
        'id' => 'ayina',
        'topic' => '金银匠艺',
      ),
      12200 => 
      array (
        'type' => 'ask',
        'name' => '柳妙手',
        'id' => 'liumiaoshou',
        'topic' => '医术',
      ),
      12300 => 
      array (
        'type' => 'ask',
        'name' => '刘老板',
        'id' => 'liulaoban',
        'topic' => '西域商事',
      ),
      12400 => 
      array (
        'type' => 'ask',
        'name' => '萨米儿大叔',
        'id' => 'unclesamui',
        'topic' => '烹饪术',
      ),
      12500 => 
      array (
        'type' => 'ask',
        'name' => '陈长老',
        'id' => 'chenzhanglao',
        'topic' => '祭祀术',
      ),
      13000 => 
      array (
        'type' => 'ask',
        'name' => '日值功曹',
        'id' => 'rizhigongcao',
        'topic' => '传授武功',
      ),
      13100 => 
      array (
        'type' => 'ask',
        'name' => '年值功曹',
        'id' => 'nianzhigongcao',
        'topic' => '传授武功',
      ),
      13200 => 
      array (
        'type' => 'ask',
        'name' => '月值功曹',
        'id' => 'yuezhigongcao',
        'topic' => '传授武功',
      ),
      13300 => 
      array (
        'type' => 'ask',
        'name' => '时值功曹',
        'id' => 'shizhigongcao',
        'topic' => '传授武功',
      ),
      13400 => 
      array (
        'type' => 'ask',
        'name' => '魔礼海',
        'id' => 'molihai',
        'topic' => '传授武功',
      ),
      13500 => 
      array (
        'type' => 'ask',
        'name' => '魔礼青',
        'id' => 'moliqing',
        'topic' => '传授武功',
      ),
      13600 => 
      array (
        'type' => 'ask',
        'name' => '风婆',
        'id' => 'fengpo',
        'topic' => '起风',
      ),
      13700 => 
      array (
        'type' => 'ask',
        'name' => '云童',
        'id' => 'yuntong',
        'topic' => '布云',
      ),
      13800 => 
      array (
        'type' => 'ask',
        'name' => '雷公',
        'id' => 'leigong',
        'topic' => '打雷',
      ),
      13900 => 
      array (
        'type' => 'ask',
        'name' => '电母',
        'id' => 'dianmu',
        'topic' => '闪电',
      ),
      15100 => 
      array (
        'type' => 'ask',
        'name' => '黄衣仙女',
        'id' => 'huangyixiannu',
        'topic' => '邀请下凡',
      ),
      15200 => 
      array (
        'type' => 'ask',
        'name' => '紫衣仙女',
        'id' => 'ziyixiannu',
        'topic' => '邀请下凡',
      ),
      15300 => 
      array (
        'type' => 'ask',
        'name' => '绿衣仙女',
        'id' => 'luyixiannu',
        'topic' => '邀请下凡',
      ),
      15400 => 
      array (
        'type' => 'ask',
        'name' => '红衣仙女',
        'id' => 'hongyixiannu',
        'topic' => '邀请下凡',
      ),
      15500 => 
      array (
        'type' => 'ask',
        'name' => '青衣仙女',
        'id' => 'qingyixiannu',
        'topic' => '邀请下凡',
      ),
      15600 => 
      array (
        'type' => 'ask',
        'name' => '皂衣仙女',
        'id' => 'zaoyixiannu',
        'topic' => '邀请下凡',
      ),
      15700 => 
      array (
        'type' => 'ask',
        'name' => '素衣仙女',
        'id' => 'suyixiannu',
        'topic' => '邀请下凡',
      ),
      16100 => 
      array (
        'type' => 'ask',
        'name' => '运水力士',
        'id' => 'lishi',
        'topic' => '邀请观礼水陆大会',
      ),
      16200 => 
      array (
        'type' => 'ask',
        'name' => '修桃力士',
        'id' => 'lishi',
        'topic' => '邀请观礼水陆大会',
      ),
      16300 => 
      array (
        'type' => 'ask',
        'name' => '打扫力士',
        'id' => 'lishi',
        'topic' => '邀请观礼水陆大会',
      ),
      16400 => 
      array (
        'type' => 'ask',
        'name' => '剪枝力士',
        'id' => 'lishi',
        'topic' => '邀请观礼水陆大会',
      ),
      16500 => 
      array (
        'type' => 'ask',
        'name' => '施肥力士',
        'id' => 'lishi',
        'topic' => '邀请观礼水陆大会',
      ),
      16600 => 
      array (
        'type' => 'ask',
        'name' => '培土力士',
        'id' => 'lishi',
        'topic' => '邀请观礼水陆大会',
      ),
      16700 => 
      array (
        'type' => 'ask',
        'name' => '锄树力士',
        'id' => 'lishi',
        'topic' => '邀请观礼水陆大会',
      ),
      20100 => 
      array (
        'type' => 'ask',
        'name' => '黛姐',
        'id' => 'daijie',
        'topic' => '花艺',
      ),
      20200 => 
      array (
        'type' => 'ask',
        'name' => '方丈',
        'id' => 'fangzhang',
        'topic' => '传授佛学',
      ),
      20300 => 
      array (
        'type' => 'ask',
        'name' => '福嫂',
        'id' => 'fusao',
        'topic' => '烹饪术',
      ),
      20400 => 
      array (
        'type' => 'ask',
        'name' => '柳光头',
        'id' => 'liuguangtou',
        'topic' => '烹饪术',
      ),
      20500 => 
      array (
        'type' => 'ask',
        'name' => '万口福',
        'id' => 'wankoufu',
        'topic' => '烹饪术',
      ),
      20600 => 
      array (
        'type' => 'ask',
        'name' => '辛儿娘',
        'id' => 'xinerniang',
        'topic' => '烹饪术',
      ),
      20700 => 
      array (
        'type' => 'ask',
        'name' => '侯郎中',
        'id' => 'houlangzhong',
        'topic' => '西域药理',
      ),
      20800 => 
      array (
        'type' => 'ask',
        'name' => '张妈',
        'id' => 'zhangma',
        'topic' => '烹饪术',
      ),
      20900 => 
      array (
        'type' => 'ask',
        'name' => '甜妞',
        'id' => 'tianniu',
        'topic' => '西域服装',
      ),
      21000 => 
      array (
        'type' => 'ask',
        'name' => '歌女',
        'id' => 'genu',
        'topic' => '来唐唱歌',
      ),
      30100 => 
      array (
        'type' => 'ask',
        'name' => '郭三娘',
        'id' => 'guosanniang',
        'topic' => '西域服装',
      ),
      30200 => 
      array (
        'type' => 'ask',
        'name' => '魏大嫂',
        'id' => 'weidasao',
        'topic' => '种植园艺',
      ),
      30300 => 
      array (
        'type' => 'ask',
        'name' => '季梅儿',
        'id' => 'jimeier',
        'topic' => '西域服装',
      ),
      30400 => 
      array (
        'type' => 'ask',
        'name' => '陶娘子',
        'id' => 'taoniangzi',
        'topic' => '西域服装',
      ),
      30500 => 
      array (
        'type' => 'ask',
        'name' => '侯郎中',
        'id' => 'houlangzhong',
        'topic' => '西域药理',
      ),
      30600 => 
      array (
        'type' => 'ask',
        'name' => '黄汤',
        'id' => 'huangtang',
        'topic' => '酿酒术',
      ),
      30700 => 
      array (
        'type' => 'ask',
        'name' => '醉方休',
        'id' => 'zuifangxiu',
        'topic' => '酿酒术',
      ),
      30800 => 
      array (
        'type' => 'ask',
        'name' => '胖店主',
        'id' => 'pangdianzhu',
        'topic' => '商务',
      ),
      30900 => 
      array (
        'type' => 'ask',
        'name' => '瘦店娘',
        'id' => 'shoudianniang',
        'topic' => '商务',
      ),
      40100 => 
      array (
        'type' => 'ask',
        'name' => '米老板',
        'id' => 'milaoban',
        'topic' => '商务',
      ),
      40200 => 
      array (
        'type' => 'ask',
        'name' => '乐老板',
        'id' => 'lelaoban',
        'topic' => '商务',
      ),
      40300 => 
      array (
        'type' => 'ask',
        'name' => '吴老板',
        'id' => 'wulaoban',
        'topic' => '商务',
      ),
      40400 => 
      array (
        'type' => 'ask',
        'name' => '马老板',
        'id' => 'malaoban',
        'topic' => '马术',
      ),
      40500 => 
      array (
        'type' => 'ask',
        'name' => '乔老板',
        'id' => 'qiaolaoban',
        'topic' => '商务',
      ),
      40600 => 
      array (
        'type' => 'ask',
        'name' => '史老板',
        'id' => 'shilaoban',
        'topic' => '竹器编制工艺',
      ),
      40700 => 
      array (
        'type' => 'ask',
        'name' => '舒老板',
        'id' => 'shulaoban',
        'topic' => '西域服装',
      ),
      40800 => 
      array (
        'type' => 'ask',
        'name' => '司徒晋宝',
        'id' => 'situjinbao',
        'topic' => '商务',
      ),
      40900 => 
      array (
        'type' => 'ask',
        'name' => '知书和尚',
        'id' => 'heshang',
        'topic' => '佛学',
      ),
      41000 => 
      array (
        'type' => 'ask',
        'name' => '尚礼和尚',
        'id' => 'heshang',
        'topic' => '佛学',
      ),
      41100 => 
      array (
        'type' => 'ask',
        'name' => '俗家和尚',
        'id' => 'heshang',
        'topic' => '佛学',
      ),
      41200 => 
      array (
        'type' => 'ask',
        'name' => '书院和尚',
        'id' => 'heshang',
        'topic' => '佛学',
      ),
      41300 => 
      array (
        'type' => 'ask',
        'name' => '客家和尚',
        'id' => 'heshang',
        'topic' => '佛学',
      ),
      41400 => 
      array (
        'type' => 'ask',
        'name' => '修行和尚',
        'id' => 'heshang',
        'topic' => '佛学',
      ),
      41500 => 
      array (
        'type' => 'ask',
        'name' => '穷和尚',
        'id' => 'heshang',
        'topic' => '佛学',
      ),
      41600 => 
      array (
        'type' => 'ask',
        'name' => '瘦和尚',
        'id' => 'heshang',
        'topic' => '佛学',
      ),
      41700 => 
      array (
        'type' => 'ask',
        'name' => '胖和尚',
        'id' => 'heshang',
        'topic' => '佛学',
      ),
      41800 => 
      array (
        'type' => 'ask',
        'name' => '老和尚',
        'id' => 'heshang',
        'topic' => '佛学',
      ),
      41900 => 
      array (
        'type' => 'ask',
        'name' => '笑和尚',
        'id' => 'heshang',
        'topic' => '佛学',
      ),
      42000 => 
      array (
        'type' => 'ask',
        'name' => '诵经僧人',
        'id' => 'heshang',
        'topic' => '佛学',
      ),
      42100 => 
      array (
        'type' => 'ask',
        'name' => '出勤僧人',
        'id' => 'heshang',
        'topic' => '佛学',
      ),
      42200 => 
      array (
        'type' => 'ask',
        'name' => '俗家僧人',
        'id' => 'heshang',
        'topic' => '佛学',
      ),
      42300 => 
      array (
        'type' => 'ask',
        'name' => '修禅僧人',
        'id' => 'heshang',
        'topic' => '佛学',
      ),
      42400 => 
      array (
        'type' => 'ask',
        'name' => '传法僧人',
        'id' => 'heshang',
        'topic' => '佛学',
      ),
      50100 => 
      array (
        'type' => 'ask',
        'name' => '陈康',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      50200 => 
      array (
        'type' => 'ask',
        'name' => '陈禄',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      50300 => 
      array (
        'type' => 'ask',
        'name' => '陈溯',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      50400 => 
      array (
        'type' => 'ask',
        'name' => '陈鸠',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      50500 => 
      array (
        'type' => 'ask',
        'name' => '陈蜀',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      50600 => 
      array (
        'type' => 'ask',
        'name' => '陈焘',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      50700 => 
      array (
        'type' => 'ask',
        'name' => '陈戛',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      50800 => 
      array (
        'type' => 'ask',
        'name' => '陈笮',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      50900 => 
      array (
        'type' => 'ask',
        'name' => '陈子虬',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      51000 => 
      array (
        'type' => 'ask',
        'name' => '陈龙大',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      51100 => 
      array (
        'type' => 'ask',
        'name' => '陈大头',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      51200 => 
      array (
        'type' => 'ask',
        'name' => '陈小个',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      51300 => 
      array (
        'type' => 'ask',
        'name' => '陈老大',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      51400 => 
      array (
        'type' => 'ask',
        'name' => '陈老二',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      51500 => 
      array (
        'type' => 'ask',
        'name' => '陈老三',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      51600 => 
      array (
        'type' => 'ask',
        'name' => '陈老四',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      51700 => 
      array (
        'type' => 'ask',
        'name' => '陈大伯',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      51800 => 
      array (
        'type' => 'ask',
        'name' => '陈大叔',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      51900 => 
      array (
        'type' => 'ask',
        'name' => '陈大舅',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      52000 => 
      array (
        'type' => 'ask',
        'name' => '陈大哥',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      52100 => 
      array (
        'type' => 'ask',
        'name' => '陈大爷',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      52200 => 
      array (
        'type' => 'ask',
        'name' => '陈二伯',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      52300 => 
      array (
        'type' => 'ask',
        'name' => '陈二叔',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      52400 => 
      array (
        'type' => 'ask',
        'name' => '陈二舅',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      52500 => 
      array (
        'type' => 'ask',
        'name' => '陈二哥',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      52600 => 
      array (
        'type' => 'ask',
        'name' => '陈二爷',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      52700 => 
      array (
        'type' => 'ask',
        'name' => '陈三伯',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      52800 => 
      array (
        'type' => 'ask',
        'name' => '陈三叔',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      52900 => 
      array (
        'type' => 'ask',
        'name' => '陈三舅',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      53000 => 
      array (
        'type' => 'ask',
        'name' => '陈三哥',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      53100 => 
      array (
        'type' => 'ask',
        'name' => '陈三爷',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      53200 => 
      array (
        'type' => 'ask',
        'name' => '陈四伯',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      53300 => 
      array (
        'type' => 'ask',
        'name' => '陈四叔',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      54400 => 
      array (
        'type' => 'ask',
        'name' => '陈四舅',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      54500 => 
      array (
        'type' => 'ask',
        'name' => '陈四哥',
        'id' => 'chen',
        'topic' => '捕鱼术',
      ),
      54600 => 
      array (
        'type' => 'ask',
        'name' => '陈娘',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      54700 => 
      array (
        'type' => 'ask',
        'name' => '陈氏',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      54800 => 
      array (
        'type' => 'ask',
        'name' => '陈婆',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      54900 => 
      array (
        'type' => 'ask',
        'name' => '陈妈',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      55000 => 
      array (
        'type' => 'ask',
        'name' => '陈嫂',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      55100 => 
      array (
        'type' => 'ask',
        'name' => '陈婶',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      55200 => 
      array (
        'type' => 'ask',
        'name' => '陈大娘',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      55300 => 
      array (
        'type' => 'ask',
        'name' => '陈大婆',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      55400 => 
      array (
        'type' => 'ask',
        'name' => '陈大妈',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      55500 => 
      array (
        'type' => 'ask',
        'name' => '陈大嫂',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      55600 => 
      array (
        'type' => 'ask',
        'name' => '陈二嫂',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      55700 => 
      array (
        'type' => 'ask',
        'name' => '陈二婶',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      55800 => 
      array (
        'type' => 'ask',
        'name' => '陈三娘',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      55900 => 
      array (
        'type' => 'ask',
        'name' => '陈三婆',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      56000 => 
      array (
        'type' => 'ask',
        'name' => '陈三妈',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      56100 => 
      array (
        'type' => 'ask',
        'name' => '陈三嫂',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      56200 => 
      array (
        'type' => 'ask',
        'name' => '陈三婶',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      56300 => 
      array (
        'type' => 'ask',
        'name' => '陈四娘',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      56400 => 
      array (
        'type' => 'ask',
        'name' => '陈四婆',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      56500 => 
      array (
        'type' => 'ask',
        'name' => '陈四妈',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      56600 => 
      array (
        'type' => 'ask',
        'name' => '陈四嫂',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      56700 => 
      array (
        'type' => 'ask',
        'name' => '陈四婶',
        'id' => 'chen',
        'topic' => '烹鱼技艺',
      ),
      60000 => 
      array (
        'type' => 'ask',
        'name' => '官人',
        'id' => 'guanren',
        'topic' => '互访',
      ),
      60100 => 
      array (
        'type' => 'ask',
        'name' => '大姑奶',
        'id' => 'dagunai',
        'topic' => '捐助水陆大会',
      ),
      60200 => 
      array (
        'type' => 'ask',
        'name' => '二姑奶',
        'id' => 'ergunai',
        'topic' => '捐助水陆大会',
      ),
      60300 => 
      array (
        'type' => 'ask',
        'name' => '小姑奶',
        'id' => 'xiaogunai',
        'topic' => '捐助水陆大会',
      ),
      60400 => 
      array (
        'type' => 'ask',
        'name' => '大嫂子',
        'id' => 'dasaozi',
        'topic' => '捐助水陆大会',
      ),
      60500 => 
      array (
        'type' => 'ask',
        'name' => '小嫂子',
        'id' => 'xiaosaozi',
        'topic' => '捐助水陆大会',
      ),
      60600 => 
      array (
        'type' => 'ask',
        'name' => '小妾',
        'id' => 'xiaoqie',
        'topic' => '捐助水陆大会',
      ),
      60700 => 
      array (
        'type' => 'ask',
        'name' => '二妾',
        'id' => 'erqie',
        'topic' => '捐助水陆大会',
      ),
      60800 => 
      array (
        'type' => 'ask',
        'name' => '大小姐',
        'id' => 'daxiaojie',
        'topic' => '捐助水陆大会',
      ),
      60900 => 
      array (
        'type' => 'ask',
        'name' => '二小姐',
        'id' => 'erxiaojie',
        'topic' => '捐助水陆大会',
      ),
      61000 => 
      array (
        'type' => 'ask',
        'name' => '花姐儿',
        'id' => 'huajieer',
        'topic' => '捐助水陆大会',
      ),
      61200 => 
      array (
        'type' => 'ask',
        'name' => '小姐',
        'id' => 'xiaojie',
        'topic' => '捐助水陆大会',
      ),
      70000 => 
      array (
        'type' => 'ask',
        'name' => '大少爷',
        'id' => 'dashaoye',
        'topic' => '捐助水陆大会',
      ),
      70100 => 
      array (
        'type' => 'ask',
        'name' => '二少爷',
        'id' => 'ershaoye',
        'topic' => '捐助水陆大会',
      ),
      70200 => 
      array (
        'type' => 'ask',
        'name' => '小少爷',
        'id' => 'xiaoshaoye',
        'topic' => '捐助水陆大会',
      ),
      70300 => 
      array (
        'type' => 'ask',
        'name' => '大痞子',
        'id' => 'dapizi',
        'topic' => '捐助水陆大会',
      ),
      70400 => 
      array (
        'type' => 'ask',
        'name' => '小痞子',
        'id' => 'xiaopizi',
        'topic' => '捐助水陆大会',
      ),
      70500 => 
      array (
        'type' => 'ask',
        'name' => '小官人',
        'id' => 'xiaoguanren',
        'topic' => '捐助水陆大会',
      ),
      70600 => 
      array (
        'type' => 'ask',
        'name' => '大公子',
        'id' => 'dagongzi',
        'topic' => '捐助水陆大会',
      ),
      70700 => 
      array (
        'type' => 'ask',
        'name' => '二公子',
        'id' => 'ergongzi',
        'topic' => '捐助水陆大会',
      ),
      70800 => 
      array (
        'type' => 'ask',
        'name' => '少公子',
        'id' => 'shaogongzi',
        'topic' => '捐助水陆大会',
      ),
      70900 => 
      array (
        'type' => 'ask',
        'name' => '花公子',
        'id' => 'huagongzi',
        'topic' => '捐助水陆大会',
      ),
      71000 => 
      array (
        'type' => 'ask',
        'name' => '浪公子',
        'id' => 'langgongzi',
        'topic' => '捐助水陆大会',
      ),
      71100 => 
      array (
        'type' => 'ask',
        'name' => '公子',
        'id' => 'gongzi',
        'topic' => '捐助水陆大会',
      ),
      71200 => 
      array (
        'type' => 'ask',
        'name' => '王孙',
        'id' => 'wangsun',
        'topic' => '捐助水陆大会',
      ),
      72000 => 
      array (
        'type' => 'ask',
        'name' => '灵宝道君',
        'id' => 'lingbaodaojun',
        'topic' => '主持道场',
      ),
      72100 => 
      array (
        'type' => 'ask',
        'name' => '太上老君',
        'id' => 'taishanglaojun',
        'topic' => '主持道场',
      ),
      72200 => 
      array (
        'type' => 'ask',
        'name' => '元始天尊',
        'id' => 'yuanshitianzun',
        'topic' => '主持道场',
      ),
      75000 => 
      array (
        'type' => 'ask',
        'name' => '布道法师',
        'id' => 'budaofashi',
        'topic' => '来唐布道',
      ),
      75100 => 
      array (
        'type' => 'ask',
        'name' => '祭官',
        'id' => 'jiguan',
        'topic' => '祭祀术',
      ),
      75200 => 
      array (
        'type' => 'ask',
        'name' => '慈悲和尚',
        'id' => 'cibeiheshang',
        'topic' => '佛法',
      ),
      75300 => 
      array (
        'type' => 'ask',
        'name' => '灯官',
        'id' => 'dengguan',
        'topic' => '制灯术',
      ),
      75400 => 
      array (
        'type' => 'ask',
        'name' => '店主',
        'id' => 'dianzhu',
        'topic' => '烹调术',
      ),
      75500 => 
      array (
        'type' => 'ask',
        'name' => '府令',
        'id' => 'fuling',
        'topic' => '西域民法',
      ),
      75600 => 
      array (
        'type' => 'ask',
        'name' => '府官',
        'id' => 'fuguan',
        'topic' => '西域民法',
      ),
      75700 => 
      array (
        'type' => 'ask',
        'name' => '上官郡主',
        'id' => 'shangguanjunzhu',
        'topic' => '祈雨术',
      ),
      75800 => 
      array (
        'type' => 'ask',
        'name' => '上官仪',
        'id' => 'shangguanyi',
        'topic' => '祈雨术',
      ),
      75900 => 
      array (
        'type' => 'ask',
        'name' => '上官阿人',
        'id' => 'shangguanaren',
        'topic' => '祈雨术',
      ),
      76000 => 
      array (
        'type' => 'ask',
        'name' => '上官申尜',
        'id' => 'shangguanshenga',
        'topic' => '祈雨术',
      ),
      76100 => 
      array (
        'type' => 'ask',
        'name' => '上官曲岸',
        'id' => 'shangguanquan',
        'topic' => '祈雨术',
      ),
      76200 => 
      array (
        'type' => 'ask',
        'name' => '老王爷',
        'id' => 'laowangye',
        'topic' => '来访大唐国',
      ),
      76300 => 
      array (
        'type' => 'ask',
        'name' => '大王子',
        'id' => 'dawangzi',
        'topic' => '来访大唐国',
      ),
      76400 => 
      array (
        'type' => 'ask',
        'name' => '二王子',
        'id' => 'erwangzi',
        'topic' => '来访大唐国',
      ),
      76500 => 
      array (
        'type' => 'ask',
        'name' => '小王子',
        'id' => 'xiaowangzi',
        'topic' => '来访大唐国',
      ),
      76600 => 
      array (
        'type' => 'ask',
        'name' => '老者',
        'id' => 'oldman',
        'topic' => '避火法',
      ),
      76700 => 
      array (
        'type' => 'ask',
        'name' => '土地',
        'id' => 'tudi',
        'topic' => '火焰山',
      ),
      76800 => 
      array (
        'type' => 'ask',
        'name' => '张铁臂',
        'id' => 'blacksmith',
        'topic' => '锻铁术',
      ),
      76900 => 
      array (
        'type' => 'ask',
        'name' => '茶花娘子',
        'id' => 'chahuaniangzi',
        'topic' => '茶道',
      ),
      77000 => 
      array (
        'type' => 'ask',
        'name' => '鹤发老童',
        'id' => 'hefalaotong',
        'topic' => '西域药理',
      ),
      80000 => 
      array (
        'type' => 'ask',
        'name' => '金顶大仙',
        'id' => 'jindingdaxian',
        'topic' => '取经',
      ),
      80100 => 
      array (
        'type' => 'ask',
        'name' => '阿傩尊者',
        'id' => 'anuozunzhe',
        'topic' => '取经',
      ),
      80200 => 
      array (
        'type' => 'ask',
        'name' => '迦叶尊者',
        'id' => 'jiayezunzhe',
        'topic' => '取经',
      ),
      80300 => 
      array (
        'type' => 'ask',
        'name' => '接引佛祖',
        'id' => 'jieyinfuzu',
        'topic' => '取经',
      ),
      80400 => 
      array (
        'type' => 'ask',
        'name' => '优婆塞',
        'id' => 'youposai',
        'topic' => '取经',
      ),
      80500 => 
      array (
        'type' => 'ask',
        'name' => '优婆夷',
        'id' => 'youpoyi',
        'topic' => '取经',
      ),
      81000 => 
      array (
        'type' => 'ask',
        'name' => '白髯土地',
        'id' => 'tudi',
        'topic' => '西域民俗',
      ),
      100000 => 
      array (
        'type' => 'ask',
        'name' => '白无常',
        'id' => 'baiwuchang',
        'topic' => '比武',
      ),
      100500 => 
      array (
        'type' => 'ask',
        'name' => '黑无常',
        'id' => 'heiwuchang',
        'topic' => '比武',
      ),
      101000 => 
      array (
        'type' => 'ask',
        'name' => '王方平',
        'id' => 'wangfangping',
        'topic' => '比武',
      ),
      101500 => 
      array (
        'type' => 'ask',
        'name' => '阴长生',
        'id' => 'yinchangsheng',
        'topic' => '比武',
      ),
      102000 => 
      array (
        'type' => 'ask',
        'name' => '郁垒',
        'id' => 'yulei',
        'topic' => '比武',
      ),
      102500 => 
      array (
        'type' => 'ask',
        'name' => '神荼',
        'id' => 'shentu',
        'topic' => '比武',
      ),
      103000 => 
      array (
        'type' => 'ask',
        'name' => '桥梁使者',
        'id' => 'bridgeguard',
        'topic' => '奈何桥',
      ),
      103500 => 
      array (
        'type' => 'ask',
        'name' => '断足鬼',
        'id' => 'duanzugui',
        'topic' => '可怜人',
      ),
      104000 => 
      array (
        'type' => 'ask',
        'name' => '牛头鬼',
        'id' => 'niutougui',
        'topic' => '比武',
      ),
      104500 => 
      array (
        'type' => 'ask',
        'name' => '马面鬼',
        'id' => 'mamiangui',
        'topic' => '比武',
      ),
      150000 => 
      array (
        'type' => 'ask',
        'name' => '阎罗王',
        'id' => 'yanluowang',
        'topic' => '生死',
      ),
      151000 => 
      array (
        'type' => 'ask',
        'name' => '轮转王',
        'id' => 'lunzhuanwang',
        'topic' => '生死',
      ),
      152000 => 
      array (
        'type' => 'ask',
        'name' => '卞城王',
        'id' => 'bianchengwang',
        'topic' => '生死',
      ),
      153000 => 
      array (
        'type' => 'ask',
        'name' => '都市王',
        'id' => 'dushiwang',
        'topic' => '生死',
      ),
      154000 => 
      array (
        'type' => 'ask',
        'name' => '泰山王',
        'id' => 'taishanwang',
        'topic' => '生死',
      ),
      155000 => 
      array (
        'type' => 'ask',
        'name' => '仵官王',
        'id' => 'chuguanwang',
        'topic' => '生死',
      ),
      156000 => 
      array (
        'type' => 'ask',
        'name' => '平等王',
        'id' => 'pingdengwang',
        'topic' => '生死',
      ),
      157000 => 
      array (
        'type' => 'ask',
        'name' => '宋帝王',
        'id' => 'songdiwang',
        'topic' => '生死',
      ),
      158000 => 
      array (
        'type' => 'ask',
        'name' => '楚江王',
        'id' => 'chujiangwang',
        'topic' => '生死',
      ),
      159000 => 
      array (
        'type' => 'ask',
        'name' => '秦广王',
        'id' => 'qinguangwang',
        'topic' => '生死',
      ),
      160000 => 
      array (
        'type' => 'ask',
        'name' => '地藏王菩萨',
        'id' => 'dizangpusa',
        'topic' => '生死',
      ),
      170000 => 
      array (
        'type' => 'ask',
        'name' => '孤直公',
        'id' => 'guzhigong',
        'topic' => '诗歌',
      ),
      175000 => 
      array (
        'type' => 'ask',
        'name' => '凌空子',
        'id' => 'lingkongzi',
        'topic' => '诗歌',
      ),
      180000 => 
      array (
        'type' => 'ask',
        'name' => '十八公',
        'id' => 'shibagong',
        'topic' => '诗歌',
      ),
      185000 => 
      array (
        'type' => 'ask',
        'name' => '拂云叟',
        'id' => 'fuyunsou',
        'topic' => '诗歌',
      ),
      190000 => 
      array (
        'type' => 'ask',
        'name' => '杏仙',
        'id' => 'xingxian',
        'topic' => '讲习成精法术',
      ),
      200000 => 
      array (
        'type' => 'ask',
        'name' => '康安裕',
        'id' => 'kanganyu',
        'topic' => '比武',
      ),
      210000 => 
      array (
        'type' => 'ask',
        'name' => '张伯时',
        'id' => 'zhangboshi',
        'topic' => '比武',
      ),
      220000 => 
      array (
        'type' => 'ask',
        'name' => '姚公麟',
        'id' => 'yaogonglin',
        'topic' => '比武',
      ),
      230000 => 
      array (
        'type' => 'ask',
        'name' => '李焕章',
        'id' => 'lihuanzhang',
        'topic' => '比武',
      ),
      240000 => 
      array (
        'type' => 'ask',
        'name' => '直健',
        'id' => 'zhijian',
        'topic' => '拳法',
      ),
      250000 => 
      array (
        'type' => 'ask',
        'name' => '郭申',
        'id' => 'guoshen',
        'topic' => '拳法',
      ),
      260000 => 
      array (
        'type' => 'ask',
        'name' => '福星',
        'id' => 'fuxing',
        'topic' => '来访大唐国',
      ),
      270000 => 
      array (
        'type' => 'ask',
        'name' => '寿星',
        'id' => 'shouxing',
        'topic' => '来访大唐国',
      ),
      280000 => 
      array (
        'type' => 'ask',
        'name' => '禄星',
        'id' => 'luxing',
        'topic' => '来访大唐国',
      ),
      290000 => 
      array (
        'type' => 'ask',
        'name' => '军机大臣',
        'id' => 'junji',
        'topic' => '访问大唐国',
      ),
      300000 => 
      array (
        'type' => 'ask',
        'name' => '井龙王',
        'id' => 'jinglongwang',
        'topic' => '治水术',
      ),
      900000 => 
      array (
        'type' => 'ask',
        'name' => '二郎真君',
        'id' => 'erlangzhenjun',
        'topic' => '得道秘法',
      ),
      910000 => 
      array (
        'type' => 'ask',
        'name' => '金圣宫',
        'id' => 'jinshenggong',
        'topic' => '避妖术',
      ),
      920000 => 
      array (
        'type' => 'ask',
        'name' => '毗蓝婆',
        'id' => 'pilanpo',
        'topic' => '讲授佛学',
      ),
      930000 => 
      array (
        'type' => 'ask',
        'name' => '圆清',
        'id' => 'yuanqing',
        'topic' => '讲授佛学',
      ),
      1000000 => 
      array (
        'type' => 'ask',
        'name' => '大鹏明王',
        'id' => 'dapengmingwang',
        'topic' => '大雪山参加水陆大会',
      ),
    ),
    'kill' => 
    array (
      10 => 
      array (
        'type' => 'kill',
        'name' => '蟋蟀',
        'id' => 'xishuai',
      ),
      20 => 
      array (
        'type' => 'kill',
        'name' => '大老鼠',
        'id' => 'rat',
      ),
      30 => 
      array (
        'type' => 'kill',
        'name' => '野狗',
        'id' => 'dog',
      ),
      40 => 
      array (
        'type' => 'kill',
        'name' => '小狼',
        'id' => 'wolf',
      ),
      50 => 
      array (
        'type' => 'kill',
        'name' => '公鸡',
        'id' => 'gongji',
      ),
      60 => 
      array (
        'type' => 'kill',
        'name' => '山鸡',
        'id' => 'shanji',
      ),
      500 => 
      array (
        'type' => 'kill',
        'name' => '小痞子',
        'id' => 'xiaopizi',
      ),
      1000 => 
      array (
        'type' => 'kill',
        'name' => '小流氓',
        'id' => 'xiaoliumang',
      ),
      2000 => 
      array (
        'type' => 'kill',
        'name' => '老头',
        'id' => 'laotou',
      ),
      2500 => 
      array (
        'type' => 'kill',
        'name' => '恶狼',
        'id' => 'wolf',
      ),
      3000 => 
      array (
        'type' => 'kill',
        'name' => '打手',
        'id' => 'dashou',
      ),
      4000 => 
      array (
        'type' => 'kill',
        'name' => '强盗头目',
        'id' => 'banditleader',
      ),
      5000 => 
      array (
        'type' => 'kill',
        'name' => '马贼',
        'id' => 'madao',
      ),
      5100 => 
      array (
        'type' => 'kill',
        'name' => '江湖骗子',
        'id' => 'jianghupianzi',
      ),
      5200 => 
      array (
        'type' => 'kill',
        'name' => '金钗女',
        'id' => 'jinchainu',
      ),
      5300 => 
      array (
        'type' => 'kill',
        'name' => '剑客',
        'id' => 'jianshang',
      ),
      5400 => 
      array (
        'type' => 'kill',
        'name' => '庸医',
        'id' => 'yongyi',
      ),
      6200 => 
      array (
        'type' => 'kill',
        'name' => '水蛇怪',
        'id' => 'shuisheguai',
      ),
      6300 => 
      array (
        'type' => 'kill',
        'name' => '金睛兽',
        'id' => 'jinjingshou',
      ),
      7100 => 
      array (
        'type' => 'kill',
        'name' => '花人',
        'id' => 'huaren',
      ),
      7200 => 
      array (
        'type' => 'kill',
        'name' => '狐',
        'id' => 'mi',
      ),
      7300 => 
      array (
        'type' => 'kill',
        'name' => '马',
        'id' => 'ma',
      ),
      7400 => 
      array (
        'type' => 'kill',
        'name' => '驴',
        'id' => 'lu',
      ),
      7500 => 
      array (
        'type' => 'kill',
        'name' => '班',
        'id' => 'ban',
      ),
      7600 => 
      array (
        'type' => 'kill',
        'name' => '猛',
        'id' => 'meng',
      ),
      7700 => 
      array (
        'type' => 'kill',
        'name' => '獭',
        'id' => 'la',
      ),
      7800 => 
      array (
        'type' => 'kill',
        'name' => '青',
        'id' => 'qing',
      ),
      8100 => 
      array (
        'type' => 'kill',
        'name' => '鼠',
        'id' => 'yanshu',
      ),
      8200 => 
      array (
        'type' => 'kill',
        'name' => '虾婆',
        'id' => 'xiapo',
      ),
      8300 => 
      array (
        'type' => 'kill',
        'name' => '虾婆',
        'id' => 'xiapo',
      ),
      8400 => 
      array (
        'type' => 'kill',
        'name' => '谷婆儿',
        'id' => 'guboerxi',
      ),
      8500 => 
      array (
        'type' => 'kill',
        'name' => '锡伯尔',
        'id' => 'xiboergu',
      ),
      8600 => 
      array (
        'type' => 'kill',
        'name' => '柳树精',
        'id' => 'liushujing',
      ),
      9000 => 
      array (
        'type' => 'kill',
        'name' => '鹿头怪',
        'id' => 'yaoguai',
      ),
      9100 => 
      array (
        'type' => 'kill',
        'name' => '虎头怪',
        'id' => 'yaoguai',
      ),
      9200 => 
      array (
        'type' => 'kill',
        'name' => '狮头怪',
        'id' => 'yaoguai',
      ),
      9300 => 
      array (
        'type' => 'kill',
        'name' => '象头怪',
        'id' => 'yaoguai',
      ),
      9400 => 
      array (
        'type' => 'kill',
        'name' => '熊头怪',
        'id' => 'yaoguai',
      ),
      10000 => 
      array (
        'type' => 'kill',
        'name' => '夏鹏展',
        'id' => 'xiapengzhan',
      ),
      11000 => 
      array (
        'type' => 'kill',
        'name' => '巴波尔',
        'id' => 'baboerben',
      ),
      12000 => 
      array (
        'type' => 'kill',
        'name' => '奔波尔',
        'id' => 'benboerba',
      ),
      13000 => 
      array (
        'type' => 'kill',
        'name' => '灵虚子',
        'id' => 'lingxuzi',
      ),
      20100 => 
      array (
        'type' => 'kill',
        'name' => '山妖',
        'id' => 'shanyao',
      ),
      20200 => 
      array (
        'type' => 'kill',
        'name' => '小妖',
        'id' => 'xiaoyao',
      ),
      20300 => 
      array (
        'type' => 'kill',
        'name' => '妖怪',
        'id' => 'yaoguai',
      ),
      21000 => 
      array (
        'type' => 'kill',
        'name' => '妖精',
        'id' => 'yaojing',
      ),
      22000 => 
      array (
        'type' => 'kill',
        'name' => '妖精',
        'id' => 'yaojing',
      ),
      23000 => 
      array (
        'type' => 'kill',
        'name' => '妖精',
        'id' => 'yaojing',
      ),
      24000 => 
      array (
        'type' => 'kill',
        'name' => '妖精',
        'id' => 'yaojing',
      ),
      25020 => 
      array (
        'type' => 'kill',
        'name' => '妖精',
        'id' => 'yaojing',
      ),
      30100 => 
      array (
        'type' => 'kill',
        'name' => '妖怪',
        'id' => 'yaoguai',
      ),
      41000 => 
      array (
        'type' => 'kill',
        'name' => '妖精',
        'id' => 'yaojing',
      ),
      50100 => 
      array (
        'type' => 'kill',
        'name' => '妖怪',
        'id' => 'yaoguai',
      ),
      63000 => 
      array (
        'type' => 'kill',
        'name' => '刁钻古怪',
        'id' => 'diaozuanguguai',
      ),
      64000 => 
      array (
        'type' => 'kill',
        'name' => '古怪刁钻',
        'id' => 'guguaidiaozuan',
      ),
      65000 => 
      array (
        'type' => 'kill',
        'name' => '芦娘',
        'id' => 'luniang',
      ),
      66000 => 
      array (
        'type' => 'kill',
        'name' => '石头精',
        'id' => 'shitoujing',
      ),
      67000 => 
      array (
        'type' => 'kill',
        'name' => '龙',
        'id' => 'dragon',
      ),
      68000 => 
      array (
        'type' => 'kill',
        'name' => '蝴蝶妖',
        'id' => 'hudianyao',
      ),
      69000 => 
      array (
        'type' => 'kill',
        'name' => '看门妖',
        'id' => 'kanmenyao',
      ),
      70000 => 
      array (
        'type' => 'kill',
        'name' => '妖精',
        'id' => 'yaojing',
      ),
      81000 => 
      array (
        'type' => 'kill',
        'name' => '鲨皮怪',
        'id' => 'shark',
      ),
      91000 => 
      array (
        'type' => 'kill',
        'name' => '老虎精',
        'id' => 'laohujing',
      ),
      92000 => 
      array (
        'type' => 'kill',
        'name' => '天波尔笑',
        'id' => 'tianboerxiao',
      ),
      93000 => 
      array (
        'type' => 'kill',
        'name' => '笑波尔天',
        'id' => 'xiaoboertian',
      ),
      94000 => 
      array (
        'type' => 'kill',
        'name' => '万圣公主',
        'id' => 'wanshenggongzhu',
      ),
      95000 => 
      array (
        'type' => 'kill',
        'name' => '猕猴',
        'id' => 'meihou',
      ),
      96000 => 
      array (
        'type' => 'kill',
        'name' => '蚂蟥怪',
        'id' => 'mahuangguai',
      ),
      97000 => 
      array (
        'type' => 'kill',
        'name' => '蝙蝠精',
        'id' => 'bianfujing',
      ),
      101000 => 
      array (
        'type' => 'kill',
        'name' => '芭蕉怪',
        'id' => 'bajiaoguai',
      ),
      103000 => 
      array (
        'type' => 'kill',
        'name' => '玉面公主',
        'id' => 'yumiangongzhu',
      ),
      104000 => 
      array (
        'type' => 'kill',
        'name' => '牛魔女',
        'id' => 'niuer',
      ),
      110000 => 
      array (
        'type' => 'kill',
        'name' => '千面怪',
        'id' => 'qianmianguai',
      ),
      120000 => 
      array (
        'type' => 'kill',
        'name' => '女子',
        'id' => 'nuzi',
      ),
      120100 => 
      array (
        'type' => 'kill',
        'name' => '夫人',
        'id' => 'furen',
      ),
      120200 => 
      array (
        'type' => 'kill',
        'name' => '公公',
        'id' => 'gonggong',
      ),
      120300 => 
      array (
        'type' => 'kill',
        'name' => '蝎子精',
        'id' => 'xiezijing',
      ),
      130100 => 
      array (
        'type' => 'kill',
        'name' => '妖精',
        'id' => 'yaojing',
      ),
      130200 => 
      array (
        'type' => 'kill',
        'name' => '妖王',
        'id' => 'yaowang',
      ),
      140000 => 
      array (
        'type' => 'kill',
        'name' => '水蛛精',
        'id' => 'shuizhijing',
      ),
      150000 => 
      array (
        'type' => 'kill',
        'name' => '灵力虫',
        'id' => 'lingliching',
      ),
      160000 => 
      array (
        'type' => 'kill',
        'name' => '精细鬼',
        'id' => 'jingxigui',
      ),
      170000 => 
      array (
        'type' => 'kill',
        'name' => '巴山虎',
        'id' => 'bashanhu',
      ),
      180000 => 
      array (
        'type' => 'kill',
        'name' => '异海龙',
        'id' => 'yihailong',
      ),
      200000 => 
      array (
        'type' => 'kill',
        'name' => '万圣龙王',
        'id' => 'wanshenglongwang',
      ),
      210000 => 
      array (
        'type' => 'kill',
        'name' => '鱼象',
        'id' => 'yuxiang',
      ),
      220000 => 
      array (
        'type' => 'kill',
        'name' => '花鱼',
        'id' => 'huayu',
      ),
      230000 => 
      array (
        'type' => 'kill',
        'name' => '柳眉',
        'id' => 'liumei',
      ),
      240000 => 
      array (
        'type' => 'kill',
        'name' => '檀口',
        'id' => 'tankou',
      ),
      250000 => 
      array (
        'type' => 'kill',
        'name' => '柴头',
        'id' => 'chaitou',
      ),
      260000 => 
      array (
        'type' => 'kill',
        'name' => '金莲',
        'id' => 'jinlian',
      ),
      270000 => 
      array (
        'type' => 'kill',
        'name' => '僵群',
        'id' => 'jiangqun',
      ),
      280000 => 
      array (
        'type' => 'kill',
        'name' => '山鸡',
        'id' => 'shanji',
      ),
      310000 => 
      array (
        'type' => 'kill',
        'name' => '九头驸马',
        'id' => 'jiutoufuma',
      ),
      320000 => 
      array (
        'type' => 'kill',
        'name' => '金爪龙',
        'id' => 'goldendragon',
      ),
      330000 => 
      array (
        'type' => 'kill',
        'name' => '国丈',
        'id' => 'guozhang',
      ),
      340000 => 
      array (
        'type' => 'kill',
        'name' => '老奶奶',
        'id' => 'laonainai',
      ),
      350000 => 
      array (
        'type' => 'kill',
        'name' => '天鼠',
        'id' => 'tianshu',
      ),
      400000 => 
      array (
        'type' => 'kill',
        'name' => '妖怪',
        'id' => 'yaoguai',
      ),
      501000 => 
      array (
        'type' => 'kill',
        'name' => '狮子',
        'id' => 'yaoguai',
      ),
      700000 => 
      array (
        'type' => 'kill',
        'name' => '北力大仙',
        'id' => 'beilidaxian',
      ),
      710000 => 
      array (
        'type' => 'kill',
        'name' => '柴力大仙',
        'id' => 'chailidaxian',
      ),
      720000 => 
      array (
        'type' => 'kill',
        'name' => '狐力大仙',
        'id' => 'hulidaxian',
      ),
      730000 => 
      array (
        'type' => 'kill',
        'name' => '狼力大仙',
        'id' => 'langlidaxian',
      ),
      740000 => 
      array (
        'type' => 'kill',
        'name' => '鹿力大仙',
        'id' => 'lulidaxian',
      ),
      750000 => 
      array (
        'type' => 'kill',
        'name' => '马力大仙',
        'id' => 'malidaxian',
      ),
      760000 => 
      array (
        'type' => 'kill',
        'name' => '羊力大仙',
        'id' => 'yanglidaxian',
      ),
      770000 => 
      array (
        'type' => 'kill',
        'name' => '玉鼠',
        'id' => 'yushu',
      ),
      800000 => 
      array (
        'type' => 'kill',
        'name' => '守门牛精',
        'id' => 'shoumenniujing',
      ),
      890000 => 
      array (
        'type' => 'kill',
        'name' => '花奇大王',
        'id' => 'huaqidawang',
      ),
      900000 => 
      array (
        'type' => 'kill',
        'name' => '辟寒大王',
        'id' => 'pihandawang',
      ),
      910000 => 
      array (
        'type' => 'kill',
        'name' => '辟暑大王',
        'id' => 'pishudawang',
      ),
      920000 => 
      array (
        'type' => 'kill',
        'name' => '辟尘大王',
        'id' => 'pichendawang',
      ),
      930000 => 
      array (
        'type' => 'kill',
        'name' => '金铃怪',
        'id' => 'jinlingguai',
      ),
      1000000 => 
      array (
        'type' => 'kill',
        'name' => '金角大王',
        'id' => 'jinjiaodawang',
      ),
      1100000 => 
      array (
        'type' => 'kill',
        'name' => '银角大王',
        'id' => 'yinjiaodawang',
      ),
      1200000 => 
      array (
        'type' => 'kill',
        'name' => '独角兕大王',
        'id' => 'dujiaosidawang',
      ),
      1300000 => 
      array (
        'type' => 'kill',
        'name' => '黄眉老佛',
        'id' => 'huangmeilaofo',
      ),
      1400000 => 
      array (
        'type' => 'kill',
        'name' => '牛魔王',
        'id' => 'niumowang',
      ),
      1500000 => 
      array (
        'type' => 'kill',
        'name' => '九头狮',
        'id' => 'jiutoushi',
      ),
    ),
    'give' => 
    array (
      5 => 
      array (
        'type' => 'give',
        'name' => '女孩',
        'id' => 'girl',
      ),
      10 => 
      array (
        'type' => 'give',
        'name' => '男孩',
        'id' => 'boy',
      ),
      15 => 
      array (
        'type' => 'give',
        'name' => '小女孩',
        'id' => 'kid',
      ),
      20 => 
      array (
        'type' => 'give',
        'name' => '小男孩',
        'id' => 'kid',
      ),
      25 => 
      array (
        'type' => 'give',
        'name' => '苦力',
        'id' => 'kuli',
      ),
      30 => 
      array (
        'type' => 'give',
        'name' => '酸秀才',
        'id' => 'suanxiucai',
      ),
      40 => 
      array (
        'type' => 'give',
        'name' => '游客',
        'id' => 'youke',
      ),
      45 => 
      array (
        'type' => 'give',
        'name' => '路人',
        'id' => 'luren',
      ),
      50 => 
      array (
        'type' => 'give',
        'name' => '江湖艺人',
        'id' => 'yiren',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      55 => 
      array (
        'type' => 'give',
        'name' => '浣衣女',
        'id' => 'girl',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      60 => 
      array (
        'type' => 'give',
        'name' => '阿婆',
        'id' => 'aqipo',
      ),
      65 => 
      array (
        'type' => 'give',
        'name' => '农夫',
        'id' => 'farmer',
      ),
      70 => 
      array (
        'type' => 'give',
        'name' => '轿夫',
        'id' => 'qiaofu',
      ),
      100 => 
      array (
        'type' => 'give',
        'name' => '庙祝',
        'id' => 'miaozhu',
        'object' => '黄金(gold)',
        'amount' => 1,
      ),
      110 => 
      array (
        'type' => 'give',
        'name' => '妇人',
        'id' => 'woman',
        'object' => '黄金(gold)',
        'amount' => 1,
      ),
      121 => 
      array (
        'type' => 'give',
        'name' => '赵秀才',
        'id' => 'xiucai',
        'object' => '四书五经注疏(book)',
        'amount' => 1,
      ),
      122 => 
      array (
        'type' => 'give',
        'name' => '钱秀才',
        'id' => 'xiucai',
        'object' => '四书五经注疏(book)',
        'amount' => 1,
      ),
      123 => 
      array (
        'type' => 'give',
        'name' => '孙秀才',
        'id' => 'xiucai',
        'object' => '四书五经注疏(book)',
        'amount' => 1,
      ),
      124 => 
      array (
        'type' => 'give',
        'name' => '李秀才',
        'id' => 'xiucai',
        'object' => '四书五经注疏(book)',
        'amount' => 1,
      ),
      125 => 
      array (
        'type' => 'give',
        'name' => '周秀才',
        'id' => 'xiucai',
        'object' => '四书五经注疏(book)',
        'amount' => 1,
      ),
      126 => 
      array (
        'type' => 'give',
        'name' => '吴秀才',
        'id' => 'xiucai',
        'object' => '四书五经注疏(book)',
        'amount' => 1,
      ),
      127 => 
      array (
        'type' => 'give',
        'name' => '郑秀才',
        'id' => 'xiucai',
        'object' => '四书五经注疏(book)',
        'amount' => 1,
      ),
      128 => 
      array (
        'type' => 'give',
        'name' => '王秀才',
        'id' => 'xiucai',
        'object' => '四书五经注疏(book)',
        'amount' => 1,
      ),
      130 => 
      array (
        'type' => 'give',
        'name' => '轿夫',
        'id' => 'jiaofu',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      135 => 
      array (
        'type' => 'give',
        'name' => '轿夫头',
        'id' => 'jiaofutou',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      140 => 
      array (
        'type' => 'give',
        'name' => '唢呐手',
        'id' => 'suonashou',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      1000 => 
      array (
        'type' => 'give',
        'name' => '学童',
        'id' => 'xuetong',
        'object' => '四书五经注疏(book)',
        'amount' => 1,
      ),
      2000 => 
      array (
        'type' => 'give',
        'name' => '小童',
        'id' => 'kid',
        'object' => '四书五经注疏(book)',
        'amount' => 1,
      ),
      3000 => 
      array (
        'type' => 'give',
        'name' => '痴客',
        'id' => 'chike',
        'object' => '鸡腿(jitui)',
        'amount' => 1,
      ),
      4000 => 
      array (
        'type' => 'give',
        'name' => '农家女',
        'id' => 'nongjianu',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      5000 => 
      array (
        'type' => 'give',
        'name' => '书呆子',
        'id' => 'shudaizi',
        'object' => '四书五经注疏(book)',
        'amount' => 1,
      ),
      6000 => 
      array (
        'type' => 'give',
        'name' => '少妇',
        'id' => 'woman',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      7000 => 
      array (
        'type' => 'give',
        'name' => '美貌妇人',
        'id' => 'woman',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      8000 => 
      array (
        'type' => 'give',
        'name' => '老妇人',
        'id' => 'oldwoman',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      9000 => 
      array (
        'type' => 'give',
        'name' => '老夫',
        'id' => 'laofu',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      10000 => 
      array (
        'type' => 'give',
        'name' => '渔妇',
        'id' => 'yufu',
        'object' => '钢叉(gang cha)',
        'amount' => 1,
      ),
      11000 => 
      array (
        'type' => 'give',
        'name' => '樵夫',
        'id' => 'qiaofu',
        'object' => '大斧(bigaxe)',
        'amount' => 1,
      ),
      12000 => 
      array (
        'type' => 'give',
        'name' => '村妇',
        'id' => 'funu',
        'object' => '粉红布料(pink cloth)',
        'amount' => 1,
      ),
      13000 => 
      array (
        'type' => 'give',
        'name' => '镇上商人',
        'id' => 'shangren',
        'object' => '黄金(gold)',
        'amount' => 1,
      ),
      14000 => 
      array (
        'type' => 'give',
        'name' => '乞丐',
        'id' => 'qigai',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      15000 => 
      array (
        'type' => 'give',
        'name' => '艺人',
        'id' => 'yiren',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      16000 => 
      array (
        'type' => 'give',
        'name' => '车夫',
        'id' => 'chefu',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      17000 => 
      array (
        'type' => 'give',
        'name' => '路人',
        'id' => 'luren',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      18000 => 
      array (
        'type' => 'give',
        'name' => '老婆婆',
        'id' => 'oldwoman',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      21000 => 
      array (
        'type' => 'give',
        'name' => '瑶池水客',
        'id' => 'yaolingshuike',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      22000 => 
      array (
        'type' => 'give',
        'name' => '卖菜妇人',
        'id' => 'maicaifunu',
        'object' => '筐(kuang)',
        'amount' => 1,
      ),
      23000 => 
      array (
        'type' => 'give',
        'name' => '苦行僧',
        'id' => 'kuxingsen',
        'object' => '饭(fan)',
        'amount' => 1,
      ),
      24000 => 
      array (
        'type' => 'give',
        'name' => '脚夫',
        'id' => 'jiaofu',
        'object' => '圆头布鞋(shoes)',
        'amount' => 1,
      ),
      25000 => 
      array (
        'type' => 'give',
        'name' => '读书人',
        'id' => 'dushuren',
        'object' => '四书五经注疏(book)',
        'amount' => 1,
      ),
      26000 => 
      array (
        'type' => 'give',
        'name' => '女佣',
        'id' => 'nuyong',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      27000 => 
      array (
        'type' => 'give',
        'name' => '女仆',
        'id' => 'nupu',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      28000 => 
      array (
        'type' => 'give',
        'name' => '男仆',
        'id' => 'nanpu',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      29000 => 
      array (
        'type' => 'give',
        'name' => '饥民',
        'id' => 'jimin',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      31000 => 
      array (
        'type' => 'give',
        'name' => '瘦子',
        'id' => 'shouzi',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      32000 => 
      array (
        'type' => 'give',
        'name' => '断肠妇人',
        'id' => 'duanchangfuren',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      41000 => 
      array (
        'type' => 'give',
        'name' => '和尚',
        'id' => 'heshang',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      42000 => 
      array (
        'type' => 'give',
        'name' => '胖和尚',
        'id' => 'heshang',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      43000 => 
      array (
        'type' => 'give',
        'name' => '小和尚',
        'id' => 'heshang',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      44000 => 
      array (
        'type' => 'give',
        'name' => '瘦和尚',
        'id' => 'heshang',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      45000 => 
      array (
        'type' => 'give',
        'name' => '苦行僧',
        'id' => 'shousiseng',
        'object' => '断腕刀(duan wan dao)',
        'amount' => 1,
      ),
      46000 => 
      array (
        'type' => 'give',
        'name' => '书生',
        'id' => 'shusheng',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      52000 => 
      array (
        'type' => 'give',
        'name' => '挑灯夫',
        'id' => 'tiaodengfu',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      55000 => 
      array (
        'type' => 'give',
        'name' => '松游客',
        'id' => 'songyouke',
        'object' => '黄金(gold)',
        'amount' => 1,
      ),
      62000 => 
      array (
        'type' => 'give',
        'name' => '铁匠',
        'id' => 'tiejiang',
        'object' => '金锤(golden hammer)',
        'amount' => 1,
      ),
      63000 => 
      array (
        'type' => 'give',
        'name' => '茶客',
        'id' => 'chake',
        'object' => '芦茶(lu cha)',
        'amount' => 1,
      ),
      71000 => 
      array (
        'type' => 'give',
        'name' => '村民',
        'id' => 'cunmin',
        'object' => '金刚护法像(jingang)',
        'amount' => 1,
      ),
      72000 => 
      array (
        'type' => 'give',
        'name' => '香客',
        'id' => 'xiangke',
        'object' => '金刚护法像(jingang)',
        'amount' => 1,
      ),
      73000 => 
      array (
        'type' => 'give',
        'name' => '农人',
        'id' => 'nongren',
        'object' => '金刚护法像(jingang)',
        'amount' => 1,
      ),
      74000 => 
      array (
        'type' => 'give',
        'name' => '农夫',
        'id' => 'nongfu',
        'object' => '金刚护法像(jingang)',
        'amount' => 1,
      ),
      75000 => 
      array (
        'type' => 'give',
        'name' => '居民',
        'id' => 'jumin',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      76000 => 
      array (
        'type' => 'give',
        'name' => '市民',
        'id' => 'shimin',
        'object' => '白银(silver)',
        'amount' => 1,
      ),
      80001 => 
      array (
        'type' => 'give',
        'name' => '猎户',
        'id' => 'liehu',
        'object' => '金粉(jingu fen)',
        'amount' => 1,
      ),
      80002 => 
      array (
        'type' => 'give',
        'name' => '女猎户',
        'id' => 'liehu',
        'object' => '金粉(jingu fen)',
        'amount' => 1,
      ),
      80003 => 
      array (
        'type' => 'give',
        'name' => '猎户',
        'id' => 'liehu',
        'object' => '金粉(jingu fen)',
        'amount' => 1,
      ),
      80004 => 
      array (
        'type' => 'give',
        'name' => '女猎户',
        'id' => 'liehu',
        'object' => '金粉(jingu fen)',
        'amount' => 1,
      ),
      80005 => 
      array (
        'type' => 'give',
        'name' => '猎户',
        'id' => 'liehu',
        'object' => '金粉(jingu fen)',
        'amount' => 1,
      ),
      80006 => 
      array (
        'type' => 'give',
        'name' => '女猎户',
        'id' => 'liehu',
        'object' => '金粉(jingu fen)',
        'amount' => 1,
      ),
      80007 => 
      array (
        'type' => 'give',
        'name' => '金猎人',
        'id' => 'liehu',
        'object' => '金粉(jingu fen)',
        'amount' => 1,
      ),
      80008 => 
      array (
        'type' => 'give',
        'name' => '金猎人',
        'id' => 'liehu',
        'object' => '金粉(jingu fen)',
        'amount' => 1,
      ),
      80009 => 
      array (
        'type' => 'give',
        'name' => '金猎人',
        'id' => 'liehu',
        'object' => '跌打丸(dieda wan)',
        'amount' => 1,
      ),
      80010 => 
      array (
        'type' => 'give',
        'name' => '金猎人',
        'id' => 'liehu',
        'object' => '跌打丸(dieda wan)',
        'amount' => 1,
      ),
      80011 => 
      array (
        'type' => 'give',
        'name' => '金猎人',
        'id' => 'liehu',
        'object' => '跌打丸(dieda wan)',
        'amount' => 1,
      ),
      80012 => 
      array (
        'type' => 'give',
        'name' => '金猎人',
        'id' => 'liehu',
        'object' => '跌打丸(dieda wan)',
        'amount' => 1,
      ),
      80013 => 
      array (
        'type' => 'give',
        'name' => '女猎人',
        'id' => 'liehu',
        'object' => '跌打丸(dieda wan)',
        'amount' => 1,
      ),
      80014 => 
      array (
        'type' => 'give',
        'name' => '女猎人',
        'id' => 'liehu',
        'object' => '跌打丸(dieda wan)',
        'amount' => 1,
      ),
      80015 => 
      array (
        'type' => 'give',
        'name' => '女猎人',
        'id' => 'liehu',
        'object' => '跌打丸(dieda wan)',
        'amount' => 1,
      ),
      80016 => 
      array (
        'type' => 'give',
        'name' => '女猎人',
        'id' => 'liehu',
        'object' => '跌打丸(dieda wan)',
        'amount' => 1,
      ),
      80017 => 
      array (
        'type' => 'give',
        'name' => '女猎人',
        'id' => 'liehu',
        'object' => '金疮药(jinchuang yao)',
        'amount' => 1,
      ),
      80018 => 
      array (
        'type' => 'give',
        'name' => '女猎人',
        'id' => 'liehu',
        'object' => '金疮药(jinchuang yao)',
        'amount' => 1,
      ),
      80019 => 
      array (
        'type' => 'give',
        'name' => '女猎人',
        'id' => 'liehu',
        'object' => '金疮药(jinchuang yao)',
        'amount' => 1,
      ),
      80020 => 
      array (
        'type' => 'give',
        'name' => '女猎人',
        'id' => 'liehu',
        'object' => '金疮药(jinchuang yao)',
        'amount' => 1,
      ),
      80021 => 
      array (
        'type' => 'give',
        'name' => '女猎人',
        'id' => 'liehu',
        'object' => '金疮药(jinchuang yao)',
        'amount' => 1,
      ),
      80022 => 
      array (
        'type' => 'give',
        'name' => '女猎人',
        'id' => 'liehu',
        'object' => '金疮药(jinchuang yao)',
        'amount' => 1,
      ),
      80023 => 
      array (
        'type' => 'give',
        'name' => '女猎人',
        'id' => 'liehu',
        'object' => '金疮药(jinchuang yao)',
        'amount' => 1,
      ),
      80024 => 
      array (
        'type' => 'give',
        'name' => '女猎人',
        'id' => 'liehu',
        'object' => '金疮药(jinchuang yao)',
        'amount' => 1,
      ),
      100000 => 
      array (
        'type' => 'give',
        'name' => '老板娘',
        'id' => 'banniang',
        'object' => '黄金(gold)',
        'amount' => 1,
      ),
      100010 => 
      array (
        'type' => 'give',
        'name' => '老板娘',
        'id' => 'banniang',
        'object' => '钻石戒指(zuan jie)',
        'amount' => 1,
      ),
      100020 => 
      array (
        'type' => 'give',
        'name' => '小三',
        'id' => 'banniang',
        'object' => '金戒指(jin jie)',
        'amount' => 1,
      ),
      110000 => 
      array (
        'type' => 'give',
        'name' => '蒲牢',
        'id' => 'pulao',
        'object' => '水族令牌(yao pai)',
        'amount' => 1,
      ),
      120000 => 
      array (
        'type' => 'give',
        'name' => '狴犴',
        'id' => 'bian',
        'object' => '水族令牌(yao pai)',
        'amount' => 1,
      ),
      130000 => 
      array (
        'type' => 'give',
        'name' => '睚眦',
        'id' => 'yazi',
        'object' => '水族令牌(yao pai)',
        'amount' => 1,
      ),
      140000 => 
      array (
        'type' => 'give',
        'name' => '霸下',
        'id' => 'baxia',
        'object' => '水族令牌(yao pai)',
        'amount' => 1,
      ),
      150000 => 
      array (
        'type' => 'give',
        'name' => '螭吻',
        'id' => 'chiwen',
        'object' => '水族令牌(yao pai)',
        'amount' => 1,
      ),
      160000 => 
      array (
        'type' => 'give',
        'name' => '饕餮',
        'id' => 'taotie',
        'object' => '水族令牌(yao pai)',
        'amount' => 1,
      ),
      170000 => 
      array (
        'type' => 'give',
        'name' => '蚣蝮',
        'id' => 'gongfu',
        'object' => '水族令牌(yao pai)',
        'amount' => 1,
      ),
      180000 => 
      array (
        'type' => 'give',
        'name' => '金猊',
        'id' => 'jinni',
        'object' => '水族令牌(yao pai)',
        'amount' => 1,
      ),
      190000 => 
      array (
        'type' => 'give',
        'name' => '椒图',
        'id' => 'shutu',
        'object' => '水族令牌(yao pai)',
        'amount' => 1,
      ),
      200000 => 
      array (
        'type' => 'give',
        'name' => '曹国舅',
        'id' => 'caoguojiu',
        'object' => '太乙真经注疏(taiyi zhenjing)',
        'amount' => 1,
      ),
      210000 => 
      array (
        'type' => 'give',
        'name' => '韩湘子',
        'id' => 'hanxiangzi',
        'object' => '太乙真经注疏(taiyi zhenjing)',
        'amount' => 1,
      ),
      220000 => 
      array (
        'type' => 'give',
        'name' => '汉钟离',
        'id' => 'hanzhongli',
        'object' => '太乙真经注疏(taiyi zhenjing)',
        'amount' => 1,
      ),
      230000 => 
      array (
        'type' => 'give',
        'name' => '何仙姑',
        'id' => 'hexiangu',
        'object' => '太乙真经注疏(taiyi zhenjing)',
        'amount' => 1,
      ),
      240000 => 
      array (
        'type' => 'give',
        'name' => '蓝采和',
        'id' => 'lancaihe',
        'object' => '太乙真经注疏(taiyi zhenjing)',
        'amount' => 1,
      ),
      250000 => 
      array (
        'type' => 'give',
        'name' => '铁拐李',
        'id' => 'tieguaili',
        'object' => '太乙真经注疏(taiyi zhenjing)',
        'amount' => 1,
      ),
      260000 => 
      array (
        'type' => 'give',
        'name' => '张果老',
        'id' => 'zhangguolao',
        'object' => '太乙真经注疏(taiyi zhenjing)',
        'amount' => 1,
      ),
      270000 => 
      array (
        'type' => 'give',
        'name' => '吕洞宾',
        'id' => 'ludongbin',
        'object' => '太乙真经注疏(taiyi zhenjing)',
        'amount' => 1,
      ),
    ),
    'furniture' => 
    array (
      50 => 
      array (
        'type' => 'find',
        'name' => '菜刀',
        'id' => 'blade',
      ),
      110 => 
      array (
        'type' => 'find',
        'name' => '醋坛子',
        'id' => 'tanzi',
      ),
      120 => 
      array (
        'type' => 'find',
        'name' => '油瓶',
        'id' => 'youping',
      ),
      130 => 
      array (
        'type' => 'find',
        'name' => '布袋',
        'id' => 'bag',
      ),
      140 => 
      array (
        'type' => 'find',
        'name' => '饭盒',
        'id' => 'fanhe',
      ),
      210 => 
      array (
        'type' => 'find',
        'name' => '竹扫帚',
        'id' => 'broom',
      ),
      220 => 
      array (
        'type' => 'find',
        'name' => '雪饮杯',
        'id' => 'snowglass',
      ),
      330 => 
      array (
        'type' => 'find',
        'name' => '戒尺',
        'id' => 'ruler',
      ),
      1100 => 
      array (
        'type' => 'find',
        'name' => '挂毯',
        'id' => 'guatan',
      ),
      1200 => 
      array (
        'type' => 'find',
        'name' => '锡壶',
        'id' => 'xihu',
      ),
      1300 => 
      array (
        'type' => 'find',
        'name' => '锡碗',
        'id' => 'xiwan',
      ),
      1400 => 
      array (
        'type' => 'find',
        'name' => '雕木斜靠椅',
        'id' => 'yizi',
      ),
      1500 => 
      array (
        'type' => 'find',
        'name' => '镂空木桌',
        'id' => 'zhuozi',
      ),
      2000 => 
      array (
        'type' => 'find',
        'name' => '浴巾',
        'id' => 'yujin',
      ),
      3000 => 
      array (
        'type' => 'find',
        'name' => '水罐',
        'id' => 'shuiguan',
      ),
      4100 => 
      array (
        'type' => 'find',
        'name' => '油葫芦',
        'id' => 'youhulu',
      ),
      4200 => 
      array (
        'type' => 'find',
        'name' => '青竹筒',
        'id' => 'qingzhutong',
      ),
      5100 => 
      array (
        'type' => 'find',
        'name' => '竹篓',
        'id' => 'zhulou',
      ),
      5200 => 
      array (
        'type' => 'find',
        'name' => '水酒罐',
        'id' => 'jiuguan',
      ),
      5300 => 
      array (
        'type' => 'find',
        'name' => '清白老酒壶',
        'id' => 'jiuhu',
      ),
      5400 => 
      array (
        'type' => 'find',
        'name' => '白瓷花盆',
        'id' => 'huapen',
      ),
      5500 => 
      array (
        'type' => 'find',
        'name' => '青石花瓶',
        'id' => 'huaping',
      ),
      5600 => 
      array (
        'type' => 'find',
        'name' => '烫花竹篮',
        'id' => 'zhulan',
      ),
      5700 => 
      array (
        'type' => 'find',
        'name' => '细条竹篓',
        'id' => 'zhulou',
      ),
      5800 => 
      array (
        'type' => 'find',
        'name' => '银药盏',
        'id' => 'yinyaozhan',
      ),
      5850 => 
      array (
        'type' => 'find',
        'name' => '白布',
        'id' => 'baibu',
      ),
      5900 => 
      array (
        'type' => 'find',
        'name' => '花布',
        'id' => 'huabu',
      ),
      6000 => 
      array (
        'type' => 'find',
        'name' => '荷花灯',
        'id' => 'denglong',
      ),
      6100 => 
      array (
        'type' => 'find',
        'name' => '莲花灯',
        'id' => 'denglong',
      ),
      6150 => 
      array (
        'type' => 'find',
        'name' => '雪花灯',
        'id' => 'denglong',
      ),
      6200 => 
      array (
        'type' => 'find',
        'name' => '桔花灯',
        'id' => 'denglong',
      ),
      6250 => 
      array (
        'type' => 'find',
        'name' => '梅花灯',
        'id' => 'denglong',
      ),
      6300 => 
      array (
        'type' => 'find',
        'name' => '青龙灯',
        'id' => 'denglong',
      ),
      6350 => 
      array (
        'type' => 'find',
        'name' => '金凤灯',
        'id' => 'denglong',
      ),
      6400 => 
      array (
        'type' => 'find',
        'name' => '麒麟灯',
        'id' => 'denglong',
      ),
      6450 => 
      array (
        'type' => 'find',
        'name' => '翡翠灯',
        'id' => 'denglong',
      ),
      6500 => 
      array (
        'type' => 'find',
        'name' => '玉兔灯',
        'id' => 'denglong',
      ),
      6550 => 
      array (
        'type' => 'find',
        'name' => '兰花灯',
        'id' => 'denglong',
      ),
      6600 => 
      array (
        'type' => 'find',
        'name' => '朝阳灯',
        'id' => 'denglong',
      ),
      6650 => 
      array (
        'type' => 'find',
        'name' => '走马灯',
        'id' => 'denglong',
      ),
      6700 => 
      array (
        'type' => 'find',
        'name' => '绣屏灯',
        'id' => 'denglong',
      ),
      6750 => 
      array (
        'type' => 'find',
        'name' => '画屏灯',
        'id' => 'denglong',
      ),
      6800 => 
      array (
        'type' => 'find',
        'name' => '梦幻灯',
        'id' => 'denglong',
      ),
      6850 => 
      array (
        'type' => 'find',
        'name' => '云雾灯',
        'id' => 'denglong',
      ),
      6900 => 
      array (
        'type' => 'find',
        'name' => '金鱼灯',
        'id' => 'denglong',
      ),
      6950 => 
      array (
        'type' => 'find',
        'name' => '仙鹤灯',
        'id' => 'denglong',
      ),
      7000 => 
      array (
        'type' => 'find',
        'name' => '锦面扶手长椅',
        'id' => 'chair',
      ),
      7001 => 
      array (
        'type' => 'find',
        'name' => '锦面扶手躺椅',
        'id' => 'chair',
      ),
      7002 => 
      array (
        'type' => 'find',
        'name' => '锦面靠背长椅',
        'id' => 'chair',
      ),
      7003 => 
      array (
        'type' => 'find',
        'name' => '锦面靠背躺椅',
        'id' => 'chair',
      ),
      7004 => 
      array (
        'type' => 'find',
        'name' => '锦面长椅',
        'id' => 'chair',
      ),
      7005 => 
      array (
        'type' => 'find',
        'name' => '锦面躺椅',
        'id' => 'chair',
      ),
      7006 => 
      array (
        'type' => 'find',
        'name' => '锦面大长椅',
        'id' => 'chair',
      ),
      7007 => 
      array (
        'type' => 'find',
        'name' => '锦面大躺椅',
        'id' => 'chair',
      ),
      7008 => 
      array (
        'type' => 'find',
        'name' => '锦面折叠长椅',
        'id' => 'chair',
      ),
      7009 => 
      array (
        'type' => 'find',
        'name' => '锦面折叠躺椅',
        'id' => 'chair',
      ),
      7010 => 
      array (
        'type' => 'find',
        'name' => '锦面安乐长椅',
        'id' => 'chair',
      ),
      7011 => 
      array (
        'type' => 'find',
        'name' => '锦面安乐躺椅',
        'id' => 'chair',
      ),
      7100 => 
      array (
        'type' => 'find',
        'name' => '缎面扶手长椅',
        'id' => 'chair',
      ),
      7101 => 
      array (
        'type' => 'find',
        'name' => '缎面扶手躺椅',
        'id' => 'chair',
      ),
      7102 => 
      array (
        'type' => 'find',
        'name' => '缎面靠背长椅',
        'id' => 'chair',
      ),
      7103 => 
      array (
        'type' => 'find',
        'name' => '缎面靠背躺椅',
        'id' => 'chair',
      ),
      7104 => 
      array (
        'type' => 'find',
        'name' => '缎面长椅',
        'id' => 'chair',
      ),
      7105 => 
      array (
        'type' => 'find',
        'name' => '缎面躺椅',
        'id' => 'chair',
      ),
      7106 => 
      array (
        'type' => 'find',
        'name' => '缎面大长椅',
        'id' => 'chair',
      ),
      7107 => 
      array (
        'type' => 'find',
        'name' => '缎面大躺椅',
        'id' => 'chair',
      ),
      7108 => 
      array (
        'type' => 'find',
        'name' => '缎面折叠长椅',
        'id' => 'chair',
      ),
      7109 => 
      array (
        'type' => 'find',
        'name' => '缎面折叠躺椅',
        'id' => 'chair',
      ),
      7110 => 
      array (
        'type' => 'find',
        'name' => '缎面安乐长椅',
        'id' => 'chair',
      ),
      7111 => 
      array (
        'type' => 'find',
        'name' => '缎面安乐躺椅',
        'id' => 'chair',
      ),
      7200 => 
      array (
        'type' => 'find',
        'name' => '鹅绒扶手长椅',
        'id' => 'chair',
      ),
      7201 => 
      array (
        'type' => 'find',
        'name' => '鹅绒扶手躺椅',
        'id' => 'chair',
      ),
      7202 => 
      array (
        'type' => 'find',
        'name' => '鹅绒靠背长椅',
        'id' => 'chair',
      ),
      7203 => 
      array (
        'type' => 'find',
        'name' => '鹅绒靠背躺椅',
        'id' => 'chair',
      ),
      7204 => 
      array (
        'type' => 'find',
        'name' => '鹅绒长椅',
        'id' => 'chair',
      ),
      7205 => 
      array (
        'type' => 'find',
        'name' => '鹅绒躺椅',
        'id' => 'chair',
      ),
      7206 => 
      array (
        'type' => 'find',
        'name' => '鹅绒大长椅',
        'id' => 'chair',
      ),
      7207 => 
      array (
        'type' => 'find',
        'name' => '鹅绒大躺椅',
        'id' => 'chair',
      ),
      7208 => 
      array (
        'type' => 'find',
        'name' => '鹅绒折叠长椅',
        'id' => 'chair',
      ),
      7209 => 
      array (
        'type' => 'find',
        'name' => '鹅绒折叠躺椅',
        'id' => 'chair',
      ),
      7210 => 
      array (
        'type' => 'find',
        'name' => '鹅绒安乐长椅',
        'id' => 'chair',
      ),
      7211 => 
      array (
        'type' => 'find',
        'name' => '鹅绒安乐躺椅',
        'id' => 'chair',
      ),
      7300 => 
      array (
        'type' => 'find',
        'name' => '丝绣扶手长椅',
        'id' => 'chair',
      ),
      7301 => 
      array (
        'type' => 'find',
        'name' => '丝绣扶手躺椅',
        'id' => 'chair',
      ),
      7302 => 
      array (
        'type' => 'find',
        'name' => '丝绣靠背长椅',
        'id' => 'chair',
      ),
      7303 => 
      array (
        'type' => 'find',
        'name' => '丝绣靠背躺椅',
        'id' => 'chair',
      ),
      7304 => 
      array (
        'type' => 'find',
        'name' => '丝绣长椅',
        'id' => 'chair',
      ),
      7305 => 
      array (
        'type' => 'find',
        'name' => '丝绣躺椅',
        'id' => 'chair',
      ),
      7306 => 
      array (
        'type' => 'find',
        'name' => '丝绣大长椅',
        'id' => 'chair',
      ),
      7307 => 
      array (
        'type' => 'find',
        'name' => '丝绣大躺椅',
        'id' => 'chair',
      ),
      7308 => 
      array (
        'type' => 'find',
        'name' => '丝绣折叠长椅',
        'id' => 'chair',
      ),
      7309 => 
      array (
        'type' => 'find',
        'name' => '丝绣折叠躺椅',
        'id' => 'chair',
      ),
      7310 => 
      array (
        'type' => 'find',
        'name' => '丝绣安乐长椅',
        'id' => 'chair',
      ),
      7311 => 
      array (
        'type' => 'find',
        'name' => '丝绣安乐躺椅',
        'id' => 'chair',
      ),
      8001 => 
      array (
        'type' => 'find',
        'name' => '大木桌',
        'id' => 'table',
      ),
      8002 => 
      array (
        'type' => 'find',
        'name' => '小木桌',
        'id' => 'table',
      ),
      8003 => 
      array (
        'type' => 'find',
        'name' => '黑木桌',
        'id' => 'table',
      ),
      8004 => 
      array (
        'type' => 'find',
        'name' => '方木桌',
        'id' => 'table',
      ),
      8005 => 
      array (
        'type' => 'find',
        'name' => '圆木桌',
        'id' => 'table',
      ),
      8006 => 
      array (
        'type' => 'find',
        'name' => '白漆木桌',
        'id' => 'table',
      ),
      8007 => 
      array (
        'type' => 'find',
        'name' => '青漆木桌',
        'id' => 'table',
      ),
      8008 => 
      array (
        'type' => 'find',
        'name' => '紫漆木桌',
        'id' => 'table',
      ),
      8011 => 
      array (
        'type' => 'find',
        'name' => '大仙桌',
        'id' => 'table',
      ),
      8012 => 
      array (
        'type' => 'find',
        'name' => '小仙桌',
        'id' => 'table',
      ),
      8013 => 
      array (
        'type' => 'find',
        'name' => '黑仙桌',
        'id' => 'table',
      ),
      8014 => 
      array (
        'type' => 'find',
        'name' => '方仙桌',
        'id' => 'table',
      ),
      8015 => 
      array (
        'type' => 'find',
        'name' => '圆仙桌',
        'id' => 'table',
      ),
      8016 => 
      array (
        'type' => 'find',
        'name' => '白漆仙桌',
        'id' => 'table',
      ),
      8017 => 
      array (
        'type' => 'find',
        'name' => '青漆仙桌',
        'id' => 'table',
      ),
      8018 => 
      array (
        'type' => 'find',
        'name' => '紫漆仙桌',
        'id' => 'table',
      ),
      8021 => 
      array (
        'type' => 'find',
        'name' => '大镶玉桌',
        'id' => 'table',
      ),
      8022 => 
      array (
        'type' => 'find',
        'name' => '小镶玉桌',
        'id' => 'table',
      ),
      8023 => 
      array (
        'type' => 'find',
        'name' => '黑镶玉桌',
        'id' => 'table',
      ),
      8024 => 
      array (
        'type' => 'find',
        'name' => '方镶玉桌',
        'id' => 'table',
      ),
      8025 => 
      array (
        'type' => 'find',
        'name' => '圆镶玉桌',
        'id' => 'table',
      ),
      8026 => 
      array (
        'type' => 'find',
        'name' => '白漆镶玉桌',
        'id' => 'table',
      ),
      8027 => 
      array (
        'type' => 'find',
        'name' => '青漆镶玉桌',
        'id' => 'table',
      ),
      8028 => 
      array (
        'type' => 'find',
        'name' => '紫漆镶玉桌',
        'id' => 'table',
      ),
      8031 => 
      array (
        'type' => 'find',
        'name' => '大兽脚桌',
        'id' => 'table',
      ),
      8032 => 
      array (
        'type' => 'find',
        'name' => '小兽脚桌',
        'id' => 'table',
      ),
      8033 => 
      array (
        'type' => 'find',
        'name' => '黑兽脚桌',
        'id' => 'table',
      ),
      8034 => 
      array (
        'type' => 'find',
        'name' => '方兽脚桌',
        'id' => 'table',
      ),
      8035 => 
      array (
        'type' => 'find',
        'name' => '圆兽脚桌',
        'id' => 'table',
      ),
      8036 => 
      array (
        'type' => 'find',
        'name' => '白漆兽脚桌',
        'id' => 'table',
      ),
      8037 => 
      array (
        'type' => 'find',
        'name' => '青漆兽脚桌',
        'id' => 'table',
      ),
      8038 => 
      array (
        'type' => 'find',
        'name' => '紫漆兽脚桌',
        'id' => 'table',
      ),
      9000 => 
      array (
        'type' => 'find',
        'name' => '兽皮',
        'id' => 'shoupi',
      ),
      25000 => 
      array (
        'type' => 'find',
        'name' => '净瓶',
        'id' => 'jingping',
      ),
      30000 => 
      array (
        'type' => 'find',
        'name' => '镇妖锤',
        'id' => 'mallet',
      ),
      35000 => 
      array (
        'type' => 'find',
        'name' => '摄魂匣',
        'id' => 'camera',
      ),
      45000 => 
      array (
        'type' => 'find',
        'name' => '玻璃盘子',
        'id' => 'panzi',
      ),
      200000 => 
      array (
        'type' => 'find',
        'name' => '铁钥匙',
        'id' => 'tieyaoshi',
      ),
      1800000 => 
      array (
        'type' => 'find',
        'name' => '铁钥匙',
        'id' => 'tieyaoshi',
      ),
    ),
  ),
  'cache_size' => 30,
  'index_delta' => 20,
);
