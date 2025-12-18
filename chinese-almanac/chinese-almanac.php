<?php
/**
 * Plugin Name: Chinese Almanac
 * Plugin URI: https://www.lifengdi.com/
 * Description: 显示中国传统黄历信息 [chinese_almanac] [chinese_legal_holiday] [chinese_solar_month]
 * Version: 1.0.0
 * Author: Dylan Li
 * Author URI: https://www.lifengdi.com/
 * License: GPL2
 */

// 禁止直接访问插件文件
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 按需加载资源（仅含短代码时加载）
 */
function chinese_almanac_enqueue_assets() {
    global $post;

    // 仅在包含目标短代码的页面加载资源（兼容非单页场景）
    $load_assets = false;
    if (is_singular() && is_a($post, 'WP_Post')) {
        // 合并判断：包含任意一个目标短代码则加载资源
        $load_assets = has_shortcode($post->post_content, 'chinese_almanac')
                     || has_shortcode($post->post_content, 'chinese_legal_holiday')
                     || has_shortcode($post->post_content, 'chinese_solar_month');
    }

    if ($load_assets) {
        // 加载CSS
        wp_enqueue_style(
            'chinese-almanac-style',
            plugins_url('css/tyme.css', __FILE__),
            array(),
            '1.0',
            'all'
        );

        // 1. 加载Vue（不依赖jQuery）
        wp_enqueue_script(
            'chinese-almanac-vue',
            plugins_url('js/vue.min.js', __FILE__),
            array(), // Vue无需依赖jQuery，清空依赖
            '3.3.4', // 标注真实Vue版本，便于缓存控制
            false
        );

        // 2. 加载Tyme（依赖Vue）
        wp_enqueue_script(
            'chinese-almanac-tyme',
            plugins_url('js/tyme.min.js', __FILE__),
            array('chinese-almanac-vue'), // 仅依赖Vue
            '1.0',
            false
        );

        // 3. 加载业务UI（依赖Tyme）
        wp_enqueue_script(
            'chinese-almanac-ui',
            plugins_url('js/show.js', __FILE__),
            array('chinese-almanac-tyme'), // 仅依赖Tyme
            '1.0',
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'chinese_almanac_enqueue_assets');

// 注册简码
add_shortcode('chinese_almanac', 'display_chinese_almanac');

/**
 * 显示黄历信息的简码函数
 */
function display_chinese_almanac($atts) {

    ob_start();
    ?>
    <div id="demo-huangli">
      <div class="solar">{{solar}}</div>
      <div class="lunar">{{lunar}}</div>
      <div class="gz">{{gz}}</div>
      <div class="yi"><i v-for="o in yi">{{o}}</i></div>
      <div class="ji"><i v-for="o in ji">{{o}}</i></div>
      <table>
        <tbody>
          <tr>
            <td colspan="2"><div><b>纳音</b><i>{{sound}}</i></div></td>
            <td><div><b>冲煞</b><i>冲{{chong}} 煞{{sha}}</i></div></td>
            <td colspan="2"><div><b>值神</b><i>{{twelveStar}}</i></div></td>
          </tr>
          <tr>
            <td style="width: 12.5%"><div><b style="width: 2em;">时辰吉凶</b></div></td>
            <td colspan="4" class="v h"><div><u v-for="o in hours"><i>{{o}}</i></u></div></td>
          </tr>
          <tr>
            <td rowspan="2" class="v h"><div><b>建除十二神</b><i>{{duty}}</i></div></td>
            <td style="width: 25%"><div><b>吉神宜趋</b><ul><li v-for="o in god.ji">{{o}}</li></ul></div></td>
            <td><div><b>今日胎神</b><i>{{fetus}}</i></div></td>
            <td style="width: 25%"><div><b>凶神宜忌</b><ul><li v-for="o in god.xiong">{{o}}</li></ul></div></td>
            <td rowspan="2" class="v h" style="width: 12.5%"><div><b>二十八星宿</b><i>{{twentyEightStar}}</i></div></td>
          </tr>
          <tr>
            <td colspan="3"><div><b>彭祖百忌</b><i v-for="o in pz">{{o}}</i></div></td>
          </tr>
        </tbody>
      </table>
    </div>

    <script>
        (function(W, D){
          var weekStart = 1;
          var now = new Date();

          // 黄历
          (function(){
            // 公历日
            var solarDay = SolarDay.fromYmd(now.getFullYear(), now.getMonth() + 1, now.getDate());

            new Vue({
              el: '#demo-huangli',
              data: {
                solar: '',
                week: '',
                lunar: '',
                gz: '',
                yi: [],
                ji: [],
                sound: '',
                chong: '',
                sha: '',
                twelveStar: '',
                twentyEightStar: '',
                god: {
                  ji: [],
                  xiong: []
                },
                duty: '',
                fetus: '',
                pz: [],
                hours: []
              },
              mounted: function() {
                this.compute();
              },
              methods: {
                compute: function() {
                  var that = this;
                  that.solar = solarDay.toString() + ' 星期' + solarDay.getWeek().getName();
                  that.week = solarDay.getWeek().toString();
                  var lunarDay = solarDay.getLunarDay();
                  var threePillars = lunarDay.getThreePillars();
                  var sixtyCycle = threePillars.getDay();
                  var heavenStem = sixtyCycle.getHeavenStem();
                  var earthBranch = sixtyCycle.getEarthBranch();
                  var lunarMonth = lunarDay.getLunarMonth();
                  var lunarYear = lunarMonth.getLunarYear();



                  that.lunar = lunarMonth.getName() + lunarDay.getName();
                  that.gz = [threePillars.getYear() + '(' + threePillars.getYear().getEarthBranch().getZodiac() + ')年', threePillars.getMonth() + '月', sixtyCycle + '日'].join(' ');

                  // 宜忌
                  var yi = [], ji = [];
                  var recommends = lunarDay.getRecommends();
                  for (var i = 0, j = recommends.length; i < j; i++) {
                    yi.push(recommends[i].toString());
                  }
                  var avoids = lunarDay.getAvoids();
                  for (var i = 0, j = avoids.length; i < j; i++) {
                    ji.push(avoids[i].toString());
                  }
                  that.yi = yi;
                  that.ji = ji;

                  that.sound = sixtyCycle.getSound().toString();
                  that.chong = earthBranch.getOpposite().getZodiac().toString();
                  that.sha = earthBranch.getOminous().toString();
                  that.duty = lunarDay.getDuty().toString();

                  var twelveStar = lunarDay.getTwelveStar();
                  that.twelveStar = [twelveStar.toString(), '(' + twelveStar.getEcliptic().getLuck() + ')'].join('');

                  var jiGods = [], xiongGods = [];
                  var gods = lunarDay.getGods();
                  for (var i = 0, j = gods.length; i < j; i++) {
                    var god = gods[i];
                    var godName = god.toString();
                    if ('吉' == god.getLuck().toString()) {
                      jiGods.push(godName);
                    } else {
                      xiongGods.push(godName);
                    }
                  }
                  that.god.ji = jiGods;
                  that.god.xiong = xiongGods;

                  that.fetus = lunarDay.getFetusDay().toString();

                  var twentyEightStar = lunarDay.getTwentyEightStar();
                  that.twentyEightStar = [twentyEightStar.toString(), twentyEightStar.getSevenStar().toString(), twentyEightStar.getAnimal().toString(), ' ', twentyEightStar.getLuck().toString()].join('');
                  that.pz = [heavenStem.getPengZuHeavenStem().toString(), earthBranch.getPengZuEarthBranch().toString()];

                  var hours = [];
                  var lunarHours = lunarDay.getHours();
                  for (var i = 0, j = lunarHours.length - 1; i < j; i++) {
                    var h = lunarHours[i];
                    hours.push([h.getSixtyCycle().toString(), h.getTwelveStar().getEcliptic().getLuck().toString()].join(''));
                  }
                  that.hours = hours;
                }
              }
            });
            D.getElementById('demo-huangli').style.display = 'block';
          })();

        })(window, document);
    </script>

    <?php

    return ob_get_clean();
}

// 注册简码
add_shortcode('chinese_solar_month', 'display_solar_month');

function display_solar_month($atts) {

    ob_start();
    ?>
    <div id="demo-solar-month" class="month-calendar">
          <div class="month">{{ month.name }}</div>
          <ul class="week">
            <li v-for="w in weeks" :class="{'weekend': w.isWeekend}">{{ w.name }}</li>
          </ul>
          <ul v-for="week in month.weeks">
            <li v-for="d in week.days" :class="{'holiday': d.holiday, 'holiday-work': d.holiday && d.holiday.isWork, 'weekend': d.isWeekend, 'gray': !d.isCurrentMonth, 'today': d.isToday, 'moon': d.moon, 'moon-0': d.moon && d.moonIndex === 0, 'moon-1': d.moon && d.moonIndex === 1, 'moon-2': d.moon && d.moonIndex === 2, 'moon-3': d.moon && d.moonIndex === 3, 'moon-4': d.moon && d.moonIndex === 4, 'moon-5': d.moon && d.moonIndex === 5, 'moon-6': d.moon && d.moonIndex === 6, 'moon-7': d.moon && d.moonIndex === 7}">
              <div>
                <b>{{ d.day }}</b>
                <i>{{ d.text }}</i>
                <u v-if="d.holiday">{{ d.holiday.isWork ? '班' : '休' }}</u>
                <u v-else-if="d.isToday">今</u>
              </div>
            </li>
          </ul>
          <div href="javascript:void(0);" class="prev" @click="prevMonth" title="上月"></div>
          <div href="javascript:void(0);" class="next" @click="nextMonth" title="下月"></div>
        </div>
    <script>
        (function(W, D){
          var weekStart = 1;
          var now = new Date();

          // 月历
          (function(){
            var weekHeads = [];
            var w = Week.fromIndex(weekStart);
            for (var i = 0; i < 7; i++) {
              weekHeads.push({
                isWeekend: w.getIndex() === 6 || w.getIndex() === 0,
                name: w.getName()
              });
              w = w.next(1);
            }

            var currentMonth = SolarMonth.fromYm(now.getFullYear(), now.getMonth() + 1);
            var month = currentMonth.next(0);

            new Vue({
              el: '#demo-solar-month',
              data: {
                month: {
                  name: '',
                  weeks: []
                },
                weeks: weekHeads
              },
              mounted: function() {
                this.compute();
              },
              methods: {
                compute: function() {
                  var that = this;
                  that.month.name = month.toString();

                  var weeks = [];
                  var monthWeeks = month.getWeeks(weekStart);
                  for (var i = 0, j = monthWeeks.length; i < j; i++) {
                    var days = [];
                    var weekDays = monthWeeks[i].getDays();
                    for (var x = 0, y = weekDays.length; x < y; x++) {
                      var solarDay = weekDays[x];
                      var lunarDay = solarDay.getLunarDay();
                      var holiday = solarDay.getLegalHoliday();
                      var weekIndex = solarDay.getWeek().getIndex();
                      var weekend = weekIndex === 6 || weekIndex ===0;
                      if (holiday && holiday.isWork()) {
                        weekend = false;
                      }

                      var text = null;

                      var f = solarDay.getFestival();
                      if (f) {
                        text = f.getName();
                      }

                      f = lunarDay.getFestival();
                      if (f) {
                        text = f.getName();
                      }

                      if (1 === lunarDay.getDay()) {
                        var lunarMonth = lunarDay.getLunarMonth();
                        text = lunarMonth.getName();
                        if (1 === lunarMonth.getMonthWithLeap()) {
                          text = lunarMonth.getLunarYear().getSixtyCycle().getName() + '年' + text;
                        }
                      }

                      var jq = solarDay.getTerm();
                      if(jq && jq.getSolarDay().equals(solarDay)){
                        text = jq.getName();
                        if (jq.isJie()) {
                            text += ' ' + lunarDay.getMonthSixtyCycle() + '月';
                        }
                      }

                      if (!text) {
                        text = lunarDay.getName() + ' ' + lunarDay.getSixtyCycle();
                      }

                      var phaseDay = solarDay.getPhaseDay();

                      days.push({
                        day: solarDay.getDay(),
                        holiday: holiday ? { isWork: holiday.isWork() } : null,
                        isCurrentMonth: solarDay.getSolarMonth().equals(month),
                        isToday: solarDay.getDay() === now.getDate() && solarDay.getSolarMonth().equals(currentMonth),
                        isWeekend: weekend,
                        text: text,
                        moon: phaseDay.getDayIndex() === 0,
                        moonIndex: phaseDay.getPhase().getIndex()
                      });
                    }
                    weeks.push({
                      days: days
                    });
                    that.month.weeks = weeks;
                  }
                },
                prevMonth: function() {
                  month = month.next(-1);
                  this.compute();
                },
                nextMonth: function() {
                  month = month.next(1);
                  this.compute();
                }
              }
            });
            D.getElementById('demo-solar-month').style.display = 'block';
          })();

        })(window, document);
    </script>

    <?php

    return ob_get_clean();

}

// 注册简码
add_shortcode('chinese_legal_holiday', 'display_chinese_legal_holiday');

function display_chinese_legal_holiday($atts) {
    ob_start();
    ?>
    <div id="demo-legal-holiday"></div>
    <script>
    (function(W, D){
      var weekStart = 1;
      var now = new Date();
      // 放假倒计时
      (function(){
        var size = 10;
        var year = now.getFullYear();
        var today = SolarDay.fromYmd(year, now.getMonth() + 1, now.getDate());

        var name = null;
        var l = [];

        var holiday = LegalHoliday.fromYmd(year, 1, 1);
        while (holiday && size > 0) {
          var nm = holiday.getName();
          if (nm != name && !holiday.isWork() && holiday.getDay().isAfter(today)) {
            l.push(holiday);
            name = nm;
            size--;
          }
          holiday = holiday.next(1);
        }

        var s = '';
        for (var i = 0, j = l.length; i < j; i++) {
          var h = l[i];
          s += '<p>距 ';
          s += h.getName();
          s += '放假 还有 ';
          s += h.getDay().subtract(today) - 1;
          s += ' 天</p>';
        }
        D.getElementById('demo-legal-holiday').innerHTML = s;
      })();

    })(window, document);
    </script>

    <?php
    return ob_get_clean();
}