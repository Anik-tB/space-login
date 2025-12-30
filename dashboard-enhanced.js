/* ========================================
   SAFESPACE DASHBOARD - ENHANCED INTERACTIONS
   ======================================== */

class SafeSpaceDashboard {
  constructor() {
    this.currentTheme = 'dark';
    this.isLoading = false;
    this.notifications = [];
    this.ws = null;
    this.wsRequestId = 0;
    this.wsPending = new Map();
    this.init();
  }

  init() {
    this.setupWebSocket();
    this.setupTheme();
    this.setupAnimations();
    this.setupMicrointeractions();
    this.setupDataVisualization();
    this.setupAccessibility();
    this.setupKeyboardShortcuts();
    this.setupServiceWorker();
    this.setupRealTimeUpdates();
    this.setupSmoothScrolling();
  }

  /* ========================================
       THEME MANAGEMENT
       ======================================== */

  setupTheme() {
    // Load saved theme preference
    const savedTheme = localStorage.getItem('safespace-theme') || 'dark';
    this.setTheme(savedTheme);

    // Theme toggle functionality
    const themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
      themeToggle.addEventListener('click', () => {
        this.toggleTheme();
      });
    }

    // Auto-detect system preference
    if (!localStorage.getItem('safespace-theme')) {
      const prefersDark = window.matchMedia(
        '(prefers-color-scheme: dark)'
      ).matches;
      this.setTheme(prefersDark ? 'dark' : 'light');
    }

    // Listen for system theme changes
    window
      .matchMedia('(prefers-color-scheme: dark)')
      .addEventListener('change', (e) => {
        if (!localStorage.getItem('safespace-theme')) {
          this.setTheme(e.matches ? 'dark' : 'light');
        }
      });
  }

  setTheme(theme) {
    this.currentTheme = theme;
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('safespace-theme', theme);

    // Update theme toggle button
    const themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
      const icon = themeToggle.querySelector('i');
      if (icon) {
        icon.setAttribute('data-lucide', theme === 'dark' ? 'sun' : 'moon');
        lucide.createIcons();
      }
    }

    // Update meta theme color
    const metaThemeColor = document.querySelector('meta[name="theme-color"]');
    if (metaThemeColor) {
      metaThemeColor.setAttribute(
        'content',
        theme === 'dark' ? '#0b1c30' : '#f8fafc'
      );
    }

    // Update favicon based on theme
    this.updateFavicon(theme);

    // Trigger theme change event for other components
    window.dispatchEvent(new CustomEvent('themechange', { detail: { theme } }));
  }

  toggleTheme() {
    const newTheme = this.currentTheme === 'dark' ? 'light' : 'dark';

    // Create smooth transition overlay
    this.createThemeTransition(newTheme);

    // Delay theme change for smooth transition
    setTimeout(() => {
      this.setTheme(newTheme);

      // Remove transition overlay
      setTimeout(() => {
        this.removeThemeTransition();
      }, 300);
    }, 150);
  }

  createThemeTransition(newTheme) {
    // Remove existing transition overlay
    this.removeThemeTransition();

    const overlay = document.createElement('div');
    overlay.className = 'theme-transition';
    overlay.id = 'theme-transition-overlay';

    // Set the new theme's background gradient
    const root = document.documentElement;
    const oldTheme = root.getAttribute('data-theme') || 'dark';

    // Temporarily set new theme for overlay
    root.setAttribute('data-theme', newTheme);
    overlay.style.background = getComputedStyle(
      document.documentElement
    ).getPropertyValue('--bg-gradient');

    // Restore old theme
    root.setAttribute('data-theme', oldTheme);

    document.body.appendChild(overlay);

    // Trigger transition
    requestAnimationFrame(() => {
      overlay.classList.add('active');
    });
  }

  removeThemeTransition() {
    const overlay = document.getElementById('theme-transition-overlay');
    if (overlay) {
      overlay.classList.remove('active');
      setTimeout(() => {
        overlay.remove();
      }, 300);
    }
  }

  updateFavicon(theme) {
    const favicon = document.querySelector('link[rel="icon"]');
    if (favicon) {
      const iconColor = theme === 'dark' ? '#2ec4b6' : '#0d9488';
      const svgIcon = `<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><defs><linearGradient id='grad' x1='0%' y1='0%' x2='100%' y2='100%'><stop offset='0%' style='stop-color:${iconColor};stop-opacity:1' /><stop offset='100%' style='stop-color:${
        theme === 'dark' ? '#3aaed8' : '#0891b2'
      };stop-opacity:1' /></linearGradient></defs><text y='.9em' font-size='90' fill='url(#grad)'>🛡️</text></svg>`;
      favicon.href = `data:image/svg+xml,${encodeURIComponent(svgIcon)}`;
    }
  }

  // Enhanced theme-aware color utilities
  getThemeColor(colorName) {
    const root = document.documentElement;
    return getComputedStyle(root).getPropertyValue(`--${colorName}`).trim();
  }

  getContrastColor(backgroundColor) {
    // Simple contrast calculation
    const hex = backgroundColor.replace('#', '');
    const r = parseInt(hex.substr(0, 2), 16);
    const g = parseInt(hex.substr(2, 2), 16);
    const b = parseInt(hex.substr(4, 2), 16);
    const brightness = (r * 299 + g * 587 + b * 114) / 1000;
    return brightness > 128 ? '#000000' : '#ffffff';
  }

  // Theme-aware component updates
  updateComponentThemes() {
    // Update charts if they exist
    if (window.chartInstances) {
      window.chartInstances.forEach((chart) => {
        if (chart && typeof chart.update === 'function') {
          chart.update();
        }
      });
    }

    // Update any custom components
    this.updateCustomComponents();
  }

  updateCustomComponents() {
    // Update progress bars
    const progressBars = document.querySelectorAll('.progress-bar');
    progressBars.forEach((bar) => {
      const progress = bar.getAttribute('data-progress');
      if (progress) {
        bar.style.background = `linear-gradient(90deg, ${this.getThemeColor(
          'accent-cyan'
        )} ${progress}%, transparent ${progress}%)`;
      }
    });

    // Update status indicators
    const statusDots = document.querySelectorAll('.status-dot');
    statusDots.forEach((dot) => {
      const status = dot.parentElement.classList.contains('online')
        ? 'status-online'
        : dot.parentElement.classList.contains('warning')
        ? 'status-warning'
        : dot.parentElement.classList.contains('offline')
        ? 'status-error'
        : 'status-info';
      dot.style.background = this.getThemeColor(status);
      dot.style.boxShadow = `0 0 10px ${this.getThemeColor(status)}`;
    });

    // Update glass morphism effects
    this.updateGlassEffects();
  }

  updateGlassEffects() {
    const glassElements = document.querySelectorAll(
      '.card, .welcome-section, .mission-control, .activity-section'
    );
    glassElements.forEach((element) => {
      // Refresh backdrop filter
      element.style.backdropFilter = `blur(${this.getThemeColor(
        'glass-blur'
      )})`;

      // Update border colors
      element.style.borderColor = this.getThemeColor('glass-border');

      // Update background
      element.style.background = this.getThemeColor('glass-bg');
    });
  }

  /* ========================================
       ANIMATIONS & MICROINTERACTIONS
       ======================================== */

  setupAnimations() {
    // Intersection Observer for scroll animations
    const observerOptions = {
      threshold: 0.1,
      rootMargin: '0px 0px -50px 0px',
    };

    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('animate-fade-in');
          entry.target.style.opacity = '1';
          entry.target.style.transform = 'translateY(0)';
        }
      });
    }, observerOptions);

    // Observe all animated elements
    document.querySelectorAll('.animate-on-scroll').forEach((el) => {
      el.style.opacity = '0';
      el.style.transform = 'translateY(30px)';
      el.style.transition =
        'opacity 0.8s cubic-bezier(0.22, 1, 0.36, 1), transform 0.8s cubic-bezier(0.22, 1, 0.36, 1)';
      observer.observe(el);
    });

    // Stagger animations for cards
    this.staggerCardAnimations();
  }

  staggerCardAnimations() {
    const cards = document.querySelectorAll('.card');
    cards.forEach((card, index) => {
      card.style.animationDelay = `${index * 0.15}s`;
      card.classList.add('animate-slide-up');
    });
  }

  setupMicrointeractions() {
    // Button ripple effects
    this.setupRippleEffects();

    // Card hover effects
    this.setupCardHoverEffects();

    // Loading states
    this.setupLoadingStates();

    // Toast notifications
    this.setupToastSystem();

    // Tooltip system
    //this.setupTooltips();
  }

  setupRippleEffects() {
    document.addEventListener('click', (e) => {
      const button = e.target.closest('.btn');
      if (button) {
        this.createRipple(button, e);
      }
    });
  }

  createRipple(button, event) {
    const ripple = document.createElement('span');
    const rect = button.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height);
    const x = event.clientX - rect.left - size / 2;
    const y = event.clientY - rect.top - size / 2;

    ripple.style.cssText = `
            position: absolute;
            width: ${size}px;
            height: ${size}px;
            left: ${x}px;
            top: ${y}px;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            transform: scale(0);
            animation: ripple 0.6s linear;
            pointer-events: none;
        `;

    button.style.position = 'relative';
    button.style.overflow = 'hidden';
    button.appendChild(ripple);

    setTimeout(() => {
      ripple.remove();
    }, 600);
  }

  setupCardHoverEffects() {
    const cards = document.querySelectorAll('.card');
    cards.forEach((card) => {
      card.addEventListener('mouseenter', () => {
        this.animateCardHover(card, true);
      });

      card.addEventListener('mouseleave', () => {
        this.animateCardHover(card, false);
      });
    });
  }

  animateCardHover(card, isHovering) {
    const icon = card.querySelector('.card-icon');
    const content = card.querySelector('.card-content');

    if (isHovering) {
      card.style.transition = 'transform 0.5s cubic-bezier(0.22, 1, 0.36, 1)';
      card.style.transform = 'translateY(-10px) scale(1.04)';
      if (icon) icon.style.transform = 'scale(1.12) rotate(6deg)';
      if (content) content.style.transform = 'translateY(-3px)';
    } else {
      card.style.transition = 'transform 0.5s cubic-bezier(0.22, 1, 0.36, 1)';
      card.style.transform = 'translateY(0) scale(1)';
      if (icon) icon.style.transform = 'scale(1) rotate(0deg)';
      if (content) content.style.transform = 'translateY(0)';
    }
  }

  setupLoadingStates() {
    // Show loading state for async operations
    this.showLoading = (element) => {
      element.classList.add('loading');
      element.style.pointerEvents = 'none';

      const spinner = document.createElement('div');
      spinner.className = 'spinner';
      element.appendChild(spinner);
    };

    this.hideLoading = (element) => {
      element.classList.remove('loading');
      element.style.pointerEvents = 'auto';

      const spinner = element.querySelector('.spinner');
      if (spinner) spinner.remove();
    };
  }

  showActivityNotification(activity) {
    // Create a subtle notification that doesn't interrupt the user
    const notification = document.createElement('div');
    notification.className = 'activity-notification';
    notification.style.cssText = `
      position: fixed;
      top: 100px;
      right: 20px;
      background: rgba(0, 0, 0, 0.8);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 12px;
      padding: 12px 16px;
      color: white;
      font-size: 14px;
      z-index: 1000;
      transform: translateX(100%);
      transition: transform 0.3s ease;
      max-width: 300px;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    `;

    // Create notification content
    notification.innerHTML = `
      <div class="flex items-center space-x-3">
        <div class="w-8 h-8 bg-${activity.color}-500/20 rounded-full flex items-center justify-center">
          <i data-lucide="${activity.icon}" class="w-4 h-4 text-${activity.color}-500"></i>
        </div>
        <div class="flex-1">
          <p class="font-medium text-sm">${activity.action}</p>
          <p class="text-xs opacity-70">${activity.time}</p>
        </div>
        <button class="notification-close text-white/60 hover:text-white transition-colors" onclick="this.parentElement.parentElement.remove()">
          <i data-lucide="x" class="w-4 h-4"></i>
        </button>
      </div>
    `;

    document.body.appendChild(notification);
    lucide.createIcons();

    // Animate in
    setTimeout(() => {
      notification.style.transform = 'translateX(0)';
    }, 100);

    // Auto remove after 5 seconds
    setTimeout(() => {
      notification.style.transform = 'translateX(100%)';
      setTimeout(() => {
        if (notification.parentElement) {
          notification.remove();
        }
      }, 300);
    }, 5000);

    // Add click to dismiss
    notification.addEventListener('click', (e) => {
      if (!e.target.closest('.notification-close')) {
        notification.style.transform = 'translateX(100%)';
        setTimeout(() => {
          if (notification.parentElement) {
            notification.remove();
          }
        }, 300);
      }
    });
  }

  updateActivityCounter(count) {
    // Update the activity counter in the header or sidebar
    let counter = document.getElementById('activity-counter');

    if (!counter) {
      // Create counter if it doesn't exist
      counter = document.createElement('div');
      counter.id = 'activity-counter';
      counter.style.cssText = `
        position: absolute;
        top: -5px;
        right: -5px;
        background: #ef4444;
        color: white;
        border-radius: 50%;
        width: 20px;
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: bold;
        animation: pulse 2s infinite;
      `;

      // Find the activity section or header to attach the counter
      const activitySection = document.querySelector('.activity-timeline');
      if (activitySection) {
        activitySection.style.position = 'relative';
        activitySection.appendChild(counter);
      }
    }

    // Update counter value
    counter.textContent = count;
    counter.style.display = count > 0 ? 'flex' : 'none';

    // Auto-hide after 10 seconds
    setTimeout(() => {
      counter.style.display = 'none';
    }, 10000);
  }

  setupToastSystem() {
    this.showToast = (message, type = 'info', duration = 3000) => {
      const toast = document.createElement('div');
      toast.className = `toast toast-${type}`;
      toast.innerHTML = `
                <div class="toast-content">
                    <i data-lucide="${this.getToastIcon(type)}"></i>
                    <span>${message}</span>
                </div>
                <button class="toast-close" onclick="this.parentElement.remove()">
                    <i data-lucide="x"></i>
                </button>
            `;

      document.body.appendChild(toast);
      lucide.createIcons();

      // Animate in
      setTimeout(() => {
        toast.classList.add('toast-show');
      }, 100);

      // Auto remove
      setTimeout(() => {
        toast.classList.remove('toast-show');
        setTimeout(() => {
          if (toast.parentElement) {
            toast.remove();
          }
        }, 300);
      }, duration);
    };

    this.getToastIcon = (type) => {
      const icons = {
        success: 'check-circle',
        error: 'alert-circle',
        warning: 'alert-triangle',
        info: 'info',
      };
      return icons[type] || 'info';
    };
  }

 /* ========================================
       SINGLE INSTANCE TOOLTIP SYSTEM
     ======================================== */

  setupTooltips() {
    // 1. Clean up any existing tooltips from previous page loads
    document.querySelectorAll('.tooltip').forEach(el => el.remove());

    // 2. Create ONE single tooltip element that we will reuse
    this.tooltipElement = document.createElement('div');
    this.tooltipElement.className = 'tooltip';
    document.body.appendChild(this.tooltipElement);

    // 3. Attach listeners to all buttons
    const tooltipTargets = document.querySelectorAll('[data-tooltip]');

    tooltipTargets.forEach((element) => {
      element.addEventListener('mouseenter', (e) => {
        // Show the single tooltip
        this.showTooltip(e.target, e.target.dataset.tooltip);
      });

      element.addEventListener('mouseleave', () => {
        this.hideTooltip();
      });

      element.addEventListener('mousedown', () => {
        this.hideTooltip();
      });
    });

    // 4. Safety Nets: Hide immediately on scroll or resize
    window.addEventListener('scroll', () => this.hideTooltip(), { passive: true });
    window.addEventListener('resize', () => this.hideTooltip());
  }

  showTooltip(element, text) {
    if (!text) return;

    // Update the text of our SINGLE tooltip
    this.tooltipElement.textContent = text;

    // Calculate Position
    const rect = element.getBoundingClientRect();

    // Since your header is fixed, we use fixed positioning calculations
    // but the tooltip itself is absolute relative to the body.
    const scrollY = window.scrollY || window.pageYOffset;
    const scrollX = window.scrollX || window.pageXOffset;

    // Apply positioning
    this.tooltipElement.style.left = (rect.left + scrollX + rect.width / 2 - this.tooltipElement.offsetWidth / 2) + 'px';
    this.tooltipElement.style.top = (rect.top + scrollY - this.tooltipElement.offsetHeight - 8) + 'px';

    // Make it visible
    // We use requestAnimationFrame to ensure the position applies before the fade-in
    requestAnimationFrame(() => {
        this.tooltipElement.classList.add('tooltip-show');
    });
  }

  hideTooltip() {
    // Just hide the single element, don't destroy it
    if (this.tooltipElement) {
        this.tooltipElement.classList.remove('tooltip-show');
    }
  }

  /* ========================================
       DATA VISUALIZATION
       ======================================== */

  setupDataVisualization() {
    // this.setupCharts();
    this.setupProgressBars();
    this.setupCounters();
    // this.setupChartPeriodToggle();
    this.setupAdvancedCharts();
    this.setupRealTimeMetrics();
  }

  setupCharts() {
    // Enhanced chart setup with loading states
    const chartCanvas = document.getElementById('trendChart');
    const chartLoading = document.getElementById('chartLoading');

    if (chartCanvas) {
      // Show loading initially
      if (chartLoading) {
        chartLoading.style.display = 'flex';
      }

      // Simulate loading delay
      setTimeout(() => {
        this.createEnhancedChart(chartCanvas);
        if (chartLoading) {
          chartLoading.style.display = 'none';
        }
      }, 1000);
    }
  }

  setupChartPeriodToggle() {
    const periodButtons = document.querySelectorAll('[data-period]');
    periodButtons.forEach((button) => {
      button.addEventListener('click', (e) => {
        // Remove active class from all buttons
        periodButtons.forEach((btn) => {
          btn.classList.remove('btn-primary');
          btn.classList.add('btn-outline');
        });

        // Add active class to clicked button
        e.target.classList.remove('btn-outline');
        e.target.classList.add('btn-primary');

        // Update chart with new period
        const period = e.target.dataset.period;
        this.updateChartData(period);
      });
    });
  }

  updateChartData(period) {
    const chartCanvas = document.getElementById('trendChart');
    const chartLoading = document.getElementById('chartLoading');

    if (chartCanvas && chartLoading) {
      chartLoading.style.display = 'flex';

      setTimeout(() => {
        this.createEnhancedChart(chartCanvas, period);
        chartLoading.style.display = 'none';
      }, 500);
    }
  }

  createEnhancedChart(canvas, period = '30d') {
    const ctx = canvas.getContext('2d');
    const width = canvas.width;
    const height = canvas.height;

    // Clear canvas
    ctx.clearRect(0, 0, width, height);

    // Generate data based on period
    let data, labels;
    switch (period) {
      case '7d':
        data = [15, 22, 18, 25, 30, 28, 35];
        labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        break;
      case '90d':
        data = [120, 135, 110, 145, 130, 155, 140, 165, 150, 175, 160, 185];
        labels = [
          'Jan',
          'Feb',
          'Mar',
          'Apr',
          'May',
          'Jun',
          'Jul',
          'Aug',
          'Sep',
          'Oct',
          'Nov',
          'Dec',
        ];
        break;
      default: // 30d
        data = [
          20, 35, 25, 45, 30, 50, 40, 60, 55, 70, 65, 80, 75, 90, 85, 100, 95,
          110, 105, 120, 115, 130, 125, 140, 135, 150, 145, 160, 155, 170,
        ];
        labels = Array.from({ length: 30 }, (_, i) => i + 1);
    }

    const maxValue = Math.max(...data);
    const minValue = Math.min(...data);
    const range = maxValue - minValue;

    // Draw grid lines
    const isDark =
      document.documentElement.getAttribute('data-theme') === 'dark';
    ctx.strokeStyle = isDark
      ? 'rgba(255, 255, 255, 0.1)'
      : 'rgba(0, 0, 0, 0.1)';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
      const y = height * 0.1 + (i * height * 0.8) / 4;
      ctx.beginPath();
      ctx.moveTo(0, y);
      ctx.lineTo(width, y);
      ctx.stroke();
    }

    // Draw area under the line
    const gradient = ctx.createLinearGradient(0, 0, 0, height);
    gradient.addColorStop(0, 'rgba(14, 165, 233, 0.3)');
    gradient.addColorStop(1, 'rgba(14, 165, 233, 0.05)');

    ctx.fillStyle = gradient;
    ctx.beginPath();

    const stepX = width / (data.length - 1);
    data.forEach((value, index) => {
      const x = index * stepX;
      const y = height * 0.1 + (1 - (value - minValue) / range) * height * 0.8;

      if (index === 0) {
        ctx.moveTo(x, y);
      } else {
        ctx.lineTo(x, y);
      }
    });

    // Complete the area path
    ctx.lineTo(width, height * 0.9);
    ctx.lineTo(0, height * 0.9);
    ctx.closePath();
    ctx.fill();

    // Draw the main line
    ctx.strokeStyle = '#0ea5e9';
    ctx.lineWidth = 3;
    ctx.beginPath();

    data.forEach((value, index) => {
      const x = index * stepX;
      const y = height * 0.1 + (1 - (value - minValue) / range) * height * 0.8;

      if (index === 0) {
        ctx.moveTo(x, y);
      } else {
        ctx.lineTo(x, y);
      }
    });

    ctx.stroke();

    // Draw data points
    ctx.fillStyle = '#0ea5e9';
    data.forEach((value, index) => {
      const x = index * stepX;
      const y = height * 0.1 + (1 - (value - minValue) / range) * height * 0.8;

      ctx.beginPath();
      ctx.arc(x, y, 4, 0, 2 * Math.PI);
      ctx.fill();
    });

    // Draw labels (only for 7d and 90d to avoid clutter)
    if (period !== '30d') {
      ctx.fillStyle = isDark
        ? 'rgba(255, 255, 255, 0.6)'
        : 'rgba(0, 0, 0, 0.6)';
      ctx.font = '12px Inter';
      ctx.textAlign = 'center';

      labels.forEach((label, index) => {
        const x = index * stepX;
        const y = height - 10;
        ctx.fillText(label, x, y);
      });
    }
  }

  setupProgressBars() {
    const progressBars = document.querySelectorAll('.progress-bar');
    progressBars.forEach((bar) => {
      const progress = bar.dataset.progress || 0;
      const fill = bar.querySelector('.progress-fill');
      if (fill) {
        fill.style.width = `${progress}%`;
        fill.style.transition = 'width 1s ease-out';
      }
    });
  }

  setupCounters() {
    const counters = document.querySelectorAll('.counter');
    counters.forEach((counter) => {
      const target = parseInt(counter.dataset.target) || 0;
      const duration = parseInt(counter.dataset.duration) || 2000;
      this.animateCounter(counter, target, duration);
    });
  }

  animateCounter(element, target, duration) {
    let start = 0;
    const increment = target / (duration / 16);

    const timer = setInterval(() => {
      start += increment;
      if (start >= target) {
        start = target;
        clearInterval(timer);
      }
      element.textContent = Math.floor(start).toLocaleString();
    }, 16);
  }

  /* ========================================
       ACCESSIBILITY FEATURES
       ======================================== */

  setupAccessibility() {
    // Skip to main content
    this.setupSkipLinks();

    // Focus management
    this.setupFocusManagement();

    // Screen reader announcements
    this.setupScreenReaderAnnouncements();

    // High contrast mode detection
    this.setupHighContrastMode();
  }

  setupSkipLinks() {
    const skipLink = document.createElement('a');
    skipLink.href = '#main-content';
    skipLink.className = 'skip-link sr-only focus:not-sr-only';
    skipLink.textContent = 'Skip to main content';
    document.body.insertBefore(skipLink, document.body.firstChild);
  }

  setupFocusManagement() {
    // Trap focus in modals
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Tab') {
        const modal = document.querySelector('.modal[aria-hidden="false"]');
        if (modal) {
          this.trapFocus(modal, e);
        }
      }
    });
  }

  trapFocus(element, event) {
    const focusableElements = element.querySelectorAll(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    );
    const firstElement = focusableElements[0];
    const lastElement = focusableElements[focusableElements.length - 1];

    if (event.shiftKey) {
      if (document.activeElement === firstElement) {
        lastElement.focus();
        event.preventDefault();
      }
    } else {
      if (document.activeElement === lastElement) {
        firstElement.focus();
        event.preventDefault();
      }
    }
  }

  setupScreenReaderAnnouncements() {
    this.announceToScreenReader = (message) => {
      const announcement = document.createElement('div');
      announcement.setAttribute('aria-live', 'polite');
      announcement.setAttribute('aria-atomic', 'true');
      announcement.className = 'sr-only';
      announcement.textContent = message;

      document.body.appendChild(announcement);

      setTimeout(() => {
        if (announcement.parentElement) {
          announcement.remove();
        }
      }, 1000);
    };
  }

  setupHighContrastMode() {
    const mediaQuery = window.matchMedia('(prefers-contrast: high)');
    const handleContrastChange = (e) => {
      document.body.classList.toggle('high-contrast', e.matches);
    };

    mediaQuery.addListener(handleContrastChange);
    handleContrastChange(mediaQuery);
  }

  /* ========================================
       KEYBOARD SHORTCUTS
       ======================================== */

  setupKeyboardShortcuts() {
    document.addEventListener('keydown', (e) => {
      // Don't trigger shortcuts when typing in inputs
      if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
        return;
      }

      // Ctrl/Cmd + K: Quick actions menu
      if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        this.showQuickActions();
      }

      // Ctrl/Cmd + R: Report incident
      if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
        e.preventDefault();
        window.location.href = 'report_incident.php';
      }

      // Ctrl/Cmd + A: View alerts
      if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
        e.preventDefault();
        window.location.href = 'community_alerts.php';
      }

      // Ctrl/Cmd + S: Safe spaces
      if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        window.location.href = 'safe_space_nearby.php';
      }

      // Escape: Close modals/dropdowns
      if (e.key === 'Escape') {
        this.closeAllModals();
      }

      // Alt + T: Toggle theme
      if (e.altKey && e.key === 't') {
        e.preventDefault();
        this.toggleTheme();
      }
    });
  }

  showQuickActions() {
    const quickActions = document.createElement('div');
    quickActions.className = 'quick-actions-modal';
    quickActions.innerHTML = `
            <div class="quick-actions-content">
                <button class="quick-actions-close" onclick="window.safeSpaceDashboard.closeAllModals()"></button>
                <h2 class="quick-actions-title">Quick Actions</h2>
                <div class="quick-actions-grid">
                    <button onclick="window.location.href='report_incident.php'">
                        <i data-lucide="alert-triangle"></i>
                        Report Incident
                    </button>
                    <button onclick="window.location.href='community_alerts.php'">
                        <i data-lucide="bell"></i>
                        View Alerts
                    </button>
                    <button onclick="window.location.href='safe_space_nearby.php'">
                        <i data-lucide="map-pin"></i>
                        Safe Spaces
                    </button>
                    <button onclick="window.location.href='my_reports.php'">
                        <i data-lucide="folder-open"></i>
                        My Reports
                    </button>
                </div>
            </div>
        `;

    document.body.appendChild(quickActions);
    lucide.createIcons();

    // Add click outside to close
    quickActions.addEventListener('click', (e) => {
      if (e.target === quickActions) {
        this.closeAllModals();
      }
    });

    // Add click outside to close (alternative method)
    const handleBackgroundClick = (e) => {
      if (e.target.classList.contains('quick-actions-modal')) {
        this.closeAllModals();
      }
    };
    quickActions.addEventListener('click', handleBackgroundClick);

    // Add escape key to close
    const handleEscape = (e) => {
      if (e.key === 'Escape') {
        this.closeAllModals();
        document.removeEventListener('keydown', handleEscape);
      }
    };
    document.addEventListener('keydown', handleEscape);

    setTimeout(() => {
      quickActions.classList.add('quick-actions-show');
    }, 100);
  }

  closeAllModals() {
    document
      .querySelectorAll('.modal, .quick-actions-modal')
      .forEach((modal) => {
        modal.classList.remove('modal-show', 'quick-actions-show');
        setTimeout(() => {
          if (modal.parentElement) {
            modal.remove();
          }
        }, 300);
      });
  }

  /* ========================================
       SERVICE WORKER & OFFLINE SUPPORT
       ======================================== */

  setupServiceWorker() {
    // Service Worker registration disabled - optional feature
    // Uncomment below code if you want to enable service worker functionality
    /*
    // Service Worker registration disabled
    // if ('serviceWorker' in navigator) {
    //   navigator.serviceWorker
    //     .register('/sw.js')
    //     .then((registration) => {
    //       // SW registered successfully
    //     })
    //     .catch((registrationError) => {
    //       // SW registration failed
    //     });

    //   // Listen for updates
    //   navigator.serviceWorker.addEventListener('message', (event) => {
    //     if (event.data.type === 'UPDATE_AVAILABLE') {
    //       this.showToast('New version available. Refresh to update.', 'info');
    //     }
    //   });
    // }
    */
  }

  /* ========================================
       REAL-TIME UPDATES
       ======================================== */

  setupRealTimeUpdates() {
    // Periodic refresh via WebSocket (no HTTP)
    setInterval(() => {
      this.requestMetricsViaWS();
    }, 30000);
  }

  updateStats() {
    // Simulate updating dashboard stats
    const statsCards = document.querySelectorAll('.stats-card');
    statsCards.forEach((card) => {
      const valueElement = card.querySelector('.stat-value');
      if (valueElement) {
        const currentValue = parseInt(
          valueElement.textContent.replace(/,/g, '')
        );
        const newValue = currentValue + Math.floor(Math.random() * 5);
        this.animateCounter(valueElement, newValue, 1000);
      }
    });
  }

  /* ========================================
       ADVANCED CHARTING & ANALYTICS
       ======================================== */

  setupAdvancedCharts() {
    this.setupApexCharts();
    this.setupGaugeCharts();
    this.setupHeatmapCharts();
  }

  setupApexCharts() {
    // Advanced line chart with multiple series
    if (document.getElementById('advancedTrendChart')) {
      const options = {
        series: [
          {
            name: 'Reports',
            data: [30, 40, 35, 50, 49, 60, 70, 91, 125],
          },
          {
            name: 'Alerts',
            data: [10, 15, 12, 20, 18, 25, 30, 35, 40],
          },
        ],
        chart: {
          height: 350,
          type: 'line',
          zoom: {
            enabled: false,
          },
          background: 'transparent',
          foreColor: '#ffffff',
        },
        dataLabels: {
          enabled: false,
        },
        stroke: {
          curve: 'smooth',
          width: 3,
        },
        title: {
          text: 'Advanced Analytics Dashboard',
          align: 'left',
          style: {
            color: '#ffffff',
          },
        },
        grid: {
          borderColor: 'rgba(255, 255, 255, 0.1)',
          strokeDashArray: 4,
        },
        markers: {
          size: 6,
          colors: ['#3b82f6', '#ef4444'],
          strokeColors: '#ffffff',
          strokeWidth: 2,
        },
        xaxis: {
          categories: [
            'Jan',
            'Feb',
            'Mar',
            'Apr',
            'May',
            'Jun',
            'Jul',
            'Aug',
            'Sep',
          ],
          labels: {
            style: {
              colors: 'rgba(255, 255, 255, 0.7)',
            },
          },
        },
        yaxis: {
          labels: {
            style: {
              colors: 'rgba(255, 255, 255, 0.7)',
            },
          },
        },
        legend: {
          labels: {
            colors: 'rgba(255, 255, 255, 0.7)',
          },
        },
      };

      const chart = new ApexCharts(
        document.getElementById('advancedTrendChart'),
        options
      );
      chart.render();
    }
  }

  setupGaugeCharts() {
    // Performance gauge charts
    const gaugeOptions = {
      series: [85],
      chart: {
        height: 200,
        type: 'radialBar',
        background: 'transparent',
      },
      plotOptions: {
        radialBar: {
          startAngle: -135,
          endAngle: 135,
          hollow: {
            margin: 15,
            size: '70%',
          },
          track: {
            background: 'rgba(255, 255, 255, 0.1)',
            strokeWidth: '97%',
            margin: 5,
          },
          dataLabels: {
            name: {
              show: false,
            },
            value: {
              offsetY: 7,
              color: '#ffffff',
              fontSize: '22px',
              show: true,
              formatter: function (val) {
                return val + '%';
              },
            },
          },
        },
      },
      fill: {
        type: 'gradient',
        gradient: {
          shade: 'dark',
          type: 'horizontal',
          shadeIntensity: 0.5,
          gradientToColors: ['#3b82f6'],
          inverseColors: true,
          opacityFrom: 1,
          opacityTo: 1,
          stops: [0, 100],
        },
      },
      stroke: {
        lineCap: 'round',
      },
    };

    // Create gauge charts for different metrics
    ['performanceGauge', 'securityGauge', 'uptimeGauge'].forEach((id) => {
      const element = document.getElementById(id);
      if (element) {
        const chart = new ApexCharts(element, gaugeOptions);
        chart.render();
      }
    });
  }

  setupHeatmapCharts() {
    // Activity heatmap
    if (document.getElementById('activityHeatmap')) {
      const options = {
        series: [
          {
            name: 'Activity',
            data: this.generateHeatmapData(),
          },
        ],
        chart: {
          height: 200,
          type: 'heatmap',
          background: 'transparent',
        },
        dataLabels: {
          enabled: false,
        },
        colors: ['#1e40af', '#3b82f6', '#60a5fa', '#93c5fd', '#dbeafe'],
        xaxis: {
          categories: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
          labels: {
            style: {
              colors: 'rgba(255, 255, 255, 0.7)',
            },
          },
        },
        yaxis: {
          categories: ['00:00', '06:00', '12:00', '18:00', '24:00'],
          labels: {
            style: {
              colors: 'rgba(255, 255, 255, 0.7)',
            },
          },
        },
      };

      const chart = new ApexCharts(
        document.getElementById('activityHeatmap'),
        options
      );
      chart.render();
    }
  }

  generateHeatmapData() {
    const data = [];
    for (let i = 0; i < 5; i++) {
      for (let j = 0; j < 7; j++) {
        data.push({
          x: j,
          y: i,
          value: Math.floor(Math.random() * 100),
        });
      }
    }
    return data;
  }

  setupRealTimeMetrics() {
    // Initialize activities storage
    this.allActivities = [];

    // Real-time performance monitoring
    setInterval(() => {
      this.updateRealTimeMetrics();
    }, 5000);

    // Update user session activity every 30 seconds
    setInterval(() => {
      this.updateUserSession();
    }, 30000);

    // System health monitoring
    this.monitorSystemHealth();

    // Activity timeline updates every 10 seconds
   // setInterval(() => {
    //  this.updateActivityTimeline();
    //}, 10000);

    // Update activity timestamps every minute
    setInterval(() => {
      this.updateActivityTimestamps();
    }, 60000);

    // Setup "View All" functionality
    this.setupViewAllActivities();

    // Initial load
    this.updateRealTimeMetrics();
   //this.updateActivityTimeline();
  }

  updateUserSession() {
    // Keep-alive over WebSocket (no HTTP)
    if (this.ws && this.ws.readyState === 1) {
      this.sendWsRequest('ping', {}).catch(() => {});
    }
  }

  updateRealTimeMetrics() {
    this.requestMetricsViaWS()
      .then((metrics) => {
        const performanceMetrics = {
          uptime: metrics.uptime,
          response: metrics.response_time,
          users: metrics.active_users,
          cpu: metrics.cpu_usage,
          memory: metrics.memory_usage,
          network: metrics.network_usage,
        };

        Object.keys(performanceMetrics).forEach((key) => {
          const element = document.getElementById(`${key}Metric`);
          if (element) {
            element.textContent = performanceMetrics[key];
            element.classList.add('real-data');
            setTimeout(() => element.classList.remove('real-data'), 2000);
          }
        });

        this.updateStatsCards(metrics);
      })
      .catch(() => this.updateSimulatedMetrics());

    //this.updateActivityTimeline();
  }

  updateStatsCards(metrics) {
    // Update stats cards with real database data
    const statsCards = {
      reports: metrics.total_reports,
      alerts: metrics.active_alerts,
      'safe-spaces': metrics.safe_spaces,
    };

    Object.keys(statsCards).forEach((statType) => {
      const card = document.querySelector(`[data-stat="${statType}"]`);
      if (card) {
        const valueElement = card.querySelector('.counter, .heading-3');
        if (valueElement) {
          const currentValue = parseInt(
            valueElement.textContent.replace(/,/g, '')
          );
          const newValue = statsCards[statType];

          if (currentValue !== newValue) {
            this.animateCounter(valueElement, newValue, 1000);
          }
        }
      }
    });
  }

  updateSimulatedMetrics() {
    // Fallback to simulated data if API fails
    const metrics = {
      uptime: '99.9%',
      response: (Math.random() * 2 + 1).toFixed(1) + 's',
      users: Math.floor(Math.random() * 400 + 800).toLocaleString(),
      cpu: Math.floor(Math.random() * 30 + 20),
      memory: Math.floor(Math.random() * 20 + 60),
      network: Math.floor(Math.random() * 50 + 100),
    };

    Object.keys(metrics).forEach((key) => {
      const element = document.getElementById(`${key}Metric`);
      if (element) {
        element.textContent = metrics[key];
      }
    });
  }

  updateActivityTimeline() {
    this.requestActivityViaWS(10)
      .then((activities) => {
        const timeline = document.querySelector('.activity-timeline');
        if (timeline && activities.length > 0) {
          const currentActivityIds = Array.from(
            timeline.querySelectorAll('.activity-item')
          )
            .map((item) => item.dataset.activityId)
            .filter((id) => id);

          const newActivities = activities.filter(
            (activity) => !currentActivityIds.includes(activity.id.toString())
          );

          if (
            newActivities.length > 0 &&
            this.allActivities.length > 0 &&
            !localStorage.getItem('safespace-notifications-disabled') &&
            !localStorage.getItem('safespace-dnd-enabled')
          ) {
            const latestActivity = newActivities[0];
            const importantTypes = ['alert', 'report', 'dispute'];
            if (importantTypes.includes(latestActivity.type)) {
              this.showActivityNotification(latestActivity);
            } else if (!localStorage.getItem('safespace-counter-disabled')) {
              this.updateActivityCounter(newActivities.length);
            }
          }

          const existingItems = timeline.querySelectorAll('.activity-item');
          existingItems.forEach((item, index) => {
            if (index >= 3) item.remove();
          });

          activities.slice(0, 3).forEach((activity) => {
            this.addRealTimelineItem(timeline, activity);
          });

          this.allActivities = activities;
        }
      })
     .catch(() => {
      // Do nothing on failure. Only show real data.
    });
  }

  setupWebSocket() {
    try {
      const url =
        (location.protocol === 'https:' ? 'wss://' : 'ws://') +
        (location.hostname || 'localhost') +
        ':8080';
      this.ws = new WebSocket(url);

      this.ws.onopen = () => {
        // initial requests
        this.requestMetricsViaWS();
        this.requestActivityViaWS(10);
        this.requestSystemHealthViaWS();
        // keep alive
        setInterval(() => this.sendWsRequest('ping', {}), 25000);
        // ADD THIS LINE:
        window.dispatchEvent(new Event('wsReady'));
      };

      this.ws.onmessage = (event) => {
        try {
          const msg = JSON.parse(event.data);
          const reqId = msg.requestId;
          if (reqId && this.wsPending.has(reqId)) {
            const { resolve } = this.wsPending.get(reqId);
            this.wsPending.delete(reqId);
            resolve(msg.data);
            return;
          }
        } catch (e) {
          // ignore malformed
        }
      };

      this.ws.onerror = () => {
        // fallback handled by callers
      };

      this.ws.onclose = () => {
        // attempt reconnect
        setTimeout(() => this.setupWebSocket(), 3000);
      };
    } catch (e) {
      // ignore
    }
  }

  sendWsRequest(action, payload) {
    return new Promise((resolve, reject) => {
      if (!this.ws || this.ws.readyState !== 1) {
        reject(new Error('WS not connected'));
        return;
      }
      const requestId = ++this.wsRequestId;
      this.wsPending.set(requestId, { resolve, reject, ts: Date.now() });
      this.ws.send(JSON.stringify({ action, requestId, ...payload }));
      setTimeout(() => {
        if (this.wsPending.has(requestId)) {
          this.wsPending.delete(requestId);
          reject(new Error('WS request timeout'));
        }
      }, 8000);
    });
  }

  requestMetricsViaWS() {
    return this.sendWsRequest('get_metrics', {});
  }

  requestActivityViaWS(limit = 10) {
    return this.sendWsRequest('get_activity', { limit });
  }

  requestSystemHealthViaWS() {
    return this.sendWsRequest('get_system_health', {});
  }

  requestChartDataViaWS(period = '30D') {
    return this.sendWsRequest('get_chart_data', { period });
  }

  requestReportCategoriesViaWS() {
    return this.sendWsRequest('get_report_categories', {});
  }

  requestDetailedMetricsViaWS() {
    return this.sendWsRequest('get_detailed_metrics', {});
  }

  addRealTimelineItem(timeline, activity) {
    const item = document.createElement('div');
    item.className =
      'flex items-center space-x-3 p-3 bg-white/5 rounded-lg animate-fade-in activity-item';
    item.dataset.activityId = activity.id;

    // Derive a robust timestamp from various possible fields
    const parsedDate = this.parseActivityTime(
      activity.timestamp ??
        activity.originalTime ??
        activity.date ??
        activity.time
    );
    const originalTimeMs = parsedDate ? parsedDate.getTime() : Date.now();
    item.dataset.originalTime = String(originalTimeMs);

    const absoluteText = this.formatAbsoluteLocalTime(originalTimeMs);

    item.innerHTML = `
      <div class="w-8 h-8 bg-${activity.color}-500/20 rounded-full flex items-center justify-center">
        <i data-lucide="${activity.icon}" class="w-4 h-4 text-${activity.color}-500"></i>
      </div>
      <div class="flex-1">
        <p class="text-sm font-medium" style="color: var(--text-primary);">${activity.action}</p>
        <p class="text-xs activity-time" style="color: var(--text-tertiary);">${absoluteText}</p>
      </div>
      <div class="w-2 h-2 bg-${activity.color}-500 rounded-full"></div>
    `;

    // Insert after the first static item (keep initial 3 static examples intact)
    const firstItem = timeline.querySelector('.activity-item');
    if (firstItem) {
      timeline.insertBefore(item, firstItem.nextSibling);
    } else {
      timeline.appendChild(item);
    }

    // Remove old items if too many (keep only 3 items in timeline)
    const allItems = timeline.querySelectorAll('.activity-item');
    if (allItems.length > 3) {
      timeline.removeChild(allItems[allItems.length - 1]);
    }

    // Recreate icons
    lucide.createIcons();
  }

  updateActivityTimestamps() {
    // Update timestamps for all activity items (absolute local time)
    const activityItems = document.querySelectorAll('.activity-item');
    activityItems.forEach((item) => {
      const timeElement = item.querySelector('.activity-time');
      const originalTime = item.dataset.originalTime;

      if (timeElement && originalTime) {
        const ms = /^\d{10,13}$/.test(originalTime)
          ? parseInt(originalTime, 10)
          : new Date(originalTime).getTime();
        const updatedTime = this.formatAbsoluteLocalTime(ms);
        if (updatedTime !== timeElement.textContent) {
          timeElement.textContent = updatedTime;
        }
      }
    });
  }

  // Format absolute local time consistently
  formatAbsoluteLocalTime(ms) {
    try {
      const d = new Date(ms);
      return d.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone: 'Asia/Dhaka',
        timeZoneName: 'short',
      });
    } catch (e) {
      return 'Unknown time';
    }
  }

  setupViewAllActivities() {
    const viewAllBtn = document.getElementById('viewAllActivities');
    if (viewAllBtn) {
      viewAllBtn.addEventListener('click', () => {
        this.showAllActivitiesModal();
      });
    }
  }

  showAllActivitiesModal() {
    // Create modal for all activities
    const modal = document.createElement('div');
    modal.className =
      'fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4';
    const itemsHtml =
      this.allActivities.length > 0
        ? this.allActivities
            .map((activity) => {
              const parsed = this.parseActivityTime(
                activity.timestamp ?? activity.originalTime ?? activity.time
              );
              const ms = parsed ? parsed.getTime() : Date.now();
              const timeText = this.formatAbsoluteLocalTime(ms);
              return `
                <div class="flex items-center space-x-3 p-3 bg-white/5 rounded-lg">
                  <div class="w-8 h-8 bg-${activity.color}-500/20 rounded-full flex items-center justify-center">
                    <i data-lucide="${activity.icon}" class="w-4 h-4 text-${activity.color}-500"></i>
                  </div>
                  <div class="flex-1">
                    <p class="text-sm font-medium" style="color: var(--text-primary);">${activity.action}</p>
                    <p class="text-xs" style="color: var(--text-tertiary);">${timeText}</p>
                  </div>
                  <div class="w-2 h-2 bg-${activity.color}-500 rounded-full"></div>
                </div>
              `;
            })
            .join('')
        : '<p class="text-center py-8" style="color: var(--text-tertiary);">No activities found</p>';

    modal.innerHTML = `
      <div class="bg-white/10 backdrop-blur-md rounded-xl border border-white/20 max-w-2xl w-full max-h-[80vh] overflow-hidden">
        <div class="flex items-center justify-between p-6 border-b border-white/10">
          <h3 class="text-lg font-semibold" style="color: var(--text-primary);">All Recent Activities</h3>
          <button class="close-modal-btn p-2 hover:bg-white/10 rounded-lg transition-colors">
            <i data-lucide="x" class="w-5 h-5" style="color: var(--text-primary);"></i>
          </button>
        </div>
        <div class="p-6 overflow-y-auto max-h-[60vh]">
          <div class="space-y-4 all-activities-list">
            ${itemsHtml}
          </div>
        </div>
      </div>
    `;

    document.body.appendChild(modal);

    // Close modal functionality
    const closeBtn = modal.querySelector('.close-modal-btn');
    const closeModal = () => {
      modal.remove();
    };

    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => {
      if (e.target === modal) closeModal();
    });

    // Recreate icons
    lucide.createIcons();
  }

  updateSimulatedActivityTimeline() {
    const activities = [
      {
        type: 'user',
        action: 'New user registered',
        time: '2 minutes ago',
        color: 'green',
        icon: 'user-plus',
      },
      {
        type: 'report',
        action: 'Report submitted',
        time: '5 minutes ago',
        color: 'blue',
        icon: 'file-text',
      },
      {
        type: 'alert',
        action: 'Alert triggered',
        time: '8 minutes ago',
        color: 'yellow',
        icon: 'bell',
      },
      {
        type: 'system',
        action: 'System backup completed',
        time: '12 minutes ago',
        color: 'purple',
        icon: 'settings',
      },
    ];

    const timeline = document.querySelector('.activity-timeline');
    if (timeline) {
      const randomActivity =
        activities[Math.floor(Math.random() * activities.length)];
      this.addTimelineItem(timeline, randomActivity);
    }
  }

  addTimelineItem(timeline, activity) {
    const item = document.createElement('div');
    item.className =
      'flex items-center space-x-3 p-3 bg-white/5 rounded-lg animate-fade-in';
    item.innerHTML = `
      <div class="w-8 h-8 bg-${
        activity.color
      }-500/20 rounded-full flex items-center justify-center">
        <i data-lucide="${this.getActivityIcon(
          activity.type
        )}" class="w-4 h-4 text-${activity.color}-500"></i>
      </div>
      <div class="flex-1">
        <p class="text-sm font-medium" style="color: var(--text-primary);">${
          activity.action
        }</p>
        <p class="text-xs" style="color: var(--text-tertiary);">${
          activity.time
        }</p>
      </div>
      <div class="w-2 h-2 bg-${activity.color}-500 rounded-full"></div>
    `;

    timeline.insertBefore(item, timeline.firstChild);

    // Remove old items if too many
    if (timeline.children.length > 5) {
      timeline.removeChild(timeline.lastChild);
    }

    // Recreate icons
    lucide.createIcons();
  }

  /**
 * Renders a single activity item into the timeline.
 * This will be used by our new filter function.
 */
renderActivityItem(timeline, activity) {
    const item = document.createElement('div');
    item.className = `activity-item flex items-center space-x-4 p-4 rounded-lg hover:bg-white/5 transition-all duration-200 border-l-4 border-${activity.color}-500/30`;
    item.dataset.type = activity.type;
    item.dataset.activityId = activity.id;

    // Format the time
    const timeMs = this.parseActivityTime(activity.timestamp || activity.originalTime || activity.time);
    const timeText = timeMs ? this.formatAbsoluteLocalTime(timeMs) : (activity.time || 'Just now');

    // This is the HTML from your dashboard.php, now in JavaScript
    item.innerHTML = `
        <div class="relative">
            <div class="w-12 h-12 bg-gradient-to-r from-${activity.color}-500 to-${activity.color}-600 rounded-lg flex items-center justify-center">
                <i data-lucide="${activity.icon}" class="w-6 h-6 text-white"></i>
            </div>
        </div>
        <div class="flex-1">
            <div class="flex items-center space-x-2 mb-1">
                <p class="font-medium" style="color: var(--text-primary);">${activity.action}</p>
                <span class="text-xs px-2 py-0.5 bg-${activity.color}-500/20 text-${activity.color}-400 rounded-full">
                    ${activity.type.charAt(0).toUpperCase() + activity.type.slice(1)}
                </span>
            </div>
            <p class="text-sm" style="color: var(--text-secondary);">
                ${activity.details.category || activity.details.email || ''}
            </p>
            <div class="flex items-center space-x-2 mt-1">
                <span class="text-xs" style="color: var(--text-tertiary);">
                    ${timeText}
                </span>
                <span class="text-xs text-green-400">• Live</span>
            </div>
        </div>
        <div class="flex flex-col items-end space-y-2">
            <button class="activity-details-btn p-1 hover:bg-white/10 rounded transition-colors" data-activity-id="${activity.id}">
                <i data-lucide="more-horizontal" class="w-4 h-4" style="color: var(--text-tertiary);"></i>
            </button>
            <div class="w-2 h-2 bg-${activity.color}-500 rounded-full"></div>
        </div>
    `;
    timeline.appendChild(item);
}

/**
 * Formats an absolute local time for display.
 */
formatAbsoluteLocalTime(ms) {
    try {
        const d = new Date(ms);
        // Using a clear, standard format
        return d.toLocaleString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: true,
        });
    } catch (e) {
        return 'Unknown time';
    }
}
/**
 * Loads and displays activity based on the selected filter.
 */
async loadAndDisplayActivity(filter = 'all') {
    const timeline = document.querySelector('#activity-timeline');
    if (!timeline) return;

    // Show a loading spinner
    timeline.innerHTML = `
        <div class="flex items-center justify-center p-8">
            <div class="spinner w-8 h-8 border-2 border-primary-500 border-t-transparent rounded-full animate-spin"></div>
        </div>`;

    try {
        // Fetch the filtered data from our modified API
        const response = await fetch(`realtime_activity.php?action=get_activity&type=${filter}`);
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }

        const result = await response.json();

        if (result.success && Array.isArray(result.data)) {
            // Clear the spinner
            timeline.innerHTML = '';

            if (result.data.length === 0) {
                timeline.innerHTML = `
                    <div class="text-center py-8">
                        <div class="w-16 h-16 bg-gradient-to-r from-gray-500 to-gray-600 rounded-lg flex items-center justify-center mx-auto mb-4">
                            <i data-lucide="inbox" class="w-8 h-8 text-white"></i>
                        </div>
                        <p class="text-sm" style="color: var(--text-tertiary);">No activity found for this filter.</p>
                    </div>`;
            } else {
                // Render each new item
                result.data.forEach(activity => {
                    this.renderActivityItem(timeline, activity);
                });
            }
        } else {
            throw new Error('Invalid data format');
        }
    } catch (error) {
        console.error('Error fetching activity:', error);
        timeline.innerHTML = `
            <div class="text-center py-8">
                <div class="w-16 h-16 bg-gradient-to-r from-error-500 to-error-600 rounded-lg flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="alert-triangle" class="w-8 h-8 text-white"></i>
                </div>
                <p class="text-sm" style="color: var(--text-tertiary);">Could not load activity.</p>
            </div>`;
    }

    // IMPORTANT: Re-initialize Lucide icons
    lucide.createIcons();
}

  getActivityIcon(type) {
    const icons = {
      user: 'user-plus',
      report: 'file-text',
      alert: 'bell',
      system: 'settings',
    };
    return icons[type] || 'activity';
  }

  monitorSystemHealth() {
    // Fetch real system health from database
    const updateHealth = () => {
      fetch('realtime_activity.php?action=get_system_health')
        .then((response) => response.json())
        .then((data) => {
          if (data.success) {
            const health = data.data;

            Object.keys(health).forEach((service) => {
              const element = document.querySelector(
                `[data-service="${service}"]`
              );
              if (element) {
                const progressBar = element.querySelector('.progress-bar-fill');
                const percentage = element.querySelector('.percentage');

                if (progressBar && percentage) {
                  const healthData = health[service];
                  progressBar.style.width = `${healthData.percentage}%`;
                  percentage.textContent = `${Math.round(
                    healthData.percentage
                  )}%`;

                  // Update status indicator
                  const statusDot = element.querySelector('.status-dot');
                  if (statusDot) {
                    const statusClass =
                      healthData.status === 'green'
                        ? 'bg-green-500'
                        : healthData.status === 'yellow'
                        ? 'bg-yellow-500'
                        : 'bg-red-500';
                    statusDot.className = `w-3 h-3 rounded-full ${statusClass}`;
                  }
                }
              }
            });
          }
        })
        .catch((error) => {
          console.error('Error fetching system health:', error);
          // Fallback to simulated data
          this.updateSimulatedSystemHealth();
        });
    };

    // Update immediately and then every 10 seconds
    updateHealth();
    setInterval(updateHealth, 10000);
  }

  updateSimulatedSystemHealth() {
    const health = {
      database: Math.random() * 10 + 90,
      api: Math.random() * 5 + 95,
      storage: Math.random() * 20 + 70,
      security: 100,
    };

    Object.keys(health).forEach((service) => {
      const element = document.querySelector(`[data-service="${service}"]`);
      if (element) {
        const progressBar = element.querySelector('.progress-bar-fill');
        const percentage = element.querySelector('.percentage');

        if (progressBar && percentage) {
          progressBar.style.width = `${health[service]}%`;
          percentage.textContent = `${Math.round(health[service])}%`;

          // Update status indicator
          const statusDot = element.querySelector('.status-dot');
          if (statusDot) {
            statusDot.className = `w-3 h-3 rounded-full ${
              health[service] > 90
                ? 'bg-green-500'
                : health[service] > 70
                ? 'bg-yellow-500'
                : 'bg-red-500'
            }`;
          }
        }
      }
    });
  }

  /* ========================================
       UTILITY METHODS
       ======================================== */

  // Debounce function for performance
  debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  // Throttle function for performance
  throttle(func, limit) {
    let inThrottle;
    return function () {
      const args = arguments;
      const context = this;
      if (!inThrottle) {
        func.apply(context, args);
        inThrottle = true;
        setTimeout(() => (inThrottle = false), limit);
      }
    };
  }

  // Format numbers with commas
  formatNumber(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  // Format relative time with improved accuracy
  formatRelativeTime(date) {
    try {
      const now = new Date().getTime();
      const timeMs = (() => {
        if (typeof date === 'number') return date;
        if (typeof date === 'string' && /^\d{10,13}$/.test(date))
          return parseInt(date, 10);
        const parsed = new Date(date);
        return isNaN(parsed.getTime()) ? now : parsed.getTime();
      })();
      const diff = now - timeMs;

      // Handle invalid dates
      if (isNaN(diff)) {
        return 'Unknown time';
      }

      // Handle future dates
      if (diff < 0) {
        return 'Just now';
      }

      const seconds = Math.floor(diff / 1000);
      const minutes = Math.floor(diff / 60000);
      const hours = Math.floor(diff / 3600000);
      const days = Math.floor(diff / 86400000);
      const months = Math.floor(days / 30);
      const years = Math.floor(days / 365);

      if (years > 0) {
        return `${years} year${years > 1 ? 's' : ''} ago`;
      } else if (months > 0) {
        return `${months} month${months > 1 ? 's' : ''} ago`;
      } else if (days > 0) {
        return `${days} day${days > 1 ? 's' : ''} ago`;
      } else if (hours > 0) {
        return `${hours} hour${hours > 1 ? 's' : ''} ago`;
      } else if (minutes > 0) {
        return `${minutes} minute${minutes > 1 ? 's' : ''} ago`;
      } else if (seconds > 30) {
        return 'Less than a minute ago';
      } else {
        return 'Just now';
      }
    } catch (error) {
      return 'Unknown time';
    }
  }

  // Parse various possible time formats to Date
  parseActivityTime(value) {
    if (!value) return null;
    if (value instanceof Date) return value;
    if (typeof value === 'number') return new Date(value);
    if (typeof value === 'string') {
      if (/^\d{10}$/.test(value)) return new Date(parseInt(value, 10) * 1000);
      if (/^\d{13}$/.test(value)) return new Date(parseInt(value, 10));
      const parsed = new Date(value);
      if (!isNaN(parsed.getTime())) return parsed;
    }
    return null;
  }

  setupSmoothScrolling() {
    // Enhanced smooth scrolling for anchor links
    document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
      anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
          target.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
            inline: 'nearest',
          });
        }
      });
    });

    // Smooth scrolling for programmatic navigation
    this.smoothScrollTo = (element, duration = 800) => {
      const targetPosition = element.offsetTop - 100; // Account for header
      const startPosition = window.pageYOffset;
      const distance = targetPosition - startPosition;
      let startTime = null;

      function animation(currentTime) {
        if (startTime === null) startTime = currentTime;
        const timeElapsed = currentTime - startTime;
        const run = this.easeInOutCubic(
          timeElapsed,
          startPosition,
          distance,
          duration
        );
        window.scrollTo(0, run);
        if (timeElapsed < duration) requestAnimationFrame(animation);
      }

      this.easeInOutCubic = (t, b, c, d) => {
        t /= d / 2;
        if (t < 1) return (c / 2) * t * t * t + b;
        t -= 2;
        return (c / 2) * (t * t * t + 2) + b;
      };

      requestAnimationFrame(animation);
    };

    // Add momentum scrolling for touch devices
    if ('ontouchstart' in window) {
      document.addEventListener('touchstart', () => {
        document.body.style.webkitOverflowScrolling = 'touch';
      });
    }

    // Smooth scroll restoration
    if ('scrollRestoration' in history) {
      history.scrollRestoration = 'manual';
    }

    // Add scroll progress indicator
    this.setupScrollProgress();
  }



  setupScrollProgress() {
    const progressBar = document.createElement('div');
    progressBar.className = 'scroll-progress';
    progressBar.style.cssText = `
      position: fixed;
      top: 0;
      left: 0;
      width: 0%;
      height: 3px;
      background: linear-gradient(90deg, var(--accent-cyan), var(--accent-teal));
      z-index: 9999;
      transition: width 0.1s ease;
    `;
    document.body.appendChild(progressBar);

    window.addEventListener('scroll', () => {
      const scrollTop = window.pageYOffset;
      const docHeight = document.body.scrollHeight - window.innerHeight;
      const scrollPercent = (scrollTop / docHeight) * 100;
      progressBar.style.width = scrollPercent + '%';
    });
  }
}

// Initialize dashboard when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
  window.safeSpaceDashboard = new SafeSpaceDashboard();
});

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
  module.exports = SafeSpaceDashboard;
}