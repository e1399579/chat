// import {unpack, pack} from 'msgpackr';

export class DataHelper {
    static encode(obj) {
        // return pack(obj);
        return JSON.stringify(obj);
    }

    static decode(str) {
        return JSON.parse(str);
        // return unpack(new Uint8Array(str)); //ArrayBuffer->Uint8Array
    }

    static toObject(obj) {
        let target = {};
        for (let key in obj) {
            target[key] = obj[key];
        }
        return target;
    }

    static buildTraceId() {
        return Math.random().toString(36).substr(2,10);
    }

    static async sha256(blob) {
        // @see https://developer.mozilla.org/zh-CN/docs/Web/API/SubtleCrypto/digest
        let buffer = await blob.arrayBuffer();
        let hashBuffer = await crypto.subtle.digest("SHA-256", buffer);
        let hashArray = Array.from(new Uint8Array(hashBuffer)); // 将缓冲区转换为字节数组
        return hashArray.map((b) => b.toString(16).padStart(2, "0")).join(""); // 将字节数组转换为十六进制字符串
    }
}

String.prototype.replaceMulti = function (search, replace) {
    let str = this;
    search.forEach((value, key) => {
        str = str.replace(new RegExp(value, 'gi'), replace[key]);
    });
    return str;
};

Date.prototype.format = function (format = 'Y-m-d H:i:s') {
    let search = ['Y', 'm', 'd', 'H', 'i', 's', 'y'];
    let replace = [this.getFullYear(), (this.getMonth() + 1).toString().padStart(2, '0'),
        this.getDate().toString().padStart(2, '0'), this.getHours().toString().padStart(2, '0'),
        this.getMinutes().toString().padStart(2, '0'), this.getSeconds().toString().padStart(2, '0'),
        this.getFullYear().toString().padStart(4, '0').substring(2)];
    return format.replaceMulti(search, replace);
};

Date.prototype.formatUTC = function (format = 'Y-m-d H:i:s') {
    let search = ['Y', 'm', 'd', 'H', 'i', 's', 'y'];
    let replace = [this.getUTCFullYear(), (this.getUTCMonth() + 1).toString().padStart(2, '0'),
        this.getUTCDate().toString().padStart(2, '0'), this.getUTCHours().toString().padStart(2, '0'),
        this.getUTCMinutes().toString().padStart(2, '0'), this.getUTCSeconds().toString().padStart(2, '0'),
        this.getUTCFullYear().toString().padStart(4, '0').substring(2)];
    return format.replaceMulti(search, replace);
};

export function generateUUID() {
    let time = new Date().getTime();
    time += performance.now();
    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, function(
        char,
    ) {
        let rand = (time + Math.random() * 16) % 16 | 0;
        time = Math.floor(time / 16);
        return (char === "x" ? rand : (rand & 0x3) | 0x8).toString(16);
    });
}
