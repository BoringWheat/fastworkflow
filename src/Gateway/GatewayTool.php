<?php
namespace wheatcao\fastworkflow\Gateway;

class GatewayTool{

    public static $type = array(
        //并行开始
        'ParallelGatewayStart',
        //并行结束
        'ParallelGatewayEnd',
        //竞争开始
        'CompetitiveGatewayStart',
        //竞争结束
        'CompetitiveGatewayEnd'
    );



    /**
     * 并行网关开始时，创建一个token
     */
    public static function MakeToken($sn, $step_name){
        return md5($sn.'_'.$step_name);
    }

    /**
     * 校验网关闭合
     * return bool
     */
    public static function check($flow){
        //搜索路径，检查是否有正确的闭合

    }


}