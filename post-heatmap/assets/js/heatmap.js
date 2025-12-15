jQuery(document).ready(function($) {
    /**
     * 初始化所有热力图
     */
    $('.ph-heatmap').each(function() {
        const $heatmap = $(this);
        const heatmapId = $heatmap.attr('id');

        // 获取隐藏的热力图数据
        const $dataEl = $(`.ph-heatmap-data-${heatmapId}`);
        const heatmapConfig = JSON.parse($dataEl.html());
        const heatmapData = heatmapConfig.data;
        const timeRange = heatmapConfig.time_range;

        // 渲染热力图
        renderPostHeatmap($heatmap, heatmapData, timeRange);
    });

    /**
     * 渲染 GitHub 风格热力图（优化对齐+日期格式+未来日期tooltip）
     * @param {jQuery} $container 容器元素
     * @param {Object} data 日期-数量映射 {YYYY-MM-DD: count}
     * @param {Number} timeRange 时间范围（天）
     */
    function renderPostHeatmap($container, data, timeRange) {
        // 1. 计算时间范围（结束日期=今天，开始日期=今天-timeRange天）
        const today = new Date();
        const endDate = new Date(today.getFullYear(), today.getMonth(), today.getDate() + 1); // 今天0点
        const startDate = new Date();
        startDate.setDate(endDate.getDate() - timeRange);

        // 2. 生成按周分组的日期矩阵（核心修复：按周日到周六分组，每行是一周的7天）
        const weekMatrix = getWeekMatrix(startDate, endDate);
        const totalWeeks = weekMatrix.length;
        const cellSize = 13; // 单元格尺寸
        const cellGap = 2;   // 单元格间隙

        // 3. 清空容器并构建结构
        $container.empty();
        const $tooltip = $('<div class="ph-heatmap-tooltip"></div>').appendTo('body');

        // 3.1 构建热力图整体容器（星期列 + 主内容）
        const $wrapper = $('<div style="display: flex; align-items: flex-start;"></div>');

        // 3.2 星期标签列（固定7行：周日到周六）- 独立列，不与月份重叠
        const $weekdaysCol = $('<div class="ph-heatmap-weekdays"></div>');
        const weekdays = ['日', '一', '二', '三', '四', '五', '六'];
        weekdays.forEach(day => {
            $weekdaysCol.append(`<div class="ph-heatmap-weekday">${day}</div>`);
        });
        $wrapper.append($weekdaysCol);

        // 3.3 主内容区（月份标签 + 单元格网格）
        const $mainContent = $('<div class="ph-heatmap-main"></div>');

        // 3.4 月份标签行（优化：YYYY-MM格式，按周数动态分配宽度，避开星期列）
        const monthLabels = getMonthLabels(weekMatrix, startDate, endDate, cellSize, cellGap);
        const $monthsRow = $('<div class="ph-heatmap-months"></div>');
        monthLabels.forEach(label => {
            $monthsRow.append(`<div class="ph-heatmap-month" style="width: ${label.width}px; left: ${label.offset}px;">${label.text}</div>`);
        });
        $mainContent.append($monthsRow);

        // 3.5 单元格网格（核心修复：7行×N列，周日严格对齐第一列）
        const $cellsGrid = $('<div class="ph-heatmap-grid"></div>');
        // 设置网格：7行（周日到周六）×N列（周数）
        $cellsGrid.css({
            'display': 'grid',
            'grid-template-rows': `repeat(7, ${cellSize}px)`,
            'grid-template-columns': `repeat(${totalWeeks}, ${cellSize}px)`,
            'gap': `${cellGap}px`,
            'margin-top': '18px' // 给月份标签留空间
        });

        // 遍历每周（列），再遍历7天（行）→ 周日严格对齐第一行第一列
        weekMatrix.forEach((week, weekIndex) => {
            week.forEach((date, dayIndex) => {
                let count = 0;
                let dateStr = '';
                let isFuture = false;

                if (date) {
                    dateStr = formatDate(date);
                    // 判断是否是未来日期（今天之后）
                    isFuture = date > endDate;
                    count = isFuture ? 0 : (data[dateStr] || 0);
                }

                const level = isFuture ? 0 : getHeatmapLevel(count);
                const $cell = $(`<div class="ph-heatmap-cell level-${level}"
                                    data-date="${dateStr || ''}"
                                    data-count="${count}"
                                    data-is-future="${isFuture}"></div>`);

                // 绑定 tooltip 事件（优化：未来日期也显示日期）
                $cell.hover(
                    function() {
                        const $this = $(this);
                        const cellDate = $this.data('date');
                        const cellCount = $this.data('count');
                        const isFuture = $this.data('is-future');

                        let tooltipText = '';
                        if (cellDate) {
                            if (isFuture) {
                                tooltipText = `${cellDate}：未发布`;
                            } else {
                                tooltipText = `${cellDate}：${cellCount} 篇`;
                            }
                        } else {
                            tooltipText = '无数据';
                        }

                        // 定位 tooltip（避免偏移）
                        const offset = $this.offset();
                        $tooltip
                            .text(tooltipText)
                            .css({
                                top: `${offset.top - 30}px`,
                                left: `${offset.left - ($tooltip.outerWidth() / 2 - cellSize / 2)}px`,
                                opacity: 1,
                                zIndex: 9999
                            });
                    },
                    function() {
                        $tooltip.css('opacity', 0);
                    }
                );

                // 计算网格位置：行=周日(0)到周六(6)，列=周数
                $cell.css({
                    'grid-row': `${dayIndex + 1}`,
                    'grid-column': `${weekIndex + 1}`
                });

                $cellsGrid.append($cell);
            });
        });
        $mainContent.append($cellsGrid);
        $wrapper.append($mainContent);
        $container.append($wrapper);

        // 3.6 添加图例
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
        $container.append($legend);
    }

    /**
     * 辅助函数：生成周矩阵（按周日到周六分组，每行是一周的7天）
     * 确保周日是每一周的第一个元素，严格对齐第一列
     */
    function getWeekMatrix(startDate, endDate) {
        const matrix = [];
        // 调整起始日期到本周日（核心：确保周日是第一列）
        let currentWeekStart = new Date(startDate);
        currentWeekStart.setDate(currentWeekStart.getDate() - currentWeekStart.getDay()); // 周日=0，周一=1...

        while (currentWeekStart <= endDate) {
            const week = [];
            // 填充本周7天（周日到周六）
            for (let dayIndex = 0; dayIndex < 7; dayIndex++) {
                const date = new Date(currentWeekStart);
                date.setDate(currentWeekStart.getDate() + dayIndex);
                // 区分过去/今天/未来日期
                week.push(date <= endDate ? date : new Date(date)); // 未来日期也保留
            }
            matrix.push(week);
            // 下一周
            currentWeekStart.setDate(currentWeekStart.getDate() + 7);
        }
        return matrix;
    }

    /**
     * 辅助函数：生成月份标签（优化：YYYY-MM格式，计算偏移和宽度）
     */
    function getMonthLabels(weekMatrix, startDate, endDate, cellSize, cellGap) {
        const monthMap = {};
        const baseOffset = 0; // 基准偏移（避开星期列）

        // 遍历每周，记录每周第一个日期的月份
        weekMatrix.forEach((week, weekIndex) => {
            const firstDate = week[0]; // 每周第一天（周日）
            if (!firstDate) return;

            const year = firstDate.getFullYear();
            const month = firstDate.getMonth() + 1; // 1-12月
            const monthStr = month < 10 ? `0${month}` : month;
            const key = `${year}-${monthStr}`; // YYYY-MM格式

            // 计算该周的偏移和宽度
            const weekOffset = baseOffset + (weekIndex * (cellSize + cellGap));
            const weekWidth = cellSize + cellGap;

            if (!monthMap[key]) {
                monthMap[key] = {
                    text: key,
                    startOffset: weekOffset,
                    totalWidth: weekWidth,
                    weekCount: 1
                };
            } else {
                monthMap[key].totalWidth += weekWidth;
                monthMap[key].weekCount += 1;
            }
        });

        // 格式化月份标签数据
        return Object.values(monthMap).map(month => ({
            text: month.text,
            offset: month.startOffset,
            width: month.totalWidth - cellGap // 减去最后一个间隙
        }));
    }

    /**
     * 辅助函数：格式化日期为 YYYY-MM-DD
     */
    function formatDate(date) {
        const year = date.getFullYear();
        const month = date.getMonth() + 1;
        const day = date.getDate();
        return `${year}-${month < 10 ? '0' + month : month}-${day < 10 ? '0' + day : day}`;
    }

    /**
     * 辅助函数：计算热力图等级（0-4）
     */
    function getHeatmapLevel(count) {
        if (count === 0) return 0;
        if (count === 1) return 1;
        if (count <= 3) return 2;
        if (count <= 5) return 3;
        return 4;
    }
});