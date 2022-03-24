## 简介
WebSocket 聊天室

## docker安装
1. 构建PHP镜像
```
cd docker
docker build -t test/php7 .
```
2. 修改默认docker配置 `docker-compose.yml` 中的目录映射
3. 修改默认网站配置 `nginx/www.conf`
4. 启动服务 `docker-compose up`
5. 浏览器访问 index.html

## 常规安装
1. 环境要求：Redis 2.6+、PHP 7.0+、libevent-dev、Apache/nginx
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
- [ ] 前后端项目拆分
- [ ] 细节优化