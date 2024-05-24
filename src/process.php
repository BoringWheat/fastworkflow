<?php
/**
 * 节点操作类
 *
 */
namespace wheatcao\fastworkflow;
use wheatcao\fastworkflow\Event\EventTool;
use wheatcao\fastworkflow\Vendor\vendorRegister;
class process{
    /**
     * @var array 树状结构
     * fastworkflow\utils提供工具函数，也可以自行生成
     */
    public $structure_conf;

    /**
     * 审批触发的节点
     */
    public $step;

    /**
     * @var 已经过的节点 [path: [], ...]
     */
    public $history_steps;

    /**
     * @var 当前节点流入
     */
    public $IncomingTransitions;

    /**
     * @var 当前节点流出
     */
    public $OutgoingTransition;

    /**
     * @var 流程原始线性配置
     */
    public $conf;

    public function __construct($option)
    {
        $this->structure_conf = $option['structure_conf'];
        $this->step = $option['step'];
        $this->history_steps = $option['history_steps'];
        $this->current_step = $option['current_step'];
        $this->conf = $option['conf'];
    }

    public function __invoke($option, $history_step_data, $post_data)
    {
        return $this->commitProcess($option, $history_step_data, $post_data);
    }

    /**
     * 处理用户提交，返回提交后的状态
     * @return (array)
     *      code 200 正常 || 400 未定义的事件 ||
     *      nextTask array  下一个审批节点
     *      status string 状态描述 close || terminate
     *      error 异常描述
     */
    function commitProcess($option, $history_step_data = [], $post_data = [])
    {
        //允许注入定制逻辑
        if($option['vendor'])
        {
            $vendor = new vendorRegister();
            if(is_callable(array($vendor,$option['vendor']))){
                $res = call_user_func([$vendor,$option['vendor']],[]);
                return $res;
            }
        }else{
            $next = [];
            //处理提交
            switch ($option['action']){
                case 'commit':
                    $next = $this->passProcess($option, $history_step_data, $post_data);
                    break;
                case 'jointProcess':
                    $next = $this->jointProcess($option ,$history_step_data, $post_data);
                    break;
                case 'reject':
                    $next = $this->findBackAvtivity($option);
                    break;
                default:
                    break;
            }
            return $next;
        }
        return false;
    }

    /**
     * 通过节点
     * 1.检查当前节点是否允许通过
     * 2.获取下一个节点
     *   如果为网关开始，返回支线集合（竞争排他网关，则匹配condition）；如果为网关结束，返回网关下一个节点
     *   如果下一个节点为空，表示流程结束，触发 "EventClose" 事件
     *
     */
    function passProcess($option, $history_step_data, $post_data)
    {
        if(!$next = EventTool::filterEvent($option))
        {
            $next = $this->findNextAvtivity($option, $history_step_data, $post_data);
        }
        return $next;
    }

    /**
     * 会签操作
     * @param $option
     * 此部分沿用内部业务逻辑
     * @return
     *
     */
    function jointProcess($option, $history_step_data, $post_data)
    {
        $step = $option['step'];
        $co_sign_conf = $this->getCosignConf($step);
        $act_approver = []; //usrid : 票数
        if($history_step_data[$step])
        {
            foreach ($history_step_data[$step] as $k=>$v)
            {
                $act_approver[$v['uid']] = $co_sign_conf['completionCondition']['co_sign_p'][$v['uid']]?:1; //默认一票
            }
        }
        
        $act_approver[$post_data['uid']] = $co_sign_conf['completionCondition']['co_sign_p'][$post_data['uid']]?:1; //默认一票
        if($co_sign_conf['completionCondition']['count_type'] == 'end')
        {
            if(count(array_diff($co_sign_conf['collection'],array_keys($act_approver)))>0)
            {
                $next = array(
                    'code'=>200,
                    'nextTask'=>[], //本次提交获取的下一步审批节点
                    'current_step' => $this->current_step, //返回最新的节点情况
                    'status' => 'waitjointProcessEnd'
                );
            }else{
                $r = $this->getCosignResult($co_sign_conf, $history_step_data, $post_data);
                switch ($r['status'])
                {
                    case 'next':
                        $next = $this->findNextAvtivity($option, $history_step_data, $post_data);
                        //添加返回的结果用于_hook
                        $next['jointProcessResult'] = $r['result'];
                        break;
                    case 'continue': //配置错误
                        $next = array(
                            'code'=>400,
                            'nextTask'=>[],
                            'current_step' => [],
                            'status' => 'error'
                        );
                        break;
                    case 'terminate':
                        $next = array(
                            'code'=>200,
                            'nextTask'=>[],
                            'current_step' => 'terminate',
                            'status' => 'terminate'
                        );
                        $next['jointProcessResult'] = $r['result'];
                        break;
                }
            }
        }else{
            $r = $this->getCosignResult($co_sign_conf, $history_step_data, $post_data);
            switch ($r['status'])
            {
                case 'next':
                    $next = $this->findNextAvtivity($option, $history_step_data, $post_data);
                    $next['jointProcessResult'] = $r['result'];
                    break;
                case 'continue':
                    $next = array(
                        'code'=>200,
                        'nextTask'=>[],
                        'current_step' => $this->current_step,
                        'status' => 'waitjointProcessEnd'
                    );
                    break;
                case 'terminate':
                    $next = array(
                        'code'=>200,
                        'nextTask'=>[],
                        'current_step' => 'terminate',
                        'status' => 'terminate'
                    );
                    $next['jointProcessResult'] = $r['result'];
                    break;
            }
        }
        return $next;
    }


    /**
     * author: wheat
     * date: 12/18/20 10:42 AM
     * @param $step
     * 读取配置
     */
    function getCosignConf($step)
    {
        $co_sign_conf = $this->conf['item'][$step];
        return $co_sign_conf;
    }

    /**
     *统计count key的汇总结果
     * @return
     */
    function getCosignResult($co_sign_conf, $history_step_data, $post_data)
    {
        $data = $history_step_data[$co_sign_conf['id']];
        $data[$post_data['post_time']] = $post_data;
        $rsc = [];
        $count_key = $co_sign_conf['completionCondition']['count_key'];
        $ypfj_usr = $co_sign_conf['completionCondition']['ypfj_usr'];
        $condition = [];
        foreach ($co_sign_conf['completionCondition'] as $k => $v) {
            if (is_array($v) && isset($v['condition'])) {
                $condition['co_sign_' . $k] = $v;
            }
        }
        if(count($ypfj_usr)>0)
        {
            $ypfj_model = true;
            $ypfj_conf = $co_sign_conf['completionCondition']['ypfj'];
        }
        $rsc = [];
        $ypfj_act_usr = []; //记录
        foreach ($data as $v)
        {
            $v['data']['action'] = $v['action'];
            $val = $v['data'][$count_key];
            $p = $co_sign_conf['completionCondition']['co_sign_p'][$v['uid']]?:1;
            $rsc[$val] = $rsc[$val] ? $rsc[$val]+$p : $p;
            if($ypfj_model && in_array($val,$ypfj_conf['value']))
            {
                $ypfj_act_usr[] = $v['uid'];
            }
        }
        //判断是否触发一票否决
        if($ypfj_model && count(array_intersect($ypfj_act_usr, $ypfj_usr))>0)
        {
            $ypfj_result = true;
        }

        if($ypfj_result)
        {
            $r_map['result'] = 'co_sign_'.$ypfj_conf['result'];
            $r_map['status'] = 'terminate';
        }else{ //依次匹配
            $act_approver_all_num = count($co_sign_conf['completionCondition']['collection']);
            if($condition){
                foreach ($condition as $k=>$v)
                {
                    $co_sign_match = utils::co_sign_match($rsc, $v['condition'], $act_approver_all_num);
                    if ($co_sign_match) {
                        $r_map['result'] = $k;
                        $r_map['status'] = 'next';
                    }
                }
            }else{
                $r_map['result'] = "";
                $r_map['status'] = 'next';
            }
        }
        //todo 需要区分是否有异常
        if(!$r_map){
            $r_map = ['co_sign_result' => '', 'status'=>'continue'];
        }
        return $r_map;
    }

    /**
     * 驳回
     * $return （array）被驳回的节点的id
     */
    function findBackAvtivity($option)
    {
        $rejected_list = utils::getRejectedSteps($option['from'], $option['to'], $this->structure_conf,$this->conf);
        return $rejected_list;
    }

    /**
     * 获取当前节点最近的流入节点
     */
    function filterLastAvtivity()
    {

    }

    /**
     * 获取下一个节点集合
     * 依据历史，和本次提交 判断是否全部分支到达
     * return nextTask=>[path_name: current_step_id]
     */
    function findNextAvtivity($option, $history_step_data, $post_data)
    {
        $conf = $this->structure_conf;
        $step = $this->step;
        $nextTask = utils::getNextTask($step, $conf);
        //[step:[time:[],]]
        $flow_step_data = $history_step_data;
        $flow_step_data[$post_data['step']][$post_data['post_time']] = $post_data;
        //更好的设计是定义常量
        $code = 200;
        $status = '';
        $current_step = $this->current_step;
        switch ($nextTask['type'])
        {
            case 'ParallelGatewayEnd':
                //判断是否可以前进
                $gateway_id = $nextTask['gateway_id'];
                $gateway_path = utils::getGatewayPathGroup($gateway_id, $conf);
                $gateway_path_keys = array_keys($gateway_path['item']);
                if(utils::ParallelGatewayEndCheck($step, $gateway_id, $conf, $this->history_steps))
                {
                    //$current_step注销该网关下全部的审批节点
                    foreach ($gateway_path_keys as $k=>$v)
                    {
                        unset($current_step[$v]);
                    }
                    //找到对应网关的下一个节点
                    $nextTask = utils::getParallelGatewayNextTask($gateway_id, $conf);
                    if($nextTask['type'] == 'endProcess')
                    {
                        $status = 'endProcess';
                        $current_step = 'close';
                    }else{
                        foreach ($nextTask as $k=>$v)
                        {
                            $current_step[$v['path_name']] = $v['id'];
                        }
                    }
                }else{
                    $nextTask['type'] = 'WaitForParentParallelGatewayEnd';
                    $status = 'WaitForParentParallelGatewayEnd';
                    foreach ($this->current_step as $path_name =>$step_id)
                    {
                        if($step_id == $step)
                        {
                            unset($current_step[$path_name]);
                        }
                    }
                }
                break;
            case 'CompetitiveGatewayEnd':
                //直接获取下一个节点
                $gateway_id = $nextTask['gateway_id'];
                $gateway_path = utils::getGatewayPathGroup($gateway_id, $conf);
                $gateway_path_keys = array_keys($gateway_path['item']);
                //$current_step注销该网关下全部的审批节点
                foreach ($gateway_path_keys as $k=>$v)
                {
                    unset($current_step[$v]);
                }
                $nextTask = utils::getCompetitiveGatewayNextTask($gateway_id, $conf);
                if($nextTask['type'] == 'endProcess')
                {
                    $status = 'endProcess';
                    $current_step = 'close';
                }else{
                    foreach ($nextTask as $k=>$v)
                    {
                        $current_step[$v['path_name']] = $v['id'];
                    }
                }
                break;
            case 'endProcess':
                $status = 'endProcess';
                $current_step = 'close';
                $nextTask = [];
                break;
            default:
                $current_step_path = $this->conf['item'][$step]['path_name'];
                $temp = []; //临时记录下一步审批节点
                $ParentCompetitiveGatewayId = '';
                foreach ($nextTask as $k=>$v)
                {
                    $temp[$v['path_name']] = $v['id'];
                    if(!$ParentCompetitiveGatewayId && $v['ParentCompetitiveGatewayId'])
                    {
                        $ParentCompetitiveGatewayId = $v['ParentCompetitiveGatewayId'];
                    }
                }
                //由上级分支进入竞争分支 判断具体哪个分支
                if(!$temp[$current_step_path] && $ParentCompetitiveGatewayId)
                {

                    $path_name = utils::get_match_path(utils::merge_step_data($flow_step_data), $this->conf['item'][$ParentCompetitiveGatewayId]['condition']);
                    if($path_name)
                    {
                        unset($current_step[$current_step_path]);
                        foreach ($nextTask as $k => $v)
                        {
                            if((int)$v['path_name'] != (int)$path_name)
                            {
                                unset($nextTask[$k]);
                            }
                        }
                    }else{
                        $code = '400';
                        $nextTask = [];
                    }
                }

                if(!$temp[$current_step_path])
                {
                    unset($current_step[$current_step_path]);
                }

                foreach ($nextTask as $k=>$v)
                {
                    $current_step[$v['path_name']] = $v['id'];
                }
                break;
        }
        $next = array(
            'code'=>$code,
            'nextTask'=>$nextTask, //本次提交获取的下一步审批节点
            'current_step' => $current_step, //返回最新的节点情况
            'status' => $status //状态描述
        );
        return $next;
    }


    /**
     * 判断并行网关是否已全部到达
     *  -- 仅通过现有签核节点的id判断
     */
    public function isParallelGatewayEnd($step_id, $gateway_id)
    {
        $conf = $this->structure_conf;
        //获取该网关下
    }



    /**
     * 获取网关下一个节点
     *
     *
     */
    function getGatewayNextTask($gatewayid, $conf)
    {

    }


    /**
     * 流程转向
     * @param $activityId 目标节点的id
     */
    function turnTransition($activityId)
    {

    }



}