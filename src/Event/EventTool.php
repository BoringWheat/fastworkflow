<?php
namespace wheatcao\fastworkflow\Event;

class EventTool{

    public static $type = array(
        //结束
        'EventClose',
        //终止
        'EventTerminate'
    );

    public static $event2type= array(
        //结束
        'EventClose'=>'close',
        //终止
        'EventTerminate'=>'terminate'
    );

    /**
     * @param array $option
     */
    public static function filterEvent($option = [])
    {
        if($option['event'])
        {
            if(in_array($option['event'],self::$type))
            {
                return ['code'=>200,'nextTask'=>[],'status'=>$event2type[$option['event']]];
            }else{
                return ['code'=>400,'error'=>'未定义的事件'];
            }
        }else{
            return false;
        }
    }

}