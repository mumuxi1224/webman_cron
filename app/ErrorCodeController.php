<?php
namespace  app;
class ErrorCode{
    //正常code
    const SUCCESS = 200;

    //基础错误类型
    const T_LOGIN_FAIL      = 3001;//登录失败
    const T_TOKEN_FAIL      = 3002;//token失效/过期
    const T_TOKEN_AUTH      = 3003;//token认证失败
    const T_TOKEN_NOT       = 3004;//未携带token HTTP消息头未找到 Authorization 字段
    const T_VERSION_NOT     = 3005;//未携带version HTTP消息头未找到 Version 字段
    const T_VERSION_ERROR   = 3006;//Version 错误
    const T_ACTION_NOT      = 3007;//缺少 api_name 字段
    const T_ACTION_ERROR    = 3008;//api_name 参数错误
    const T_NO_AUTH         = 3009;//暂无权限操作
    const T_REQUEST_POST    = 3010;//请使用POST请求
    const T_REQUEST_GET     = 3011;//请使用GET请求
    const T_VERIFY_CODE     = 3012;//验证码错误
    const T_LOGIN_SINGLE    = 3013;//单点登录失效
    const T_INVALID_OFFLINE = 3014;//账号已失效,登录下线
    const T_TOKEN_EMPTY     = 3015;//token 参数不能为空
}