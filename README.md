# scf-flight
Make FlightPHP Framework running on Tencent SCF environment  
在腾讯云云函数上运行FlightPHP框架，低成本迁移  
> scf能运行的，web一定能。web能运行的，可能需要一定修改，详见下面。

### 可用
```php
$_POST
$_GET
$_FILES //不能太大
$_SERVER
$_COOKIE
Flight::request()->data
Flight::request()->body
Flight::response()->header("X-By","scFlight");
Flight::response()->status(404);
Flight::setcookie($key,$value,$options);    //php 7.3 setcookie方式
//Flight框架中的一切函数
//等等
```
### 不可用
```php
header();           
setcookie();        //使用Flight::setcookie();代替
session_start();    //不支持，因为scf不保存session
```
### 安装
须同时安装`mikecao/flight`和`xytoki/scf-flight`。本项目暂未发布至composer，需从github安装。
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/xytoki/scf-flight"
        }
    ],
    "require": {
        "xytoki/scf-flight": "dev-master",
		    "mikecao/flight": "^1.3"
    }
}
```
之后将`Flight::start();`替换为以下内容即可。本项目会自动检测腾讯云scf环境并使框架正常运行。
```php
if(isset($_ENV['TENCENTCLOUD_RUNENV'])){
    function main_handler($event, $context){
        return Flight::start($event, $context, dirname(__FILE__));
    }
}else{
    Flight::start();
}
```
