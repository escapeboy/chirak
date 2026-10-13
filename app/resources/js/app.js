import './bootstrap';
import Chart from 'chart.js/auto';

const PALETTE = ['#94a3b8', '#4f46e5', '#0891b2', '#d97706'];

// curves: {label: [{step, loss}, ...]}
const renderLossChart = (canvas, curves) => {
    const labels = Object.keys(curves);
    new Chart(canvas, {
        type: 'line',
        data: {
            datasets: labels.map((label, i) => ({
                label,
                data: curves[label].map((p) => ({ x: p.step, y: p.loss })),
                borderColor: PALETTE[(i + 1) % PALETTE.length],
                backgroundColor: PALETTE[(i + 1) % PALETTE.length],
                borderWidth: 2,
                pointRadius: 2,
                tension: 0.2,
            })),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            parsing: false,
            scales: {
                x: { type: 'linear', title: { display: true, text: 'step' } },
                y: { title: { display: true, text: 'loss' } },
            },
        },
    });
};

// Livewire swaps DOM on every poll, so charts are created for any new canvas that appears.
const initCharts = () => {
    document.querySelectorAll('canvas[data-loss]:not([data-ready])').forEach((canvas) => {
        canvas.dataset.ready = '1';
        renderLossChart(canvas, JSON.parse(canvas.dataset.loss));
    });
};
new MutationObserver(initCharts).observe(document.documentElement, { childList: true, subtree: true });
document.addEventListener('DOMContentLoaded', initCharts);
