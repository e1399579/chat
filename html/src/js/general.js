import IMessage from "./imessage.js";
import Constant from "./constant.js";
import {DataHelper, generateUUID} from "./util.js";
import IProcessor from "./iprocessor.js";

export class GeneralMessage extends IMessage {
    process(vm, mess) {
        switch (mess.type) {
            // 用户状态
            // myself @other TODO 修改消息样式
            case Constant.MESSAGE_SELF:
            case Constant.IMAGE_SELF:
            case Constant.FILE_SELF:
            case Constant.MUSIC_SELF:
                break;
            case Constant.USER_REGISTER: // 需要注册
                vm.$modal.show('login-modal');
                break;
            case Constant.USER_INCORRECT: // 登录出错
                vm.$notify({
                    group: 'tip',
                    text: mess.mess,
                    type: 'error',
                });
                break;
            case Constant.USER_LOGIN: // 已经登录
            {
                let user = mess.user;
                // 隐藏modal
                vm.$modal.hide('login-modal');
                // 更新vm.user
                vm.user.id = user.user_id;
                vm.user.displayName = user.username;
                vm.user.avatar = user.avatar ? vm.upload_url + user.avatar : Constant.DEFAULT_AVATAR;
                vm.user.is_active = parseInt(user.is_active)
                vm.user.is_super_admin = user.is_super_admin;
                // 刷新cookie 在线用户列表
                vm.setCookie("user", JSON.stringify(vm.user));
                vm.online_users.set(user.user_id, user);
                vm.$notify({
                    group: 'tip',
                    text: `${vm.user.displayName}，欢迎回来`,
                    type: 'success',
                });

                // 查询在线人数
                vm.sendMessage(Constant.USER_ONLINE_TOTAL);

                // 查询群组列表
                vm.sendMessage(Constant.GROUP_QUERY_LIST);

                // 查询在线用户
                vm.sendMessage(Constant.USER_LIST);
                break;
            }
            case Constant.USER_LIST: // 在线用户
            {
                let users = mess.users;
                for (let user of users) {
                    vm.online_users.set(user.user_id, user);
                }
                break;
            }
            case Constant.USER_ONLINE://欢迎消息
            {
                let user = mess.user;
                // 联系人
                vm.im.appendMessage({
                    id: generateUUID(),
                    status: "succeed",
                    type: "event",
                    sendTime: mess.timestamp,
                    content: `用户 ${user.username} 进入聊天室`,
                    toContactId: "0",
                }, true);
                if (vm.user.id !== user.user_id) {
                    ++vm.online_total;
                    user.is_online = true;
                    vm.online_users.set(user.user_id, user);
                    vm.updateContact(user.user_id, {is_online: true});
                    vm.updateContact("0", {online_total:vm.online_total, unread: "+1"});
                }
                break;
            }
            case Constant.USER_ONLINE_TOTAL:
                vm.online_total = mess.mess; // 置为实际数量
                vm.updateContact("0", {online_total:vm.online_total});
                break;
            case Constant.USER_QUIT:
            {
                let user = mess.user;
                if (vm.user.id !== user.user_id) {
                    vm.im.appendMessage({
                        id: generateUUID(),
                        status: "succeed",
                        type: "event",
                        sendTime: mess.timestamp,
                        content: `用户 ${user.username} 退出聊天室`,
                        toContactId: "0",
                        fromUser: ""
                    }, true);
                    vm.online_total = Math.max(--vm.online_total, 1);
                    vm.online_users.delete(user.user_id);
                    vm.updateContact("0", {online_total:vm.online_total, unread: "+1"});
                    vm.updateContact(user.user_id, {is_online: false});
                }
                break;
            }
            case Constant.USER_QUERY:
            {
                let user = mess.user;
                let user_id = user.user_id;
                vm.addUser(user);
                // 执行下一步
                let resolve = vm.query_next.get(user_id);
                if (resolve) {
                    resolve(user);
                }
                break;
            }
            case Constant.USER_DOWNLINE://下线
            case Constant.USER_REMOVE://移除
            case Constant.USER_DISABLED: //禁用
            {
                vm.user.is_active = 0;
                vm.disconnect_mess = mess.mess;
                vm.$modal.show('disconnect-modal');
                break;
            }

            case Constant.USER_AVATAR_SUCCESS: {
                vm.user.avatar = vm.upload_url + mess.mess;
                // 刷新cookie
                vm.setCookie("user", JSON.stringify(vm.user));
                // 刷新在线用户
                let user = vm.online_users.get(vm.user.id);
                user.avatar = mess.mess;
                vm.online_users.set(vm.user.id, user);
                vm.$notify({
                    group: 'tip',
                    text: '上传头像成功',
                    type: 'success',
                });
                break;
            }
            case Constant.USER_AVATAR_FAIL: {
                vm.$notify({
                    group: 'tip',
                    text: '上传头像失败',
                    type: 'warn',
                });
                break;
            }

            // 公共、个人消息
            case Constant.MESSAGE_COMMON: //公共消息
            case Constant.MESSAGE_OTHER: //other @me
            case Constant.IMAGE_COMMON:
            case Constant.IMAGE_OTHER:
            case Constant.MUSIC_COMMON:
            case Constant.MUSIC_OTHER:
            case Constant.FILE_COMMON:
            case Constant.FILE_OTHER:
            {
                let sender_id = mess.sender_id;
                if (sender_id === vm.user.id) return; // 自己发的，忽略，避免重复

                // 查询收信人（群组）
                let receiver_id = mess.receiver_id;
                let is_group = receiver_id && (receiver_id !== vm.user.id);
                if (is_group) {
                    let contact = vm.im.findContact(receiver_id);
                    if (!contact) {
                        vm.sendMessage(Constant.GROUP_QUERY_INFO, '0', receiver_id);
                        let promise = new Promise((resolve) => {
                            vm.query_group_next.set(receiver_id, resolve);
                        });
                        promise.then((group) => {
                            vm.query_group_next.delete(group.id);
                            // 添加联系人
                            vm.addGroupContact(group);
                        });
                    }
                }

                // 查询发信人，若找不到用户，则查询异步处理
                vm.getUserAsync(sender_id, (user) => {
                    vm.addPersonalContact(user);
                    vm.receiveMessage(mess, user);
                });
                break;
            }

            case Constant.HISTORY_MESSAGE_COMMON:
            case Constant.HISTORY_MESSAGE_PERSONAL:
            {
                let contact_id = mess.receiver_id;
                let resolve = vm.pull_next.get(contact_id);
                if (resolve) {
                    resolve(mess);
                }
                break;
            }

            // 文件上传
            case Constant.FILE_UPLOAD_SUCCESS:
            {
                // hash, path, size
                let resolve = vm.upload_next.get(mess.mess.hash);
                resolve(mess.mess);
                break;
            }

            // 通知
            case Constant.ERROR:
            case Constant.WARNING:
            case Constant.SYSTEM:
            {
                vm.receiveMessage(mess);
                break;
            }

            default:
            {
                this.next.process(vm, mess);
                break;
            }
        }
    }
}

export class GeneralProcessor extends IProcessor {
    getData() {
        return {
            im: null,
            server_url: process.env.VUE_APP_SERVER_URL,
            upload_url: process.env.VUE_APP_UPLOAD_URL,
            default_avatar_url: Constant.DEFAULT_AVATAR,
            width: window.innerWidth - 16,
            height: window.innerHeight - 16,
            username: "",
            password: "",
            user: {
                id: 0, displayName: '',
                avatar: Constant.DEFAULT_AVATAR,
                is_active: 1,
                is_super_admin: false,
            },
            socket: null,
            reconnect_times: 0,
            disconnect_mess: "",
            online_total: 0,
            online_users: new Map(),
            pull_next: new Map(),
            send_next: new Map(),
            query_next: new Map(),
            upload_next: new Map(),
            query_member_next: new Map(),
            query_group_next: new Map(),
            images: [],
            image_crop: {
                show: false,
            },
        };
    }

    getMethods() {
        return {
            // 回调方法
            onOpen() {
                // 从cookie中获取信息
                let user = JSON.parse(this.getCookie('user'));
                if (typeof user === 'object') {
                    this.user.id = user.id;
                    this.user.displayName = user.displayName;
                    this.user.avatar = user.avatar ? user.avatar : Constant.DEFAULT_AVATAR;
                    this.user.is_active = parseInt(user.is_active)
                    this.user.is_super_admin = user.is_super_admin;
                    this.sendMessage(Constant.USER_LOGIN);
                } else {
                    this.$modal.show('login-modal');
                }
            },
            onMessage(message) {
                let mess = DataHelper.decode(message.data);
                mess.timestamp = mess.timestamp * 1000;
                this.trace("receive", mess);
                this.$modal.hide('disconnect-modal');
                this.message_handler.process(this, mess);
            },
            onClose() {
                // 联系人离线
                if (!this.user.is_active) return; // 被禁用
                if (this.socket.readyState === WebSocket.OPEN) return; // 重试多次时，避免连接成功后再次调用

                this.disconnect_mess = (new Date()).format() + ' 已断线，重试中...';
                this.$modal.show('disconnect-modal');
                let timer;
                let handler = () => {
                    try {
                        //断线重连
                        if (this.reconnect_times >= Constant.MAX_LIMITS) {
                            window.clearInterval(timer);
                            this.$notify({
                                group: 'tip',
                                text: '无法连接到服务器，请稍候再试',
                                type: 'warn',
                            });
                            return this.$modal.hide('disconnect-modal');
                        }
                        if (this.socket.readyState === WebSocket.OPEN) {
                            window.clearInterval(timer);
                            this.socket.addEventListener('message', this.onMessage);
                            this.socket.addEventListener('close', this.onClose);
                            this.socket.addEventListener('error', this.onError);

                            this.onOpen();

                            this.reconnect_times = 0;
                            return this.$modal.hide('disconnect-modal');
                        }
                        this.socket = new WebSocket(this.server_url);
                        this.socket.binaryType = 'arraybuffer';
                        this.reconnect_times++;
                    } catch (e) {
                        this.trace(e);
                    }
                };
                timer = window.setInterval(handler, 2000);
            },
            onError(e) {
                this.trace(e);
                this.$notify({
                    group: 'tip',
                    text: '连接服务器出错',
                    type: 'error',
                });
            },

            // 工具方法
            timeFormat(timestamp) {
                let date = new Date(timestamp);
                let is_today = ((new Date()).getTime() - date.getTime() < 8.64e7);
                let format = is_today ? 'H:i' : 'y.m.d H:i';
                return date.format(format);
            },
            trace() {
                if (!Constant.DEBUG)
                    return;
                let now = (window.performance.now() / 1000).toFixed(3);
                console.group(now);
                console.log(...arguments);
                console.groupEnd();
            },
            getCookie(name) {
                let nameEQ = name + "=";
                let ca = document.cookie.split(';');    //把cookie分割成组
                for (let c of ca) {
                    while (c.charAt(0) === ' ') {          //判断一下字符串有没有前导空格
                        c = c.substring(1, c.length);      //有的话，从第二位开始取
                    }
                    if (c.indexOf(nameEQ) === 0) {       //如果含有我们要的name
                        return unescape(c.substring(nameEQ.length, c.length));    //解码并截取我们要值
                    }
                }
                return false;
            },
            setCookie(name, value) {
                let exp = new Date();
                exp.setTime(exp.getTime() + Constant.COOKIE_EXPIRE_DAYS * 24 * 60 * 60 * 1000);
                document.cookie = name + "=" + escape(value) + ";expires=" + exp.toGMTString();
            },
            sendMessage(type, receiver_id = '0', mess = null, id = "", trace_id = "") {
                let defaults = {
                    type: Constant.MESSAGE_COMMON,
                    receiver_id: '0',
                    mess: "",
                    trace_id: trace_id ? trace_id : DataHelper.buildTraceId(),
                };
                let data = Object.assign(defaults, {
                    type,
                    sender_id: this.user.id,
                    receiver_id,
                    mess,
                });
                if (id) data.id = id;
                this.trace("send", data);
                this.socket.send(DataHelper.encode(data));
            },
            parseMessage(mess, sender = null) {
                let id, type, content, fileSize, fileName;
                id = mess.id;
                switch (mess.type) {
                    case Constant.MESSAGE_COMMON:
                    case Constant.MESSAGE_SELF:
                    case Constant.MESSAGE_OTHER:
                    {
                        type = "text";
                        content = mess.mess;
                        fileSize = 0;
                        fileName = "";
                        break;
                    }
                    case Constant.IMAGE_COMMON:
                    case Constant.IMAGE_SELF:
                    case Constant.IMAGE_OTHER:
                    {
                        type = "image";
                        content = this.upload_url + mess.mess.path;
                        fileSize = mess.mess.size;
                        fileName = mess.mess.name;
                        break;
                    }
                    case Constant.FILE_COMMON:
                    case Constant.FILE_SELF:
                    case Constant.FILE_OTHER:
                    {
                        type = "file";
                        content = this.upload_url + mess.mess.path;
                        fileSize = mess.mess.size;
                        fileName = mess.mess.name;
                        break;
                    }
                    case Constant.MUSIC_COMMON:
                    case Constant.MUSIC_SELF:
                    case Constant.MUSIC_OTHER:
                    {
                        type = "music";
                        content = this.upload_url + mess.mess.path;
                        fileSize = mess.mess.size;
                        fileName = mess.mess.name;
                        break;
                    }
                    case Constant.GROUP_CREATE:
                    {
                        type = "event";
                        let admin_name = (sender.user_id === this.user.id) ? "你" : sender.username;
                        content = admin_name + "创建了群聊";
                        fileSize = 0;
                        fileName = "";
                        break;
                    }
                    case Constant.ERROR:
                    case Constant.WARNING:
                    case Constant.SYSTEM:
                    {
                        id = generateUUID();
                        type = "event";
                        content = mess.mess;
                        fileSize = 0;
                        fileName = "";
                        break;
                    }
                }
                let toContactId;
                switch (mess.type) {
                    case Constant.MESSAGE_OTHER:
                    case Constant.IMAGE_OTHER:
                    case Constant.FILE_OTHER:
                    case Constant.MUSIC_OTHER:
                    {
                        toContactId = mess.sender_id;
                        break;
                    }
                    default:
                    {
                        toContactId = mess.receiver_id;
                        break;
                    }
                }
                return {
                    id: id,
                    status: "succeed",
                    type,
                    sendTime: mess.timestamp,
                    content,
                    toContactId,
                    fileSize,
                    fileName,
                    fromUser: {
                        //如果 id == this.user.id消息会显示在右侧，否则在左侧
                        id: mess.sender_id,
                        displayName: sender ? sender.username : '',
                        avatar: sender && sender.avatar ? this.upload_url + sender.avatar : Constant.DEFAULT_AVATAR,
                    }
                };
            },
            receiveMessage(mess, sender = null) {
                let parsed = this.parseMessage(mess, sender);
                this.trace('parsed', parsed);
                this.im.appendMessage(parsed, true);
                // this.updateContact(parsed.toContactId, {unread: "+1"});
            },
            updateContact(contact_id, option) {
                this.im.updateContact({
                    id: contact_id,
                    ...option,
                });
            },
            addPersonalContact(user, lastMessage = "") {
                let data = {
                    id: user.user_id,
                    displayName: user.username,
                    avatar: user.avatar ? this.upload_url + user.avatar : Constant.DEFAULT_AVATAR,
                    lastContent: lastMessage,
                    index: "Personal",

                    // 新加字段
                    is_group: false,
                    is_online: user.is_online,
                    query_time: ((new Date()).getTime() + performance.now()) / 1000,
                };
                this.im.appendContact(data);
            },
            addUser(user) {
                return this.online_users.set(user.user_id, user);
            },
            getUser(user_id) {
                return this.online_users.get(user_id);
            },
            getUserAsync(user_id, callback = new Function()) {
                let user = this.getUser(user_id);
                if (user) {
                    callback(user);
                } else {
                    this.sendMessage(Constant.USER_QUERY, user_id, user_id);
                    let promise = new Promise((resolve) => {
                        this.query_next.set(user_id, resolve);
                    });
                    promise.then((user) => {
                        this.query_next.delete(user.user_id);
                        // 添加联系人
                        this.addPersonalContact(user);
                        return user;
                    }).then(callback);
                }
            },
            addGroupContact(group, lastMessage = "", members = new Map()) {
                let data = {
                    id: group.id,
                    displayName: group.name,
                    avatar: group.avatar ? this.upload_url + group.avatar : Constant.DEFAULT_AVATAR,
                    lastContent: lastMessage,
                    index: "Group",

                    // 新加字段
                    online_total: 0,
                    is_group: true,
                    members: members,
                    query_time: ((new Date()).getTime() + performance.now()) / 1000,
                };
                this.im.appendContact(data);
            },

            // 交互方法
            login(e) {
                e.preventDefault();
                let username = this.username.trim();
                if (username.length < 3) {
                    this.$notify({
                        group: 'tip',
                        text: "用户名需要3字符以上",
                        type: 'error',
                    });
                    return false;
                }
                // 登录/注册
                this.socket.send(DataHelper.encode({
                    type: Constant.USER_REGISTER,
                    username: username.substr(0, 30),
                    password: this.password ? this.password : '123456',
                }));
                return false;
            },
            getHistory(contact, next) {
                let query_time = (contact.query_time > 0) ? contact.query_time : ((new Date()).getTime() + performance.now()) / 1000;
                let type = contact.is_group ? Constant.HISTORY_MESSAGE_COMMON : Constant.HISTORY_MESSAGE_PERSONAL;
                // 异步查询历史消息
                let promise = new Promise((resolve, reject) => {
                    try {
                        this.sendMessage(type, contact.id, query_time);
                        this.pull_next.set(contact.id, resolve); // 保存resolve
                    } catch (e) {
                        reject(e);
                        this.trace(e);
                    }
                });
                promise.then((mess) => {
                    let query_id_list = new Set(); // 要查询的唯一用户ID
                    let contact_id = mess.receiver_id;
                    let list = mess.mess;
                    this.pull_next.delete(contact_id); // 清除resolve
                    // 更新下次查询时间（以第1条消息为准），精确到4位，和服务器保持一致，并去除边界的一条
                    list.length && (contact.query_time = list[0].timestamp - 0.0001);

                    // 查询未知的用户信息，消息列表需要展示昵称和头像
                    for (let one of list) {
                        let sender_id = one.sender_id;
                        let user = this.getUser(sender_id);
                        if (!user) {
                            query_id_list.add(sender_id);
                        }
                    }
                    let promise_list = [];
                    query_id_list.forEach((user_id) => {
                        let promise2 = new Promise((resolve) => {
                            this.query_next.set(user_id, resolve);
                            this.sendMessage(Constant.USER_QUERY, user_id, "", "", user_id);
                        });
                        promise2.then((user) => {
                            this.addPersonalContact(user);
                        });
                        promise_list.push(promise2);
                    });
                    // 用户信息全部查询完毕，再处理消息
                    Promise.all(promise_list).then(() => {
                        let messages = [];
                        for (let one of list) {
                            let sender_id = one.sender_id;
                            let user = this.getUser(sender_id);
                            one.timestamp = one.timestamp * 1000;
                            messages.push(this.parseMessage(one, user));
                        }
                        let is_end = (list.length < 10);
                        // 将第二个参数设为true，表示已到末尾
                        next(messages, is_end);

                        // 清除resolve
                        query_id_list.forEach((user_id) => {
                            this.query_next.delete(user_id);
                        });
                    });
                });
            },
            // 发送消息
            send(message, next, file) {
                try {
                    this.trace('@send', message, file);

                    // 有文件时，修正type
                    if (file) {
                        let music_types = [
                            "audio/mpeg",
                        ];
                        if (music_types.includes(file.type)) {
                            message.type = "music";
                        }
                    }

                    let receiver_id = message.toContactId;
                    let contact = this.im.findContact(receiver_id);
                    let is_personal = !contact.is_group + 0;
                    let type_map = {
                        "image": [Constant.IMAGE_COMMON, Constant.IMAGE_PERSONAL],
                        "file": [Constant.FILE_COMMON, Constant.FILE_PERSONAL],
                        "text": [Constant.MESSAGE_COMMON, Constant.MESSAGE_PERSONAL],
                        "music": [Constant.MUSIC_COMMON, Constant.MUSIC_PERSONAL],
                    };
                    let type = type_map[message.type][is_personal];

                    // 文本直接发送
                    if (!file) {
                        this.sendMessage(type, receiver_id, message.content, message.id);
                        return next();
                    }

                    // 文件处理
                    // audio/mpeg image/png
                    let limit_size;
                    switch (message.type) {
                        case "image":
                        default:
                            limit_size = Constant.MAX_IMAGE_SIZE;
                            break;
                        case "music":
                            limit_size = Constant.MAX_MUSIC_SIZE;
                            break;
                        case "file":
                            limit_size = Constant.MAX_FILE_SIZE;
                            break;
                    }
                    if (file.size > limit_size) {
                        this.$notify({
                            group: 'tip',
                            text: '文件太大，限制：<' + (limit_size / 1024 ** 2) + 'M',
                            type: 'error',
                        });
                        return next({status:'failed'});
                    }

                    this.socket.send(file); // WebSocket发送文件时无法携带其他信息
                    let message_id = message.id;
                    let hashing = DataHelper.sha256(file);
                    hashing.then((hash) => {
                        return new Promise((resolve) => {
                            this.upload_next.set(hash, resolve);
                        });
                    }).then((info) => {
                        let {hash, path} = info;
                        this.upload_next.delete(hash);
                        let data = {
                            name: file.name,
                            path,
                            size: file.size,
                        };
                        this.sendMessage(type, receiver_id, data, message.id);
                        // 更新此条消息的URL
                        this.im.updateMessage({
                            id: message_id,
                            content: this.upload_url + path,
                        });
                        next();
                    });
                } catch (e) {
                    this.trace(e);
                    next({status:'failed'});
                }
            },

            // 打开/关闭抽屉，展示群组/私聊成员
            toggleDrawer() {
                // let self = this;
                this.im.changeDrawer({
                    position: "rightInside",
                    offsetY: 33,
                    width: 242,
                    height: this.$el.clientHeight - 33,
                });
            },

            // 消息点击
            messageClick(e, key, message) {
                let contact_id = message.toContactId;
                // 标记为已读
                this.updateContact(contact_id, {unread: 0});

                switch (message.type) {
                    case "image":
                    {
                        this.imagePreview(message.content);
                        break;
                    }
                    case "file":
                    {
                        window.open(message.content);
                        break;
                    }
                }
            },

            // 切换联系人
            changeContact(contact) {
                let contact_id = contact.id;
                this.updateContact(contact_id, {unread: 0});
                if (contact.is_group && contact_id && !contact.members.size) {
                    // 查询成员
                    let group_id = contact_id;
                    this.sendMessage(Constant.GROUP_QUERY_MEMBER, 0, group_id);
                    // TODO 更新列表
                    let promise = new Promise((resolve) => {
                        this.query_member_next.set(group_id, resolve);
                    });
                    promise.then((members) => {
                        members.forEach((member) => {
                            contact.members.set(member.user_id, member);
                        });
                        this.$forceUpdate();
                    });
                }
            },

            // 图片预览
            imagePreview(url) {
                let images = document.querySelectorAll(".lemon-message__content img[src^=http]");
                let index = 0;
                this.images = [];
                images.forEach((image, i) => {
                    this.images.push(image.src);
                    if (image.src === url) {
                        index = i;
                    }
                });
                this.$viewerApi({
                    images: this.images,
                    options: {
                        toolbar: true,
                        initialViewIndex: index,
                    },
                });
            },

            // 打开公告
            openNotice() {
                return '';
            },

            // 添加成员
            openAddGroupUser() {},

            // 更换头像
            changeAvatar() {
                this.image_crop.show = true;
            },

            cropSuccess(imgDataUrl) {
                fetch(imgDataUrl)
                    .then(res => res.blob())
                    .then(blob => {
                        let hashing = DataHelper.sha256(blob);
                        this.socket.send(blob); // 上传文件之后，服务器返回path, size, hash
                        return hashing; // return to next
                    }).then((hash) => {
                    // return Promise，在服务器返回数据时调用resolve
                    return new Promise((resolve) => {
                        this.upload_next.set(hash, resolve);
                    });
                }).then((info) => {
                    // 数据来自resolve(xxx)
                    let {path, size, hash} = info;
                    this.upload_next.delete(hash);
                    this.sendMessage(Constant.USER_AVATAR_UPLOAD, '0', {path, size});
                }).catch((e) => {
                    this.trace(e);
                });
            },
        };
    }
}