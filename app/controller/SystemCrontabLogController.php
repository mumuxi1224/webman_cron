<?php

namespace app\controller;

use support\Db;
use support\Request;
use support\Response;
use app\model\SystemCrontabLog;

/**
 * 定时任务日志
 */
class SystemCrontabLogController extends MyCrudController {

    protected $canNotApi = ['insert', 'update', 'delete'];

    /**
     * @var SystemCrontabLog
     */
    protected $model = null;

    /**
     * 构造函数
     * @return void
     */
    public function __construct() {
        $this->model = new SystemCrontabLog;
    }

    /**
     * 浏览
     * @return Response
     */
    public function index(): Response {
        return view('system-crontab-log/index');
    }

    public function select(Request $request): Response {
        [$where, $format, $limit, $field, $order] = $this->selectInput($request);
        $where     = $this->__formatSearch($where);
        $query     = $this->doSelect($where, $field, $order);
        $paginator = $query->paginate($limit);
        $items     = $paginator->items();
        if ($items) {
            $items        = arrayObjToArray($items);
            $crotab_ids   = array_column($items, 'crontab_id');
            $field        = ['id', 'title'];
            $crontab_info = Db::table(config('crontab.task.crontab_table'))->whereIn('id', $crotab_ids)->select($field)->get()->toArray();
            $crontab_info = array_column($crontab_info, null, 'id');
            foreach ($items as &$item) {
                $item['create_time']     = date('Y-m-d H:i:s', $item['create_time']);
                $item['return_code_msg'] = $item['return_code'] == 0 ? '成功' : '失败';
                $item['crontab_info']    = isset($crontab_info[$item['crontab_id']]) ? $crontab_info[$item['crontab_id']]->title : '';
            }
        }

        return json(['code' => 0, 'msg' => 'ok', 'count' => $paginator->total(), 'data' => $items]);
    }

    /**
     * 获取前端查询用的定时器列表
     * @param Request $request
     * @return Response
     * @author guoliangchen
     * @date 2023/1/13 0013 13:57
     */
    public function getCrontabList(Request $request) {
        $list         = [];
        $field        = ['id', 'title'];
        $crontab_info = Db::table(config('crontab.task.crontab_table'))->orderBy('id','desc')->select($field)->get()->toArray();
        foreach ($crontab_info as $item) {
            $list[] = [
                'name'  => $item->title,
                'value' => $item->id
            ];
        }
        return $this->json(0, 'ok', $list);
    }

    public function __formatSearch(array $where = []): array {
        if (!$where) {
            return [];
        }
        if (isset($where['create_time'])) {
            if (count($where['create_time']) == 2) {
                $where['create_time'][0] = strtotime($where['create_time'][0]);
                $where['create_time'][1] = strtotime($where['create_time'][1]);
            }
            else {
                unset($where['create_time']);
            }
        }
        return $where;
    }
}
