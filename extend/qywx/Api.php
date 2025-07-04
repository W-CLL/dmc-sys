<?php

namespace qywx;

use Requests;
use think\Cache;
use think\Env;

class Api
{

    protected static $corp_id = "";
    protected static $corp_secret = '';
    protected static $agentid = '';


    private static function getConfig()
    {
        self::$corp_id = Env::get('work_wx.corp_id');
        self::$corp_secret = Env::get('work_wx.secret');
        self::$agentid = Env::get('work_wx.agent_id');

        return Env::get('work_wx.corp_id');
    }

    /**
     * 获取企业微信access_token
     */
    public static function get_access_token()
    {
        $res = self::getConfig();
        if (!$res) {
            return '';
        }
        $access_token = Cache::get("qywx_access_token");
        if (!$access_token) {
            $url = "https://qyapi.weixin.qq.com/cgi-bin/gettoken?corpid=" . self::$corp_id . "&corpsecret=" . self::$corp_secret;
            $access_token = Requests::get($url)["access_token"];
            Cache::set("qywx_access_token", $access_token, 7200);
        }
        return $access_token;

    }

    /**
     * 获取部门成员列表，默认部门id为7
     */
    public static function get_department_member($num = 0, $department_id = 7)
    {
        $access_token = self::get_access_token();
        $url = "https://qyapi.weixin.qq.com/cgi-bin/user/simplelist?access_token=" . $access_token . "&department_id=" . $department_id;
        $result = Requests::get($url);
        if ($result["errmsg"] != "ok") {
            Cache::rm('qywx_access_token');
            if ($num == 1) {
                return [];
            }
            self::get_department_member(1);
        }
        return $result["userlist"];
    }

    public static function get_user_list()
    {
        $access_token = self::get_access_token();
        $url = "https://qyapi.weixin.qq.com/cgi-bin/user/list_id?access_token=" . $access_token;
        $data = [
            'limit' => 100
        ];
        return Requests::post($url, json_encode($data, true));
    }

    public static function send_application_messages($touser, $content)
    {
        $access_token = self::get_access_token();
        $url = "https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=" . $access_token;
        $data = [
            "touser" => $touser,
            "toparty" => "",
            "totag" => "",
            "msgtype" => "text",
            "agentid" => self::$agentid,
            "text" => [
                "content" => $content
            ],
            "safe" => 0,
            "enable_id_trans" => 0,
            "enable_duplicate_check" => 0,
        ];
        return Requests::post($url, json_encode($data, true));
    }

    public static function send_image_messages($touser, $media_id)
    {
        $access_token = self::get_access_token();
        $url = "https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=" . $access_token;
        $data = [
            "touser" => $touser,
            "toparty" => "",
            "totag" => "",
            "msgtype" => "image",
            "agentid" => self::$agentid,
            "image" => [
                "media_id" => $media_id
            ],
            "safe" => 0,
            "enable_duplicate_check" => 0,
            "duplicate_check_interval" => 1800,
        ];
        Requests::post($url, json_encode($data, true));
    }

    /**
     * @param $file
     * @param string $type 图片（image）、语音（voice）、视频（video），普通文件（file）
     */
    public static function media_upload($file, $type = "image")
    {
        $access_token = self::get_access_token();
        $url = "https://qyapi.weixin.qq.com/cgi-bin/media/upload?access_token=" . $access_token . "&type=" . $type;
        $data = [
            "media" => new \CURLFILE($file),
        ];

        $result = Requests::post($url, $data);
        if ($result["errmsg"] == "ok") {
            return $result["media_id"];
        }
        return "";
    }

    public static function create_group($group_name, $owner, array $user_list, $chat_id = '')
    {
        $access_token = self::get_access_token();
        $url = "https://qyapi.weixin.qq.com/cgi-bin/appchat/create?access_token=" . $access_token;
        $data = [
            "name" => $group_name,
            "owner" => $owner,
            "userlist" => $user_list,
            "chatid" => $chat_id
        ];
        return Requests::post($url, json_encode($data, true));
    }


    public static function get_department()
    {
        $token = self::get_access_token();
        $url = "https://qyapi.weixin.qq.com/cgi-bin/department/list?access_token=" . $token;
        return Requests::get($url);
    }


    public static function get_group_message($chat_id)
    {
        $access_token = self::get_access_token();
        $url = "https://qyapi.weixin.qq.com/cgi-bin/appchat/get?access_token=" . $access_token . "&chatid=" . $chat_id;
        return Requests::get($url);
    }

    public static function set_group_join_way(array $chat_list)
    {
        $access_token = self::get_access_token();
        $url = "https://qyapi.weixin.qq.com/cgi-bin/externalcontact/groupchat/add_join_way?access_token=" . $access_token;
        $data = [
            "scene" => 2,
            "chat_id_list" => $chat_list,
        ];
        return Requests::post($url, json_encode($data, true));
    }

}