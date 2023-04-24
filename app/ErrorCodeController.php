<?php
namespace  app;
class ErrorCodeController{
    //正常code
    const SUCCESS = 200;

    //基础错误类型
    const T_REQ_TYPE_FAIL = 3001;//请求方式错误
    const T_TOKEN_FAIL = 3002;//认证失败
    const T_PARAMS_FAIL = 3003;//参数错误
    const T_CRON_FAIL = 3004;//定时器返回错误
    const T_REQ_FRE_FAIL = 3005;//请求频繁
}