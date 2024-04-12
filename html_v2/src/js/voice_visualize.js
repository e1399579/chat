// see https://developer.mozilla.org/en-US/docs/Web/API/AnalyserNode
// see https://github.com/mdn/webaudio-examples/blob/main/voice-change-o-matic/scripts/app.js
// see https://mdn.github.io/webaudio-examples/voice-change-o-matic/
export default class VoiceVisualize {
    constructor(stream) {
        const audioCtx = new AudioContext();
        this.analyser = audioCtx.createAnalyser();
        // this.analyser.minDecibels = -90;
        // this.analyser.maxDecibels = -10;
        // this.analyser.smoothingTimeConstant = 0.85;
        this.source = audioCtx.createMediaStreamSource(stream);
        this.source.connect(this.analyser);
        this.drawVisual = null;
    }

    visualize(canvas, visualSetting = "sine-wave") {
        const canvasCtx = canvas.getContext("2d");

        const WIDTH = canvas.width;
        const HEIGHT = canvas.height;
        switch (visualSetting) {
            case "sine-wave":
            {
                this.analyser.fftSize = 512;
                const bufferLength = this.analyser.fftSize;
                console.log(bufferLength);

                // We can use Float32Array instead of Uint8Array if we want higher precision
                // const dataArray = new Float32Array(bufferLength);
                const dataArray = new Uint8Array(bufferLength);

                canvasCtx.clearRect(0, 0, WIDTH, HEIGHT);

                const draw = () => {
                    this.drawVisual = requestAnimationFrame(draw);

                    this.analyser.getByteTimeDomainData(dataArray);

                    canvasCtx.fillStyle = "rgb(200, 200, 200)";
                    canvasCtx.fillRect(0, 0, WIDTH, HEIGHT);

                    canvasCtx.lineWidth = 1;
                    canvasCtx.strokeStyle = "rgb(0, 0, 0)";

                    canvasCtx.beginPath();

                    const sliceWidth = (WIDTH * 1.0) / bufferLength;
                    let x = 0;

                    for (let i = 0; i < bufferLength; i++) {
                        const v = dataArray[i] / 128.0;
                        const y = (v * HEIGHT) / 2;

                        if (i === 0) {
                            canvasCtx.moveTo(x, y);
                        } else {
                            canvasCtx.lineTo(x, y);
                        }

                        x += sliceWidth;
                    }

                    canvasCtx.lineTo(WIDTH, HEIGHT / 2);
                    canvasCtx.stroke();
                };

                draw();
                break;
            }
            case "frequency-bars":
            {
                this.analyser.fftSize = 256;
                const bufferLengthAlt = this.analyser.frequencyBinCount;
                console.log(bufferLengthAlt);

                // See comment above for Float32Array()
                const dataArrayAlt = new Uint8Array(bufferLengthAlt);

                canvasCtx.clearRect(0, 0, WIDTH, HEIGHT);

                const drawAlt = () => {
                    this.drawVisual = requestAnimationFrame(drawAlt);

                    this.analyser.getByteFrequencyData(dataArrayAlt);

                    canvasCtx.fillStyle = "rgb(0, 0, 0)";
                    canvasCtx.fillRect(0, 0, WIDTH, HEIGHT);

                    const barWidth = (WIDTH / bufferLengthAlt) * 2.5;
                    let x = 0;

                    for (let i = 0; i < bufferLengthAlt; i++) {
                        const barHeight = dataArrayAlt[i];

                        canvasCtx.fillStyle = "rgb(" + (barHeight + 100) + ",50,50)";
                        canvasCtx.fillRect(
                            x,
                            HEIGHT - barHeight / 2,
                            barWidth,
                            barHeight / 2
                        );

                        x += barWidth + 1;
                    }
                };

                drawAlt();
                break;
            }
        }
    }

    close() {
        cancelAnimationFrame(this.drawVisual);
        this.source.disconnect();
        this.source = null;
        this.analyser = null;
    }
}