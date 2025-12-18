function view(id) {
  var dom = document.getElementById(id);
  if (dom) {
    dom.scrollIntoView({behavior: 'smooth', inline: 'start', block: 'start'});
  }
}
