<?php

namespace App\Game\Logic;

use App\Game\Conf\MainCmd;
use App\Game\Conf\SubCmd;
use App\Game\Core\AStrategy;
use App\Game\Core\DdzPoker;
use App\Game\Core\Packet;
use Hyperf\Redis\Redis;
use Hyperf\Utils\ApplicationContext;
use Swoole\Server;

class JoinRoom extends AStrategy
{
    /**
     * @var Redis
     */
    private $redis;

    public function __construct($params = array())
    {
        parent::__construct($params);
        $this->redis = ApplicationContext::getContainer()->get(Redis::class);
    }

    public function exec()
    {
        /** @var Server $serv */
        $serv = server();
        $game_conf = config('game');
        $account = $this->_params['userinfo']['account'];
        /**
         * 在临时房间中先查询是否有该房间号 如果有看集合的数是否凑成了3个人
         * 如果没有 则获取一个最新的NO来作为临时房间号
         */
        $room_no = $this->_params['data']["room_no"];
        /** 这是一个无序集合set */
        $user_room_temp_key = sprintf($game_conf['user_room_temp_no'], $room_no);
        $len = $this->redis->sCard($user_room_temp_key);

        if ($len >= 1 && $len < 3) { // 说明这个房间已经建立了
            $this->redis->sAdd($user_room_temp_key, $account);
            $len = $this->redis->sCard($user_room_temp_key);
            if ($len >= 3) { // 加入后如果长度大于3则可以开房间发牌了
                //匹配成功, 下发手牌数据, 并进入房间数据
                $users = $users_key = $fds = array();
                for ($i = 0; $i < 3; $i++) {
                    $account = $this->redis->sPop($user_room_temp_key);
                    $key = sprintf($game_conf['user_bind_key'], $account);
                    //根据账号获取fd
                    $fds[$account] = $this->redis->get($key);
                    //获取账号数
                    $users[] = $account;
                }

                //存入房间号和用户对应关系
                foreach ($users as $v) {
                    $user_key = sprintf($game_conf['user_room'], $v);
                    $user_room[$user_key] = $room_no;
                }

                if (!empty($user_room)) {
                    $this->redis->mset($user_room);
                }

                //随机发牌
                $obj = new DdzPoker();
                $card = $obj->dealCards($users);

                //存入用户信息
                $room_data = array(
                    'room_no' => $room_no,
                    'hand' => $card['card']['hand']
                );
                foreach ($users as $k => $v) {
                    $room_data['uinfo'][] = $v;
                    $room_data[$v] = array(
                        'card' => $card['card'][$v],
                        'chair_id' => ($k + 1)
                    );
                }
                $user_room_data_key = sprintf($game_conf['user_room_data'], $room_no);
                $this->arrToHashInRedis($room_data, $user_room_data_key);
                $this->redis->del($user_room_temp_key);
                //分别发消息给三个人
                foreach ($users as $k => $v) {
                    if (isset($fds[$v])) {
                        $data = Packet::packFormat('OK', 0, $room_data[$v]);
                        $data = Packet::packEncode($data, MainCmd::CMD_SYS, SubCmd::ENTER_ROOM_SUCC_RESP);

                        $serv->push($fds[$v], $data, WEBSOCKET_OPCODE_BINARY);
                    }
                }
            } else {
                //匹配失败， 请继续等待
                $msg = array(
                    'status' => 'fail',
                    'msg' => '人数不够3人，请耐心等待!'
                );
                $data = Packet::packFormat('OK', 0, $msg);
                $data = Packet::packEncode($data, MainCmd::CMD_SYS, SubCmd::ENTER_ROOM_FAIL_RESP);
                $serv->push($this->_params['userinfo']['fd'], $data, WEBSOCKET_OPCODE_BINARY);
            }
        } elseif ($len == 0) { // 说明该房间未建立 并且判断是否跟已有房间号冲突
            $game_key = $this->getGameConf("user_room_data");
            if ($game_key) {
                $user_room_key = sprintf($game_key, $room_no);
                $user_room_data = redis()->hGetAll($user_room_key);
                if ($user_room_data) {
                    //匹配失败， 请继续等待
                    $msg = array(
                        'status' => 'fail',
                        'msg' => '该房间号已经满人，请输入其他房间号，谢谢!'
                    );
                    $data = Packet::packFormat('OK', 0, $msg);
                    $data = Packet::packEncode($data, MainCmd::CMD_SYS, SubCmd::ENTER_ROOM_FAIL_RESP);
                    $serv->push($this->_params['userinfo']['fd'], $data, WEBSOCKET_OPCODE_BINARY);
                } else {
                    $this->redis->sAdd($user_room_temp_key, $account);
                    //匹配失败， 请继续等待
                    $msg = array(
                        'status'=>'fail',
                        'msg'=>'人数不够3人，请耐心等待!'
                    );
                    $data = Packet::packFormat('OK', 0, $msg);
                    $data = Packet::packEncode($data, MainCmd::CMD_SYS, SubCmd::ENTER_ROOM_FAIL_RESP);
                    $serv->push($this->_params['userinfo']['fd'], $data, WEBSOCKET_OPCODE_BINARY);
                }
            }
        }

    }
}