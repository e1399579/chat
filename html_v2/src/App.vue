<template>
    <main>
        <lemon-imui :user="user" ref="im" @pull-messages="getHistory" @send="send"></lemon-imui>
        <!--注册/登录弹框-->
        <modal name="login-modal" :clickToClose="false" :height="250" :width="500">
            <dialog class="box" open>
                <div id="bp-left" class="box-part">
                    <div id="partition-register" class="partition">
                        <div class="partition-title">请登录/注册</div>
                        <div class="partition-form">
                            <form autocomplete="false">
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

        <!--提示框-->
        <notifications group="tip" position="top center" />
    </main>
</template>

<script>
import './css/login.css';

import {DataHelper} from './js/util.js';

const DEBUG = true;
const USER_LOGIN = 205; // 用户登录
const MESSAGE_COMMON = 100;//公共消息
const MESSAGE_SELF = 101;//本人消息
const MESSAGE_OTHER = 102;//他人消息
const MESSAGE_PERSONAL = 103;//私信
const USER_ONLINE = 200;//用户上线
const USER_ONLINE_TOTAL = 213; // 用户在线数量
const USER_QUIT = 201;//用户退出
const USER_REGISTER = 204;//用户注册
const USER_INCORRECT = 208;//用户名/密码错误
const HISTORY_MESSAGE_COMMON = 800; //历史公共消息
const HISTORY_MESSAGE_PERSONAL = 801; //历史个人消息
const MAX_LIMITS = 100; //断线最大重连次数
const COOKIE_EXPIRE_DAYS = 7; //cookie过期天数

export default {
    name: 'App',
    components: {},
    data() {
        return {
            im: null,
            server_url: process.env.VUE_APP_SERVER_URL,
            username: "",
            password: "",
            user: {id: 0, displayName: '', avatar: ''},
            socket: null,
            reconnect_times: 0,
            online_total: 0,
            login_mess: "",
            contact_query_time: new Map(),
            pull_callback: new Map(),
            send_callback: new Map(),
        }
    },
    watch: {
        // 监听人数，修改标题
        online_total: function (new_num) {
            this.im.updateContact({
                id: '0',
                displayName: `大厅 (${new_num})`,
            });
        }
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
        // im.initEmoji();

        // 大厅
        this.im.initContacts([{
            id: '0',
            displayName: "大厅",
            avatar: "",
            index: "D",
            unread: 0,
            //最近一条消息的内容，如果值为空，不会出现在“聊天”列表里面。
            //lastContentRender 函数会将 file 消息转换为 '[文件]', image 消息转换为 '[图片]'，对 text 会将文字里的表情标识替换为img标签,
            lastContent: "",
            //最近一条消息的发送时间
            lastSendTime: 0,
        }]);
        this.contact_query_time.set('0', {timestamp: (new Date()).getTime() / 1000});
    },
    methods: {
        onOpen() {
            // 从cookie中获取信息
            let user = JSON.parse(this.getCookie('user'));
            if (typeof user === 'object') {
                this.user.id = user.id;
                this.user.displayName = user.displayName;
                this.user.avatar = user.avatar;
                this.sendMessage(USER_LOGIN);
            } else {
                this.$modal.show('login-modal');
            }
        },
        onMessage(message) {
            let mess = DataHelper.decode(message.data);
            mess.timestamp = parseInt(mess.timestamp * 1000);
            this.trace(mess);
            // let id = mess.receiver_id;
            // let trace_id = mess.trace_id;
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
                    this.user.avatar = '';
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
                        fromUser: ""
                    });
                    if (this.user.id !== mess.user.user_id) ++this.online_total;
                    break;
                case USER_ONLINE_TOTAL:
                    this.online_total = mess.mess; // 置为实际数量
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
                    break;
                }

                // 公共、个人消息
                case MESSAGE_OTHER: {//other @me
                    this.im.appendMessage({
                        id: DataHelper.buildTraceId(),
                        status: "succeed",
                        type: "text",
                        sendTime: mess.timestamp,
                        content: mess.mess,
                        toContactId: mess.receiver_id,
                        fromUser: {
                            id: mess.sender_id,
                            displayName: "",
                            avatar: ""
                        }
                    });
                    break;
                }
                case MESSAGE_COMMON: {//公共消息
                    if (mess.sender_id === this.user.id) return;
                    this.im.appendMessage({
                        id: DataHelper.buildTraceId(),
                        status: "succeed",
                        type: "text",
                        sendTime: mess.timestamp,
                        content: mess.mess,
                        toContactId: mess.receiver_id,
                        fromUser: {
                            id: mess.sender_id,
                            displayName: "",
                            avatar: ""
                        }
                    });
                    break;
                }

                case HISTORY_MESSAGE_COMMON:
                case HISTORY_MESSAGE_PERSONAL: {
                    let contact_id = mess.receiver_id;
                    let next = this.pull_callback.get(contact_id);
                    let list = mess.mess;
                    let is_end = (list.length <= 0);
                    let messages = [];
                    for (let one of list) {
                        messages.push({
                            id: DataHelper.buildTraceId(),
                            status: 'succeed',
                            type: 'text',
                            sendTime: parseInt(one.timestamp * 1000),
                            content: one.mess,
                            toContactId: contact_id,
                            fromUser: {
                                id: one.sender_id,
                                displayName: '',
                                avatar: '',
                            }
                        });
                    }
                    list.length && this.contact_query_time.set(contact_id, list[0].timestamp);
                    //将第二个参数设为true，表示已到末尾，聊天窗口顶部会显示“暂无更多消息”，不然会一直转圈。
                    next(messages, is_end);
                    break;
                }
                default:
                    this.receiveMessage(mess);
            }
        },
        onClose() {
            //联系人离线
            if (!this.is_active) return;
            // let d = new Date();
            // let date = 'Y-m-d H:i:s';
            // let search = ['Y', 'm', 'd', 'H', 'i', 's'];
            // let replace = [d.getFullYear(), d.getMonth() + 1, d.getDate(), d.getHours(), d.getMinutes(), d.getSeconds()];
            // date = date.replaceMulti(search, replace);

            // let index = Util.loading(date + ' 已断线，重试中...', false, false);
            let timer = window.setInterval(() => {
                try {
                    //断线重连
                    if (this.reconnect_times >= MAX_LIMITS) {
                        window.clearInterval(timer);
                        // layer.close(index);
                        return;
                        // return Util.toast("无法连接到服务器，请稍候再试");
                    }
                    if (this.socket.readyState === WebSocket.OPEN) {
                        window.clearInterval(timer);
                        this.socket.addEventListener('message', this.onMessage);
                        this.socket.addEventListener('close', this.onClose);
                        this.socket.addEventListener('error', this.onError);

                        this.onOpen();

                        this.reconnect_times = 0;
                        return;
                    }
                    this.socket = new WebSocket(this.server_url);
                    this.socket.binaryType = 'arraybuffer';
                    this.reconnect_times++;
                } catch (e) {
                    this.trace(e);
                }
            }, 2000);
        },
        onError(e) {
            this.trace(e);
            // Util.toast("连接服务器出错");
        },

        trace(arg) {
            if (!DEBUG)
                return;
            let now = (window.performance.now() / 1000).toFixed(3);
            console.log(now + ': ', arg);
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
            this.socket.send(DataHelper.encode(Object.assign(defaults, {
                type,
                sender_id: this.user.id,
                receiver_id,
                mess,
            })));
        },
        receiveMessage(mess) {
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
                    displayName: '',
                    avatar: '',
                }
            });
        },

        login(e) {
            console.log(this.username, this.password);
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
            // 查询历史消息 数据通过回调通知
            let _query_time = this.contact_query_time.get(contact.id);
            // 精确到4位，和服务器保持一致，并去除边界的一条
            let query_time = _query_time > 0 ? _query_time - 0.0001 : (new Date()).getTime() / 1000;
            this.trace(_query_time, query_time);
            let type = (contact.id === '0') ? HISTORY_MESSAGE_COMMON : HISTORY_MESSAGE_PERSONAL;
            this.sendMessage(type, contact.id, query_time);
            this.pull_callback.set(contact.id, next);
        },
        send(message, next) {
            // 发送消息
            let receiver_id = message.toContactId;
            let type = (message.toContactId === '0') ? MESSAGE_COMMON : MESSAGE_PERSONAL;
            this.sendMessage(type, receiver_id, message.content);

            //执行到next消息会停止转圈，如果接口调用失败，可以修改消息的状态 next({status:'failed'});
            next();
        },
    }
}
</script>

