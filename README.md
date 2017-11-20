# SliceUpload    
<small>浏览器通过websocket切割大文件上传</small>


[![OS](https://img.shields.io/badge/OS-Linux-red.svg)](#)
[![npm](https://img.shields.io/npm/l/express.svg)](#)

项目 实践思路 参见 [Doc](https://github.com/Kuri-su/SliceUploader/blob/master/_doc/Thinking.md)

## 简介
主要用于 `浏览器前端` 与 `服务器` 进行 `大文件分包传输`的 `可行思路` , 主要用于 学习与交流


\# 目录结构
```
-root
 | -lib                     #lib
 |  | -php 
 |  |  | -server.php        #基于swoole的服务端类库
 |  | -js
 |  |  | -base.js           #前端主要逻辑
 |  |  | -spark-md5.js      #计算md5的库
 |  |  | -jquery-3.2.1.js   #Jquery库
 | -demo
 |  | -css
 |  |  | base.css           #示例demo的css
 |  | -client.html          #示例demo的html
 |  | -testDemo.php         #演示脚本
```

<hr/>

## 例示案例部署步骤：

1. 首先确认php有swoole拓展，使用 `php -m`指令确认， 若没有安装swoole请参考 [此文章](https://segmentfault.com/a/1190000008285814) 或者其他相似的文章 进行安装

2. 修改 `/lib/js/base.js` 文件
> 这里没有把配置取出来放到客户端demo里设置。。
```javascript
    // line 20 
    // 这里需要告诉js，与之通信的服务器的IP和port
    var url = 'ws://';
    // eg:
    var url = 'ws://101.101.101.101:49604';
```

3. 修改 `/demo/testDemo.php` 文件
```php
    //line 11
    //实例化server类的时候，需要给server类传递参数
    $uploadServer = new server(40720,"127.0.0.1",0,'files/','localhost','username',"password",'file_upload',3306);
    
    eg:
    $uploadServer = new server(40720,"111.10.10.1111",49604,'files/','localhost','username',"password",'file_upload',3306);
    
    以上参数分别是，分包大小，服务器IP，swoole监听端口，文件存放目录，数据库地址，数据库用户名，数据库密码，使用的库名(databaseName)，数据库端口
```

4. 然后在命令行运行指令, `php /demo/testDemo.php`，启动服务端脚本

因为swoole必须运行在linux环境下，推荐在服务器上部署 `服务端脚本` 或者在本地使用 [Vagrant](https://www.vagrantup.com/ "vagrant") 开发环境

5. 然后在浏览器打开客户端 `client.html ` 文件，按照提示进行连接。

## WARNING
`swoole` 必须运行在 `linux` 环境下

## 服务器和客户端交互步骤
参见 [实现思路](https://github.com/Kuri-su/SliceUploader/blob/master/_doc/Thinking.md)

## 更新计划
暂无

## LICENSE
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>MIT</b>

#
