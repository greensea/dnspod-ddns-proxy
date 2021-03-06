<?php
if (!file_exists('config.php')) {
    apiout(-10, 'config.php 文件不存在，建议 mv config.sample.php config.php 并修改配置');
}
else {
    require_once('config.php');
}

$domain = isset($_POST['domain']) ? $_POST['domain'] : '';                    /// 域名
$record_id = isset($_POST['record_id']) ? $_POST['record_id'] : '';           /// 记录编号
$sub_domain = isset($_POST['sub_domain']) ? $_POST['sub_domain'] : '';        /// 子域名名字，由于 DNSPod 设计的问题，必须传入子域名，否则子域名会被修改为 @
//$record_type = isset($_POST['record_type']) ? $_POST['record_type'] : 'A';    /// 记录类型，默认为 A 记录，暂未实现
//$record_line = isset($_POST['record_line']) ? $_POST['record_line'] : '默认';    /// 线路类型，默认为“默认”（线路），暂未实现
$value = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
$api_token = isset($_POST['api_token']) ? $_POST['api_token'] : '';

if (!$value) {
    apiout(-20, '没有获取到客户端 IP，不会进行任何操作');
}
if (!$domain || !$record_id || !$sub_domain) {
    apiout(-30, '没有提供 domain 或 record_id 或 sub_domain', [
        'domain' => $domain,
        'record_id' => $record_id,
        'sub_domain' => $sub_domain,
    ]);
}


/// 1. 进行认证
if (!isset($_POST['api_token']) && !isset($_POST['sign'])) {
    apiout(-35, '没有提供 api_token 参数，也没有提供 sign 参数');
}
/// 如果提供了 api_token，则直接使用，否则根据 sign 进行查询
$api_token = '';
if (isset($_POST['api_token'])) {
    $api_token = $_POST['api_token'];
}
else {
    $api_token = search_api_token();
}
if (!$api_token) {
    apiout(-38, '没有提供 api_token，或提供了 sign，但没有找到 sign 对应的 API TOKEN');
}


/// 2. 判断是否需要更新
LOGD("要更新的记录是 ({$domain}-{$record_id})，客户端的地址是 {$value}");
$c = get_config($domain, $record_id);
$latest_ip = '';
if (isset($c['ip'])) {
    $latest_ip = $c['ip'];
}
if ($latest_ip == $value) {
    LOGD("最后更新的 IP 和当前客户端 IP 都是 {$value}，不会进行更新");
    apiout(0, 'IP 没有改变，不用更新');
}


/// 更新记录
$url = 'https://dnsapi.cn/Record.Modify';
$params = [
    'domain' => $domain,
    'record_id' => $record_id,
    'sub_domain' => $sub_domain,
    'value' => $value,
    'record_type' => 'A',
    'record_line' => '默认',
    'format' => 'json',
    'login_token' => $api_token,
];
$err = '';
$ret = request($url, $params, $err);
if (!$ret) {
    apiout(-40, 'DNSPOD 接口访问失败: ' . $err);
}

$j = json_decode($ret, TRUE);
if (!$j) {
    apiout(-50, '无法将 DNSPOD 返回的数据解析为 JSON');
}

if (!isset($j['status'])) {
    apiout(-60, 'DNSPOD 返回的结果中没有 status 字段');
}
if (!isset($j['status']['code'])) {
    apiout(-70, 'DNSPOD 返回的结果中没有 status.code 字段');
}

if ($j['status']['code'] != 1) {
    apiout(-80, 'DNSPOD 返回失败: ' . (isset($j['status']['message']) ? $j['status']['message'] : '(DNSPOD 返回的结果中没有 status.message 字段)'));
}


/// 更新成功，修改本机状态缓存
$c = get_config($domain, $record_id);
if (!$c) {
    $c = [];
}
$c['ip'] = $value;
set_config($domain, $record_id, $c);
apiout(0, "域名记录修改成功({$domain}-{$record_id}) --> {$value}");




/**
 * 获取该域名记录的最后一次更新到的 IP 地址
 */
function get_config($domain, $record_id) {
    $path = "/tmp/ddns-latest-dns-{$domain}-{$record_id}";
    if (file_exists($path)) {
        return unserialize(file_get_contents($path));
    }
    else {
        return '';
    }
}

/**
 * 设置该域名记录最后一次更新到的 IP 地址
 */
function set_config($domain, $record_id, $data) {
    $path = "/tmp/ddns-latest-dns-{$domain}-{$record_id}";
    file_put_contents($path, serialize($data));
    
    return TRUE;
}


/**
 * 输出内容
 */
function apiout($code, $message, $data = NULL) {
    $output = [
        'code' => $code,
        'message' => $message,
        'data' => $data
    ];
    die(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}


/**
 *  发起一个 POST 网络请求
 */
function request($url, $params, &$err) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'ddns/1.0 (gs@bbxy.net)'
    ]);
    
    LOGD("发起 URL 请求: {$url}");
    
    $t1 = microtime(TRUE);
    $ret = curl_exec($ch);
    $t2 = microtime(TRUE) - $t1;
    
    
    if (!$ret) {
        $err = curl_error($ch);
        LOGD(sprintf("cURL 请求失败，耗时 %0.3f 秒: %s", $t2, $err));
        return FALSE;
    }
    else {
        LOGD(sprintf("cURL 请求成功，耗时 %0.3f 秒，返回的原始结果是: %s", $t2, var_export($ret, TRUE)));
        return $ret;
    }
}

/**
 * 记录一行日志
 */
function LOGD($s) {
    $time = date('Y-m-d H:i:s');
    $time .= sprintf('.%06d', explode(' ', microtime())[0] * 1000000);
    
    $log = sprintf("[%s] %s\n", $time, $s);
    
    file_put_contents(LOG_PATH, $log, FILE_APPEND);
}

/**
 * 根据当前的请求参数，查找到对应的 API TOKEN
 */
function search_api_token() {   
    $sign = '';
    
    $params = $_POST;
    ksort($params);
    
    if (isset($params['sign'])) {
        $sign = $params['sign'];
        unset($params['sign']);
    }
    else {
        LOGD("没有提供签名签名参数(sign)");
        return FALSE;
    }
    
    $tokens = [];
    foreach ($params as $k => $v) {
        $tokens[] = "{$k}={$v}";
    }
    $plain = implode('&', $tokens);

    foreach (DNSPOD_API_TOKENS as $api_token) {
        /// 检查 nonce
        $ts = time();
        $c = get_config($params['domain'], $params['record_id']);
        if (!isset($c['timestamp'])) {
            $c['timestamp'] = 0;
        }
        $timestamp = isset($params['timestamp']) ? (int)$params['timestamp'] : 0;
        
        if ((int)$timestamp <= $c['timestamp']) {
            LOGD("timestamp({$timestamp}) 已经被使用过了, GET 参数: " . json_encode($params));
            return FALSE;
        }
        
        $our_sign = md5($plain . $api_token);
        LOGD("检查签名 {$our_sign} == {$sign}，明文是 {$plain}{$api_token}");
        if ($our_sign == $sign) {
            $c['timestamp'] = $params['timestamp'];
            set_config($params['domain'], $params['record_id'], $c);
            
            return $api_token;
        }
    }
    
    return FALSE;
}