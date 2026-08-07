<?php
/**
 * 敲晕锤 - 武器类玩具
 * Converted from LPC: d/obj/misc/mallet.c
 * Author: snowcat
 */

namespace Xyj\D\Obj\Misc;

// TODO: 此文件尚未完成从 LPC 到 Web 架构的适配
// 原依赖: std/weapon/hammer.php (LPC 对象系统)
// 需要: 重构为 Web Session + Database 模式
// 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
trigger_error('mallet.php 尚未适配 Web 架构，请完成 LPC 到 Web 的重构', E_USER_ERROR);

// use Xyj\Std\Weapon\Hammer;

class Mallet /* extends Hammer */
{
    /**
     * 创建物品
     */
    public function create()
    {
        $this->set_name("敲晕锤", ["mallet"]);
        $this->set_weight(350);
        $this->set('long', "一把用来敲人的小锤子\n");
        $this->set('unit', '把');
        $this->set('value', 50000);
        $this->set('no_get', 1);
        $this->init_hammer(1);
    }

    /**
     * 初始化命令
     */
    public function init()
    {
        // parent::init(); // TODO: 适配后恢复
        $this->add_action('do_hammer', 'za');
        $this->add_action('do_faint', 'yun');
        $this->add_action('do_maxfaint', 'zayun');
        return true;
    }

    /**
     * 在当前房间查找角色
     */
    private function is_present(string $arg, $room)
    {
        if (!$arg) {
            return null;
        }
        
        $list = $room->query('all_inventory') ?? [];
        $ob = null;
        
        foreach ($list as $item) {
            if ($item->query('id') === $arg) {
                $ob = $item;
                break;
            }
        }
        
        // 如果没有找到，尝试使用 present 函数
        if (!$ob) {
            $ob = $room->present($arg);
        }
        
        // 检查是否为角色
        if ($ob && !$ob->is_character()) {
            return null;
        }
        
        return $ob;
    }

    /**
     * 用锤子砸
     */
    public function do_hammer(string $arg)
    {
        $hits = [
            "\n$N双手抓着锤子狠狠地砸下！\n\n",
            "\n$N举起锤子，狠狠地砸下！\n\n",
            "\n$N拼命地举起重锤，然后轰然砸下！\n\n",
            "\n$N突然举起一个巨锤，然后轰然砸在地上！\n\n",
        ];

        $me = $this->getPlayer();
        if (!$me) {
            return false;
        }
        
        $room = $this->query('environment');
        if (!$room) {
            return false;
        }

        if (!$arg) {
            $this->message("要砸哪位？\n");
            return false;
        }
        
        $ob = $this->is_present($arg, $room);
        if (!$ob) {
            $this->message("这位玩家不在这里。\n");
            return false;
        }

        $this->set('value', 0);
        
        if ($me->query('sen') > 50) {
            $me->add('sen', -50);
        } else {
            $me->unconcious();
            return true;
        }

        if ($ob->query('env/invisibility') > 0) {
            $this->message_vision("$N高高举起一把巨大锤子向$n狠狠地砸下！\n", $me, $ob);
        } else {
            $this->message_vision("$N高高举起一把巨大锤子向$n狠狠地砸下！\n", $me, $ob);
        }
        
        $this->message_vision("\n只听见哐！哐！哐！\n\n一阵惊天动地的巨响。\n", $ob);
        $this->message_vision($hits[array_rand($hits)], $ob);
        
        // 移除隐身和无敌状态
        $ob->set('env/immortal', 0);
        $ob->set('env/invisibility', 0);
        
        return true;
    }

    /**
     * 使目标昏迷（巫师专用）
     */
    public function do_faint(string $arg)
    {
        if (!$this->do_hammer($arg)) {
            return false;
        }
        
        $me = $this->getPlayer();
        if (!$this->isWizard($me)) {
            return true;
        }
        
        $this->set('value', 0);
        
        if ($me->query('sen') > 50) {
            $me->add('sen', -50);
        } else {
            $me->unconcious();
            return true;
        }
        
        $room = $this->query('environment');
        $target = $this->is_present($arg, $room);
        
        $this->call_out('get_fainted', 2, [$target]);
        return true;
    }

    /**
     * 昏迷处理
     */
    public function get_fainted($ob)
    {
        // 目前注释掉，保持与原版一致
        // $ob->unconcious();
    }

    /**
     * 使目标深度昏迷（巫师专用）
     */
    public function do_maxfaint(string $arg)
    {
        if (!$this->do_hammer($arg)) {
            return false;
        }
        
        $me = $this->getPlayer();
        if (!$this->isWizard($me)) {
            return true;
        }
        
        $this->set('value', 0);
        
        if ($me->query('sen') > 50) {
            $me->add('sen', -50);
        } else {
            $me->unconcious();
            return true;
        }
        
        $room = $this->query('environment');
        $target = $this->is_present($arg, $room);
        
        $this->call_out('get_maxfainted', 2, [$target]);
        return true;
    }

    /**
     * 深度昏迷处理
     */
    public function get_maxfainted($ob)
    {
        $short = $ob->query('name') . '(' . $this->capitalize($ob->query('id')) . ')';
        
        if ($ob->query('nickname')) {
            $short = '"' . $ob->query('nickname') . '"' . $short;
        }
        
        if ($ob->query('title')) {
            $short = $ob->query('title') . $short;
        }
        
        $this->message_vision(HIR . "\n$N软绵绵的一声，一屁股坐到了地上....\n\n" . NOR, $ob);
        
        // 设置昏迷状态
        $ob->set_temp('apply/short', [$short . ' <昏迷中>']);
        $ob->set_temp('mallet_fainted', 1);
        
        // 计算昏迷时间（基于 CON 属性）
        $con = $this->query('con') ?? 10;
        $revive_time = random(100 - $con) + 50;
        
        $this->call_out('get_revived', $revive_time, [$ob, $short]);
        $this->call_out('display_fainted', random(5), [$ob]);
    }

    /**
     * 苏醒处理
     */
    public function get_revived($ob, string $short)
    {
        if (!$ob->query_temp('mallet_fainted')) {
            return;
        }

        $this->message_vision(HIR . "\n$N迷迷糊糊地站了起来。\n" . NOR, $ob);
        
        // 清除昏迷状态
        $ob->delete_temp('apply/short');
        $ob->delete_temp('mallet_fainted');
    }

    /**
     * 显示昏迷状态
     */
    public function display_fainted($ob)
    {
        $msgs = [
            "$N迷迷糊糊地翻一个身睡过去。\n",
            "$N躺在地上打滚。\n",
            "$N软绵绵地倒在地上人事不知。\n",
            "$N翻翻眼，头一歪晕了过去。\n",
            "$N努力想说什么，什么也说不出来。\n",
            "$N昏迷不醒。\n",
            "$N昏迷一阵。\n",
            "$N软软地伸手，在空中抓了一下。\n",
        ];

        if (!$ob->query_temp('mallet_fainted')) {
            return;
        }

        $this->message_vision($msgs[array_rand($msgs)], $ob);
        
        // 继续显示昏迷状态
        $this->call_out('display_fainted', random(20), [$ob]);
    }

    /**
     * 获取当前玩家
     */
    private function getPlayer()
    {
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
     * 字符串首字母大写
     */
    private function capitalize(string $str): string
    {
        if (empty($str)) {
            return '';
        }
        return ucfirst($str);
    }

    /**
     * 设置延迟调用
     */
    private function call_out(string $func_name, int $delay, array $args = [])
    {
        global $call_out_queue;
        $call_out_queue[$func_name] = [
            'func' => $func_name,
            'delay' => $delay,
            'args' => $args,
            'time' => time() + $delay,
        ];
    }
}

// 定义 ANSI 颜色常量
if (!defined('HIR')) {
    define('HIR', "\x1b[1;31m"); // 红色高亮
}
if (!defined('NOR')) {
    define('NOR', "\x1b[m"); // 普通颜色
}
