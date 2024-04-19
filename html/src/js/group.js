import IMessage from "./imessage.js";
import Constant from "./constant.js";
import IProcessor from "./iprocessor.js";

export class GroupMessage extends IMessage {
    process(vm, mess) {
        switch (mess.type) {
            // 群聊
            case Constant.GROUP_CREATE:
            {
                let group = mess.mess;
                vm.groups.set(group.id, group);
                // 更新联系人
                vm.addGroupContact(group);
                // 创建群聊通知
                let admin_id = group.admin_id;
                // 管理员昵称可能变化，这里查询最新昵称
                vm.getUserAsync(admin_id, (user) => {
                    vm.receiveMessage(mess, user);
                });
                break;
            }
            case Constant.GROUP_QUERY_LIST:
            {
                let groups = mess.mess;
                for (let group_id of Object.keys(groups)) {
                    let group = groups[group_id];
                    group.id = group_id;
                    vm.groups.set(group_id, group);
                    vm.addGroupContact(group, " ");
                }
                break;
            }
            case Constant.GROUP_QUERY_MEMBER:
            {
                let data = mess.mess;
                let group_id = data.group_id;
                let members = data.members;
                let resolve = vm.query_member_next.get(group_id);
                if (resolve) {
                    resolve(members);
                }
                break;
            }
            case Constant.GROUP_QUERY_INFO:
            {
                let data = mess.mess;
                let group_id = data.id;
                let resolve = vm.query_group_next.get(group_id);
                if (resolve) {
                    resolve(data);
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

export class GroupProcessor extends IProcessor {
    getData() {
        return {
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
                {
                    text: "移除大厅",
                    visible: instance => {
                        const { IMUI, contact } = instance;
                        return (contact.user_id !== this.user.id)
                            && (this.user.is_super_admin)
                            && (IMUI.getCurrentContact().id === "0");
                    },
                    click: (e, instance, hide) => {
                        const { contact } = instance;
                        this.sendMessage(Constant.USER_REMOVE, contact.user_id);
                        hide();
                    },
                },
            ],
        };
    }

    getMethods() {
        return {
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
                this.sendMessage(Constant.GROUP_CREATE, 0, mess);
                this.$modal.hide('group-modal');
            },
        };
    }
}