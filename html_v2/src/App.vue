<template>
    <main>
        <lemon-imui :user="user" ref="im"
                    :hide-message-name="false"
                    @pull-messages="getHistory"
                    @message-click="messageClick"
                    @change-contact="changeContact"
                    @menu-avatar-click="changeAvatar"
                    @send="send">

            <template #sidebar-message-fixedtop="">
                <div class="flex space-between search-bar">
                    <input type="text" class="input-medium" placeholder="搜索" />
                    <button @click="addGroup">➕</button>
                </div>
            </template>
            <!--聊天窗口标题-->
            <template #message-title="contact">
                <div class="flex space-between">
                    <span>{{contact.displayName}}<span v-if="contact.is_group"> ({{contact.id ? contact.members.size : contact.online_total}})</span></span>
                    <b @click="toggleDrawer(contact)" class="pointer user-select-none">···</b>
                </div>
            </template>
            <!--聊天窗口右侧栏-->
            <template #drawer="contact">
                <div class="slot-group" v-if="contact.is_group">
                    <div class="slot-group-title">群通知</div>
                    <hr/>
                    <div class="slot-group-notice">公告内容</div>
                    <hr/>
                    <div class="slot-group-title">群成员</div>
                    <input class="slot-search" placeholder="搜索群成员"/>
                    <div class="slot-group-panel flex">
                        <lemon-contact
                            v-for="item of contact.members.values()"
                            :key="item.user_id"
                            :contact="item"
                            v-lemon-contextmenu.contact="group_menu">
                            <div class="slot-group-member">
                                <div class="slot-group-avatar">
                                    <img :src="item.avatar ? upload_url + item.avatar : default_avatar_url" alt="avatar" />
                                </div>
                                <div class="slot-group-name">{{item.username}}</div>
                            </div>
                        </lemon-contact>
                    </div>
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

        <!--图片预览-->
        <viewer :images="images"></viewer>

        <!--头像裁剪-->
        <template>
            <image-crop
                field="img"
                @crop-success="cropSuccess"
                v-model="image_crop.show"
                :width="300"
                :height="300"
                img-format="png"></image-crop>
        </template>

        <!--创建群聊-->
        <modal name="group-modal" :clickToClose="true" :height="500" :width="666">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">创建群聊</h3>
                    <span>群聊名称：</span>
                    <input v-model="group_name" class="input-medium group-name" placeholder="请输入群聊名称" />
                </div>
                <div class="flex space-between vertical-center modal-body">
                    <div class="group-select">
                        <div>联系人</div>
                        <select multiple v-model="left_options" size="10">
                            <option v-for="item of group_available_users.values()" :key="item.user_id" :value="item.user_id">{{item.username}}</option>
                        </select>
                    </div>
                    <div class="group-select-middle">
                        <button @click="moveToLeft">⬅️</button>
                        <button @click="moveToRight">➡️</button>
                    </div>
                    <div class="group-select">
                        <div>已选</div>
                        <select multiple v-model="right_options" size="10">
                            <option v-for="item of group_chosen_users.values()" :key="item.user_id" :value="item.user_id">{{item.username}}</option>
                        </select>
                    </div>
                </div>
                <div class="flex horizontal-right modal-footer">
                    <button @click="groupCancel">取消</button>
                    <button @click="groupSubmit">确定</button>
                </div>
            </div>
        </modal>
    </main>
</template>

<script>
import './css/login.css';
import './css/main.css';

import {DataHelper, generateUUID} from './js/util.js';
import emoji from './js/emoji.js';

const DEBUG = true;
const DEFAULT_AVATAR = "/static/chat.png";
const MAX_LIMITS = 100; //断线最大重连次数
const COOKIE_EXPIRE_DAYS = 7; //cookie过期天数
const MAX_IMAGE_SIZE = 1024 * 1024 * 4; //最大上传图片尺寸
const MAX_MUSIC_SIZE = 1024 * 1024 * 16; //最大音乐尺寸
const MAX_FILE_SIZE = 1024 * 1024 * 50; //最大音乐尺寸

const MESSAGE_COMMON = 100;//公共消息
const MESSAGE_SELF = 101;//本人消息
const MESSAGE_OTHER = 102;//他人消息
const MESSAGE_PERSONAL = 103;//私信

const USER_ONLINE = 200;//用户上线
const USER_QUIT = 201;//用户退出
const USER_LIST = 202;//用户列表
const USER_QUERY = 203; //用户查询
const USER_REGISTER = 204;//用户注册
const USER_LOGIN = 205; // 用户登录
const USER_DISABLED = 206;//用户禁用
const USER_DOWNLINE = 207;//用户下线
const USER_INCORRECT = 208;//用户名/密码错误
const USER_REMOVE = 209;//用户移除
const USER_AVATAR_UPLOAD = 210;//上传头像
const USER_AVATAR_SUCCESS = 211;//上传成功
const USER_AVATAR_FAIL = 212;//上传失败
const USER_ONLINE_TOTAL = 213; // 用户在线数量

const IMAGE_COMMON = 300;//公共图片
const IMAGE_SELF = 301;//本人图片
const IMAGE_OTHER = 302;//他人图片
const IMAGE_PERSONAL = 303;//私信图片

const MUSIC_COMMON = 500; //公共音乐
const MUSIC_SELF = 501; //本人音乐
const MUSIC_OTHER = 502; //他人音乐
const MUSIC_PERSONAL = 503; //私信音乐

const FILE_COMMON = 1000;
const FILE_SELF = 1001;
const FILE_OTHER = 1002;
const FILE_PERSONAL = 1003;

// 群聊
const GROUP_CREATE = 1100;
const GROUP_QUERY_LIST = 1101;
const GROUP_QUERY_MEMBER = 1102;
const GROUP_QUERY_INFO = 1103;
// const GROUP_JOIN = 1104;
// const GROUP_EXIT = 1105;
// const GROUP_DEL = 1106;

const HISTORY_MESSAGE_COMMON = 800; //历史公共消息
const HISTORY_MESSAGE_PERSONAL = 801; //历史个人消息
const FILE_UPLOAD_SUCCESS = 903; // 文件上传成功

export default {
    name: 'App',
    components: {},
    data() {
        return {
            im: null,
            server_url: process.env.VUE_APP_SERVER_URL,
            upload_url: process.env.VUE_APP_UPLOAD_URL,
            default_avatar_url: DEFAULT_AVATAR,
            username: "",
            password: "",
            user: {id: 0, displayName: '', avatar: DEFAULT_AVATAR, is_active: 1},
            socket: null,
            reconnect_times: 0,
            login_mess: "",
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
            group_name: "",
            group_available_users: new Map(),
            group_chosen_users: new Map(),
            left_options: [],
            right_options: [],
            groups: new Map(),
            // 成员菜单
            group_menu: [
                {
                    text: "发消息",
                    visible: instance => {
                        return instance.contact.user_id !== this.user.id;
                    },
                    click: (e, instance, hide) => {
                        const { IMUI, contact } = instance;
                        IMUI.$parent.addPersonalContact(contact, "临时会话");
                        IMUI.changeContact(contact.user_id);
                        hide();
                        IMUI.closeDrawer();
                    },
                },
            ],
        };
    },
    watch: {
    },
    mounted() {
        const {im} = this.$refs;
        this.im = im;
        generateUUID();

        // 连接服务器，监听事件
        this.socket = new WebSocket(this.server_url);
        // this.socket.binaryType = 'arraybuffer'; //设为二进制的原始缓冲区

        this.socket.addEventListener('open', this.onOpen);
        this.socket.addEventListener('message', this.onMessage);
        this.socket.addEventListener('close', this.onClose);
        this.socket.addEventListener('error', this.onError);

        // 菜单
        this.im.initMenus([{name: "messages"}, {name: "contacts"}]);

        // 初始化表情包
        this.im.initEmoji(emoji);

        // 初始化工具栏
        this.im.initEditorTools([
            {
                name: 'emoji'
            },
            {
                name: 'uploadImage'
            },
            {
                name: 'uploadDoc',
                title: "上传文档",
                click: () => {
                    this.im.$refs.editor.selectFile(".doc,.docx,.xls,.xlsx");
                },
                render: () => {
                    return <span>📄</span>;
                },
            },
            {
                name: "uploadMusic",
                title: "上传音乐",
                click: () => {
                    this.im.$refs.editor.selectFile(".mp3");
                },
                render: () => {
                    return <span>🎵</span>;
                },
            },
        ]);

        // 自定义消息-音乐
        this.im.setLastContentRender('music', (message) => {
            return <span>[音乐]{message.fileName}</span>;
        });

        // 大厅
        this.addGroupContact({
            id: '0',
            name: '大厅',
            avatar: '',
        }, "", this.online_users);
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
            mess.timestamp = mess.timestamp * 1000;
            this.trace("receive", mess);
            this.$modal.hide('disconnect-modal');
            switch (mess.type) {
                // 用户状态
                // myself @other TODO 修改消息样式
                case MESSAGE_SELF:
                case IMAGE_SELF:
                case FILE_SELF:
                case MUSIC_SELF:
                    break;
                case USER_REGISTER: // 需要注册
                    this.$modal.show('login-modal');
                    break;
                case USER_INCORRECT: // 登录出错
                    this.login_mess = mess.mess;
                    break;
                case USER_LOGIN: // 已经登录
                {
                    let user = mess.user;
                    // 隐藏modal
                    this.login_mess = "登录成功";
                    this.$modal.hide('login-modal');
                    // 更新this.user
                    this.user.id = user.user_id;
                    this.user.displayName = user.username;
                    this.user.avatar = user.avatar ? this.upload_url + user.avatar : DEFAULT_AVATAR;
                    // 刷新cookie 在线用户列表
                    this.setCookie("user", JSON.stringify(this.user));
                    this.online_users.set(user.user_id, user);
                    this.$notify({
                        group: 'tip',
                        text: `${this.user.displayName}，欢迎回来`,
                        type: 'success',
                    });

                    // 查询在线人数
                    this.sendMessage(USER_ONLINE_TOTAL);

                    // 查询群组列表
                    this.sendMessage(GROUP_QUERY_LIST);

                    // 查询在线用户
                    this.sendMessage(USER_LIST);
                    break;
                }
                case USER_LIST: // 在线用户
                {
                    let users = mess.users;
                    for (let user of users) {
                        this.online_users.set(user.user_id, user);
                    }
                    break;
                }
                case USER_ONLINE://欢迎消息
                {
                    let user = mess.user;
                    // 联系人
                    this.im.appendMessage({
                        id: DataHelper.buildTraceId(),
                        status: "succeed",
                        type: "event",
                        sendTime: mess.timestamp,
                        content: `用户 ${user.username} 进入聊天室`,
                        toContactId: "0",
                    });
                    if (this.user.id !== user.user_id) {
                        ++this.online_total;
                        this.online_users.set(user.user_id, user);
                        this.updateContact("0", {online_total:this.online_total, unread: "+1"});
                    }
                    break;
                }
                case USER_ONLINE_TOTAL:
                    this.online_total = mess.mess; // 置为实际数量
                    this.updateContact("0", {online_total:this.online_total});
                    break;
                case USER_QUIT:
                {
                    let user = mess.user;
                    if (this.user.id !== user.user_id) {
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
                        this.updateContact("0", {online_total:this.online_total, unread: "+1"});
                    }
                    break;
                }
                case USER_QUERY:
                {
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
                case USER_DISABLED: //禁用
                {
                    this.user.is_active = 0;
                    this.disconnect_mess = this.disconnect_mess = mess.mess;
                    this.$modal.show('disconnect-modal');
                    break;
                }

                case USER_AVATAR_SUCCESS: {
                    this.user.avatar = this.upload_url + mess.mess;
                    // 刷新cookie
                    this.setCookie("user", JSON.stringify(this.user));
                    // 刷新在线用户
                    let user = this.online_users.get(this.user.id);
                    user.avatar = mess.mess;
                    this.online_users.set(this.user.id, user);
                    this.$notify({
                        group: 'tip',
                        text: '上传头像成功',
                        type: 'success',
                    });
                    break;
                }
                case USER_AVATAR_FAIL: {
                    this.$notify({
                        group: 'tip',
                        text: '上传头像失败',
                        type: 'warn',
                    });
                    break;
                }

                // 公共、个人消息
                case MESSAGE_COMMON: //公共消息
                case MESSAGE_OTHER: //other @me
                case IMAGE_COMMON:
                case IMAGE_OTHER:
                case MUSIC_COMMON:
                case MUSIC_OTHER:
                case FILE_COMMON:
                case FILE_OTHER:
                {
                    let sender_id = mess.sender_id;
                    if (sender_id === this.user.id) return; // 自己发的，忽略，避免重复

                    // 查询收信人（群组）
                    let receiver_id = mess.receiver_id;
                    let is_group = receiver_id && (receiver_id !== this.user.id);
                    if (is_group) {
                        let contact = this.im.findContact(receiver_id);
                        if (!contact) {
                            this.sendMessage(GROUP_QUERY_INFO, '0', receiver_id);
                            let promise = new Promise((resolve) => {
                                this.query_group_next.set(receiver_id, resolve);
                            });
                            promise.then((group) => {
                                this.query_group_next.delete(group.id);
                                // 添加联系人
                                this.addGroupContact(group);
                            });
                        }
                    }

                    // 查询发信人
                    let sender = this.getUser(sender_id);
                    if (sender) {
                        this.addPersonalContact(sender);
                        this.receiveMessage(mess, sender);
                    } else {
                        // 若找不到用户，则查询异步处理
                        this.getUserAsync(sender_id, (user) => {
                            this.addPersonalContact(user);
                            this.receiveMessage(mess, user);
                        });
                    }
                    break;
                }

                case HISTORY_MESSAGE_COMMON:
                case HISTORY_MESSAGE_PERSONAL:
                {
                    let contact_id = mess.receiver_id;
                    let resolve = this.pull_next.get(contact_id);
                    if (resolve) {
                        resolve(mess);
                    }
                    break;
                }

                // 文件上传
                case FILE_UPLOAD_SUCCESS:
                {
                    // hash, path, size
                    let resolve = this.upload_next.get(mess.mess.hash);
                    resolve(mess.mess);
                    break;
                }

                // 群聊
                case GROUP_CREATE:
                {
                    let group = mess.mess;
                    this.groups.set(group.id, group);
                    // 更新联系人
                    this.addGroupContact(group);
                    // 创建群聊通知
                    let admin_id = group.admin_id;
                    // 管理员昵称可能变化，这里查询最新昵称
                    let admin = this.getUser(admin_id);
                    if (admin) {
                        this.receiveMessage(mess, admin);
                    } else {
                        this.getUserAsync(admin_id, (user) => {
                            this.receiveMessage(mess, user);
                        });
                    }
                    break;
                }
                case GROUP_QUERY_LIST:
                {
                    let groups = mess.mess;
                    for (let group_id of Object.keys(groups)) {
                        let group = groups[group_id];
                        group.id = group_id;
                        this.groups.set(group_id, group);
                        this.addGroupContact(group);
                    }
                    break;
                }
                case GROUP_QUERY_MEMBER:
                {
                    let data = mess.mess;
                    let group_id = data.group_id;
                    let members = data.members;
                    let resolve = this.query_member_next.get(group_id);
                    if (resolve) {
                        resolve(members);
                    }
                    break;
                }
                case GROUP_QUERY_INFO:
                {
                    let data = mess.mess;
                    let group_id = data.id;
                    let resolve = this.query_group_next.get(group_id);
                    if (resolve) {
                        resolve(data);
                    }
                    break;
                }

                default:
                {
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
            this.$notify({
                group: 'tip',
                text: '连接服务器出错',
                type: 'error',
            });
        },

        // 工具方法
        trace() {
            if (!DEBUG)
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
            exp.setTime(exp.getTime() + COOKIE_EXPIRE_DAYS * 24 * 60 * 60 * 1000);
            document.cookie = name + "=" + escape(value) + ";expires=" + exp.toGMTString();
        },
        sendMessage(type, receiver_id = 0, mess = "", id = "", trace_id = "") {
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
            if (id) data.id = id;
            this.trace("send", data);
            this.socket.send(DataHelper.encode(data));
        },
        parseMessage(mess, sender = null) {
            let type, content, fileSize, fileName;
            switch (mess.type) {
                case MESSAGE_COMMON:
                case MESSAGE_SELF:
                case MESSAGE_OTHER:
                {
                    type = "text";
                    content = mess.mess;
                    fileSize = 0;
                    fileName = "";
                    break;
                }
                case IMAGE_COMMON:
                case IMAGE_SELF:
                case IMAGE_OTHER:
                {
                    type = "image";
                    content = this.upload_url + mess.mess.path;
                    fileSize = mess.mess.size;
                    fileName = mess.mess.name;
                    break;
                }
                case FILE_COMMON:
                case FILE_SELF:
                case FILE_OTHER:
                {
                    type = "file";
                    content = this.upload_url + mess.mess.path;
                    fileSize = mess.mess.size;
                    fileName = mess.mess.name;
                    break;
                }
                case MUSIC_COMMON:
                case MUSIC_SELF:
                case MUSIC_OTHER:
                {
                    type = "music";
                    content = this.upload_url + mess.mess.path;
                    fileSize = mess.mess.size;
                    fileName = mess.mess.name;
                    break;
                }
                case GROUP_CREATE:
                {
                    type = "event";
                    let admin_name = (sender.user_id === this.user.id) ? "你" : sender.username;
                    content = admin_name + "创建了群聊";
                    fileSize = 0;
                    fileName = "";
                    break;
                }
            }
            let toContactId;
            switch (mess.type) {
                case MESSAGE_OTHER:
                case IMAGE_OTHER:
                case FILE_OTHER:
                case MUSIC_OTHER:
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
                id: mess.id,
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
                    avatar: sender && sender.avatar ? this.upload_url + sender.avatar : DEFAULT_AVATAR,
                }
            };
        },
        receiveMessage(mess, sender = null) {
            let parsed = this.parseMessage(mess, sender);
            this.trace('parsed', parsed);
            this.im.appendMessage(parsed);
            this.im.updateContact({unread: "+1"});
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
                avatar: user.avatar ? this.upload_url + user.avatar : DEFAULT_AVATAR,
                lastContent: lastMessage,
                index: "Personal",

                // 新加字段
                is_group: false,
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
            this.sendMessage(USER_QUERY, user_id, user_id);
            let promise = new Promise((resolve) => {
                this.query_next.set(user_id, resolve);
            });
            promise.then((user) => {
                this.query_next.delete(user.user_id);
                // 添加联系人
                this.addPersonalContact(user);
                return user;
            }).then(callback);
        },
        addGroupContact(group, lastMessage = "", members = new Map()) {
            let data = {
                id: group.id,
                displayName: group.name,
                avatar: group.avatar ? this.upload_url + group.avatar : DEFAULT_AVATAR,
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
            // 登录/注册
            this.socket.send(DataHelper.encode({
                type: USER_REGISTER,
                username: this.username.substr(0, 30),
                password: this.password ? this.password : '123456',
            }));
            return false;
        },
        getHistory(contact, next) {
            let query_time = (contact.query_time > 0) ? contact.query_time : ((new Date()).getTime() + performance.now()) / 1000;
            let type = contact.is_group ? HISTORY_MESSAGE_COMMON : HISTORY_MESSAGE_PERSONAL;
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
                        this.sendMessage(USER_QUERY, user_id, "", "", user_id);
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
                    "image": [IMAGE_COMMON, IMAGE_PERSONAL],
                    "file": [FILE_COMMON, FILE_PERSONAL],
                    "text": [MESSAGE_COMMON, MESSAGE_PERSONAL],
                    "music": [MUSIC_COMMON, MUSIC_PERSONAL],
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
                        limit_size = MAX_IMAGE_SIZE;
                        break;
                    case "music":
                        limit_size = MAX_MUSIC_SIZE;
                        break;
                    case "file":
                        limit_size = MAX_FILE_SIZE;
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
                height: this.$el.clientHeight - 33,
            });
        },

        // 消息点击
        messageClick(e, key, message) {
            let contact_id = message.toContactId;
            // 标记为已读
            this.updateContact(contact_id, {unread: 0});

            this.trace(e, key, message);
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
                this.sendMessage(GROUP_QUERY_MEMBER, 0, group_id);
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
            let images = document.querySelectorAll(".lemon-message__content img");
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
                    this.sendMessage(USER_AVATAR_UPLOAD, '0', {path, size});
                }).catch((e) => {
                    this.trace(e);
                });
        },

        // 创建群聊
        addGroup() {
            this.group_name = "";
            this.group_available_users = new Map(this.online_users);
            this.group_available_users.delete(this.user.id);
            this.group_chosen_users.clear();
            this.$modal.show('group-modal');
        },
        moveToLeft() {
            this.right_options.forEach((user_id) => {
                let user = this.group_chosen_users.get(user_id);
                this.group_available_users.set(user_id, user);
                this.group_chosen_users.delete(user_id);
            });
            this.$forceUpdate();
        },
        moveToRight() {
            this.left_options.forEach((user_id) => {
                let user = this.group_available_users.get(user_id);
                this.group_chosen_users.set(user_id, user);
                this.group_available_users.delete(user_id);
            });
            this.$forceUpdate();
        },
        groupCancel() {
            this.$modal.hide('group-modal');
        },
        groupSubmit() {
            if (this.group_name === "") {
                return this.$notify({
                    group: 'tip',
                    text: '请输入群聊名称',
                    type: 'error',
                });
            }
            let chosen_num = this.group_chosen_users.size;
            if (chosen_num < 2) {
                return this.$notify({
                    group: 'tip',
                    text: '群聊人数不能少于2人',
                    type: 'error',
                });
            }

            // 请求
            let mess = {
                name: this.group_name,
                members: [...this.group_chosen_users.keys()],
            };
            this.sendMessage(GROUP_CREATE, 0, mess);
            this.$modal.hide('group-modal');
        },
    }
}
</script>