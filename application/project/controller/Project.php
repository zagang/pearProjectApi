<?php

namespace app\project\controller;

use app\common\Model\Member;
use app\common\Model\MemberAccount;
use app\common\Model\Notify;
use app\common\Model\ProjectCollection;
use app\common\Model\ProjectMember;
use app\common\Model\SystemConfig;
use controller\BasicApi;
use OSS\Core\OssException;
use service\FileService;
use service\NodeService;
use service\RandomService;
use think\Db;
use think\Exception;
use think\exception\PDOException;
use think\facade\Request;
use think\File;

/**
 */
class Project extends BasicApi
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->model) {
            $this->model = new \app\common\Model\Project();
        }
    }

    /**
     * 显示资源列表
     *
     * @return void
     * @throws \think\exception\DbException
     */
    public function index()
    {
        $where = [];
        $type = Request::post('type');
        $data = Request::only('recycle,archive,all');
        $member = getCurrentMember();

        $where[] = ['member_code', '=', $member['code']];
        if ($type == 'my' || $type == 'other') {
            $projectMemberModel = new ProjectMember();
            $list = $projectMemberModel->_list($where);
        } else {
            $projectCollectionModel = new ProjectCollection();
            $where[] = ['member_code', '=', $member['code']];
            $list = $projectCollectionModel->_list($where);
        }
        $newList = [];
        if ($list['list']) {
            $currentMember = getCurrentMember();
            foreach ($list['list'] as $key => &$item) {
                $delete = false;
                $project = $this->model->where(['code' => $item->project_code])->find();
                if (!$project) {
                    $delete = true;
                }
                if ($type != 'other') {
                    if ($project['deleted']) {
                        $delete = true;
                    }
                }
                if (isset($data['archive']) && !$project['archive']) {
                    $delete = true;
                }
                if (isset($data['recycle']) && !$project['deleted']) {
                    $delete = true;
                }
                if ($delete) {
                    continue;
                }
                $item['collected'] = false;
                $item['owner_name'] = '-';
                if (isset($item['project_code'])) {
                    $item['code'] = $item['project_code'];
                    $item = $this->model->where(['code' => $item['code']])->find();
                }
                $collected = ProjectCollection::where(['project_code' => $item['code'], 'member_code' => $currentMember['code']])->field('id')->find();
                if ($collected) {
                    $item['collected'] = true;
                }

                $owner = ProjectMember::where(['project_code' => $item['code'], 'is_owner' => 1])->field('member_code')->find();
                if (!$owner) {
                    continue;
                }
                $member = Member::where(['code' => $owner['member_code']])->field('name')->find();
                if (!$member) {
                    continue;
                }
                $item['owner_name'] = $member['name'];
                $newList[] = $item;
            }
        }
        $this->success('', ['list' => $newList, 'total' => count($newList)]);
    }

    /**
     * 获取自己的项目
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function selfList()
    {
        $type = Request::post('type');
        $memberCode = Request::post('memberCode', '');
        if (!$memberCode) {
            $member = getCurrentMember();
        } else {
            $member = Member::where(['code' => $memberCode])->find();
        }
        $deleted = 1;
        if (!$type) {
            $deleted = 0;
        }
        $list = $this->model->getMemberProjects($member['code'], $deleted, Request::post('page'), Request::post('pageSize'));
        if ($list['list']) {
            foreach ($list['list'] as $key => &$item) {
                $item['collected'] = false;
                $item['owner_name'] = '-';
                if (isset($item['project_code'])) {
                    $item['code'] = $item['project_code'];
                    $item = $this->model->where(['code' => $item['code']])->find();
                }
                $collected = ProjectCollection::where(['project_code' => $item['code'], 'member_code' => getCurrentMember()['code']])->field('id')->find();
                if ($collected) {
                    $item['collected'] = true;
                }

                $owner = ProjectMember::where(['project_code' => $item['code'], 'is_owner' => 1])->field('member_code')->find();
                $member = Member::where(['code' => $owner['member_code']])->field('name')->find();
                $item['owner_name'] = $member['name'];
            }
        }
        $this->success('', $list);
    }

    /**
     * 新增
     *
     * @param Request $request
     * @return void
     * @throws \Exception
     */
    public function save(Request $request)
    {
        $data = $request::only('name,description,templateCode');
        if (!$request::post('name')) {
            $this->error("请填写项目名称");
        }
        $data['organization_code'] = getCurrentOrganizationCode();
        $member = getCurrentMember();
        try {
            $result = $this->model->createProject($member['code'], $data['organization_code'], $data['name'], $data['description'], $data['templateCode']);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), $e->getCode());;
        }
        if ($result) {
            $this->success('', $result);
        }
        $this->error("操作失败，请稍候再试！");
    }

    /**
     * 获取信息
     *
     * @param Request $request
     * @return void
     * @throws \think\Exception\DbException
     */
    public function read(Request $request)
    {
        $project = $this->model->where(['code' => $request::post('projectCode')])->field('id', true)->find();
        if (!$project) {
            $this->notFound();
        }
        $project['collected'] = false;
        $collected = ProjectCollection::where(['project_code' => $project['code'], 'member_code' => getCurrentMember()['code']])->field('id')->find();
        if ($collected) {
            $project['collected'] = true;
        }
        $item['owner_name'] = '';
        $item['owner_avatar'] = '';
        $owner = ProjectMember::where(['project_code' => $project['code'], 'is_owner' => 1])->field('member_code')->find();
        if ($owner) {
            $member = Member::where(['code' => $owner['member_code']])->field('name,avatar')->find();
            if ($member) {
                $project['owner_name'] = $member['name'];
                $project['owner_avatar'] = $member['avatar'];
            }
        }
        $this->success('', $project);
    }

    /**
     * 保存
     *
     * @param Request $request
     * @return void
     * @throws \Exception
     */
    public function edit(Request $request)
    {
        $data = $request::only('name,description,cover,private,prefix,open_prefix,schedule');
        $code = $request::param('projectCode');
        try {
            $result = $this->model->edit($code, $data);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), $e->getCode());;

        }
        if ($result) {
            $this->success();
        }
        $this->error("操作失败，请稍候再试！");
    }

    /**
     * 相关的项目动态
     */
    public function getLogBySelfProject()
    {
        $projectCode = Request::param('projectCode', '');
        $member = getCurrentMember();
        if (!$member) {
            $this->success('', []);
        }
        $prefix = config('database.prefix');
        if (!$projectCode) {
            $where = [];
            $where[] = ['member_code', '=', $member['code']];
            $projectCodes = ProjectMember::where($where)->column('project_code');
            if (!$projectCodes) {
                $this->success('', []);
            }
            foreach ($projectCodes as &$projectCode) {
                $projectCode = "'{$projectCode}'";
            }
            $projectCodes = implode(',', $projectCodes);
            $sql = "select tl.remark as remark,tl.content as content,tl.is_comment as is_comment,tl.create_time as create_time,p.name as project_name,t.name as task_name,t.code as source_code,p.code as project_code,m.avatar as member_avatar,m.name as member_name from {$prefix}project_log as tl join {$prefix}task as t on tl.source_code = t.code join {$prefix}project as p on t.project_code = p.code join {$prefix}member as m on tl.member_code = m.code where tl.action_type = 'task' and p.code in ({$projectCodes}) and p.deleted = 0 order by tl.id desc limit 0,20";
//        $sql = "select tl.remark as remark,tl.content as content,tl.is_comment as is_comment,tl.create_time as create_time,p.name as project_name,p.code as project_code,m.avatar as member_avatar,m.name as member_name from {$prefix}project_log as tl join {$prefix}project as p on tl.project_code = p.code join {$prefix}member as m on tl.member_code = m.code where p.code in ({$projectCodes}) and p.deleted = 0 order by tl.id desc limit 0,20";
            $list = Db::query($sql);
        } else {
            $page = Request::param('page');
            $pageSize = Request::param('pageSize');
            if ($page < 1) {
                $page = 1;
            }
            $offset = $pageSize * ($page - 1);
            $sql = "select tl.type as type,tl.action_type as action_type,tl.source_code as source_code,tl.remark as remark,tl.content as content,tl.is_comment as is_comment,tl.create_time as create_time,p.name as project_name,p.code as project_code,m.avatar as member_avatar,m.name as member_name from {$prefix}project_log as tl join {$prefix}project as p on tl.project_code = p.code join {$prefix}member as m on tl.member_code = m.code where p.code = '{$projectCode}' and p.deleted = 0 order by tl.id desc";
            $list = Db::query($sql);
            $total = count($list);
            $sql .= " limit {$offset},{$pageSize}";
            $list = Db::query($sql);
            if ($list) {
                foreach ($list as &$item) {
                    $item['sourceInfo'] = [];
                    switch ($item['action_type']) {
                        case 'task':
                            $item['sourceInfo'] = \app\common\Model\Task::where(['code' => $item['source_code']])->find();
                            break;
                        case 'project':
                            $item['sourceInfo'] = \app\common\Model\Project::where(['code' => $item['source_code']])->find();
                            break;
                    }
                }
            }
            $list = ['total' => $total, 'list' => $list];
        }
        $this->success('', $list);
    }

    /**
     * 上传封面
     */
    public function uploadCover()
    {
        try {
            $file = $this->model->uploadCover(Request::file('cover'));
        } catch (\Exception $e) {
            $this->error($e->getMessage(), $e->getCode());;
        }
        $this->success('', $file);
    }

    /**
     * 放入回收站
     */
    public function recycle()
    {
        try {
            $this->model->recycle(Request::post('projectCode'));
        } catch (\Exception $e) {
            $this->error($e->getMessage(), $e->getCode());;
        }
        $this->success('');
    }

    /**
     * 恢复
     */
    public function recovery()
    {
        try {
            $this->model->recovery(Request::post('projectCode'));
        } catch (\Exception $e) {
            $this->error($e->getMessage(), $e->getCode());;
        }
        $this->success('');
    }


    /**
     * 归档
     */
    public function archive()
    {
        try {
            $this->model->archive(Request::post('projectCode'));
        } catch (\Exception $e) {
            $this->error($e->getMessage(), $e->getCode());;
        }
        $this->success('');
    }

    /**
     * 恢复归档
     */
    public function recoveryArchive()
    {
        try {
            $this->model->recoveryArchive(Request::post('projectCode'));
        } catch (\Exception $e) {
            $this->error($e->getMessage(), $e->getCode());;
        }
        $this->success('');
    }

    /**
     * 退出项目
     */
    public function quit()
    {
        try {
            $this->model->quit(Request::post('projectCode'));
        } catch (\Exception $e) {
            $this->error($e->getMessage(), $e->getCode());;
        }
        $this->success('');
    }


}
