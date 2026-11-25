<?php
//小淋 - 永恒之蓝[修改-原js仅能在cf的works里使用,国内访问限制较大,故而改为自由部署]
// 适配PHP7.3 + 新增出站代理 + 修复境外域名DNS解析污染问题
// 引入外部配置文件（优先加载外部域名，不存在则用默认值）
if (file_exists('config.php')) {
require_once 'config.php';
} else {
// 备用默认域名（防止配置文件丢失）
define('BASE_URL', 'https://nekocloud.xx.kg');
}
define('USER_AGENT', 'Mozilla/5.0');
// 新增：定义保存账号和订阅地址的CSV文件路径（可自行修改，确保服务器有写入权限）
define('SAVE_FILE', 'account_subscribe_log.csv');

// 代理配置：替换成你服务器可用的出站代理（http://ip:端口），无代理填空字符串关闭
define('OUT_PROXY', '');
define('OUT_PROXY_TYPE', CURLPROXY_HTTP);

// 2. 工具函数：生成随机邮箱（10位随机字符+@gmail.com）
function randomEmail() {
$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
$username = substr(str_shuffle($chars), 0, 10);
return $username. '@gmail.com';
}

// 3. 工具函数：生成12位随机密码（含大小写字母+数字）
function randomPassword($length = 12) {
$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
$password = substr(str_shuffle($chars), 0, $length);
return $password;
}

// 新增：4. 保存账号、密码、订阅地址到服务器CSV文件
function saveAccountAndSubscribe($email, $password, $subscribeUrl) {
try {
// 1. 准备保存的数据（时间戳+账号+密码+订阅地址，用英文逗号分隔CSV格式）
$time = date('Y-m-d H:i:s'); // 当前时间（便于追溯）
$data = [
$time, // 操作时间
$email, // 生成的账号（邮箱）
$password, // 生成的密码
$subscribeUrl // 原订阅地址
];
// 转义CSV特殊字符（防止逗号、引号导致格式错乱）
$escapedData = array_map(function($item) {
return '"'. str_replace('"', '""', $item). '"';
}, $data);
$line = implode(',', $escapedData). "\n"; // 拼接为一行CSV数据

// 2. 处理文件：不存在则创建并写入表头，存在则直接追加
$fileHandle = fopen(SAVE_FILE, 'a+'); // 'a+' 模式：追加写入，文件不存在则创建
if (!$fileHandle) {
throw new Exception('无法打开保存文件，请检查服务器文件权限');
}

// 3. 若文件为空（新创建），先写入CSV表头
fseek($fileHandle, 0); // 移动到文件开头
if (fgets($fileHandle) === false) { // 读取第一行，判断是否为空
$header = "操作时间,账号(邮箱),密码,订阅地址\n";
fwrite($fileHandle, $header);
}

// 4. 追加数据到文件末尾
fseek($fileHandle, 0, SEEK_END); // 移动到文件末尾
fwrite($fileHandle, $line);
fclose($fileHandle);

return ['success' => true];
} catch (Exception $e) {
// 保存失败不抛出异常（避免影响主功能），仅返回错误信息
return ['success' => false, 'msg' => $e->getMessage()];
}
}

// 5. 核心工具函数：封装curl请求
function curlRequest($url, $method = 'GET', $data = null, $headers = []) {
$ch = curl_init(); // 初始化curl句柄（仅在函数内有效）

// 基础curl配置
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // 结果以字符串返回
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // 自动跟随302跳转
curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT); // 模拟浏览器UA
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 跳过SSL证书验证（避免境外机场报错）
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
// 强制使用公共境外DNS，解决国内服务器域名解析污染失败
curl_setopt($ch, CURLOPT_DNS_SERVERS, '8.8.8.8,1.1.1.1');

// ==========新增出站代理配置============
if (!empty(OUT_PROXY)) {
curl_setopt($ch, CURLOPT_PROXY, OUT_PROXY);
curl_setopt($ch, CURLOPT_PROXYTYPE, OUT_PROXY_TYPE);
// 代理需要账号密码时启用
// curl_setopt($ch, CURLOPT_PROXYUSERPWD, "user:pass");
}
// =====================================

// 配置POST请求
if ($method === 'POST') {
curl_setopt($ch, CURLOPT_POST, true);
if ($data) {
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
}
}

// 配置请求头
if (!empty($headers)) {
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
}

// 执行请求并获取结果
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); // 响应状态码
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE)?: 'text/plain'; // 响应内容类型
$error = curl_error($ch); // 错误信息

// 关闭curl句柄
curl_close($ch);

// 错误处理
if ($error) {
return ['success' => false, 'msg' => 'curl请求失败: '. $error];
}

// 返回结果（包含contentType，供外部设置响应头）
return [
'success' => true,
'httpCode' => $httpCode,
'data' => $response,
'contentType' => $contentType
];
}

// 6. 主业务逻辑：注册→登录→获取订阅→返回节点→保存信息
function handleSubscribe() {
try {
// 步骤1：随机注册账号
$email = randomEmail();
$password = randomPassword();
$registerData = json_encode([
'email' => $email,
'password' => $password,
'email_code' => ''
]);
$registerHeaders = [
'Content-Type: application/json',
'Content-Length: '. strlen($registerData)
];
$registerResp = curlRequest(
BASE_URL. '/api/v1/passport/auth/register',
'POST',
$registerData,
$registerHeaders
);
if (!$registerResp['success']) throw new Exception($registerResp['msg']);
if ($registerResp['httpCode']!== 200) throw new Exception('注册失败，状态码: '. $registerResp['httpCode']);
$registerJson = json_decode($registerResp['data'], true);
if (empty($registerJson['data'])) throw new Exception('注册接口返回异常');

// 步骤2：登录获取Token
$loginData = json_encode([
'email' => $email,
'password' => $password
]);
$loginHeaders = [
'Content-Type: application/json',
'Content-Length: '. strlen($loginData)
];
$loginResp = curlRequest(
BASE_URL. '/api/v1/passport/auth/login',
'POST',
$loginData,
$loginHeaders
);
if (!$loginResp['success']) throw new Exception($loginResp['msg']);
if ($loginResp['httpCode']!== 200) throw new Exception('登录失败，状态码: '. $loginResp['httpCode']);
$loginJson = json_decode($loginResp['data'], true);
$token = isset($loginJson['data']['auth_data'])? $loginJson['data']['auth_data']: '';
if (empty($token)) throw new Exception('未获取到登录Token');

// 步骤3：用Token获取订阅链接
$subHeaders = [
'Authorization: '. $token,
'User-Agent: '. USER_AGENT
];
$subResp = curlRequest(
BASE_URL. '/api/v1/user/getSubscribe',
'GET',
null,
$subHeaders
);
if (!$subResp['success']) throw new Exception($subResp['msg']);
if ($subResp['httpCode']!== 200) throw new Exception('获取订阅链接失败，状态码: '. $subResp['httpCode']);
$subJson = json_decode($subResp['data'], true);
$subscribeUrl = isset($subJson['data']['subscribe_url'])? $subJson['data']['subscribe_url']: '';
if (empty($subscribeUrl)) throw new Exception('未找到订阅地址');

// 新增：步骤4. 保存账号、密码、订阅地址到服务器文件
$saveResult = saveAccountAndSubscribe($email, $password, $subscribeUrl);
if (!$saveResult['success']) {
error_log('账号订阅保存失败: '. $saveResult['msg']);
}

// 步骤5：请求订阅内容并返回
$clientUA = isset($_SERVER['HTTP_USER_AGENT'])? $_SERVER['HTTP_USER_AGENT']: USER_AGENT;
$targetHeaders = ['User-Agent: '. $clientUA];
$targetResp = curlRequest($subscribeUrl, 'GET', null, $targetHeaders);
if (!$targetResp['success']) throw new Exception($targetResp['msg']);

header('Content-Type: '. $targetResp['contentType']);
echo $targetResp['data'];
exit;
} catch (Exception $e) {
http_response_code(500);
echo '服务异常: '. $e->getMessage();
exit;
}
}

// 7. 跨域配置
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: User-Agent');

// 8. 处理预检请求（OPTIONS方法）
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
http_response_code(204);
exit;
}

// 9. 仅允许GET请求
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
handleSubscribe();
} else {
http_response_code(405);
echo '仅支持GET请求';
exit;
}
?>
