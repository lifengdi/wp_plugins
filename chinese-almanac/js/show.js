function view(id) {
  var dom = document.getElementById(id);
  if (dom) {
    dom.scrollIntoView({behavior: 'smooth', inline: 'start', block: 'start'});
  }
}

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
