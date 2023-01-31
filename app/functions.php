<?php
/**
 * Here is your custom functions.
 */

/**
 * 常用的cron表达式
 * http://cron.ciding.cc/
 * @return array|string[][]
 * @author guoliangchen
 * @date 2022/12/26 0026 11:45
 */
function getCronTips(): array {
    return [
        ['cron' => '*/5 * * * * *', 'tips' => '每隔5秒执行一次'],
        ['cron' => '0 */1 * * * *', 'tips' => '每隔1分钟执行一次'],
        ['cron' => '0 0 10,14,16 * * *', 'tips' => '每天上午10点，下午2点，4点'],
        ['cron' => '0 0 5-15 * * *', 'tips' => '每天5-15点整点触发'],
        ['cron' => '0 2 1 * *', 'tips' => '每个月1号的2点执行'],
    ];
}


/**
 * 数组对象集合批量改成数据
 * @param array $dataArray
 * @return array
 * @author guoliangchen
 * @date 2023/1/9 0009 13:32
 */
function arrayObjToArray(array $dataArray = []) {
    return array_map(function ($obj) {
        if (method_exists($obj, 'toArray')) {
            return call_user_func([$obj, 'toArray']);
        }
    }, $dataArray);
}

/**
 * 验证是否是手机号
 * @param string $mobile
 * @return bool
 * @author guoliangchen
 * @date 2023/1/31 0031 13:51
 */
function isMobile(string $mobile):bool {
    if (empty($mobile)){
        return false;
    }
    if(preg_match("/^1[3456789]\d{9}$/", $mobile)){
        return true;
    }
    return false;
}