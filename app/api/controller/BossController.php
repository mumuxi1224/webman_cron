<?php
namespace app\api\controller;

use support\Request;
use think\Validate;
/**
 * 对外提供的鉴权接口
 */
class Boss
{
    public function index(Request $request) {

        return view('index/index', ['name' => 'webman11']);
    }

}