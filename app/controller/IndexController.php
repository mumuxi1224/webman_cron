<?php

namespace app\controller;

use support\Request;
use think\Validate;

class IndexController {
    public function index(Request $request) {
        return redirect('/app/admin/');
//        return view('index/index', ['name' => 'webman11']);
    }

}
