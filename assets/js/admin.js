// Admin analytics charts (Chart.js)
const TEAL = '#0E0F0C', MINT = '#B5E21F', CORAL = '#E5484D', GOLD = '#F4B942', BLUE = '#9DB8FF', GRID = '#ECEEE8';
Chart.defaults.font.family = "'Plus Jakarta Sans', system-ui, sans-serif";
Chart.defaults.color = '#4A5E5B';
const quiet = { grid: { color: GRID }, border: { display: false } };
Chart.defaults.maintainAspectRatio = false;

// Forecast: history (solid) + forecast (dashed), joined at the last real day
const labels = [...D.histLabels, ...D.fcLabels];
const hist = [...D.histVals, ...Array(D.fcVals.length).fill(null)];
const fc = [...Array(D.histVals.length - 1).fill(null), D.histVals[D.histVals.length - 1], ...D.fcVals];
new Chart(document.getElementById('fcChart'), {
  type: 'line',
  data: { labels, datasets: [
    { label: 'Actual bookings', data: hist, borderColor: TEAL, backgroundColor: 'rgba(18,60,66,.08)', fill: true, tension: .35, pointRadius: 3 },
    { label: 'Forecast (with weather)', data: fc, borderColor: CORAL, borderDash: [6, 5], tension: .35, pointRadius: 3, pointBackgroundColor: CORAL },
    { label: 'Forecast without weather', data: [...Array(D.histVals.length).fill(null), ...D.fcBaseVals], borderColor: '#9AABA8', borderDash: [2, 4], tension: .35, pointRadius: 0 },
  ] },
  options: { plugins: { legend: { position: 'bottom' } }, scales: { x: { grid: { display: false } }, y: { ...quiet, beginAtZero: true } } },
});

new Chart(document.getElementById('svcChart'), {
  type: 'doughnut',
  data: { labels: D.svcLabels, datasets: [{ data: D.svcVals, backgroundColor: [TEAL, MINT, CORAL, GOLD, BLUE], borderWidth: 0 }] },
  options: { cutout: '62%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 10 } } } },
});

new Chart(document.getElementById('areaChart'), {
  type: 'bar',
  data: { labels: D.areaLabels, datasets: [{ data: D.areaVals, backgroundColor: D.areaVals.map((v, i) => i === 0 ? CORAL : MINT), borderRadius: 6 }] },
  options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: quiet, y: { grid: { display: false }, ticks: { autoSkip: false } } } },
});

const maxH = Math.max(...D.hourVals);
new Chart(document.getElementById('hourChart'), {
  type: 'bar',
  data: { labels: D.hourLabels, datasets: [{ data: D.hourVals, backgroundColor: D.hourVals.map(v => v >= maxH * .8 ? CORAL : TEAL), borderRadius: 4 }] },
  options: { plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: quiet } },
});

new Chart(document.getElementById('utilChart'), {
  type: 'bar',
  data: { labels: D.utilLabels, datasets: [{ label: 'Utilisation %', data: D.utilVals, backgroundColor: D.utilVals.map(v => v < 15 ? GOLD : MINT), borderRadius: 4 }] },
  options: { plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => c.raw + '% of capacity' } } },
    scales: { x: { grid: { display: false }, ticks: { autoSkip: false, maxRotation: 45, minRotation: 45 } }, y: { ...quiet, beginAtZero: true, ticks: { callback: v => v + '%' } } } },
});
