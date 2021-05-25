<?php
namespace App\Game\Logic;

use App\Game\Core\AStrategy;
use App\Game\Core\Packet;
use App\Game\Conf\MainCmd;
use App\Game\Conf\SubCmd;
use App\Task\GameSyncTask;
use Hyperf\Utils\Context;

/**
 *  游戏开始
 */
class GameStart extends AStrategy
{
    /**
     * 执行方法
     */
    public function exec()
    {
        //加入游戏房间队列里面
        $account = $this->_params['userinfo']['account'];
        $room_data = $this->getRoomData($account);
        $user_room_data = isset($room_data[$account]) ? json_decode($room_data[$account], true) : array();
        if ($user_room_data) {
            //是否产生地主
            $master = isset($room_data['master']) ? $room_data['master'] : '';
            if ($master) {
                $user_room_data['is_master'] = 1;
                if ($master == $account) {
                    //此人是地主
                    $user_room_data['master'] = 1;
                }
            } else {
                $user_room_data['is_master'] = 0;
            }

            //轮到谁出牌了
            $last_chair_id = isset($room_data['last_chair_id']) ? $room_data['last_chair_id'] : 0;
            $next_chair_id = isset($room_data['next_chair_id']) ? $room_data['next_chair_id'] : 0;
            $user_room_data['is_first_round'] = false;
            if ($next_chair_id > 0) {
                $user_room_data['index_chair_id'] = $next_chair_id;
                if ($next_chair_id == $last_chair_id) {
                    //首轮出牌
                    $user_room_data['is_first_round'] = true;
                }
            } else {
                //地主首次出牌
                if (isset($room_data[$master])) {
                    $master_info = json_decode($room_data[$master], true);
                    $user_room_data['index_chair_id'] = $master_info['chair_id'];
                    //首轮出牌
                    $user_room_data['is_first_round'] = true;
                }
            }

            //判断游戏是否结束
            $user_room_data['is_game_over'] = isset($room_data['is_game_over']) ? $room_data['is_game_over'] : false;
            //进入房间成功
            $msg = $user_room_data;
            $room_data = Packet::packFormat('OK', 0, $msg);
            $room_data = Packet::packEncode($room_data, MainCmd::CMD_SYS, SubCmd::ENTER_ROOM_SUCC_RESP);
            return $room_data;
        } else {
            $room_list = $this->getGameConf('room_list');
            if ($room_list) {
                //判断是否在队列里面
                redis()->sAdd($room_list, $this->_params['userinfo']['account']);
                //投递异步任务
                $task = container()->get(GameSyncTask::class);
                $task->gameRoomMatch($this->_params['userinfo']['fd']);
            }
            return 0;
        }
    }
}