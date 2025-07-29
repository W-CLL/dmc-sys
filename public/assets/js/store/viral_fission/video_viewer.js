/**
 * 视频查看器组件
 * 用于在弹窗中显示视频内容
 */
define(['jquery'], function ($) {

    return {

        /**
         * 显示视频弹窗
         * @param {string} videoUrl 视频URL
         * @param {string} videoTitle 视频标题
         * @param {object} options 可选参数
         */
        show: function (videoUrl, videoTitle, options) {
            options = options || {};

            // 默认配置
            var config = {
                modalSize: options.modalSize || 'modal-lg',
                autoplay: options.autoplay || false,
                controls: options.controls !== false,
                width: options.width || '100%',
                maxWidth: options.maxWidth || '800px',
                showDownloadLink: options.showDownloadLink !== false
            };

            // 创建视频弹窗HTML
            var modalHtml = this.createModalHtml(videoUrl, videoTitle, config);

            // 移除已存在的视频弹窗
            $('#videoModal').remove();

            // 添加新的视频弹窗到页面
            $('body').append(modalHtml);

            // 显示弹窗
            $('#videoModal').modal('show');

            // 绑定事件
            this.bindEvents();
        },

        /**
         * 创建弹窗HTML
         */
        createModalHtml: function (videoUrl, videoTitle, config) {
            var autoplayAttr = config.autoplay ? 'autoplay' : '';
            var controlsAttr = config.controls ? 'controls' : '';
            var downloadLinkHtml = config.showDownloadLink ?
                `<a href="${videoUrl}" target="_blank" class="btn btn-info">在新窗口打开</a>
                 <a href="${videoUrl}" download class="btn btn-success">下载视频</a>` : '';

            return `
                <div class="modal fade" id="videoModal" tabindex="-1" role="dialog">
                    <div class="modal-dialog ${config.modalSize}" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                                <h4 class="modal-title">${this.escapeHtml(videoTitle)}</h4>
                            </div>
                            <div class="modal-body" style="text-align: center; padding: 20px;">
                                <div class="video-container" style="position: relative;">
                                    <video ${controlsAttr} ${autoplayAttr} style="width: ${config.width}; max-width: ${config.maxWidth}; height: auto; border-radius: 4px;">
                                        <source src="${videoUrl}" type="video/mp4">
                                        <source src="${videoUrl}" type="video/webm">
                                        <source src="${videoUrl}" type="video/ogg">
                                        <div class="video-error" style="padding: 40px; background: #f5f5f5; border-radius: 4px; color: #666;">
                                            <i class="fa fa-exclamation-triangle" style="font-size: 24px; margin-bottom: 10px;"></i>
                                            <p>您的浏览器不支持视频播放</p>
                                            <a href="${videoUrl}" target="_blank" class="btn btn-primary btn-sm">点击下载视频</a>
                                        </div>
                                    </video>
                                </div>
                                <div class="video-info" style="margin-top: 15px; font-size: 12px; color: #999;">
                                    <span>视频地址：${this.truncateUrl(videoUrl, 50)}</span>
                                </div>
                            </div>
                            <div class="modal-footer">
                                ${downloadLinkHtml}
                                <button type="button" class="btn btn-default" data-dismiss="modal">关闭</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        },

        /**
         * 绑定事件
         */
        bindEvents: function () {
            var self = this;

            // 弹窗关闭时暂停视频并清理
            $('#videoModal').on('hidden.bs.modal', function () {
                var video = $(this).find('video')[0];
                if (video) {
                    video.pause();
                    video.currentTime = 0;
                }
                $(this).remove();
            });

            // 视频加载错误处理
            $('#videoModal video').on('error', function () {
                var $container = $(this).parent();
                var videoUrl = $(this).find('source').first().attr('src');

                $container.html(`
                    <div class="video-error" style="padding: 40px; background: #f5f5f5; border-radius: 4px; color: #666; text-align: center;">
                        <i class="fa fa-exclamation-triangle" style="font-size: 24px; margin-bottom: 10px; color: #f39c12;"></i>
                        <p style="margin-bottom: 15px;">视频加载失败</p>
                        <p style="font-size: 12px; color: #999; margin-bottom: 15px;">可能是网络问题或视频格式不支持</p>
                        <a href="${videoUrl}" target="_blank" class="btn btn-primary btn-sm">在新窗口中尝试打开</a>
                    </div>
                `);
            });

            // 视频加载成功处理
            $('#videoModal video').on('loadeddata', function () {
                console.log('视频加载成功');
            });
        },

        /**
         * HTML转义
         */
        escapeHtml: function (text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function (m) {
                return map[m];
            });
        },

        /**
         * 截断URL显示
         */
        truncateUrl: function (url, maxLength) {
            if (url.length <= maxLength) {
                return url;
            }
            return url.substring(0, maxLength - 3) + '...';
        }
    };
});
