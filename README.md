# chat
websocket 聊天室 https://chat.ridersam.cn/
## config
参考nginx.conf
## install
1. 需要安装Msgpack扩展，项目地址 https://github.com/msgpack/msgpack-php/
2. 需要安装Redis，项目地址 https://redis.io/
3. 需要安装libevent扩展，项目地址 https://github.com/expressif/pecl-event-libevent
4. 需要安装event扩展，项目地址 http://pecl.php.net/package/event
5. 视频聊天采用webrtc技术，要求网站是https协议，需要搭建turnserver，项目地址 https://github.com/coturn/coturn/
6. PHP版本：5.3+，建议7.0+
## shell
```
php Client 8080
```
## support browser
1. chrome
2. firefox
3. opera
4. qq