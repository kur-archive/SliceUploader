//base6
debug = true;
md5 = '';

/*
    upload_sendFileInfo(file,callback)
    参数：
        file :  要上传的文件
        callback ： 回调函数
        uploadOSS : True or False

    回调函数 例示
    function callback(msg){
           //msg代表现在的状态，包括是否在计算MD5，以及服务器查询的文件状态
    }

 */
function upload_sendFileInfo(file, callback,isOSS) {
    //TODO::填写目标服务器
    var url = 'ws://';
    socket = new WebSocket(url);
    socket.onopen = function () {
        log('link success');
        // send('Let\'s Start，link start!');
        document.getElementById('result1').innerHTML = 'result1: link success';
        var filename = file.name,
            totalSize = file.size;
    };
    callback('md5 calculating');
    calculate(file, MD5_callback);

    function MD5_callback(md5) {
        send(JSON.stringify({
            'filename': filename,
            'totalSize': totalSize,
            'md5': md5,
            'operate': 'startQuery',
            'uploadOss': uploadOSS
        }));
        window.md5 = md5;
    }

    socket.onmessage = function (msg) {
        var msg_json = JSON.parse(msg.data);
        if (msg_json.result === 'firstUpload') {
            callback('firstUpload');
            log('firstUpload');
        } else if (msg_json.result === 'continueUpload') {
            callback('continueUpload');
            log('继续上传');
        } else if (msg_json.result === 'finish') {
            //秒传
            callback('finish');
            log('秒传成功');
        } else if (msg_json.result === 'fileBreak') {
            //秒传
            callback('fileBreak');
            log('文件损坏，重新上传');
        }
        document.getElementById('result2').innerHTML = 'result2:' + msg_json.result;
    };
    socket.onclose = function () {
        callback('disconnect');
        log('断开连接');
        socket = null
    };


}
/*
    upload_sendData(file,callback0,callback1)
    参数：
         callback0返回状态
            上传中，上传完毕，上传失败，暂停
         callback1返回进度
 */

function upload_sendData(file,callback0,callback1,uploadOSS) {
    //首先发出一个UploadReady包，包括文件的信息,服务器要拿去和之前在数据库的信息相比对
    //之后服务器会返回，告诉本次传输的第一个包的序号是多少，
    send(JSON.stringify({
        'filename': file.name,
        'totalSize': file.size,
        'md5': md5,
        'operate': 'startInfo'
    }));
    callback0("uploading");
    //TODO 每次要传哪个包，服务器会返回一个nextChunk告诉客户端，换句话说，每次客户端要发哪一段都是由服务器来决定
    socket.onmessage = function (msg) {

        log(msg.data);
        msg_data_json = JSON.parse(msg.data);
        if (msg_data_json.result === 'dataInfo ok') {
            //这里就根据服务器传回来的数据发送相应的包过去就ok了
            dataInfo_ok(file,msg_data_json);
        } else if (msg_data_json.result === 'data ok' || msg_data_json.result === 'startInfo ok') {
            //显示进度并触发发送dataInfo的函数
            //根据服务器发回来的数据，发送下一个包
            //服务器要根据这里的数据，检查现在文件的大小是否等于blobFrom(-1?)，以及加上eachSize以后是否等于BlobTo之后是否等于blobTo，
            percent=data_ok(file,msg_data_json);
            callback1(percent);
        } else if (msg_data_json.result === 'stopUpload') {
            callback0('stopUpload');
            alert('暂停成功');
        } else if (msg_data_json.result === 'upload success') {
            //检查（check）完毕后，返回检查结果
            callback0('upload success')
        } else if (msg_data_json.result === 'upload fail') {
            //检查（check）完毕后，返回检查结果和错误原因
            //TODO 不过这里也有接受全部错误的想法，全部的错误信息都走这里
            //TODO 所有的上传错误都走这里
            log(msg_data_json);
            callback0('error cause : ' + msg_data_json.error);
        }
    }

}

function dataInfo_ok(file,msg_data_json) {
    var chunk_T = parseInt(msg_data_json.nextChunk, 10);
    var eachSize_T = parseInt(msg_data_json.eachSize, 10);
    log('chuck_T : ' + chunk_T);
    var blobFrom_T = chunk_T * eachSize_T;
    var blobTo_T = (chunk_T + 1) * eachSize_T > totalSize ? totalSize : (chunk_T + 1) * eachSize_T;
    send(file.slice(blobFrom_T, blobTo_T));
}

function data_ok(file,msg_data_json) {
    log(msg_data_json);
    var chunk = parseInt(msg_data_json.nextChunk, 10);
    var chunks = parseInt(msg_data_json.chunks, 10);
    var eachSize = parseInt(msg_data_json.eachSize, 10);
    var remarks = msg_data_json.remarks;


    showProgress(chunk - 1, chunks - 1);

    var blobFrom = chunk * eachSize;
    var blobTo = (chunk + 1) * eachSize > file.size ? file.size : (chunk + 1) * eachSize;
    var isLastChunk = (chunk === (chunks - 1) ? 1 : 0);
    log('chunk : ' + chunk + ' chunks : ' + chunks + ' isLastChunk : ' + isLastChunk);
    send(JSON.stringify({
        'filename': file.name,
        'totalSize': file.size,
        'blobFrom': blobFrom,
        'blobTo': blobTo,
        'chunks': chunks,
        'isLastChunk': isLastChunk,
        'operate': 'dataInfo_transmission'
    }));
    return (chunk * 100 / chunks).toFixed(1);
    //这里发过去以后，服务器检查是不是需要的包，如果是的话就可以准备接受后面的文件块本体
    
}




function stopUpload() {
    alert('stop');
    send(JSON.stringify({
        'operate': 'stopUpload'
    }));
}

function continueUpload(file) {
    //TODO 这里重新发送一遍StartInfo包，一让服务器检测对照文件信息，二重新触发服务器开始向客户端要求发送包
    log('continue');
    send(JSON.stringify({
        'filename': file.name,
        'totalSize': file.size,
        'md5': md5,
        'operate': 'startInfo'
    }));
    socket.onmessage = function (msg) {
        msgx(msg, file);
    }
}

function showProgress() {
    //TODO 展示进度,返回一个值

}


function calculate(file, callBack) {
    var fileReader = new FileReader(),
        blobSlice = File.prototype.mozSlice || File.prototype.webkitSlice || File.prototype.slice,
        chunkSize = 2097152,
        chunks = Math.ceil(file.size / chunkSize),
        currentChunk = 0,
        spark = new SparkMD5.ArrayBuffer();
    fileReader.onload = function (e) {
        spark.append(e.target.result); // append binary string
        currentChunk++;

        if (currentChunk < chunks) {
            loadNext();
        }
        else {
            callBack(spark.end());
            // window.MD5 = spark.end();
        }
    };

    function loadNext() {
        var start = currentChunk * chunkSize,
            end = ((start + chunkSize) >= file.size) ? file.size : start + chunkSize;

        fileReader.readAsArrayBuffer(blobSlice.call(file, start, end));
    }

    loadNext();
}


function send(msg) {
    // socket.send(file);
    socket.send(msg);
}

function log(log) {
    if (debug === true) {
        console.log(log);
    }

}

