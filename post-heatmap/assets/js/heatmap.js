jQuery(document).ready(function($) {
    /**
     * 初始化所有热力图
     */
    $('.ph-heatmap').each(function() {
        const $heatmap = $(this);
        const heatmapId = $heatmap.attr('id');
        const $dataEl = $(`.ph-heatmap-data-${heatmapId}`);
        const heatmapConfig = JSON.parse($dataEl.html());

        // 关键修复：确保传递stats参数
        renderPostHeatmap(
            $heatmap,
            heatmapConfig.data,
            heatmapConfig.stats, // 传递统计信息
            heatmapConfig.year,
            heatmapConfig.time_range
        );
    });

    /**
     * 年份选择器切换事件
     */
    $(document).on('change', '.ph-year-select', function() {
        const $select = $(this);
        const heatmapId = $select.data('heatmap-id');
        const $heatmap = $(`#${heatmapId}`);
        const $container = $heatmap.closest('.ph-heatmap-container');

        // 禁用选择器+显示加载中
        $select.prop('disabled', true);
        $heatmap.html('<div style="padding: 20px;">加载中...</div>');

        const selectedYear = $select.val() || null;
        const postType = $container.data('post-type');
        const timeRange = $container.data('time-range');

        $.ajax({
            url: phHeatmap.ajaxUrl,
            type: 'GET',
            data: {
                action: 'ph_get_heatmap_data',
                year: selectedYear,
                post_type: postType,
                time_range: timeRange
            },
            dataType: 'json',
            success: function(data) {
                // 关键修复：传递stats参数
                renderPostHeatmap(
                    $heatmap,
                    data.data,
                    data.stats, // 传递统计信息
                    data.year,
                    data.time_range
                );
            },
            error: function() {
                alert('数据加载失败，请刷新重试');
                $heatmap.empty(); // 失败时也清空旧内容
            },
            complete: function() {
                $select.prop('disabled', false); // 恢复选择器
            }
        });
    });

    /**
     * 核心：渲染热力图
     * @param {jQuery} $container 容器
     * @param {Object} data 日期-数量映射 {YYYY-MM-DD: count}
     * @param {Object} stats 统计信息
     * @param {Number|null} year 年份（null=最近一年）
     * @param {Number} timeRange 时间范围（天）
     */
    function renderPostHeatmap($container, data, stats, year, timeRange) {
        // 1. 计算时间范围
        let startDate, endDate;
        if (year) {
            startDate = new Date(`${year}-01-01`);
            endDate = new Date(`${year}-12-31`);
        } else {
            endDate = new Date();
            startDate = new Date();
            startDate.setDate(endDate.getDate() - timeRange);
        }

        // 2. 生成按周分组的日期矩阵
        const weekMatrix = getWeekMatrix(startDate, endDate);
        const totalWeeks = weekMatrix.length;
        const cellSize = 13; // 优化后的单元格尺寸
        const cellGap = 2;

        // 3. 彻底清空容器及附属元素
        $container.empty(); // 清空热力图容器
        $container.siblings('.ph-heatmap-legend').remove(); // 删除旧图例

        // 修复：用 let 声明 $tooltip，避免 const 重新赋值报错
        let $tooltip = $('.ph-heatmap-tooltip');
        if (!$tooltip.length) {
            $tooltip = $('<div class="ph-heatmap-tooltip"></div>').appendTo('body');
        }

        // 4. 构建热力图结构
        const $wrapper = $('<div style="display: flex; align-items: flex-start;"></div>');

        // 4.1 星期标签列
        const $weekdaysCol = $('<div class="ph-heatmap-weekdays"></div>');
        const weekdays = ['日', '一', '二', '三', '四', '五', '六'];
        weekdays.forEach(day => {
            $weekdaysCol.append(`<div class="ph-heatmap-weekday">${day}</div>`);
        });
        $wrapper.append($weekdaysCol);

        // 4.2 主内容区（月份标签 + 单元格网格）
        const $mainContent = $('<div class="ph-heatmap-main"></div>');

        // 4.3 月份标签行（重新生成，无残留）
        const monthLabels = getMonthLabels(weekMatrix, startDate, endDate, cellSize, cellGap);
        const $monthsRow = $('<div class="ph-heatmap-months"></div>');
        monthLabels.forEach(label => {
            $monthsRow.append(`<div class="ph-heatmap-month" style="width: ${label.width}px; left: ${label.offset}px;">${label.text}</div>`);
        });
        $mainContent.append($monthsRow);

        // 4.4 单元格网格
        const $cellsGrid = $('<div class="ph-heatmap-grid"></div>');
        $cellsGrid.css({
            'grid-template-columns': `repeat(${totalWeeks}, ${cellSize}px)`,
            'margin-top': '18px'
        });

        // 填充单元格
        weekMatrix.forEach((week, weekIndex) => {
            week.forEach((date, dayIndex) => {
                let count = 0;
                let dateStr = '';
                let isFuture = false;

                if (date) {
                    dateStr = formatDate(date);
                    isFuture = date > new Date();
                    count = isFuture ? 0 : (data[dateStr] || 0);
                }

                const level = getHeatmapLevel(count);
                const $cell = $(`<div class="ph-heatmap-cell level-${level}"
                                    data-date="${dateStr || ''}"
                                    data-count="${count}"></div>`);

                // 悬停提示框
                $cell.hover(
                    function(e) {
                        if (!dateStr) return;
                        const text = `${dateStr}：发布${count}篇文章`;
                        $tooltip.text(text).css({
                            top: e.pageY + 10,
                            left: e.pageX + 10,
                            opacity: 1
                        });
                    },
                    function() {
                        $tooltip.css('opacity', 0);
                    }
                );

                $cell.css({
                    'grid-row': `${dayIndex + 1}`,
                    'grid-column': `${weekIndex + 1}`
                });

                $cellsGrid.append($cell);
            });
        });
        $mainContent.append($cellsGrid);
        $wrapper.append($mainContent);

        // 4.5 右侧信息区（年份+统计）
        const $rightInfo = $('<div class="ph-heatmap-right-info"></div>');
        // 年份标签
        const displayText = year ? `${year}年` : '最近一年';
        $rightInfo.append(`<div class="ph-heatmap-year-label">${displayText}</div>`);

        // 统计信息卡片
        const $statsCard = $(`
                <div class="ph-heatmap-stats-card">
                    <!-- 基础统计模块 -->
                    <div class="ph-stats-base">
                        <div class="ph-stats-item">
                            <span class="ph-stats-label">总发布数：</span>
                            <span class="ph-stats-value">${stats.total}篇</span>
                        </div>
                        <div class="ph-stats-item">
                            <span class="ph-stats-label">日均发布：</span>
                            <span class="ph-stats-value">${stats.daily_avg}篇</span>
                        </div>
                        <div class="ph-stats-item">
                            <span class="ph-stats-label">最高单日：</span>
                            <span class="ph-stats-value">${stats.max_daily_date || '无'} ${stats.max_daily}篇</span>
                        </div>
                        <div class="ph-stats-item">
                            <span class="ph-stats-label">最活跃月份：</span>
                            <span class="ph-stats-value">${stats.max_month} ${stats.max_month_count}篇</span>
                        </div>
                    </div>

                    <!-- 发布节奏模块 -->
                    <div class="ph-stats-rhythm">
                        <div class="rhythm-item">
                            <span class="rhythm-label">高频时段：</span>
                            <span class="rhythm-tag">每周${stats.high_freq_weekday}</span>
                        </div>
                        <div class="rhythm-item">
                            <span class="rhythm-label">最长断更：</span>
                            <span class="rhythm-tag">${stats.max_break_days || 0}天</span>
                        </div>
                    </div>

                    <!-- 分类占比模块（有数据才显示） -->
                    ${stats.category_data && stats.category_data.length > 0 ? `
                    <div class="ph-stats-category">
                        ${stats.category_data.map(cat => `
                        <div class="category-item">
                            <span class="category-label">${cat.name}</span>
                            <div class="category-bar">
                                <div class="bar-fill" style="width: ${cat.percent}%;"></div>
                            </div>
                            <span class="category-percent">${cat.percent}%</span>
                        </div>
                        `).join('')}
                    </div>
                    ` : ''}
                </div>
            `);
        $rightInfo.append($statsCard);
        $wrapper.append($rightInfo);

        // 5. 插入到容器
        $container.append($wrapper);

        // 6. 添加新图例（无残留）
        const $legend = $(`
            <div class="ph-heatmap-legend">
                <span class="ph-heatmap-legend-label">最少</span>
                <div class="ph-heatmap-legend-colors">
                    <div class="ph-heatmap-legend-color level-0"></div>
                    <div class="ph-heatmap-legend-color level-1"></div>
                    <div class="ph-heatmap-legend-color level-2"></div>
                    <div class="ph-heatmap-legend-color level-3"></div>
                    <div class="ph-heatmap-legend-color level-4"></div>
                </div>
                <span class="ph-heatmap-legend-label">最多</span>
            </div>
        `);
        $container.after($legend);
    }

    /**
     * 辅助函数：生成按周分组的日期矩阵
     * @param {Date} startDate 开始日期
     * @param {Date} endDate 结束日期
     * @returns {Array} 二维数组 [周][星期] → 日期对象
     */
    function getWeekMatrix(startDate, endDate) {
        const matrix = [];
        let currentDate = new Date(startDate);
        let currentWeek = new Array(7).fill(null); // 一周7天

        // 定位到第一周的第一天（周日）
        const firstDay = new Date(startDate);
        firstDay.setDate(startDate.getDate() - startDate.getDay());

        currentDate = new Date(firstDay);

        // 遍历所有日期
        while (currentDate <= endDate) {
            const dayOfWeek = currentDate.getDay(); // 0=周日，6=周六
            currentWeek[dayOfWeek] = new Date(currentDate);

            // 周末 → 存入矩阵，重置周数组
            if (dayOfWeek === 6) {
                matrix.push(currentWeek);
                currentWeek = new Array(7).fill(null);
            }

            // 下一天
            currentDate.setDate(currentDate.getDate() + 1);
        }

        // 最后一周（未完成）
        if (currentWeek.some(day => day !== null)) {
            matrix.push(currentWeek);
        }

        return matrix;
    }

    /**
     * 辅助函数：生成月份标签（定位+文本）
     * @param {Array} weekMatrix 周矩阵
     * @param {Date} startDate 开始日期
     * @param {Date} endDate 结束日期
     * @param {Number} cellSize 单元格尺寸
     * @param {Number} cellGap 单元格间距
     * @returns {Array} 月份标签数组
     */
    function getMonthLabels(weekMatrix, startDate, endDate, cellSize, cellGap) {
        const monthNames = ['1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月'];
        const monthLabels = [];
        let lastMonth = -1;

        weekMatrix.forEach((week, weekIndex) => {
            // 取每周第一个有效日期
            const firstDate = week.find(day => day !== null);
            if (!firstDate) return;

            const month = firstDate.getMonth();
            if (month === lastMonth) return;

            // 计算标签位置：基础宽度改为 30px（足够容纳“10月”等文本）
            const offset = weekIndex * (cellSize + cellGap) + (cellSize / 2);
            monthLabels.push({
                text: monthNames[month],
                offset: offset,
                width: 30
            });

            lastMonth = month;
        });

        return monthLabels;
    }

    /**
     * 辅助函数：格式化日期为 YYYY-MM-DD
     * @param {Date} date 日期对象
     * @returns {String} 格式化后的日期
     */
    function formatDate(date) {
        if (!date) return '';
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    /**
     * 辅助函数：计算热力图颜色等级
     * @param {Number} count 文章数量
     * @returns {Number} 等级 0-4
     */
    function getHeatmapLevel(count) {
        if (count === 0) return 0;
        if (count < 2) return 1;
        if (count < 5) return 2;
        if (count < 10) return 3;
        return 4;
    }
});