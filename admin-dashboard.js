// Make functions globally available immediately (before DOMContentLoaded)
window.handleApproval = function(itemId, itemType, status) {
  if (!itemId || !itemType || !status) {
    console.error('Missing parameters for handleApproval');
    if (typeof showNotification === 'function') {
      showNotification('Invalid request parameters', 'error');
    } else {
      alert('Invalid request parameters');
    }
    return;
  }

  const actionText = status === 'approved' ? 'approve' :
                     status === 'rejected' ? 'reject' :
                     status === 'activated' ? 'activate' :
                     status === 'deactivated' ? 'deactivate' :
                     status === 'verified' ? 'verify' :
                     status === 'resolved' ? 'resolve' :
                     status === 'dismissed' ? 'dismiss' : 'process';

  if (!confirm(`Are you sure you want to ${actionText} this item?`)) {
    return;
  }

  // Disable button during processing
  const buttons = document.querySelectorAll(`[onclick*="handleApproval(${itemId}"`);
  buttons.forEach(btn => {
    btn.disabled = true;
    btn.style.opacity = '0.6';
    btn.style.cursor = 'not-allowed';
  });

  const formData = new FormData();
  formData.append('action', 'approve');
  formData.append('item_type', itemType);
  formData.append('item_id', itemId);
  formData.append('status', status);

  fetch('admin_approval_handler.php', {
    method: 'POST',
    body: formData
  })
  .then(response => {
    if (!response.ok) {
      throw new Error('Network response was not ok');
    }
    return response.json();
  })
  .then(data => {
    if (data.success) {
      // Remove the item from the list
      const item = document.querySelector(`[data-id="${itemId}"][data-type="${itemType}"]`);
      if (item) {
        item.setAttribute('data-removed', 'true');
        item.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        item.style.opacity = '0.5';
        item.style.transform = 'translateX(-20px)';
        item.style.pointerEvents = 'none';

        // Update tab counts immediately
        setTimeout(() => {
          updateTabCounts();
        }, 100);

        // Remove from DOM after animation
        setTimeout(() => {
          item.remove();
          updateTabCounts();
        }, 500);
      }

      // Show success message
      if (typeof showNotification === 'function') {
        showNotification(data.message || 'Action completed successfully', 'success');
      } else {
        alert(data.message || 'Action completed successfully');
      }

      // Reload page after 1.5 seconds to refresh data and get accurate counts
      setTimeout(() => {
        window.location.reload();
      }, 1500);
    } else {
      if (typeof showNotification === 'function') {
        showNotification(data.message || 'Failed to process request', 'error');
      } else {
        alert(data.message || 'Failed to process request');
      }
      // Re-enable buttons on error
      buttons.forEach(btn => {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
      });
    }
  })
  .catch(error => {
    console.error('Error:', error);
    if (typeof showNotification === 'function') {
      showNotification('An error occurred. Please try again.', 'error');
    } else {
      alert('An error occurred. Please try again.');
    }
    // Re-enable buttons on error
    buttons.forEach(btn => {
      btn.disabled = false;
      btn.style.opacity = '1';
      btn.style.cursor = 'pointer';
    });
  });
};

window.loadMoreItems = function(type, total) {
  if (!type) {
    console.error('Missing type parameter for loadMoreItems');
    return;
  }

  // Show all items by removing the limit
  const tabContent = document.querySelector(`[data-content="${type}"]`);
  if (tabContent) {
    const list = tabContent.querySelector('.approval-list');
    if (list) {
      // Show all hidden items
      const hiddenItems = list.querySelectorAll('.approval-item[style*="display: none"]');
      hiddenItems.forEach(item => {
        item.style.display = 'flex';
      });

      // Hide the "View All" button
      const viewAllBtn = list.nextElementSibling;
      if (viewAllBtn && viewAllBtn.tagName === 'BUTTON') {
        viewAllBtn.style.display = 'none';
      }

      // Also check for button in parent
      const parentBtn = tabContent.querySelector(`button[onclick*="loadMoreItems('${type}'"]`);
      if (parentBtn) {
        parentBtn.style.display = 'none';
      }
    }
  }
};

document.addEventListener('DOMContentLoaded', () => {
  console.log('DOM Content Loaded - Initializing admin dashboard');

  const chartPayload = window.SAFE_SPACE_ADMIN_DATA || {};
  console.log('Chart payload received:', chartPayload);

  const severityCanvas = document.getElementById('severityChart');
  const categoryCanvas = document.getElementById('categoryChart');
  const trendCanvas = document.getElementById('trendChart');
  const alertCanvas = document.getElementById('alertChart');

  console.log('Canvas elements found:', {
    severity: !!severityCanvas,
    category: !!categoryCanvas,
    trend: !!trendCanvas,
    alert: !!alertCanvas
  });

  if (!severityCanvas || !categoryCanvas || !trendCanvas || !alertCanvas) {
    console.error('One or more canvas elements not found!');
  }

  let severityChart;
  let categoryChart;
  let trendChart;
  let alertChart;

  const chartColors = {
    severity: {
      low: '#6ef5c5',
      medium: '#ffd166',
      high: '#ff9f43',
      critical: '#ff6384',
    },
    categoryPalette: [
      '#15d1ff',
      '#86f6ff',
      '#00ffc7',
      '#ffc857',
      '#ff6b6b',
      '#8c7bff',
      '#4cd3c2',
      '#ffc4d6',
    ],
  };

  Chart.defaults.font.family =
    '"Inter", "Figtree", system-ui, -apple-system, "Segoe UI", sans-serif';
  Chart.defaults.font.size = 14;
  Chart.defaults.font.weight = '500';

  // Update chart colors based on theme
  const isDarkTheme = document.body.classList.contains('dark-theme');
  Chart.defaults.color = isDarkTheme ? '#94a3b8' : '#475569';

  Chart.defaults.plugins.legend.labels.usePointStyle = true;
  Chart.defaults.plugins.legend.labels.padding = 18;
  Chart.defaults.plugins.legend.labels.font = { size: 13, weight: '600' };

  // Update chart colors when theme changes
  const observer = new MutationObserver(() => {
    const isDark = document.body.classList.contains('dark-theme');
    Chart.defaults.color = isDark ? '#94a3b8' : '#475569';
    // Rebuild charts if they exist
    if (severityChart) severityChart.update();
    if (categoryChart) categoryChart.update();
    if (trendChart) trendChart.update();
    if (alertChart) alertChart.update();
  });

  observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });

  function buildSeverityChart() {
    if (!severityCanvas) {
      console.error('Severity chart canvas not found');
      return;
    }

    console.log('Building severity chart...');

    if (severityChart) {
      severityChart.destroy();
    }

    // Ensure we have data - show all severities even if 0
    const severityData = chartPayload.severity || {};
    const allSeverities = ['low', 'medium', 'high', 'critical'];
    const labels = allSeverities.map(label =>
      label.charAt(0).toUpperCase() + label.slice(1)
    );
    const values = allSeverities.map(sev => severityData[sev] || 0);

    // Check if Chart is available
    if (typeof Chart === 'undefined') {
      console.error('Chart.js library not loaded');
      const ctx = severityCanvas.getContext('2d');
      ctx.clearRect(0, 0, severityCanvas.width, severityCanvas.height);
      ctx.font = '14px Inter';
      ctx.fillStyle = '#ef4444';
      ctx.textAlign = 'center';
      ctx.fillText('Chart.js not loaded', severityCanvas.width / 2, severityCanvas.height / 2);
      return;
    }

    console.log('Severity chart data:', { labels, values });

    severityChart = new Chart(severityCanvas, {
      type: 'doughnut',
      data: {
        labels,
        datasets: [
          {
            data: values,
            backgroundColor: labels.map((label) => {
              const key = label.toLowerCase();
              return chartColors.severity[key] || '#00ffc7';
            }),
            borderColor: 'rgba(5,12,26,0.9)',
            borderWidth: 2,
            hoverOffset: 10,
          },
        ],
      },
      options: {
        cutout: '65%',
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              color: document.body.classList.contains('dark-theme') ? '#94a3b8' : '#475569',
              font: { size: 14, weight: '600' },
              padding: 12,
            },
          },
          tooltip: {
            backgroundColor: 'rgba(5,16,34,0.92)',
            padding: 14,
            borderColor: 'rgba(0,255,231,0.35)',
            borderWidth: 1,
            titleFont: { size: 15, weight: '600' },
            bodyFont: { size: 14, weight: '500' },
            callbacks: {
              label: (context) => {
                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                const value = context.formattedValue;
                const percentage =
                  total > 0 ? ((context.parsed / total) * 100).toFixed(1) : 0;
                return `${value} cases (${percentage}%)`;
              },
            },
          },
        },
      },
    });
  }

  function buildCategoryChart() {
    if (!categoryCanvas) {
      console.error('Category chart canvas not found');
      return;
    }

    console.log('Building category chart...');

    if (categoryChart) {
      categoryChart.destroy();
    }

    // Ensure we have data
    const categoryData = chartPayload.categories || {};
    const labels = Object.keys(categoryData).map((label) => {
      // Truncate long labels to fit better
      let formatted = label.replace('_', ' ').toUpperCase();
      if (formatted.length > 15) {
        formatted = formatted.substring(0, 12) + '...';
      }
      return formatted;
    });
    const values = Object.values(categoryData);

    // Check if Chart is available
    if (typeof Chart === 'undefined') {
      console.error('Chart.js library not loaded');
      const ctx = categoryCanvas.getContext('2d');
      ctx.clearRect(0, 0, categoryCanvas.width, categoryCanvas.height);
      ctx.font = '14px Inter';
      ctx.fillStyle = '#ef4444';
      ctx.textAlign = 'center';
      ctx.fillText('Chart.js not loaded', categoryCanvas.width / 2, categoryCanvas.height / 2);
      return;
    }

    // If no data, show placeholder with sample data
    if (labels.length === 0 || values.length === 0 || values.every(v => v === 0)) {
      console.log('No category data, showing placeholder');
      labels.push('Sample Category 1', 'Sample Category 2', 'Sample Category 3');
      values.push(5, 3, 2);
    }

    console.log('Category chart data:', { labels, values });

    categoryChart = new Chart(categoryCanvas, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            label: 'Reports',
            data: values,
            backgroundColor: chartColors.categoryPalette.slice(
              0,
              values.length
            ),
            borderRadius: 10,
            borderSkipped: false,
            maxBarThickness: 28,
          },
        ],
      },
      options: {
        indexAxis: 'y',
        scales: {
          x: {
            grid: {
              color: 'rgba(0,180,255,0.1)',
              borderDash: [4, 6],
            },
            ticks: {
              color: document.body.classList.contains('dark-theme') ? '#64748b' : '#475569',
              precision: 0,
              font: { size: 13, weight: '500' },
            },
            title: {
              display: false,
            },
          },
          y: {
            grid: {
              display: false,
            },
            ticks: {
              color: document.body.classList.contains('dark-theme') ? '#1e293b' : '#1e293b',
              font: { size: 9, weight: '500' },
              padding: 4,
              maxRotation: 0,
              autoSkip: false,
            },
            title: {
              display: false,
            },
          },
        },
        plugins: {
          legend: {
            display: false,
          },
          tooltip: {
            backgroundColor: 'rgba(4,13,28,0.92)',
            borderColor: 'rgba(0,255,231,0.35)',
            borderWidth: 1,
            padding: 12,
          },
        },
      },
    });
  }

  function buildTrendChart() {
    if (!trendCanvas) {
      console.error('Trend chart canvas not found');
      return;
    }

    console.log('Building trend chart...');

    if (trendChart) {
      trendChart.destroy();
    }

    if (trendCanvas.parentElement) {
      trendCanvas.parentElement.classList.remove('chart-empty');
    }

    let trendData = Array.isArray(chartPayload.trend) ? chartPayload.trend : [];

    // Check if Chart is available
    if (typeof Chart === 'undefined') {
      console.error('Chart.js library not loaded');
      const ctx = trendCanvas.getContext('2d');
      ctx.clearRect(0, 0, trendCanvas.width, trendCanvas.height);
      ctx.font = '14px Inter';
      ctx.fillStyle = '#ef4444';
      ctx.textAlign = 'center';
      ctx.fillText('Chart.js not loaded', trendCanvas.width / 2, trendCanvas.height / 2);
      return;
    }

    // If no data, create sample data for last 7 days
    if (!trendData.length) {
      console.log('No trend data, creating sample data');
      const today = new Date();
      trendData = [];
      for (let i = 6; i >= 0; i--) {
        const date = new Date(today);
        date.setDate(date.getDate() - i);
        trendData.push({
          day: date.toISOString().split('T')[0],
          total: Math.floor(Math.random() * 10) + 1,
          resolved: Math.floor(Math.random() * 5),
          critical: Math.floor(Math.random() * 3)
        });
      }
    }

    console.log('Trend chart data:', trendData);

    const labels = trendData.map((row) => {
      if (!row.day) return '';
      const date = new Date(row.day);
      if (Number.isNaN(date.getTime())) {
        return row.day;
      }
      return date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
      });
    });

    const totals = trendData.map((row) => row.total || 0);
    const resolved = trendData.map((row) => row.resolved || 0);
    const critical = trendData.map((row) => row.critical || 0);

    trendChart = new Chart(trendCanvas, {
      type: 'line',
      data: {
        labels,
        datasets: [
          {
            label: 'Total Reports',
            data: totals,
            borderColor: '#15d1ff',
            backgroundColor: 'rgba(21, 209, 255, 0.18)',
            tension: 0.35,
            fill: true,
            pointRadius: 4,
            pointHoverRadius: 6,
          },
          {
            label: 'Resolved',
            data: resolved,
            borderColor: '#6ef5c5',
            backgroundColor: 'rgba(110, 245, 197, 0.16)',
            tension: 0.35,
            fill: true,
            pointRadius: 4,
            pointHoverRadius: 6,
          },
          {
            label: 'Critical Detected',
            data: critical,
            borderColor: '#ff6384',
            backgroundColor: 'rgba(255, 99, 132, 0.18)',
            borderDash: [6, 6],
            tension: 0.35,
            pointRadius: 4,
            pointHoverRadius: 6,
            fill: false,
          },
        ],
      },
      options: {
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              color: document.body.classList.contains('dark-theme') ? '#94a3b8' : '#475569',
              padding: 18,
              usePointStyle: true,
              font: { size: 14, weight: '600' },
            },
          },
          tooltip: {
            backgroundColor: document.body.classList.contains('dark-theme') ? 'rgba(15,23,42,0.95)' : 'rgba(4,13,28,0.92)',
            borderColor: 'rgba(0,255,231,0.35)',
            borderWidth: 1,
            padding: 14,
            intersect: false,
            mode: 'index',
            titleFont: { size: 15, weight: '600' },
            bodyFont: { size: 14, weight: '500' },
          },
        },
        scales: {
          x: {
            grid: {
              color: document.body.classList.contains('dark-theme') ? 'rgba(51,65,85,0.3)' : 'rgba(0, 132, 255, 0.1)',
              borderDash: [3, 6],
            },
            ticks: {
              color: document.body.classList.contains('dark-theme') ? '#94a3b8' : '#475569',
              font: { size: 13, weight: '500' },
            },
          },
          y: {
            grid: {
              color: document.body.classList.contains('dark-theme') ? 'rgba(51,65,85,0.3)' : 'rgba(0, 132, 255, 0.08)',
              borderDash: [3, 6],
            },
            ticks: {
              color: document.body.classList.contains('dark-theme') ? '#94a3b8' : '#475569',
              font: { size: 13, weight: '500' },
              precision: 0,
            },
          },
        },
      },
    });
  }

  function buildAlertChart() {
    if (!alertCanvas || !chartPayload.alerts) return;
    if (alertChart) {
      alertChart.destroy();
    }

    if (alertCanvas.parentElement) {
      alertCanvas.parentElement.classList.remove('chart-empty');
    }

    const alertEntries = Object.entries(chartPayload.alerts);
    if (!alertEntries.length) {
      alertCanvas.parentElement.classList.add('chart-empty');
      return;
    }

    const labels = alertEntries.map(([severity]) =>
      severity.replace('_', ' ').toUpperCase()
    );
    const values = alertEntries.map(([, count]) => count);

    alertChart = new Chart(alertCanvas, {
      type: 'polarArea',
      data: {
        labels,
        datasets: [
          {
            data: values,
            backgroundColor: ['#ff6384', '#ff9f43', '#ffd166', '#6ef5c5'],
            borderWidth: 1,
          },
        ],
      },
      options: {
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              color: document.body.classList.contains('dark-theme') ? '#94a3b8' : '#475569',
              padding: 16,
              usePointStyle: true,
              font: { size: 14, weight: '600' },
            },
          },
          tooltip: {
            backgroundColor: document.body.classList.contains('dark-theme') ? 'rgba(15,23,42,0.95)' : 'rgba(4,13,28,0.92)',
            borderColor: 'rgba(0,255,231,0.35)',
            borderWidth: 1,
            padding: 14,
            titleFont: { size: 15, weight: '600' },
            bodyFont: { size: 14, weight: '500' },
          },
        },
        scales: {
          r: {
            grid: {
              color: document.body.classList.contains('dark-theme') ? 'rgba(51,65,85,0.3)' : 'rgba(0, 255, 231, 0.12)',
            },
            angleLines: {
              color: document.body.classList.contains('dark-theme') ? 'rgba(51,65,85,0.3)' : 'rgba(0, 255, 231, 0.12)',
            },
            ticks: {
              color: document.body.classList.contains('dark-theme') ? '#94a3b8' : '#475569',
              backdropColor: 'transparent',
              font: { size: 13, weight: '500' },
            },
          },
        },
      },
    });
  }

  function initialiseCharts() {
    console.log('Initializing charts...');
    console.log('Chart.js available:', typeof Chart !== 'undefined');
    console.log('Chart payload:', chartPayload);

    try {
      if (typeof Chart === 'undefined') {
        console.error('Chart.js is not loaded! Please check the script tag.');
        return;
      }

      buildSeverityChart();
      buildCategoryChart();
      buildTrendChart();
      buildAlertChart();

      console.log('All charts initialized successfully');
    } catch (error) {
      console.error('Error initializing charts:', error);
      console.error('Error stack:', error.stack);
    }
  }

  // Real-time data refresh function
  function refreshChartData() {
    fetch('admin_dashboard.php?ajax=1&refresh=' + Date.now())
      .then(response => response.json())
      .then(data => {
        if (data.success && data.chartData) {
          // Update chart payload
          Object.assign(chartPayload, data.chartData);
          // Rebuild all charts
          initialiseCharts();
        }
      })
      .catch(error => {
        console.error('Error refreshing chart data:', error);
      });
  }

  // Auto-refresh every 30 seconds
  let refreshInterval = setInterval(refreshChartData, 30000);

  // Manual refresh button
  const refreshButtons = document.querySelectorAll('[data-trend-refresh], [data-chart-refresh]');
  refreshButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      btn.classList.add('is-refreshing');
      refreshChartData();
      setTimeout(() => btn.classList.remove('is-refreshing'), 600);
    });
  });

  // Initialize charts - wait for Chart.js if needed
  if (typeof Chart !== 'undefined') {
    // Chart.js already loaded
    initialiseCharts();
  } else {
    // Wait for Chart.js to load
    let attempts = 0;
    const checkChart = setInterval(() => {
      attempts++;
      if (typeof Chart !== 'undefined') {
        clearInterval(checkChart);
        console.log('Chart.js loaded, initializing charts...');
        initialiseCharts();
      } else if (attempts >= 50) {
        clearInterval(checkChart);
        console.error('Chart.js failed to load');
        // Show error message on canvases
        [severityCanvas, categoryCanvas, trendCanvas, alertCanvas].forEach(canvas => {
          if (canvas) {
            const ctx = canvas.getContext('2d');
            ctx.font = '14px Inter';
            ctx.fillStyle = '#ef4444';
            ctx.textAlign = 'center';
            ctx.fillText('Chart.js failed to load', canvas.width / 2, canvas.height / 2);
          }
        });
      }
    }, 100);
  }


  // Tab switching functionality
  const tabButtons = document.querySelectorAll('.tab-btn');
  const tabContents = document.querySelectorAll('.tab-content');

  tabButtons.forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();

      const targetTab = btn.getAttribute('data-tab');
      if (!targetTab) return;

      // Remove active class from all tabs and contents
      tabButtons.forEach(b => b.classList.remove('active'));
      tabContents.forEach(c => c.classList.remove('active'));

      // Add active class to clicked tab and corresponding content
      btn.classList.add('active');
      const targetContent = document.querySelector(`[data-content="${targetTab}"]`);
      if (targetContent) {
        targetContent.classList.add('active');
      }
    });
  });

  // Make handleApproval available globally
  window.handleApproval = handleApproval;
  window.loadMoreItems = loadMoreItems;
});

// Admin approval handler - Make it globally available
window.handleApproval = function(itemId, itemType, status) {
  if (!itemId || !itemType || !status) {
    console.error('Missing parameters for handleApproval');
    showNotification('Invalid request parameters', 'error');
    return;
  }

  const actionText = status === 'approved' ? 'approve' :
                     status === 'rejected' ? 'reject' :
                     status === 'activated' ? 'activate' :
                     status === 'deactivated' ? 'deactivate' :
                     status === 'verified' ? 'verify' :
                     status === 'resolved' ? 'resolve' :
                     status === 'dismissed' ? 'dismiss' : 'process';

  if (!confirm(`Are you sure you want to ${actionText} this item?`)) {
    return;
  }

  // Disable button during processing
  const buttons = document.querySelectorAll(`[onclick*="handleApproval(${itemId}"`);
  buttons.forEach(btn => {
    btn.disabled = true;
    btn.style.opacity = '0.6';
    btn.style.cursor = 'not-allowed';
  });

  const formData = new FormData();
  formData.append('action', 'approve');
  formData.append('item_type', itemType);
  formData.append('item_id', itemId);
  formData.append('status', status);

  fetch('admin_approval_handler.php', {
    method: 'POST',
    body: formData
  })
  .then(response => {
    if (!response.ok) {
      throw new Error('Network response was not ok');
    }
    return response.json();
  })
  .then(data => {
    if (data.success) {
      // Remove the item from the list
      const item = document.querySelector(`[data-id="${itemId}"][data-type="${itemType}"]`);
      if (item) {
        item.setAttribute('data-removed', 'true');
        item.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        item.style.opacity = '0.5';
        item.style.transform = 'translateX(-20px)';
        item.style.pointerEvents = 'none';
      }

      // Update tab counts with real database counts if available
      if (data.counts) {
        updateTabCountsFromServer(data.counts);
      } else {
        // Fallback to DOM counting
        setTimeout(() => {
          if (item) item.remove();
          updateTabCounts();
        }, 500);
      }

      // Show success message
      if (typeof showNotification === 'function') {
        showNotification(data.message || 'Action completed successfully', 'success');
      } else {
        alert(data.message || 'Action completed successfully');
      }

      // Reload page after 1.5 seconds to refresh data and get accurate counts
      setTimeout(() => {
        window.location.reload();
      }, 1500);
    } else {
      showNotification(data.message || 'Failed to process request', 'error');
      // Re-enable buttons on error
      buttons.forEach(btn => {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
      });
    }
  })
  .catch(error => {
    console.error('Error:', error);
    showNotification('An error occurred. Please try again.', 'error');
    // Re-enable buttons on error
    buttons.forEach(btn => {
      btn.disabled = false;
      btn.style.opacity = '1';
      btn.style.cursor = 'pointer';
    });
  });
};

function updateTabCounts() {
  const tabs = document.querySelectorAll('.tab-btn');
  tabs.forEach(tab => {
    const contentId = tab.getAttribute('data-tab');
    const content = document.querySelector(`[data-content="${contentId}"]`);
    if (!content) return;

    // Count visible items (not hidden or removed)
    const items = content.querySelectorAll('.approval-item');
    let count = 0;
    items.forEach(item => {
      const style = window.getComputedStyle(item);
      if (style.display !== 'none' && style.opacity !== '0.5' && !item.hasAttribute('data-removed')) {
        count++;
      }
    });

    // Update tab text with new count
    const tabText = tab.textContent.replace(/\s*\(\d+\)/, '').trim();
    tab.textContent = `${tabText} (${count})`;
  });
}

function updateTabCountsFromServer(counts) {
  const tabMap = {
    'reports': 'Reports',
    'groups': 'Groups',
    'disputes': 'Disputes',
    'legal': 'Legal Providers',
    'medical': 'Medical Providers',
    'alerts': 'Alerts',
    'spaces': 'Safe Spaces'
  };

  const tabs = document.querySelectorAll('.tab-btn');
  tabs.forEach(tab => {
    const tabText = tab.textContent.replace(/\s*\(\d+\)/, '').trim();

    // Find matching count
    for (const [key, label] of Object.entries(tabMap)) {
      if (tabText.includes(label)) {
        const count = counts[key] || 0;
        tab.textContent = `${tabText} (${count})`;
        break;
      }
    }
  });
}

function showNotification(message, type) {
  const notification = document.createElement('div');
  notification.className = `notification notification-${type}`;
  notification.textContent = message;
  notification.style.cssText = `
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 16px 24px;
    background: ${type === 'success' ? '#10b981' : '#ef4444'};
    color: white;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 10000;
    animation: slideIn 0.3s ease-out;
  `;

  document.body.appendChild(notification);

  setTimeout(() => {
    notification.style.animation = 'slideOut 0.3s ease-out';
    setTimeout(() => notification.remove(), 300);
  }, 3000);
}

// Make loadMoreItems globally available
window.loadMoreItems = function(type, total) {
  if (!type) {
    console.error('Missing type parameter for loadMoreItems');
    return;
  }

  console.log('Showing all items for type:', type, 'Total:', total);

  // Show all items that are already in the DOM (just hidden)
  const tabContent = document.querySelector(`[data-content="${type}"]`);
  if (tabContent) {
    const list = tabContent.querySelector('.approval-list');
    if (list) {
      // Show all hidden items
      const hiddenItems = list.querySelectorAll('.approval-item.hidden-item, .approval-item[style*="display: none"]');
      console.log('Found hidden items:', hiddenItems.length);

      hiddenItems.forEach(item => {
        item.style.display = 'flex';
        item.classList.remove('hidden-item');
      });

      // Hide the "View All" button container
      const viewAllContainer = list.parentElement.querySelector('div[style*="text-align: center"]');
      if (viewAllContainer) {
        viewAllContainer.style.display = 'none';
      }

      // Also hide button directly
      const viewAllBtn = tabContent.querySelector(`button[onclick*="loadMoreItems('${type}'"]`);
      if (viewAllBtn) {
        viewAllBtn.style.display = 'none';
      }

      // Also check parent container
      const parentContainer = tabContent.closest('.panel');
      if (parentContainer) {
        const parentBtn = parentContainer.querySelector(`button[onclick*="loadMoreItems('${type}'"]`);
        if (parentBtn) {
          parentBtn.style.display = 'none';
        }
        const parentDiv = parentContainer.querySelector('div[style*="text-align: center"]');
        if (parentDiv && parentDiv.querySelector(`button[onclick*="loadMoreItems('${type}'"]`)) {
          parentDiv.style.display = 'none';
        }
      }

      // Update tab count
      updateTabCounts();

      console.log('All items shown successfully');
    } else {
      console.error('Approval list not found for type:', type);
    }
  } else {
    console.error('Tab content not found for type:', type);
  }
};
