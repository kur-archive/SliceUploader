<?php
/**
 * Created by PhpStorm.
 * User: kurisu
 * Date: 2017/08/22
 * Time: 18:23
 */

class server
{

    //TODO 把eachSize设置成常量
    public $server;
    private $eachSize, $fileAfterDirPath,  $conn;
    private $bigArr = [];
    private $debug = true;
    public $white_list = [];

    public function __construct($eachSize, $ip, $port, $fileAfterDirPath, $mysql_local, $mysql_user, $mysql_password, $mysql_database = 'file_upload', $mysql_port = 3306)
    {
        $this->eachSize = $eachSize;
        $this->fileAfterPath = $fileAfterDirPath;
        $this->server = new swoole_websocket_server($ip, $port);
        $this->conn = mysqli_connect($mysql_local, $mysql_user, $mysql_password, $mysql_database, $mysql_port) or die('connect database error');
        echo "websocket Server Start!　\n";

        //连接上的时候
        $this->server->on('open', function (swoole_websocket_server $server, $request) {
            //用户连上的时候会显示这一句
            echo "server: handshake success with fd{$request->fd}\n";
        });

        //接到消息的时候
        $this->server->on('message', function (swoole_websocket_server $server, $frame) {
            //先区分是json消息还是分片的文件数据
            //如果开头结尾是{""}或者[{""}]就认为是json信息
            if (
                substr($frame->data, 0, 2) == '{"' && substr($frame->data, strlen($frame->data) - 2, 2) == '"}' ||
                substr($frame->data, 0, 3) == '[{"' && substr($frame->data, strlen($frame->data) - 3, 3) == '"}]') {
                $jsonData = json_decode($frame->data);
                $operate = $jsonData->operate;//针对传过来的操作(operate)进行分别处理


                if ($operate == 'startQuery') {
                    //开始时候的查询，用于核对数据库是否有这个文件的记录
                    //但是感觉这一步的存在可能是个冗余
                    $this->debugLog('- startQuery stage start ');
                    $this->startQuery($jsonData, $frame->fd);
                    $this->debugLog('- startQuery stage end');
                } elseif ($operate == 'startInfo') {
                    //作为开始的引线
                    $this->debugLog('- startInfo stage ');
                    $this->startInfo($jsonData, $frame->fd);

                } elseif ($operate == 'dataInfo_transmission') {
                    //确认和设置包的信息
                    $this->debugLog('- dataInfo_transmission stage start');
                    $this->dataInfo_transmission($jsonData, $frame->fd);
                    $this->debugLog('- dataInfo_transmission stage end');

                } elseif ($operate == 'stopUpload') {
                    $this->debugLog('- getStopUpload message');
                    $this->bigArr[$frame->fd]['isStop'] = 1;

                }
            } else {
                $this->saveData($frame->data,$frame->fd);
            }
        });
        $this->server->on('close', function ($ser, $fd) {
            echo "client {$fd} closed\n";

            if ($this->bigArr[$fd]['isUpload']) {
                //isUpload =1 ，说明非正常结束
                $sql = "UPDATE `file_info` SET `nextChunk`='" . $this->bigArr[$fd]['nextChunk'] . "',remarks='lost conn', `updated_at`='" . date('Y-m-d H:i:s', time()) . "' WHERE `md5`='" . $this->bigArr[$fd]['md5'] . "' and `totalSize`='" . $this->bigArr[$fd]['totalSize'] . "'";
                mysqli_query($this->conn, $sql);
                echo 'lost conn';
            } else {
                echo 'isUpload == 0';
            }
            unset($this->bigArr[$fd]);
        });

    }//end by __construct

    public function startQuery($jsonData, $fd)
    {
        //查询数据库，看有没有这个文件的记录(用md5值和totalSize来查找)
        $sql = "SELECT `filename`,`md5`,`totalSize`,`nextChunk`,`chunks`,`isfinish` FROM `file_info` WHERE md5='" . $jsonData->md5 . "' and totalSize='" . $jsonData->totalSize . "'";
        $this->debugLog('--- start find file by md5 = ' . $jsonData->md5 . ' totalSize = ' . $jsonData->totalSize, true);
        $resus0 = mysqli_query($this->conn, $sql);
        $resu0 = mysqli_fetch_row($resus0);
        //如果找到数据了
        if (!empty($resu0)) {
            $this->debugLog('--- find fileInfo');
            $tmp = [
                'filename' => $resu0[0],
                'md5' => $resu0[1],
                'totalSize' => $resu0[2],
                'nextChunk' => $resu0[3],
                'chunks' => $resu0[4],
                'isfinish' => $resu0[5],
            ];

            //如果数据库的isfinish字段为1则说明上传完毕，则直接返回结果为finish，并直接关闭socket连接
            if ($tmp['isfinish'] == 1) {
                $this->server->push($fd, json_encode(['result' => 'finish']));
                $this->debugLog('----- The file was uploaded and finished');
                $this->server->close($fd, false);

                //如果数据库isfinish字段为0，则说明并没有上传完毕，需要续传
            } elseif ($tmp['isfinish'] == 0) {

                //续传之前先判断是否因为网络波动终止传输，使得文件出现出现问题，如果出现大小不相等的情况，说明文件出现损坏，需要删除重新传，如果大小相等，说明文件未损坏，可以继续上传
                if (filesize($tmp['filename']) == $this->eachSize * $tmp['nextChunk']) {
                    //文件未损坏，可以继续上传
                    $this->debugLog('----- Size is equal filename in fact : ' . filesize($tmp['filename']) . ' ' . eachSize * $tmp['nextChunk']);
                    //返回文件的接受状态，更新前端面板
                    $this->server->push($fd, json_encode([
                        'result' => 'continueUpload',
                        'nextCheck' => $tmp['nextChunk'],
                        'chunks' => $tmp['chunks'],
                    ]));
                    $this->debugLog('----- continueUpload');
                    $this->debugLog('----- nextCheck : ' . $tmp['nextChunk']);
                    $this->debugLog('----- chunks : ' . $tmp['chunks']);
                } else {
                    //文件可能损坏，需要重新上传
                    $this->debugLog('-----  Size is not equal filename in fact : ' . filesize($tmp['filename']) . ' ' . eachSize * $tmp['nextChunk']);
                    //删除文件
                    unlink($tmp['filename']);
                    //更新数据库，将nextChunk更改为0
                    $sql = "UPDATE `file_info` SET `nextChunk`=0 WHERE md5='" . $jsonData->md5 . "' and totalSize='" . $jsonData->totalSize . "'";
                    $resu1 = mysqli_query($this->conn, $sql);
                    if ($resu1) {
                        $this->server->push($fd, json_encode([
                            'result' => 'fileBreak',
                            'nextCheck' => 0,
                            'chunks' => $tmp['chunks'],
                        ]));
                        $this->debugLog('----- continueUpload');
                        $this->debugLog('----- nextCheck : 0');
                        $this->debugLog('----- chunks : ' . $tmp['chunks']);
                    } else {
                        $this->debugLog('*----- mysql error');

                    }
                }
            }

            //这里记录下如果文件名不同，但MD5和大小一致的案例
            //这里仅仅只是做个试验，观察是否会出现md5值碰撞的情况，理论上应该是没有
            if ($tmp['filename'] != $jsonData->filename)
                mysqli_query($this->conn, "INSERT INTO `aberrant`(`difference1`,`difference2`,`remark1`,`same`,`remark2`,`created_at`,`updated_at`) VALUES ('" . $tmp['filename'] . "','{$jsonData->filename}','filename','" . $tmp['md5'] . "','md5','" . date('Y-m-d H:i:s', time()) . "','" . date('Y-m-d H:i:s', time()) . "')");

        } elseif (empty($resu0)) {
            //如果没有在数据库查找到该文件的数据，说明该文件是第一次上传
            $this->debugLog('--- unfind fileInfo');
            //首次查询的时候给数据库新加一条数据
            $sql = "INSERT INTO `file_info`(`filename`,`md5`,`totalSize`,`eachSize`,`nextChunk`,`chunks`,`isfinish`,`created_at`,`updated_at`) VALUES ('" . $jsonData->filename . "','" . $jsonData->md5 . "','" . $jsonData->totalSize . "','" . $this->eachSize . "',0,'" . ceil($jsonData->totalSize / $this->eachSize) . "',0,'" . date('Y-m-d H:i:s', time()) . "','" . date('Y-m-d H:i:s', time()) . "')";
            $resu2 = mysqli_query($this->conn, $sql);
            if ($resu2) {
                $this->server->push($fd, json_encode(['result' => 'first upload']));
                $this->debugLog('----- first upload');
            } else {
                $this->debugLog('*----- mysql error by unfind fileInfo ');
            }
        }
    }//end function startQuery

    public function startInfo($jsonData, $fd)
    {
        //在数据库查找文件信息
        $sql = "SELECT `eachSize`,`nextChunk`,`chunks`,`remarks` FROM `file_info` WHERE md5='" . $jsonData->md5 . "' and totalSize='" . $jsonData->totalSize . "'";
        $resus2 = mysqli_query($this->conn, $sql);
        $resu2 = mysqli_fetch_row($resus2);
        //如果数据库有这个文件的数据
        //一般都会有的，如果没有则会返回一个result='upload error'，说明原因
        if (!empty($resu2)) {
            //如果确认无误，这里就可以进行之后的操作
            $this->bigArr[$fd]['filename'] = $jsonData->filename;
            $this->bigArr[$fd]['totalSize'] = $jsonData->totalSize;
            $this->bigArr[$fd]['eachSize'] = $resu2[0];
            $this->bigArr[$fd]['chunks'] = $resu2[2];
            $this->bigArr[$fd]['md5'] = $jsonData->md5;
            $this->bigArr[$fd]['nextChunk'] = $resu2[1];

            //TODO 这两个变量可以合并
            //把isStop设为0，即开始上传
            $this->bigArr[$fd]['isStop'] = 0;
            //把isUpload设为1，即开始上传
            $this->bigArr[$fd]['isUpload'] = 0;

            //告诉客户端下一个包是哪个
            $this->server->push($fd, json_encode([
                'result' => 'startInfo ok',
                'nextChunk' => $this->bigArr[$fd]['nextChunk'],//告诉客户端，要哪个包
                'eachSize' => $this->bigArr[$fd]['eachSize'],
                'chunks' => $this->bigArr[$fd]['chunks'],
                'remarks' => !empty($resu2[3]) ? $resu2[3] : '',
            ]));
        } else {
            //如果数据库没有这个文件的数据，则返回upload fail
            $this->server->push($fd, json_encode([
                'result' => 'upload fail',
                'error' => ' error with chack fileInfo'
            ]));
        }
    }//end function startInfo

    public function dataInfo_transmission($jsonData, $fd)
    {
        //如果没暂停，则告诉客户端，下一次要发哪个包，以及eachSize是多少
        if ($this->bigArr[$fd]['isStop'] != 1) {
            $this->bigArr[$fd]['blobFrom'] = $jsonData->blobFrom;
            $this->bigArr[$fd]['blobTo'] = $jsonData->blobTo;
            $this->bigArr[$fd]['isLastChunk'] = $jsonData->isLastChunk;


            $this->server->push($fd, json_encode([
                'result' => 'dataInfo ok',
                'nextChunk' => $this->bigArr[$fd]['nextChunk'],
                'eachSize' => $this->bigArr[$fd]['eachSize'],
                'chunks' => $this->bigArr[$fd]['chunks'],

            ]));
            //如果isStop等于1，说明客户端叫暂停
        } elseif ($this->bigArr[$fd]['isStop'] == 1) {

            //这个时候要储存目前进度到数据库
            $sql = "UPDATE `file_info` SET `nextChunk`='" . $this->bigArr[$fd]['nextChunk'] . "',`remarks`='user stop' ,`updated_at`='" . date('Y-m-d H:i:s', time()) . "' WHERE `md5`='" . $this->bigArr[$fd]['md5'] . "' and `totalSize`='" . $this->bigArr[$fd]['totalSize'] . "'";
            $res = mysqli_query($this->conn, $sql);
            if ($res) {
                //true
                $this->bigArr[$fd]['isUpload'] = 0;
                $this->server->push($fd, json_encode([
                    'result' => 'stopUpload',
                    'now_nextChunk' => $this->bigArr[$fd]['nextChunk'],
                ]));
                $this->server->close($fd, false);
            } else {
                //false
                $this->server->push($fd, json_encode([
                    'result' => 'upload fail',
                    'error' => ' error with stop'
                ]));
            }

        }
    }

    public function saveData($data,$fd)
    {

        $this->debugLog('--- get ' . $this->bigArr[$fd]['nextChunk'] . 'chunk');
        //如果开头结尾不是{""}或者[{""}]就认为是数据，就往指定文件的结尾添加数据
        file_put_contents('./' . $this->bigArr[$fd]['filename'], $data, FILE_APPEND);

        //如果不是最后一个包
        if ($this->bigArr[$fd]['isLastChunk'] != 1) {
            $this->bigArr[$fd]['nextChunk'] += 1;
            $this->server->push($fd, json_encode([
                'result' => 'data ok',
                'nextChunk' => $this->bigArr[$fd]['nextChunk'],
                'eachSize' => $this->bigArr[$fd]['eachSize'],
                'chunks' => $this->bigArr[$fd]['chunks'],
            ]));

            //如果是最后一个包
        } elseif ($this->bigArr[$fd]['isLastChunk'] == 1) {
            //TODO 计算MD5的话可能之后会移除，他会带来一个延迟，以及它可能会占用相当大的磁盘IO，看测试情况来决定吧
            //测试文件大小是否相等
            $mark1 = ($this->bigArr[$fd]['totalSize'] == filesize($this->bigArr[$fd]['filename']) ? 1 : 0);
            //测试文件的md5值是否相等
            $md5_a = md5_file($this->bigArr[$fd]['filename']);
            $mark2 = ($md5_a == $this->bigArr[$fd]['md5'] ? 1 : 0);
            echo "$md5_a \n{$this->bigArr[$fd]['md5']}\n";
            //如果两个测试都通过了，就把文件挪到指定的文件夹
            if ($mark1 + $mark2 == 2) {
                $fileAftername = md5(time() . $this->bigArr[$fd]['totalSize']);
                //如果挪动成功，则修改数据库，nextChunk修改成最后一个包，isfinish修改为1，表示已经完成
                if (rename($this->bigArr[$fd]['filename'], $this->fileAfterDirPath . $fileAftername)) {
                    $sql = "UPDATE `file_info` SET `file_after`='$fileAftername' , `nextChunk`='" . $this->bigArr[$fd]['nextChunk'] . "',`remarks`='',`isfinish`=1 , `updated_at`='" . date('Y-m-d H:i:s', time()) . "' WHERE `md5`='" . $this->bigArr[$fd]['md5'] . "' and `totalSize`='" . $this->bigArr[$fd]['totalSize'] . "'";
                    if (mysqli_query($this->conn, $sql)) {
                        $this->bigArr[$fd]['isUpload'] = 0;
                        $this->server->push($fd, json_encode([
                            'result' => 'upload success',
                        ]));
                        $this->server->close($fd, false);
                    } else {
                        $this->server->push($fd, json_encode([
                            'result' => 'upload fail',
                            'error' => 'error with sql updateError by upload success'
                        ]));
                        $this->server->close($fd, false);
                    }
                } else {
                    $this->server->push($fd, json_encode([
                        'result' => 'upload fail',
                        'error' => 'error with move file'
                    ]));
                    $this->server->close($fd, false);
                }
            } else {
                $this->server->push($fd, json_encode([
                    'result' => 'upload fail',
                    'error' => ($mark1 == 1 ? '' : '大小不相等') . ($mark2 == 1 ? '' : 'md5值不相等'),
                    'md5' => $md5_a,
                ]));
                $this->server->close($fd, false);
            }
        } else {
            $this->server->push($fd, json_encode([
                'result' => 'upload fail',
                'error' => ' error at save fileData  '
            ]));
        }

    }

    //输出调试信息
    public function debugLog($msg, $linefeed = true)
    {
        if ($this->debug) {
            if ($linefeed) {
                echo($msg . "\n");
            } else {
                echo($msg);
            }
        }
    }
}