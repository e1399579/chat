export default class Constant {
    static DEBUG = true;
    static DEFAULT_AVATAR = "/static/chat.png";
    static MAX_LIMITS = 100; //断线最大重连次数
    static COOKIE_EXPIRE_DAYS = 7; //cookie过期天数
    static MAX_IMAGE_SIZE = 1024 * 1024 * 4; //最大上传图片尺寸
    static MAX_MUSIC_SIZE = 1024 * 1024 * 16; //最大音乐尺寸
    static MAX_FILE_SIZE = 1024 * 1024 * 50; //最大音乐尺寸

    // 文本消息
    static MESSAGE_COMMON = 100;
    static MESSAGE_SELF = 101;
    static MESSAGE_OTHER = 102;
    static MESSAGE_PERSONAL = 103;

    // 用户
    static USER_ONLINE = 200;//用户上线
    static USER_QUIT = 201;//用户退出
    static USER_LIST = 202;//用户列表
    static USER_QUERY = 203; //用户查询
    static USER_REGISTER = 204;//用户注册
    static USER_LOGIN = 205; // 用户登录
    static USER_DISABLED = 206;//用户禁用
    static USER_DOWNLINE = 207;//用户下线
    static USER_INCORRECT = 208;//用户名/密码错误
    static USER_REMOVE = 209;//用户移除
    static USER_AVATAR_UPLOAD = 210;//上传头像
    static USER_AVATAR_SUCCESS = 211;//上传成功
    static USER_AVATAR_FAIL = 212;//上传失败
    static USER_ONLINE_TOTAL = 213; // 用户在线数量

    // 图片
    static IMAGE_COMMON = 300;
    static IMAGE_SELF = 301;
    static IMAGE_OTHER = 302;
    static IMAGE_PERSONAL = 303;

    // 音乐
    static MUSIC_COMMON = 500;
    static MUSIC_SELF = 501;
    static MUSIC_OTHER = 502;
    static MUSIC_PERSONAL = 503;

    // 文件
    static FILE_COMMON = 1000;
    static FILE_SELF = 1001;
    static FILE_OTHER = 1002;
    static FILE_PERSONAL = 1003;

    // 群聊
    static GROUP_CREATE = 1100;
    static GROUP_QUERY_LIST = 1101;
    static GROUP_QUERY_MEMBER = 1102;
    static GROUP_QUERY_INFO = 1103;
// static GROUP_JOIN = 1104;
// static GROUP_EXIT = 1105;
// static GROUP_DEL = 1106;

    // RTC
    static RTC_CREATE = 600;
    static RTC_JOIN = 601;
    static RTC_MESSAGE = 602;
    static RTC_OFFLINE = 603;
    static RTC_CLOSE = 604;
    static RTC_EXIT = 605;

    // 历史记录
    static HISTORY_MESSAGE_COMMON = 800;
    static HISTORY_MESSAGE_PERSONAL = 801;

    // 通知
    static ERROR = 900;
    static WARNING = 901;
    static SYSTEM = 902;
    static FILE_UPLOAD_SUCCESS = 903;
}
