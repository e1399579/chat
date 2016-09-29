/**
 * Created by Administrator on 2016/7/13.
 * By: 李金珂小朋友
 * 文件上传小组件 支持图片 和视频
 * v 0.2
 *  ：）
 */
(function ($) {
    var LjkUpload = function (element) {
        this.element = element;
        this.canvas = document.createElement("canvas");
        this.ctx = this.canvas.getContext("2d");
        this.isPc = this.isPc();
    };
    LjkUpload.prototype = {
        translate_css: "translate(0, 0)",
        scale_css: "scale(1)",
        left: 0,
        top: 0,
        scale: 1, //缩放比例
        data_url: "",
        width: 0, //图片宽度
        height: 0, //图片高度
        //是否为PC   返回 true 或 false
        isPc: function () {
            var userAgent = navigator.userAgent;
            var AgentsArray = ["Android", "iPhone", "SymbianOS", "Windows Phone", "iPad", "iPod"];
            for (var i = 0, len = AgentsArray.length; i < len; i++) {
                if (userAgent.indexOf(AgentsArray[i]) > -1) {
                    return false;
                }
            }
            return true;
        },

        /**
         * loading动画
         * @param msg   文字信息
         */
        loading: function (msg) {
            
        },

        tip: function (msg) {
            
        },

        //删除loading动画
        delete: function (loading) {

        },

        /**
         * 拖拽图片
         * @param {object} options.ele      拖拽区域
         */
        moveImage: function (options) {
            var mouseOffsetX = 0,
                mouseOffsetY = 0,
                _this = this;
            //按下
            options.ele.on("mousedown touchstart", function (e) {
                var touche = _this.isPc ? e : event.targetTouches[0];
                mouseOffsetX = touche.pageX - _this.left;
                mouseOffsetY = touche.pageY - _this.top;

                //移动
                $(this).bind("mousemove touchmove", function (e) {
                    e.preventDefault();
                    var touche = _this.isPc ? e : event.targetTouches[0];
                    var mouseX = touche.pageX - mouseOffsetX;
                    var mouseY = touche.pageY - mouseOffsetY;
                    _this.left = mouseX;
                    _this.top = mouseY;
                    _this.translate_css = "translate(" + mouseX + "px," + mouseY + "px" + ")";

                    $(this).css({
                        "-webkit-transform": _this.scale_css + " " + _this.translate_css,
                        "-moz-transform": _this.scale_css + " " + _this.translate_css,
                        "-ms-transform": _this.scale_css + " " + _this.translate_css,
                        "-o-transform": _this.scale_css + " " + _this.translate_css,
                        "transform": _this.scale_css + " " + _this.translate_css
                    });
                });
            });
            //弹起
            options.ele.on("mouseup touchend", function (e) {
                e.preventDefault();
                $(this).unbind("mousemove touchmove");
            });
            //移出
            options.ele.on("mouseout", function (e) {
                e.preventDefault();
                $(this).unbind("mousemove touchmove");
            });
        },

        /**
         * 展示图片
         * @param options.fileSelectBtn     文件选择按钮
         * @param options.fileBtn     文件按钮
         * @param options.showEle     图片显示区域
         * @param options.isImage     是图片还是文件 true || false
         * @param options.maxSize     图片最大限制  KB
         */
        showImage: function (options) {
            var defaults = {
                isImage: true,
                maxSize: 1024
            };
            options = $.extend(defaults, options);
            var _this = this;
            options.fileSelectBtn.click(function () {
                options.fileBtn.trigger("click");
            });
            //获取到文件时
            options.fileBtn.change(function () {
                if (!window.FileReader) {
                    _this.tip("浏览器版本过低");
                    return;
                }
                if (this.files.length && this.files.length > 1) {
                    _this.tip("只能上传1张图片:)");
                    return;
                }
                //将对象转换为数组 Array.prototype.slice.call(obj);
                //ES6中可以写成  Array.from(obj);
                var files = Array.prototype.slice.call(this.files);

                files.forEach(function (file, i) {
                    //jpeg png gif    "/images/jpeg"     i对大小写不敏感
                    var fileType = options.isImage ? /\/(?:jpeg|png|gif)/i : /\/(?:mp4|rmvb|mp4|wmv|rm|3gp)/i;          //图片或者 视频
                    var type = file.type.split("/").pop();
                    if (!fileType.test(file.type)) {
                        _this.tip("不支持" + type + "格式的视频文件哟");
                        return;
                    }
                    if (options.maxSize != 'undefined' && typeof options.maxSize == 'number') {
                        var fileSize = file.size / 1024;
                        if (fileSize > options.maxSize) {
                            _this.tip("抱歉,文件最大为 " + options.maxSize + " KB");
                            return;
                        }
                    }
                    //HTML 5.1  新增file接口
                    var reader = new FileReader();
                    var loading;

                    reader.onloadstart = function () {
                        loading = _this.loading("读取中,请稍后");
                    };
                    /*reader.onprogress = function () {
                        loading = _this.loading("读取中,请稍后");
                    };*/
                    //读取失败
                    reader.onerror = function () {
                        _this.delete(loading);
                        _this.tip("读取失败");
                    };
                    //读取中断
                    reader.onabort = function () {
                        _this.delete(loading);
                        _this.tip("网络异常!");
                    };
                    //读取成功
                    reader.onload = function () {
                        _this.data_url = this.result;        //读取失败时  null   否则就是读取的结果
                        if (options.isImage === true) {
                            var image = new Image();
                            image.onload = function () {
                                //旋转fix
                                EXIF.getData(image, function () {
                                    var tags = EXIF.getAllTags(this),
                                        is_rotate = true;
                                    switch (tags.Orientation) {
                                        case 1: //左
                                        default:
                                            is_rotate = false;
                                            break;
                                        case 3: //设备方向右 顺时针旋转180度
                                            _this.canvas.width = tags.PixelXDimension;
                                            _this.canvas.height = tags.PixelYDimension;
                                            _this.ctx.rotate(Math.PI); //180*(PI/180)
                                            _this.ctx.drawImage(image, -tags.PixelXDimension, -tags.PixelYDimension);
                                            break;
                                        case 6: //上 旋转90度
                                            _this.canvas.width = tags.PixelYDimension;
                                            _this.canvas.height = tags.PixelXDimension;
                                            _this.ctx.rotate(Math.PI/2);
                                            _this.ctx.drawImage(image, 0, -tags.PixelYDimension);
                                            break;
                                        case 8: //下 旋转270度
                                            _this.canvas.width = tags.PixelYDimension;
                                            _this.canvas.height = tags.PixelXDimension;
                                            _this.ctx.rotate(Math.PI*1.5);
                                            _this.ctx.drawImage(image, -tags.PixelXDimension, 0);
                                            break;
                                    }
                                    options.fileSelectBtn.addClass("success-linear");
                                    if (is_rotate) {
                                        _this.data_url = _this.canvas.toDataURL("images/png");
                                        var image2 = new Image();
                                        image2.src = _this.data_url;
                                        _this.width = image2.width;
                                        _this.height = image2.height;
                                        options.showEle.html('').append(image2).removeClass("hasImg");
                                        image = null;
                                    } else {
                                        _this.width = image.width;
                                        _this.height = image.height;
                                        options.showEle.html('').append(image).removeClass("hasImg");
                                    }
                                    _this.delete(loading);
                                });
                            };
                            image.src = _this.data_url;
                        } else if (options.isImage === false) {
                            var video = $("<video id='video' controls><source src='" + _this.data_url + "' type='video/" + type + "'></video>");
                            var networkState = 0,   //尚未初始化
                                videoReaderState = 0;  //视频就绪状态
                            if (networkState == 2 || networkState == 0 || networkState == 3) {
                                _this.loading("视频初始化");
                            }
                            //0 = NETWORK_EMPTY - 音频/视频尚未初始化
                            // 1 = NETWORK_IDLE - 音频/视频是活动的且已选取资源，但并未使用网络
                            // 2 = NETWORK_LOADING - 浏览器正在下载数据
                            // 3 = NETWORK_NO_SOURCE - 未找到音频/视频来源
                            //readyState == 4  视频已就绪
                            var State = setInterval(function () {
                                networkState = video.get(0).networkState;
                                videoReaderState = video.get(0).readyState;
                                if (networkState != 0 && networkState != 2 && networkState != 3 && videoReaderState == 4) {
                                    _this.delete(loading);
                                    clearInterval(State);
                                    options.showEle.append(video);
                                }
                            }, 1000);
                        }

                        var $range = $('input[type="range"]'),
                            scale = Number($range.val()),
                            step = $range.attr("step"),
                            max = $range.attr("max"),
                            min = $range.attr("min");
                        options.showEle.get(0).onmousewheel = function (e) {
                            var target,
                                ee = e || window.event;
                            target = ee.delta ? ee.delta : ee.wheelDelta;
                            if (target > 0) {
                                scale += step;
                                scale = Math.min(scale, max);
                                $range.val(scale);
                                _this.ToScale(options.showEle, scale)
                            } else if (target < 0) {
                                scale -= step;
                                scale = Math.max(scale, min);
                                $range.val(scale);
                                _this.ToScale(options.showEle, scale)
                            } else {
                                return false;
                            }
                        };

                        options.fileBtn.blur();
                    };
                    //注入图片或文件  转换成base64
                    reader.readAsDataURL(file);      //base64
                    // reader.readAsBinaryString( file );      //二进制
                });
            });
        },

        /**
         * 滑块拖拽缩放
         * @param options.range   滑块
         * @param options.scale   缩放比例
         * @param options.ele     什么区域进行缩放
         */
        rangeToScale: function (options) {
            var _this = this;
            var scale = Number(options.range.val());
            options.range.on("mousemove touchmove", function (e) {
                scale = Number($(this).val());
                _this.ToScale(options.ele, scale);
            }).prev().on("click touchstart", function () {
                scale -= 0.01;
                options.range.val(scale);
                _this.ToScale(options.ele, scale)
            }).next().next().on("click touchstart", function () {
                scale += 0.01;
                options.range.val(scale);
                _this.ToScale(options.ele, scale)
            });
        },

        /**
         * 缩放
         * @param ele     什么区域进行缩放
         * @param scale   缩放比例
         */
        ToScale: function (ele, scale) {
            this.scale_css = "scale(" + scale + ")";
            var _this = this;
            this.scale = scale;
            ele.css({
                "-webkit-transform": _this.scale_css + " " + _this.translate_css,
                "-moz-transform": _this.scale_css + " " + _this.translate_css,
                "-ms-transform": _this.scale_css + " " + _this.translate_css,
                "-o-transform": _this.scale_css + " " + _this.translate_css,
                "transform": _this.scale_css + " " + _this.translate_css
            });
        },

        //裁剪
        /**
         *
         * @param options.uploadBtn  上传按钮
         * @param options.uploadImageBox  拖拽区域
         * @param options.clipImage  裁剪区域
         * @param options.range  滑块
         */
        clipImage: function (options) {
            var _this = this;
            var defaults = {
                uploadBtn: $(".upload-upload-btn"),
                uploadImageBox: $(".move-image"),
                clipImage: $(".clip-image"),
                range: $("#range"),
                clipSuccess: function (Src) {}
            };
            options = $.extend(defaults, options);
            //选择文件

            options.uploadBtn.on("click", function () {
                if (options.uploadImageBox.hasClass("hasImg")) {
                    _this.tip("请选择图片");
                    return;
                }
                var $img = options.uploadImageBox.find("img"),
                    $width = options.clipImage.width(),
                    $height = options.clipImage.height();
                _this.canvas.width = $width;
                _this.canvas.height = $height;

                var sx = parseInt(options.clipImage.offset().left - options.uploadImageBox.offset().left),
                    sy = parseInt(options.clipImage.offset().top - options.uploadImageBox.offset().top);
                _this.ctx.drawImage($img.get(0), sx / _this.scale, sy / _this.scale, $width / _this.scale, $height / _this.scale, 0, 0, $width, $height);

                var Src = _this.canvas.toDataURL("images/png");
                if (typeof  options.clipSuccess != "function") {
                    _this.tip("请使用clipSuccess回调函数:(");
                    return;
                }
                options.clipSuccess(Src);
            })
        }
    };
    window['LjkUpload'] = LjkUpload;
})(jQuery);