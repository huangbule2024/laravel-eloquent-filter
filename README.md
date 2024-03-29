<h1 align="center"> laravel-eloquent-filter </h1>

<p align="center"> An easy way to filter Eloquent Models.</p>

## 依赖
1. PHP7.4+

## 安装

```shell
$ composer require huangbule/laravel-eloquent-filter
```
发布配置文件：
```shell
php artisan vendor:publish --tag=filter 
```

## 设计初衷
为了简化搜索写一大推`重复代码`,看下平时大家会写的简化代码，安全方面暂时先不考虑
```
$input = \request()->input(); //模拟接受用户数据
OrdersModel::query()->when(!empty($input['title']), function ($q) use($input) {
    //订单标题，模糊搜索，我想你们不止订单要给表有`title`字段吧，每个model你们都写一遍
    $q->where('title', 'like', '%' . $input['title'] . '%');
})->when(!empty($input['status']), function ($q) use($input) {
    //模拟in类型，订单状态，如果搜索多个状态你们可能会这么写，可能传数组或者逗号隔开
    if (!is_array($input['status'])) {
        $input['status'] = explode(",", $input['status']);
    }
    $q->whereIn('status', $input['status']);
})->when(!empty($input['created_at']), function ($q) use($input) {
    //搜索订单创建时间，可能传数组或者逗号隔开
    if (is_array($input['created_at'])) {
        $start_at = $input['created_at'][0];
        $end_at = date('Y-m-d', strtotime('+1 day', strtotime($input['created_at'][1])));
    } else {
        $start_at = $input['created_at'];
        $end_at = date('Y-m-d', strtotime('+1 day', strtotime($input['created_at'])));
    }
    return $q->where('created_at', '>=', $start_at)->where('created_at', '<', $end_at);
})->get();
```
这边只简单列举了一个orderModel, 你们会发现项目中这样代码重复遍地可见，所以需要优化加速开发

或者 你可能用scope封装起来，比如：
```
public function scopeWxUserId($query, $param)
{
    if (! empty($param['wx_user_id'])) {
        return $query->where('wx_user_id', $param['wx_user_id']);
    }
}

public function scopeUserId($query, $param)
{
    if (! empty($param['user_id'])) {
        return $query->where('user_id', $param['user_id']);
    }
}
```
你看上面都是重复代码，都属于where 中等于情况， 那我们为何不把他们封装起来呢，为什么要重复写呢？


## 设计思路
1.  参考laravel validate写法，简化语句
2.  引入 `操作符` 比如 like、>=、 between 等等， 而且大家可以自己拓展
3.  引入 `前置处理器` 再最终执行where语句之前，我们可能需要对$input用户传过来的数据做下处理，比如上面的 
    created_at [2024-03-14, 2024-03-20] 其实20号需要加一天，因为created_at 是datetime类型。  而且必须可拓展
4.  引入 `别名` 比如标题我们数据库用title,  但是由于特殊原因不能用这个字段，比如传 order_title
5.  确定搜索规则格式： `搜索字段:前置处理器（0-n个)|操作符(可能没有默认)`  为了区分`前置处理器` 跟 `操作符`
    对`操作符`做个特殊表示用`$`开头 比如 $like 、 $eq
    
6.  订单列表返回了用户信息，这时候我们想搜索用户标题咋办？ 必须支持 关联关系查询，设定关联关系用`#`开头 比如['title:#user'],
    然后必须在order表里面定义 user() 关联方法，默认就查询user表里面的title了，原理用 whereHas
7.  引入配置文件，因为大部分字段搜索的规则都差不多的，我总不可能每个里面都写吧，比如['title:$like'],
   我在每个控制器里User、Order面都写一遍，那岂不是太麻烦了
    
8. 如果你既想在配置直接定义title， 又想定义它所属关联关系。 可以在关联关系前面加#title，代码会在$input['#title']
   去掉# 变成 $input['title'] 获取值, demo 见 下面的#department_name

### 配置文件
```
<?php

return [
    'default' => '$like', //定义默认操作符, [title'] 等同于 ['title:$like']  
    'rule' => [ //定义通用字段类型
        'id' => '$eq',
        'title' => '$like', //可以不写 因为默认是like
        'order_title' => '@title', //@开头是别名，指定表里面字段是title
        'department_name' => '$like',
        '#department_name' => '#department|$like', //department是关联关系
        'created_at' => '#department|HalfOpenDate|$halfOpen' //不分顺序。  属于department表，同时进行预处理函数，最后执行左开右闭处理
    ]
];

```

## 用法
引入 `FilterTrait` trait to Model 
```
namespace App\Models;

use Huangbule\LaravelEloquentFilter\Traits\FilterTrait;
use Illuminate\Database\Eloquent\Model;

class OrderModel extends Model
{    
    use FilterTrait;
}
```

然后在你控制器里面 eloquent query 对象调用 filter 方法，第一个参数是用户传过来的数据，第二个参数是定义规则
 ```
    use App\Models\Order;
    class OrderController extends Controller
    {
        public function index(Request $request)
        {
            return User::filter($request->input(), ['title','created_at','搜索字段:预处理器|#操作符|@别名'])->get();
        }
    }
```
### 标识符枚举：

| 标识符        | Description                              |
|-----------------|------------------------------------------|
| `@`           | 字段别名   |
| `#`           | 关联关系标识|
| `$`           | 操作符标识|


### 操作符枚举：

| Operator        | Description                              |
|-----------------|------------------------------------------|
| `$eq`           | where('title', '=', 'hello')|
| `$ne`           | where('title', '!=', 'hello')|
| `$lt`           | where('id', '<', 10)   |
| `$lte`          | where('id', '<=', 10) |
| `$gt`           | where('id', '>', 10) |
| `$gte`          | where('id', '>=', 10) |
| `$in`           | whereIn('id', [1, 2, 3]) |
| `$notIn`        | whereNotIn('id', [1, 2, 3]) |
| `$between`      | whereBetween('id', [1, 10]) |
| `$halfOpen`| where('id','>=',1)->where('id','<',10) |                       |

### 前置处理器枚举

| preprocess        | Description                              |
|-----------------|------------------------------------------|
| `HalfOpenDate`           |把开始和结束时间中的结束时间自动加一天       


### 如何拓展自定义前置处理器

```
    namespace App\Providers;
    
    use App\Preprocess\UuidPreprocess;
    use Huangbule\LaravelEloquentFilter\Traits\FilterTrait;
    use Illuminate\Support\ServiceProvider;
    
    class AppServiceProvider extends ServiceProvider
    {
     
        public function boot(Request $request)
        {
           $this->app->singleton('uuid', UuidPreprocess::class);
        } 
    }

```
自定义预处理器必须实现 `Huangbule\LaravelEloquentFilter\Contracts\Ipreprocess` 接口

```
namespace App\Preprocess;

use  Huangbule\LaravelEloquentFilter\Contracts\Ipreprocess;

class UuidPreprocess implements Ipreprocess {

    public function handle($column, &$param) {
        //@todo 业务逻辑
        if (! empty($param[$column])) {
            $param[$column] = \Hashids::decode($param[$column]);
        }
    }
}

```

### 如何拓展操作符

```
    namespace App\Providers;
    
    use App\Preprocess\UuidPreprocess;
    use Huangbule\LaravelEloquentFilter\Traits\FilterTrait;
    use Illuminate\Support\ServiceProvider;
    
    class AppServiceProvider extends ServiceProvider
    {
     
        public function boot(Request $request)
        {
            $this->app->singleton('leftLike', LeftLikeFilter::class);
            
        } 
    }

```
自定义过滤器必须实现 `Huangbule\LaravelEloquentFilter\Contracts\Ifilter` 接口

```
namespace App\Preprocess;

use  Huangbule\LaravelEloquentFilter\Contracts\Ifilter;

class LeftLikeFilter implements Ifilter {

    public function handle($qr, $column, $param) {
        //@todo 业务逻辑
       
    }
}

```

## Contributing

You can contribute in one of three ways:

1. File bug reports using the [issue tracker](https://github.com/huangbule/laravel-eloquent-filter/issues).
2. Answer questions or fix bugs on the [issue tracker](https://github.com/huangbule/laravel-eloquent-filter/issues).
3. Contribute new features or update the wiki.

_The code contribution process is not very formal. You just need to make sure that you follow the PSR-0, PSR-1, and PSR-2 coding guidelines. Any new code contributions must be accompanied by unit tests where applicable._

## License

MIT
