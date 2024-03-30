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
}

String.prototype.replaceMulti = function (search, replace) {
    let str = this;
    search.forEach((value, key) => {
        str = str.replace(new RegExp(value, 'gi'), replace[key]);
    });
    return str;
};

Date.prototype.format = function (format = 'Y-m-d H:i:s') {
    let search = ['Y', 'm', 'd', 'H', 'i', 's'];
    let replace = [this.getFullYear(), this.getMonth() + 1, this.getDate(), this.getHours(), this.getMinutes(), this.getSeconds()];
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
