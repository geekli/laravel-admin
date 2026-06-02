<?php

namespace Encore\Admin;

use Closure;
use Encore\Admin\Auth\Database\Menu;
use Encore\Admin\Controllers\AuthController;
use Encore\Admin\Layout\Content;
use Encore\Admin\Traits\HasAssets;
use Encore\Admin\Widgets\Navbar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Class Admin.
 */
class Admin
{
    use HasAssets;

    /**
     * The Laravel admin version.
     *
     * @var string
     */
    const VERSION = '1.8.17';

    /**
     * @var Navbar
     */
    protected $navbar;

    /**
     * @var array
     */
    protected $menu = [];

    /**
     * @var string
     */
    public static $metaTitle;

    /**
     * @var string
     */
    public static $favicon;

    /**
     * @var array
     */
    public static $extensions = [];

    /**
     * @var []Closure
     */
    protected static $bootingCallbacks = [];

    /**
     * @var []Closure
     */
    protected static $bootedCallbacks = [];

    /**
     * Returns the long version of Laravel-admin.
     *
     * @return string The long application version
     */
    public static function getLongVersion()
    {
        return sprintf('Laravel-admin <comment>version</comment> <info>%s</info>', self::VERSION);
    }

    /**
     * @param $model
     * @param Closure $callable
     *
     * @return \Encore\Admin\Grid
     *
     * @deprecated since v1.6.1
     */
    public function grid($model, Closure $callable)
    {
        return new Grid($this->getModel($model), $callable);
    }

    /**
     * @param $model
     * @param Closure $callable
     *
     * @return \Encore\Admin\Form
     *
     *  @deprecated since v1.6.1
     */
    public function form($model, Closure $callable)
    {
        return new Form($this->getModel($model), $callable);
    }

    /**
     * Build a tree.
     *
     * @param $model
     * @param Closure|null $callable
     *
     * @return \Encore\Admin\Tree
     */
    public function tree($model, Closure $callable = null)
    {
        return new Tree($this->getModel($model), $callable);
    }

    /**
     * Build show page.
     *
     * @param $model
     * @param mixed $callable
     *
     * @return Show
     *
     * @deprecated since v1.6.1
     */
    public function show($model, $callable = null)
    {
        return new Show($this->getModel($model), $callable);
    }

    /**
     * @param Closure $callable
     *
     * @return \Encore\Admin\Layout\Content
     *
     * @deprecated since v1.6.1
     */
    public function content(Closure $callable = null)
    {
        return new Content($callable);
    }

    /**
     * @param $model
     *
     * @return mixed
     */
    public function getModel($model)
    {
        if ($model instanceof Model) {
            return $model;
        }

        if (is_string($model) && class_exists($model)) {
            return $this->getModel(new $model());
        }

        throw new InvalidArgumentException("$model is not a valid model");
    }

    /**
     * Left sider-bar menu.
     *
     * @return array
     */
    public function menu()
    {
        if (!empty($this->menu)) {
            return $this->menu;
        }

        $menuClass = config('admin.database.menu_model');

        /** @var Menu $menuModel */
        $menuModel = new $menuClass();
        //return $this->menu = $tree = $menuModel->toTree();
        $menus = $menuModel->with('roles')->get();
        $tree = $this->buildMenuTree($menus->toArray());

        //重写验证逻辑===重点
        // 同时支持角色 OR 权限（OR 逻辑）
        $user = $this->user();
        if (!$user) {
            return $this->menu = [];
        }

        $tree = $this->menu = array_values(array_filter($tree, function ($item) use ($user) {
            // 递归处理子菜单
            if (!empty($item['children'])) {
                $item['children'] = array_values(array_filter($item['children'], function ($child) use ($user) {
                    return $this->passesRoleOrPermission($child, $user);
                }));
            }
            return $this->passesRoleOrPermission($item, $user);
        }));

        // 使用深度递归过滤
        $filteredTree = $this->filterMenuTree($tree, $user);

        return $this->menu = array_values($filteredTree);
    }

    /**
     * @param array $menu
     *
     * @return array
     */
    public function menuLinks($menu = [])
    {
        if (empty($menu)) {
            $menu = $this->menu();
        }

        $links = [];

        foreach ($menu as $item) {
            if (!empty($item['children'])) {
                $links = array_merge($links, $this->menuLinks($item['children']));
            } else {
                $links[] = Arr::only($item, ['title', 'uri', 'icon']);
            }
        }

        return $links;
    }

    /**
     * Set admin title.
     *
     * @param string $title
     *
     * @return void
     */
    public static function setTitle($title)
    {
        self::$metaTitle = $title;
    }

    /**
     * Get admin title.
     *
     * @return string
     */
    public function title()
    {
        return self::$metaTitle ? self::$metaTitle : config('admin.title');
    }

    /**
     * @param null|string $favicon
     *
     * @return string|void
     */
    public static function favicon($favicon = null)
    {
        if (is_null($favicon)) {
            return static::$favicon;
        }

        static::$favicon = $favicon;
    }

    /**
     * Get the currently authenticated user.
     *
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function user()
    {
        return $this->guard()->user();
    }

    /**
     * Attempt to get the guard from the local cache.
     *
     * @return \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard
     */
    public function guard()
    {
        $guard = config('admin.auth.guard') ?: 'admin';

        return Auth::guard($guard);
    }

    /**
     * Set navbar.
     *
     * @param Closure|null $builder
     *
     * @return Navbar
     */
    public function navbar(Closure $builder = null)
    {
        if (is_null($builder)) {
            return $this->getNavbar();
        }

        call_user_func($builder, $this->getNavbar());
    }

    /**
     * Get navbar object.
     *
     * @return \Encore\Admin\Widgets\Navbar
     */
    public function getNavbar()
    {
        if (is_null($this->navbar)) {
            $this->navbar = new Navbar();
        }

        return $this->navbar;
    }

    /**
     * Register the laravel-admin builtin routes.
     *
     * @return void
     *
     * @deprecated Use Admin::routes() instead();
     */
    public function registerAuthRoutes()
    {
        $this->routes();
    }

    /**
     * Register the laravel-admin builtin routes.
     *
     * @return void
     */
    public function routes()
    {
        $attributes = [
            'prefix'     => config('admin.route.prefix'),
            'middleware' => config('admin.route.middleware'),
        ];

        app('router')->group($attributes, function ($router) {

            /* @var \Illuminate\Support\Facades\Route $router */
            $router->namespace('\Encore\Admin\Controllers')->group(function ($router) {

                /* @var \Illuminate\Routing\Router $router */
                $router->resource('auth/users', 'UserController')->names('admin.auth.users');
                $router->resource('auth/roles', 'RoleController')->names('admin.auth.roles');
                $router->resource('auth/permissions', 'PermissionController')->names('admin.auth.permissions');
                $router->resource('auth/menu', 'MenuController', ['except' => ['create']])->names('admin.auth.menu');
                $router->resource('auth/logs', 'LogController', ['only' => ['index', 'destroy']])->names('admin.auth.logs');

                $router->post('_handle_form_', 'HandleController@handleForm')->name('admin.handle-form');
                $router->post('_handle_action_', 'HandleController@handleAction')->name('admin.handle-action');
                $router->get('_handle_selectable_', 'HandleController@handleSelectable')->name('admin.handle-selectable');
                $router->get('_handle_renderable_', 'HandleController@handleRenderable')->name('admin.handle-renderable');
            });

            $authController = config('admin.auth.controller', AuthController::class);

            /* @var \Illuminate\Routing\Router $router */
            $router->get('auth/login', $authController.'@getLogin')->name('admin.login');
            $router->post('auth/login', $authController.'@postLogin');
            $router->get('auth/logout', $authController.'@getLogout')->name('admin.logout');
            $router->get('auth/setting', $authController.'@getSetting')->name('admin.setting');
            $router->put('auth/setting', $authController.'@putSetting');
        });
    }

    /**
     * Extend a extension.
     *
     * @param string $name
     * @param string $class
     *
     * @return void
     */
    public static function extend($name, $class)
    {
        static::$extensions[$name] = $class;
    }

    /**
     * @param callable $callback
     */
    public static function booting(callable $callback)
    {
        static::$bootingCallbacks[] = $callback;
    }

    /**
     * @param callable $callback
     */
    public static function booted(callable $callback)
    {
        static::$bootedCallbacks[] = $callback;
    }

    /**
     * Bootstrap the admin application.
     */
    public function bootstrap()
    {
        $this->fireBootingCallbacks();

        require config('admin.bootstrap', admin_path('bootstrap.php'));

        $this->addAdminAssets();

        $this->fireBootedCallbacks();
    }

    /**
     * Add JS & CSS assets to pages.
     */
    protected function addAdminAssets()
    {
        $assets = Form::collectFieldAssets();

        self::css($assets['css']);
        self::js($assets['js']);
    }

    /**
     * Call the booting callbacks for the admin application.
     */
    protected function fireBootingCallbacks()
    {
        foreach (static::$bootingCallbacks as $callable) {
            call_user_func($callable);
        }
    }

    /**
     * Call the booted callbacks for the admin application.
     */
    protected function fireBootedCallbacks()
    {
        foreach (static::$bootedCallbacks as $callable) {
            call_user_func($callable);
        }
    }

    /*
     * Disable Pjax for current Request
     *
     * @return void
     */
    public function disablePjax()
    {
        if (request()->pjax()) {
            request()->headers->set('X-PJAX', false);
        }
    }

    /**
     * Check if a menu item is visible by role OR permission.
     *
     * @param array $item
     * @param mixed $user
     * @return bool
     */
    protected function passesRoleOrPermission(array $item, $user): bool
    {
        //超级管理员不限制
        if ($user && $user->id == 1) {
            return true;
        }
        // administrator 角色且拥有 All permission（*）→ 显示全部菜单
        if ($user && $user->isRole('administrator') && $this->userHasAllPermission($user)) {
            return true;
        }
        //安全提取角色 ID（兼容 Eloquent 模型）
        $menuRoles = $item['roles'] ?? [];
        $menuRoleIds = collect($menuRoles)->pluck('id')->filter()->toArray();
        // 用户角色 ID
        $userRoleIds = $user->roles->pluck('id')->toArray();

        // 角色匹配
        $passesRole = !empty(array_intersect($userRoleIds, $menuRoleIds));

        // 权限检查
        $permissions = $item['permission'] ?? [];
        $passesPermission = false;
        if (!empty($permissions)) {
            if (is_string($permissions)) {
                $permissions = [$permissions];
            }
            $passesPermission = collect($permissions)->contains(function ($perm) use ($user) {
                return $user->can($perm);
            });
        }

        // 安全默认：无任何配置则隐藏
        $hasRoleConfig = !empty($menuRoleIds);
        $hasPermissionConfig = !empty($permissions);

        if (!$hasRoleConfig && !$hasPermissionConfig) {
            return false;
        }
        $result = $passesRole || $passesPermission;
        return $result;
    }

    /**
     * 是否实际拥有 All permission（slug=*），不依赖 isAdministrator 对 can() 的短路。
     *
     * @param mixed $user
     */
    protected function userHasAllPermission($user): bool
    {
        return $user->allPermissions()->pluck('slug')->contains('*');
    }

    /**
     * Build menu tree from flat array (with roles preserved).
     *
     * @param array $menus
     * @param int $parentId
     * @return array
     */
    protected function buildMenuTree(array $menus, int $parentId = 0): array
    {
        $branch = [];
        foreach ($menus as $menu) {
            if ((int)$menu['parent_id'] === $parentId) {
                $children = $this->buildMenuTree($menus, (int)$menu['id']);
                if (!empty($children)) {
                    $menu['children'] = $children;
                }
                $branch[] = $menu;
            }
        }
        //排序
        usort($branch, function ($a, $b) {
            $orderA = (int)($a['order'] ?? 0);
            $orderB = (int)($b['order'] ?? 0);
            return $orderA <=> $orderB;
        });
        return $branch;
    }

    /**
     * Recursively filter menu tree by user roles or permissions.
     *
     * @param array $items
     * @param mixed $user
     * @return array
     */
    protected function filterMenuTree(array $items, $user): array
    {
        $result = [];

        foreach ($items as $item) {
            // 递归处理子菜单
            if (!empty($item['children'])) {
                $item['children'] = $this->filterMenuTree($item['children'], $user);
            }

            // 判断当前项是否有直接访问权限
            $hasDirectAccess = $this->passesRoleOrPermission($item, $user);

            // 情况 1: 是叶子节点（无 children）
            if (empty($item['children'])) {
                if ($hasDirectAccess) {
                    $result[] = $item;
                }
                continue;
            }

            // 情况 2: 有子菜单
            // 如果父菜单自身可访问 → 保留（即使子菜单为空，也可能是一个可点击页面）
            // 或者：父菜单不可访问，但有可见子菜单 → 保留作为容器
            if ($hasDirectAccess || !empty($item['children'])) {
                $result[] = $item;
            }
            // 否则：父菜单不可访问 + 无子菜单 → 跳过（不会发生，因上面已处理 empty(children)）
        }
        //排序
        usort($result, function ($a, $b) {
            $orderA = (int)($a['order'] ?? 0);
            $orderB = (int)($b['order'] ?? 0);
            return $orderA <=> $orderB;
        });
        return $result;
    }
}
