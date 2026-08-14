=== 每日早报自动发布 ===
Contributors: dylanli
Tags: news, cron, alapi, daily, digest
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Author URI: https://www.lifengdi.com/
Plugin URI: https://bbs.lifengdi.com/

每天定时通过 ALAPI 抓取每日早报、知乎日报、微博热搜榜、网易新闻头条、今日热榜，整合为一篇文章发布。

== Description ==

插件每天在设定时间执行一次，把勾选的数据源各自渲染为文章中的一个小节，合并成当天的一篇日报。

支持的数据源（均来自 ALAPI，需自备 Token）：

* 每日早报（ALAPI #67）— 15 条全球新闻速报 + 每日微语。接口只返回纯文字，**不含逐条原文链接**。
* 知乎日报（ALAPI #15）— 头条 + 今日推荐，每条带原文链接与配图。
* 微博热搜榜（ALAPI #16）— 热搜词 + 热度值，链接到微博搜索页。
* 网易新闻头条（ALAPI #18）— 标题、摘要、来源、时间、配图，每条带原文链接。
* 今日热榜（ALAPI #29）— 聚合站点热榜，可指定榜单类型或站点 ID。

设计要点：

* 图片与媒体一律使用原始远程地址，不下载、不写入媒体库。
* 外链统一带 `target="_blank"` 与 `rel="noopener nofollow external"`。
* 按日期去重：当天已有文章时跳过，不重复创建。已被删除或在回收站的当天文章同样算已存在，不会被重新生成。
* 某个数据源失败不会中断整次运行，文章用成功的部分生成，失败原因记入运行日志。
* Token 存在站点数据库中，不回显到页面，也不写入运行日志。

== Installation ==

1. 将 `daily-zaobao` 目录上传到 `wp-content/plugins/`。
2. 在「插件」页面启用。
3. 打开「设置 → 每日早报」，填入 ALAPI Token，勾选数据源，设定抓取时间。
4. 可先点「立即抓取一次」验证。

== Frequently Asked Questions ==

= 为什么「每日早报」的条目不能点击跳转原文？ =

ALAPI 的每日早报接口（#67）返回的 `news` 是纯字符串数组，本身不含链接。需要逐条跳原文请改用知乎日报、网易新闻头条或今日热榜。

= 为什么没有设置特色图片？ =

特色图片要求附件存在于媒体库，而本插件按设计不落本地媒体。头图直接渲染在正文顶部。

= 定时没有按点触发？ =

WP-Cron 依赖站点被访问才会触发，访问量低的站点会延后。需要准点可关闭 `WP_CRON` 并改用系统 crontab 调用 `wp-cron.php`。

== Support ==

* 作者主页：https://www.lifengdi.com/
* 问题反馈：https://bbs.lifengdi.com/

== Changelog ==

= 1.3.0 =
* 文末「数据来源」的所有链接统一指向 https://www.alapi.cn/aff/xh7den。
* 补充插件头部 `Author URI`，`Plugin URI` 指向反馈论坛。
* 插件列表页新增「作者主页」「问题反馈」链接，设置页底部同样标注。

= 1.2.1 =
* 修复图片周围出现空白：主题普遍带有 `img { height: auto }`，会盖掉插件的 `height: 100%`，图片按原始比例渲染，比容器矮就下方留白、窄就左右留白。改为容器用 `aspect-ratio` 定形、图片四边绝对定位撑满，不再与主题争 `height`。
* 清除主题可能通过 `::before` 或 `::marker` 添加的列表符号。

= 1.2.0 =
* 「今日热榜榜单」改为下拉框，选项由 `/api/tophub/site` 动态拉取，按站点分组。选中后请求改用 `id` 参数。
* 「网易新闻类型」改为下拉框，选项由 `/api/new/toutiao/type` 动态拉取。
* 两个列表缓存一周（transient），新增「刷新可选列表」按钮。接口不可用时自动回退为手动填写，不会锁死设置。
* Token 字段固定显示 ALAPI 注册链接。
* 带缩略图的条目列表去掉前导圆点；热搜/热榜的排名序号保留。

= 1.1.0 =
* 修复：知乎日报头条的缩略图地址取错。`top_stories` 的 `image` 是字符串，被当成数组取 `[0]`，结果拿到的是首字符，生成了 `src="h"`。
* 调整：每日早报只渲染 `head_image`，不再渲染 `image`。
* 调整：条目统一改为左图右文，缩略图固定 140×94（窄屏 96×68），头图固定高度并裁切。新增前端样式表，仅在包含本插件文章的页面加载。

= 1.0.0 =
* 首个版本：五个数据源、多源合并为一篇、WP-Cron 定时、手动抓取、运行日志。
