## 简介
WebSocket 聊天室，基于原生PHP和libevent

## 后端
### 目录结构
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

### 运行流程图
![流程图](./doc/flowchart.png "flowchart")

### 安装
#### Docker安装
1. 修改默认docker配置 `docker-compose.yml` 中的目录映射
2. 修改默认网站配置 `nginx/www.conf`
3. 创建目录（保存Redis快照） `mkdir /home/chat/data`
4. 创建目录（保存上传文件） `mkdir /home/chat/php/upload`
5. 启动服务 `docker-compose up`
6. 浏览器访问 index.html，示例：http://localhost/

#### 常规安装
1. 环境要求：Redis 2.6+、PHP 7.2+、libevent-dev、Apache/nginx
2. 安装扩展 `pecl install msgpack redis event` 并添加到 `php.ini` 中
3. 启动服务 `php Client.php -d -p 8080 -n 8`
4. 浏览器访问 index.html，示例：http://localhost/

#### 启动参数说明
1. -d 后台运行
2. -p port 监听端口号
3. -n num 开启的子进程数，至少为1

## 前端(新版html_v2)
### 框架/插件
1. 框架 [Vue 2](https://v2.cn.vuejs.org/)
2. 主界面 [lemon-imui](https://www.npmjs.com/package/lemon-imui)
3. 登录框 [vue-js-modal](https://www.npmjs.com/package/vue-js-modal)
4. 数据压缩 [msgpackr](https://www.npmjs.com/package/msgpackr)
5. JS编译器 [Babel](https://babel.nodejs.cn/docs/)
6. 提示框 [vue-notification](https://www.npmjs.com/package/vue-notification)
7. 图片预览 [v-viewer@legacy](https://github.com/mirari/v-viewer/tree/v2)
8. 头像裁剪 [vue-image-crop-upload](https://www.npmjs.com/package/vue-image-crop-upload)

### 安装
1. 创建.env.local文件，设置WebSocket服务器地址`VUE_APP_SERVER_URL`，设置文件服务器地址`VUE_APP_UPLOAD_URL`
2. 安装 `npm install`
3. 运行 `npm run serve`
4. 浏览器访问 index.html，示例：http://localhost:8081/

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

## 相关链接
[pecl-event](https://bitbucket.org/osmanov/pecl-event)
[msgpack](https://github.com/msgpack/msgpack-php)
[phpredis](https://github.com/phpredis/phpredis/)