<?php
/**
 * Created by PhpStorm.
 * User: caozhaohai
 * Date: 2020-09-26
 * Time: 17:04
 */
 namespace wheatcao\fastworkflow;
 use wheatcao\fastworkflow\Gateway\GatewayTool;
 class utils{
     /**
      * 匹配
      * 条件应为case : condition 的结构 ，case 为或 ，condition 为与
      * 比如
      *    rule:
      *      -
      *        condition:
      *          key:
      *            operator:
      *            val:
      *      -
      *        condition:
      *          ...
      * 表示 (A&&B)||C
      * return
      */
     public static function rule_match($value = [] ,$rule = [])
     {
         if(!$rule) return true; //无条件，直接匹配
         foreach ($rule as $case)
         {
             //condition_match为比较函数
             if (condition_match($case['condition'], $value))
             {
                return true;
             }
         }
         return false;
     }

     /**
      * author: wheat
      * date: 11/30/20 10:01 AM
      * @param array $data
      * @param $gateway_condition
      * 返回竞争分支的匹配分支
      */
     public static function get_match_path($data = [] , $gateway_condition)
     {
         $path = '';
         while($gateway_condition)
         {
             $path_item = current($gateway_condition);
             if(self::rule_match($data, $path_item['rule']))
             {
                 $path = $path_item['path'];
                 break;
             }
             next($gateway_condition);
         }
         return $path;
     }

     /**
      * 判断是否满足会签配置条件
      */
     function co_sign_match($rsc, $condition, $act_approver_all_num)
     {
         $r = false;
         //遍历全部condition 全部符合则算符合
         foreach ($condition as $k => $v) {
             $match_key = $k;
             $match_val = $v;
             $this_r = false;
             switch (key($match_val)) {
                 case '>':
                     if ($rsc[$match_key] > (int)current($match_val)) {
                         $this_r = true;
                     }
                     break;
                 case '=':
                     if ($rsc[$match_key] == (int)current($match_val)) {
                         $this_r = true;
                     }
                     break;
                 case '<':
                     if ($rsc[$match_key] < (int)current($match_val)) {
                         $this_r = true;
                     }
                     break;
                 case 'percent_gt':
                     if ((float)($rsc[$match_key] / $act_approver_all_num) > (float)current($match_val)) {
                         $this_r = true;
                     }
                     break;
                 case 'percent_egt':
                     if ((float)($rsc[$match_key] / $act_approver_all_num) >= (float)current($match_val)) {
                         $this_r = true;
                     }
                     break;
                 case 'percent_lt':
                     if ((float)($rsc[$match_key] / $act_approver_all_num) < (float)current($match_val)) {
                         $this_r = true;
                     }
                     break;
                 case 'percent_elt':
                     if ((float)($rsc[$match_key] / $act_approver_all_num) <= (float)current($match_val)) {
                         $this_r = true;
                     }
                     break;
                 case 'eq':
                     if ((float)($rsc[$match_key] / $act_approver_all_num) == (float)current($match_val)) {
                         $this_r = true;
                     }
                     break;
                 default:
                     break;
             }
             if (!$this_r) {
                 return $r;
             }
         }
         return true;
     }

     /**
      * author: wheat
      * date: 11/30/20 4:11 PM
      * 
      */
     public static function merge_step_data($flow_step_data)
     {
         $data = [];
         foreach ($flow_step_data as $step=>$time_data)
         {
             foreach ($time_data as $k=>$v)
             {
                 if (is_array($v['data']))
                 {
                     $data = array_merge($data,$v['data']);
                 }
             }
         }
         return $data;
     }

     /**
      * 流程结构转化函数
      * 将线性流程转为树状结构
      */
     public static function structure_init($config)
     {
         $new = [];
         foreach ($config as $k=>$v){
             $path_name = $v['path_name'];
             if(!$path_name) continue; //必须指定分支，todo配置检查
             switch ($v['type']){
                 case 'UserTask': //人工任务
                     if($TokenStart)
                     {
                         $temp[$k] = $v;
                         if($ParentParallelGatewayId){
                             $temp[$k]['ParentParallelGatewayId'] = $ParentParallelGatewayId;
                         }
                         if($ParentCompetitiveGatewayId){
                             $temp[$k]['ParentCompetitiveGatewayId'] = $ParentCompetitiveGatewayId;
                         }
                     }else{
                         $new[$path_name][$k] = $v;
                     }
                     break;
                 case 'ParallelGatewayStart':
                     if($TokenStart)
                     {
                         $temp[$k] = $v;
                     }else{
                         $TokenStart = GatewayTool::MakeToken($path_name, $v['gateway_name']);
                         $ParentParallelGatewayId = $v['id'];
                         $new[$path_name][$k] = $v;
                         $new[$path_name][$TokenStart] = array(
                             'parent_path' => $path_name,
                             'type' => 'Gateway',
                             'gateway_id'=>$v['id'], //唯一
                             'gateway_name' =>$v['gateway_name'], //网关名
                             'gateway_type'=>'ParallelGateway'
                         );
                         $temp = [];
                     }
                     break;
                 case 'ParallelGatewayEnd':
                     if($TokenStart){
                         $TokenEnd = GatewayTool::MakeToken($path_name, $v['gateway_name']);
                         if($TokenStart == $TokenEnd)
                         {
                             $new[$path_name][$k] = $v;
                             $new[$path_name][$TokenStart]['item'] = self::structure_init($temp);
                             unset($TokenStart);
                             unset($ParentParallelGatewayId);
                         }else{
                             $temp[$k] = $v;
                         }
                     }
                     break;
                 case 'CompetitiveGatewayStart':
                     if($TokenStart)
                     {
                         $temp[$k] = $v;
                     }else{
                         $TokenStart = GatewayTool::MakeToken($path_name, $v['gateway_name']);
                         $ParentCompetitiveGatewayId = $v['id'];
                         $new[$path_name][$k] = $v;
                         $new[$path_name][$TokenStart] = array(
                             'parent_path' => $path_name,
                             'type' => 'Gateway',
                             'gateway_id'=>$v['id'],
                             'gateway_name' =>$v['gateway_name'],
                             'gateway_type'=>'CompetitiveGateway',
                             //竞争分支匹配规则：
                             //使用 case + condition
                             // -
                             //  path: 2
                             //  rule:
                             //    -  (隐藏case)
                             //     condition:
                             //        key:
                             //         operator:
                             //         value:
                             //        ...
                             //  逐条匹配 ，匹配上就停止
                             'CompetitiveGatewayCondition'=>$v['CompetitiveGatewayCondition']
                         );
                         $temp = [];
                     }
                     break;
                 case 'CompetitiveGatewayEnd':
                     if($TokenStart){
                         $TokenEnd = GatewayTool::MakeToken($path_name, $v['gateway_name']);
                         if($TokenStart == $TokenEnd)
                         {
                             $new[$path_name][$k] = $v;
                             $new[$path_name][$TokenStart]['item'] = self::structure_init($temp);
                             unset($TokenStart);
                             unset($ParentCompetitiveGatewayId);
                         }else{
                             $temp[$k] = $v;
                         }
                     }
                     break;
                 case 'event':
                     if($TokenStart)
                     {
                         $temp[$k] = $v;
                     }else{
                         $new[$path_name][$k] = $v;
                     }
                     break;
                 default:
                     if($TokenStart)
                     {
                         $temp[] = $v;
                     }else{
                         $new[$path_name][$k] = $v;
                     }
                     break;
             }
         }
         return $new;
     }

     /**
      * 单线搜索是否为最后一个节点
      * @param $taskid
      * @param $path
      * @return bool
      */
     public static function isEndTask($taskid, $path)
     {
         return in_array($taskid,array_column($path, 'id')) && $taskid == end($path)['id'];
     }

     /**
      * 获取网关下全部路径
      * $param $gateway_id 父级网关开始的id
      */
     public static function getGatewayPathGroup($gateway_id, $conf)
     {
         $group = [];
         $temp = [];
         foreach ($conf as $path)
         {
             foreach ($path as $v)
             {
                 if($v['type'] == 'Gateway'){
                     if($v['gateway_id'] == $gateway_id)
                     {
                         $group = $v;
                     }else{
                         $temp = array_merge($temp, (array)$v['item']);
                     }
                 }
             }
         }
         if(!$group && count($temp)>0)
         {
             $group = self::getGatewayPathGroup($gateway_id, $temp);
         }else{
             return $group;
         }
     }

     /**
      * 递归搜索该节点的下一个节点
      * 如果是并行/竞争网关分支的最后一个节点，返回网关的id和类别
      * $param $step节点的id , $conf 结构化的配置
      */
     public static function getNextTask($step, $conf)
     {
         $nextTask = [];
         $gatewayPath = []; //记录单线上的分支
         $ParentParallelGatewayId = null; //if为并行网关的分支收束节点，返回并行网关id
         $ParentCompetitiveGatewayId = null;
         $path_name = null;
         //路径
         foreach ($conf as $path)
         {
             if(!$step) //step 0
             {
                 $nextTask[] = current($path);
                 continue;
             }
             //搜索
             while ($k = key($path))
             {
                 if(current($path)['type'] == 'Gateway')
                 {
                     $gatewayPath = array_merge($gatewayPath, (array)current($path)['item']);
                 }
                 if($step == $k)
                 {
                     next($path);
                     //如果下个节点是网关节点 直接跳过
                     if(in_array(current($path)['type'], GatewayTool::$type)) next($path); //网关节点不参与计算
                     if(current($path)['type'] == 'Gateway')
                     {
                         $nextTask = self::getNextTask('', current($path)['item']);
                         break 2; //直接跳出两层循环
                     }
                     if(current($path))
                     {
                         $nextTask[] = current($path);
                         break 2;
                     }else{
                         //单线走到底部时需要考虑是否为并行分支收束
                         if($path[$step]['ParentParallelGatewayId'])
                         {
                             if(self::isEndTask($step,$path))
                             {
                                 $ParentParallelGatewayId = $path[$step]['ParentParallelGatewayId'];
                                 $path_name = $path[$step]['path_name'];
                                 break 2;
                             }
                         }
                         //如果是竞争分支收束
                         if($path[$step]['ParentCompetitiveGatewayId'])
                         {
                             if(self::isEndTask($step,$path))
                             {
                                 $ParentCompetitiveGatewayId = $path[$step]['ParentCompetitiveGatewayId'];
                                 $path_name = $path[$step]['path_name'];
                                 break 2;
                             }
                         }
                     }
                 }
                 next($path);
             }
         }
         if($ParentParallelGatewayId){
             //todo 实际收束需要全部分支到达，这里递归查询不好再反向查询上层节点，所以直接返回交给调用函数处理
             return ['path_name'=>$path_name, 'gateway_id'=>$ParentParallelGatewayId,'type'=>'ParallelGatewayEnd'];
         }
         if($ParentCompetitiveGatewayId){
             //todo 实际收束需要全部分支到达，这里递归查询不好再反向查询上层节点，所以直接返回交给调用函数处理
             return ['path_name'=>$path_name, 'gateway_id'=>$ParentCompetitiveGatewayId,'type'=>'CompetitiveGatewayEnd'];
         }
         if(!$nextTask)
         {
             if(count($gatewayPath)>0)
             {
                 $nextTask = self::getNextTask($step, $gatewayPath);
                 return $nextTask;
             }else{
                 //未找到下一个节点，且无下级分支，代表流程已结束
                 return ['type'=>'endProcess'];
             }
         }else{
             return $nextTask;
         }
     }


     /**
      * 获取并行网关结束节点后的任务节点
      * $param $gatewayid节点的id , $conf 结构化的配置
      */
     public static function getParallelGatewayNextTask($gateway_id, $conf)
     {
         $nextTask = [];
         $gatewayPath = []; //记录单线上的分支
         $ParentParallelGatewayId = null; //if为并行网关的分支收束节点，返回并行网关id
         $ParentCompetitiveGatewayId = null;
         $path_name = null;
         //路径
         foreach ($conf as $path)
         {
             //搜索
             while ($k = key($path))
             {
                 //记录单线上全部分支
                 if(current($path)['type'] == 'Gateway')
                 {
                     $gatewayPath = array_merge($gatewayPath, (array)current($path)['item']);
                 }
                 if($k == $gateway_id && current($path)['type'] == 'ParallelGatewayStart')
                 {
                     $ParallelGatewayStart = true;
                     $gateway_name = current($path)['gateway_name'];
                     next($path);
                 }
                 if($ParallelGatewayStart)
                 {
                     next($path);
                 }
                 if($ParallelGatewayStart && current($path)['type'] == 'ParallelGatewayEnd')
                 {
                     next($path);
                     $ParallelGatewayStart = false;
                     if(current($path))
                     {
                         $nextTask[] = current($path);
                         break 2;
                     }else{
                         //todo 网关结束刚好是分支收束

                     }
                 }
                 next($path);
             }
         }
         if(!$nextTask)
         {
             if(count($gatewayPath)>0)
             {
                 $nextTask = self::getParallelGatewayNextTask($gateway_id, $gatewayPath);
                 return $nextTask;
             }else{
                 //未找到下一个节点，且无下级分支，代表流程已结束
                 return ['type'=>'endProcess'];
             }
         }else{
             return $nextTask;
         }
     }

     /**
      * 获取竞争网关结束节点后的任务节点
      * $param $gatewayid节点的id , $conf 结构化的配置
      */
     public static function getCompetitiveGatewayNextTask($gateway_id, $conf)
     {
         $nextTask = [];
         $gatewayPath = []; //记录单线上的分支
         $ParentParallelGatewayId = null; //if为并行网关的分支收束节点，返回并行网关id
         $ParentCompetitiveGatewayId = null;
         $path_name = null;
         //路径
         foreach ($conf as $path)
         {
             //搜索
             while ($k = key($path))
             {
                 //记录单线上全部分支
                 if(current($path)['type'] == 'Gateway')
                 {
                     $gatewayPath = array_merge($gatewayPath, (array)current($path)['item']);
                 }
                 if($k == $gateway_id && current($path)['type'] == 'CompetitiveGatewayStart')
                 {
                     $CompetitiveGatewayStart = true;
                     $gateway_name = current($path)['gateway_name'];
                     next($path);
                 }
                 if($CompetitiveGatewayStart)
                 {
                     next($path);
                 }
                 if($CompetitiveGatewayStart && current($path)['type'] == 'CompetitiveGatewayEnd')
                 {
                     next($path);
                     $CompetitiveGatewayStart = false;
                     if(current($path))
                     {
                         $nextTask[] = current($path);
                         break 2;
                     }else{
                         //todo 网关结束刚好是分支收束

                     }
                 }
                 next($path);
             }
         }
         if(!$nextTask)
         {
             if(count($gatewayPath)>0)
             {
                 $nextTask = self::getCompetitiveGatewayNextTask($gateway_id, $gatewayPath);
                 return $nextTask;
             }else{
                 //未找到下一个节点，且无下级分支，代表流程已结束
                 return ['type'=>'endProcess'];
             }
         }else{
             return $nextTask;
         }
     }

     /**
      * 判断 $taskId的流出方向是否为并行网关的终点
      * @param $taskId
      * @param $conf
      */
     public static function findParallelGatewayId($taskId, $conf)
     {
         //即在一个分支内，且在分支的最后一个节点
         $res = '';
         $temp = [];
         foreach ($conf as $path)
         {
             if(self::isEndTask($taskId, $path))
             {
                 $res = $path[$taskId]['ParentParallelGatewayId']?:false;
                 break;
             }else{
                 foreach ($path as $v)
                 {
                     if($v['type']='Gateway')
                     {
                         $temp = array_merge($temp,$v['item']);
                     }
                 }
             }
         }
         if(!$res && count($temp)>0){
             $res = self::findParallelGatewayId($taskId, $temp);
         }else{
             return $res;
         }
     }

     /**
      * 获取可以驳回的节点
      *   驳回不可以越过并行网关，（todo）但可以越过竞争网关
      * @param $step
      * @param $conf
      */
     public static function getRejectStepList($step, $conf)
     {
         $reject_step_list = [];
         $gatewayPath = []; //记录单线上的分支
         foreach ($conf as $path)
         {
             //先判断是否在该分支
             if(in_array($step,array_keys($path)))
             {
                 while ($k = key($path))
                 {
                     //遇到并行网关就清空
                     if (in_array(current($path)['type'], ['ParallelGatewayStart', 'Gateway', 'ParallelGatewayEnd'])) {
                         $reject_step_list = [];
                         next($path);
                     }
                     if (in_array(current($path)['type'], ['CompetitiveGatewayStart', 'CompetitiveGatewayStartEnd'])) {
                         next($path);
                     }
                     if ($k == $step) {
                         break 2;
                     }
                     $reject_step_list[] = $k;
                     next($path);
                 }
             }else{
                 foreach ($path as $item)
                 {
                     if($item['type'] == 'Gateway')
                     {
                         $gatewayPath = array_merge($gatewayPath, (array)$item['item']);
                     }
                 }
             }
         }
         if($reject_step_list)
         {
             return $reject_step_list;
         }else{
             if(count($gatewayPath)>0)
             {
                 $reject_step_list = self::getRejectStepList($step, $gatewayPath);
                 return $reject_step_list;
             }else{
                 return [];
             }
         }
     }

     /**
      * 并行网关下，是否全部节点已到达
      * @param (string)$step 本次提交节点的id, (string)$gatewayid 节点所属的父级网关的id, (array)$structure_conf ,(array)$history_step
      * @return (bool) true/false
      */
     public static function ParallelGatewayEndCheck($step, $gateway_id, $structure_conf, $history_step)
     {
         $gate_way_group = self::getGatewayPathGroup($gateway_id, $structure_conf);
         $end_steps = [];
         foreach ($gate_way_group['item'] as $k=>$path)
         {
             end($path);
             $end_steps[] = current($path)['id'];
         }
         $all_history_steps = [];
         array_map(function ($v) use(&$all_history_steps){
             $all_history_steps = array_merge($all_history_steps, $v);
         }, $history_step);
         $all_history_steps[] = $step;
         $all_history_steps = array_unique($all_history_steps);
         if(count(array_intersect($all_history_steps, $end_steps)) == count($end_steps))
         {
             return true;
         }else{
             return false;
         }
     }

     /**
      * author: wheat
      * date: 2020-11-10 16:02
      * $param $from ,$to ,$conf
      * $return 返回被驳回的节点
      */
     public static function getRejectedSteps($from, $to, $conf)
     {
         $r = [];
         $gatewayPath = []; //记录单线上的分支
         foreach ($conf as $path)
         {
             //todo 需要考虑跳过竞争网关的情况，比较好的方案是将竞争网关生成的路径与上级路径直接合并拼接
             if(in_array($from,array_keys($path)) && in_array($to,array_keys($path)))
             {
                 foreach ($path as $k=>$v)
                 {
                     if($v['id'] == $to)
                     {
                         $sta = true;
                     }
                     if($sta)
                     {
                         $r[] = $v['id'];
                     }
                     if($v['id'] == $from)
                     {
                         break 2;
                     }
                 }
             }else{
                 foreach ($path as $item)
                 {
                     if($item['type'] == 'Gateway')
                     {
                         $gatewayPath = array_merge($gatewayPath, (array)$item['item']);
                     }
                 }
             }
         }

         if($r)
         {
             return $r;
         }else{
             if(count($gatewayPath))
             {
                 $r = self::getRejectedSteps($from, $to, $gatewayPath);
             }
             return $r;
         }
     }

     /**
      * 标记父或子节点
      * @todo多级嵌套的逻辑有bug ,仅支持两级分支
      */
     public static function markParentNode($conf, $sort = 'asc', $gateway_pre_node = [])
     {
         $new = [];
         foreach ($conf as $path_k =>$path)
         {
             $temp = $path;
             if($sort == 'desc'){
                 $path = array_reverse($path ,true);
             }
             $pre_node = $gateway_pre_node?:[];
             foreach ($path as $k => $v)
             {
                 switch ($v['type'])
                 {
                     case 'UserTask':
                         if($sort == 'desc'){
                             $temp[$k]['out'] = $pre_node;
                             $pre_node = [$v['id']];
                         }else{
                             $temp[$k]['in'] = $pre_node;
                             $pre_node = [$v['id']];
                         }
                         break;
                     case 'ParallelGatewayStart':
                         if($sort == 'desc')
                         {

                         }else{

                         }
                         break;
                     case 'Gateway':
                         $temp[$k]['item'] = self::markParentNode($temp[$k]['item'], $sort, $pre_node);
                         $pre_node = self::getGatewayEndTasks($temp[$k]['item'], $sort);
                         break;
                     case 'ParallelGatewayEnd':
                         if($sort == 'desc'){

                         }else{

                         }
                         break;
                 }
             }
             $new[$path_k] = $temp;
         }
         return $new;
     }

     /**
      * author: wheat
      * date: 2020-11-15 23:17
      * @param $conf
      * 返回各个path最后一个Task Node
      */
     public static function getGatewayEndTasks($conf, $type = 'asc')
     {
         $temp = [];
         foreach ($conf as $path)
         {
             if($type == 'asc')
             {
                $path = array_reverse($path);
             }
             foreach ($path as $v)
             {
                 if($v['type'] == 'UserTask')
                 {
                     $temp[] = $v['id'];
                     break;
                 }
             }
         }
         return $temp;
     }

     /**
      * author: wheat
      * date: 12/25/20 5:04 PM
      * 获取节点上一个节点
      */
     public static function getPreStep($step, $conf)
     {
         $pre_step = null;
         foreach ($conf as $path)
         {
             if(in_array($step,array_keys($path)))
             {
                 $path = array_reverse($path ,true);
                 while($val = current($path))
                 {
                     if($val['id'] == $step)
                     {
                         next($path);
                         $pre_step = current($path)['id'];
                     }
                     next($path);
                 }
             }else{
                 foreach ($path as $item)
                 {
                     if($item['type'] == 'Gateway')
                     {
                         $gatewayPath = array_merge($gatewayPath, (array)$item['item']);
                     }
                 }
             }
         }
         if($pre_step)
         {
             return $pre_step;
         }else{
             if(count($gatewayPath))
             {
                 $pre_step = self::getPreStep($step, $gatewayPath);
                 return $pre_step;
             }else{
                 return false;
             }
         }

     }
 }