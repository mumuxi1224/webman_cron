<?php

namespace app\controller;

use support\Db;
use support\Request;
use support\Response;
use app\model\SystemCrontabWarnHistory;
use support\exception\BusinessException;

/**
 * 预警历史 
 */
class SystemCrontabWarnHistoryController extends MyCrudController
{
    protected $canNotApi = ['insert', 'update', 'delete'];
    /**
     * @var SystemCrontabWarnHistory
     */
    protected $model = null;

    /**
     * 构造函数
     * @return void
     */
    public function __construct()
    {
        $this->model = new SystemCrontabWarnHistory;
    }
    
    /**
     * 浏览
     * @return Response
     */
    public function index(): Response
    {
        return view('system-crontab-warn-history/index');
    }

    /**
     * 插入
     * @param Request $request
     * @return Response
     * @throws BusinessException
     */
    public function insert(Request $request): Response
    {
        if ($request->method() === 'POST') {
            return parent::insert($request);
        }
        return view('system-crontab-warn-history/insert');
    }

    /**
     * 更新
     * @param Request $request
     * @return Response
     * @throws BusinessException
    */
    public function update(Request $request): Response
    {
        if ($request->method() === 'POST') {
            return parent::update($request);
        }
        return view('system-crontab-warn-history/update');
    }

    public function select(Request $request): Response {
        [$where, $format, $limit, $field, $order] = $this->selectInput($request);
        $query     = $this->doSelect($where, $field, $order);
        $paginator = $query->paginate($limit);
        $items     = $paginator->items();
        if ($items) {
            $items        = arrayObjToArray($items);
            $crontab_ids   = array_column($items, 'crontab_id');
            $field        = ['id', 'title'];
            $crontab_info = Db::table(config('crontab.task.crontab_table'))->whereIn('id', $crontab_ids)->select($field)->get()->toArray();
            $crontab_info = array_column($crontab_info, null, 'id');
            foreach ($items as &$item) {
                $item['create_time']     = date('Y-m-d H:i:s', $item['create_time']);
//                $item['update_time']     = date('Y-m-d H:i:s', $item['update_time']);
                $item['crontab_info']    = isset($crontab_info[$item['crontab_id']]) ? $crontab_info[$item['crontab_id']]->title : '';
            }
        }

        return json(['code' => 0, 'msg' => 'ok', 'count' => $paginator->total(), 'data' => $items]);
    }
}
