<template>
    <main>
        <lemon-imui :user="user" ref="im"
                    :hide-message-name="false"
                    @pull-messages="getHistory"
                    @message-click="messageClick"
                    @change-contact="changeContact"
                    @menu-avatar-click="changeAvatar"
                    @send="send">

            <!--搜索、创建群聊-->
            <template #sidebar-message-fixedtop="">
                <div class="flex space-between search-bar">
                    <input type="text" class="input-medium" placeholder="搜索" />
                    <button @click="addGroup">➕</button>
                </div>
            </template>

            <!--最近消息（可删除，默认无状态显示）-->
            <template #sidebar-message="contact">
                <lemon-badge
                    :count="contact.unread"
                    class="lemon-contact__avatar">
                    <lemon-avatar :size="40" :src="contact.avatar" />
                </lemon-badge>
                <div class="lemon-contact__inner">
                    <p class="lemon-contact__label">
                        <span class="online-status" v-if="contact.is_online"></span>
                        <span class="lemon-contact__name">{{contact.displayName}}</span>
                        <span class="lemon-contact__time">{{contact.lastSendTime ? timeFormat(contact.lastSendTime) : ""}} </span>
                    </p>
                    <p class="lemon-contact__content">
                        <span v-html="contact.lastContent"></span>
                    </p>
                </div>
            </template>

            <!--聊天窗口标题（可删除，默认无人数显示）-->
            <template #message-title="contact">
                <div class="flex space-between">
                    <span>{{contact.displayName}}<span v-if="contact.is_group"> ({{contact.id ? contact.members.size : contact.online_total}})</span></span>
                    <b @click="toggleDrawer(contact)" class="pointer user-select-none">···</b>
                </div>
            </template>

            <!--聊天窗口右侧栏（可删除，默认群成员显示）-->
            <template #drawer="contact">
                <div class="slot-group" v-if="contact.is_group">
                    <div class="slot-group-title">群通知</div>
                    <hr/>
                    <div class="slot-group-notice">公告内容</div>
                    <hr/>
                    <div class="slot-group-title">群成员</div>
                    <input class="slot-search" placeholder="搜索群成员"/>
                    <div class="slot-group-panel flex flex-wrap">
                        <lemon-contact
                            v-for="item of contact.members.values()"
                            :key="item.user_id"
                            :contact="item"
                            v-lemon-contextmenu.contact="group_menu">
                            <div class="slot-group-member">
                                <div class="slot-group-avatar">
                                    <img :src="item.avatar ? upload_url + item.avatar : default_avatar_url" alt="avatar" />
                                </div>
                                <div class="slot-group-name text-ellipsis">{{item.username}}</div>
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

        <!--WebRTC-->
        <modal name="rtc-modal" :clickToClose="false" :height="'auto'" :width="960" :scrollable="true" draggable="true">
            <div class="flex flex-wrap horizontal-center vertical-center">
                <template v-if="video_flag">
                    <div class="local-video">
                        <video autoplay muted :srcObject.prop="local_media"></video>
                        <div class="flex video-bar">
                            <div class="video-tag text-ellipsis">{{user.displayName}}</div>
                            <canvas class="voice-visualize-canvas" id="local-canvas" width="420" height="30" />
                        </div>
                    </div>
                    <div class="remote-video"
                         v-for="[index, remote_video] of remote_medias" :key="index">
                        <video autoplay :srcObject.prop="remote_video"></video>
                        <div class="flex video-bar">
                            <div class="video-tag text-ellipsis">{{remote_users.get(index).username}}</div>
                            <canvas class="voice-visualize-canvas" :id="'remote-canvas-' + index" width="420" height="30" />
                        </div>
                    </div>
                </template>
                <template v-else>
                    <div class="local-audio">
                        <audio autoplay muted :srcObject.prop="local_media" @canplay="setMute"></audio>
                        <div class="flex audio-bar">
                            <div>
                                <img :src="user.avatar" />
                                <div class="text-ellipsis text-center username">{{user.displayName}}</div>
                            </div>
                            <canvas class="voice-visualize-canvas" id="local-canvas" width="380" height="120" />
                        </div>
                    </div>
                    <div class="remote-audio"
                         v-for="[index, remote_audio] of remote_medias" :key="index">
                        <audio autoplay :srcObject.prop="remote_audio"></audio>
                        <div class="flex audio-bar">
                            <div>
                                <img :src="remote_users.get(index).avatar ? upload_url + remote_users.get(index).avatar : default_avatar_url" />
                                <div class="text-ellipsis text-center username">{{remote_users.get(index).username}}</div>
                            </div>
                            <canvas class="voice-visualize-canvas" :id="'remote-canvas-' + index" width="380" height="120" />
                        </div>
                    </div>
                </template>
            </div>
            <div class="flex horizontal-right vertical-center">
                <span>{{clock_text}}</span>
                <button @click="hangUp" class="flex vertical-center">
                    <span class="hang-up-button"></span>
                    <span>结束通话</span>
                </button>
            </div>
        </modal>
        <v-dialog />
    </main>
</template>

<script>
import './css/login.css';
import './css/main.css';
import 'weui-icon';

import data from './js/data.js';
import methods from './js/methods.js';
import emoji from './js/emoji.js';
import WebRTC from "./js/webrtc.js";
import Constant from "./js/constant.js";

export default {
    name: 'App',
    components: {},
    data,
    methods,
    mounted() {
        const {im} = this.$refs;
        this.im = im;

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

        // 初始化RTC
        this.rtc = new WebRTC();
        this.rtc.setCallbacks({
            onTrack: this.onTrack,
            onNegotiateReady: this.onNegotiateReady,
            onIceCandidate: this.onIceCandidate,
            onRemoteSteamClose: this.onRemoteSteamClose,
        });

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
                    return <i class="weui-icon-outlined-note"></i>;
                },
            },
            {
                name: "uploadMusic",
                title: "上传音乐",
                click: () => {
                    this.im.$refs.editor.selectFile(".mp3");
                },
                render: () => {
                    return <i class="weui-icon-outlined-music"></i>;
                },
            },
            {
                name: "videoChat",
                title: "视频聊天",
                click: () => {
                    this.$modal.show('rtc-modal');

                    this.rtc_room_id = "";
                    let contact = this.im.getCurrentContact();
                    let video = true;
                    this.video_flag = video;
                    this.rtc.setConstraints({ audio: true, video });
                    this.rtc.open().then((stream) => {
                        this.setLocalStream(stream);
                        this.rtc_running = true;
                        // 请求CREATE ROOM
                        let is_group = contact.is_group;
                        this.sendMessage(Constant.RTC_CREATE, contact.id, {
                            is_group,
                            video,
                        });
                    }).catch((e) => {
                        this.trace(e);
                        this.rtc_running = true;
                    });
                },
                render: () => {
                    return <i class="weui-icon-outlined-video-call"></i>;
                },
            },
            {
                name: "VoiceChat",
                title: "语音聊天",
                click: () => {
                    this.$modal.show('rtc-modal');

                    this.rtc_room_id = "";
                    let contact = this.im.getCurrentContact();
                    let video = false;
                    this.video_flag = video;
                    this.rtc.setConstraints({ audio: true, video });
                    this.rtc.open().then((stream) => {
                        this.setLocalStream(stream);
                        this.rtc_running = true;
                        // 请求CREATE ROOM
                        let is_group = contact.is_group;
                        this.sendMessage(Constant.RTC_CREATE, contact.id, {
                            is_group,
                            video,
                        });
                    }).catch((e) => {
                        this.trace(e);
                        this.rtc_running = true;
                    });
                },
                render: () => {
                    return <i class="weui-icon-outlined-mike"></i>;
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
}
</script>