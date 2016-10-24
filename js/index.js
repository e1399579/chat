var H, W;
function autoSize() {
    H = Math.min(window.innerHeight, window.screen.height) - 80;
    W = Math.min(window.innerWidth, window.screen.width);
    $("body").width(W);
    $("#room").height(H);
}
autoSize();
window.addEventListener("resize", autoSize);

var audio = document.createElement("audio");
audio.src = "./media/notification.ogg";
audio.volume = 1;
var music = document.createElement("audio");
var receiver_id = 0; //接收者ID
var role_id = 0; //当前用户角色ID
var user = JSON.parse(getCookie('user')); //当前用户
var is_active = 1;
const DEBUG = false;
const MAX_LENGTH = 10000; //最大聊天字数
const MAX_IMAGE = 1024 * 1024 * 2; //最大上传图片尺寸
const MAX_UPLOAD = 5; //每次最多上传图片
const COMPRESS_PERCENT = 0.3; //截图压缩比例
const MAX_MUSIC_SIZE = 1024 * 1024 * 8; //最大音乐尺寸
const MAX_LIMITS = 100; //断线最大重连次数
const MATCH_URL = '((https?|ftp|file):\/\/[-A-Za-z0-9+&@#/%?=~_|!:,.;]+[-A-Za-z0-9+&@#/%=~_|])'; //匹配URL
var href = window.location.href; //当前的域名
var end = href.lastIndexOf('/');
href = (end == -1) ? href+'/' : href.substring(0, end) + '/'; //删除index.html?之类的字符
var port = 8080;
var protocol = window.location.protocol == 'https:' ? 'wss://' : 'ws://';
var url = protocol + window.location.host + ':' + port;
var ws = new WebSocket(url);

const COMMON = 0;//公共消息
const WELCOME = 1;//欢迎消息
const QUIT = -1;//退出消息
const SELF = 2;//本人消息
const OTHER = 3;//他人消息
const PERSONAL = 4;//私信
const ONLINE = 10;//在线用户
const REGISTER = 99;//用户注册
const LOGIN = 100;//用户登录
const ERROR = -2;//错误消息
const WARNING = -3;//警告消息
const REMOVE = -100;//移除用户
const SYSTEM = 11;//系统消息
const FORBIDDEN = -99;//禁用
const DOWNLINE = -10;//下线
const INCORRECT = -4;//用户名/密码错误
const COMMON_IMAGE = 20;//公共图片
const SELF_IMAGE = 22;//本人图片
const OTHER_IMAGE = 23;//他人图片
const PERSONAL_IMAGE = 24;//私信
const COMMON_EMOTION = 30;//公共表情
const SELF_EMOTION = 32;//本人表情
const OTHER_EMOTION = 33;//他人图片
const PERSONAL_EMOTION = 34;//私信表情
const AVATAR_UPLOAD = 42;//上传头像
const AVATAR_SUCCESS = 43;//上传成功
const AVATAR_FAIL = -43;//上传失败
const HISTORY_COMMON_MESSAGE = 50; //历史公共消息
const HISTORY_PERSONAL_MESSAGE = 51; //历史个人消息
const COMMON_MUSIC = 60; //公共音乐
const SELF_MUSIC = 62; //本人音乐
const OTHER_MUSIC = 63; //他人音乐
const PERSONAL_MUSIC = 64; //私信音乐
const PERSONAL_VIDEO_REQUEST = 70; //私信视频请求
const PERSONAL_VIDEO_OFFLINE = -70; //离线
const PERSONAL_VIDEO_ALLOW = 71; //请求通过
const PERSONAL_VIDEO_DENY = -71; //请求拒绝
const PERSONAL_VIDEO_OPEN = 72; //打开摄像头
const PERSONAL_VIDEO_CLOSE = -72; //关闭摄像头
const PERSONAL_VIDEO_END = 79; //传输结束

const PERSONAL_VIDEO_OFFER_DESC = 80;
const PERSONAL_VIDEO_ANSWER_DESC = 81;
const PERSONAL_VIDEO_CANDIDATE = 82;

const COMMON_VIDEO_REQUEST = 90;
const COMMON_VIDEO_NOTIFY = 91;
const PERSONAL_VIDEO_NOTIFY = 92;

//取得cookie
function getCookie(name) {
    var nameEQ = name + "=";
    var ca = document.cookie.split(';');    //把cookie分割成组
    for (var i = 0; i < ca.length; i++) {
        var c = ca[i];                      //取得字符串
        while (c.charAt(0) == ' ') {          //判断一下字符串有没有前导空格
            c = c.substring(1, c.length);      //有的话，从第二位开始取
        }
        if (c.indexOf(nameEQ) == 0) {       //如果含有我们要的name
            return unescape(c.substring(nameEQ.length, c.length));    //解码并截取我们要值
        }
    }
    return false;
}

function setCookie(name, value) {
    var Days = 1;
    var exp = new Date();
    exp.setTime(exp.getTime() + Days * 24 * 60 * 60 * 1000);
    document.cookie = name + "=" + escape(value) + ";expires=" + exp.toGMTString();
}

//子字符串替换
String.prototype.replaceMulti = function (search, replace) {
    var str = this;
    for (var i = 0, len = search.length; i < len; i++) {
        str = str.replace(new RegExp(search[i], 'gi'), replace[i]);
    }
    return str;
};

//随机生成汉字
function generateHanzi() {
    eval("var word=" + '"\\u' + (Math.round(Math.random() * 20901) + 19968).toString(16) + '";');
    return word;
}

ws.onopen = function () {
    messageHelper.onOpen();
};

ws.onclose = function (e) {
    messageHelper.onClose(e);
};

ws.onerror = function (e) {
    messageHelper.onError(e);
};

//TODO 配置文件，直接调用方法
ws.onmessage = function (message) {
    messageHelper.onMessage(message);
};

var dataHelper = {
    is_msgpack: typeof msgpack == "object",
    /**
     * 编码数据
     * @param {object} obj
     * @returns {string}
     */
    encode: function (obj) {
        /*if (this.is_msgpack)
         return msgpack.pack(obj, true);
         else*/
        return JSON.stringify(obj);
    },
    /**
     * 解码数据
     * @param {string} str
     * @returns {object}
     */
    decode: function (str) {
        /*if (this.is_msgpack)
         return msgpack.unpack(str);
         else*/
        return JSON.parse(str);
    }
};

//======================消息处理============================
var im = {
    layer_user: [], //保存用户窗口顺序
    chatContainer: $(".chat-box"),
    current_user_id: 0, //当前聊天的用户ID
    chat_personal_html:
        '<div id="%ID%" class="chat-personal animated %CLASS_NAME%">\
            <span class="chat-back" onclick="im.hide(this);"><返回</span>\
            <div class="chat-title">\
                <span class="chat-status chat-status-online"></span>\
                <span>%USERNAME%</span>\
            </div>\
            <div class="chat-content">%MESSAGE%</div>\
        </div>',
    contactsContainer: $("#contacts"),
    getWindowId: function (user_id) {
        return "user-" + user_id;
    },
    getWindow: function (user_id) {
        return $("#" + this.getWindowId(user_id));
    },
    hasWindow: function (user_id) {
        return this.getWindow(user_id).length > 0;
    },
    isTop: function (user_id) {
        return user_id == this.current_user_id;
    },
    getWindowContent: function (user_id) {
        return this.getWindow(user_id).children(".chat-content");
    },
    writeMessage: function (user_id, html) {
        this.getWindowContent(user_id).append(html);
    },
    writeHistoryMessage: function (user_id, html) {
        this.getWindowContent(user_id).prepend(html);
    },
    tip: function (user, html, timestamp) {
        this.open(user, html, timestamp, true);
        if (this.isTop(user.user_id)) {
            //当前正在聊天，则不提示
            return;
        }

        audio.play();

        var src = user.avatar ? './' + user.avatar : './images/chat.png';
        layer.open({
            style: 'background:#fff;cursor:pointer;bottom:-100px;',
            content:'<div class="chat-min"><img src="'+src+'"><span>收到新消息</span></div>',
            skin: 'msg',
            success: function(elem) {
                elem.onclick = function () {
                    layer.close(this.getAttribute("index")); //点击时关闭弹框
                    im.open(user, "", timestamp);
                }
            }
        });
    },
    open: function (user, html, timestamp, hidden) {
        var user_id = user.user_id;
        var username = user.username;
        html = (typeof html == "undefined") ? "" : html;
        hidden = typeof hidden != "undefined";

        if (!hidden) {
            this.current_user_id = user_id;
            this.chatContainer.removeClass("hidden");
            messageHelper.setReceiver(user);
        }

        if (this.hasWindow(user_id)) {
            this.writeMessage(user_id, html);
            var user_window = this.getWindow(user_id);
            if (!hidden) {
                user_window.removeClass("hidden bounceOutRight z-index-normal").addClass("bounceInRight z-index-top");
            }
            //检测在线
            if (messageHelper.isOnline(user_id)) {
                user_window.find(".chat-status").removeClass("chat-status-offline").addClass("chat-status-online");
            } else {
                user_window.find(".chat-status").removeClass("chat-status-online").addClass("chat-status-offline");
            }
        } else {
            //无窗口，创建
            html = messageHelper.history_personal_btn + html; //历史消息按钮
            timestamp = (typeof timestamp == "undefined") ? (new Date()).getTime() / 1000 : timestamp;
            messageHelper.setHistoryTime(user_id, timestamp);

            var window_id = this.getWindowId(user_id);
            var class_name = hidden ? "hidden z-index-normal" : "bounceInRight z-index-top";
            var search = ["%ID%", "%CLASS_NAME%", "%USERNAME%", "%MESSAGE%"];
            var replace = [window_id, class_name, username, html];
            html = this.chat_personal_html.replaceMulti(search, replace);
            this.chatContainer.append(html);
        }

        this.addLayer(user);
        $(".chat-personal").not("#" + this.getWindowId(user_id)).removeClass("z-index-top").addClass("z-index-normal");
        this.autoBottom();
    },
    hide: function (btn) {
        this.delLayer();
        $(btn).parent(".chat-personal").removeClass("bounceInRight z-index-top").addClass("bounceOutRight");
        var len = this.layer_user.length;
        if (0 == len) {
            this.current_user_id = 0;
            this.hideAll();
        } else {
            var user = this.layer_user[len - 1];
            this.current_user_id = user.user_id;
            messageHelper.setReceiver(user);
        }
        window.setTimeout(function () {
            $(btn).parent(".chat-personal").addClass("hidden z-index-normal");
        }, 500);
    },
    hideAll: function () {
        this.layer_user = [];
        messageHelper.setReceiver(0);
        var container = this.chatContainer;
        window.setTimeout(function () {
            container.addClass("hidden");
        }, 500);
    },
    addLayer: function (user) {
        var index = false;
        //查找数组中是否已经存在该用户，有则删除，之后重新排列
        for (var i in this.layer_user) {
            if (this.layer_user[i].user_id == user.user_id) {
                index = i;
                break;
            }
        }
        if (index !== false) {
            this.layer_user.splice(index, 1);
        }
        this.layer_user.push(user); //将用户加入数组队列
    },
    delLayer: function () {
        this.layer_user.pop(); //将当前用户移出队列
    },
    autoBottom: function () {
        if (this.current_user_id) {
            var container = this.getWindowContent(this.current_user_id);
            container.scrollTop(container[0].scrollHeight - container[0].offsetHeight);
        }
    },
    openContacts: function () {
        this.contactsContainer.removeClass("hidden slideOutLeft").addClass("animated slideInLeft");
    },
    closeContacts: function () {
        this.contactsContainer.removeClass("slideInLeft").addClass("animated slideOutLeft");
    }
};

window.MessageHelper = function (options) {
    this.room_container = options.room_container;
    this.online_container = options.online_container;
    this.submit_button = options.submit_button;
    this.input_container = options.input_container;
    this.upload_container = options.upload_container;
    this.upload_music_container = options.upload_music_container;
    this.input_container.attr("maxlength", MAX_LENGTH);
    this.image_loading = 0; //图片上传动画
    this.image_flag = true; //发送图片标志
    var _this = this;

    this.room_container.append(this.history_common_btn);

    //粘贴图片
    this.input_container.pastableTextarea(COMPRESS_PERCENT).on("loadImage", function () {
        _this.image_loading = _this.loading("正在分析，请稍候...");
    }).on("pasteImage", function (e, data) {
        _this.image_loading = _this.loading('正在上传，请稍候...');
        if (!_this.image_flag) {
            return _this.toast('请等待图片上传完成再发送');
        }
        var type = receiver_id ? PERSONAL_IMAGE : COMMON_IMAGE;
        var image = data.dataURL;
        if (image.length > MAX_IMAGE) {
            var size = MAX_IMAGE / 1024 / 1024;
            _this.toast('图片太大，目前只支持'+size+'M');
            return layer.close(_this.image_loading);
        }
        _this.image_flag = false;
        messageHelper.sendMessageAsBlob(type, user.user_id, receiver_id, image);
    }).on("pasteImageStart", function () {
        _this.image_loading = _this.loading("处理中...");
    });

    //压缩图片
    var is_locked = false;
    var image_regexp = /(?:jpe?g|png|gif)/i;
    function compressAndSend(files) {
        if (is_locked) {
            return _this.toast("正在上传中，请稍候再试");
        }
        var len = files.length;
        if (len > MAX_UPLOAD) {
            return _this.toast("目前最多只能上传"+MAX_UPLOAD+"张图片");
        } else if (0 == len) {
            return;
        }
        is_locked = true;
        var type = receiver_id ? PERSONAL_IMAGE : COMMON_IMAGE;
        var index = _this.loading("正在处理，总共"+len+"张图片", len*2);
        var complete = 0;
        function unlock() {
            if (complete >= len) {
                is_locked = false;
                layer.close(index);
            }
        }
        for (var i=0;i<len;i++) {
            if (files[i].size < MAX_IMAGE / 2) {
                var file = files[i];
                var reader = new FileReader();
                reader.onload = function (event) {
                    complete++;
                    if (!image_regexp.test(file.type.split("/").pop())) {
                        unlock();
                        _this.toast("请选择图片上传");
                    } else {
                        _this.sendMessageAsBlob(type, user.user_id, receiver_id, this.result);
                        if (complete >= len) {
                            unlock();
                            _this.toast("上传完毕，点击图片可以预览哦:-)");
                        }
                    }
                };
                reader.readAsDataURL(file);
            } else {
                //压缩图片
                new html5ImgCompress(files[i], {
                    before: function(file) {
                    },
                    done: function (file, base64) {
                        complete++;
                        if (base64.length > MAX_IMAGE) {
                            _this.toast(file.name+"太大");
                        } else {
                            _this.sendMessageAsBlob(type, user.user_id, receiver_id, base64);
                        }
                        if (complete >= len) {
                            unlock();
                            _this.toast("上传完毕，点击图片可以预览哦:-)");
                        }
                    },
                    fail: function(file) {
                        complete++;
                        unlock();
                        _this.toast("图片压缩失败");
                    },
                    complete: function(file) {
                    },
                    notSupport: function(file) {
                        complete++;
                        unlock();
                        _this.toast('当浏览器不支持，换Chrome试试吧;-)');
                    }
                });
            }
        }
    }

    //点击上传图片
    var input = this.upload_container.next("[type=file]");
    this.upload_container.click(function () {
        _this.toast("最多"+MAX_UPLOAD+"张哦");
        input.trigger("click");
    });
    input.change(function () {
        compressAndSend(this.files);
    });

    //上传音乐
    var input_music = this.upload_music_container.next("[type=file]");
    this.upload_music_container.click(function () {
        input_music.trigger("click");
    });
    input_music.change(function () {
        if (0 == this.files.length) {
            _this.toast("请选择一首音乐");
            return;
        }
        var index = _this.loading("正在处理，请稍候...", 3);
        var reader = new FileReader();
        var file = this.files[0];
        reader.onload = function (event) {
            layer.close(index);
            var name = file.name;
            var type = file.type;
            var regexp = /(?:mp3|ogg|wav)/i;
            if (!regexp.test(type.split('/').pop())) {
                _this.toast("目前只支持mp3,ogg,wav格式");
                return;
            }
            if (file.size > MAX_MUSIC_SIZE) {
                var size = MAX_MUSIC_SIZE / 1024 / 1024;
                _this.toast("文件大太，目前最大"+size+'M');
                return;
            }
            index = _this.loading("上传中，请稍候...", 3);
            type = (0 == receiver_id) ? COMMON_MUSIC : PERSONAL_MUSIC;
            _this.sendMessageAsBlob(type, user.user_id, receiver_id, {
                name: name,
                data: this.result
            });
            layer.close(index);
            _this.toast("上传音乐完毕");
        };
        reader.readAsDataURL(file);
    });

    //拖放上传
    //移入
    document.addEventListener("dragenter", function (e) {
        $("body").css("border", "5px solid rgba(0,255,0, 0.3)");
    }, false);
    //移出
    document.addEventListener("dragleave", function (e) {
        $("body").css("border", "none");
    }, false);
    //放到何处
    document.addEventListener("dragover", function (e) {
        e.preventDefault(); //阻止默认的无法放置
    }, false);
    //进行放置
    document.addEventListener("drop", function (e) {
        e.preventDefault(); //阻止默认的以链接方式打开
        $("body").css("border", "none");
        compressAndSend(e.dataTransfer.files);
    }, false);
};

MessageHelper.prototype = {
    retry_times: 0, //断线重试次数

    prev_time: 0,//上次消息时间 (new Date()).getTime()

    show_time_during: 300,//显示聊天时间的间隔时长

    query_time: {}, //查询时间

    history_time: {}, //历史时间

    time_message: '<div class="text-center">%TIME%</div>',//时间信息

    is_first: true, //是否第一次进入

    search: [" ", "\n", "\r\n", "\t", "\\\\'", '\\\\\\\\', MATCH_URL], //空格、换行、制表符...转换标签，保持原貌（适用于颜文字、代码等）

    replace: ["&nbsp;", "<br />", "<br />", "&nbsp;&nbsp;&nbsp;&nbsp;", "'", "\\", "<a href='$1' target='_blank'>$1</a>"],

    history_common_btn: '<div class="text-center"><a href="javascript:;" onclick="messageHelper.getHistoryCommonMessage(this, event)">查看历史消息</a></div>',

    history_personal_btn: '<div class="text-center"><a href="javascript:;" onclick="messageHelper.getHistoryPersonalMessage(this, event)">查看历史消息</a></div>',

    common_message:
        '<table class="table-chat">\
            <tr>\
                <td class="td-head">\
                    <div class="head text-center text-muted img-circle">\
                        <a href=\'javascript:im.open(%USER%);\' title="点击头像私聊">%AVATAR%</a>\
                    </div>\
                </td>\
                <td class="td-triangle"><div class="left-triangle-common"></div></td>\
                <td><div class="text-left text-sm">%INFO%:</div><div class="bubble-left text-left text-color">%MESSAGE%</div></td>\
                <td class="td-blank"></td>\
            </tr>\
        </table>',

    my_message:
        '<table class="table-chat">\
            <tr>\
                <td class="td-blank"></td>\
                <td><div class="text-right text-sm">%INFO%:</div><div class="bubble-right text-left text-color">%MESSAGE%</div></td>\
                <td class="td-triangle"><div class="right-triangle-common"></div></td>\
                <td class="td-head"><div class="head text-center text-muted img-circle">%AVATAR%</div></td>\
            </tr>\
        </table>',//我发出的 me@all

    self_message:
        '<table class="table-chat">\
            <tr>\
                <td class="td-blank"></td>\
                <td><div class="bubble-right text-left">%MESSAGE%</div></td>\
                <td class="td-triangle"><div class="right-triangle"></div></td>\
                <td class="td-head"><div class="head text-center text-muted img-circle">%AVATAR%</div></td>\
            </tr>\
        </table>',//我发出的 me@other

    private_message:
        '<table class="table-chat">\
            <tr>\
                <td class="td-head">\
                    <div class="head text-center text-muted img-circle">\
                        <a href=\'javascript:im.open(%USER%);\' title="点击头像私聊">%AVATAR%</a>\
                    </div>\
                </td>\
                <td class="td-triangle"><div class="left-triangle"></div></td>\
                <td><div class="bubble-left text-left">%MESSAGE%</div></td>\
                <td class="td-blank"></td>\
            </tr>\
        </table>',//私信 other@me

    warning_message: '<div class="text-center text-danger">%MESSAGE%</div>',

    system_message: '<div class="text-center text-muted">%MESSAGE%</div>',

    welcome_message:
        '<div class="text-center text-info">\
            欢迎<a href=\'javascript:im.open(%USER%);\'>%USERNAME%</a>进入聊天室\
        </div>',

    quit_message:
        '<div class="text-center text-muted">\
            用户%USERNAME%退出聊天室\
        </div>',

    tip: '<div class="text-center text-info">欢迎进入聊天室。文明上网，礼貌发言</div>',

    common_image: '<img class="img-responsive img-msg" src="%URL%" onload="messageHelper.autoBottom()" onclick="imageHelper.preview(this)" alt="%SENDER%" title="点击图片预览" />',

    history_common_image: '<img class="img-responsive img-msg" src="%URL%" onload="" onclick="imageHelper.preview(this)" alt="%SENDER%" title="点击图片预览" />',

    private_image: '<img class="img-responsive img-msg" src="%URL%" onload="im.autoBottom()" onclick="imageHelper.preview(this)" alt="%SENDER%" title="点击图片预览" />',

    history_private_image: '<img class="img-responsive img-msg" src="%URL%" onload="" onclick="imageHelper.preview(this)" alt="%SENDER%" title="点击图片预览" />',

    music_message:
        '<i class="fa fa-music fa-3x music-color"></i>\
        <div class="music-info" onclick="messageHelper.playMusic(this, event);" data-url="%MUSIC_URL%" title="点击播放/暂停">\
            <progress class="progress music-color music-progress" value="0" max="100" data-time="0">0%</progress>\
            <span class="text-left music-name">%MUSIC_NAME%</span><span class="text-right music-during"></span>\
        </div>',

    welcome: function (mess) {
        var html = this.getTimeMessage(mess);
        if (user.user_id == mess.user.user_id) {
            if (this.is_first) {
                html += this.tip;
                this.toast(user.username + "，欢迎回来");
                this.is_first = false;
                this.history_time[0] = this.query_time[0] = mess.timestamp; //查询时间点
            } else {
                html = "";
                this.toast("已成功连接！");
            }
        } else {
            var search = ["%USER_ID%", "%USERNAME%", "%USER%"];
            var replace = [mess.user.user_id, mess.user.username, JSON.stringify(mess.user)];
            html += this.welcome_message.replaceMulti(search, replace);
        }
        this.room_container.append(html);
        this.autoBottom();
    },

    quit: function (mess) {
        var html = this.getTimeMessage(mess);
        var search = ["%USERNAME%"];
        var replace = [mess.user.username];
        html += this.quit_message.replaceMulti(search, replace);
        this.room_container.append(html);
        this.autoBottom();
    },

    getCommonHtml: function (mess, html) {
        var search, avatar, info, replace;
        avatar = mess.sender.avatar ? '<img src="./'+mess.sender.avatar+'" />' : '<img src="./images/chat.png" />';
        info = mess.sender.username;
        mess.mess = mess.mess.replaceMulti(this.search, this.replace);
        if (user.user_id == mess.sender.user_id) {
            search = ["%INFO%", "%MESSAGE%", "%AVATAR%"];
            replace = [info, mess.mess, avatar];
            html += this.my_message.replaceMulti(search, replace);
        } else {
            search = ["%USER%", "%AVATAR%", "%INFO%", "%MESSAGE%"];
            replace = [JSON.stringify(mess.sender), avatar, info, mess.mess];
            html += this.common_message.replaceMulti(search, replace);
        }
        return html;
    },

    common: function (mess) {
        var html = this.getTimeMessage(mess);
        html = this.getCommonHtml(mess, html);
        this.room_container.append(html);
        this.autoBottom();
    },

    getCommonImageHtml: function (mess, html, image_html) {
        var search = ["%URL%", "%SENDER%"];
        var replace = [href + mess.mess, mess.sender.username];
        var image = image_html.replaceMulti(search, replace);
        var avatar = mess.sender.avatar ? '<img src="./'+mess.sender.avatar+'" />' : '<img src="./images/chat.png" />';
        var info = mess.sender.username;
        if (user.user_id == mess.sender.user_id) {
            search = ["%INFO%", "%MESSAGE%", "%AVATAR%"];
            replace = [info, image, avatar];
            html += this.my_message.replaceMulti(search, replace);
            this.image_flag = true;
            this.image_loading && layer.close(this.image_loading);
        } else {
            search = ["%USER%", "%AVATAR%", "%INFO%", "%MESSAGE%"];
            replace = [JSON.stringify(mess.sender), avatar, info, image];
            html += this.common_message.replaceMulti(search, replace);
        }
        return html;
    },

    commonImage: function (mess) {
        var html = this.getTimeMessage(mess);
        html = this.getCommonImageHtml(mess, html, this.common_image);
        this.room_container.append(html);
    },

    getSelfHtml: function (mess, html) {
        mess.mess = mess.mess.replaceMulti(this.search, this.replace);
        var avatar = mess.sender.avatar ? '<img src="./'+mess.sender.avatar+'" />' : '<img src="./images/chat.png" />';
        var search = ["%MESSAGE%", "%AVATAR%"];
        var replace = [mess.mess, avatar];
        html += this.self_message.replaceMulti(search, replace);
        return html;
    },

    self: function (mess) {
        var html = this.getTimeMessage(mess);
        html = this.getSelfHtml(mess, html);

        im.writeMessage(mess.receiver.user_id, html);
        im.autoBottom();
    },

    getSelfImageHtml: function(mess, html, image_html) {
        var search = ["%URL%", "%SENDER%"];
        var replace = [href + mess.mess, mess.sender.username];
        var image = image_html.replaceMulti(search, replace);
        var avatar = mess.sender.avatar ? '<img src="./'+mess.sender.avatar+'" />' : '<img src="./images/chat.png" />';

        search = ["%MESSAGE%", "%AVATAR%"];
        replace = [image, avatar];
        html += this.self_message.replaceMulti(search, replace);
        return html;
    },

    selfImage: function (mess) {
        var html = this.getTimeMessage(mess);
        html = this.getSelfImageHtml(mess, html, this.private_image);

        this.image_flag = true;
        this.image_loading && layer.close(this.image_loading);
        im.writeMessage(mess.receiver.user_id, html);
        im.autoBottom();
    },

    getOtherHtml: function (mess, html) {
        mess.mess = mess.mess.replaceMulti(this.search, this.replace);
        var avatar = mess.sender.avatar ? '<img src="./'+mess.sender.avatar+'" />' : '<img src="./images/chat.png" />';
        var search = ["%USER%", "%AVATAR%", "%MESSAGE%"];
        var replace = [JSON.stringify(mess.sender), avatar, mess.mess];
        html += this.private_message.replaceMulti(search, replace);
        return html;
    },

    other: function (mess) {
        var html = this.getTimeMessage(mess);
        html = this.getOtherHtml(mess, html);

        im.tip(mess.sender, html, mess.timestamp);
    },

    getOtherImageHtml: function(mess, html, image_html) {
        var search = ["%URL%", "%SENDER%"];
        var replace = [href + mess.mess, mess.sender.username];
        var image = image_html.replaceMulti(search, replace);
        var avatar = mess.sender.avatar ? '<img src="./'+mess.sender.avatar+'" />' : '<img src="./images/chat.png" />';
        search = ["%USER%", "%AVATAR%", "%MESSAGE%"];
        replace = [JSON.stringify(mess.sender), avatar, image];
        html += this.private_message.replaceMulti(search, replace);
        return html;
    },

    otherImage: function (mess) {
        var html = this.getTimeMessage(mess);
        html = this.getOtherImageHtml(mess, html, this.private_image);

        im.tip(mess.sender, html, mess.timestamp);
    },

    //获取历史消息
    getHistoryCommonMessage: function (btn, e) {
        e.stopPropagation();
        btn.parentNode.removeChild(btn); //暂时删除按钮，接收到数据后，再显示按钮，防止操作频繁
        var query_time = this.query_time[0] - 0.0001; //精确到4位，和服务器保持一致
        ws.send(dataHelper.encode({
            "type": HISTORY_COMMON_MESSAGE,
            "sender_id": user.user_id,
            "query_time": query_time
        }));
    },

    setQueryTime: function (receiver_id) {
        var date = new Date();
        date.setTime(this.query_time[receiver_id] * 1000);
        var curr_date = date.toDateString(); //当前日期
        var time = date.getHours()+date.getMinutes()+date.getSeconds();
        var second = (0 == time) ? 86400 : 0; //如果不是整点，则查截止到今天的，否则是前一天的
        this.query_time[receiver_id] = Date.parse(curr_date) / 1000 - second;

        date.setTime((this.query_time[receiver_id]-1) * 1000);
        var query_date = date.toLocaleDateString();
        this.toast("继续点击查" + query_date + "消息");
    },

    setHistoryTime: function (receiver_id, timestamp) {
        this.history_time[receiver_id] = this.query_time[receiver_id] = timestamp;
    },

    //历史消息处理
    history_common_message: function (mess) {
        var data = mess.mess, len = data.length;
        var html = this.history_common_btn;
        if (0 == len) {
            this.setQueryTime(0);

            this.room_container.prepend(html);
            return;
        }

        for (var i=0;i<len;i++) {
            var dataI = dataHelper.decode(data[i]);
            var time_message = this.getHistoryTimeMessage(0, dataI);
            switch (dataI.type) {
                case COMMON:
                    html += this.getCommonHtml(dataI, time_message);
                    break;
                case COMMON_IMAGE:
                case COMMON_EMOTION:
                    html += this.getCommonImageHtml(dataI, time_message, this.history_common_image);
                    break;
                case COMMON_MUSIC:
                    html += this.getCommonMusicHtml(dataI, time_message);
                    break;
            }
        }
        this.room_container.prepend(html);
        this.setHistoryTime(0, dataHelper.decode(data[0]).timestamp); //重置为第一个时间戳
    },

    getHistoryPersonalMessage: function (btn, e) {
        e.stopPropagation();
        btn.parentNode.removeChild(btn);
        var query_time = this.query_time[receiver_id] - 0.0001;
        ws.send(dataHelper.encode({
            "type": HISTORY_PERSONAL_MESSAGE,
            "sender_id": user.user_id,
            "query_time": query_time,
            "receiver_id": receiver_id
        }));
    },

    history_personal_message: function (mess) {
        var data = mess.mess,len=data.length;
        var html = this.history_personal_btn;
        var receiver_id = mess.receiver_id;
        if (0 == len) {
            this.setQueryTime(receiver_id);

            im.writeHistoryMessage(receiver_id, html);
            return;
        }

        for (var i=0;i<len;i++) {
            var dataI = dataHelper.decode(data[i]), time_message;
            switch (dataI.type) {
                case PERSONAL:
                    if (dataI.sender.user_id == user.user_id) {
                        time_message = this.getHistoryTimeMessage(dataI.receiver.user_id, dataI);
                        html += this.getSelfHtml(dataI, time_message);
                    } else {
                        time_message = this.getHistoryTimeMessage(dataI.sender.user_id, dataI);
                        html += this.getOtherHtml(dataI, time_message);
                    }
                    break;
                case PERSONAL_IMAGE:
                case PERSONAL_EMOTION:
                    if (dataI.sender.user_id == user.user_id) {
                        time_message = this.getHistoryTimeMessage(dataI.receiver.user_id, dataI);
                        html += this.getSelfImageHtml(dataI, time_message, this.history_private_image);
                    } else {
                        time_message = this.getHistoryTimeMessage(dataI.sender.user_id, dataI);
                        html += this.getOtherImageHtml(dataI, time_message, this.history_private_image);
                    }
                    break;
                case PERSONAL_MUSIC:
                    if (dataI.sender.user_id == user.user_id) {
                        time_message = this.getHistoryTimeMessage(dataI.receiver.user_id, dataI);
                        html += this.getSelfMusicHtml(dataI, time_message);
                    } else {
                        time_message = this.getHistoryTimeMessage(dataI.sender.user_id, dataI);
                        html += this.getOtherMusicHtml(dataI, time_message);
                    }
                    break;
            }
        }
        im.writeHistoryMessage(receiver_id, html);

        this.setHistoryTime(receiver_id, dataHelper.decode(data[0]).timestamp); //重置为第一个时间戳
    },

    getCommonMusicHtml: function (mess, html) {
        var search, avatar, info, message, replace;
        avatar = mess.sender.avatar ? '<img src="./'+mess.sender.avatar+'" />' : '<img src="./images/chat.png" />';
        info = mess.sender.username;

        search = ["%MUSIC_URL%", "%MUSIC_NAME%"];
        replace = [mess.mess, mess.name];
        message = this.music_message.replaceMulti(search, replace);

        if (user.user_id == mess.sender.user_id) {
            search = ["%INFO%", "%MESSAGE%", "%AVATAR%"];
            replace = [info, message, avatar];
            html += this.my_message.replaceMulti(search, replace);
        } else {
            search = ["%USER%", "%AVATAR%", "%INFO%", "%MESSAGE%"];
            replace = [JSON.stringify(mess.sender), avatar, info, message];
            html += this.common_message.replaceMulti(search, replace);
        }
        return html;
    },

    common_music: function (mess) {
        var html = this.getTimeMessage(mess);
        html = this.getCommonMusicHtml(mess, html);
        this.room_container.append(html);
        this.autoBottom();
    },

    getSelfMusicHtml: function (mess, html) {
        var search, avatar, message, replace;
        avatar = mess.sender.avatar ? '<img src="./'+mess.sender.avatar+'" />' : '<img src="./images/chat.png" />';
        search = ["%MUSIC_URL%", "%MUSIC_NAME%"];
        replace = [mess.mess, mess.name];
        message = this.music_message.replaceMulti(search, replace);

        search = ["%MESSAGE%", "%AVATAR%"];
        replace = [message, avatar];
        html += this.self_message.replaceMulti(search, replace);
        return html;
    },

    self_music: function (mess) {
        var html = this.getTimeMessage(mess);
        html = this.getSelfMusicHtml(mess, html);

        im.writeMessage(mess.receiver.user_id, html);
    },

    getOtherMusicHtml: function (mess, html) {
        var search, avatar, message, replace;
        avatar = mess.sender.avatar ? '<img src="./'+mess.sender.avatar+'" />' : '<img src="./images/chat.png" />';
        search = ["%MUSIC_URL%", "%MUSIC_NAME%"];
        replace = [mess.mess, mess.name];
        message = this.music_message.replaceMulti(search, replace);

        search = ["%USER%", "%AVATAR%", "%MESSAGE%"];
        replace = [JSON.stringify(mess.sender), avatar, message];
        html += this.private_message.replaceMulti(search, replace);
        return html;
    },

    other_music: function (mess) {
        var html = this.getTimeMessage(mess);
        html = this.getOtherMusicHtml(mess, html);

        im.tip(mess.sender, html, mess.timestamp);
    },

    music_timer: 0,

    getDuring: function(second) {
        var minute = Math.floor(second / 60);
        var sec = Math.ceil(second - minute * 60);
        minute = minute > 9 ? minute : '0' + minute;
        sec = sec > 9 ? sec : '0' + sec;
        return minute + ":" + sec;
    },

    playMusic: function (btn, e) {
        e.stopPropagation(); //阻止冒泡事件
        var url = href + $(btn).attr("data-url");
        var progress = $(btn).children("progress");
        var music_during = $(btn).children(".music-during");
        if (music.src != url) {
            clearInterval(this.music_timer);
            music.pause();
            music.src = url;
        }

        if (music.paused) {
            music.currentTime = progress.attr("data-time"); //保留播放进度
            music.play();
            var _this = this;
            this.music_timer = window.setInterval(function () {
                var percent = (music.currentTime/ music.duration * 100);
                progress.val(percent);
                progress.attr("data-time", music.currentTime);
                //计算时长
                music_during.text(_this.getDuring(music.currentTime) + "/" + _this.getDuring(music.duration));
                if (percent >= 100) {
                    progress.attr("data-time", 0); //清零，下次重新播放
                    clearInterval(_this.music_timer);
                }
            }, 200);
        } else {
            music.pause();
            clearInterval(this.music_timer);
        }
    },

    online_users: {}, //在线用户

    online: function (mess) {
        this.online_container.html("");
        this.online_users = {};
        var total = 0, list;
        var users = mess.users;
        var html = '<div class="form-control btn-success-outline text-center" onclick="im.hideAll();im.closeContacts();">所有人</div>';
        if (1 == role_id) {
            list =
                '<div class="input-group">\
                    <div class="form-control btn-success-outline text-center" onclick=\'im.open(%USER%);im.closeContacts();\'>%USERNAME%</div>\
                    <span class="input-group-btn">\
                        <button type="button" class="btn btn-danger-outline" onclick="messageHelper.forbid(\'%USER_ID%\', \'%ADMIN_ID%\')">拉黑</button>\
                    </span>\
                </div>';
        } else {
            list = '<div class="form-control btn-success-outline text-center" onclick=\'im.open(%USER%);im.closeContacts();\'>%USERNAME%</div>';
        }
        var search = ["%USER_ID%", "%USERNAME%", "%ADMIN_ID%", "%USER%"];
        for (var i in users) {
            total++;
            if (user.user_id == users[i].user_id) continue;
            var replace = [users[i].user_id, users[i].username, user.user_id, JSON.stringify(users[i])];
            html += list.replaceMulti(search, replace);
            this.online_users[users[i].user_id] = users[i].username;
        }
        $("#total").text(total);
        this.online_container.append(html);
    },

    isOnline: function (user_id) {
        return this.online_users.hasOwnProperty(user_id);
    },

    register: function (mess) {
        //弹出框
        $("#register").modal({
            backdrop: true,
            keyboard: false
        });
        this.autoBottom();
    },

    reg: function (username, password) {
        if (!username) {
            username = "";
            for (var i = 0; i < 4; i++) {
                username += generateHanzi();
            }
        }
        username = username.substr(0, 30);
        !password && (password = '123456');
        ws.send(dataHelper.encode({
            type: REGISTER,
            username: username,
            password: password
        }));
    },

    incorrect: function (mess) {
        $("#error").text(mess.mess);
        $("#password").val("");//清空密码
        window.setTimeout(function () {
            $("#error").text("");
        }, 1000);
        this.autoBottom();
    },

    login: function (mess) {
        $("#register").modal("hide");// 弹框退出
        user = mess.user;
        role_id = user.role_id;
        is_active = user.is_active;
        setCookie("user", JSON.stringify(user));//刷新cookie
        this.autoBottom();
        this.input_container.focus();
    },

    error: function (mess) {
        this.image_flag = true;
        this.image_loading && layer.close(this.image_loading);
        this.toast(mess.mess);
    },

    system: function (mess) {
        var html = this.system_message.replace(/%MESSAGE%/g, mess.mess);
        this.room_container.append(html);
        this.autoBottom();
    },

    warning: function (mess) {
        var html = this.warning_message.replace(/%MESSAGE%/g, mess.mess);
        this.room_container.append(html);
        this.autoBottom();
    },

    avatar_success: function (mess) {
        var obj = {avatar: href + mess.mess};
        $.extend(user, obj);
        setCookie('user', JSON.stringify(user));
        avatarHelper.close();
        this.toast("上传成功，发消息试试吧;-)");
    },

    avatar_fail: function (mess) {
        this.toast(mess.mess);
    },

    getTimeMessage: function (mess) {
        var html = "";
        if (Math.abs(mess.timestamp - this.prev_time) > this.show_time_during) {
            html = this.time_message.replace(/%TIME%/g, mess.time);
            this.prev_time = mess.timestamp;
        }
        return html;
    },

    getHistoryTimeMessage: function (user_id, mess) {
        var html = "";
        if (Math.abs(this.history_time[user_id] - mess.timestamp) > this.show_time_during) {
            var date = new Date();
            date.setTime(mess.timestamp * 1000);
            var curr_date = date.toLocaleDateString();
            html = this.time_message.replace(/%TIME%/g, curr_date + " " + mess.time);
            this.history_time[user_id] = mess.timestamp;
        }
        return html;
    },

    //自动到底部，图片需要加载完成时再调用
    autoBottom: function () {
        this.room_container.scrollTop(this.room_container.get(0).scrollHeight - this.room_container.get(0).offsetHeight);
    },

    autoTop: function () {
        this.room_container.scrollTop(0);
    },

    //设置接收人
    setReceiver: function (user) {
        var text;
        if (0 == user) {
            receiver_id = 0;
            text = "发送";
        } else {
            receiver_id = user.user_id;
            text = "@" + user.username;
        }
        this.submit_button.text(text);
    },

    //发送消息
    send: function () {
        this.input_container.focus();
        var mess = this.input_container.val();
        if ("" == mess) {
            return;
        }
        if (mess.length > MAX_LENGTH)
            mess = mess.substr(0, MAX_LENGTH);
        var type = receiver_id ? PERSONAL : COMMON;
        this.sendMessage(type, user.user_id, receiver_id, mess);
        this.input_container.val("");
    },

    //按键ctrl+enter
    sendMess: function (event) {
        if (event.ctrlKey && event.keyCode == 13)
            this.send();
    },

    forbid: function (user_id, admin_id) {
        this.sendMessage(REMOVE, admin_id, user_id, "");
    },

    sendMessage: function (type, sender_id, receiver_id, mess) {
        ws.send(dataHelper.encode({
            type: type,
            sender_id: sender_id,
            receiver_id: receiver_id,
            mess: mess
        }));
    },

    sendMessageAsBlob: function (type, sender_id, receiver_id, mess) {
        try {
            ws.send(new Blob([dataHelper.encode({
                type: type,
                sender_id: sender_id,
                receiver_id: receiver_id,
                mess: mess
            })], {type: "application/json"}));
        } catch (e) {
            //UC不能使用Blob，换明文试一次
            this.sendMessage(type, sender_id, receiver_id, mess);
        }
    },

    toast: function (content) {
        return layer.open({
            content: content
            ,skin: 'msg'
            ,time: 3
        });
    },

    loading: function (content, time, shadeClose) {
        if (typeof time=="undefined") time = false;
        if (typeof shadeClose=="undefined") shadeClose = true;
        return layer.open({
            type: 2
            ,content: content
            ,time: time
            ,shadeClose: shadeClose
        });
    },

    controlBell: function (btn) {
        var i = $(btn).children("i");
        if (i.hasClass("fa-bell-o")) {
            this.toast("声音：关");
            audio.volume = 0;
            music.volume = 0;
            i.removeClass("fa-bell-o").addClass("fa-bell-slash-o");
        } else {
            this.toast("声音：开");
            audio.volume = 1;
            music.volume = 1;
            audio.play();
            i.removeClass("fa-bell-slash-o").addClass("fa-bell-o");
        }
    },

    about: function () {
        layer.open({
            content: "QQ群：345480905",
            btn: "联知道了"
        })
    },

    onOpen: function () {
        //握手成功
        if ((typeof(user) == 'object') && user.hasOwnProperty('user_id') && user.user_id) {
            ws.send(dataHelper.encode({
                type: LOGIN,
                sender_id: user.user_id
            }));
        } else {
            this.register();
        }
    },

    onClose: function (e) {
        //连接关闭
        $("#total").text(0);//清除在线人数
        $("#online").text("");//清除在线列表
        var d = new Date();
        var date = 'Y-m-d H:i:s';
        var search = ['Y', 'm', 'd', 'H', 'i', 's'];
        var replace = [d.getFullYear(), d.getMonth() + 1, d.getDate(), d.getHours(), d.getMinutes(), d.getSeconds()];
        date = date.replaceMulti(search, replace);
        if (!is_active) {
            return;
        }
        var index = this.loading(date + ' 已断线，重试中...', false, false);
        var _this = this;
        var timer = window.setInterval(function () {
            try {
                //断线重连
                if (_this.retry_times >= MAX_LIMITS) {
                    window.clearInterval(timer);
                    layer.close(index);
                    //alert(ws.readyState);
                    _this.toast("无法连接到服务器，请稍候再试");
                    return;
                }
                if (ws.readyState == WebSocket.OPEN) {
                    window.clearInterval(timer);
                    ws.onclose = function (e) {
                        _this.onClose(e);
                    };

                    ws.onerror = function (e) {
                        _this.onError(e);
                    };

                    ws.onmessage = function (message) {
                        _this.onMessage(message);
                    };
                    _this.onOpen();
                    layer.close(index);
                    _this.retry_times = 0;
                    return;
                }
                ws = new WebSocket(url);
                _this.retry_times++;
            } catch (e) {}
        }, 2000);
    },

    onError: function (e) {
        $("#total").text(0);//清除在线人数
        $("#online").text("");//清除在线列表
        this.toast('连接服务器失败');
    },

    onMessage: function (message) {
        var mess = dataHelper.decode(message.data);
        try {
            switch (mess.type) {
                case WELCOME://欢迎消息
                    this.welcome(mess);
                    break;
                case QUIT://退出消息
                    this.quit(mess);
                    break;
                case COMMON://公共消息
                    this.common(mess);
                    break;
                case COMMON_IMAGE://公共消息
                case COMMON_EMOTION:
                    this.commonImage(mess);
                    break;
                case SELF://myself @other
                    this.self(mess);
                    break;
                case SELF_IMAGE://myself @other
                case SELF_EMOTION:
                    this.selfImage(mess);
                    break;
                case OTHER://other @me
                    this.other(mess);
                    break;
                case OTHER_IMAGE://other @me
                case OTHER_EMOTION:
                    this.otherImage(mess);
                    break;
                case ONLINE://在线用户列表
                    this.online(mess);
                    break;
                case REGISTER://需要注册
                    this.register();
                    break;
                case INCORRECT://登录出错
                    this.incorrect(mess);
                    break;
                case LOGIN://已经登录
                    this.login(mess);
                    break;
                case ERROR://出错
                    this.error(mess);
                    break;
                case REMOVE://移除
                case FORBIDDEN://禁用
                    is_active = 0;
                    this.warning(mess);
                    break;
                case WARNING://警告
                    this.warning(mess);
                    break;
                case SYSTEM://系统信息
                    this.system(mess);
                    break;
                case DOWNLINE://下线
                    is_active = 0;
                    this.system(mess);
                    break;
                case AVATAR_SUCCESS:
                    this.avatar_success(mess);
                    break;
                case AVATAR_FAIL:
                    this.avatar_fail(mess);
                    break;
                case HISTORY_COMMON_MESSAGE:
                    this.history_common_message(mess);
                    break;
                case HISTORY_PERSONAL_MESSAGE:
                    this.history_personal_message(mess);
                    break;
                case COMMON_MUSIC:
                    this.common_music(mess);
                    break;
                case SELF_MUSIC:
                    this.self_music(mess);
                    break;
                case OTHER_MUSIC:
                    this.other_music(mess);
                    break;
                case COMMON_VIDEO_REQUEST:
                    videoHelper.request(mess, true);
                    break;
                case PERSONAL_VIDEO_REQUEST:
                    videoHelper.request(mess, false);
                    break;
                case PERSONAL_VIDEO_OFFLINE:
                    videoHelper.offline(mess);
                    break;
                case PERSONAL_VIDEO_ALLOW:
                    videoHelper.allow(mess);
                    break;
                case PERSONAL_VIDEO_DENY:
                    videoHelper.deny(mess);
                    break;
                case PERSONAL_VIDEO_OPEN:
                    videoHelper.video_open(mess);
                    break;
                case PERSONAL_VIDEO_CLOSE:
                    videoHelper.video_close(mess);
                    break;
                case PERSONAL_VIDEO_END:
                    videoHelper.end(mess);
                    break;
                case PERSONAL_VIDEO_OFFER_DESC:
                    videoHelper.offer_desc(mess);
                    break;
                case PERSONAL_VIDEO_ANSWER_DESC:
                    videoHelper.answer_desc(mess);
                    break;
                case PERSONAL_VIDEO_CANDIDATE:
                    videoHelper.video_candidate(mess);
                    break;
                case COMMON_VIDEO_NOTIFY:
                    videoHelper.common_notify(mess);
                    break;
                case PERSONAL_VIDEO_NOTIFY:
                    videoHelper.personal_notify(mess);
                    break;
            }
        } catch (e) {
            alert(e);
        }
    }
};
var messageHelper = new MessageHelper({
    room_container: $("#room"),
    online_container: $("#online"),
    submit_button: $("#submit"),
    input_container: $("#mess"),
    upload_container: $("#upload-image"),
    upload_music_container: $("#upload-music")
});

var imageHelper = {
    //图片预览-针对移动设备，参见Touch events
    preview: function (img) {
        var container = $("#img-container");
        var slider = $("#slider");
        var _this = this;
        $("#room").css("overflow", "hidden");
        $("#backdrop").fadeIn();
        slider.removeClass("hidden bounceOutUp").addClass("animated bounceInDown");
        container.html("");
        var html = "";
        var index = 0;
        var images = $(img).parents(".table-chat").parent().find("img.img-msg");
        var total = images.length;
        $.each(images, function (i, n) {
            if (n == img) {
                index = i;
            }
            var sequence = i + 1;
            html +=
                '<li>\
                    <div class="pinch-zoom">\
                        <img src="' + n.src + '" onclick="event.stopPropagation();" />\
						</div>\
						<div class="description">' + n.alt + " (" + sequence + "/" + total + ")" + '</div>\
					</li>';
        });
        images = ""; //释放内存
        container.append(html);
        container.ready(function () {
            //手指滑动
            var swipe = new Swipe(slider.get(0), {
                speed: 400,
                startSlide: index
            });

            //手指缩放
            $('div.pinch-zoom').each(function () {
                new RTP.PinchZoom($(this), {
                    tapZoomFactor:2,
                    zoomOutFactor:1.3,
                    animationDuration:300,
                    animationInterval:5,
                    maxZoom:4,
                    minZoom:.5,
                    lockDragAxis:false,
                    use2d:true
                });
            });

            slider.click(function () {
                _this.closePreview();
            });


            var max = total - 1;
            var start = swipe.getPos();

            //滑动滚轮切换
            container.get(0).onmousewheel = function (e) {
                var ee = e || window.event;
                var target = ee.delta ? ee.delta : ee.wheelDelta;
                if (target < 0) {
                    start++;
                    start = Math.min(start, max);
                } else if (target > 0) {
                    start --;
                    start = Math.max(start, 0);
                }
                swipe.slide(start, 400);
            };
        });

    },
    //取消预览
    closePreview: function () {
        $("#room").css("overflow", "auto");
        $("#slider").removeClass("bounceInDown").addClass("animated bounceOutUp");
        $("#backdrop").fadeOut();
    }
};

var avatarHelper = {
    container: $(".headImg-popup"),
    backdrop: $("#backdrop"),
    open: function () {
        this.backdrop.fadeIn();
        this.container.removeClass("hidden slideOutUp").addClass("animated slideInDown");
    },
    close: function () {
        this.container.removeClass("slideInDown").addClass("animated slideOutUp");
        this.backdrop.fadeOut();
    },
    init: function () {
        var _this = this;
        this.backdrop.bind("click", function () {
            _this.close();
        });
        $(".showImage").bind("click", function () {
            _this.close();
        });

        var ljkUpload = new LjkUpload(this.container);
        var $file_btn = $(".upload-select-btn"),     //文件选择按钮
            $move_image = $(".move-image"),    //裁剪框
            $range = $("#range");               //滑块

        ljkUpload.tip = function (msg) {
            layer.open({
                content: msg
                ,skin: 'msg'
                ,time: 3
            });
        };
        ljkUpload.loading = function (msg) {
            return layer.open({
                type: 2,
                content: msg
            });
        };
        ljkUpload.delete = function (loading) {
            layer.close(loading);
        };

        //拖拽
        ljkUpload.moveImage({
            ele:$move_image
        });
        //预览
        ljkUpload.showImage({
            fileSelectBtn:$file_btn,
            fileBtn:$("aside input[type='file']"),
            showEle:$move_image,
            isImage:true,          //是文件 false  默认 true
            maxSize:1024*10            //文件最大限制  KB   默认1M
        });
        //缩放
        ljkUpload.rangeToScale({
            range:$range,
            ele:$move_image
        });
        //裁剪
        ljkUpload.clipImage({
            clipSuccess:function( src ){       //clipSuccess  裁剪成功 返回 base64图片
                var index = ljkUpload.loading("上传中，请稍候");
                var html = '<img src="'+src+'" />';
                $(".showImage").html(html);

                messageHelper.sendMessageAsBlob(AVATAR_UPLOAD, user.user_id, 0, src);
                ljkUpload.delete(index);
            }
        });
    }
};
avatarHelper.init();

//=====================表情======================

var emotionHelper = {
    total: 0,//表情总页数
    emotion_html: "",
    position_html: "",
    container: $("#emotion"),
    dots: [],
    emotion_height: 0,
    bottom: 80,
    speed: 100,
    config: [
        {name:"ywz", suffix: '.gif', cols:4, rows:2, len:24, start:1},
        {name:"ymj", suffix: '.gif', cols:4, rows:2, len:24, start:0}
    ],
    init: function () {
        //表情元素
        for (var i in this.config) {
            var name = this.config[i].name;
            var suffix = this.config[i].suffix;
            var cols = this.config[i].cols;
            var rows = this.config[i].rows;
            var len = this.config[i].len;
            var current = this.config[i].start;
            var pages = Math.ceil(len / (cols * rows));//当前表情包页数
            this.total += pages;
            //外层每页li
            for (var j = 0; j < pages; j++) {
                var li = '<li><table class="emotion-table">';
                //每行tr
                for (var k = 0; k < rows; k++) {
                    li += '<tr class="' + name + '">';
                    for (var l = 0; l < cols; l++) {
                        if (current > len) break;//退出td
                        li += '<td><img src="./images/emotion/' + name + '/' + current + suffix +
                            '" alt="' + current + '" title="'+ name + '_' + current + suffix + '"></td>';
                        current++;
                    }
                    li += '</tr>';
                }
                li += '</table></li>';
                this.emotion_html += li;
            }
        }
        this.container.children("ul").html(this.emotion_html);

        //导航元素
        this.position_html += '<li class="active"></li>';
        for (var m = 0; m < this.total - 1; m++) {
            this.position_html += '<li></li>';
        }
        this.position_html += '</li>';
        this.container.children("ol").html(this.position_html);

        this.dots = this.container.children("ol").children("li");
        this.emotion_height = this.container.height();

        //导航滑动、样式
        var _this = this;
        var swipe = new Swipe(document.getElementById('emotion'), {
            speed: 200,
            callback: function (e, pos, li) {
                _this.dots.removeClass("active").eq(pos).addClass("active");
            }
        });
        this.dots.click(function () {
            swipe.slide($(this).index(), 500);
        });

        //表情点击发送消息
        this.container.find("img").click(function (event) {
            event.stopPropagation();
            var type = receiver_id ? PERSONAL_EMOTION : COMMON_EMOTION;
            messageHelper.sendMessage(type, user.user_id, receiver_id, $(this).prop("title"));
        });

        //点击空白折叠
        function hide(event) {
            event.stopPropagation();
            _this.close();
            im.closeContacts();
        }
        $("#room").bind("click", function (e) {
            hide(e);
        });
        $(".chat-box").bind("click", function (e) {
            hide(e);
        });
    },
    open: function () {
        if (H > this.emotion_height) {
            this.toggle();
        } else {
            window.setTimeout("emotionHelper.toggle()", 200); //手机打开键盘时，窗口大小会变化
        }
    },
    close: function () {
        var _this = this;
        $("#room").animate({
            height: H
        }, this.speed, "linear", function () {
            _this.container.addClass("hidden");
        });
        $(".chat-box").animate({
            bottom: _this.bottom
        }, this.speed);
    },
    toggle: function () {
        var available_height = H - this.emotion_height;
        var _this = this;
        if (this.container.hasClass("hidden")) {
            //展开
            this.container.removeClass("hidden");

            $("#room").animate({
                height: available_height
            }, this.speed);
            $(".chat-box").animate({
                bottom: _this.emotion_height + _this.bottom
            }, this.speed);
        } else {
            //折叠
            this.close();
        }
    }
};
emotionHelper.init();

function trace(arg) {
    if (!DEBUG)
        return;
    var now = (window.performance.now() / 1000).toFixed(3);
    console.log(now + ': ', arg);
}
var videoHelper = {
    local_video: null,
    local_stream: null,
    video_box: $(".video-box"),
    backdrop: $("#backdrop"),
    is_connected: false,
    startTime: 0,
    count: 1,
    volume: 0.3,
    is_multi : false,
    max_video: 4,
    user_stream: {},
    user_video: {},
    is_open: false, //是否打开了媒体，多人聊天时不用重复打开
    init: function () {
        this.local_video = document.createElement("video");
        this.local_video.controls = true;
        this.local_video.autoplay = true;
        this.local_video.muted = true;
        this.local_video.volume = this.volume;
        $(this.video_box).append(this.local_video);
    },
    getClassName: function () {
        return this.is_multi ? "video-multi" : "video-double";
    },
    createVideo: function (user_id) {
        var video = document.createElement("video");
        video.controls = true;
        video.autoplay = true;
        video.volume = this.volume;
        video.className = this.getClassName();
        $(this.video_box).prepend(video);
        this.count++;
        this.user_video[user_id] = video;
        return video;
    },
    closeVideo: function () {
        var _this = this;
        layer.open({
            title: ["提示", "background:#eee"],
            content: "不聊了么？",
            btn: ["关闭", "继续"],
            yes: function (index) {
                layer.close(index);
                _this.notify_end();
                _this.closeAll();
            }
        });
    },
    confirm: function () {
        var content, type;
        if (receiver_id) {
            this.is_multi = false;
            if (messageHelper.online_users.hasOwnProperty(receiver_id)) {
                var username = messageHelper.online_users[receiver_id];
                content = "将与" + username + "进行视频聊天，是否继续？";
                type = PERSONAL_VIDEO_REQUEST;
            } else {
                messageHelper.toast("对方已经离线...");
                return;
            }
        } else {
            this.is_multi = true;
            content = "将进行多人视频，是否继续？";
            type = COMMON_VIDEO_REQUEST;
        }
        layer.open({
            title: ["提示", "background:#eee"],
            content: content,
            btn: ["确认", "取消"],
            yes: function (index) {
                layer.close(index);
                messageHelper.sendMessage(type, user.user_id, receiver_id, '');
            }
        });
    },
    request: function (mess, is_multi) {
        var receiver_id = mess.sender.user_id;
        var username = mess.sender.username;
        var _this = this;
        if (mess.sender.user_id == user.user_id)
            return;
        var content = is_multi ?  username + "邀请您加入多人视频，是否允许？" : username + "请求和您视频聊天，是否允许？";
        this.is_multi = is_multi;
        layer.open({
            title: ["提示", "background:#eee"],
            content: content,
            btn: ["允许", "拒绝"],
            yes: function (index) {
                layer.close(index);
                _this.closeAll(); //关闭正在聊天的视频
                _this.accept(mess);
            },
            no: function (index) {
                layer.close(index);
                _this.close(receiver_id);
                messageHelper.sendMessage(PERSONAL_VIDEO_DENY, user.user_id, receiver_id, '');
            }
        });
    },
    //caller
    allow: function(mess) {
        var receiver_id = mess.sender.user_id;
        this.open();
        this.connect(true, receiver_id);
        this.notify_other(receiver_id);
    },
    //receiver
    accept: function (mess) {
        var receiver_id = mess.sender.user_id;
        this.open();
        this.connect(false, receiver_id);
        messageHelper.sendMessage(PERSONAL_VIDEO_ALLOW, user.user_id, receiver_id, '');
        if (!this.is_multi)
            im.open(mess.sender, "", mess.timestamp);
    },
    deny: function (mess) {
        this.is_connected = false;
        this.close(mess.sender.user_id);
        messageHelper.toast(mess.sender.username + mess.mess);
    },
    offline: function (mess) {
        this.is_connected = false;
        messageHelper.toast(mess.sender.username + mess.mess);
        this.close(mess.sender.user_id);
    },
    end: function (mess) {
        layer.closeAll();

        if (this.is_connected) {
            messageHelper.other(mess);
            var during = ((this.getTimestamp() - this.startTime) / 60).toFixed(1);
            messageHelper.toast("视频聊天结束，总共" + during + "分钟");
        }
        this.close(mess.sender.user_id);
    },
    open: function () {
        this.local_video.className = this.getClassName();
        this.backdrop.fadeIn();
        this.video_box.removeClass("hidden").show();
    },
    close: function (user_id) {
        this.count--;
        if (this.user_video.hasOwnProperty(user_id)) {
            this.user_video[user_id].remove();
            this.peerConnection[user_id].close();
            this.user_stream[user_id].getTracks().forEach(function(track) {
                track.stop();
            });
            delete this.user_video[user_id];
            delete this.peerConnection[user_id];
            delete this.user_stream[user_id];
        }
        if (this.count <= 1) {
            this.closeAll();
        }
    },
    closeAll: function () {
        this.count = 1;
        this.is_connected = false;
        this.is_open = false;
        this.video_box.addClass("hidden");
        this.backdrop.fadeOut();

        console.log('disconnect');
        this.stop();
    },
    stop: function () {
        if (this.local_stream) {
            this.local_stream.getTracks().forEach(function(track) {
                track.stop();
            });
        }

        for (var i in this.user_stream) {
            if (this.user_stream[i]) {
                this.user_stream[i].getTracks().forEach(function(track) {
                    track.stop();
                });
            }
        }
        for (var j in this.user_video) {
            $(this.user_video[j]).remove();
            delete this.user_video[j];
        }

        for (var k in this.peerConnection) {
            if (this.peerConnection[k]) {
                this.peerConnection[k].close();
                this.peerConnection[k] = null;
            }
        }

        this.user_stream = {};
        this.user_video = {};
        this.peerConnection = {};
        this.local_video.src = "";
        this.local_stream = null;
    },
    notify_end: function () {
        for (var i in this.user_video) {
            messageHelper.sendMessage(PERSONAL_VIDEO_END, user.user_id, i, '');
        }
    },
    getTimestamp: function () {
        return (new Date()).getTime() / 1000;
    },
    constraints: {
        audio: {echoCancellation: true, autoGainControl: true, volume: 0.5},
        video: {
            width: {min: 640, ideal: 1280, max: 1920},
            height: {min: 480, ideal: 720, max: 1080}
        }
    },
    servers: {
        iceServers: [
            {urls: "turn:ridersam@123.206.83.227", credential: 1399579, username: ""},
            {urls: "stun:stun1.l.google.com:19302"},
            {urls: "stun:stun2.l.google.com:19302"},
            {urls: "stun:stun3.l.google.com:19302"},
            {urls: "stun:stun4.l.google.com:19302"},
            {urls: "stun:stun.ekiga.net"}
        ]
    },
    peerConnection: {},
    connect: function (isCaller, receiver_id) {
        var _this = this, videoTracks, audioTracks;
        var video = this.createVideo(receiver_id);
        console.log('connect');

        //创建连接
        var peerConnection = new RTCPeerConnection(this.servers);
        this.peerConnection[receiver_id] = peerConnection;
        trace('Created local peer connection object peerConnection');

        peerConnection.onicecandidate = function (e) {
            if (e.candidate) {
                trace('peerConnection candidate: '+e.candidate.candidate);
                messageHelper.sendMessage(PERSONAL_VIDEO_CANDIDATE, user.user_id, receiver_id, e.candidate);
            }
        };

        peerConnection.oniceconnectionstatechange = function (e) {
            trace('peerConnection ICE state: ' + this.iceConnectionState);
            console.log('peerConnection ICE state change event: ', e);
        };
        
        peerConnection.onaddstream = function (e) {
            trace('received remote stream');
            video.srcObject = e.stream;
            _this.user_stream[receiver_id] = e.stream;
        };

        if (this.is_open) {
            peerConnection.addStream(this.local_stream);
            if (isCaller)
                this.caller(receiver_id);
            _this.user_stream[receiver_id] = this.local_stream;
            return;
        }

        //获取本地媒体
        trace('Requesting local stream');
        navigator.mediaDevices.getUserMedia(_this.constraints)
            .then(function (stream) {
                _this.local_stream = stream;
                _this.is_open = true;
                messageHelper.sendMessage(PERSONAL_VIDEO_OPEN, user.user_id, receiver_id, "");
                peerConnection.addStream(stream);
                trace('Added local stream to peerConnection');

                if (isCaller) {
                    _this.caller(receiver_id);
                }

                videoTracks = stream.getVideoTracks();
                audioTracks = stream.getAudioTracks();
                if (videoTracks.length > 0) {
                    trace('Using video device: ' + videoTracks[0].label);
                }
                if (audioTracks.length > 0) {
                    trace('Using audio device: ' + audioTracks[0].label);
                }

                trace('Received local stream');
                _this.local_video.srcObject = stream;
                _this.user_stream[receiver_id] = stream;
            })
            .catch(function (e) {
                messageHelper.sendMessage(PERSONAL_VIDEO_CLOSE, user.user_id, receiver_id, "");
                messageHelper.toast("调用视频失败：" + e.toLocaleString());
            });
    },
    offerOptions: {
        offerToReceiveAudio: 1,
        offerToReceiveVideo: 1
    },
    caller: function (receiver_id) {
        var peerConnection = this.peerConnection[receiver_id];
        //创建offer
        trace('peerConnection createOffer start');
        trace("iceConnectionState:"+peerConnection.iceConnectionState+",iceGatheringState:"+peerConnection.iceGatheringState+",signalingState:"+peerConnection.signalingState);

        peerConnection.createOffer(this.offerOptions).then(
            function (desc) {
                trace('Offer from peerConnection\n' + desc.sdp);
                trace('createOffer setLocalDescription start');
                trace("iceConnectionState:"+peerConnection.iceConnectionState+",iceGatheringState:"+peerConnection.iceGatheringState+",signalingState:"+peerConnection.signalingState);

                //设置本地描述
                peerConnection.setLocalDescription(desc).then(function(){
                    trace('createOffer setLocalDescription complete');

                    //发送SDP到远程
                    messageHelper.sendMessage(PERSONAL_VIDEO_OFFER_DESC, user.user_id, receiver_id, desc);
                }, function (error) {
                    trace('createOffer setLocalDescription failed: ' + error.toString());
                });
            },
            function (error) {
                trace('createOffer create session description failed: ' + error.toString());
            }
        );
    },
    video_candidate: function (mess) {
        var candidate = mess.mess;
        var peerConnection = this.peerConnection[mess.sender.user_id];

        trace('video_candidate:', candidate);
        if (!peerConnection)
            return;
        trace("iceConnectionState:"+peerConnection.iceConnectionState+",iceGatheringState:"+peerConnection.iceGatheringState+",signalingState:"+peerConnection.signalingState);
        peerConnection.addIceCandidate(
            new RTCIceCandidate(candidate)
        ).then(function () {
            trace('peerConnection addIceCandidate success');
        }, function (error) {
            trace('peerConnection failed to add ICE Candidate: ' + error.toString());
        });
    },
    desc: function (mess) {
        var desc = mess.mess;
        var peerConnection = this.peerConnection[mess.sender.user_id];

        //设置远程描述
        trace('setRemoteDescription start');
        trace("iceConnectionState:"+peerConnection.iceConnectionState+",iceGatheringState:"+peerConnection.iceGatheringState+",signalingState:"+peerConnection.signalingState);

        peerConnection.setRemoteDescription(desc).then(function () {
            trace('setRemoteDescription complete');
        }, function (error) {
            trace('setRemoteDescription failed: ' + error.toString());
        });
    },
    offer_desc: function (mess) {
        this.desc(mess);
        var _this = this;
        var peerConnection = this.peerConnection[mess.sender.user_id];
        //创建answer
        trace('peerConnection createAnswer start');
        trace("iceConnectionState:"+peerConnection.iceConnectionState+",iceGatheringState:"+peerConnection.iceGatheringState+",signalingState:"+peerConnection.signalingState);

        peerConnection.createAnswer().then(
            function (desc) {
                trace('Answer from peerConnection:\n' + desc.sdp);
                trace('createAnswer setLocalDescription start');
                trace("iceConnectionState:"+peerConnection.iceConnectionState+",iceGatheringState:"+peerConnection.iceGatheringState+",signalingState:"+peerConnection.signalingState);

                //设置本地描述
                peerConnection.setLocalDescription(desc).then(function () {
                    trace('createAnswer setLocalDescription complete');
                    //回传SDP
                    messageHelper.sendMessage(PERSONAL_VIDEO_ANSWER_DESC, user.user_id, mess.sender.user_id, desc);
                    this.is_connected = true;
                    _this.startTime = _this.getTimestamp();
                }, function (error) {
                    trace('createAnswer setLocalDescription failed: ' + error.toString());
                });
            },
            function (error) {
                trace('createAnswer create session description failed: ' + error.toString());
            }
        );
    },
    answer_desc: function (mess) {
        this.desc(mess);
        this.is_connected = true;
        this.startTime = this.getTimestamp();
    },
    notify_other: function (receiver_id) {
        if (this.count <= 2)
            return;
        for (var i in this.user_video) {
            if (i == receiver_id)
                continue;
            var mess = {
                user_id: receiver_id
            };
            messageHelper.sendMessage(COMMON_VIDEO_NOTIFY, user.user_id, i, mess);
        }
    },
    common_notify: function (mess) {
        var receiver_id = mess.mess.user_id;
        messageHelper.sendMessage(PERSONAL_VIDEO_NOTIFY, user.user_id, receiver_id, "");
        this.connect(true, receiver_id);
    },
    personal_notify: function (mess) {
        var receiver_id = mess.sender.user_id;
        this.connect(false, receiver_id);
    },
    video_open: function (mess) {
        messageHelper.toast(mess.sender.username + "已经打开视频，等待传输...");
    },
    video_close: function (mess) {
        this.close(mess.sender.user_id);
    }
};
videoHelper.init();
