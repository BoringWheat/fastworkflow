<?php
/**
 * 流程引擎入口
 * 除vendorRegister类，禁止任何I/O操作
 */

namespace wheatcao\fastworkflow;
use wheatcao\fastworkflow\Gateway\GatewayTool;
use wheatcao\fastworkflow\Event\EventTool;
use wheatcao\fastworkflow\process;
use wheatcao\fastworkflow\utils;

class flowregister{

    /**
     * @var 流程SN
     */
    protected $sn;

    /**
     * @var 流程状态
     */
    protected $state;

    /**
     * @var （array）流程当前停靠的节点数组
     */
    public $current_step;

    /**
     * @var 流程配置
     */
    protected $conf;

    /**
     * @var array 网关类型
     */
    public $gateWayTypes;

    /**
     * @var array 事件类型
     */
    public $eventTypes;

    /**
     * @var array 任务类型
     */
    public $tasks;

    /**
     * @var array 树状结构
     * fastworkflow\utils提供工具函数，也可以自行生成
     * [path:
     *   [ taskid:[],
     *     gateway:[path:[],...],
     *     ...],
     *  ...]
     */
    public $structure_conf;

    /**
     * @var array 流程历史数据（已签核的节点）
     * [['path_name': 1,'task_id'=>['a','b','c']],...]
     */
    public $history_steps;

    /**
     * flowregister constructor.
     * 静态数据初始化
     */
    public function __construct()
    {
        $this->gateWayTypes = $this->GetGatewayTypes();
        $this->eventTypes = $this->GetEventTypes();
    }

    /**
     * 注册流程
     *
     */
    public function register($option = [])
    {
        $this->sn = $option['sn'];
        $this->state = $option['state'];
        $this->conf = $option['conf'];
        $this->current_step = $option['current_step'];
        //转为树状结构，便于处理，该结构每次动态生成，不作持久化
        $this->structure_conf = utils::structure_init($option['conf']['item']);
        $this->history_steps = $option['history_steps'];
        return $this;
    }

    /**
     * 流程接受请求，返回下个节点状态
     * @param  array $action , array $history_step_data历史节点的表单数据, array $post_data 本次提交的表单数据
     */
    public function doProcess($action = [], $history_step_data = [], $post_data = [])
    {
        $option = [
            'structure_conf'=>$this->structure_conf,
            'step'=>$action['step'],
            'history_steps'=>$this->history_steps,
            'current_step' =>$this->current_step,
            'conf' =>$this->conf
        ];
        $process = new process($option);
        $result = $process($action, $history_step_data, $post_data);
        return $result;
    }

    /**
     * 获取网关类型
     */
    public function GetGatewayTypes()
    {
        return GatewayTool::$type;
    }

    /**
     * 获取事件类型
     */
    public function GetEventTypes()
    {
        return EventTool::$type;
    }

    /**
     * - 对每个节点标记 in方向的节点
     * - 对每个节点标记 out方向的节点
     */
    public function MarkStructureConf()
    {
        $structure_conf = $this->structure_conf;
        $history_steps = $this->history_steps;
        $structure_conf = utils::markParentNode($structure_conf);
        $structure_conf = utils::markParentNode($structure_conf, 'desc');
        return $structure_conf;
    }

    /**
     * author: wheat
     * date: 2020-11-09 14:06
     * @param $key
     * @return mixed
     */
    public function GetByKey($key)
    {
        return $this->$key;
    }

    /**
     * author: wheat
     * date: 2020-11-09 21:56
     * @param $step_id
     * 返回可供驳回的节点
     */
    public function GetReject($step_id)
    {
        return utils::getRejectStepList($step_id, $this->structure_conf);
    }

    /**
     * author: wheat
     * date: 12/3/20 2:10 PM
     * 外部获取竞争分支匹配路径
     */
    public function GetCompetitiveGatewayMatchPath($history_step_data, $ParentCompetitiveGatewayId)
    {
        return utils::get_match_path(utils::merge_step_data($history_step_data), $this->conf['item'][$ParentCompetitiveGatewayId]['condition']);
    }

    /**
     * author: wheat
     * date: 12/25/20 4:45 PM
     * 获取当前节点可撤销的节点 撤回不允许越过网关，不允许跨分支
     * $param $step_id
     */
    public function GetRetractStep()
    {
        $pre_step_ls = [];
        if($this->current_step && is_array($this->current_step))
        {
            foreach ($this->current_step as $k => $v)
            {
                if($pre_setp = utils::getPreStep($v, $this->conf))
                {
                    $pre_step_ls[] = $pre_setp;
                }
            }
        }
        return $pre_step_ls;
    }

}


