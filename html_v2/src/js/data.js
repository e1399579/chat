import Constant from "./constant.js";

export default function () {
    return {
        im: null,
        server_url: process.env.VUE_APP_SERVER_URL,
        upload_url: process.env.VUE_APP_UPLOAD_URL,
        default_avatar_url: Constant.DEFAULT_AVATAR,
        username: "",
        password: "",
        user: {id: 0, displayName: '', avatar: Constant.DEFAULT_AVATAR, is_active: 1},
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
        rtc: null,
        video_flag: true,
        local_media: null,
        remote_medias: new Map(),
        remote_users: new Map(),
        voice_visualizes: [],
        clock_text: "",
        clock_timer: 0,
        candidates: new Map(),
        rtc_key_sender: new Map(),
        rtc_sender_key: new Map(),
        rtc_room_id: "",
        rtc_running: false,
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
}