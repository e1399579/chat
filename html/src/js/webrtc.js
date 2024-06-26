// @see https://developer.mozilla.org/en-US/docs/Web/API/WebRTC_API/Perfect_negotiation

export default class WebRTC {
    constructor(config = null) {
        // @see https://gist.github.com/mondain/b0ec1cf5f60ae726202e
        // @see https://gist.github.com/sagivo/3a4b2f2c7ac6e1b5267c2f1f59ac6c6b
        // @see https://freestun.net/
        // TEST STUN/TURN SERVER https://icetest.atec-systems.com/
        this.config = config ? config : {
            iceServers: [
                {
                    urls: ["stun:stun.l.google.com:19302", "stun:stun.files.fm:3478"],
                },
                {
                    urls: 'turns:freestun.net:5350',
                    username: 'free',
                    credential: 'free',
                },
            ],
        };
        this.constraints = {
            audio: true,
            video: true
        };

        this.callbacks = {};
        this.pcs = new Map();
        this.stream = null;
        this.timers = new Map();
    }

    setCallbacks(callbacks) {
        const {onTrack, onNegotiateReady, onIceCandidate, onRemoteSteamClose} = callbacks;
        this.callbacks.onTrack = onTrack;
        this.callbacks.onNegotiateReady = onNegotiateReady;
        this.callbacks.onIceCandidate = onIceCandidate;
        this.callbacks.onRemoteSteamClose = onRemoteSteamClose;
    }

    setConstraints(constraints) {
        this.constraints = constraints;
    }

    async open() {
        try {
            if (!this.stream) {
                // [Caller] a. invite: 2. access the webcam and microphone
                this.stream = await navigator.mediaDevices.getUserMedia(this.constraints);
            }
            return this.stream;
        } catch (e) {
            console.error(e);
            switch (e.name) {
                case "NotFoundError":
                    alert("No camera or microphone were found.");
                    break;
                case "SecurityError":
                case "PermissionDeniedError":
                    // Do nothing; this is the same as the user canceling the call.
                    break;
                default:
                    alert(`Error opening your camera or microphone: ${e.message}`);
                    break;
            }
            throw new Error("open media failed");
        }
    }

    create() {
        // [Caller] a. invite: 1. create an RTCPeerConnection
        let pc = new RTCPeerConnection(this.config);
        let key = this.getPeerConnectionLastId();
        this.pcs.set(key, pc);
        pc.ontrack = ({ track, streams }) => {
            track.onunmute = () => {
                // set remote video srcObject
                this.callbacks.onTrack(key, streams);
            };
        };

        pc.onnegotiationneeded = async (e) => {
            try {
                let pc = e.target;
                // [Caller] b. ready to negotiate: 1. create(set) an SDP offer
                // see https://webrtc.github.io/samples/src/content/peerconnection/restart-ice/
                let offer = await pc.createOffer({
                    iceRestart: true,
                });
                offer.sdp = this.replaceSDP(offer.sdp);
                await pc.setLocalDescription(offer);

                // [Caller] b. ready to negotiate: 2. send the offer
                // type: video-offer
                this.callbacks.onNegotiateReady(key, pc.localDescription);
            } catch (e) {
                console.error('onnegotiationneeded error', e);
            }
        };

        pc.onicecandidate = ({ candidate }) => {
            // type: new-ice-candidate
            this.callbacks.onIceCandidate(key, candidate);
        };


        pc.oniceconnectionstatechange = (e) => {
            let pc = e.target;
            console.log('oniceconnectionstatechange', pc.iceConnectionState);
            switch (pc.iceConnectionState) {
                case "closed":
                {
                    this.close(key);
                    break;
                }
                case "failed":
                {
                    pc.restartIce();
                    break;
                }
                case "disconnected":
                {
                    this.timers.set(key, setTimeout((pc) => {
                        console.log('restart ice');
                        pc.restartIce();
                    }, 3000, pc));
                    break;
                }
                case "connected":
                {
                    this.timers.has(key) && clearTimeout(this.timers.get(key));
                    break;
                }
            }
        };

        pc.onicegatheringstatechange = (e) => {
            let pc = e.target;
            console.log('onicegatheringstatechange', pc.iceGatheringState);
        };

        pc.onsignalingstatechange = (e) => {
            let pc = e.target;
            console.log('onsignalingstatechange', pc.signalingState);
            switch (pc.signalingState) {
                case "closed":
                {
                    this.close(key);
                    break;
                }
            }
        }

        return key;
    }

    addTrack(key) {
        let pc = this.pcs.get(key);
        // [Caller] a. invite: 3. add the local stream's tracks
        for (const track of this.stream.getTracks()) {
            pc.addTrack(track, this.stream);
        }
    }

    close(key) {
        let pc = this.pcs.get(key);
        pc.ontrack = null;
        pc.onremovetrack = null;
        pc.onremovestream = null;
        pc.onicecandidate = null;
        pc.oniceconnectionstatechange = null;
        pc.onsignalingstatechange = null;
        pc.onicegatheringstatechange = null;
        pc.onnegotiationneeded = null;

        pc.close();
        pc = null;
        this.pcs.delete(key);
        this.timers.delete(key);

        // stop video track
        this.callbacks.onRemoteSteamClose(key);
    }

    closeAll() {
        this.pcs.forEach((pc, key) => {
            this.close(key);
        });
        this.pcs.clear();
        this.stream = null;
        this.timers.clear();
    }

    getPeerConnectionLastId() {
        return this.pcs.size;
    }

    async handleVideoOfferMsg(description, reuse_key = -1) {
        let key, pc;
        if (-1 === reuse_key) {
            // [Callee] received video-offer: 1. Create an RTCPeerConnection
            key = this.create();
            pc = this.pcs.get(key);
            // [Callee] a. received video-offer: 2. set remote SDP
            await pc.setRemoteDescription(description);
            // [Callee] a. received video-offer: 3. access the webcam and microphone
            // [Callee] a. received video-offer: 4. add the local stream's tracks
            // [Callee] a. received video-offer: 5. create(set) an SDP answer
            await this.open();
            this.addTrack(key);
        } else {
            key = reuse_key;
            pc = this.pcs.get(key);
            await pc.setRemoteDescription(description);
        }

        let answer = await pc.createAnswer();
        answer.sdp = this.replaceSDP(answer.sdp);
        await pc.setLocalDescription(answer);
        // [Callee] a. received video-offer: 6. send the answer
        // type: video-answer
        return {key, description: pc.localDescription};
    }

    async handleVideoAnswerMsg(key, description) {
        try {
            // [Caller] c. received video-answer : 1. create(set) an SDP
            let pc = this.pcs.get(key);
            return await pc.setRemoteDescription(description);
        } catch (e) {
            console.log('setRemoteDescription error', e);
        }
    }

    async handleNewICECandidateMsg(key, candidate) {
        try {
            let pc = this.pcs.get(key);
            return await pc.addIceCandidate(candidate);
        } catch (e) {
            console.log('addIceCandidate error', e);
        }
    }

    replaceSDP(sdp) {
        // audio quality https://stackoverflow.com/questions/46063374/is-it-really-possible-for-webrtc-to-stream-high-quality-audio-without-noise
        return sdp.replace('useinbandfec=1', 'useinbandfec=1; stereo=1; maxaveragebitrate=128000');
    }
}