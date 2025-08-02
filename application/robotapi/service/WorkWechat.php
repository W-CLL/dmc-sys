<?php

namespace app\robotapi\service;

use qywx\Api;
use think\Controller;

class WorkWechat extends Controller
{

    public function sandMessage($data)
    {
        $title = $data['title'];
        Api::send_application_messages('MaYuTian|WuZhongTuan|PanHaoWei|WangChunLong|WuZhongJie', $title);
    }

    public function validateParam($data, $type = 0){
        switch ($type) {
            case 1: // get
                return [false, '不允许使用'];
            case 2: // post
                if (!is_array($data)) {
                    return [false, '数据与预期不一致'];
                }
                $validate = [
                    ["title", "require", "title 必填"],
                ];
                $result = $this->validate($data, $validate);
                if ($result !== true) {
                    return [false, 40003, $result];
                }
                return true;
            case 3: // put
                return [false, '不允许使用'];
            case 4: // delete
                return [false, '不允许使用'];
            default:
                return [false, '不允许使用'];
        }
    }

}