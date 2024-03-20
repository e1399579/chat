#!/bin/bash
// node test_connections.js
// npm install -g ws msgpackr
// linux: export NODE_PATH=/usr/lib/node_modules/
// windows: NODE_PATH=%AppData%\npm\node_modules

const ws = require('ws');
const WebSocket = ws.WebSocket;
const msgpack = require('msgpackr');

let success = 0, fail = 0, closed = 0;
let test_num = process.argv.length > 2 ? parseInt(process.argv[2]) : 255;
let sockets = [];

const USER_ONLINE = 200;//用户上线
const USER_QUIT = 201;//用户退出
const USER_REGISTER = 204;//用户注册
const USER_LOGIN = 205;//用户登录

const ERROR = 900;//错误消息
const WARNING = 901;//警告消息
const SYSTEM = 902;//系统消息

const PORT = 8080;
const PROTOCOL = 'ws://';
const HOST = '192.168.43.165';
const SERVER_URL = PROTOCOL + HOST + ':' + PORT;

class DataHelper {
    static encode(obj) {
        //return JSON.stringify(obj);
        return msgpack.pack(obj);
    }

    static decode(str) {
        //return JSON.parse(str);
        return msgpack.unpack(new Uint8Array(str)); //ArrayBuffer->Uint8Array
    }
}

let success_set = new Set();
function init_sockets(num, sockets) {
    for (let i=0;i<num;i++) {
        let socket = new WebSocket(SERVER_URL, [], {
            handshakeTimeout: 6e4,
        });
        socket.binaryType = 'arraybuffer'; //设为二进制的原始缓冲区
        socket.on('open', () => {
            socket.send(DataHelper.encode({
                type: USER_REGISTER,
                username: 'test_' + Math.random().toString(36).substr(2,10),
                password: 123456
            }));
        });
        socket.on('message', (message) => {
            let dec = DataHelper.decode(message);
            let type = dec.type;
            switch (type) {
                case ERROR:
                case WARNING:
                case SYSTEM:
                    fail++;
                    console.log("success:", success, "fail:", fail, "type:",dec.type);
                    break;
                case USER_LOGIN:
                    ++success;
                    console.log("success:", success, "fail:", fail);
                    socket.ping();
                    break;
                case USER_ONLINE: // 每次登录会通知所有人，去重数量
                    // let prev = success;
                    // let user_id = dec.user.user_id;
                    // success_set.add(user_id);
                    // success = success_set.size;
                    // if (success > prev) {
                    //     console.log("success:", success, "fail:", fail);
                    // }
                    break;
                case USER_QUIT:
                default:

                    break;
            }
        });
        socket.on('close', (code) => {
            ++closed;
            console.log("closed:", closed);
        });
        socket.on('error', (code) => {
            console.log("error", code);
        });
        sockets[i] = socket;
    }
}

init_sockets(test_num, sockets);
