import { Chart, registerables } from 'chart.js';
Chart.register(...registerables);

window.NexusCharts = {
    /**
     * Portal engagement line chart (Views + P24 lead counts over time). Returns
     * the Chart instance; the caller mutates chart.data + calls chart.update()
     * when the range filter changes. Colours work on light and dark surfaces.
     */
    portalEngagement(ctxEl, labels, viewsData, leadsData) {
        if (!ctxEl) return null;
        return new Chart(ctxEl, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        type: 'line',
                        label: 'Views',
                        data: viewsData,
                        borderColor: '#00d4aa',
                        backgroundColor: 'rgba(0, 212, 170, 0.12)',
                        borderWidth: 2,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        tension: 0.3,
                        fill: true,
                        yAxisID: 'y',
                        order: 2,
                    },
                    {
                        // Leads render as bars on their OWN right-hand axis — their
                        // magnitude is tiny next to views, so a shared axis would
                        // flat-line them. Bars make single-day leads visible.
                        type: 'bar',
                        label: 'P24 Leads',
                        data: leadsData,
                        backgroundColor: 'rgba(239, 68, 68, 0.55)',
                        borderColor: '#ef4444',
                        borderWidth: 0,
                        borderRadius: 2,
                        barPercentage: 0.9,
                        categoryPercentage: 0.9,
                        maxBarThickness: 14,
                        yAxisID: 'yLeads',
                        order: 1,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        align: 'end',
                        labels: { boxWidth: 10, boxHeight: 10, font: { size: 11 }, color: '#9ca3af', usePointStyle: true },
                    },
                    tooltip: {
                        backgroundColor: '#1f2937',
                        titleFont: { size: 12 },
                        bodyFont: { size: 12 },
                        padding: 10,
                        cornerRadius: 6,
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        title: { display: true, text: 'Views', font: { size: 10 }, color: '#00d4aa' },
                        grid: { color: 'rgba(148, 163, 184, 0.15)' },
                        ticks: { font: { size: 11 }, color: '#9ca3af', precision: 0 },
                        border: { display: false },
                    },
                    yLeads: {
                        beginAtZero: true,
                        position: 'right',
                        // Keep the lead axis to whole numbers and give a little
                        // headroom so a lone lead doesn't become a full-height bar.
                        suggestedMax: 4,
                        title: { display: true, text: 'Leads', font: { size: 10 }, color: '#ef4444' },
                        grid: { drawOnChartArea: false },
                        ticks: { font: { size: 11 }, color: '#9ca3af', precision: 0, stepSize: 1 },
                        border: { display: false },
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 10 }, color: '#9ca3af', maxRotation: 0, autoSkip: true, maxTicksLimit: 8 },
                        border: { display: false },
                    },
                },
            },
        });
    },

    transactionVolume(canvasId, labels, data) {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return null;

        return new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Transactions',
                    data: data,
                    backgroundColor: 'rgba(79, 70, 229, 0.8)',
                    borderColor: 'rgba(79, 70, 229, 1)',
                    borderWidth: 0,
                    borderRadius: 4,
                    barPercentage: 0.6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1f2937',
                        titleFont: { size: 12 },
                        bodyFont: { size: 12 },
                        padding: 10,
                        cornerRadius: 6,
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f3f4f6' },
                        ticks: {
                            font: { size: 11 },
                            color: '#9ca3af',
                            stepSize: 1,
                        },
                        border: { display: false },
                    },
                    x: {
                        grid: { display: false },
                        ticks: {
                            font: { size: 11 },
                            color: '#9ca3af',
                        },
                        border: { display: false },
                    }
                }
            }
        });
    }
};
