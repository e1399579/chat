<style>
.lemon-message .lemon-message__audio_name {
    padding: 0 0 8px 0;
    text-align: center;
    color: #5C3BB5;
}

.lemon-message::before {
    display: none;
}
</style>
<script>
export default {
    name: "lemonMessageMusic",
    inheritAttrs: false,
    inject: ["IMUI"],
    render() {
        return (
            <lemon-message-basic
                class="lemon-message-audio"
                props={{...this.$attrs}}
                scopedSlots={{
                    content: props => {
                        return (
                            <div>
                                <div class="lemon-message__audio_name">
                                    <i>🎵</i>
                                    <span>{props.fileName}</span>
                                </div>
                                <audio controls src={props.content} title={props.fileName}></audio>
                            </div>
                        );
                    }
                }}
            />
        );
    }
};
</script>