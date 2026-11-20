/* Optima Core map: the Google map loads in place only when a visitor taps "Show the map". */
document.addEventListener('click', function (e) {
	var a = e.target.closest && e.target.closest('.optima-map-show');
	if (!a) { return; }
	var m = a.closest('.optima-map');
	if (!m || m.classList.contains('is-on')) { return; }
	e.preventDefault();
	var h = m.offsetHeight;
	var f = document.createElement('iframe');
	f.src = m.getAttribute('data-optima-map');
	f.title = m.getAttribute('data-optima-map-title') || 'Map';
	f.setAttribute('allowfullscreen', '');
	f.setAttribute('loading', 'lazy');
	f.setAttribute('referrerpolicy', 'no-referrer-when-downgrade');
	if (h > 200) { f.style.height = h + 'px'; }
	m.appendChild(f);
	m.classList.add('is-on');
	f.focus();
});
