<?php

namespace app\store\controller;



use app\common\controller\Store;
use fast\Random;
use think\Config;
use think\Db;
use think\Hook;
use think\Session;
use think\Validate;

/**
 * 后台首页
 * @internal
 */
class Index extends Store
{

    protected $noNeedLogin = ['login'];
    protected $noNeedRight = ['index', 'logout','password_save'];
    protected $layout = '';

    public function _initialize()
    {
        parent::_initialize();
        //移除HTML标签
        $this->request->filter('trim,strip_tags,htmlspecialchars');
    }

    /**
     * 后台首页
     */
    public function index()
    {
        $cookieArr = ['adminskin' => "/^skin\-([a-z\-]+)\$/i", 'multiplenav' => "/^(0|1)\$/", 'multipletab' => "/^(0|1)\$/", 'show_submenu' => "/^(0|1)\$/"];
        foreach ($cookieArr as $key => $regex) {
            $cookieValue = $this->request->cookie($key);
            if (!is_null($cookieValue) && preg_match($regex, $cookieValue)) {
                config('fastadmin.' . $key, $cookieValue);
            }
        }

        //左侧菜单
        list($menulist, $navlist, $fixedmenu, $referermenu) = $this->auth->getSidebar([
            'dashboard' => 'hot',
            'addon'     => ['new', 'red', 'badge'],
            'auth/rule' => __('Menu'),
        ], $this->view->site['fixedpage']);
        $action = $this->request->request('action');
        if ($this->request->isPost()) {
            if ($action == 'refreshmenu') {
                $this->success('', null, ['menulist' => $menulist, 'navlist' => $navlist]);
            }
        }

        $this->assignconfig('cookie', ['prefix' => config('cookie.prefix')]);
        $this->view->assign('menulist', $menulist);
        $this->view->assign('navlist', $navlist);
        $this->view->assign('fixedmenu', $fixedmenu);
        $this->view->assign('referermenu', $referermenu);
        $this->view->assign('title', __('Home'));

        return $this->view->fetch();
    }

    /**
     * 管理员登录
     */
    public function login()
    {
        $url = $this->request->get('url', '', 'url_clean');
        $url = $url ?: 'index/index';
        if ($this->auth->isLogin()) {
            $this->success(__("您已登录"), $url);
        }
        //保持会话有效时长，单位:小时
        $keeyloginhours = 24;
        if ($this->request->isPost()) {
            $username = $this->request->post('username');
            $password = $this->request->post('password', '', null);
            $keeplogin = $this->request->post('keeplogin',1);
//            $token = $this->request->post('__token__');
            $rule = [
                'username'  => 'require|length:2,30',
                'password'  => 'require|length:3,30',
//                '__token__' => 'require|token',
            ];
            $data = [
                'username'  => $username,
                'password'  => $password,
//                '__token__' => $token,
            ];
            if (Config::get('store_fastadmin.login_captcha')) {
                $rule['captcha'] = 'require|captcha';
                $data['captcha'] = $this->request->post('captcha');
            }
            $validate = new Validate($rule, [], ['username' => "账户名", 'password' => __('Password'), 'captcha' => __('Captcha')]);
            $result = $validate->check($data);
            if (!$result) {
                $this->error($validate->getError(), $url, ['token' => $this->request->token()]);
            }

            $result = $this->auth->login($username, $password, $keeplogin ? $keeyloginhours * 3600 : 0);
            if ($result === true) {
                Hook::listen("store_login_after", $this->request);
                $this->success(__('Login successful'), $url, ['url' => $url, 'id' => $this->auth->id, 'username' => $username, 'avatar' => $this->auth->avatar]);
            } else {
                $msg = $this->auth->getError();
                $msg = $msg ? $msg : __('用户名或密码不正确');
                $this->error($msg, $url, ['token' => $this->request->token()]);
            }
        }

        $background = Config::get('store_fastadmin.login_background');
        $background = $background ? (stripos($background, 'http') === 0 ? $background : config('site.cdnurl') . $background) : '';
        $this->view->assign('keeyloginhours', $keeyloginhours);
        $this->view->assign('background', $background);
        $this->view->assign('title', __('Login'));
        Hook::listen("store_login_init", $this->request);

        return $this->view->fetch();
    }

    /**
     * 退出登录
     */
    public function logout()
    {
        if ($this->request->isPost()) {
            $this->auth->logout();
            Hook::listen("store_logout_after", $this->request);
            $this->success(__('Logout successful'), 'index/login');
        }
        $html = "<form id='logout_submit' name='logout_submit' action='' method='post'>" . token() . "<input type='submit' value='ok' style='display:none;'></form>";
        $html .= "<script>document.forms['logout_submit'].submit();</script>";

        return $html;
    }

    public function password_save(){
        if ($this->request->isPost()){
            $password = input("password");
            $passwords = input("passwords");
            if (empty($passwords)){
                $this->error("请输入旧密码");
            }
            if (empty($password)){
                $this->error("请输入新密码");
            }

            $company = Db::name("store")->where("id",$this->auth->id)->find();
            if ($company['password'] != $this->auth->getEncryptPassword($passwords, $company['salt'])) {
                $this->error('密码不正确');
            }


            $params['salt'] = Random::alnum();
            $params['password'] = $this->auth->getEncryptPassword($password,$params['salt']);
            if (Db::name("store")->where("id",$this->auth->id)->update($params)){
                $this->success("修改成功");
            }
            $this->error("修改失败");
        }
        return $this->fetch();
    }

}
