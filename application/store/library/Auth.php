<?php

namespace app\store\library;


use fast\Random;
use fast\Tree;
use think\Config;
use think\Cookie;
use think\Db;
use think\Hook;
use think\Request;
use think\Session;

class Auth extends \fast\Auth
{
    protected $_error = '';
    protected $requestUri = '';
    protected $breadcrumb = [];
    protected $logined = false; //登录状态

    public function __construct()
    {
        parent::__construct();
    }

    public function __get($name)
    {
        return Session::get('store.' . $name);
    }

    /**
     * 管理员登录
     *
     * @param string $username 用户名
     * @param string $password 密码
     * @param int    $keeptime 有效时长
     * @return  boolean
     */
    public function login($username, $password, $keeptime = 0)
    {
        $store_data = Db::name("store")->where(['username' => $username])->find();
        if (!$store_data) {
            $this->setError('用户不存在');
            return false;
        }
        if (!$store_data['status']) {
            $this->setError('账号未启用');
            return false;
        }
        if (Config::get('store_fastadmin.login_failure_retry') && $store_data['loginfailure'] >= 10 && time() - $store_data['update_time'] < 86400) {
            $this->setError('请在1天后重试');
            return false;
        }


        if ($store_data['password'] != $this->getEncryptPassword($password, $store_data['salt'])) {
            Db::name("store")->where(['id'=>$store_data['id']])->setInc('loginfailure');
            $this->setError('密码不正确');
            return false;
        }
        $store_data['loginfailure'] = 0;
        $store_data['login_time'] = time();
        $store_data['loginip'] = request()->ip();
        $store_data['token'] = Random::uuid();
        Db::name("store")->where(['id'=>$store_data['id']])->update($store_data);
        Session::set("store", $store_data);
        Session::set("store.safecode", $this->getEncryptSafecode($store_data));
        $this->keeplogin($store_data, $keeptime);
        return true;
    }

    /**
     * 退出登录
     */
    public function logout()
    {

        Db::name("store")->where(['id'=>$this->id])->update(['token'=>'']);
        $this->logined = false; //重置登录状态
        Session::delete("store");
        Cookie::delete("store_keeplogin");
        setcookie('faststore_userinfo', '', $_SERVER['REQUEST_TIME'] - 3600, rtrim(url("/" . request()->module(), '', false), '/'));
        return true;
    }

    /**
     * 自动登录
     * @return boolean
     */
    public function autologin()
    {
        // 添加 token 验证
        $token = Request::instance()->get('token');
        if ($token) {
            $store = Db::name("store")->where(['token'=>$token])->find();
            if ($store && $store['token']) {
                // 清除已使用的 token，保证一次性有效
                Db::name("store")->where(['id'=>$store['id']])->update(['token'=>'']);

                // 清除可能存在的旧会话
                Session::delete("store");

                Session::set("store", $store);
                Session::set("store.safecode", $this->getEncryptSafecode($store));
                $this->logined = true; // 标记为已登录
                return true;
            }
            return false; // 有token但无效，返回false
        }

        // 原有的 cookie 自动登录逻辑
        $keeplogin = Cookie::get('store_keeplogin');
        if (!$keeplogin) {
            return false;
        }
        list($id, $keeptime, $expiretime, $key) = explode('|', $keeplogin);
        if ($id && $keeptime && $expiretime && $key && $expiretime > time()) {
            $store = Db::name("store")->where(['id'=>$id])->find();
            if (!$store || !$store['token']) {
                return false;
            }
            //token有变更
            if ($key != $this->getKeeploginKey($store, $keeptime, $expiretime)) {
                return false;
            }
            $ip = request()->ip();
            //IP有变动
            if ($store['loginip'] != $ip) {
                return false;
            }
            Session::set("store", $store);
            Session::set("store.safecode", $this->getEncryptSafecode($store));
            $this->logined = true; // 标记为已登录
            //刷新自动登录的时效
            $this->keeplogin($store, $keeptime);
            return true;
        } else {
            return false;
        }
    }

    /**
     * 刷新保持登录的Cookie
     *
     * @param int $keeptime
     * @return  boolean
     */
    protected function keeplogin($store, $keeptime = 0)
    {
        if ($keeptime) {
            $expiretime = time() + $keeptime;
            $key = $this->getKeeploginKey($store, $keeptime, $expiretime);
            Cookie::set('store_keeplogin', implode('|', [$store['id'], $keeptime, $expiretime, $key]), $keeptime);
            return true;
        }
        return false;
    }

    /**
     * 获取密码加密后的字符串
     * @param string $password 密码
     * @param string $salt     密码盐
     * @return string
     */
    public function getEncryptPassword($password, $salt = '')
    {
        return md5(md5($password) . $salt);
    }

    /**
     * 获取密码加密后的自动登录码
     * @param string $password 密码
     * @param string $salt     密码盐
     * @return string
     */
    public function getEncryptKeeplogin($params, $keeptime)
    {
        $expiretime = time() + $keeptime;
        $key = md5(md5($params['id']) . md5($keeptime) . md5($expiretime) . $params['token'] . config('token.key'));
        return implode('|', [$this->id, $keeptime, $expiretime, $key]);
    }

    /**
     * 获取自动登录Key
     * @param $params
     * @param $keeptime
     * @param $expiretime
     * @return string
     */
    public function getKeeploginKey($params, $keeptime, $expiretime)
    {
        $key = md5(md5($params['id']) . md5($keeptime) . md5($expiretime) . $params['token'] . config('token.key'));
        return $key;
    }

    /**
     * 获取加密后的安全码
     * @param $params
     * @return string
     */
    public function getEncryptSafecode($params)
    {
        return md5(md5($params['username']) . md5(substr($params['password'], 0, 6)) . config('token.key'));
    }

    public function check_rule($name, $relation = 'or', $mode = 'url')
    {
        if (!$this->config['auth_on']) {
            return true;
        }
        $rules = Db::name("store_group")->where("id",$this->group_id)->value("rules");
        // 获取用户需要验证的所有有效规则列表
        $rulelist = Db::name("store_rule")->where(['id'=>['in',$rules]])->column("name");

        if (is_string($name)) {
            $name = strtolower($name);
            if (strpos($name, ',') !== false) {
                $name = explode(',', $name);
            } else {
                $name = [$name];
            }
        }
        $list = []; //保存验证通过的规则名
        if ('url' == $mode) {
            $REQUEST = unserialize(strtolower(serialize($this->request->param())));
        }
        foreach ($rulelist as $rule) {
            $query = preg_replace('/^.+\?/U', '', $rule);
            if ('url' == $mode && $query != $rule) {
                parse_str($query, $param); //解析规则中的param
                $intersect = array_intersect_assoc($REQUEST, $param);
                $rule = preg_replace('/\?.*$/U', '', $rule);
                if (in_array($rule, $name) && $intersect == $param) {
                    //如果节点相符且url参数满足
                    $list[] = $rule;
                }
            } else {
                if (in_array($rule, $name)) {
                    $list[] = $rule;
                }
            }
        }
        if ('or' == $relation && !empty($list)) {
            return true;
        }
        $diff = array_diff($name, $list);
        if ('and' == $relation && empty($diff)) {
            return true;
        }

        return false;
    }

    /**
     * 检测当前控制器和方法是否匹配传递的数组
     *
     * @param array $arr 需要验证权限的数组
     * @return bool
     */
    public function match($arr = [])
    {
        $request = Request::instance();

        $arr = is_array($arr) ? $arr : explode(',', $arr);
        if (!$arr) {
            return false;
        }


        $arr = array_map('strtolower', $arr);
        // 是否存在
        if (in_array(strtolower($request->action()), $arr) || in_array('*', $arr)) {
            return true;
        }

        // 没找到匹配
        return false;
    }

    /**
     * 检测是否登录
     *
     * @return boolean
     */
    public function isLogin()
    {
        // 检查是否有token参数，如果有则不使用当前会话状态
        $token = Request::instance()->get('token');
        if ($token) {
            return false;
        }

        if ($this->logined) {
            return true;
        }
        $store = Session::get('store');
        if (!$store) {
            return false;
        }
        $my = Db::name("store")->where(['id'=>$store['id']])->find();
        if (!$my) {
            Session::delete("store");
            return false;
        }
        //校验安全码，可用于判断关键信息发生了变更需要重新登录
        if (!isset($store['safecode']) || $this->getEncryptSafecode($my) !== $store['safecode']) {
            $this->logout();
            return false;
        }
        $this->logined = true;
        return true;
    }

    /**
     * 获取当前请求的URI
     * @return string
     */
    public function getRequestUri()
    {
        return $this->requestUri;
    }

    /**
     * 设置当前请求的URI
     * @param string $uri
     */
    public function setRequestUri($uri)
    {
        $this->requestUri = $uri;
    }




    public function getUserInfo($uid = null)
    {
        $uid = is_null($uid) ? $this->id : $uid;

        return $uid != $this->id ? Db::name("store")->where('id',intval($uid))->find() : Session::get('store');
    }

    public function getRuleIds($uid = null)
    {
        $uid = is_null($uid) ? $this->id : $uid;
        return parent::getRuleIds($uid);
    }

    public function isSuperAdmin()
    {
        return in_array('*', $this->getRuleIds()) ? true : false;
    }

    /**
     * 获取管理员所属于的分组ID
     * @param int $uid
     * @return array
     */
    public function getGroupIds($uid = null)
    {
        $groups = $this->getGroups($uid);
        $groupIds = [];
        foreach ($groups as $K => $v) {
            $groupIds[] = (int)$v['group_id'];
        }
        return $groupIds;
    }

    /**
     * 取出当前管理员所拥有权限的分组
     * @param boolean $withself 是否包含当前所在的分组
     * @return array
     */
    public function getChildrenGroupIds()
    {
        $store = Session::get('store');
        $groupList = Db::name("store_group")->where(['id'=>$store['group_id'],'status' => 'normal'])->find();
        return explode(",",$groupList['rules']);
    }



    /**
     * 获得面包屑导航
     * @param string $path
     * @return array
     */
    public function getBreadCrumb($path = '')
    {
        if ($this->breadcrumb || !$path) {
            return $this->breadcrumb;
        }
        $titleArr = [];
        $menuArr = [];
        $urlArr = explode('/', $path);
        foreach ($urlArr as $index => $item) {
            $pathArr[implode('/', array_slice($urlArr, 0, $index + 1))] = $index;
        }
        if (!$this->rules && $this->id) {
            $this->getRuleList();
        }
        foreach ($this->rules as $rule) {
            if (isset($pathArr[$rule['name']])) {
                $rule['title'] = __($rule['title']);
                $rule['url'] = url($rule['name']);
                $titleArr[$pathArr[$rule['name']]] = $rule['title'];
                $menuArr[$pathArr[$rule['name']]] = $rule;
            }

        }
        ksort($menuArr);
        $this->breadcrumb = $menuArr;
        return $this->breadcrumb;
    }

    /**
     * 获取左侧和顶部菜单栏
     *
     * @param array  $params    URL对应的badge数据
     * @param string $fixedPage 默认页
     * @return array
     */
    public function getSidebar($params = [], $fixedPage = 'dashboard')
    {
        // 边栏开始
        Hook::listen("store_sidebar_begin", $params);
        $colorArr = ['red', 'green', 'yellow', 'blue', 'teal', 'orange', 'purple'];
        $colorNums = count($colorArr);
        $badgeList = [];
        $module = request()->module();
        // 生成菜单的badge
        foreach ($params as $k => $v) {
            $url = $k;
            if (is_array($v)) {
                $nums = isset($v[0]) ? $v[0] : 0;
                $color = isset($v[1]) ? $v[1] : $colorArr[(is_numeric($nums) ? $nums : strlen($nums)) % $colorNums];
                $class = isset($v[2]) ? $v[2] : 'label';
            } else {
                $nums = $v;
                $color = $colorArr[(is_numeric($nums) ? $nums : strlen($nums)) % $colorNums];
                $class = 'label';
            }
            //必须nums大于0才显示
            if ($nums) {
                $badgeList[$url] = '<small class="' . $class . ' pull-right bg-' . $color . '">' . $nums . '</small>';
            }
        }

        // 读取管理员当前拥有的权限节点
        $userRule = [];
        $rules = Db::name("store_group")->where("id",$this->group_id)->value('rules');
        $userRule = Db::name("store_rule")->where(['id'=>['in',$rules]])->column("name");

        $selected = $referer = [];
        $refererUrl = Session::get('store_referer');

        // 必须将结果集转换为数组
        $ruleList = Db::name("store_rule")->where('status', 'normal')
            ->where('ismenu', 1)
            ->order('weigh', 'desc')
            ->select();
        $indexRuleList = Db::name("store_rule")->where('status', 'normal')
            ->where('ismenu', 0)
            ->where('name', 'like', '%/index')
            ->column('name,pid');
        $pidArr = array_unique(array_filter(array_column($ruleList, 'pid')));

        foreach ($ruleList as $k => &$v) {
            if (!in_array($v['name'], $userRule)) {
                unset($ruleList[$k]);
                continue;
            }
            $indexRuleName = $v['name'] . '/index';
            if (isset($indexRuleList[$indexRuleName]) && !in_array($indexRuleName, $userRule)) {
                unset($ruleList[$k]);
                continue;
            }

            $v['url'] = isset($v['url']) && $v['url'] ? $v['url'] : '/' . $module . '/' . $v['name'];

            $v['badge'] = isset($badgeList[$v['name']]) ? $badgeList[$v['name']] : '';
            $v['title'] = __($v['title']);
            $v['url'] = preg_match("/^((?:[a-z]+:)?\/\/|data:image\/)(.*)/i", $v['url']) ? $v['url'] : url($v['url'],'',false);
            $selected = $v['name'] == $fixedPage ? $v : $selected;

            $referer = $v['url'] == $refererUrl ? $v : $referer;
        }

        $lastArr = array_unique(array_filter(array_column($ruleList, 'pid')));
        $pidDiffArr = array_diff($pidArr, $lastArr);

        foreach ($ruleList as $index => $item) {
            if (in_array($item['id'], $pidDiffArr)) {
                unset($ruleList[$index]);
            }
        }
        if ($selected == $referer) {
            $referer = [];
        }

        $select_id = $referer ? $referer['id'] : ($selected ? $selected['id'] : 0);
        $menu = $nav = '';
        $showSubmenu = config('fastadmin.show_submenu');

        if (Config::get('store_fastadmin.multiplenav')) {

            $topList = [];
            foreach ($ruleList as $index => $item) {
                if (!$item['pid']) {
                    $topList[] = $item;
                }
            }
            $selectParentIds = [];
            $tree = Tree::instance();
            $tree->init($ruleList);
            if ($select_id) {
                $selectParentIds = $tree->getParentsIds($select_id, true);
            }
            foreach ($topList as $index => $item) {
                $childList = Tree::instance()->getTreeMenu(
                    $item['id'],
                    '<li class="@class" pid="@pid"><a @extend href="@url@addtabs" addtabs="@id" class="@menuclass" url="@url" py="@py" pinyin="@pinyin"><i class="@icon"></i> <span>@title</span> <span class="pull-right-container">@caret @badge</span></a> @childlist</li>',
                    $select_id,
                    '',
                    'ul',
                    'class="treeview-menu' . ($showSubmenu ? ' menu-open' : '') . '"'
                );
                $current = in_array($item['id'], $selectParentIds);
                $url = $childList ? 'javascript:;' : $item['url'];
//                $addtabs = $childList || !$url ? "" : (stripos($url, "?") !== false ? "&" : "?") . "ref=" . ($item['menutype'] ? $item['menutype'] : 'addtabs');
                $childList = str_replace(
                    '" pid="' . $item['id'] . '"',
                    ' ' . ($current ? '' : 'hidden') . '" pid="' . $item['id'] . '"',
                    $childList
                );
                $nav .= '<li class="' . ($current ? 'active' : '') . '">'  . '" url="' . $url . '" title="' . $item['title'] . '"><span>' . $item['title'] . '</span> <span class="pull-right-container"> </span></a> </li>';
                $menu .= $childList;
            }
        } else {
            // 构造菜单数据
            Tree::instance()->init($ruleList);
            $menu = Tree::instance()->getTreeMenu(
                0,
                '<li class="@class"><a @extend href="@url@addtabs" addtabs="@id" @menutabs class="@menuclass" url="@url" py="@py" pinyin="@pinyin"><i class="@icon"></i> <span>@title</span> <span class="pull-right-container">@caret @badge</span></a> @childlist</li>',
                $select_id,
                '',
                'ul',
                'class="treeview-menu' . ($showSubmenu ? ' menu-open' : '') . '"'
            );

            if ($selected) {
                $nav .= '<li role="presentation" id="tab_' . $selected['id'] . '" class="' . ($referer ? '' : 'active') . '"><a href="#con_' . $selected['id'] . '" node-id="' . $selected['id'] . '" aria-controls="' . $selected['id'] . '" role="tab" data-toggle="tab"> <span>' . $selected['title'] . '</span> </a></li>';
            }
            if ($referer) {
                $nav .= '<li role="presentation" id="tab_' . $referer['id'] . '" class="active"><a href="#con_' . $referer['id'] . '" node-id="' . $referer['id'] . '" aria-controls="' . $referer['id'] . '" role="tab" data-toggle="tab"> <span>' . $referer['title'] . '</span> </a> <i class="close-tab fa fa-remove"></i></li>';
            }

        }

        return [$menu, $nav, $selected, $referer];
    }

    /**
     * 设置错误信息
     *
     * @param string $error 错误信息
     * @return Auth
     */
    public function setError($error)
    {
        $this->_error = $error;
        return $this;
    }

    /**
     * 获取错误信息
     * @return string
     */
    public function getError()
    {
        return $this->_error ? __($this->_error) : '';
    }
}
