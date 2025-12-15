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
     * 渲染 GitHub 风格热力图（彻底修复日期判断+星期对齐）
     * @param {jQuery} $container 容器元素
     * @param {Object} data 日期-数量映射 {YYYY-MM-DD: count}
     * @param {Number} timeRange 时间范围（天）
     */
    function renderPostHeatmap($container, data, timeRange) {
        // 1. 时间基准（核心修复：统一为UTC时间，避免时区问题）
        const today = new Date();
        const todayUTC = new Date(Date.UTC(today.getFullYear(), today.getMonth(), today.getDate())); // 今天0点UTC
        const endDate = todayUTC; // 结束日期=今天
        const startDate = new Date(Date.UTC(today.getFullYear(), today.getMonth(), today.getDate() - timeRange));

        // 2. 生成周矩阵（核心：周日严格作为第一列，独立于月份）
        const weekMatrix = getWeekMatrix(startDate, endDate);
        const totalWeeks = weekMatrix.length;
        const cellSize = 13; // 单元格尺寸
        const cellGap = 2;   // 单元格间隙

        // 3. 清空容器+创建Tooltip
        $container.empty();
        const $tooltip = $('<div class="ph-heatmap-tooltip"></div>').appendTo('body');

        // 4. 整体布局结构（星期列 + 主内容区，彻底分离）
        const $heatmapWrap = $('<div class="ph-heatmap-wrap" style="display: flex;"></div>');

        // 4.1 星期列（独立列，绝对不与月份重叠）
        const $weekdaysCol = $('<div class="ph-heatmap-weekdays-col"></div>');
        const weekdays = ['日', '一', '二', '三', '四', '五', '六'];
        weekdays.forEach(day => {
            $weekdaysCol.append(`<div class="ph-heatmap-weekday-item">${day}</div>`);
        });
        $heatmapWrap.append($weekdaysCol);

        // 4.2 主内容区（月份 + 网格，与星期列并排）
        const $contentArea = $('<div class="ph-heatmap-content-area"></div>');

        // 4.3 月份标签（YYYY-MM格式，基于周数定位，不影响星期列）
        const monthLabels = getMonthLabels(weekMatrix, startDate, endDate, cellSize, cellGap);
        const $monthsWrap = $('<div class="ph-heatmap-months-wrap"></div>');
        monthLabels.forEach((label, idx) => {
            $monthsWrap.append(`<div class="ph-heatmap-month-item" style="left: ${label.left}px;">${label.text}</div>`);
        });
        $contentArea.append($monthsWrap);

        // 4.4 单元格网格（核心：7行×N列，周日=第一行，严格对齐星期列）
        const $cellsGrid = $('<div class="ph-heatmap-cells-grid"></div>');
        $cellsGrid.css({
            'display': 'grid',
            'grid-template-rows': `repeat(7, ${cellSize}px)`, // 7行=周日到周六
            'grid-template-columns': `repeat(${totalWeeks}, ${cellSize}px)`, // N列=周数
            'gap': `${cellGap}px`,
            'margin-top': '20px' // 给月份标签留空间
        });

        // 遍历周矩阵生成单元格（核心修复：先列后行，周日对齐第一行）
        weekMatrix.forEach((week, weekIdx) => {
            week.forEach((date, dayIdx) => { // dayIdx=0=周日，1=周一...6=周六
                if (!date) {
                    // 无日期的空单元格
                    $cellsGrid.append(`<div class="ph-heatmap-cell" data-date="" data-count="0"></div>`);
                    return;
                }

                // 日期格式化（统一为YYYY-MM-DD）
                const dateStr = formatDate(date);
                // 修复未来日期判断：仅date > todayUTC才是未来
                const isFuture = date > todayUTC;
                const count = isFuture ? 0 : (data[dateStr] || 0);
                const level = getHeatmapLevel(count);

                // 创建单元格（绑定所有数据）
                const $cell = $(`<div class="ph-heatmap-cell level-${level}"
                                    data-date="${dateStr}"
                                    data-count="${count}"
                                    data-is-future="${isFuture}"></div>`);

                // 网格定位（核心：dayIdx=行，weekIdx=列 → 周日=第一行）
                $cell.css({
                    'grid-row': `${dayIdx + 1}`, // 周日=1行，周一=2行...
                    'grid-column': `${weekIdx + 1}` // 第一周=1列，第二周=2列...
                });

                // Tooltip事件（修复未来日期+今天显示）
                $cell.hover(
                    function() {
                        const $this = $(this);
                        const cellDate = $this.data('date');
                        const cellCount = $this.data('count');
                        const isFuture = $this.data('is-future');

                        let tipText = '';
                        if (cellDate) {
                            if (isFuture) {
                                tipText = `${cellDate}：未来日期`;
                            } else if (cellDate === formatDate(todayUTC)) {
                                tipText = `${cellDate}：今天 · ${cellCount} 篇文章`;
                            } else {
                                tipText = `${cellDate}：${cellCount} 篇文章`;
                            }
                        } else {
                            tipText = '无数据';
                        }

                        // Tooltip定位（基于单元格绝对位置）
                        const offset = $this.offset();
                        $tooltip
                            .text(tipText)
                            .css({
                                top: `${offset.top - 35}px`,
                                left: `${offset.left - ($tooltip.outerWidth() / 2 - cellSize / 2)}px`,
                                opacity: 1,
                                zIndex: 99999
                            });
                    },
                    function() {
                        $tooltip.css('opacity', 0);
                    }
                );

                $cellsGrid.append($cell);
            });
        });
        $contentArea.append($cellsGrid);
        $heatmapWrap.append($contentArea);
        $container.append($heatmapWrap);

        // 5. 图例
        const $legend = $(`
            <div class="ph-heatmap-legend" style="margin-left: 28px; margin-top: 12px;">
                <span class="ph-legend-label">最少</span>
                <div class="ph-legend-colors">
                    <div class="ph-legend-color level-0"></div>
                    <div class="ph-legend-color level-1"></div>
                    <div class="ph-legend-color level-2"></div>
                    <div class="ph-legend-color level-3"></div>
                    <div class="ph-legend-color level-4"></div>
                </div>
                <span class="ph-legend-label">最多</span>
            </div>
        `);
        $container.append($legend);
    }

    /**
     * 生成周矩阵（彻底修复：周日作为每周第一个元素，严格对齐第一列）
     * @return {Array} 格式：[[周日, 周一, ..., 周六], [下一周周日, ...], ...]
     */
    function getWeekMatrix(startDate, endDate) {
        const matrix = [];
        // 起始日期调整为：时间范围内第一个周日
        let current = new Date(startDate);
        current.setUTCDate(current.getUTCDate() - current.getUTCDay()); // getUTCDay=0=周日

        while (current <= endDate) {
            const week = [];
            // 填充本周7天（周日到周六）
            for (let i = 0; i < 7; i++) {
                const date = new Date(current);
                date.setUTCDate(current.getUTCDate() + i);
                week.push(date <= endDate ? date : null);
            }
            matrix.push(week);
            // 下一周
            current.setUTCDate(current.getUTCDate() + 7);
        }
        return matrix;
    }

    /**
     * 生成月份标签（YYYY-MM格式，精准定位到对应周数）
     */
    function getMonthLabels(weekMatrix, startDate, endDate, cellSize, cellGap) {
        const labels = [];
        let lastMonth = ''; // 记录上一个月份，避免重复

        weekMatrix.forEach((week, weekIdx) => {
            const firstDate = week[0]; // 每周第一天（周日）
            if (!firstDate) return;

            // 格式化为YYYY-MM（UTC时间）
            const year = firstDate.getUTCFullYear();
            const month = firstDate.getUTCMonth() + 1; // 1-12
            const monthStr = month < 10 ? `0${month}` : month;
            const currentMonth = `${year}-${monthStr}`;

            // 每个月份只显示一次
            if (currentMonth !== lastMonth) {
                lastMonth = currentMonth;
                // 计算标签左侧偏移（基于周数）
                const left = weekIdx * (cellSize + cellGap);
                labels.push({
                    text: currentMonth,
                    left: left + (cellSize / 2) // 标签居中对齐对应周
                });
            }
        });
        return labels;
    }

    /**
     * 格式化日期为YYYY-MM-DD（UTC时间，避免时区问题）
     */
    function formatDate(date) {
        const year = date.getUTCFullYear();
        const month = date.getUTCMonth() + 1;
        const day = date.getUTCDate();
        return `${year}-${month < 10 ? '0' + month : month}-${day < 10 ? '0' + day : day}`;
    }

    /**
     * 热力图颜色等级计算
     */
    function getHeatmapLevel(count) {
        if (count === 0) return 0;
        if (count === 1) return 1;
        if (count <= 3) return 2;
        if (count <= 5) return 3;
        return 4;
    }
});