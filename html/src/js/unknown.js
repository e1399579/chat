import IMessage from "./imessage.js";

export class UnknownMessage extends IMessage {
    process(vm, mess) {
        vm.$notify({
            group: 'tip',
            text: '未知的消息类型：' + mess.type,
            type: 'warn',
        });
    }
}