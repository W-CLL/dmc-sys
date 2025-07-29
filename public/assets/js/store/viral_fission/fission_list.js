define(['jquery', 'bootstrap', 'store', 'table', 'form', '../viral_fission/video_viewer'], function ($, undefined, Backend, Table, Form, VideoViewer) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();

            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'viral_fission/fission_list/index',
                    batch_precheck_url: 'viral_fission/fission_list/batchPreCheck',
                    batch_adopt_url: 'viral_fission/fission_list/batchAdopt',
                    single_precheck_url: 'viral_fission/fission_list/singlePreCheck',
                    table: 'adv_derive_material',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                searchFormVisible: true,
                searchFormTemplate: 'customformtpl',
                pageList: [10, 15, 20,50,100],
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: "ID", visible: false},
                        {field: 'adv_id', title: "千川ID", operate: '='},
                        {field: 'strategy_description', title: "描述", operate: '='},
                        {field: 'title', title: "裂变素材名称", operate: 'like'},
                        {field: 'old_material_id', title: "原素材ID", operate: '=',
                                formatter: function(value, row, index) {
                                if (!value) return '-';
                                return '<span class="material-id-hover" data-url="' + row.video_url + '">' + value + '</span>';
                            }},
                        {field: 'strategy_name', title: "裂变策略", operate: false},
                        {field: 'create_time', title: "生成时间", operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                        {
                            field: 'adopt_material_id',
                            title: "视频ID",
                            formatter: function(value, row, index) {
                                if (!value) return '-';
                                return '<span class="material-id-hover" data-url="' + row.adopt_video_url + '">' + value + '</span>';
                            }
                        },
                        {
                            field: 'adopt_status_message',
                            title: "采纳状态",
                            operate: false,
                            formatter: function(value, row, index) {
                                return value === "success" ? '<span class="label label-success">已采纳</span>' : '<span class="label label-danger">未采纳</span>';
                            }
                        },

                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);

        },


        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});

// Add hover event listener after table rendering
$(document).on('mouseenter', '.material-id-hover', function() {
    var videoUrl = $(this).data('url');
    if (!videoUrl) return;

    var videoPlayer = $('#video-player');

    if (videoPlayer.length) {
        // Update the video source if the player already exists
        videoPlayer.find('video').attr('src', videoUrl);
    } else {
        // Create a new video player if it doesn't exist
        var newVideoPlayer = '<div id="video-player" style="position:absolute; z-index:1000; background:#000; padding:10px; border-radius:5px; box-shadow:0 4px 8px rgba(0,0,0,0.2);">' +
                             '<div id="drag-handle" style="width:100%; height:30px; background:#555; color:#fff; font-size:14px; display:flex; align-items:center; justify-content:center; border-radius:5px 5px 0 0; cursor:move;">拖动视频播放器</div>' +
                             '<button id="close-video" style="position:absolute; top:5px; right:5px; width:30px; height:30px; background:#ff4d4d; border:none; border-radius:50%; cursor:pointer; font-size:20px; line-height:40px; text-align:center; display:flex; align-items:center; justify-content:center; color:#fff;">×</button>' +
                             '<video src="' + videoUrl + '" controls autoplay style="width:400px; height:300px; border-radius:0 0 5px 5px;"></video>' +
                             '</div>';

        $('body').append(newVideoPlayer);
    }

    // Adjust the position dynamically based on the hovered element's location
    var elementTop = $(this).offset().top;
    var elementHeight = $(this).height();
    var playerHeight = $('#video-player').outerHeight();
    var windowHeight = $(window).height();

    if (elementTop + elementHeight + playerHeight > windowHeight) {
        // Place the video player above the hovered element if it overlaps with the bottom of the page
        $('#video-player').css({
            top: elementTop - playerHeight - 10,
            left: $(this).offset().left - $('#video-player').outerWidth() - 10
        });
    } else {
        // Default placement below the hovered element
        $('#video-player').css({
            top: elementTop + elementHeight + 10,
            left: $(this).offset().left - $('#video-player').outerWidth() - 10
        });
    }

    // Automatically pin the video player on hover
    $('#video-player').addClass('pinned');

    // Add drag-and-drop functionality restricted to the drag handle
    $('#drag-handle').on('mousedown', function(e) {
        var player = $('#video-player');
        var offsetX = e.pageX - player.offset().left;
        var offsetY = e.pageY - player.offset().top;

        $(document).on('mousemove.videoPlayerDrag', function(e) {
            player.css({
                top: e.pageY - offsetY,
                left: e.pageX - offsetX
            });
        });

        $(document).on('mouseup.videoPlayerDrag', function() {
            $(document).off('.videoPlayerDrag');
        });
    });

    // Bind click event to close button
    $(document).on('click', '#close-video', function() {
        $('#video-player').remove();
    });
});

$(document).on('mouseleave', '.material-id-hover', function() {
    // Do not remove the video player since it is pinned on hover
});
