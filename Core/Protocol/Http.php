<?php
/**
 * 从TCP数据流中解析HTTP协议
 * Created by lixuan-it@360.cn
 * User: lane
 * Date: 16/3/26
 * Time: 下午10:27
 * E-mail: lixuan868686@163.com
 * WebSite: http://www.lanecn.com
 */
namespace FastWS\Core\Protocol;

use FastWS\Core\Connect\ConnectInterface;
use FastWS\Core\Connect\Tcp;
use FastWS\Core\Log;

class Http implements ProtocolInterface{

    public static function input($data, ConnectInterface $connect)
    {
        $position = strpos($data, "\r\n\r\n");
        //如果数据是两个\r\n开头,则不处理
        if ($position === 0) {
            return 0;
            //如果数据没有找到两个\r\n,则数据未完
        } else if ($position === false) {
            //如果长度大于所能接收的Tcp所限制的最大数据量,则不处理,并且断开该链接
            if (strlen($data) >= Tcp::$maxPackageSize) {
                $connect->close();
                return 0;
            }
            return 0;
        }
        //将数据按照\r\n\r\n分割为两部分.第一部分是http头,第二部分是http body
        list($header, ) = explode("\r\n\r\n", $data, 2);
        //POST请求
        if (strpos($data, "POST") === 0) {
            $match = array();
            if (preg_match("/\r\nContent-Length: ?(\d+)/", $header, $match)) {
                //返回数据长度+头长度+4(\r\n\r\n)
                return $match + strlen($header) + 4;
            } else {
                return 0;
            }
        //非POST请求
        }else{
            //返回头长度+4(\r\n\r\n)
            return strlen($header) + 4;
        }
    }

    /**
     * 将数据封装为HTTP协议数据
     * @param $data
     * @param ConnectInterface $connect
     * @return string
     */
    public static function encode($data, ConnectInterface $connect)
    {
        //状态码
        $header = HttpCache::$header['Http-Code'] ? HttpCache::$header['Http-Code'] : 'HTTP/1.1 200 OK';
        $header .= "\r\n";
        unset(HttpCache::$header['Http-Code']);
        //Content-Type
        $header = HttpCache::$header['Content-Type'] ? HttpCache::$header['Content-Type'] : 'Content-Type: text/html; charset=utf-8';
        $header .= "\r\n";
        //其他部分
        foreach(HttpCache::$header as $httpName => $value){
            if($httpName === 'Set-Cookie' && is_array($value)){
                foreach($value as $v){
                    $header .= $v . "\r\n";
                }
            }else{
                $header .= $value . "\r\n";
            }
        }
        //完善HTTP头的固定信息
        $header .= 'Server: FastWS' . FASTWS_VERSION . "\r\nContent-Length: " . strlen($data) . "\r\n\r\n";
        //保存SESSION
        self::_saveSession();
        //返回一个完整的数据包(头 + 数据)
        return $header . $data;
    }

    /**
     * 将数据包根据HTTP协议解码
     * @param $data
     * @param ConnectInterface $connect
     * @return array
     */
    public static function decode($data, ConnectInterface $connect)
    {
        //将超全局变量设为空.初始化HttpCache
        $_POST = $_GET = $_COOKIE = $_REQUEST = $_SESSION = $_FILES = $GLOBALS['HTTP_RAW_POST_DATA'] = array();
        HttpCache::$header = array('Connection' => 'Connection: keep-alive');
        HttpCache::$instance = new HttpCache();
        $_SERVER = array (
            'QUERY_STRING' => '',
            'REQUEST_METHOD' => '',
            'REQUEST_URI' => '',
            'SERVER_PROTOCOL' => '',
            'SERVER_SOFTWARE' => 'FastWS/' . FASTWS_VERSION,
            'SERVER_NAME' => '',
            'HTTP_HOST' => '',
            'HTTP_USER_AGENT' => '',
            'HTTP_ACCEPT' => '',
            'HTTP_ACCEPT_LANGUAGE' => '',
            'HTTP_ACCEPT_ENCODING' => '',
            'HTTP_COOKIE' => '',
            'HTTP_CONNECTION' => '',
            'REMOTE_ADDR' => '',
            'REMOTE_PORT' => '0',
        );
        //解析HTTP头
        list($header, $body) = explode("\r\n\e\n", $data, 2);
        $header = explode("\r\n", $header);
        list($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['SERVER_PROTOCOL']) = explode(' ', $header[0]);
        unset($header[0]);
        foreach($header as $h){
            if(empty($h)){
                continue;
            }
            list($name, $value) = explode(':', $h, 2);
            $value = trim($value);
            switch(strtolower($name)){
                //host
                case 'host':
                    $_SERVER['HTTP_HOST'] = $value;
                    $value = explode(':', $value);
                    $_SERVER['SERVER_NAME'] = $value[0];
                    if(isset($value[1]))
                    {
                        $_SERVER['SERVER_PORT'] = $value[1];
                    }
                    break;
                // cookie
                case 'cookie':
                    $_SERVER['HTTP_COOKIE'] = $value;
                    parse_str(str_replace('; ', '&', $_SERVER['HTTP_COOKIE']), $_COOKIE);
                    break;
                // user-agent
                case 'user-agent':
                    $_SERVER['HTTP_USER_AGENT'] = $value;
                    break;
                // accept
                case 'accept':
                    $_SERVER['HTTP_ACCEPT'] = $value;
                    break;
                // accept-language
                case 'accept-language':
                    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $value;
                    break;
                // accept-encoding
                case 'accept-encoding':
                    $_SERVER['HTTP_ACCEPT_ENCODING'] = $value;
                    break;
                // connection
                case 'connection':
                    $_SERVER['HTTP_CONNECTION'] = $value;
                    break;
                case 'referer':
                    $_SERVER['HTTP_REFERER'] = $value;
                    break;
                case 'if-modified-since':
                    $_SERVER['HTTP_IF_MODIFIED_SINCE'] = $value;
                    break;
                case 'if-none-match':
                    $_SERVER['HTTP_IF_NONE_MATCH'] = $value;
                    break;
                case 'content-type':
                    if(!preg_match('/boundary="?(\S+)"?/', $value, $match))
                    {
                        $_SERVER['CONTENT_TYPE'] = $value;
                    }
                    else
                    {
                        $_SERVER['CONTENT_TYPE'] = 'multipart/form-data';
                        $httpPostBoundary = '--'.$match[1];
                    }
                    break;
            }
        }

        //POST请求
        if($_SERVER['REQUEST_METHOD'] === 'POST'){
            if(isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] === 'multipart/form-data'){
                self::parseUploadFiles($body, $httpPostBoundary);
            }else{
                parse_str($body, $_POST);
                $GLOBALS['HTTP_RAW_POST_DATA'] = $body;
            }
        }
        //QUERY_STRING
        $_SERVER['QUERY_STRING'] = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        $_SERVER['QUERY_STRING'] = $_SERVER['QUERY_STRING'] ? parse_str($_SERVER['QUERY_STRING'], $_GET) : '';
        //REQUEST
        $_REQUEST = array_merge($_GET, $_POST);
        //REMOTE_ADDR
        $_SERVER['REMOTE_ADDR'] = $connect->getIp();
        //REMOTE_PORT
        $_SERVER['REMOTE_PORT'] = $connect->getPort();
        return array('get'=>$_GET, 'post'=>$_POST, 'cookie'=>$_COOKIE, 'server'=>$_SERVER, 'files'=>$_FILES);
    }

    /**
     * 保存SESSION
     */
    private static function _saveSession()
    {
        //不是命令行模式则写入SESSION并关闭文件
        if(PHP_SAPI !== 'cli'){
            session_write_close();
            return '';
        }
        //如果SESSION已经开启,并且$_SESSION有值
        if(HttpCache::$instance->sessionStarted && $_SESSION){
            $session = session_encode();
            if($session && HttpCache::$instance->sessionFile){
                return file_put_contents(HttpCache::$instance->sessionFile, $session);
            }
        }
        return empty($_SESSION);
    }

    /**
     * 删除header()设置的HTTP头信息
     * @param string $name 删除指定的头信息
     */
    public static function removeHttpHeader($name)
    {
        if(PHP_SAPI != 'cli'){
            header_remove();
        }
        unset(HttpCache::$header[$name]);
    }

    /**
     * 设置Cookie
     * @param string $name
     * @param string $value
     * @param integer $maxage
     * @param string $path
     * @param string $domain
     * @param bool $secure
     * @param bool $HTTPOnly
     */
    public static function setcookie($name, $value = '', $maxage = 0, $path = '', $domain = '', $secure = false, $HTTPOnly = false) {
        if(PHP_SAPI != 'cli'){
            return setcookie($name, $value, $maxage, $path, $domain, $secure, $HTTPOnly);
        }
        return self::header(
            'Set-Cookie: ' . $name . '=' . rawurlencode($value)
            . (empty($domain) ? '' : '; Domain=' . $domain)
            . (empty($maxage) ? '' : '; Max-Age=' . $maxage)
            . (empty($path) ? '' : '; Path=' . $path)
            . (!$secure ? '' : '; Secure')
            . (!$HTTPOnly ? '' : '; HttpOnly'), false);
    }

    /**
     * 开启SESSION
     * @return bool
     */
    public static function sessionStart()
    {
        if(PHP_SAPI != 'cli'){
            return session_start();
        }
        if(HttpCache::$instance->sessionStarted){
            Log::write('Session already started');
            return true;
        }
        HttpCache::$instance->sessionStarted = true;
        //生成SID
        if(!isset($_COOKIE[HttpCache::$sessionName]) || !is_file(HttpCache::$sessionPath . '/ses' . $_COOKIE[HttpCache::$sessionName]))
        {
            $file_name = tempnam(HttpCache::$sessionPath, 'ses');
            if(!$file_name)
            {
                return false;
            }
            HttpCache::$instance->sessionFile = $file_name;
            $session_id = substr(basename($file_name), strlen('ses'));
            return self::setcookie(
                HttpCache::$sessionName
                , $session_id
                , ini_get('session.cookie_lifetime')
                , ini_get('session.cookie_path')
                , ini_get('session.cookie_domain')
                , ini_get('session.cookie_secure')
                , ini_get('session.cookie_httponly')
            );
        }
        if(!HttpCache::$instance->sessionFile){
            HttpCache::$instance->sessionFile = HttpCache::$sessionPath . '/ses' . $_COOKIE[HttpCache::$sessionName];
        }
        //读取SESSION文件,填充到$_SESSION中
        if(HttpCache::$instance->sessionFile){
            $raw = file_get_contents(HttpCache::$instance->sessionFile);
            if($raw){
                session_decode($raw);
            }
        }
        return true;
    }

    /**
     * End, like call exit in php-fpm.
     * @param string $msg
     * @throws \Exception
     */
    public static function end($msg = '')
    {
        if(PHP_SAPI !== 'cli'){
            exit($msg);
        }
        if($msg){
            echo $msg;
        }
        throw new \Exception('jump_exit');
    }

    /**
     * 获取MIME TYPE
     * @return string
     */
    public static function getMimeTypesFile()
    {
        return __DIR__.'/mime.types';
    }

    /**
     * 解析$_FILES
     * @param $httpBody string HTTP主体数据
     * @param $httpPostBoundary string HTTP POST 请求的边界
     * @return void
     */
    protected static function parseUploadFiles($httpBody, $httpPostBoundary)
    {
        $httpBody = substr($httpBody, 0, strlen($httpBody) - (strlen($httpPostBoundary) + 4));
        $boundaryDataList = explode($httpPostBoundary . "\r\n", $httpBody);
        if($boundaryDataList[0] === ''){
            unset($boundaryDataList[0]);
        }
        foreach($boundaryDataList as $boundaryData){
            //分割为描述信息和数据
            list($boundaryHeaderBuffer, $boundaryValue) = explode("\r\n\r\n", $boundaryData, 2);
            //移除数据结尾的\r\n
            $boundaryValue = substr($boundaryValue, 0, -2);
            foreach (explode("\r\n", $boundaryHeaderBuffer) as $item){
                list($headerName, $headerValue) = explode(": ", $item);
                $headerName = strtolower($headerName);
                switch ($headerName){
                    case "content-disposition":
                        //是上传文件
                        if(preg_match('/name=".*?"; filename="(.*?)"$/', $headerValue, $match))
                        {
                            //将文件数据写入$_FILES
                            $_FILES[] = array(
                                'file_name' => $match[1],
                                'file_data' => $boundaryValue,
                                'file_size' => strlen($boundaryValue),
                            );
                            continue;
                        //POST数据
                        }else{
                            //将POST数据写入$_POST
                            if(preg_match('/name="(.*?)"$/', $headerValue, $match))
                            {
                                $_POST[$match[1]] = $boundaryValue;
                            }
                        }
                        break;
                }
            }
        }
    }
}