class Avatar {
    constructor(element) {
        this.element = element;
        this.canvas = document.createElement("canvas");
        this.ctx = this.canvas.getContext("2d");
        this.isTouchDevice =
            (typeof window !== 'undefined' &&
                typeof navigator !== 'undefined' &&
                ('ontouchstart' in window || navigator.msMaxTouchPoints > 0)
            );
        this.translate_css = "translate(0, 0)";
        this.scale_css = "scale(1)";
        this.left = 0;
        this.top = 0;
        this.scale = 1; //缩放比例
        this.data_url = "";
        this.width = 0; //图片宽度
        this.height = 0; //图片高度
    }

    /**
     * loading动画
     * @param msg   文字信息
     */
    loading(msg) {

    }

    tip(msg) {

    }

    //删除loading动画
    delete(loading) {

    }

    /**
     * 拖拽图片
     * @param {object} options.ele      拖拽区域
     */
    moveImage(options) {
        let mouseOffsetX = 0,
            mouseOffsetY = 0,
            isDown = false;

        //按下
        options.ele.on("mousedown touchstart", (e) => {
            e.preventDefault();
            let touche = this.isTouchDevice ? e.originalEvent.touches[0] : e;
            mouseOffsetX = touche.pageX - this.left;
            mouseOffsetY = touche.pageY - this.top;
            isDown = true;
        });
        //移动
        options.ele.on("mousemove touchmove", (e) => {
            e.preventDefault();
            if (!isDown) return;
            let touche = this.isTouchDevice ? e.originalEvent.touches[0] : e;
            let mouseX = touche.pageX - mouseOffsetX;
            let mouseY = touche.pageY - mouseOffsetY;
            this.left = mouseX;
            this.top = mouseY;
            this.translate_css = "translate(" + mouseX + "px," + mouseY + "px" + ")";

            options.ele.css({
                "-webkit-transform": this.scale_css + " " + this.translate_css,
                "-moz-transform": this.scale_css + " " + this.translate_css,
                "-ms-transform": this.scale_css + " " + this.translate_css,
                "-o-transform": this.scale_css + " " + this.translate_css,
                "transform": this.scale_css + " " + this.translate_css
            });
        });
        //弹起
        options.ele.on("mouseup touchend", (e) => {
            e.preventDefault();
            isDown = false;
        });
        //移出
        options.ele.on("mouseout", (e) => {
            e.preventDefault();
            isDown = false;
        });
    }

    /**
     * 展示图片
     * @param options.fileSelectBtn     文件选择按钮
     * @param options.fileBtn     文件按钮
     * @param options.showEle     图片显示区域
     * @param options.maxSize     图片最大限制  KB
     */
    showImage(options) {
        let defaults = {
            maxSize: 1024
        };
        options = $.extend(defaults, options);
        options.fileSelectBtn.click((e) => {
            options.fileBtn.trigger("click");
        });
        //获取到文件时
        options.fileBtn.change((e) => {
            let files = e.currentTarget.files;
            if (files.length <= 0) return;
            let file = files[0];

            let fileType = /\/(?:jpeg|png|gif)/i;
            let type = file.type.split("/").pop();
            if (!fileType.test(file.type)) {
                this.tip("不支持" + type + "格式");
                return;
            }
            let fileSize = parseInt(file.size / 1024);
            if (fileSize > options.maxSize) {
                this.tip("抱歉，文件最大为 " + options.maxSize + " KB");
                return;
            }
            let reader = new FileReader();
            let loading;

            reader.onloadstart = () => {
                loading = this.loading("读取中，请稍后");
            };
            /*reader.onprogress = () => {
             loading = this.loading("读取中,请稍后");
             };*/
            //读取失败
            reader.onerror = () => {
                this.delete(loading);
                this.tip("读取失败");
            };
            //读取中断
            reader.onabort = () => {
                this.delete(loading);
                this.tip("网络异常");
            };
            //读取成功
            reader.onload = (e) => {
                this.data_url = e.currentTarget.result;        //读取失败时  null   否则就是读取的结果
                let image = new Image();
                image.onload = () => {
                    //旋转fix
                    EXIF.getData(image, () => {
                        let tags = EXIF.getAllTags(this),
                            is_rotate = true;
                        let X = tags.PixelXDimension;
                        let Y = tags.PixelYDimension;
                        switch (tags.Orientation) {
                            case 1: //左
                            default:
                                is_rotate = false;
                                break;
                            case 3: //设备方向右 顺时针旋转180度
                                this.canvas.width = X;
                                this.canvas.height = Y;
                                this.ctx.rotate(Math.PI); //180*(PI/180)
                                this.ctx.drawImage(image, -X, -Y);
                                width = X;
                                height = Y;
                                break;
                            case 6: //上 旋转90度
                                this.canvas.width = Y;
                                this.canvas.height = X;
                                this.ctx.rotate(Math.PI / 2);
                                this.ctx.drawImage(image, 0, -Y);
                                break;
                            case 8: //下 旋转270度
                                this.canvas.width = Y;
                                this.canvas.height = X;
                                this.ctx.rotate(Math.PI * 1.5);
                                this.ctx.drawImage(image, -X, 0);
                                break;
                        }
                        options.fileSelectBtn.addClass("success-linear");
                        if (is_rotate) {
                            this.data_url = this.canvas.toDataURL("images/png");
                            let image2 = new Image();
                            image2.src = this.data_url;
                            this.width = image2.width;
                            this.height = image2.height;
                            options.showEle.html('').append(image2).removeClass("hasImg");
                            image = null;
                        } else {
                            this.width = image.width;
                            this.height = image.height;
                            options.showEle.html('').append(image).removeClass("hasImg");
                        }
                        this.delete(loading);
                    });
                };
                image.src = this.data_url;

                let $range = $('input[type="range"]'),
                    scale = Number($range.val()),
                    step = $range.attr("step"),
                    max = $range.attr("max"),
                    min = $range.attr("min");
                options.showEle.get(0).onmousewheel = (e) => {
                    let target,
                        ee = e || window.event;
                    target = ee.delta ? ee.delta : ee.wheelDelta;
                    if (target > 0) {
                        scale += step;
                        scale = Math.min(scale, max);
                        $range.val(scale);
                        this.ToScale(options.showEle, scale)
                    } else if (target < 0) {
                        scale -= step;
                        scale = Math.max(scale, min);
                        $range.val(scale);
                        this.ToScale(options.showEle, scale)
                    } else {
                        return false;
                    }
                };

                options.fileBtn.blur();
            };
            reader.readAsDataURL(file);
        });
    }

    /**
     * 滑块拖拽缩放
     * @param options.range   滑块
     * @param options.scale   缩放比例
     * @param options.ele     什么区域进行缩放
     */
    rangeToScale(options) {
        let scale = Number(options.range.val());
        options.range.on("mousemove touchmove", (e) => {
            scale = Number($(e.currentTarget).val());
            this.ToScale(options.ele, scale);
        }).prev().on("click touchstart", () => {
            scale -= 0.01;
            options.range.val(scale);
            this.ToScale(options.ele, scale)
        }).next().next().on("click touchstart", () => {
            scale += 0.01;
            options.range.val(scale);
            this.ToScale(options.ele, scale)
        });
    }

    /**
     * 缩放
     * @param ele     什么区域进行缩放
     * @param scale   缩放比例
     */
    ToScale(ele, scale) {
        this.scale_css = "scale(" + scale + ")";
        this.scale = scale;
        ele.css({
            "-webkit-transform": this.scale_css + " " + this.translate_css,
            "-moz-transform": this.scale_css + " " + this.translate_css,
            "-ms-transform": this.scale_css + " " + this.translate_css,
            "-o-transform": this.scale_css + " " + this.translate_css,
            "transform": this.scale_css + " " + this.translate_css
        });
    }

    /**
     * 裁剪
     * @param options.uploadBtn  上传按钮
     * @param options.uploadImageBox  拖拽区域
     * @param options.clipImage  裁剪区域
     * @param options.range  滑块
     */
    clipImage(options) {
        let defaults = {
            uploadBtn: $(".upload-upload-btn"),
            uploadImageBox: $(".move-image"),
            clipImage: $(".clip-image"),
            range: $("#range"),
            clipSuccess(Src) {
            }
        };
        options = $.extend(defaults, options);
        //选择文件

        options.uploadBtn.on("click", () => {
            if (options.uploadImageBox.hasClass("hasImg")) {
                this.tip("请选择图片");
                return;
            }
            let $img = options.uploadImageBox.find("img"),
                $width = options.clipImage.width(),
                $height = options.clipImage.height();
            this.canvas.width = $width;
            this.canvas.height = $height;

            let sx = parseInt(options.clipImage.offset().left - options.uploadImageBox.offset().left),
                sy = parseInt(options.clipImage.offset().top - options.uploadImageBox.offset().top);
            this.ctx.drawImage($img.get(0), sx / this.scale, sy / this.scale, $width / this.scale, $height / this.scale, 0, 0, $width, $height);

            let Src = this.canvas.toDataURL("images/png");
            if (typeof  options.clipSuccess != "function") {
                this.tip("调用失败");
                return;
            }
            options.clipSuccess(Src);
        })
    }
}