## 简介
WebSocket 聊天室

## 目录结构
```
chat
├── docker              Docker相关配置
│   ├── conf
│   │   ├── nginx       nginx网站配置.conf
│   │   └── php         PHP运行配置.ini
│   └── source          扩展包
├── html                HTML页面
│   ├── css
│   ├── fonts
│   ├── images
│   ├── js
│   ├── media
│   └── tests
└── php                 PHP服务脚本
    ├── logs
    └── server
```

## Docker安装
1. 修改默认docker配置 `docker-compose.yml` 中的目录映射
2. 修改默认网站配置 `nginx/www.conf`
3. 启动服务 `docker-compose up`
4. 浏览器访问 index.html

## 常规安装
1. 环境要求：Redis 2.6+、PHP 7.2+ zts、libevent-dev、Apache/nginx
2. 安装扩展 `pecl install msgpack redis event` 并添加到 `php.ini` 中
3. 启动服务 `php Client.php -d -p 8080 -n 8`
4. 浏览器访问 index.html

## 启动参数说明
1. -d 后台运行
2. -p port 监听端口号
3. -n num 开启的子进程数，至少为1

## 视频聊天
采用WebRTC技术，有以下要求：
1. 网站以https协议访问
2. 搭建turnserver实现NAT穿透，请访问 https://github.com/coturn/coturn/
3. 修改 `index.js` 中的 `iceServers` 配置

## 支持的浏览器
1. Chrome
2. Firefox
3. Edge
4. Opera
5. ...

## 待办
- [ ] 重写界面
- [ ] 细节优化

## 相关链接
[pecl-event](https://bitbucket.org/osmanov/pecl-event)
[msgpack](https://github.com/msgpack/msgpack-php)
[phpredis](https://github.com/phpredis/phpredis/)