# dnspod-ddns-proxy
将访问此地址的客户端 IP 更新到托管在 DNSPod 的域名的指定记录上

## 用法
搭建 HTTP 服务器。
OpenWRT 上发起请求访问 ddns.php，并通过 POST 传以下参数：

* _domain_ 域名名字
* _record_id_ DNSPod 上的解析记录的 ID
* _sub_domain_ 解析记录的子域名。必须传入，否则 DNSPod 会将子域名更新为 @
* _api_token_ 访问 DNSPod API 的 API TOKEN

