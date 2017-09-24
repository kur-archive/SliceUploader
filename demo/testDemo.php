<?php
/**
 * Created by PhpStorm.
 * User: kurisu
 * Date: 2017/08/23
 * Time: 9:19
 */
require 'server.php';

//TODO::下面需要补充IP，开放的端口号，建立保存文件的文件夹，以及数据库密码和用户，否则无法正常运行
$uploadServer = new server(40720,"127.0.0.1",0,'files/','localhost','username',"password",'file_upload',3306);
$uploadServer->server->start();