<?php

namespace app\admin\controller\more;

use app\common\controller\Backend;
use app\common\library\Upload;
use think\Db;
use think\Validate;
use txy\TextRecognition;

/**
 * 核销回单管理
 *
 * @icon fa fa-file-text-o
 */
class WriteOffReceipt extends Backend
{
    
    /**
     * @var object
     * @phpstan-var null
     */
    protected $model = null;

    /**
     * 无需鉴权的方法,但需要登录
     * @var array
     */
    protected $noNeedRight = ['index'];

    public function _initialize()
    {
        parent::_initialize();
    }
    
    /**
     * 查看核销回单列表
     */
    public function index()
    {
        //设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            //如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            
            $list = Db::name('receipt_use_log')
                    ->where($where)
                    ->order($sort, $order)
                    ->paginate($limit);
            
            $result = array("total" => $list->total(), "rows" => $list->items());
            
            return json($result);
        }
        return $this->view->fetch();
    }
    
    /**
     * 添加核销回单
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post("row/a");
            if (empty($params)) {
                $this->error(__('Parameter %s can not be empty', ''));
            }
            
            $validate = new Validate([
                'receipt_no' => 'require|unique:receipt_use_log',
            ]);
            
            $validate->message([
                'receipt_no.require' => '回单号不能为空',
                'receipt_no.unique' => '回单号已存在',
            ]);
            
            if (!$validate->check($params)) {
                $this->error($validate->getError());
            }
            
            // 开启事务
            Db::startTrans();
            try {
                // 插入数据
                $data = [
                    'receipt_no' => $params['receipt_no'],
                    'admin_id' => $this->auth->id,
                    'admin_name' => $this->auth->username,
                    'create_time' => time(),
                ];
                
                $result = Db::name('receipt_use_log')->insert($data);
                if (!$result) {
                    throw new \Exception('保存失败');
                }
                
                Db::commit();
                $this->success('保存成功');
            } catch (\Exception $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }
        }
        return $this->view->fetch();
    }
    
    /**
     * 上传回单图片并识别回单号
     */
    public function upload()
    {
        if ($this->request->isPost()) {
            $file = $this->request->file('image');
            
            // 检查是否有文件被上传
            if (empty($file)) {
                if ($this->request->isAjax()) {
                    $this->error("请上传回单图片");
                } else {
                    $this->error("请上传回单图片");
                }
            }
            
            try {
                // 使用系统标准的上传类处理文件上传
                $upload = new Upload($file, '/receipt/{year}{mon}{day}/{filemd5}{.suffix}');
                $attachment = $upload->upload();
                
                // 获取上传后的文件路径
                $imagePath = $attachment->url;
            } catch (\Exception $e) {
                if ($this->request->isAjax()) {
                    $this->error("图片上传失败：" . $e->getMessage());
                } else {
                    $this->error("图片上传失败：" . $e->getMessage());
                }
            }
            
            // 获取配置信息
            $config_data = Db::name("qc_config")->where("id", 2)->find();
            if (!$config_data) {
                if ($this->request->isAjax()) {
                    $this->error('未找到配置信息');
                } else {
                    $this->error('未找到配置信息');
                }
            }

                // 调用腾讯云OCR识别回单信息
                // 注意：这里需要使用完整的URL地址，而不是相对路径
                $fullImageUrl = request()->domain() . $imagePath;
                $data = TextRecognition::get_image_info($config_data['secret'], $config_data['api_key'], $fullImageUrl);
                
                $order_number = '';
                // 从识别结果中提取回单号
                if (isset($data['BankSlipInfos']) && is_array($data['BankSlipInfos'])) {
                    foreach ($data['BankSlipInfos'] as $item) {
                        // 尝试查找回单号字段
                        if (isset($item['Name']) && isset($item['Value'])) {
                            // 常见的回单号标识
                            $receipt_identifiers = [
                                "日志号",
                                "交易序号",
                                "凭证号",
                                "交易流水号",
                                "电子回单号",
                                "网银交易流水号",
                                "指令序号",
                                "汇款编号",
                                "回单号",
                                "受理单号",
                                "回单流水号",
                                "柜员交易号",
                                "转账流水号",
                                "流水号",
                                "凭证号",
                                "回单验证码"
                            ];
                            if (in_array($item['Name'], $receipt_identifiers) || stripos($item['Name'], '回单') !== false || stripos($item['Name'], '流水') !== false) {
                                $order_number = $item['Value'];
                                break;
                            }
                        }
                    }
                }
                
                if (empty($order_number)) {
                    if ($this->request->isAjax()) {
                        $this->error('无法从图片中识别回单号');
                    } else {
                        $this->error('无法从图片中识别回单号');
                    }
                }
                
                // 检查回单号是否已存在
                if (Db::name('receipt_use_log')->where('receipt_no', $order_number)->find()) {
                    if ($this->request->isAjax()) {
                        $this->error('回单号已存在');
                    } else {
                        $this->error('回单号已存在');
                    }
                }
                
                // 保存回单信息
                $insertData = [
                    'receipt_no' => $order_number,
                    'image_path' => $imagePath,
                    'admin_id' => $this->auth->id,
                    'admin_name' => $this->auth->username,
                    'create_time' => time(),
                ];
                
                $result = Db::name('receipt_use_log')->insert($insertData);
                if (!$result) {
                    if ($this->request->isAjax()) {
                        $this->error('保存失败');
                    } else {
                        $this->error('保存失败');
                    }
                }
                
                $this->success('识别并保存成功');
        }
        
        return $this->view->fetch();
    }
    
    /**
     * 删除核销回单
     */
    public function del($ids = "")
    {
        if (!$this->request->isPost()) {
            $this->error(__("Invalid parameters"));
        }
        
        $ids = $ids ? $ids : $this->request->post("ids");
        if ($ids) {
            $where = ['id' => ['in', $ids]];
            $count = Db::name('receipt_use_log')->where($where)->delete();
            if ($count) {
                $this->success();
            }
        }
        $this->error(__('Parameter %s can not be empty', 'ids'));
    }
}