document.addEventListener("DOMContentLoaded", function () {
  const clamp = (value, min, max) => Math.min(Math.max(value, min), max);
  const formatCurrency = (() => {
    try {
      const nf = new Intl.NumberFormat("tr-TR", {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
      });
      return (value) => `₺${nf.format(value || 0)}`;
    } catch (err) {
      return (value) => `₺${(value || 0).toLocaleString("tr-TR")}`;
    }
  })();

  const progressBars = document.querySelectorAll(".burs-progress__inner[data-progress]");
  progressBars.forEach((bar) => {
    const value = Number.parseFloat(bar.dataset.progress || "0");
    requestAnimationFrame(() => {
      bar.style.width = `${clamp(value, 0, 100)}%`;
    });
  });

  if (typeof Chart === "undefined") {
    return;
  }

  Chart.defaults.font.family = "'Inter','Segoe UI','Helvetica Neue',Arial,sans-serif";
  Chart.defaults.font.size = 13;
  Chart.defaults.color = "#475569";

  const parseChartPayload = (canvas) => {
    try {
      return JSON.parse(canvas.dataset.chart || "{}");
    } catch (err) {
      console.warn("BursMetre: chart payload parse error", err);
      return null;
    }
  };

  let deptChartHeight = null;

  const deptCanvas = document.getElementById("deptChart");
  if (deptCanvas) {
    const payload = parseChartPayload(deptCanvas);
    if (payload && Array.isArray(payload.labels) && payload.labels.length) {
      const targetHeight = Math.max(360, Math.min(540, (payload.labels.length || 0) * 34));
      deptChartHeight = targetHeight;
      const canvasParent = deptCanvas.parentElement;
      if (canvasParent) {
        canvasParent.style.minHeight = `${targetHeight}px`;
        canvasParent.style.height = `${targetHeight}px`;
      }
      deptCanvas.height = targetHeight;
      deptCanvas.style.height = `${targetHeight}px`;

      new Chart(deptCanvas, {
        type: "bar",
        data: {
          labels: payload.labels,
          datasets: [
            {
              data: payload.values || [],
              backgroundColor: payload.colors || "#3b82f6",
              borderRadius: 12,
              borderSkipped: false,
              barThickness: 18
            }
          ]
        },
        options: {
          indexAxis: "y",
          maintainAspectRatio: false,
          responsive: true,
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: "#0f172a",
              padding: 12,
              cornerRadius: 10,
              titleFont: { weight: "700" },
              callbacks: {
                label: (ctx) => formatCurrency(ctx.parsed.x)
              }
            }
          },
          layout: {
            padding: { top: 8, bottom: 24, left: 12, right: 16 }
          },
          scales: {
            x: {
              beginAtZero: true,
              grid: { color: "rgba(148, 163, 184, 0.25)", drawTicks: false },
              ticks: {
                padding: 6,
                font: { size: 12 },
                callback: (val) => formatCurrency(val)
              }
            },
            y: {
              grid: { display: false },
              ticks: { color: "#0f172a", font: { weight: "600" }, padding: 6 }
            }
          }
        }
      });
    }
  }

  const topCanvas = document.getElementById("topCampaignsChart");
  if (topCanvas) {
    const payload = parseChartPayload(topCanvas);
    if (payload && Array.isArray(payload.labels) && payload.labels.length) {
      const computedHeight = Math.max(360, Math.min(540, (payload.labels.length || 0) * 34));
      const topHeight = deptChartHeight || computedHeight;
      const topParent = topCanvas.parentElement;
      if (topParent) {
        topParent.style.minHeight = `${topHeight}px`;
        topParent.style.height = `${topHeight}px`;
      }
      topCanvas.height = topHeight;
      topCanvas.style.height = `${topHeight}px`;

      new Chart(topCanvas, {
        type: "bar",
        data: {
          labels: payload.labels,
          datasets: [
            {
              data: payload.values || [],
              backgroundColor: payload.colors || "#ef4444",
              borderRadius: 12,
              borderSkipped: false,
              barThickness: 32
            }
          ]
        },
        options: {
          maintainAspectRatio: false,
          responsive: true,
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: "#0f172a",
              padding: 12,
              cornerRadius: 10,
              titleFont: { weight: "700" },
              callbacks: {
                label: (ctx) => formatCurrency(ctx.parsed.y)
              }
            }
          },
          layout: {
            padding: { top: 8, bottom: 32, left: 8, right: 16 }
          },
          scales: {
            x: {
              grid: { color: "rgba(148, 163, 184, 0.18)", drawTicks: false },
              ticks: {
                color: "#0f172a",
                font: { weight: "600", size: 12 },
                autoSkip: false,
                maxRotation: 48,
                minRotation: 48,
                align: "end",
                crossAlign: "far",
                padding: 6
              }
            },
            y: {
              beginAtZero: true,
              grid: { display: false },
              ticks: { callback: (val) => formatCurrency(val) }
            }
          }
        }
      });
    }
  }
});
