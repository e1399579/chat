<template>
    <main>
        <lemon-imui :user="user" ref="im"
                    :hide-message-name="false"
                    @pull-messages="getHistory"
                    @message-click="messageClick"
                    @change-contact="changeContact"
                    @send="send">
            <template #message-title="contact">
                <div class="flex space-between">
                    <span>{{contact.displayName}}<span v-if="contact.is_group"> ({{contact.online_total}})</span></span>
                    <b @click="changeDrawer(contact)">···</b>
                </div>
            </template>
        </lemon-imui>
        <!--注册/登录弹框-->
        <modal name="login-modal" :clickToClose="false" :height="250" :width="500">
            <dialog class="box" open>
                <div id="bp-left" class="box-part">
                    <div id="partition-register" class="partition">
                        <div class="partition-title">请登录/注册</div>
                        <div class="partition-form">
                            <form autocomplete="off">
                                <input type="text" id="username" v-model="username" placeholder="起个名吧，亲:)"
                                       maxlength="30" autocomplete/>
                                <input type="password" id="password" v-model="password" placeholder="密码：默认123456"
                                       maxlength="16"/>
                                <button type="submit" class="large-btn github-btn" v-on:click="login">
                                    登录/注册
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div id="bp-right" class="box-part">
                    <div class="box-messages">{{login_mess}}</div>
                </div>
            </dialog>
        </modal>

        <!--掉线提示-->
        <modal name="disconnect-modal" :clickToClose="false" :height="25" :width="300">{{disconnect_mess}}</modal>

        <!--提示框-->
        <notifications group="tip" position="top center" />
    </main>
</template>

<script>
import './css/login.css';
import './css/main.css';

import {DataHelper} from './js/util.js';
import emoji from './js/emoji.js';
import default_avatar from './assets/chat.png';

const DEBUG = true;
const DEFAULT_AVATAR = default_avatar;
const MAX_LIMITS = 100; //断线最大重连次数
const COOKIE_EXPIRE_DAYS = 7; //cookie过期天数

const USER_LOGIN = 205; // 用户登录
const MESSAGE_COMMON = 100;//公共消息
const MESSAGE_SELF = 101;//本人消息
const MESSAGE_OTHER = 102;//他人消息
const MESSAGE_PERSONAL = 103;//私信
const USER_ONLINE = 200;//用户上线
const USER_DISABLED = 206;//用户禁用
const USER_DOWNLINE = 207;//用户下线
const USER_REMOVE = 209;//用户移除
const USER_ONLINE_TOTAL = 213; // 用户在线数量
const USER_QUIT = 201;//用户退出
const USER_QUERY = 203; //用户查询
const USER_REGISTER = 204;//用户注册
const USER_INCORRECT = 208;//用户名/密码错误
const HISTORY_MESSAGE_COMMON = 800; //历史公共消息
const HISTORY_MESSAGE_PERSONAL = 801; //历史个人消息

export default {
    name: 'App',
    components: {},
    data() {
        return {
            im: null,
            server_url: process.env.VUE_APP_SERVER_URL,
            username: "",
            password: "",
            user: {id: 0, displayName: '', avatar: DEFAULT_AVATAR, is_active: 1},
            socket: null,
            reconnect_times: 0,
            login_mess: "",
            disconnect_mess: "",
            online_total: 0,
            online_users: new Map(),
            contact_query_time: new Map(),
            pull_next: new Map(),
            send_next: new Map(),
            query_next: new Map(),
        }
    },
    watch: {
    },
    mounted() {
        const {im} = this.$refs;
        this.im = im;

        // 连接服务器，监听事件
        this.socket = new WebSocket(this.server_url);
        this.socket.binaryType = 'arraybuffer'; //设为二进制的原始缓冲区

        this.socket.addEventListener('open', this.onOpen);
        this.socket.addEventListener('message', this.onMessage);
        this.socket.addEventListener('close', this.onClose);
        this.socket.addEventListener('error', this.onError);

        // 菜单
        this.im.initMenus([{name: "messages"}, {name: "contacts"}]);

        // 初始化表情包
        im.initEmoji(emoji);

        // 大厅
        this.im.initContacts([{
            id: '0',
            displayName: "大厅",
            avatar: DEFAULT_AVATAR,
            index: "Group",
            unread: 0,

            // 新加字段
            online_total: 0,
            is_group: true,
        }]);
        this.contact_query_time.set('0', {timestamp: (new Date()).getTime() / 1000});
    },
    methods: {
        // 回调方法
        onOpen() {
            // 从cookie中获取信息
            let user = JSON.parse(this.getCookie('user'));
            if (typeof user === 'object') {
                this.user.id = user.id;
                this.user.displayName = user.displayName;
                this.user.avatar = user.avatar ? user.avatar : DEFAULT_AVATAR;
                this.sendMessage(USER_LOGIN);
            } else {
                this.$modal.show('login-modal');
            }
        },
        onMessage(message) {
            let mess = DataHelper.decode(message.data);
            mess.timestamp = parseInt(mess.timestamp * 1000);
            this.trace("receive", mess);
            // let id = mess.receiver_id;
            // let trace_id = mess.trace_id;
            this.$modal.hide('disconnect-modal');
            switch (mess.type) {
                // 用户状态
                case MESSAGE_SELF://myself @other // TODO 修改消息样式
                    break;
                case USER_REGISTER://需要注册
                    this.$modal.show('login-modal');
                    break;
                case USER_INCORRECT://登录出错
                    this.login_mess = mess.mess;
                    break;
                case USER_LOGIN://已经登录
                    this.login_mess = "登录成功";
                    this.$modal.hide('login-modal');
                    this.user.id = mess.user.user_id;
                    this.user.displayName = mess.user.username;
                    this.user.avatar = mess.user.avatar ? mess.user.avatar : DEFAULT_AVATAR;
                    this.setCookie("user", JSON.stringify(this.user));//刷新cookie
                    this.$modal.show('dialog', {
                        text: `${this.user.displayName}，欢迎回来`,
                        buttons: [
                            {
                                title: 'Cancel',
                                handler: () => {
                                    this.$modal.hide('dialog')
                                }
                            }
                        ]
                    });
                    this.$notify({
                        group: 'tip',
                        text: `${this.user.displayName}，欢迎回来`,
                        type: 'success',
                    });

                    // 查询在线人数
                    this.sendMessage(USER_ONLINE_TOTAL);
                    break;
                case USER_ONLINE://欢迎消息
                    // 联系人
                    this.im.appendMessage({
                        id: DataHelper.buildTraceId(),
                        status: "succeed",
                        type: "event",
                        sendTime: mess.timestamp,
                        content: `用户 ${mess.user.username} 进入聊天室`,
                        toContactId: "0",
                    });
                    if (this.user.id !== mess.user.user_id) {
                        ++this.online_total;
                        this.online_users.set(mess.user.user_id, mess.user);
                    }
                    this.updateContact("0", {online_total:this.online_total});
                    break;
                case USER_ONLINE_TOTAL:
                    this.online_total = mess.mess; // 置为实际数量
                    this.updateContact("0", {online_total:this.online_total});
                    break;
                case USER_QUIT: {
                    let user = mess.user;
                    this.im.appendMessage({
                        id: DataHelper.buildTraceId(),
                        status: "succeed",
                        type: "event",
                        sendTime: mess.timestamp,
                        content: `用户 ${user.username} 退出聊天室`,
                        toContactId: "0",
                        fromUser: ""
                    });
                    this.online_total = Math.max(--this.online_total, 1);
                    this.online_users.delete(user.user_id);
                    this.updateContact("0", {online_total:this.online_total});
                    break;
                }
                case USER_QUERY: {
                    let user = mess.user;
                    let user_id = user.user_id;
                    this.addUser(user);
                    // 执行下一步
                    let resolve = this.query_next.get(user_id);
                    if (resolve) {
                        resolve(user);
                    }
                    break;
                }
                case USER_DOWNLINE://下线
                case USER_REMOVE://移除
                case USER_DISABLED: {//禁用
                    this.user.is_active = 0;
                    this.disconnect_mess = this.disconnect_mess = mess.mess;
                    this.$modal.show('disconnect-modal');
                    break;
                }

                // 公共、个人消息
                case MESSAGE_COMMON: //公共消息
                case MESSAGE_OTHER: {//other @me
                    let sender_id = mess.sender_id;
                    if (sender_id === this.user.id) return; // 自己发的，忽略，避免重复
                    let sender = this.getUser(sender_id);
                    if (sender) {
                        this.receiveMessage(mess, sender);
                    } else {
                        // 若找不到用户，则查询异步处理
                        let promise = new Promise((resolve) => {
                            this.query_next.set(sender_id, resolve);
                            this.sendMessage(USER_QUERY, sender_id, "", sender_id);
                        });
                        promise.then((sender) => {
                            this.query_next.delete(sender.user_id);
                            // 显示联系人
                            this.updateContactFromUser(sender, mess.mess);
                            this.receiveMessage(mess, sender);
                        });
                    }
                    break;
                }

                case HISTORY_MESSAGE_COMMON:
                case HISTORY_MESSAGE_PERSONAL: {
                    let contact_id = mess.receiver_id;
                    let resolve = this.pull_next.get(contact_id);
                    if (resolve) {
                        resolve(mess);
                    }
                    break;
                }
                default: {
                    this.$notify({
                        group: 'tip',
                        text: '未知的消息类型：' + mess.type,
                        type: 'warn',
                    });
                }
            }
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
                    if (this.reconnect_times >= MAX_LIMITS) {
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
            // Util.toast("连接服务器出错");
        },

        // 工具方法
        trace() {
            if (!DEBUG)
                return;
            let now = (window.performance.now() / 1000).toFixed(3);
            console.group(now);
            console.log(...arguments);
            // console.trace();
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
            exp.setTime(exp.getTime() + COOKIE_EXPIRE_DAYS * 24 * 60 * 60 * 1000);
            document.cookie = name + "=" + escape(value) + ";expires=" + exp.toGMTString();
        },
        sendMessage(type, receiver_id = 0, mess = "", trace_id = "") {
            let defaults = {
                type: MESSAGE_COMMON,
                receiver_id: 0,
                mess: "",
                trace_id: trace_id ? trace_id : DataHelper.buildTraceId(),
            };
            let data = Object.assign(defaults, {
                type,
                sender_id: this.user.id,
                receiver_id,
                mess,
            });
            this.trace("send", data);
            this.socket.send(DataHelper.encode(data));
        },
        receiveMessage(mess, sender = null) {
            this.im.appendMessage({
                id: DataHelper.buildTraceId(),
                status: "succeed",
                type: "text",
                sendTime: mess.timestamp,
                content: mess.mess,
                toContactId: mess.receiver_id,
                fromUser: {
                    //如果 id == this.user.id消息会显示在右侧，否则在左侧
                    id: mess.sender_id,
                    displayName: sender ? sender.username : '',
                    avatar: sender ? sender.avatar : '',
                }
            });
        },
        updateContact(contact_id, option) {
            this.im.updateContact({
                id: contact_id,
                ...option,
            });
        },
        updateContactFromUser(user, lastMessage = "") {
            this.im.appendContact({
                id: user.user_id,
                displayName: user.username,
                avatar: user.avatar ? user.avatar : DEFAULT_AVATAR,
                lastContent: lastMessage,
                is_group: false,
            });
            this.im.updateContact({
                id: user.user_id,
                displayName: user.username,
                avatar: user.avatar ? user.avatar : DEFAULT_AVATAR,
                lastContent: lastMessage,
            });
        },
        addUser(user) {
            return this.online_users.set(user.user_id, user);
        },
        getUser(user_id) {
            return this.online_users.get(user_id);
        },

        // 交互方法
        login(e) {
            e.preventDefault();
            // 登录/注册
            this.socket.send(DataHelper.encode({
                type: USER_REGISTER,
                username: this.username.substr(0, 30),
                password: this.password ? this.password : '123456',
            }));
            return false;
        },
        getHistory(contact, next) {
            let _query_time = this.contact_query_time.get(contact.id);
            // 精确到4位，和服务器保持一致，并去除边界的一条
            let query_time = _query_time > 0 ? _query_time - 0.0001 : (new Date()).getTime() / 1000;
            let type = (contact.id === '0') ? HISTORY_MESSAGE_COMMON : HISTORY_MESSAGE_PERSONAL;
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
                list.length && this.contact_query_time.set(contact_id, list[0].timestamp); // 更新查询时间

                // 查询未知的用户信息，消息列表需要展示昵称和头像
                for (let one of list) {
                    let sender_id = one.sender_id;
                    let user = this.getUser(sender_id);
                    if (!user) {
                        query_id_list.add(sender_id);
                    }
                }
                let promise_list = [];
                let i = 0;
                query_id_list.forEach((user_id) => {
                    // 并发时似乎有BUG，延时处理
                    let promise2 = new Promise((resolve) => {
                        window.setTimeout(() => {
                            this.query_next.set(user_id, resolve);
                            this.sendMessage(USER_QUERY, user_id, "", user_id);
                        }, i * 2);
                    });
                    promise2.then((user) => {
                        this.updateContactFromUser(user);
                    });
                    promise_list.push(promise2);
                    i++;
                });
                // 用户信息全部查询完毕，再处理消息
                Promise.all(promise_list).then(() => {
                    let messages = [];
                    for (let one of list) {
                        let sender_id = one.sender_id;
                        let user = this.getUser(sender_id);
                        messages.push({
                            id: DataHelper.buildTraceId(),
                            status: 'succeed',
                            type: 'text',
                            sendTime: parseInt(one.timestamp * 1000),
                            content: one.mess,
                            toContactId: contact_id,
                            fromUser: {
                                id: one.sender_id,
                                displayName: user.username,
                                avatar: user.avatar ? user.avatar : DEFAULT_AVATAR,
                            }
                        });
                    }
                    let is_end = (list.length <= 0);
                    // 将第二个参数设为true，表示已到末尾
                    next(messages, is_end);

                    // 清除resolve
                    query_id_list.forEach((user_id) => {
                        this.query_next.delete(user_id);
                    });
                });
            });
        },
        send(message, next) {
            // 发送消息
            try {
                let receiver_id = message.toContactId;
                let type = (message.toContactId === '0') ? MESSAGE_COMMON : MESSAGE_PERSONAL;
                this.sendMessage(type, receiver_id, message.content);

                next();
            } catch (e) {
                this.trace(e);
                next({status:'failed'});
            }
        },
        changeDrawer() {
            let self = this;
            this.im.changeDrawer({
                position: "rightInside",
                offsetY: 33,
                height: this.$el.clientHeight - 33,
                render: () => {
                    // JSX see https://v2.cn.vuejs.org/v2/guide/render-function.html
                    // https://github.com/vuejs/babel-plugin-transform-vue-jsx/issues/38
                    let elements = [];
                    this.online_users.forEach((user) => {
                        if (user.user_id === this.user.id) {
                            elements.push(
                                <div class="slot-group-member">
                                    <div class="slot-group-avatar">
                                        <img src={user.avatar ? user.avatar : DEFAULT_AVATAR} alt="avatar" />
                                    </div>
                                    <div class="slot-group-name">{user.username}</div>
                                </div>
                            );
                        } else {
                            elements.push(
                                <div class="slot-group-member" v-lemon-contextmenu_click={[
                                    {
                                        text: "发消息",
                                        click(e, instance, hide) {
                                            self.chatWith(user);
                                            hide();
                                        },
                                    },
                                ]}
                                >
                                    <div class="slot-group-avatar">
                                        <img src={user.avatar ? user.avatar : DEFAULT_AVATAR} alt="avatar" />
                                    </div>
                                    <div class="slot-group-name">{user.username}</div>
                                </div>
                            );
                        }
                    });
                    return (
                        <div class="slot-group">
                            <div class="slot-group-title">群通知</div>
                            <div class="slot-group-notice">公告内容</div>
                            <div class="slot-group-title">群成员</div>
                            <input class="slot-search" placeholder="搜索群成员"/>
                            <div class="slot-group-panel flex">
                                {elements}
                            </div>
                        </div>
                    );
                },
            });
        },
        chatWith(user) {
            this.updateContactFromUser(user, "临时会话");
            this.im.changeContact(user.user_id);
        },
        messageClick(e, key, message) {
            let contact_id = message.toContactId;
            // 标记为已读
            this.updateContact(contact_id, {unread: 0});
        },
        changeContact(contact) {
            this.updateContact(contact.id, {unread: 0});
        },
    }
}
</script>

