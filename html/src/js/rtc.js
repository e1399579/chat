import IMessage from "./imessage.js";
import Constant from "./constant.js";
import IProcessor from "./iprocessor.js";
import VoiceVisualize from "./voice_visualize.js";

export class RTCMessage extends IMessage {
    process(vm, mess) {
        switch (mess.type) {
            // RTC
            case Constant.RTC_CREATE:
            {
                let data = mess.mess;
                let sender_id = mess.sender_id;
                let receiver_id = mess.receiver_id;
                if (sender_id === vm.user.id) {
                    // 本人创建，仅更新room_id
                    vm.rtc_room_id = data.room_id;
                    vm.rtc_receiver_id = receiver_id;
                    return;
                } else if (vm.rtc_running) {
                    // 已经在聊天，忽略
                    let message = vm.user.displayName + " 正在聊天中";
                    vm.sendMessage(Constant.RTC_MESSAGE, sender_id, {
                        type: "deny",
                        message,
                    });
                    return;
                }

                // 弹窗提示
                let video = data.video;
                let is_group = data.is_group;
                let title = '<div class="flex vertical-center">';
                title += '<span class="call-button"></span>';
                title += '<span>';
                title += is_group ? '多人聊天' : '单人聊天';
                title += '</span>';
                title += '</div>';
                let tag = video ? '视频': '语音';
                vm.getUserAsync(sender_id, (user) => {
                    vm.$modal.show('dialog', {
                        title: title,
                        text: `<b>${user.username}</b> 邀请您${tag}通话，是否接听？`,
                        buttons: [
                            {
                                title: '拒绝',
                                handler: () => {
                                    vm.$modal.hide('dialog');
                                    vm.sendMessage(Constant.RTC_MESSAGE, sender_id, {
                                        type: "deny",
                                        message: vm.user.displayName + " 拒绝了您的通话请求",
                                    });
                                }
                            },
                            {
                                title: '接听',
                                handler: () => {
                                    vm.$modal.hide('dialog');
                                    // 接受，显示弹窗
                                    vm.$modal.show('rtc-modal');
                                    vm.rtc_room_id = data.room_id; // 更新room_id
                                    vm.rtc_receiver_id = receiver_id;
                                    vm.video_flag = video;
                                    vm.rtc.setConstraints({ audio: true, video });
                                    // 打开摄像头，创建连接，添加轨道，设置local，稍后(onNegotiateReady)发送offer
                                    vm.rtc.open().then((stream) => {
                                        vm.setLocalStream(stream);
                                        vm.rtc_running = true;
                                        let key = vm.rtc.create();
                                        vm.remote_medias.set(key, null);
                                        vm.rtc.addTrack(key);
                                        // 创建key<=>sender关联，后面要用
                                        vm.associateKeyWithSender(key, sender_id);

                                        // 请求加入房间
                                        vm.sendMessage(Constant.RTC_JOIN, '0', data);
                                    }).catch((e) => {
                                        vm.trace(e);
                                        vm.rtc_running = true;
                                    });
                                }
                            },
                        ]
                    });
                });
                break;
            }
            case Constant.RTC_JOIN:
            {
                let data = mess.mess;
                let sender_id = mess.sender_id;
                vm.rtc_room_id = data.room_id;
                if (sender_id === vm.user.id) return;
                // 创建连接，添加轨道，设置local，稍后(onNegotiateReady)发送offer
                let key = vm.rtc.create();
                vm.remote_medias.set(key, null);
                vm.rtc.addTrack(key);
                // 创建key<=>sender关联，后面要用
                vm.associateKeyWithSender(key, sender_id);
                break;
            }
            case Constant.RTC_MESSAGE:
            {
                let sender_id = mess.sender_id;
                let data = mess.mess;
                let type = data.type;
                switch (type) {
                    case "offer":
                    {
                        // 创建key<=>sender关联，后面要用
                        let key = vm.rtc.getPeerConnectionLastId();
                        vm.associateKeyWithSender(key, sender_id);
                        // 创建连接，设置remote，打开摄像头，添加轨道，发送answer
                        let description = data.description;
                        vm.rtc.handleVideoOfferMsg(description).then(({description}) => {
                            vm.sendAnswer(sender_id, description);
                        });
                        break;
                    }
                    case "answer":
                    {
                        // 设置remote
                        let key = vm.rtc_sender_key.get(sender_id);
                        vm.rtc.handleVideoAnswerMsg(key, data.description);
                        break;
                    }
                    case "new-ice-candidate":
                    {
                        let candidate = data.candidate;
                        if (vm.rtc_sender_key.has(sender_id)) {
                            let key = vm.rtc_sender_key.get(sender_id);
                            vm.rtc.handleNewICECandidateMsg(key, candidate);
                        } else {
                            // 还未建立连接（作为callee时出现），先保存起来，一会再处理
                            vm.trace('record candidate', sender_id, candidate);
                            let candidates;
                            if (vm.candidates.has(sender_id)) {
                                candidates = vm.candidates.get(sender_id);
                                candidates.push(candidate);
                            } else {
                                candidates = [candidate];
                            }
                            vm.candidates.set(sender_id, candidates);
                        }
                        break;
                    }
                    case "deny":
                    {
                        let message = data.message;
                        vm.$notify({
                            group: 'tip',
                            text: message,
                            type: 'error',
                        });
                        break;
                    }
                }
                break;
            }
            case Constant.RTC_CLOSE:
            case Constant.RTC_OFFLINE:
            {
                // 关闭远端video
                let sender_id = mess.sender_id;
                if (vm.rtc_sender_key.has(sender_id)) {
                    let key = vm.rtc_sender_key.get(sender_id);
                    vm.rtc.close(key);
                }
                break;
            }
            case Constant.RTC_EXIT:
            {
                let sender_id = mess.sender_id;
                if (sender_id === vm.user.id) return;
                let data = mess.mess;
                if (vm.rtc_running && (data.room_id === vm.rtc_room_id)) {
                    // 已接听，关闭聊天窗口
                    vm.$notify({
                        group: 'tip',
                        text: '聊天已经结束',
                        type: 'warn',
                    });
                    vm.hangUp(false);
                } else {
                    // 未接听，关闭弹窗提示
                    vm.$modal.hide('dialog');
                }
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

export class RTCProcessor extends IProcessor {
    getData() {
        return {
            rtc: null,
            video_flag: true,
            local_media: null,
            remote_medias: new Map(),
            remote_users: new Map(),
            voice_visualizes: new Map(),
            clock_text: "",
            clock_timer: 0,
            candidates: new Map(),
            rtc_key_sender: new Map(),
            rtc_sender_key: new Map(),
            rtc_room_id: "",
            rtc_receiver_id: "",
            rtc_running: false,
            rtc_minimize: false,
        };
    }

    getMethods() {
        return {
            // WebRTC
            hangUp(notify = true) {
                this.$modal.hide('rtc-modal');
                this.rtc_running = false;
                this.rtc_key_sender.clear();
                this.rtc_sender_key.clear();
                this.remote_users.clear();
                this.rtc.closeAll();
                this.closeLocalStream();
                this.clockStop();
                this.closeVisualize();

                // 通知其他人
                if (notify) {
                    let contact = this.im.findContact(this.rtc_receiver_id);
                    this.sendMessage(Constant.RTC_CLOSE, this.rtc_receiver_id, {
                        is_group: contact.is_group,
                        room_id: this.rtc_room_id,
                    });
                }

                this.rtc_room_id = "";
                this.rtc_receiver_id = "";
            },
            minimize() {
                this.rtc_minimize = true;
            },
            maximize() {
                this.rtc_minimize = false;
            },
            setMute(e) {
                // muted prop not working, used js
                e.target.muted = true;
            },
            clockStart() {
                if (this.clock_timer) return;
                let begin = Date.now();
                this.clock_timer = setInterval(() => {
                    let diff = Date.now() - begin;
                    let date = new Date(diff);
                    this.clock_text = date.formatUTC('H:i:s');
                }, 1000);
            },
            clockStop() {
                clearInterval(this.clock_timer);
                this.clock_timer = 0;
                this.clock_text = "";
            },
            closeVisualize() {
                for (let visualize of this.voice_visualizes.values()) {
                    visualize.close();
                }
                this.voice_visualizes.clear();
            },
            associateKeyWithSender(key, sender_id) {
                this.rtc_key_sender.set(key, sender_id);
                this.rtc_sender_key.set(sender_id, key);
                this.getUserAsync(sender_id, (user) => {
                    this.remote_users.set(key, user);
                });
            },
            setLocalStream(stream) {
                this.trace('set local stream', ...arguments);
                this.local_media = stream;
                let canvas = document.querySelector("#local-canvas");
                let visualize = new VoiceVisualize(stream);
                visualize.visualize(canvas);
                this.voice_visualizes.set('local', visualize);
            },
            closeLocalStream() {
                this.trace('close local stream');
                if (this.local_media) {
                    this.local_media.getTracks().forEach((track) => track.stop());
                    this.local_media = null;
                    this.candidates.clear();
                }
            },
            sendAnswer(sender_id, description) {
                this.trace('send answer', ...arguments);
                // 发送answer到远端
                this.sendMessage(Constant.RTC_MESSAGE, sender_id, {
                    type: "answer",
                    description,
                });
            },
            onNegotiateReady(key, description) {
                this.trace('negotiate ready', ...arguments);
                // 发送offer到远端
                let sender_id = this.rtc_key_sender.get(key);
                this.sendMessage(Constant.RTC_MESSAGE, sender_id, {
                    type: "offer",
                    description,
                });
            },
            onIceCandidate(key, candidate) {
                this.trace('candidate', ...arguments);
                if (candidate === null) return;
                let sender_id = this.rtc_key_sender.get(key);
                this.trace('ice', sender_id);
                this.sendMessage(Constant.RTC_MESSAGE, sender_id, {
                    type: "new-ice-candidate",
                    candidate,
                });
                // 若有Interactive Connectivity Establishment candidates，则处理
                if (this.candidates.has(sender_id)) {
                    let candidates = this.candidates.get(sender_id);
                    this.trace('handle candidates', candidates);
                    for (let candidate of candidates) {
                        this.rtc.handleNewICECandidateMsg(key, candidate);
                    }
                    this.candidates.set(sender_id, []);
                }
            },
            onTrack(key, streams) {
                this.trace('track', ...arguments);
                if (streams[0].active) {
                    this.remote_medias.set(key, streams[0]);
                    this.$forceUpdate();
                    this.$nextTick(() => {
                        let canvas = document.querySelector("#remote-canvas-" + key);
                        if (this.voice_visualizes.has(key)) {
                            this.voice_visualizes.get(key).close();
                            this.voice_visualizes.delete(key);
                        }
                        let visualize = new VoiceVisualize(streams[0]);
                        visualize.visualize(canvas);
                        this.voice_visualizes.set(key, visualize);
                    });
                    this.clockStart();
                }
            },
            onRemoteSteamClose(key) {
                this.trace('remote stream close', ...arguments);
                if (this.remote_medias.has(key)) {
                    this.remote_medias.get(key) && this.remote_medias.get(key).getTracks().forEach((track) => track.stop());
                    this.remote_medias.delete(key);
                    this.$forceUpdate();
                }
            },
        };
    }
}