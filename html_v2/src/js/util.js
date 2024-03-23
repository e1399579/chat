import {unpack, pack} from 'msgpackr';

export class DataHelper {
    static encode(obj) {
        return pack(obj);
    }

    static decode(str) {
        return unpack(new Uint8Array(str)); //ArrayBuffer->Uint8Array
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
