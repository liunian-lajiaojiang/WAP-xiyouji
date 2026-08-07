<?php
/**
 * 击打娃娃 - 互动玩具
 * Converted from LPC: d/obj/misc/buwawa.c
 * Author: snowcat
 */

namespace Xyj\D\Obj\Misc;

// TODO: 此文件尚未完成从 LPC 到 Web 架构的适配
// 原依赖: std/item.php (LPC 对象系统)
// 需要: 重构为 Web Session + Database 模式
// 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
trigger_error('buwawa.php 尚未适配 Web 架构，请完成 LPC 到 Web 的重构', E_USER_ERROR);

// use Xyj\Std\Item;

class Buwawa /* extends Item */
{
    /**
     * 创建物品
     */
    public function create()
    {
        $this->set_name("击打娃娃", ["bu wawa", "buwawa", "wawa"]);
        $this->set_weight(250);
        $this->set('long', "一只可爱的小型的布娃娃。\n");
        $this->set('unit', '只');
        $this->set('value', 10000);
    }

    /**
     * 初始化命令
     */
    public function init()
    {
        // parent::init(); // TODO: 适配后恢复
        $this->add_action('do_setid', 'setid');
        $this->add_action('do_setname', 'setname');
        $this->add_action('do_setunit', 'setunit');
        $this->add_action('do_nie', 'nie');
        $this->add_action('do_shua', 'shua');
        return true;
    }

    /**
     * 设置物品 ID（仅巫师）
     */
    public function do_setid(string $arg): bool
    {
        $me = $this->getPlayer();
        if (!$this->isWizard($me)) {
            return false;
        }
        
        $name = $this->query("name");
        $this->set_name($name, [$arg]);
        return true;
    }

    /**
     * 设置物品名称（仅巫师）
     */
    public function do_setname(string $arg): bool
    {
        $me = $this->getPlayer();
        if (!$this->isWizard($me)) {
            return false;
        }
        
        $this->set('name', $arg);
        $unit = $this->query('unit');
        $this->set('long', "一{$unit}{$arg}。\n");
        return true;
    }

    /**
     * 设置单位量词（仅巫师）
     */
    public function do_setunit(string $arg): bool
    {
        $me = $this->getPlayer();
        if (!$this->isWizard($me)) {
            return false;
        }
        
        $this->set('unit', $arg);
        $name = $this->query('name');
        $this->set('long', "一{$arg}{$name}。\n");
        return true;
    }

    /**
     * 捏娃娃
     */
    public function do_nie(string $arg): bool
    {
        if ($arg !== $this->query('id')) {
            return false;
        }

        $dos = [
            "伸出食指轻轻点一下",
            "用手掌拍一下",
            "小心地捏一下",
            "戳一戳",
            "用手指弹一下",
            "捏捏",
            "用指尖碰一下",
        ];

        $parts = [
            "脑袋", "头发", "眼睛", "黑脸蛋", "鼻子",
            "小嘴唇", "耳朵", "眉毛", "眼睫毛", "脸蛋",
            "小鼻梁", "下巴", "小胳膊", "腿", "脚丫",
            "手", "背", "脸蛋", "小屁股",
        ];

        $actions = [
            "咧开小嘴吱吱地笑个不停。",
            "张开嘴巴啊了一声。",
            "咿咿呀呀地发出奇怪的声音。",
            "眨巴着大眼睛东张西望，一副好奇的样子。",
            "睡眼朦胧地揉着一对大眼睛。",
            "闭上眼睛舒舒服服地闭上眼睛睡着了。",
            "摇摇晃晃地站起来。",
            "迷迷糊糊地努力抬起头来。",
            "乖乖地坐在那里。",
            "瞪着大眼睛看着$N。",
            "好奇地看着$N，咧着嘴笑个不停。",
            "伸出手指头指着$N，一边咿呀一边笑。",
            "把大脑袋往$N怀里一钻。",
            "高兴地抱着$N亲了一口。",
            "撒娇地趴在$N身上。",
            "不停地眨着眉毛。",
            "小脑袋往$N怀里一钻。",
            "高兴地把眼睛睁得大大的。",
            "乐呵呵地笑得眼睛眯成一条线。",
            "露出一副天真可爱的笑容。",
            "咧开小嘴笑得口水都流出来了。",
            "睁开眼睛对$N笑个不停。",
            "笑呀笑呀。",
            "笑呀笑呀笑呀。",
            "咿咿呀呀地唱起儿歌。",
            "高兴得手舞足蹈。",
            "扭来扭去地说：我要睡觉觉，我要吃奶奶。",
            "生气地说：人家不理你啦。",
            "奶声奶气地说：小宝宝乖。",
            "奶声奶气地说：小宝宝不哭。",
            "伸出小手拉住$N的衣角。",
            "一把抓住$N的衣服摇晃着。",
            "抓着$N的衣角巴巴地看着$N。",
            "挥舞着小拳头向$N示威。",
            "抬起一只小脚向$N踢去。",
            "高兴得大喊大叫起来。",
            "扭来扭去。",
            "摸着小屁股。",
            "用小屁股撞$N一下。",
            "笑得真开心。",
            "哈哈地笑个不停。",
            "指着$N的鼻子笑个不停。",
            "笑得前仰后合，乐不可支。",
            "笑着说：叔叔阿姨好，你们是不是喜欢我呀？",
            "笑着说：你们喜欢不喜欢我呀？",
            "突然天真地说：咦，那个小朋友怎么没有来？",
            "说：等我长大了，我也要像叔叔阿姨一样勇敢。",
            "奶声奶气地说：什么时候能长大呀？",
            "说：妈妈，我要喝奶奶。",
            "说：宝宝想喝一支奶，一支大奶。",
            "奶声奶气地说：小宝宝乖，小宝宝不哭。",
            "摇着头说：宝宝不要，宝宝只要奶奶。",
            "说：妈妈在哪里呀？宝宝找不到妈妈啦。",
            "奶声奶气地说：小宝宝乖，小宝宝最乖了。",
            "细声细气地说：小宝宝乖，宝宝最乖了。",
            "说：妈妈，我要喝奶奶，好不好嘛。",
            "说：阿姨，能不能给我一支奶？",
        ];

        $str1 = "$N" . $dos[array_rand($dos)] . "$n的" . $parts[array_rand($parts)] . "。\n";
        $str2 = "$n" . $actions[array_rand($actions)] . "\n";

        $this->set('value', 0);
        
        // 移除现有的延迟调用
        $this->remove_call_out('delayed_action');
        $this->remove_call_out('delayed_reaction');
        
        // 设置延迟动作
        $me = $this->getPlayer();
        $this->call_out('delayed_action', 1, [$str1, $me, $this]);
        $this->call_out('delayed_reaction', 3, [$str2, $me, $this]);
        
        return true;
    }

    /**
     * 耍娃娃（对指定玩家）
     */
    public function do_shua(string $arg): bool
    {
        $me = $this;
        $player = $this->getPlayer();
        $my_name = $this->query("name");

        $dos = [
            "从$N手上蹦蹦跳跳地跳到$n的",
            "在$N手里突然转向$n的",
            "从$N肩膀上一跃跳到$n的",
            "在$N头顶上一转，跳向$n的",
            "欢快地奔向$n的",
            "高兴地扑向$n的",
            "兴奋地蹦到$n的",
            "一下子跳到$n的",
            "奔向$n的",
            "一个跟斗翻到$n的",
            "一小步一小步地挪到$n的",
            "努力地爬到$n的",
            "猛地一下抓到$n的",
            "伸手抓到$n的",
        ];

        $parts = [
            "脑袋上", "肩膀上", "头顶上", "后心", "水缸里",
            "怀里", "背上", "脸蛋上", "小嘴唇", "手心里",
            "脚面上", "鼻子上", "胳膊上", "小腿上", "下巴上",
            "斜肩上", "脖子上", "头发里", "耳朵里", "眉毛上",
            "大拇指上", "背上", "腿上", "手上", "脚背上",
            "膝盖上", "小手上", "脸上", "身上", "小屁股上",
            "眼圈里", "嘴巴里", "水桶里", "上衣上", "水盆里",
            "水缸上面", "裤裆里", "上衣上", "鞋壳里", "袖子上",
            "袖口上", "细腰上", "肚皮上", "屁股上", "脚心上",
            "脚背上", "腰间", "脸上",
        ];

        $actions = [
            "然后迅速后退一小步。",
            "张开小嘴就咬了一口。",
            "打了一个小哈欠。",
            "吐出一小团粘粘的口水。",
            "张口就是一口。",
            "往$n那里爬过去。",
            "张开小嘴就是一口。",
            "张开小嘴咬住$n的衣服。",
            "咬住一小块肉。",
            "咬住一块肉。",
            "咬住$n的耳朵使劲地拽。",
            "咬出一个血印子。",
            "咬出一个血疤。",
            "拔下一根毛。",
            "撕下一块皮。",
            "揪住一根细毛不放。",
            "硬是咬出一块血痕才罢休。",
            "张口就咬，疼得直叫。",
            "咬了一口就跑。",
            "咬了一下。",
            "爬到$n身上打滚。",
            "伸出一只手要抱。",
            "爬到$n直乐。",
            "用大脑袋撞了一下。",
            "用小手指在$n身上乱画。",
            "使劲用爪子抓出血痕。",
            "抓出几条血印。",
            "用双手使劲一抓。",
            "咬牙切齿地咬住$n的耳朵。",
            "用牙齿咬$n的耳朵。",
            "咬住耳朵不放。",
            "咬了个天翻地覆。",
            "咬了个稀巴烂。",
            "用牙齿咬$n。",
            "伸出一个小指头。",
            "用小指头戳了一下。",
            "伸出手指戳了一下。",
            "吐出一口痰。",
            "抓出一片血痕。",
        ];

        $returns = [
            "然后又回到$N手上。",
            "然后乖乖地回到$N手上。",
            "然后一蹦一跳地回到$N手上。",
            "然后欢快地跃回$N手上。",
            "然后一溜烟回到$N手上。",
            "然后慢慢地回到$N手上。",
            "然后高兴地回到$N手上。",
            "然后十分兴奋地回到$N手上。",
            "然后兴奋地蹦回$N手上。",
            "然后欣喜若狂地蹦回$N手上。",
        ];

        if (!$arg) {
            return false;
        }

        $who = $this->findTarget($arg);
        if (!$who) {
            $this->message("耍谁？\n");
            return false;
        }

        $this->set('value', 0);
        
        $str1 = $my_name . $dos[array_rand($dos)] . $parts[array_rand($parts)] . "上，" . $actions[array_rand($actions)] . "\n";
        $return_str = $returns[array_rand($returns)] . "\n";

        $this->message_vision("$N的" . $my_name . "十分可爱地打量着$n。\n", $player, $who);
        
        $this->remove_call_out('delayed_action');
        $this->remove_call_out('delayed_reaction');
        
        $this->call_out('delayed_action', 3, [$str1, $player, $who]);
        $this->call_out('delayed_reaction', 4, [$return_str, $player, $who]);
        
        return true;
    }

    /**
     * 延迟动作
     */
    public function delayed_action(string $str, object $ob1, object $ob2)
    {
        $this->message_vision($str, $ob1, $ob2);
    }

    /**
     * 延迟反应
     */
    public function delayed_reaction(string $str, object $ob1, object $ob2)
    {
        $this->message_vision($str, $ob1, $ob2);
    }

    /**
     * 获取当前玩家
     */
    private function getPlayer()
    {
        // 这里需要从当前上下文中获取玩家对象
        // 在 Web 环境中，这通常通过 session 或全局变量实现
        global $current_player;
        return $current_player ?? null;
    }

    /**
     * 检查是否为巫师
     */
    private function isWizard($player): bool
    {
        if (!$player) {
            return false;
        }
        return $player->query('wizard') ?? false;
    }

    /**
     * 查找目标
     */
    private function findTarget(string $arg)
    {
        // 在当前环境中查找目标
        $room = $this->query('environment');
        if (!$room) {
            return null;
        }
        
        // 查找房间中的角色
        $characters = $room->query('characters') ?? [];
        foreach ($characters as $char) {
            $ids = $char->query('id') ?? [];
            if (in_array($arg, $ids) || $char->query('id') === $arg) {
                return $char;
            }
        }
        
        return null;
    }

    /**
     * 发送消息
     */
    private function message(string $msg)
    {
        $player = $this->getPlayer();
        if ($player) {
            $player->receive_message($msg);
        }
    }

    /**
     * 发送视觉消息
     */
    private function message_vision(string $msg, $ob1, $ob2 = null)
    {
        $player = $this->getPlayer();
        if ($player) {
            // 替换消息中的变量
            $msg = str_replace('$N', $ob1->query('name') ?? '某人', $msg);
            if ($ob2) {
                $msg = str_replace('$n', $ob2->query('name') ?? '某人', $msg);
            }
            $player->receive_message($msg);
        }
    }

    /**
     * 移除延迟调用
     */
    private function remove_call_out(string $func_name)
    {
        // 在 Web 环境中实现延迟调用管理
        // 这通常需要一个任务队列系统
        global $call_out_queue;
        if (isset($call_out_queue[$func_name])) {
            unset($call_out_queue[$func_name]);
        }
    }

    /**
     * 设置延迟调用
     */
    private function call_out(string $func_name, int $delay, array $args = [])
    {
        // 在 Web 环境中实现延迟调用
        // 可以使用 JavaScript 的 setTimeout 或类似机制
        global $call_out_queue;
        $call_out_queue[$func_name] = [
            'func' => $func_name,
            'delay' => $delay,
            'args' => $args,
            'time' => time() + $delay,
        ];
    }
}
