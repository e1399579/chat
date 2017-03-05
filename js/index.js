function getCookie(name) {
    let nameEQ = name + "=";
    let ca = document.cookie.split(';');    //把cookie分割成组
    for (let c of ca) {
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
    let exp = new Date();
    exp.setTime(exp.getTime() + COOKIE_EXPIRE_DAYS * 24 * 60 * 60 * 1000);
    document.cookie = name + "=" + escape(value) + ";expires=" + exp.toGMTString();
}

const MESSAGE_COMMON = 100;//公共消息
const MESSAGE_SELF = 101;//本人消息
const MESSAGE_OTHER = 102;//他人消息
const MESSAGE_PERSONAL = 103;//私信

const USER_ONLINE = 200;//用户上线
const USER_QUIT = 201;//用户退出
const USER_LIST = 202;//用户列表
const USER_QUERY = 203; //用户查询
const USER_REGISTER = 204;//用户注册
const USER_LOGIN = 205;//用户登录
const USER_DISABLED = 206;//用户禁用
const USER_DOWNLINE = 207;//用户下线
const USER_INCORRECT = 208;//用户名/密码错误
const USER_REMOVE = 209;//用户移除

const USER_AVATAR_UPLOAD = 210;//上传头像
const USER_AVATAR_SUCCESS = 211;//上传成功
const USER_AVATAR_FAIL = 212;//上传失败

const IMAGE_COMMON = 300;//公共图片
const IMAGE_SELF = 301;//本人图片
const IMAGE_OTHER = 302;//他人图片
const IMAGE_PERSONAL = 303;//私信图片

const EMOTION_COMMON = 400;//公共表情
const EMOTION_SELF = 401;//本人表情
const EMOTION_OTHER = 402;//他人图片
const EMOTION_PERSONAL = 403;//私信表情

const MUSIC_COMMON = 500; //公共音乐
const MUSIC_SELF = 501; //本人音乐
const MUSIC_OTHER = 502; //他人音乐
const MUSIC_PERSONAL = 503; //私信音乐

const VIDEO_PERSONAL_REQUEST = 600; //私信视频请求
const VIDEO_PERSONAL_OFFLINE = 601; //离线
const VIDEO_PERSONAL_ALLOW = 602; //请求通过
const VIDEO_PERSONAL_DENY = 603; //请求拒绝
const VIDEO_PERSONAL_OPEN = 604; //打开摄像头
const VIDEO_PERSONAL_CLOSE = 605; //关闭摄像头
const VIDEO_PERSONAL_END = 606; //传输结束

const VIDEO_PERSONAL_OFFER_DESC = 607;
const VIDEO_PERSONAL_ANSWER_DESC = 608;
const VIDEO_PERSONAL_CANDIDATE = 609;

const VIDEO_COMMON_REQUEST = 700;
const VIDEO_COMMON_NOTIFY = 701;
const VIDEO_PERSONAL_NOTIFY = 702;

const HISTORY_MESSAGE_COMMON = 800; //历史公共消息
const HISTORY_MESSAGE_PERSONAL = 801; //历史个人消息

const ERROR = 900;//错误消息
const WARNING = 901;//警告消息
const SYSTEM = 902;//系统消息

let USER = JSON.parse(getCookie('user')); //当前用户
const DEBUG = true;
const COOKIE_EXPIRE_DAYS = 7; //cookie过期天数
const MAX_LENGTH = 10000; //最大聊天字数
const MAX_IMAGE = 1024 * 1024 * 4; //最大上传图片尺寸
const MAX_UPLOAD = 5; //每次最多上传图片
const COMPRESS_PERCENT = 0.3; //截图压缩比例
const MAX_MUSIC_SIZE = 1024 * 1024 * 16; //最大音乐尺寸
const MAX_LIMITS = 100; //断线最大重连次数
const MATCH_URL = '((https?|ftp|file):\/\/[-A-Za-z0-9+&@#/%?=~_|!:,.;]+[-A-Za-z0-9+&@#/%=~_|])'; //匹配URL
let href = window.location.href; //当前的域名
let end = href.lastIndexOf('/');
const CURRENT_URL = (end == -1) ? href + '/' : href.substring(0, end) + '/'; //删除index.html?之类的字符
const PORT = 8080;
const PROTOCOL = window.location.protocol == 'https:' ? 'wss://' : 'ws://';
const SERVER_URL = PROTOCOL + window.location.host + ':' + PORT;
const SHOW_TIME_DURING = 300;
const FIRST_TIMESTAMP = (new Date()).getTime() / 1000;

let audio = document.createElement("audio");
audio.src = "./media/notification.ogg";
audio.volume = 0;
let music = document.createElement("audio");

let socket = new WebSocket(SERVER_URL);

let templates = new Map([
    ['common_window',
        `<div id="%ID%" class="chat-personal z-index-normal">
                <div class="text-center chat-title chat-title-color box-shadow">
                    <div class="table-cell title-left text-left btn-back"></div>
                    <div class="table-cell title-center"><span class="group-name"></span>(<span class="total">0</span>)</div>
                    <div class="table-cell title-right text-center">
                        <div class="inline-block width-half btn-video"><i class="fa fa-video-camera" aria-hidden="true"></i></div>
                        <div class="inline-block width-half btn-more"><i class="fa fa-paperclip" aria-hidden="true"></i></div>
                    </div>
                </div>
                <div class="chat-content"></div>
                <div class="text-center"><a href="javascript:void(0);" class="query-history">查看历史消息</a></div>
                <div class="chat-features input-group z-index-normal">
                    <span class="input-group-btn">
                        <button type="button" class="btn btn-success-outline btn-emotion">
                            <i class="fa fa-smile-o" aria-hidden="true"></i>
                        </button>
                    </span>
                    <textarea class="form-control mess-input" autofocus rows="1" placeholder="说点什么吧..." maxlength="${MAX_LENGTH}"></textarea>
                    <span class="input-group-btn">
                        <button type="button" class="btn btn-success-outline mess-submit">发送</button>
                    </span>
                </div>
            </div>`],
    ['person_window',
        `<div id="%ID%" class="chat-personal z-index-normal">
                <div class="text-center chat-title chat-title-color box-shadow">
                    <div class="table-cell title-left text-left btn-back"></div>
                    <div class="table-cell title-center">
                        <span class="chat-status chat-status-online"></span>
                        <span class="chat-username"></span>
                    </div>
                    <div class="table-cell title-right text-center">
                        <div class="inline-block width-half btn-video"><i class="fa fa-video-camera" aria-hidden="true"></i></div>
                        <div class="inline-block width-half btn-more"><i class="fa fa-paperclip" aria-hidden="true"></i></div>
                    </div>
                </div>
                <div class="text-center"><a href="javascript:void(0);" class="query-history">查看历史消息</a></div>
                <div class="chat-content"></div>
                <div class="chat-features input-group z-index-normal">
                    <span class="input-group-btn">
                        <button type="button" class="btn btn-success-outline btn-emotion">
                            <i class="fa fa-smile-o" aria-hidden="true"></i>
                        </button>
                    </span>
                    <textarea class="form-control mess-input" autofocus rows="1" placeholder="说点什么吧..." maxlength="${MAX_LENGTH}"></textarea>
                    <span class="input-group-btn">
                        <button type="button" class="btn btn-success-outline mess-submit">发送</button>
                    </span>
                </div>
            </div>`],
    ['time_message', `<div class="text-center chat-system"><span>%TIME%</span></div>`],
    ['history_common_btn', `<div class="text-center"><a href="javascript:;">查看历史消息</a></div>`],
    ['history_personal_btn', `<div class="text-center"><a href="javascript:;">查看历史消息</a></div>`],
    ['common_message',
        `<table class="table-chat">
            <tr>
                <td class="td-head">
                    <div class="head text-center text-muted img-circle">
                        <a href="javascript:void(0);" title="点击头像私聊" data-id="%ID%" onclick="PersonWindow.toShow(this)">%AVATAR%</a>
                    </div>
                </td>
                <td class="td-triangle"><div class="left-triangle-common"></div></td>
                <td><div class="text-left text-sm">%INFO%:</div><div class="bubble-left text-left text-color">%MESSAGE%</div></td>
                <td class="td-blank"></td>
            </tr>
        </table>`],
    ['my_message',
        `<table class="table-chat">
            <tr>
                <td class="td-blank"></td>
                <td><div class="text-right text-sm">%INFO%:</div><div class="bubble-right text-left text-color">%MESSAGE%</div></td>
                <td class="td-triangle"><div class="right-triangle-common"></div></td>
                <td class="td-head"><div class="head text-center text-muted img-circle">%AVATAR%</div></td>
            </tr>
        </table>`],
    ['self_message',
        `<table class="table-chat">
            <tr>
                <td class="td-blank"></td>
                <td><div class="bubble-right text-left">%MESSAGE%</div></td>
                <td class="td-triangle"><div class="right-triangle"></div></td>
                <td class="td-head"><div class="head text-center text-muted img-circle">%AVATAR%</div></td>
            </tr>
        </table>`],
    ['private_message',
        `<table class="table-chat">
            <tr>
                <td class="td-head">
                    <div class="head text-center text-muted img-circle">
                        <a href="javascript:void(0);" title="点击头像私聊">%AVATAR%</a>
                    </div>
                </td>
                <td class="td-triangle"><div class="left-triangle"></div></td>
                <td><div class="bubble-left text-left">%MESSAGE%</div></td>
                <td class="td-blank"></td>
            </tr>
        </table>`],
    ['warning_message',
        `<div class="text-center text-danger">%MESSAGE%</div>`],
    ['system_message',
        `<div class="text-center text-muted">%MESSAGE%</div>`],
    ['welcome_message',
        `<div class="text-center text-info">
            欢迎<a href="javascript:void(0);" data-id="%ID%" onclick="PersonWindow.toShow(this)">%USERNAME%</a>进入聊天室
        </div>`],
    ['quit_message',
        `<div class="text-center text-muted">
            用户%USERNAME%退出聊天室
        </div>`],
    ['welcome_text', '欢迎%USERNAME%进入聊天室'],
    ['quit_text', '用户%USERNAME%退出聊天室'],
    ['original_text', '%MESSAGE%'],
    ['tip',
        `<div class="text-center text-info">欢迎进入聊天室。文明上网，礼貌发言</div>`],
    ['image',
        `<img class="img-responsive img-msg %MSG_PREVIEW%" src="%URL%" alt="%USERNAME%" title="点击图片预览" />`],
    ['music_message',
        `<i class="fa fa-music fa-3x music-color table-cell"></i>
        <div class="music-info table-cell" data-url="%MUSIC_URL%" title="点击播放/暂停" onclick="MusicWindow.playMusic(this, event)">
            <progress class="progress music-color music-progress" value="0" max="100" data-time="0">0%</progress>
            <span class="text-left music-name">%MUSIC_NAME%</span><span class="text-right music-during"></span>
        </div>`],
    ['contact_list_admin',
        `<div id="%ID%" class="contacts-item">
            <div class="head contacts-head text-center text-muted img-circle table-cell">
                <img src="%AVATAR%">
            </div>
            <div class="contacts-content table-cell">
                <div class="contacts-title">%USERNAME%</div>
                <div class="contacts-prop text-muted"><span class="user-status">%USER_STATUS%</span><span class="user-sign">%SIGN%</span></div>
            </div>
            <div class="table-cell contacts-btn">
                <button type="button" class="btn btn-danger-outline user-remove">拉黑</button>
            </div>
        </div>`],
    ['contact_list_user',
        `<div id="%ID%" class="contacts-item">
            <div class="head contacts-head text-center text-muted img-circle table-cell">
                <img src="%AVATAR%">
            </div>
            <div class="contacts-content table-cell">
                <div class="contacts-title">%USERNAME%</div>
                <div class="contacts-prop text-muted"><span class="user-status">%USER_STATUS%</span><span class="user-sign">%SIGN%</span></div>
            </div>
        </div>`],
    ['message_list_item',
        `<div id="%ID%" class="message-item animated">
            <div class="head text-center text-muted img-circle message-head table-cell">
                
            </div>
            <div class="message-content table-cell">
                <div class="message-prop display-table">
                    <span class="message-title table-cell"></span>
                    <span class="message-time table-cell text-muted"></span>
                </div>
                <div class="message-new text-muted">
                    <span class="message-last table-cell text-truncate"></span>
                    <span class="hidden message-badge table-cell bg-info img-circle text-center text-truncate"></span>
                </div>
            </div>
        </div>`],
]);

String.prototype.replaceMulti = function (search, replace) {
    let str = this;
    search.forEach((value, key) => {
        str = str.replace(new RegExp(value, 'gi'), replace[key]);
    });
    return str;
};

Date.prototype.getTimeString = function () {
    return this.toTimeString().substr(0, 8);
};
Date.prototype.getDateString = function () {
    let year = this.getFullYear();
    let month = this.getMonth() + 1;
    let day = this.getDate();
    return `${year}-${month}-${day}`;
};

let H, W;
function flushSize() {
    H = Math.min(window.innerHeight, window.screen.height);
    W = Math.min(window.innerWidth, window.screen.width);
}
flushSize();
if (!('ontouchstart' in document.documentElement))
    window.addEventListener("resize", flushSize);

//主窗口
class MainWindow {
    constructor() {
        this.window = $(".main-box");
        this.navs = this.window.find(".box-nav li");
        this.boxs = this.window.find("ul li");
    }

    init() {
        let swipe = new Swipe(this.window.get(0), {
            speed: 150,
            callback: (e, pos, li) => {
                this.navs.removeClass("box-active").eq(pos).addClass("box-active");
            }
        });
        this.navs.click(function () {
            swipe.slide($(this).index(), 150);
        });
        this.boxs.removeClass("hidden");
    }
}

let mainWindow = new MainWindow();
mainWindow.init();

class Util {
    static toast(content) {
        return layer.open({
            content: content
            , skin: 'msg'
            , time: 3
        });
    }

    static loading(content, time, shadeClose) {
        if (typeof time == "undefined") time = false;
        if (typeof shadeClose == "undefined") shadeClose = true;
        return layer.open({
            type: 2
            , content: content
            , time: time
            , shadeClose: shadeClose
        });
    }
}

class DataHelper {
    static encode(obj) {
        return JSON.stringify(obj);
    }

    static decode(str) {
        return JSON.parse(str);
    }
}

class ImageView {
    constructor() {
        this.container = $("#img-container");
        this.slider = $("#slider");
        this.backdrop = $("#backdrop");
    }

    preview(container) {
        let html = "";
        let index = 0;
        let images = container.find("img.msg-preview");
        let total = images.length;
        this.container.html("");
        $.each(images, (i, n) => {
            let sequence = i + 1;
            html +=
                `<li>
                    <div class="pinch-zoom">
                        <img src="${n.src}" onclick="event.stopPropagation();" />
						</div>
						<div class="description">${n.alt} (${sequence}/${total})</div>
                </li>`;
        });
        this.container.append(html);
        this.container.ready(() => {
            //手指滑动
            let swipe = new Swipe(this.slider.get(0), {
                speed: 400,
                startSlide: index
            });

            //手指缩放
            $("div.pinch-zoom").each(function () {
                new RTP.PinchZoom($(this), {
                    tapZoomFactor: 2,
                    zoomOutFactor: 1.3,
                    animationDuration: 300,
                    animationInterval: 5,
                    maxZoom: 4,
                    minZoom: .5,
                    lockDragAxis: false,
                    use2d: true
                });
            });

            this.slider.click(() => {
                this.hide();
            });


            let max = total - 1;
            let start = swipe.getPos();

            //滑动滚轮切换
            this.container.get(0).onmousewheel = function (e) {
                let ee = e || window.event;
                let target = ee.delta ? ee.delta : ee.wheelDelta;
                if (target < 0) {
                    start++;
                    start = Math.min(start, max);
                } else if (target > 0) {
                    start--;
                    start = Math.max(start, 0);
                }
                swipe.slide(start, 400);
            };

            images.each((i, n) => {
                $(n).click((e) => {
                    this.show();
                    swipe.slide(i, 0);
                });
            });
        });

        images = ""; //释放内存
    }

    show() {
        this.backdrop.fadeIn();
        this.slider.removeClass("hidden bounceOutUp").addClass("animated bounceInDown");
    }

    hide() {
        this.slider.removeClass("bounceInDown").addClass("animated bounceOutUp");
        this.backdrop.fadeOut();
    }
}
let imageView = new ImageView();

class Upload {
    static sendMessage(type, receiver_id = 0, mess = "") {
        let defaults = {
            type: MESSAGE_COMMON,
            receiver_id: 0,
            mess: "",
        };
        socket.send(DataHelper.encode(
            Object.assign(defaults, {
                type,
                sender_id: USER.user_id,
                receiver_id,
                mess,
            })));
    }
}

//消息抽象类
class MessageContext {
    constructor() {
        this.data = new Map();
    }

    process(mess) {
    }

    set window_id(value) {
        this.data.set("window_id", value);
    }

    get window_id() {
        return this.data.get("window_id");
    }
}

//消息装饰器
class MessageDecorator extends MessageContext {
    constructor(message) {
        super();
        this.message = message;
    }
}

class CommonBubbleDecorator extends MessageDecorator {
    constructor(message) {
        super(message);
    }

    process(mess) {
        let html = this.message.process(mess);
        let search = ["%INFO%", "%ID%"];
        let replace = [mess.sender.username, mess.sender_id];
        return html.replaceMulti(search, replace);
    }
}


class PersonBubbleDecorator extends MessageDecorator {
    constructor(message) {
        super(message);
    }

    process(mess) {
        let html = this.message.process(mess);
        let search = [];
        let replace = [];
        return html.replaceMulti(search, replace);
    }
}

class WelcomeDecorator extends MessageDecorator {
    constructor(message) {
        super(message);
    }

    process(mess) {
        let html = this.message.process(mess);
        let search = ["%ID%", "%USERNAME%"];
        let replace = [mess.user.user_id, mess.user.username];
        return html.replaceMulti(search, replace);
    }
}

class AvatarDecorator extends MessageDecorator {
    constructor(message) {
        super(message);
    }

    process(mess) {
        let html = this.message.process(mess);
        let avatar = mess.sender.avatar ? '<img src="./' + mess.sender.avatar + '" />' : '<img src="./images/chat.png" />';
        let search = ["%AVATAR%"];
        let replace = [avatar];
        return html.replaceMulti(search, replace);
    }
}

//反转义消息
class ParseCodeDecorator extends MessageDecorator {
    constructor(message) {
        super(message);
    }

    process(mess) {
        let html = this.message.process(mess);
        let search = [" ", "\n", "\r\n", "\t", "\\\\'", '\\\\\\\\', MATCH_URL]; //空格、换行、制表符...转换标签，保持原貌（适用于颜文字、代码等）
        let replace = ["&nbsp;", "<br />", "<br />", "&nbsp;&nbsp;&nbsp;&nbsp;", "'", "\\", "<a href='$1' target='_blank'>$1</a>"];
        let decorator_mess = mess.mess.replaceMulti(search, replace);
        search = ["%MESSAGE%"];
        replace = [decorator_mess];
        return html.replaceMulti(search, replace);
    }
}

class MusicDecorator extends MessageDecorator {
    constructor(message) {
        super(message);
    }

    process(mess) {
        let html = this.message.process(mess);
        let search = ["%MUSIC_URL%", "%MUSIC_NAME%"];
        let replace = [mess.mess, mess.name];
        let decorator_mess = templates.get("music_message").replaceMulti(search, replace);
        search = ["%MESSAGE%"];
        replace = [decorator_mess];
        return html.replaceMulti(search, replace);
    }
}

class ImageDecorator extends MessageDecorator {
    constructor(message, username, is_preview=0) {
        super(message);
        this.username = username;
        this.is_preview = is_preview;
    }

    process(mess) {
        let html = this.message.process(mess);
        let msg_preview = this.is_preview ? 'msg-preview' : '';
        let search = ["%URL%", "%USERNAME%", "%MSG_PREVIEW%"];
        let replace = [CURRENT_URL + mess.mess, this.username, msg_preview];
        let decorator_mess = templates.get("image").replaceMulti(search, replace);
        search = ["%MESSAGE%"];
        replace = [decorator_mess];
        return html.replaceMulti(search, replace);
    }
}

class NormalMessageDecorator extends MessageDecorator {
    constructor(message) {
        super(message);
    }

    process(mess) {
        let html = this.message.process(mess);
        let search = ["%MESSAGE%"];
        let replace = [mess.mess];
        return html.replaceMulti(search, replace);
    }
}


class TimeTextMessage extends MessageContext {
    constructor(template, window_id, is_history = 0) {
        super();
        this.template = template;
        this.flag = `${window_id}_${is_history}`;
        if (!TimeTextMessage.time) {
            TimeTextMessage.time = new Map();
        }

        if (!TimeTextMessage.time.has(this.flag)) {
            TimeTextMessage.time.set(this.flag, 0);
        }
    }

    process(mess) {
        let html = "";
        if (Math.abs(mess.timestamp - TimeTextMessage.time.get(this.flag)) > SHOW_TIME_DURING) {
            let date = new Date(mess.timestamp * 1000);
            let date_str = date.getDateString();
            let is_today = date_str == (new Date()).getDateString();
            let str = is_today ? date.getTimeString() : `${date_str} ${date.getTimeString()}`;
            html = templates.get("time_message").replace(/%TIME%/g, str);
            TimeTextMessage.time.set(this.flag, mess.timestamp);
        }

        return html + "\n" + this.template;
    }
}

class OriginalMessage extends MessageContext {
    constructor(template) {
        super();
        this.template = template;
    }

    process(mess) {
        return this.template;
    }
}

//===================窗口容器=====================

class EmotionWindow {
    constructor() {
        this.total = 0;//表情总页数
        this.emotion_html = "";
        this.position_html = "";
        this.container = $("#emotion");
        this.dots = [];
        this.emotion_height = 0;
        this.bottom = 80;
        this.speed = 150;
        this.config = [
            {name: "ywz", suffix: '.gif', cols: 4, rows: 2, len: 24, start: 1},
            {name: "ymj", suffix: '.gif', cols: 4, rows: 2, len: 24, start: 0}
        ];
        this.type = EMOTION_COMMON;
        this.receiver_id = '0';
    }

    init() {
        this.build();

        this.dots = this.container.children("ol").children("li");
        this.emotion_height = this.container.height();

        //导航滑动、样式
        let swipe = new Swipe(document.getElementById('emotion'), {
            speed: 200,
            callback: (e, pos, li) => {
                this.dots.removeClass("active").eq(pos).addClass("active");
            }
        });
        this.dots.click(function () {
            swipe.slide($(this).index(), 500);
        });
        this.bindMessage();
    }

    build() {
        this.container.html("<ul></ul><ol></ol>");
        //表情元素
        for (let config of this.config) {
            let name = config.name;
            let suffix = config.suffix;
            let cols = config.cols;
            let rows = config.rows;
            let len = config.len;
            let current = config.start;
            let pages = Math.ceil(len / (cols * rows));//当前表情包页数
            this.total += pages;
            //外层每页li
            for (let j = 0; j < pages; j++) {
                let li = '<li><table class="emotion-table">';
                //每行tr
                for (let k = 0; k < rows; k++) {
                    li += '<tr class="' + name + '">';
                    for (let l = 0; l < cols; l++) {
                        if (current > len) break;//退出td
                        li += '<td><img src="./images/emotion/' + name + '/' + current + suffix +
                            '" alt="' + current + '" title="' + name + '_' + current + suffix + '"></td>';
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
        for (let m = 0; m < this.total - 1; m++) {
            this.position_html += '<li></li>';
        }
        this.position_html += '</li>';
        this.container.children("ol").html(this.position_html);
    }

    close(window) {
        if (this.container.hasClass("hidden")) return;
        let height = H;
        //窗口下降
        window.animate({height}, this.speed, "linear", () => {
            this.container.addClass("hidden");
            window.css("height", "inherit");
        });
        this.container.animate({top: height}, this.speed);
    }

    bindClose(elem, window) {
        elem.bind("click", () => {
            this.close(window);
        });
    }

    bindToggle(elem, window) {
        elem.bind("click", () => {
            if (this.container.hasClass("hidden")) {
                //展开
                this.container.removeClass("hidden");
                let height = H - this.emotion_height;
                //窗口上升
                window.animate({height}, this.speed);
                this.container.animate({top: height}, this.speed);
            } else {
                //折叠
                this.close(window);
            }
        });
    }

    flushProp(type, receiver_id) {
        this.type = type;
        this.receiver_id = receiver_id;
    }

    bindMessage() {
        //表情点击发送消息
        this.container.find("img").each((i, n) => {
            let img = $(n);
            img.click((event) => {
                Upload.sendMessage(this.type, this.receiver_id, img.prop("title"));
            });
        });
    }
}
let emotion = new EmotionWindow();
emotion.init();

class MenuWindow {
    constructor() {
        this.container = $("#menu");
        this.container.bind("click", () => {
            this.hide();
        });
        this.controlBell();
    }

    show() {
        this.container.removeClass("hidden z-index-normal fadeOut").addClass("z-index-top animated fadeIn menu-active");
    }

    hide() {
        this.container.removeClass("fadeIn menu-active").addClass("hidden");
    }

    bindToggle(elem, window) {
        elem.bind("click", () => {
            if (this.container.hasClass("menu-active")) {
                this.hide();
            } else {
                this.show();
            }
        });
    }

    bindHide(elem, window) {
        elem.bind("click", () => {
            this.hide();
        });
    }

    getUploadImageBtn() {
        return this.container.find(".upload-image");
    }

    getUploadMusicBtn() {
        return this.container.find(".upload-music");
    }

    getBellBtn() {
        return this.container.find(".bell-control");
    }

    getVideoChatBtn() {
        return this.container.find(".video-chat");
    }

    controlBell() {
        let btn = this.getBellBtn();
        btn.click((e) => {
            let i = btn.children("i");
            if (i.hasClass("fa-bell-o")) {
                Util.toast("声音：关");
                audio.volume = 0;
                music.volume = 0;
                i.removeClass("fa-bell-o").addClass("fa-bell-slash-o");
            } else {
                Util.toast("声音：开");
                audio.volume = 1;
                music.volume = 1;
                audio.play();
                i.removeClass("fa-bell-slash-o").addClass("fa-bell-o");
            }
        });
    }
}
let menu = new MenuWindow();

class LoginWindow {
    constructor() {
        if (!LoginWindow.cache) {
            this.window = $("#register");
            this.bindSubmit();
            LoginWindow.cache = this;
        }
        return LoginWindow.cache;
    }

    display() {
        this.window.modal({
            backdrop: true,
            keyboard: false
        });
    }

    bindSubmit() {
        this.window.find("[type=submit]").click(() => {
            let username = $("#username").val();
            let password = $("#password").val();
            username = username.substr(0, 30);
            !password && (password = '123456');
            socket.send(DataHelper.encode({
                type: USER_REGISTER,
                username: username,
                password: password
            }));
            return false;
        });
    }

    login(mess) {
        this.window.modal("hide");// 弹框退出
        USER = mess.user;
        setCookie("user", JSON.stringify(USER));//刷新cookie
        Util.toast(`${USER.username}，欢迎回来`);
    }

    incorrect(mess) {
        Util.toast(mess.mess);
        $("#password").val("");//清空密码
        window.setTimeout(function () {
            $("#error").text("");
        }, 1000);
    }
}

class ImageWindow {
    constructor() {
        this.is_locked = false;
        this.image_regexp = /(?:jpe?g|png|gif)/i;
        this.compress_size = MAX_IMAGE / 2;
        this.type = IMAGE_COMMON;
        this.receiver_id = '0';
    }

    init() {
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
        document.addEventListener("drop", (e) => {
            e.preventDefault(); //阻止默认的以链接方式打开
            $("body").css("border", "none");
            this.compress(e.dataTransfer.files, this.upload.bind(this));
        }, false);
    }

    flushProp(type, receiver_id) {
        this.type = type;
        this.receiver_id = receiver_id;
    }

    upload(file) {
        Upload.sendMessage(this.type, this.receiver_id, file);
    }

    compress(files, callback) {
        if (this.is_locked) {
            return Util.toast("正在上传中，请稍候再试");
        }
        let len = files.length;
        if (len > MAX_UPLOAD) {
            return Util.toast("目前最多只能上传" + MAX_UPLOAD + "张图片");
        } else if (0 == len) {
            return;
        }
        this.is_locked = true;
        let index = Util.loading("正在处理，总共" + len + "张图片", len * 2);
        let complete = 0;

        let completeCallBack = (content = "") => {
            if (complete >= len) {
                this.is_locked = false;
                layer.close(index);
                if (content) {
                    Util.toast(content);
                }
            }
        };

        for (let file of files) {
            complete++;
            //压缩图片
            new html5ImgCompress(file, {
                maxWidth: 1000,
                maxHeight: 1000,
                quality: 0.6,
                before: (file) => {
                },
                done: (file, base64) => {
                    if (base64.length > MAX_IMAGE) {
                        Util.toast(file.name + "太大");
                    } else {
                        callback(base64);
                    }
                    completeCallBack("上传完毕，点击图片可以预览哦:-)");
                },
                fail: (file) => {
                    Util.toast("图片压缩失败");
                    completeCallBack();
                },
                complete: (file) => {
                },
                notSupport: (file) => {
                    Util.toast("当浏览器不支持，换Chrome试试吧;-)");
                    completeCallBack();
                }
            });
        }
    }

    bindUpload(elem) {
        let input = elem.next("[type=file]");
        elem.bind("click", (e) => {
            e.stopPropagation();
            Util.toast("最多" + MAX_UPLOAD + "张哦");
            input.trigger("click");
        });
        input.bind("change", (e) => {
            e.stopPropagation();
            this.compress(e.currentTarget.files, (file) => {
                this.upload(file);
            });
        });
    }
}
let image = new ImageWindow();
image.init();
image.bindUpload(menu.getUploadImageBtn());

class MusicWindow {
    constructor() {
        this.type = MUSIC_COMMON;
        this.receiver_id = '0';
        this.size_m = MAX_MUSIC_SIZE / 1024 / 1024;
        MusicWindow.music_timer = 0;
    }

    init() {

    }

    flushProp(type, receiver_id) {
        this.type = type;
        this.receiver_id = receiver_id;
    }

    upload(name, data) {
        Upload.sendMessage(this.type, this.receiver_id, {name, data});
    }

    bindUpload(elem) {
        let input = elem.next("[type=file]");
        elem.bind("click", (e) => {
            e.stopPropagation();
            Util.toast(`最大${this.size_m}M`);
            input.trigger("click");
        });
        input.bind("change", (e) => {
            e.stopPropagation();
            if (0 == e.currentTarget.files.length) {
                return Util.toast("请选择一首音乐");
            }

            let index = Util.loading("正在处理，请稍候...", 3);
            let reader = new FileReader();
            let file = e.currentTarget.files[0];
            reader.onload = (e) => {
                layer.close(index);
                let name = file.name;
                let type = file.type;
                let regexp = /(?:mp3|ogg|wav)/i;
                if (!regexp.test(type.split('/').pop())) {
                    return Util.toast("目前只支持mp3,ogg,wav格式");
                }
                if (file.size > MAX_MUSIC_SIZE) {
                    return Util.toast(`文件大太，目前最大${this.size_m}M`);
                }

                index = Util.loading("上传中，请稍候...", 3);
                this.upload(name, e.currentTarget.result);
                layer.close(index);
                Util.toast("上传音乐完毕");
            };
            reader.readAsDataURL(file);
        });
    }

    static playMusic(btn, e) {
        e.stopPropagation(); //阻止冒泡事件
        let url = href + $(btn).attr("data-url");
        let progress = $(btn).children("progress");
        let music_during = $(btn).children(".music-during");
        if (music.src != url) {
            clearInterval(MusicWindow.music_timer);
            music.pause();
            music.src = url;
        }

        function getDuring(second) {
            let minute = Math.floor(second / 60);
            let sec = Math.ceil(second - minute * 60);
            minute = minute > 9 ? minute : '0' + minute;
            sec = sec > 9 ? sec : '0' + sec;
            return minute + ":" + sec;
        }

        if (music.paused) {
            music.currentTime = progress.attr("data-time"); //保留播放进度
            music.play();
            MusicWindow.music_timer = window.setInterval(() => {
                try {
                    let percent = (music.currentTime / music.duration * 100);
                    progress.val(percent);
                    progress.attr("data-time", music.currentTime);
                    //计算时长
                    music_during.text(getDuring(music.currentTime) + "/" + getDuring(music.duration));
                    if (percent >= 100) {
                        progress.attr("data-time", 0); //清零，下次重新播放
                        clearInterval(MusicWindow.music_timer);
                    }
                } catch (e) {
                    Util.toast("播放失败，请稍候再试...");
                    console.log(e);
                    clearInterval(MusicWindow.music_timer);
                }
            }, 200);
        } else {
            music.pause();
            clearInterval(MusicWindow.music_timer);
        }
    }
}
let musicWindow = new MusicWindow();
musicWindow.bindUpload(menu.getUploadMusicBtn());

function trace(arg) {
    if (!DEBUG)
        return;
    let now = (window.performance.now() / 1000).toFixed(3);
    console.log(now + ': ', arg);
}

//视频
class VideoWindow {
    constructor() {
        this.local_video = null;
        this.local_stream = null;
        this.video_box = $(".video-box");
        this.backdrop = $("#backdrop");
        this.close_btn = this.video_box.find(".video-close");
        this.is_connected = false;
        this.startTime = this.getTimestamp();
        this.count = 1;
        this.volume = 0.3;//音量[0-1]
        this.is_multi = false;
        this.max_video = 4;
        this.user_stream = {};
        this.user_video = {};
        this.is_open = false; //是否打开了媒体，多人聊天时不用重复打开
        this.constraints = {
            audio: {echoCancellation: true, autoGainControl: true, volume: 0.3},
            video: {
                width: {min: 640, ideal: 1280, max: 1920},
                height: {min: 480, ideal: 720, max: 1080}
            }
        };
        this.servers = {
            iceServers: [
                {urls: "turn:ridersam@123.206.83.227", credential: 1399579, username: ""},
                {urls: "stun:stun1.l.google.com:19302"},
                {urls: "stun:stun2.l.google.com:19302"},
                {urls: "stun:stun3.l.google.com:19302"},
                {urls: "stun:stun4.l.google.com:19302"},
                {urls: "stun:stun.ekiga.net"}
            ]
        };
        this.peerConnection = {};
        this.offerOptions = {
            offerToReceiveAudio: 1,
            offerToReceiveVideo: 1
        };

        this.type = VIDEO_COMMON_REQUEST;
        this.receiver_id = '0';
        this.notify_type = VIDEO_COMMON_NOTIFY;
    }

    init() {
        this.local_video = document.createElement("video");
        this.local_video.controls = true;
        this.local_video.autoplay = true;
        this.local_video.muted = true;
        this.local_video.volume = 0; //本地视频播放静音，防止回声
        $(this.video_box).append(this.local_video);

        this.close_btn.bind("click", () => {
            this.closeVideo();
        });
    }

    flushProp(type, receiver_id, notify_type) {
        this.type = type;
        this.receiver_id = receiver_id;
        this.notify_type = notify_type;
    }

    createVideo(user_id) {
        let video = document.createElement("video");
        video.controls = true;
        video.autoplay = true;
        video.volume = this.volume;
        video.className = this.is_multi ? "video-multi" : "video-double-max";
        $(this.video_box).prepend(video);
        this.count++;
        this.user_video[user_id] = video;
        return video;
    }

    closeVideo() {
        layer.open({
            title: ["提示", "background:#FF4351;color:white;"],
            content: "不聊了么？",
            btn: ["关闭", "继续"],
            yes: (index) => {
                layer.close(index);
                this.notify_end();
                this.endTip();
                this.closeAll();
            }
        });
    }

    confirm(type, receiver_id, is_multi = false) {
        let content;
        this.is_multi = is_multi;
        if (!this.is_multi) {
            if (UserObserver.isOnline(receiver_id)) {
                let username = UserObserver.getUser(receiver_id).username;
                content = "将邀请" + username + "视频聊天，是否继续？";
            } else {
                return Util.toast("对方已经离线...");
            }
        } else {
            content = "将邀请多人视频聊天，是否继续？";
        }
        layer.open({
            title: ["提示", "background:#FF4351;color:white;"],
            content: content,
            btn: ["确认", "取消"],
            yes: (index) => {
                layer.close(index);
                Upload.sendMessage(type, receiver_id, 'confirm');
            }
        });
    }

    request(mess, is_multi) {
        let username = mess.sender.username;
        if (mess.sender_id == USER.user_id)
            return;
        let content = is_multi ? username + "邀请您多人视频聊天，是否允许？" : username + "请求和您视频聊天，是否允许？";
        this.is_multi = is_multi;
        layer.open({
            title: ["提示", "background:#FF4351;color:white;"],
            content: content,
            btn: ["允许", "拒绝"],
            yes: (index) => {
                layer.close(index);
                this.closeAll(); //关闭正在聊天的视频
                this.accept(mess);
            },
            no: (index) => {
                layer.close(index);
                this.close(mess.sender_id);
                Upload.sendMessage(VIDEO_PERSONAL_DENY, mess.sender_id, 'request');
            }
        });
    }

    //caller
    allow(mess) {
        this.open();
        this.connect(true, mess.sender_id);
        this.notify_other(mess.sender_id);
    }

    //receiver
    accept(mess) {
        this.open();
        let completeCallBack = () => {
            Upload.sendMessage(VIDEO_PERSONAL_ALLOW, mess.sender_id, 'accept');
        };
        this.connect(false, mess.sender_id, completeCallBack);
    }

    deny(mess) {
        this.is_connected = false;
        this.close(mess.sender_id);
        Util.toast(mess.sender.username + mess.mess);
    }

    offline(mess) {
        this.is_connected = false;
        Util.toast(mess.sender.username + mess.mess);
        this.close(mess.sender_id);
    }

    end(mess) {
        layer.closeAll();
        this.close(mess.sender_id);
    }

    open() {
        this.local_video.className = this.is_multi ? "video-multi" : "video-double-min";
        this.backdrop.fadeIn();
        this.video_box.removeClass("hidden").show();
    }

    endTip() {
        if (!this.is_connected) return;
        let during = ((this.getTimestamp() - this.startTime) / 60).toFixed(1);
        Util.toast("视频聊天结束，总共" + during + "分钟");
    }

    close(user_id) {
        this.count--;
        if (this.user_video.hasOwnProperty(user_id)) {
            this.user_video[user_id].remove();
            this.peerConnection[user_id] && this.peerConnection[user_id].close();
            this.unsetMedia(this.user_stream[user_id]);
            delete this.user_video[user_id];
            delete this.peerConnection[user_id];
            delete this.user_stream[user_id];
        }
        if (this.count <= 1) {
            this.endTip();
            this.closeAll();
        }
    }

    closeAll() {
        this.count = 1;
        this.is_connected = false;
        this.is_open = false;
        this.video_box.addClass("hidden");
        this.backdrop.fadeOut();

        trace('disconnect');
        this.stop();
    }

    stop() {
        this.unsetMedia(this.local_stream);

        for (let key of Object.keys(this.user_stream)) {
            this.unsetMedia(this.user_stream[key]);
        }
        for (let key of Object.keys(this.user_video)) {
            $(this.user_video[key]).remove();
            delete this.user_video[key];
        }

        for (let key of Object.keys(this.peerConnection)) {
            if (this.peerConnection[key]) {
                this.peerConnection[key].close();
                this.peerConnection[key] = null;
            }
        }

        this.user_stream = {};
        this.user_video = {};
        this.peerConnection = {};
        this.local_video.src = "";
        this.local_stream = null;
    }

    unsetMedia(stream) {
        if (!stream)
            return;
        stream.getTracks().forEach(function (track) {
            track.stop();
        });
    }

    notify_end() {
        for (let key of Object.keys(this.user_video)) {
            Upload.sendMessage(VIDEO_PERSONAL_END, key, '');
        }
    }

    getTimestamp() {
        return (new Date()).getTime() / 1000;
    }

    connect(isCaller, receiver_id, completeCallBack) {
        try {
            trace('connect');

            //创建连接
            let peerConnection = new RTCPeerConnection(this.servers);
            this.peerConnection[receiver_id] = peerConnection;
            trace('Created local peer connection object peerConnection');

            let videoTracks, audioTracks;
            let username = UserObserver.getUser(receiver_id).username;
            if (!UserObserver.isOnline(receiver_id)) {
                throw new Error(`${username}已经离线`);
            }

            peerConnection.onicecandidate = (e) => {
                if (e.candidate) {
                    trace('peerConnection candidate: ' + e.candidate.candidate);
                    Upload.sendMessage(VIDEO_PERSONAL_CANDIDATE, receiver_id, e.candidate);
                }
            };

            peerConnection.oniceconnectionstatechange = (e) => {
                trace('peerConnection ICE state: ' + e.currentTarget.iceConnectionState);
                trace('peerConnection ICE state change event: ', e, e.currentTarget);

                //异常退出，则销毁视频
                if (e.currentTarget.iceConnectionState == "failed") {
                    this.close(receiver_id);
                    Util.toast(username + "异常退出");
                }
            };

            peerConnection.onaddstream = (e) => {
                trace('received remote stream');
                let video = this.createVideo(receiver_id);
                video.srcObject = e.stream;
                this.user_stream[receiver_id] = e.stream;
            };

            if (this.is_open) {
                peerConnection.addStream(this.local_stream);
                if (isCaller)
                    this.caller(receiver_id);
                if (typeof completeCallBack == "function") {
                    completeCallBack();
                }
                return;
            }

            //获取本地媒体
            trace('Requesting local stream');
            navigator.mediaDevices.getUserMedia(this.constraints)
                .then((stream) => {
                    this.local_stream = stream;
                    this.is_open = true;
                    Upload.sendMessage(VIDEO_PERSONAL_OPEN, receiver_id, "connect");
                    peerConnection.addStream(stream);
                    trace('Added local stream to peerConnection');

                    if (isCaller) {
                        this.caller(receiver_id);
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
                    this.local_video.srcObject = stream;
                    this.user_stream[receiver_id] = stream;

                    if (typeof completeCallBack == "function") {
                        completeCallBack();
                    }
                })
                .catch((e) => {
                    this.failed(receiver_id, "调用视频失败：" + e.toLocaleString());
                });
        } catch (e) {
            this.failed(receiver_id, "创建连接失败：" + e.toLocaleString());
        }
    }

    failed(receiver_id, message) {
        trace(message);
        Upload.sendMessage(VIDEO_PERSONAL_CLOSE, receiver_id, "failed");
        Util.toast(message);
        this.close(receiver_id);
    }

    caller(receiver_id) {
        let peerConnection = this.peerConnection[receiver_id];
        //创建offer
        trace('peerConnection createOffer start');
        trace("iceConnectionState:" + peerConnection.iceConnectionState + ",iceGatheringState:" + peerConnection.iceGatheringState + ",signalingState:" + peerConnection.signalingState);

        peerConnection.createOffer(this.offerOptions).then(
            (desc) => {
                trace('Offer from peerConnection\n' + desc.sdp);
                trace('createOffer setLocalDescription start');
                trace("iceConnectionState:" + peerConnection.iceConnectionState + ",iceGatheringState:" + peerConnection.iceGatheringState + ",signalingState:" + peerConnection.signalingState);

                //设置本地描述
                peerConnection.setLocalDescription(desc).then(function () {
                    trace('createOffer setLocalDescription complete');

                    //发送SDP到远程
                    Upload.sendMessage(VIDEO_PERSONAL_OFFER_DESC, receiver_id, desc);
                }, function (error) {
                    trace('createOffer setLocalDescription failed: ' + error.toString());
                });
            },
            (error) => {
                trace('createOffer create session description failed: ' + error.toString());
            }
        );
    }

    video_candidate(mess) {
        let candidate = mess.mess;
        let peerConnection = this.peerConnection[mess.sender_id];

        trace('video_candidate:', candidate);
        if (!peerConnection) {
            Util.toast("添加节点失败：未找到对应连接");
            return;
        }

        trace("iceConnectionState:" + peerConnection.iceConnectionState + ",iceGatheringState:" + peerConnection.iceGatheringState + ",signalingState:" + peerConnection.signalingState);
        peerConnection.addIceCandidate(
            new RTCIceCandidate(candidate)
        ).then(() => {
            trace('peerConnection addIceCandidate success');
        }, (error) => {
            trace('peerConnection failed to add ICE Candidate: ' + error.toString());
        });
    }

    desc(mess) {
        let desc = mess.mess;
        let peerConnection = this.peerConnection[mess.sender_id];

        //设置远程描述
        trace('setRemoteDescription start');
        trace("iceConnectionState:" + peerConnection.iceConnectionState + ",iceGatheringState:" + peerConnection.iceGatheringState + ",signalingState:" + peerConnection.signalingState);

        peerConnection.setRemoteDescription(desc).then(() => {
            trace('setRemoteDescription complete');
        }, (error) => {
            trace('setRemoteDescription failed: ' + error.toString());
        });
    }

    offer_desc(mess) {
        let receiver_id = mess.sender_id;
        let peerConnection = this.peerConnection[receiver_id];
        try {
            this.desc(mess);

            //创建answer
            trace('peerConnection createAnswer start');
            trace("iceConnectionState:" + peerConnection.iceConnectionState + ",iceGatheringState:" + peerConnection.iceGatheringState + ",signalingState:" + peerConnection.signalingState);

            peerConnection.createAnswer().then(
                (desc) => {
                    trace('Answer from peerConnection:\n' + desc.sdp);
                    trace('createAnswer setLocalDescription start');
                    trace("iceConnectionState:" + peerConnection.iceConnectionState + ",iceGatheringState:" + peerConnection.iceGatheringState + ",signalingState:" + peerConnection.signalingState);

                    //设置本地描述
                    peerConnection.setLocalDescription(desc).then(() => {
                        trace('createAnswer setLocalDescription complete');
                        //回传SDP
                        Upload.sendMessage(VIDEO_PERSONAL_ANSWER_DESC, receiver_id, desc);
                        this.is_connected = true;
                        this.startTime = this.getTimestamp();
                    }, (error) => {
                        trace('createAnswer setLocalDescription failed: ' + error.toString());
                    });
                },
                (error) => {
                    trace('createAnswer create session description failed: ' + error.toString());
                }
            );
        } catch (e) {
            this.failed(receiver_id, "建立连接失败！" + e.toLocaleString());
        }
    }

    answer_desc(mess) {
        this.desc(mess);
        this.is_connected = true;
        this.startTime = this.getTimestamp();
    }

    notify_other(receiver_id) {
        if (this.count <= 1)
            return;
        for (let key of Object.keys(this.user_video)) {
            if (key == receiver_id)
                continue;
            let mess = {
                user_id: receiver_id
            };
            Upload.sendMessage(VIDEO_COMMON_NOTIFY, key, mess);
        }
    }

    common_notify(mess) {
        let receiver_id = mess.mess.user_id;
        Upload.sendMessage(VIDEO_PERSONAL_NOTIFY, receiver_id, "common_notify");
        this.connect(true, receiver_id);
    }

    personal_notify(mess) {
        let receiver_id = mess.sender_id;
        this.connect(false, receiver_id);
    }

    video_open(mess) {
        Util.toast(mess.sender.username + "已经打开视频，等待传输...");
    }

    video_close(mess) {
        this.close(mess.sender_id);
    }
}

let videoWindow = new VideoWindow();
videoWindow.init();

class SingleWindow {
    constructor(id, initCallBack = '') {
        this.id = id;
        this.is_show = 0;
        this.is_multi = true;
        this.message_type = MESSAGE_COMMON;
        this.emotion_type = EMOTION_COMMON;
        this.image_type = IMAGE_COMMON;
        this.music_type = MUSIC_COMMON;
        this.video_type = VIDEO_COMMON_REQUEST;
        this.notify_type = VIDEO_COMMON_NOTIFY;
        this.history_type = HISTORY_MESSAGE_COMMON;
    }

    getId() {
        return this.id;
    }

    getWindowId() {
        return this.window_id;
    }

    getWindow() {
        return $(`#${this.window_id}`);
    }

    initWindow(initCallBack = '') {
        if (this.getWindow().length <= 0) {
            this.window = this.createWindow();
            this.title_container = this.window.find(".chat-title");
            this.content_container = this.window.find(".chat-content");
            this.features_container = this.window.find(".chat-features");
            this.back_btn = this.window.find(".btn-back");
            this.mess_input = this.window.find(".mess-input");
            this.mess_submit = this.window.find(".mess-submit");
            this.video_btn = this.window.find(".btn-video");
            this.more_btn = this.window.find(".btn-more");
            this.query_history_btn = this.window.find(".query-history");
            this.query_time = 0;
            //绑定事件
            this.back_btn.bind("click", this.hide.bind(this));

            //表情展示
            emotion.bindToggle(this.window.find(".btn-emotion"), this.window);
            emotion.bindClose(this.content_container, this.window);

            //发送消息
            this.bindSubmit();

            //历史查询
            this.bindQuery();

            //菜单展示
            menu.bindToggle(this.more_btn, this);
            menu.bindHide(this.back_btn, this);
            menu.bindHide(this.content_container, this);

            //上传头像

            //上传图片

            //视频聊天
            this.bindVideo();

            //粘贴图片
            this.bindInput();

            //键盘发送
            this.bindKeyboard();

            if (initCallBack) {
                initCallBack(this);
            }
        }
    }

    createWindow() {
        let search = ["%ID%"];
        let replace = [this.window_id];
        let html = templates.get(this.template_name).replaceMulti(search, replace);
        this.container.append(html);
        return this.getWindow();
    }

    write(content) {
        this.content_container.append(content);
    }

    writeHistory(content) {
        this.content_container.prepend(content);
    }

    display() {
        this.is_show = 1;
        this.container.removeClass("hidden");
        this.window.removeClass("slideOutRight z-index-normal").addClass("animated slideInRight z-index-top");
    }

    hide() {
        this.is_show = 0;
        this.window.removeClass("slideInRight").addClass("animated slideOutRight");
        window.setTimeout(() => {
            this.window.removeClass("z-index-top").addClass("z-index-normal");
        }, 1000);
    }

    hidden() {
        this.window.addClass("slideOutLeft");
        window.setTimeout(() => {
            this.window.removeClass("slideOutLeft z-index-top").addClass("z-index-normal slideOutRight");
        }, 1000);
    }

    showEvent(box) {
        this.flushBackBtn(box.getName());
        this.autoBottom();
        emotion.flushProp(this.emotion_type, this.id);
        image.flushProp(this.image_type, this.id);
        musicWindow.flushProp(this.music_type, this.id);
        videoWindow.flushProp(this.video_type, this.id, this.notify_type);
    }

    hideEvent(box) {
        emotion.close(this.window);
    }

    getName() {
        return this.name;
    }

    autoBottom() {
        let container = this.content_container;
        let speed = 100;
        let scrollTop = container[0].scrollHeight - container[0].offsetHeight;
        container.animate({scrollTop, speed});
    }

    imageBottom() {
        let image = this.content_container.find("img.img-msg").last().get(0);
        image.onload = (e) => {
            this.autoBottom();
        };
    }

    isShow() {
        return this.is_show;
    }

    bindSubmit() {
        this.mess_submit.bind("click", () => {
            this.send();
        });
    }

    send() {
        let mess = this.mess_input.val();
        if (!mess) {
            this.mess_input.focus();
            return;
        }
        Upload.sendMessage(this.message_type, this.id, mess);
        this.mess_input.val("");
        this.mess_input.focus();
    }

    bindInput() {
        let loading;
        this.image_flag = true;
        this.mess_input.pastableTextarea(COMPRESS_PERCENT).on("loadImage", () => {
            loading = Util.loading("正在分析，请稍候...");
        }).on("pasteImage", (e, data) => {
            loading = Util.loading('正在上传，请稍候...');
            if (!this.image_flag) {
                return Util.toast('请等待图片上传完成再发送');
            }
            let image = data.dataURL;
            if (image.length > MAX_IMAGE) {
                let size = MAX_IMAGE / 1024 / 1024;
                Util.toast('图片太大，目前只支持' + size + 'M');
                return layer.close(this.image_loading);
            }
            this.image_flag = false;
            Upload.sendMessage(this.image_type, this.id, image);
        }).on("pasteImageStart", () => {
            loading = Util.loading("处理中...");
        });
    }

    bindKeyboard() {
        this.mess_input.bind("keydown", (e) => {
            //ctrl+Enter键换行
            if (e.ctrlKey && e.keyCode == 13) {
                this.mess_input.val(this.mess_input.val() + "\n");
                return;
            }
            //Enter键发送
            if (e.keyCode == 13) {
                //阻止默认换行
                e.preventDefault();
                this.send();
            }

        });
    }

    imageUnlock() {
        this.image_flag = true;
        layer.closeAll();
    }

    bindQuery() {
        this.setQueryTime((new Date()).getTime() / 1000);
        this.query_history_btn.on("click", () => {
            let query_time = this.getQueryTime() - 0.0001; //精确到4位，和服务器保持一致，并去除边界的一条
            Upload.sendMessage(this.history_type, this.id, query_time);
            this.query_history_btn.hide().show(300);
        });
    }

    flushQueryTime() {
        let date = new Date();
        date.setTime(this.getQueryTime() * 1000);
        let curr_date = date.toDateString(); //当前日期
        let time = date.getHours() + date.getMinutes() + date.getSeconds();
        let second = (0 == time) ? 86400 : 0; //如果不是整点，则查截止到今天的，否则是前一天的
        let query_time = Date.parse(curr_date) / 1000 - second;
        this.setQueryTime(query_time);

        date.setTime((query_time - 1) * 1000);
        let query_date = date.toLocaleDateString();
        Util.toast("继续点击查" + query_date + "消息");
    }

    setQueryTime(timestamp) {
        this.query_time = timestamp;
    }

    getQueryTime() {
        return this.query_time;
    }

    bindVideo() {
        this.video_btn.bind("click", () => {
            videoWindow.confirm(this.video_type, this.id, this.is_multi);
        });
    }

    flushBackBtn(name) {
        this.back_btn.text(`<${name}`);
    }

    getContentContainer() {
        return this.content_container;
    }

    getBackBtn() {
        return this.back_btn;
    }
}

//私人窗口
class PersonWindow extends SingleWindow {
    constructor(id, initCallBack = '') {
        super(id, initCallBack);
        this.container = $(".chat-box");
        this.template_name = "person_window";

        this.is_multi = false;
        this.window_id = `person_${id}`;
        this.message_type = MESSAGE_PERSONAL;
        this.emotion_type = EMOTION_PERSONAL;
        this.image_type = IMAGE_PERSONAL;
        this.music_type = MUSIC_PERSONAL;
        this.video_type = VIDEO_PERSONAL_REQUEST;
        this.notify_type = VIDEO_PERSONAL_NOTIFY;
        this.history_type = HISTORY_MESSAGE_PERSONAL;

        this.initWindow(initCallBack);

        if (!PersonWindow.windows) {
            PersonWindow.windows = new Map();
        }

        if (!PersonWindow.windows.has(id)) {
            PersonWindow.windows.set(id, this);
        }
        return PersonWindow.windows.get(id);
    }

    flushTitle(user, is_online = 1) {
        this.flushStatus(is_online);
        this.name = user.username;
        this.window.find(".chat-username").text(this.name);
    }

    flushStatus(is_online) {
        let status = is_online ? "chat-status-online" : "chat-status-offline";
        this.window.find(".chat-status").removeClass("chat-status-online chat-status-offline").addClass(status);
    }

    static toShow(btn) {
        let id = $(btn).data("id");
        let singleWindow = new PersonWindow(id);
        let singleWindow2 = new CommonWindow('0');
        singleWindow.showEvent(new MessageListWindow('0'));
        singleWindow.display();

        singleWindow2.hidden();
    }
}

//公共窗口，群聊(多个群ID)
class CommonWindow extends SingleWindow {
    constructor(id, initCallBack = '') {
        super(id, initCallBack);
        this.container = $(".chat-room-box");
        this.template_name = "common_window";

        this.is_multi = true;
        this.window_id = `room_${id}`;
        this.message_type = MESSAGE_COMMON;
        this.emotion_type = EMOTION_COMMON;
        this.music_type = MUSIC_COMMON;
        this.video_type = VIDEO_COMMON_REQUEST;
        this.notify_type = VIDEO_COMMON_NOTIFY;
        this.history_type = HISTORY_MESSAGE_COMMON;

        this.initWindow(initCallBack);

        if (!CommonWindow.windows) {
            CommonWindow.windows = new Map();
        }

        if (!CommonWindow.windows.has(id)) {
            CommonWindow.windows.set(id, this);
        }
        return CommonWindow.windows.get(id);
    }

    flushTitle(group, total) {
        //this.name = group.name;
        this.name = "大厅";
        this.window.find(".group-name").text(this.name);
        this.flushTotal(total);
    }

    flushTotal(total) {
        this.window.find(".total").text(total);
    }
}

//迷你弹窗
class MiniWindow {

}

class Box {
    display() {
        this.window.removeClass("hidden z-index-normal slideOutLeft").addClass("animated slideInLeft z-index-top");
    }

    hide() {
        this.window.removeClass("slideInLeft").addClass("animated slideOutLeft");
        window.setTimeout(() => {
            this.window.removeClass("z-index-top").addClass("z-index-normal");
        }, 1000);
    }

    hideEvent() {

    }

    showEvent() {

    }

    getName() {
        return this.name;
    }

    getItem(item_id = "") {

    }

    bindClick(singleWindow, item_id = "") {
        //列表->窗口事件
        let elem = this.getItem(item_id);
        elem.bind("click", () => {
            this.hideEvent();
            this.hide();

            singleWindow.showEvent(this);
            singleWindow.display();
        });

        //窗口->列表事件
        elem = singleWindow.getBackBtn();
        elem.bind("click", () => {
            this.showEvent();
            this.display();

            singleWindow.hideEvent(this);
            singleWindow.hide();
        });
    }
}

//消息列表窗口
class MessageListWindow extends Box {
    constructor(id, initCallBack = '') {
        super();
        this.window = $(".main-box");
        this.container = $(".message-list");
        this.template_name = "message_list_item";
        this.item_id = `item_${id}`;
        this.name = '消息';
        this.initWindow(initCallBack);

        if (!MessageListWindow.total) {
            MessageListWindow.total = 0;
        }

        if (!MessageListWindow.windows) {
            MessageListWindow.windows = new Map();
        }

        if (!MessageListWindow.windows.has(id)) {
            MessageListWindow.windows.set(id, this);
        }
        return MessageListWindow.windows.get(id);
    }

    getWindowId() {
        return this.item_id;
    }

    getWindow() {
        return this.window;
    }

    getItem() {
        return $(`#${this.item_id}`);
    }

    initWindow(initCallBack = '') {
        if (this.getItem().length <= 0) {
            this.unread = 0;
            this.item = this.createWindow();
            this.head_container = this.item.find(".message-head");
            this.title_container = this.item.find(".message-title");
            this.time_container = this.item.find(".message-time");
            this.badge_container = this.item.find(".message-badge");
            this.last_container = this.item.find(".message-last");

            if (initCallBack) {
                initCallBack(this);
            }
        }
    }

    createWindow() {
        let search = ["%ID%"];
        let replace = [this.item_id];
        let html = templates.get(this.template_name).replaceMulti(search, replace);
        this.container.append(html);
        return this.getItem();
    }

    getUnread() {
        return this.unread;
    }

    getName() {
        let unread = MessageListWindow.total;
        return unread ? `${this.name}(${unread})` : this.name;
    }

    clearUnread() {
        MessageListWindow.total -= this.unread;
        this.unread = 0;
        this.badge_container.addClass("hidden").text("");
    }

    hideEvent() {
        this.clearUnread();
    }

    flushTitle(user, mess) {
        let message = mess.message ? mess.message : '';
        let html = user.avatar ? '<img src="./' + user.avatar + '" />' : '<img src="./images/chat.png" />';
        this.head_container.html(html);
        this.title_container.text(user.username);
        this.time_container.text(mess.time);
        this.last_container.text(message.substr(0, 20));
    }

    flushItemNum() {
        MessageListWindow.total++;
        this.unread++;
        this.badge_container.removeClass("hidden").text(this.unread);
    }
}

//联系人
class ContactsWindow extends Box {
    constructor() {
        super();
        this.name = '联系人';
        this.window = $(".main-box");
        this.role_id = USER.role_id;
        this.container = $(".contacts-list");
        this.template_name = (this.role_id > 0) ?
            "contact_list_admin" : "contact_list_user";
        this.search = ["%ID%", "%USERNAME%", "%USER_STATUS%", "%SIGN%", "%AVATAR%"];
        if (!ContactsWindow.total) {
            ContactsWindow.total = 0;
        }
        if (!ContactsWindow.ids) {
            ContactsWindow.ids = new Set();
        }
    }

    getItemId(id) {
        return `contacts_${id}`;
    }

    getItem(item_id) {
        return $(`#${item_id}`);
    }

    flushList(users) {
        this.clear();
        for (let user of users) {
            this.addUser(user);
        }
    }

    clear() {
        ContactsWindow.total = 0;
        ContactsWindow.ids = new Set();
        this.container.html(""); //清空列表
    }

    getUserStatus(is_online) {
        return is_online ? '[在线]' : '[离线]';
    }


    flushUser(user) {

    }

    addUser(user, prepend=false) {
        ContactsWindow.total++;
        let user_id = user.user_id;
        let id = this.getItemId(user_id);
        let username = user.username;
        let is_online = UserObserver.isOnline(user_id);
        let user_status = this.getUserStatus(is_online);
        let avatar = user.avatar ? CURRENT_URL + user.avatar : "./images/chat.png";
        let replace = [id, username, user_status, "", avatar];
        let html = templates.get(this.template_name).replaceMulti(this.search, replace);
        if (prepend)
            this.container.prepend(html);
        else
            this.container.append(html);

        ContactsWindow.ids.add(user_id);

        let window = new PersonWindow(user_id);
        this.bindClick(window, id);

        //移除按钮
        if (this.role_id > 0) {
            this.bindRemove(user);
        }
    }

    bindRemove(user) {
        let user_id = user.user_id;
        let item = this.getItem(this.getItemId(user_id));
        let btn = item.find(".user-remove");
        btn.bind("click", (e) => {
            e.stopPropagation();
            layer.open({
                title: ["提示", "background:#FF4351;color:white;"],
                content: `确定移除${user.username}？`,
                btn: ["确认", "取消"],
                yes: (index) => {
                    layer.close(index);
                    this.removeUser(user_id);
                    //item.remove();
                }
            });
        });

    }

    removeUser(user_id) {
        Upload.sendMessage(USER_REMOVE, user_id, 'remove');
    }

    flushUserStatus(user_id, is_online) {
        let user_status = this.getUserStatus(is_online);
        let item = this.getItem(this.getItemId(user_id));
        item.find(".user-status").text(user_status);
    }

    static isExists(user_id) {
        return ContactsWindow.ids.has(user_id);
    }
}

class AvatarWindow {
    constructor() {
        this.container = $(".headImg-popup");
        this.backdrop = $("#backdrop");
    }

    init() {
        this.backdrop.bind("click", () => {
            this.hide();
        });
        $(".showImage").bind("click", () => {
            this.hide();
        });

        let ljkUpload = new LjkUpload(this.container);
        let $file_btn = $(".upload-select-btn"),     //文件选择按钮
            $move_image = $(".move-image"),    //裁剪框
            $range = $("#range");               //滑块

        ljkUpload.tip = function (msg) {
            layer.open({
                content: msg
                , skin: 'msg'
                , time: 3
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
            ele: $move_image
        });
        //预览
        ljkUpload.showImage({
            fileSelectBtn: $file_btn,
            fileBtn: $("aside input[type='file']"),
            showEle: $move_image,
            isImage: true,          //是文件 false  默认 true
            maxSize: 1024 * 10            //文件最大限制  KB   默认1M
        });
        //缩放
        ljkUpload.rangeToScale({
            range: $range,
            ele: $move_image
        });
        //裁剪
        ljkUpload.clipImage({
            clipSuccess: function (src) {       //clipSuccess  裁剪成功 返回 base64图片
                let index = ljkUpload.loading("上传中，请稍候");
                let html = '<img src="' + src + '" />';
                $(".showImage").html(html);

                Upload.sendMessage(USER_AVATAR_UPLOAD, 0, src);
                ljkUpload.delete(index);
            }
        });
    }

    display() {
        this.backdrop.fadeIn();
        this.container.removeClass("hidden slideOutUp").addClass("animated slideInDown");
    }

    hide() {
        this.container.removeClass("slideInDown").addClass("animated slideOutUp");
        this.backdrop.fadeOut();
    }
}
let avatar = new AvatarWindow();
avatar.init();

//我的设置
class MyZoneWindow extends Box {
    constructor() {
        super();
        this.window = $(".main-box");
        this.container = $(".my-zone");
        this.avatar_container = this.container.find(".my-head");
        this.username_container = this.container.find(".username");
        this.upload_avatar_btn = this.container.find(".upload-avatar");
    }

    init() {
        this.upload_avatar_btn.bind("click", () => {
            avatar.display();
        });
    }

    flushUserInfo(user) {
        if (user.avatar) {
            this.avatar_container.attr("src", '.' + user.avatar);
        }
        this.username_container.text(user.username);
    }

    flushUserAvatar(avatar) {
        this.avatar_container.attr("src", '.' + avatar);
    }
}
let myZoneWindow = new MyZoneWindow();
myZoneWindow.init();


//=======================通知、观察者======================
class SplSubject {
    constructor() {
        this.obsevers = new Set();
    }

    attach(observer) {
        this.obsevers.add(observer);
    }

    detach(observer) {
        this.obsevers.delete(observer);
    }

    notify() {
        this.obsevers.forEach(
            (observer, key) => {
                observer.update(this);
            }
        );
    }
}

//消息通知
class MessageNotifier extends SplSubject {
    constructor(mess) {
        super();
        this.data = mess;
        this.type = mess.type;
    }

    getData() {
        return this.data;
    }

    setData(mess) {
        this.data = mess;
    }
}

class Observer {
    update(splSubject) {
    }
}

//用户观察者，监测用户信息变化、存储用户信息
class UserObserver extends Observer {
    constructor() {
        super();
        this.storage = localStorage;
        this.version = null;
        if (!UserObserver.users) {
            UserObserver.users = new Map();
            this.initCache();
        }

        if (!UserObserver.online_users) {
            UserObserver.online_users = new Set();
        }
    }

    update(splSubject) {
        let mess = splSubject.getData();
        let user, users;
        switch (splSubject.type) {
            case USER_ONLINE:
                user = mess.user;
                UserObserver.online(user.user_id);
                this.addUser(user);
                break;
            case USER_QUIT:
                user = mess.user;
                UserObserver.downline(user.user_id);
                break;
            case USER_LIST:
                users = mess.users;
                users.forEach((user, key) => {
                    this.addUser(user);
                    UserObserver.online(user.user_id);
                });
                break;
            case USER_QUERY:
                this.addUser(mess.user);
                break;
            case USER_AVATAR_SUCCESS:
                let update = {avatar: mess.mess};
                user = Object.assign(USER, update);
                setCookie('user', JSON.stringify(user));
                USER.avatar = mess.mess;
                this.flushUser(USER);
                break;
            case USER_DOWNLINE://下线
            case USER_REMOVE://移除
            case USER_DISABLED://禁用
                UserObserver.clear();
                USER.is_active = 0;
                this.flushUser(USER);
                Util.loading(mess.mess, false, false);
                break;
            default:
                return;
                break;
        }
    }

    initCache() {
        let user_ids = this.getUserIds();
        for (let user_id of user_ids) {
            let user = JSON.parse(this.storage.getItem(user_id));
            UserObserver.users.set(user_id, user);
        }

        this.version = this.storage.getItem("version");
        if (!this.version) {
            this.storage.setItem("version", "1.0");
            this.version = "1.0";
        }
    }

    getUserIds() {
        let ids = JSON.parse(this.storage.getItem("user_ids"));
        if (ids == null) {
            ids = [];
            this.storage.setItem("user_ids", JSON.stringify(ids));
        }
        return ids;
    }

    addUserId(user_id) {
        let ids = this.getUserIds();
        let set = new Set(ids);
        set.add(user_id);
        ids = [...set];
        this.storage.setItem("user_ids", JSON.stringify(ids));
    }


    addUser(user) {
        let user_id = user.user_id;
        this.addUserId(user_id);
        this.flushUser(user);
    }

    flushUser(user) {
        let user_id = user.user_id;
        this.storage.setItem(user_id, JSON.stringify(user));
        UserObserver.users.set(user_id, user);
    }

    static getUser(user_id) {
        let user = UserObserver.users.get(user_id);
        if (!user) {
            //查询用户信息
            Upload.sendMessage(USER_QUERY, user_id);
            user = false;
        }
        return user;
    }

    static isOnline(user_id) {
        return UserObserver.online_users.has(user_id);
    }

    static online(user_id) {
        UserObserver.online_users.add(user_id);
    }

    static downline(user_id) {
        UserObserver.online_users.delete(user_id);
    }

    static total() {
        return UserObserver.online_users.size; //算上本人
    }

    static clear() {
        UserObserver.online_users.clear();
        UserObserver.users.clear();
    }

    static getUsers(delete_me = true) {
        let users = new Map();
        let sort_list = [];
        UserObserver.users.forEach((value, key) => {
            users.set(key, value);
        });
        if (delete_me) {
            users.delete(USER.user_id); //用副本操作，不影响原来的
        }
        for (let user_id of UserObserver.online_users) {
            if (users.has(user_id)) {
                sort_list.push(users.get(user_id));
                users.delete(user_id);
            }
        }
        for (let [, user] of users) {
            sort_list.push(user);
        }
        return sort_list;
    }

    static isExists(user_id) {
        return UserObserver.users.has(user_id);
    }
}

//消息列表观察者
class MessageListObserver extends Observer {
    constructor() {
        super();
    }

    update(splSubject) {
        let mess = splSubject.getData();
        let id, user, singleWindow, decorator;
        switch (splSubject.type) {
            case MESSAGE_SELF://myself @other
                user = mess.receiver;
                id = user.user_id;
                singleWindow = new PersonWindow(id);
                mess.message = mess.mess;
                break;
            case IMAGE_SELF://myself @other
                user = mess.receiver;
                id = user.user_id;
                singleWindow = new PersonWindow(id);
                mess.message = "[图片]";
                break;
            case EMOTION_SELF:
                user = mess.receiver;
                id = user.user_id;
                singleWindow = new PersonWindow(id);
                mess.message = "[表情]";
                break;
            case MUSIC_SELF:
                user = mess.receiver;
                id = user.user_id;
                singleWindow = new PersonWindow(id);
                mess.message = `[音乐]${mess.name}`;
                break;
            case MESSAGE_OTHER://other @me
                user = mess.sender;
                id = user.user_id;
                singleWindow = new PersonWindow(id);
                mess.message = mess.mess;
                break;
            case IMAGE_OTHER://other @me
                user = mess.sender;
                id = user.user_id;
                singleWindow = new PersonWindow(id);
                mess.message = "[图片]";
                break;
            case EMOTION_OTHER:
                user = mess.sender;
                id = user.user_id;
                singleWindow = new PersonWindow(id);
                mess.message = "[表情]";
                break;
            case MUSIC_OTHER:
                user = mess.sender;
                id = user.user_id;
                singleWindow = new PersonWindow(id);
                mess.message = `[音乐]${mess.name}`;
                break;

            case USER_ONLINE://欢迎消息
                id = '0';
                singleWindow = new CommonWindow(id);
                decorator = new WelcomeDecorator(new OriginalMessage(templates.get("welcome_text")));
                mess.message = decorator.process(mess);
                break;
            case USER_QUIT://退出消息
                id = '0';
                singleWindow = new CommonWindow(id);
                decorator = new WelcomeDecorator(new OriginalMessage(templates.get("quit_text")));
                mess.message = decorator.process(mess);
                break;
            case USER_DOWNLINE:
                id = '0';
                singleWindow = new CommonWindow(id);
                decorator = new NormalMessageDecorator(new OriginalMessage(templates.get("original_text")));
                mess.message = decorator.process(mess);
                break;
            case MESSAGE_COMMON://公共消息
                id = '0';
                singleWindow = new CommonWindow(id);
                mess.message = `${mess.sender.username}:${mess.mess}`;
                break;
            case IMAGE_COMMON://公共消息
                id = '0';
                singleWindow = new CommonWindow(id);
                mess.message = `${mess.sender.username}:[图片]`;
                break;
            case EMOTION_COMMON:
                id = '0';
                singleWindow = new CommonWindow(id);
                mess.message = `${mess.sender.username}:[表情]`;
                break;
            case MUSIC_COMMON:
                id = '0';
                singleWindow = new CommonWindow(id);
                mess.message = `${mess.sender.username}:[音乐]${mess.name}`;
                break;
            default:
                return;
                break;
        }
        let messageList = new MessageListWindow(id, (messageListWindow) => {
            messageListWindow.bindClick(singleWindow);
        });
        if (id == '0') user = {username: "大厅"};
        messageList.flushTitle(user, mess);
        if (!singleWindow.isShow()) {
            messageList.flushItemNum();
        } else {
            messageList.clearUnread();
        }
    }
}

//公共窗口观察者
class CommonWindowObserver extends Observer {
    constructor(is_history = 0) {
        super();
        this.is_history = is_history;
    }

    update(splSubject) {
        let mess = splSubject.getData();
        let id, user, commonWindow, template, window_id, decorator, content, is_self, container;
        let is_image = false;
        user = mess.sender || mess.user;
        id = '0';
        commonWindow = new CommonWindow(id);
        window_id = commonWindow.getWindowId();
        switch (splSubject.type) {
            case USER_ONLINE://欢迎消息
                commonWindow.flushTitle(user, UserObserver.total());
                if (USER.user_id == user.user_id) return;
                template = templates.get("welcome_message");
                decorator = new WelcomeDecorator(new TimeTextMessage(template, window_id, this.is_history));

                break;
            case USER_QUIT://退出消息
                commonWindow.flushTotal(UserObserver.total());
                template = templates.get("quit_message");
                decorator = new WelcomeDecorator(new TimeTextMessage(template, window_id, this.is_history));

                break;
            case USER_LIST:
                commonWindow.flushTotal(UserObserver.total());
                return;
                break;
            case USER_DOWNLINE:
                commonWindow.flushTotal(UserObserver.total());
                return;
                break;
            case MESSAGE_COMMON://公共消息
                commonWindow.flushTotal(UserObserver.total());
                is_self = user.user_id == USER.user_id;
                template = is_self ? templates.get("my_message") : templates.get("common_message");
                decorator = new CommonBubbleDecorator(new AvatarDecorator(new ParseCodeDecorator(new TimeTextMessage(template, window_id, this.is_history))));

                break;
            case IMAGE_COMMON://公共消息
                commonWindow.flushTotal(UserObserver.total());
                is_self = user.user_id == USER.user_id;
                template = is_self ? templates.get("my_message") : templates.get("common_message");
                decorator = new CommonBubbleDecorator(new AvatarDecorator(new ImageDecorator(new TimeTextMessage(template, window_id, this.is_history), user.username, 1)));

                container = commonWindow.getContentContainer();
                commonWindow.imageUnlock();
                is_image = true;
                break;
            case EMOTION_COMMON:
                commonWindow.flushTotal(UserObserver.total());
                is_self = user.user_id == USER.user_id;
                template = is_self ? templates.get("my_message") : templates.get("common_message");
                decorator = new CommonBubbleDecorator(new AvatarDecorator(new ImageDecorator(new TimeTextMessage(template, window_id, this.is_history), user.username)));

                is_image = true;
                break;
            case MUSIC_COMMON:
                is_self = user.user_id == USER.user_id;
                template = is_self ? templates.get("my_message") : templates.get("common_message");
                decorator = new CommonBubbleDecorator(new AvatarDecorator(new MusicDecorator(new TimeTextMessage(template, window_id, this.is_history))));
                break;
            default:
                return;
                break;
        }

        content = decorator.process(mess);
        if (this.is_history) {
            commonWindow.writeHistory(content);
        } else {
            commonWindow.write(content);
            if (is_image) {
                commonWindow.imageBottom();
            } else {
                commonWindow.autoBottom();
            }
        }

        if (container) {
            imageView.preview(container);
        }
    }
}

//私人窗口观察者
class PersonWindowObserver extends Observer {
    constructor(is_history = 0) {
        super();
        this.is_history = is_history;
    }

    update(splSubject) {
        let mess = splSubject.getData();
        let id, user, singleWindow, template, window_id, decorator, content, template_id, container;
        let is_image = false;
        switch (splSubject.type) {
            case USER_ONLINE:
                user = mess.user;
                id = user.user_id;
                singleWindow = new PersonWindow(id);
                singleWindow.flushTitle(user, UserObserver.isOnline(id));
                return;
                break;
            case USER_QUIT:
                user = mess.user;
                id = user.user_id;
                singleWindow = new PersonWindow(id);
                singleWindow.flushStatus(0);
                return;
                break;
            case USER_LIST:
                let users = UserObserver.getUsers();
                for (let user of users) {
                    id = user.user_id;
                    singleWindow = new PersonWindow(id);
                    singleWindow.flushTitle(user, UserObserver.isOnline(id));
                }
                return;
                break;
            case MESSAGE_SELF://myself @other
                user = mess.receiver;
                id = user.user_id;
                singleWindow = new PersonWindow(id);
                window_id = singleWindow.getWindowId();
                template = templates.get("self_message");
                decorator = new PersonBubbleDecorator(new AvatarDecorator(new ParseCodeDecorator(new TimeTextMessage(template, window_id, this.is_history))));

                break;
            case IMAGE_SELF://myself @other
                user = mess.receiver;
                id = user.user_id;
                singleWindow = new PersonWindow(id);
                window_id = singleWindow.getWindowId();
                template = templates.get("self_message");
                decorator = new PersonBubbleDecorator(new AvatarDecorator(new ImageDecorator(new TimeTextMessage(template, window_id, this.is_history), user.username, 1)));

                container = singleWindow.getContentContainer();
                singleWindow.imageUnlock();
                is_image = true;
                break;
            case EMOTION_SELF:
                user = mess.receiver;
                id = user.user_id;
                singleWindow = new PersonWindow(id);
                window_id = singleWindow.getWindowId();
                template = templates.get("self_message");
                decorator = new PersonBubbleDecorator(new AvatarDecorator(new ImageDecorator(new TimeTextMessage(template, window_id, this.is_history), user.username)));

                is_image = true;
                break;
            case MESSAGE_OTHER://other @me
                user = mess.sender;
                id = user.user_id;
                singleWindow = new PersonWindow(id);
                window_id = singleWindow.getWindowId();
                template = templates.get("private_message");
                decorator = new PersonBubbleDecorator(new AvatarDecorator(new ParseCodeDecorator(new TimeTextMessage(template, window_id, this.is_history))));

                audio.play();
                break;
            case IMAGE_OTHER://other @me
                user = mess.sender;
                id = user.user_id;
                singleWindow = new PersonWindow(id);
                window_id = singleWindow.getWindowId();
                template = templates.get("private_message");
                decorator = new PersonBubbleDecorator(new AvatarDecorator(new ImageDecorator(new TimeTextMessage(template, window_id, this.is_history), user.username, 1)));

                container = singleWindow.getContentContainer();
                singleWindow.imageUnlock();
                is_image = true;
                audio.play();
                break;
            case EMOTION_OTHER:
                user = mess.sender;
                id = user.user_id;
                singleWindow = new PersonWindow(id);
                window_id = singleWindow.getWindowId();
                template = templates.get("private_message");
                decorator = new PersonBubbleDecorator(new AvatarDecorator(new ImageDecorator(new TimeTextMessage(template, window_id, this.is_history), user.username)));

                is_image = true;
                audio.play();
                break;
            case MUSIC_SELF:
                user = mess.receiver;
                id = user.user_id;
                singleWindow = new PersonWindow(id);
                window_id = singleWindow.getWindowId();
                template = templates.get("self_message");
                decorator = new PersonBubbleDecorator(new AvatarDecorator(new MusicDecorator(new TimeTextMessage(template, window_id, this.is_history))));
                break;
            case MUSIC_OTHER:
                user = mess.sender;
                id = user.user_id;
                singleWindow = new PersonWindow(id);
                window_id = singleWindow.getWindowId();
                template = templates.get("private_message");
                decorator = new PersonBubbleDecorator(new AvatarDecorator(new MusicDecorator(new TimeTextMessage(template, window_id, this.is_history))));

                audio.play();
                break;
            default:
                return;
                break;
        }
        content = decorator.process(mess);
        if (this.is_history) {
            singleWindow.writeHistory(content);
        } else {
            singleWindow.write(content);
            if (is_image) {
                singleWindow.imageBottom();
            } else {
                singleWindow.autoBottom();
            }
        }

        if (container) {
            imageView.preview(container);
        }

        user = UserObserver.getUser(id);
        let is_online = UserObserver.isOnline(id);
        singleWindow.flushTitle(user, is_online);
    }
}


//迷你弹窗观察者
class MiniWindowObserver extends Observer {
    constructor() {
        super();
    }

    update(splSubject) {
        switch (splSubject.type) {

        }
    }
}

//登录窗口观察者
class LoginWindowObserver extends Observer {
    constructor() {
        super();
    }

    update(splSubject) {
        let mess = splSubject.getData();
        let window = new LoginWindow();
        switch (splSubject.type) {
            case USER_REGISTER://需要注册
                window.display();
                break;
            case USER_INCORRECT://登录出错
                window.incorrect(mess);
                break;
            case USER_LOGIN://已经登录
                window.login(mess);
                break;
            default:
                return;
                break;
        }
    }
}

//联系人
class ContactsWindowObserver extends Observer {
    constructor() {
        super();
    }

    update(splSubject) {
        let contactsWindow = new ContactsWindow();;
        let mess = splSubject.getData();
        let users, user, user_id;
        switch (splSubject.type) {
            case USER_LIST://在线用户列表
            case USER_DOWNLINE:
            case USER_REMOVE:
                users = UserObserver.getUsers();
                contactsWindow.flushList(users);
                break;
            case USER_QUIT:
                //刷新用户状态
                user = mess.user;
                contactsWindow.flushUserStatus(user.user_id, 0);
                break;
            case USER_ONLINE:
                user = mess.user;
                user_id = user.user_id;
                if (user_id == USER.user_id) return;
                contactsWindow.flushUserStatus(user_id, 1);
                if (ContactsWindow.isExists(user_id)) return;
                contactsWindow.addUser(user, true);
                break;
            case ERROR://出错
            case WARNING://警告
            case SYSTEM://系统信息
                Util.toast(mess.mess);
                window.setTimeout(() => {
                    layer.closeAll();
                }, 3000);
                break;
            default:
                return;
                break;
        }
    }
}

class MyZoneWindowObserver extends Observer {
    constructor() {
        super();
    }

    update(splSubject) {
        let mess = splSubject.getData();
        switch (splSubject.type) {
            case USER_LOGIN:
                myZoneWindow.flushUserInfo(mess.user);
                break;
            case USER_AVATAR_SUCCESS:
                myZoneWindow.flushUserAvatar(mess.mess);
                avatar.hide();
                Util.toast("上传成功，发消息试试吧;-)");
                //TODO 更新多个窗口头像：消息列表、对话窗口
                break;
            case USER_AVATAR_FAIL:
                Util.toast(mess.mess);
                break;
        }
    }
}

//视频
class VideoWindowObserver extends Observer {
    constructor() {
        super();
    }

    update(splSubject) {
        let mess = splSubject.getData();
        switch (splSubject.type) {
            case VIDEO_COMMON_REQUEST:
                videoWindow.request(mess, true);
                break;
            case VIDEO_PERSONAL_REQUEST:
                videoWindow.request(mess, false);
                break;
            case VIDEO_PERSONAL_OFFLINE:
                videoWindow.offline(mess);
                break;
            case VIDEO_PERSONAL_ALLOW:
                videoWindow.allow(mess);
                break;
            case VIDEO_PERSONAL_DENY:
                videoWindow.deny(mess);
                break;
            case VIDEO_PERSONAL_OPEN:
                videoWindow.video_open(mess);
                break;
            case VIDEO_PERSONAL_CLOSE:
                videoWindow.video_close(mess);
                break;
            case VIDEO_PERSONAL_END:
                videoWindow.end(mess);
                break;
            case VIDEO_PERSONAL_OFFER_DESC:
                videoWindow.offer_desc(mess);
                break;
            case VIDEO_PERSONAL_ANSWER_DESC:
                videoWindow.answer_desc(mess);
                break;
            case VIDEO_PERSONAL_CANDIDATE:
                videoWindow.video_candidate(mess);
                break;
            case VIDEO_COMMON_NOTIFY:
                videoWindow.common_notify(mess);
                break;
            case VIDEO_PERSONAL_NOTIFY:
                videoWindow.personal_notify(mess);
                break;
            default:
                return;
                break;
        }
    }
}

class MessageHelper {
    constructor() {
        MessageHelper.queue = [];
        MessageHelper.times = 0;
        MessageHelper.limit_times = 3;
        MessageHelper.userObserver = new UserObserver();
        MessageHelper.reconnect_times = 0;
    }

    onOpen() {
        if (typeof USER == 'object') {
            Upload.sendMessage(USER_LOGIN);
        } else {
            let window = new LoginWindow();
            window.display();
        }
    }

    onClose(e) {
        //联系人离线
        if (!USER.is_active) return;
        let d = new Date();
        let date = 'Y-m-d H:i:s';
        let search = ['Y', 'm', 'd', 'H', 'i', 's'];
        let replace = [d.getFullYear(), d.getMonth() + 1, d.getDate(), d.getHours(), d.getMinutes(), d.getSeconds()];
        date = date.replaceMulti(search, replace);

        let index = Util.loading(date + ' 已断线，重试中...', false, false);
        let timer = window.setInterval(() => {
            try {
                //断线重连
                if (MessageHelper.reconnect_times >= MAX_LIMITS) {
                    window.clearInterval(timer);
                    layer.close(index);
                    return Util.toast("无法连接到服务器，请稍候再试");
                }
                if (socket.readyState == WebSocket.OPEN) {
                    window.clearInterval(timer);
                    socket.onclose = messageHelper.onClose;

                    socket.onerror = messageHelper.onError;

                    socket.onmessage = messageHelper.onMessage;

                    messageHelper.onOpen();
                    layer.close(index);

                    MessageHelper.reconnect_times = 0;
                    return;
                }
                socket = new WebSocket(SERVER_URL);
                MessageHelper.reconnect_times++;
            } catch (e) {
                trace(e);
            }
        }, 2000);
    }

    onError(e) {
        trace(e);
        Util.toast("连接服务器出错");
    }

    onMessage(message) {
        let mess = DataHelper.decode(message.data);
        let id = mess.receiver_id;
        let singleWindow, list;
        switch (mess.type) {
            case HISTORY_MESSAGE_COMMON:
                list = mess.mess.reverse();
                for (let one of list) {
                    MessageHelper.doOnMessage(one, 1);
                }
                singleWindow = new CommonWindow(id);
                if (list.length <= 0) {
                    singleWindow.flushQueryTime();
                } else {
                    singleWindow.setQueryTime(list[list.length - 1].timestamp);
                }
                break;
            case HISTORY_MESSAGE_PERSONAL:
                list = mess.mess.reverse();
                for (let one of list) {
                    MessageHelper.doOnMessage(one, 1);
                }
                singleWindow = new PersonWindow(id);
                if (list.length <= 0) {
                    singleWindow.flushQueryTime();
                } else {
                    singleWindow.setQueryTime(list[list.length - 1].timestamp);
                }
                break;
            default:
                MessageHelper.doOnMessage(mess);
        }


        if ((MessageHelper.queue.length > 0) && (MessageHelper.times < MessageHelper.limit_times)) {
            MessageHelper.times++;
            MessageHelper.doOnMessage(MessageHelper.delQueue());
        }
    }

    static doOnMessage(mess, is_history = 0) {
        let notifier = new MessageNotifier(mess);
        let res = true;
        notifier.attach(MessageHelper.userObserver);
        notifier.notify();
        notifier.detach(MessageHelper.userObserver);

        if (mess.hasOwnProperty("sender_id")) {
            res = mess.sender = UserObserver.getUser(mess.sender_id);
        }

        if (mess.hasOwnProperty("receiver_id") && mess.receiver_id != '0') {
            res = mess.receiver = UserObserver.getUser(mess.receiver_id);
        }

        if (!res) {
            MessageHelper.addQueue(mess);
            return;
        }

        if (!is_history) {
            notifier.attach(new LoginWindowObserver());
            notifier.attach(new MessageListObserver());
            notifier.attach(new ContactsWindowObserver());
            notifier.attach(new MiniWindowObserver());
            notifier.attach(new VideoWindowObserver());
            notifier.attach(new MyZoneWindowObserver());
        }
        notifier.attach(new PersonWindowObserver(is_history));
        notifier.attach(new CommonWindowObserver(is_history));

        notifier.notify();
    }

    static addQueue(message) {
        return MessageHelper.queue.push(message);
    }

    static delQueue() {
        return MessageHelper.queue.shift();
    }
}

let messageHelper = new MessageHelper();
socket.onopen = messageHelper.onOpen;
socket.onmessage = messageHelper.onMessage;
socket.onclose = messageHelper.onClose;
socket.onerror = messageHelper.onError;
