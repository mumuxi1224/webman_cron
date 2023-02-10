<?php
/**
 * Here is your custom functions.
 */

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
function isMobile(string $mobile): bool {
    if (empty($mobile)) {
        return false;
    }
    if (preg_match("/^1[3456789]\d{9}$/", $mobile)) {
        return true;
    }
    return false;
}

/**
 * 获取文件后缀名
 * @param $filename
 * @return string
 * @author guoliangchen
 * @date 2023/2/1 0001 11:55
 */
function getFileExt($filename){
    return strtolower(trim(substr(strrchr($filename, '.'), 1)));
}