<?php

namespace app\controller;

use support\Db;
use support\Request;
use support\Response;
use app\model\SystemLog;
use support\exception\BusinessException;

/**
 * 操作日志 
 */
class SystemLogController extends MyCrudController
{
    protected $canNotApi = ['insert', 'update', 'delete'];
    /**
     * @var SystemLog
     */
    protected $model = null;

    /**
     * 构造函数
     * @return void
     */
    public function __construct()
    {
        $this->model = new SystemLog;
    }
    
    /**
     * 浏览
     * @return Response
     */
    public function index(): Response
    {
        return view('system-log/index');
    }

    /**
     * 列表
     * @param Request $request
     * @return Response
     * @throws BusinessException
     * @author guoliangchen
     * @date 2023/2/1 0001 17:00
     */
    public function select(Request $request): Response {
        [$where, $format, $limit, $field, $order] = $this->selectInput($request);
        $where     = $this->__formatSearch($where);
        $query     = $this->doSelect($where, $field, $order);
        $paginator = $query->paginate($limit);
        $items     = $paginator->items();
        if ($items) {
            $items        = arrayObjToArray($items);
            $create_user_id   = array_column($items, 'create_user_id');
            $field        = ['id', 'nickname'];
            $crontab_info = Db::table('wa_admins')->whereIn('id', $create_user_id)->select($field)->get()->toArray();
            $crontab_info = array_column($crontab_info, null, 'id');
            foreach ($items as &$item) {
                $item['create_time']     = date('Y-m-d H:i:s', $item['create_time']);
//                $item['return_code_msg'] = $item['return_code'] == 0 ? '成功' : '失败';
                $item['create_user_info']    = isset($crontab_info[$item['create_user_id']]) ? $crontab_info[$item['create_user_id']]->nickname : '';
            }
        }

        return json(['code' => 0, 'msg' => 'ok', 'count' => $paginator->total(), 'data' => $items]);
    }

    /**
     * 获取前端查询用的管理员列表
     * @param Request $request
     * @return Response
     * @author guoliangchen
     * @date 2023/1/13 0013 13:57
     */
    public function getAdminList(Request $request) {
        $list         = [];
        $field        = ['id', 'nickname'];
        $crontab_info = Db::table('wa_admins')->orderBy('id','desc')->select($field)->get()->toArray();
        foreach ($crontab_info as $item) {
            $list[] = [
                'name'  => $item->nickname,
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
