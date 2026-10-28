/* HELMET front page. Small, vanilla, no build.
   - the hero play: run on load, light one pair at a time, erase and rerun
   - play cards: draw on scroll, rerun on hover or with "Run it"
   - scoreboard tabs (ARIA tabs, arrow keys) and the HELMET score counting up
   - copy buttons, scroll reveals, and "hike" typed anywhere snaps the ball
   With reduced motion there is no .motion class: the page is already drawn,
   and only the tabs, the copy buttons and the pair captions do anything. */
(function () {
  "use strict";

  var root = document.documentElement;
  var motion = root.classList.contains("motion");
  window.__helmetReady = true;

  /* Restart a drill's CSS animations: drop the class, reflow, add it back. */
  function run(el) {
    if (!motion || !el) return;
    el.classList.remove("is-run");
    void el.getBoundingClientRect();
    el.classList.add("is-run");
  }

  /* ------------------------------------------------------------ hero play */
  var board = document.getElementById("board");
  var caption = document.getElementById("board-caption");
  var defaultCaption = caption ? caption.textContent : "";
  var calls = Array.prototype.slice.call(document.querySelectorAll("[data-call]"));
  var pairs = board ? Array.prototype.slice.call(board.querySelectorAll(".pair")) : [];

  function light(i) {
    if (!board) return;
    board.classList.toggle("has-lit", i !== null);
    pairs.forEach(function (p) { p.classList.toggle("is-lit", String(i) === p.getAttribute("data-pair")); });
    calls.forEach(function (c) { c.setAttribute("aria-pressed", String(String(i) === c.getAttribute("data-call"))); });
    var t = i === null ? null : document.querySelector('template[data-caption="' + i + '"]');
    caption.textContent = t ? t.content.textContent : defaultCaption;
  }

  calls.forEach(function (c) {
    var i = c.getAttribute("data-call");
    c.setAttribute("aria-pressed", "false");
    c.addEventListener("mouseenter", function () { light(i); });
    c.addEventListener("focus", function () { light(i); });
    c.addEventListener("click", function () { light(c.getAttribute("aria-pressed") === "true" ? null : i); });
    c.addEventListener("mouseleave", function () { if (document.activeElement !== c) light(null); });
    c.addEventListener("blur", function () { light(null); });
  });
  pairs.forEach(function (p) {
    p.addEventListener("mouseenter", function () { light(p.getAttribute("data-pair")); });
    p.addEventListener("mouseleave", function () { light(null); });
  });

  var playing = false;
  function hike() {
    if (!motion || !board || playing) return;
    playing = true;
    board.classList.add("is-erasing");
    setTimeout(function () {
      board.classList.remove("is-erasing");
      run(board);
      playing = false;
    }, 560);
  }
  if (board) run(board);
  var eraser = document.querySelector("[data-erase]");
  if (eraser) eraser.addEventListener("click", hike);

  /* Type "hike" anywhere (outside a field) to snap the ball. */
  var typed = "";
  document.addEventListener("keydown", function (ev) {
    if (ev.ctrlKey || ev.metaKey || ev.altKey || !ev.key || ev.key.length !== 1) return;
    var tag = (ev.target && ev.target.tagName) || "";
    if (tag === "INPUT" || tag === "TEXTAREA") return;
    typed = (typed + ev.key.toLowerCase()).slice(-4);
    if (typed === "hike") {
      typed = "";
      if (board) board.scrollIntoView({ block: "center", behavior: motion ? "smooth" : "auto" });
      hike();
      toast("Hut, hut, hike! Zero sacks, again.");
    }
  });

  /* ------------------------------------------------------------ play cards */
  var cards = Array.prototype.slice.call(document.querySelectorAll(".play-card"));
  cards.forEach(function (card) {
    var drill = card.querySelector(".drill");
    var btn = card.querySelector("[data-run]");
    var last = 0;
    function again() { var now = Date.now(); if (now - last < 1600) return; last = now; run(drill); }
    card.addEventListener("mouseenter", again);
    if (btn) btn.addEventListener("click", function () { last = 0; again(); });
  });

  /* ------------------------------------------------------- scroll reveals */
  var revealSel = ".binder-head, .stadium__head, .film__head, .kit, .scoreboard, .frame, .clipboard, .whiteboard, .poster__head, .roster__row, .play-card";
  if (motion && "IntersectionObserver" in window) {
    var reveals = document.querySelectorAll(revealSel);
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        var el = en.target;
        io.unobserve(el);
        el.classList.add("is-in");
        var drill = el.querySelector && el.querySelector(".drill");
        if (drill) setTimeout(function () { run(drill); }, 350);
        if (el.classList.contains("scoreboard")) countUp(el);
        el.addEventListener("animationend", function done(ev) {
          if (ev.target !== el) return;
          el.removeEventListener("animationend", done);
          el.removeAttribute("data-reveal");
          el.classList.remove("is-in");
        });
      });
    }, { rootMargin: "0px 0px -8% 0px", threshold: 0.12 });
    Array.prototype.forEach.call(reveals, function (el) { el.setAttribute("data-reveal", ""); io.observe(el); });
  } else {
    cards.forEach(function (c) { run(c.querySelector(".drill")); });
  }

  /* The HELMET score ticks up one header at a time, under 3 changes a second. */
  function countUp(sb) {
    var d = sb.querySelector("[data-count]");
    if (!d) return;
    var target = parseInt(d.getAttribute("data-count"), 10), n = 0;
    d.textContent = "0";
    var t = setInterval(function () {
      n += 1;
      d.textContent = String(n);
      if (n >= target) clearInterval(t);
    }, 400);
  }

  /* ------------------------------------------------------------- tabs */
  var tabs = Array.prototype.slice.call(document.querySelectorAll(".sb-tab"));
  function select(tab, focus) {
    tabs.forEach(function (t) {
      var on = t === tab;
      t.setAttribute("aria-selected", String(on));
      if (on) t.removeAttribute("tabindex"); else t.setAttribute("tabindex", "-1");
      var p = document.getElementById(t.getAttribute("aria-controls"));
      if (p) p.hidden = !on;
    });
    if (focus) tab.focus();
  }
  if (tabs.length) {
    select(tabs[0], false);
    tabs.forEach(function (t, i) {
      t.addEventListener("click", function () { select(t, false); });
      t.addEventListener("keydown", function (ev) {
        var j = null;
        if (ev.key === "ArrowRight") j = (i + 1) % tabs.length;
        else if (ev.key === "ArrowLeft") j = (i - 1 + tabs.length) % tabs.length;
        else if (ev.key === "Home") j = 0;
        else if (ev.key === "End") j = tabs.length - 1;
        if (j !== null) { ev.preventDefault(); select(tabs[j], true); }
      });
    });
  }

  /* ------------------------------------------------------------- copy */
  var toastEl = document.querySelector(".toast"), toastTimer;
  function toast(msg) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.classList.add("is-on");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.classList.remove("is-on"); }, 2400);
  }
  Array.prototype.forEach.call(document.querySelectorAll("[data-copy]"), function (b) {
    b.addEventListener("click", function () {
      var text = b.getAttribute("data-copy");
      var label = b.querySelector(".copy-btn__label");
      function ok() {
        if (label) { label.textContent = "Copied"; setTimeout(function () { label.textContent = "Copy"; }, 1800); }
        toast("Copied. First down.");
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(ok, function () { toast("Copy blocked: select the text instead."); });
      } else {
        toast("Copy isn't available here: select the text instead.");
      }
    });
  });
})();
