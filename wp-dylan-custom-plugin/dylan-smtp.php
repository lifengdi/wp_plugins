<?php
/**
 * 评论回复邮件通知
 */
function comment_reply_email_notification( $comment_ID, $comment_approved = null, $commentdata = null ) {
    // ========== 1. 统一钩子参数处理 ==========
    if ( is_array( $comment_approved ) ) {
        $commentdata = $comment_approved;
        $comment_approved = null;
    }

    // ========== 2. 基础数据校验 ==========
    $comment = get_comment( $comment_ID );
    if ( ! $comment || is_wp_error( $comment ) ) {
        return;
    }
    if ( empty( $comment->comment_parent ) || $comment->comment_parent == 0 ) {
        return;
    }
    if ( $comment->comment_approved !== '1' ) {
        return;
    }

    // ========== 3. 父评论数据校验 ==========
    $parent_comment = get_comment( $comment->comment_parent );
    if ( ! $parent_comment || is_wp_error( $parent_comment ) ) {
        return;
    }
    $parent_email = trim( $parent_comment->comment_author_email );
    if ( empty( $parent_email ) || ! is_email( $parent_email ) ) {
        return;
    }

    // ========== 4. 文章数据校验 ==========
    $post = get_post( $comment->comment_post_ID );
    if ( ! $post || is_wp_error( $post ) ) {
        return;
    }
    $post_title = $post->post_title ?: '未命名文章';

    // ========== 5. 站点/评论者信息获取 ==========
    $site_name = get_bloginfo( 'name' ) ?: 'WordPress';
    $site_url = esc_url( get_bloginfo( 'url' ) );
    $comment_link = esc_url( get_comment_link( $parent_comment ) );

    // 回复人（新评论者）的邮箱和头像（Cravatar）
    $reply_email = trim( $comment->comment_author_email );
    $reply_avatar = esc_url(
        'https://cn.cravatar.com/avatar/'. md5( strtolower( $reply_email ) ) .'?s=32&d=identicon&r=g'
    );

    // ========== 6. 构建邮件内容 ==========
    $subject = sprintf( '您在《%s》的评论有新回复 - %s', esc_html( $post_title ), $site_name );

    $message = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>您的评论有新回复</title>
    <style>
        body { background: #f8f5f0; padding: 20px !important; font-family: "微软雅黑", "PingFang SC", Arial, sans-serif; }
        .email-wrapper { max-width: 750px; margin: 0 auto; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); overflow: hidden; }
        .header { padding: 20px 0; text-align: center; border-bottom: 1px solid #f0f0f0; }
        .header .logo { display: inline-flex; align-items: center; gap: 8px; font-size: 18px; font-weight: bold; color: #333; text-decoration: none; }
        .title-bar { padding: 10px 20px; font-size: 16px; color: #333; border-bottom: 1px solid #f0f0f0; text-align: center;}
        .comment-section { padding: 10px 20px; }
        .comment-item { margin-bottom: 30px; display: flex; flex-direction: column; align-items: flex-end; }
        .comment-item.reply { align-items: flex-start; }
        .comment-meta { font-size: 14px; color: #999; margin-bottom: 10px; width: 100%; text-align: center !important; }
        .comment-author-wrap { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
        .comment-item .comment-author-wrap { justify-content: flex-end; }
        .comment-item.reply .comment-author-wrap { justify-content: flex-start; }
        .comment-author { font-size: 15px; font-weight: 500; color: #333; }

        /* 强制头像正圆（兼容所有端） */
        .comment-avatar {
            width: 32px !important;
            height: 32px !important;
            border-radius: 50% !important;
            flex-shrink: 0 !important;
            object-fit: cover !important;
            border: 0 none !important;
            display: block !important;
        }

        .comment-bubble { background: #f0f0f0; padding: 12px 16px; border-radius: 8px; line-height: 1.6; font-size: 15px; color: #333; max-width: 80%; }
        .comment-item.reply .comment-bubble { background: #e6f3ff; }
        .action-btn { display: block; width: 160px; line-height: 1.5; padding: 10px 0; background: #21759b; color: #fff; text-align: center; border-radius: 4px; text-decoration: none; margin: 30px auto; font-size: 16px; }
        .footer { padding: 10px 20px;  border-top: 1px solid #f0f0f0; }

        /* 移动端适配 */
        @media (max-width: 600px) {
            .comment-section { padding: 15px 20px; }
            .comment-avatar { width: 32px !important; height: 32px !important; }
            .comment-bubble { max-width: 90%; }
            .action-btn { width: 140px; font-size: 14px; }
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <!-- 头部 -->
        <div class="header">
            <a href="'. $site_url .'" class="logo" target="_blank">
                <span>'. esc_html($site_name) .'</span>
            </a>
        </div>

        <!-- 标题 -->
        <div class="title-bar">
            敬启者，您在《'. esc_html($post_title) .'》的评论有新回复
        </div>

        <!-- 评论区 -->
        <div class="comment-section">
            <!-- 原评论（被回复者） -->
            <div class="comment-item">
                <div class="comment-meta">'. get_comment_date( 'Y-m-d H:i:s', $parent_comment->comment_ID ) .'</div>
                <div class="comment-author-wrap">
                    <div class="comment-author">'. esc_html($parent_comment->comment_author) .'</div>
                    <img class="comment-avatar"
                         src="'. esc_url( 'https://cn.cravatar.com/avatar/'. md5( strtolower( $parent_email ) ) .'?s=32&d=identicon&r=g' ) .'"
                         alt="'. esc_attr($parent_comment->comment_author) .'头像"
                         width="32" height="32">
                </div>
                <div class="comment-bubble">
                    '. nl2br( esc_html( $parent_comment->comment_content ) ) .'
                </div>
            </div>

            <!-- 回复评论（回复者）- 核心修改：改用回复人邮箱的Cravatar -->
            <div class="comment-item reply">
                <div class="comment-meta">'. get_comment_date( 'Y-m-d H:i:s', $comment->comment_ID ) .'</div>
                <div class="comment-author-wrap">
                    <img class="comment-avatar"
                         src="'. $reply_avatar .'"
                         alt="'. esc_attr($comment->comment_author) .'头像"
                         width="32" height="32">
                    <div class="comment-author">'. esc_html($comment->comment_author) .'</div>
                </div>
                <div class="comment-bubble">
                    '. nl2br( esc_html( $comment->comment_content ) ) .'
                </div>
            </div>
        </div>


        <!-- 按钮 -->
        <a href="'. $comment_link .'" class="action-btn" target="_blank">查看完整内容</a>

        <!-- 页脚 -->
        <div class="footer">
            <span style="text-align: left; display: block;">顺颂商祺</span>
            <span style="text-align: right; display: block;">'. esc_html($site_name) .' 团队</span>
            <span style="text-align: right; display: block;">'. date('Y年m月d日') .'</span>
            <p style="text-align: center; font-size: 12px; color: #999;">此邮件由 '. esc_html($site_name) .' 自动发送，请勿直接回复</p>
        </div>
    </div>
</body>
</html>';

    // ========== 7. 发送邮件 ==========
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: '. $site_name .' <'. get_option( 'admin_email' ) .'>'
    ];
    $mail_sent = wp_mail( $parent_email, $subject, $message, $headers );

}

// ========== 8. 绑定钩子 ==========
add_action( 'comment_post', 'comment_reply_email_notification', 10, 3 );
add_action( 'comment_unapproved_to_approved', function( $comment ) {
    comment_reply_email_notification( $comment->comment_ID, '1', null );
}, 10, 1 );