// Three.js Scene Setup for Dashboard
let scene, camera, renderer, stars, planets, asteroids;
let mouseX = 0,
  mouseY = 0;
let windowHalfX = window.innerWidth / 2;
let windowHalfY = window.innerHeight / 2;

// Initialize the dashboard
document.addEventListener('DOMContentLoaded', function () {
  initThreeJS();
  initParticles();
  initEventListeners();
  initFloatingActionButton();
  initRealTimeUpdates();

  animate();
});

// Three.js Initialization for Dashboard
function initThreeJS() {
  const canvas = document.getElementById('spaceCanvas');

  // Scene setup
  scene = new THREE.Scene();

  // Camera setup
  camera = new THREE.PerspectiveCamera(
    75,
    window.innerWidth / window.innerHeight,
    0.1,
    1000
  );
  camera.position.z = 5;

  // Renderer setup
  renderer = new THREE.WebGLRenderer({
    canvas: canvas,
    antialias: true,
    alpha: true,
  });
  renderer.setSize(window.innerWidth, window.innerHeight);
  renderer.setClearColor(0x000000, 0);

  // Create enhanced star field
  createEnhancedStars();

  // Create multiple planets
  createPlanets();

  // Create asteroid belt
  createAsteroidBelt();

  // Add ambient light
  const ambientLight = new THREE.AmbientLight(0x404040, 0.4);
  scene.add(ambientLight);

  // Add directional lights
  const directionalLight1 = new THREE.DirectionalLight(0x4facfe, 0.6);
  directionalLight1.position.set(5, 5, 5);
  scene.add(directionalLight1);

  const directionalLight2 = new THREE.DirectionalLight(0x00f2fe, 0.4);
  directionalLight2.position.set(-5, -5, 5);
  scene.add(directionalLight2);
}

// Create enhanced star field
function createEnhancedStars() {
  const starsGeometry = new THREE.BufferGeometry();
  const starsMaterial = new THREE.PointsMaterial({
    color: 0xffffff,
    size: 0.05,
    transparent: true,
    opacity: 0.8,
    sizeAttenuation: true,
  });

  const starsVertices = [];
  const starColors = [];

  for (let i = 0; i < 2000; i++) {
    const x = (Math.random() - 0.5) * 200;
    const y = (Math.random() - 0.5) * 200;
    const z = (Math.random() - 0.5) * 200;
    starsVertices.push(x, y, z);

    // Add color variation
    const color = new THREE.Color();
    color.setHSL(Math.random() * 0.1 + 0.9, 0.5, Math.random() * 0.5 + 0.5);
    starColors.push(color.r, color.g, color.b);
  }

  starsGeometry.setAttribute(
    'position',
    new THREE.Float32BufferAttribute(starsVertices, 3)
  );
  starsGeometry.setAttribute(
    'color',
    new THREE.Float32BufferAttribute(starColors, 3)
  );
  starsMaterial.vertexColors = true;

  stars = new THREE.Points(starsGeometry, starsMaterial);
  scene.add(stars);
}

// Create multiple planets
function createPlanets() {
  planets = [];

  // Planet 1 - Blue gas giant
  const planet1Geometry = new THREE.SphereGeometry(1.5, 32, 32);
  const planet1Material = new THREE.MeshPhongMaterial({
    color: 0x4facfe,
    transparent: true,
    opacity: 0.8,
    shininess: 100,
  });
  const planet1 = new THREE.Mesh(planet1Geometry, planet1Material);
  planet1.position.set(-10, 2, -8);
  scene.add(planet1);
  planets.push(planet1);

  // Planet 2 - Purple planet
  const planet2Geometry = new THREE.SphereGeometry(1, 32, 32);
  const planet2Material = new THREE.MeshPhongMaterial({
    color: 0x8a2be2,
    transparent: true,
    opacity: 0.7,
    shininess: 80,
  });
  const planet2 = new THREE.Mesh(planet2Geometry, planet2Material);
  planet2.position.set(8, -3, -6);
  scene.add(planet2);
  planets.push(planet2);

  // Planet 3 - Green planet
  const planet3Geometry = new THREE.SphereGeometry(0.8, 32, 32);
  const planet3Material = new THREE.MeshPhongMaterial({
    color: 0x00ff88,
    transparent: true,
    opacity: 0.6,
    shininess: 60,
  });
  const planet3 = new THREE.Mesh(planet3Geometry, planet3Material);
  planet3.position.set(-5, -5, -10);
  scene.add(planet3);
  planets.push(planet3);
}

// Create asteroid belt
function createAsteroidBelt() {
  asteroids = [];

  for (let i = 0; i < 100; i++) {
    const asteroidGeometry = new THREE.SphereGeometry(
      0.02 + Math.random() * 0.03,
      8,
      8
    );
    const asteroidMaterial = new THREE.MeshBasicMaterial({
      color: 0x888888,
      transparent: true,
      opacity: 0.6,
    });
    const asteroid = new THREE.Mesh(asteroidGeometry, asteroidMaterial);

    // Position in a ring formation
    const angle = Math.random() * Math.PI * 2;
    const radius = 15 + Math.random() * 5;
    asteroid.position.set(
      Math.cos(angle) * radius,
      (Math.random() - 0.5) * 2,
      Math.sin(angle) * radius
    );

    scene.add(asteroid);
    asteroids.push(asteroid);
  }
}

// Create floating particles
function initParticles() {
  const particlesContainer = document.getElementById('particles');

  for (let i = 0; i < 30; i++) {
    const particle = document.createElement('div');
    particle.className = 'particle';
    particle.style.left = Math.random() * 100 + '%';
    particle.style.top = Math.random() * 100 + '%';
    particle.style.animationDelay = Math.random() * 8 + 's';
    particle.style.animationDuration = Math.random() * 4 + 4 + 's';
    particlesContainer.appendChild(particle);
  }
}

// Initialize event listeners
function initEventListeners() {
  // Mouse movement for parallax effect
  document.addEventListener('mousemove', onDocumentMouseMove);

  // Window resize
  window.addEventListener('resize', onWindowResize);

  // Card hover effects
  const cards = document.querySelectorAll('.card');
  cards.forEach((card) => {
    card.addEventListener('mouseenter', handleCardHover);
    card.addEventListener('mouseleave', handleCardLeave);
  });

  // Activity item hover effects
  const activityItems = document.querySelectorAll('.activity-item');
  activityItems.forEach((item) => {
    item.addEventListener('mouseenter', handleActivityHover);
  });
}

// Mouse movement handler
function onDocumentMouseMove(event) {
  mouseX = (event.clientX - windowHalfX) / 100;
  mouseY = (event.clientY - windowHalfY) / 100;
}

// Window resize handler
function onWindowResize() {
  windowHalfX = window.innerWidth / 2;
  windowHalfY = window.innerHeight / 2;

  camera.aspect = window.innerWidth / window.innerHeight;
  camera.updateProjectionMatrix();
  renderer.setSize(window.innerWidth, window.innerHeight);
}

// Card hover handler
function handleCardHover(event) {
  const card = event.target;
  card.style.transform = 'translateY(-8px) scale(1.02)';

  // Add glow effect
  card.style.boxShadow = '0 15px 40px rgba(79, 172, 254, 0.4)';
}

// Card leave handler
function handleCardLeave(event) {
  const card = event.target;
  card.style.transform = 'translateY(0) scale(1)';
  card.style.boxShadow = '';
}

// Activity hover handler
function handleActivityHover(event) {
  const item = event.target;

  // Create ripple effect
  const ripple = document.createElement('div');
  ripple.style.position = 'absolute';
  ripple.style.width = '100%';
  ripple.style.height = '100%';
  ripple.style.background =
    'radial-gradient(circle, rgba(79, 172, 254, 0.2) 0%, transparent 70%)';
  ripple.style.borderRadius = '10px';
  ripple.style.transform = 'scale(0)';
  ripple.style.animation = 'ripple 0.6s ease-out';
  ripple.style.pointerEvents = 'none';
  ripple.style.top = '0';
  ripple.style.left = '0';

  item.style.position = 'relative';
  item.appendChild(ripple);

  setTimeout(() => {
    ripple.remove();
  }, 600);
}

// Initialize floating action button
function initFloatingActionButton() {
  const fabButton = document.querySelector('.fab-button');
  const fabMenu = document.getElementById('fabMenu');
  let isMenuOpen = false;

  fabButton.addEventListener('click', function () {
    isMenuOpen = !isMenuOpen;
    if (isMenuOpen) {
      fabMenu.classList.add('active');
      fabButton.style.transform = 'scale(1.1) rotate(45deg)';
    } else {
      fabMenu.classList.remove('active');
      fabButton.style.transform = 'scale(1.1) rotate(0deg)';
    }
  });

  // Close menu when clicking outside
  document.addEventListener('click', function (event) {
    if (!event.target.closest('.fab')) {
      fabMenu.classList.remove('active');
      fabButton.style.transform = 'scale(1.1) rotate(0deg)';
      isMenuOpen = false;
    }
  });
}

// Initialize real-time updates
function initRealTimeUpdates() {
  // Update stats every 5 seconds
  setInterval(updateStats, 5000);

  // Update activity times every minute
  setInterval(updateActivityTimes, 60000);
}

// Update dashboard stats
function updateStats() {
  const statNumbers = document.querySelectorAll('.stat-number');

  statNumbers.forEach((stat) => {
    const currentValue = parseFloat(stat.textContent.replace(/[^\d.]/g, ''));
    const variation = (Math.random() - 0.5) * 2; // ±1% variation
    const newValue = Math.max(0, Math.min(100, currentValue + variation));

    // Animate the number change
    gsap.to(stat, {
      textContent:
        newValue.toFixed(1) + (stat.textContent.includes('%') ? '%' : ''),
      duration: 1,
      ease: 'power2.out',
    });
  });
}

// Update activity times
function updateActivityTimes() {
  const activityTimes = document.querySelectorAll('.activity-time');

  activityTimes.forEach((timeElement) => {
    const currentText = timeElement.textContent;
    if (currentText.includes('minutes ago')) {
      const minutes = parseInt(currentText.match(/\d+/)[0]);
      const newMinutes = minutes + 1;
      timeElement.textContent = `${newMinutes} minutes ago`;
    } else if (currentText.includes('hour ago')) {
      const hours = parseInt(currentText.match(/\d+/)[0]);
      const newHours = hours + 1;
      timeElement.textContent = `${newHours} hour${
        newHours > 1 ? 's' : ''
      } ago`;
    }
  });
}

// Floating action button functions
function showQuickActions() {
  // This is handled by the click event listener
}

function emergencyProtocol() {
  showNotification(
    'Emergency Protocol Activated',
    'All systems are now in emergency mode.',
    'warning'
  );

  // Add emergency visual effects
  document.body.style.filter = 'hue-rotate(180deg)';
  setTimeout(() => {
    document.body.style.filter = '';
  }, 2000);
}

function systemDiagnostics() {
  showNotification(
    'System Diagnostics',
    'Running comprehensive system check...',
    'info'
  );

  // Simulate diagnostic progress
  setTimeout(() => {
    showNotification(
      'Diagnostics Complete',
      'All systems are functioning normally.',
      'success'
    );
  }, 3000);
}

function communicationCenter() {
  showNotification(
    'Communication Center',
    'Opening secure communication channels...',
    'info'
  );

  // Simulate opening communication
  setTimeout(() => {
    showNotification(
      'Communication Active',
      'All channels are now available.',
      'success'
    );
  }, 2000);
}

// Show notification
function showNotification(title, message, type = 'info') {
  const notification = document.createElement('div');
  notification.className = `notification notification-${type}`;
  notification.innerHTML = `
        <div class="notification-header">
            <h4>${title}</h4>
            <button onclick="this.parentElement.parentElement.remove()">×</button>
        </div>
        <p>${message}</p>
    `;

  // Add notification styles
  notification.style.cssText = `
        position: fixed;
        top: 100px;
        right: 20px;
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 10px;
        padding: 1rem;
        color: white;
        z-index: 10000;
        min-width: 300px;
        animation: slideInRight 0.3s ease-out;
    `;

  document.body.appendChild(notification);

  // Auto remove after 5 seconds
  setTimeout(() => {
    if (notification.parentElement) {
      notification.remove();
    }
  }, 5000);
}

// Animation loop
function animate() {
  requestAnimationFrame(animate);

  // Rotate stars
  if (stars) {
    stars.rotation.y += 0.0003;
    stars.rotation.x += 0.00012;
  }

  // Rotate planets
  if (planets) {
    planets.forEach((planet, index) => {
      planet.rotation.y += 0.003 + index * 0.0012;
      planet.rotation.x += 0.0012 + index * 0.0007;
    });
  }

  // Rotate asteroids
  if (asteroids) {
    asteroids.forEach((asteroid) => {
      asteroid.rotation.y += 0.006;
      asteroid.rotation.x += 0.003;
    });
  }

  // Parallax effect based on mouse movement
  if (stars) {
    stars.position.x = mouseX * 0.2;
    stars.position.y = mouseY * 0.2;
  }

  if (planets) {
    planets.forEach((planet, index) => {
      planet.position.x += mouseX * (0.3 + index * 0.12);
      planet.position.y += mouseY * (0.3 + index * 0.12);
    });
  }

  renderer.render(scene, camera);
}

// Add notification animation to CSS
const notificationStyle = document.createElement('style');
notificationStyle.textContent = `
    @keyframes slideInRight {
        from {
            opacity: 0;
            transform: translateX(100%);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    .notification-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
    }

    .notification-header h4 {
        margin: 0;
        color: #4facfe;
    }

    .notification-header button {
        background: none;
        border: none;
        color: white;
        font-size: 1.2rem;
        cursor: pointer;
        padding: 0;
        width: 20px;
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .notification p {
        margin: 0;
        font-size: 0.9rem;
        color: rgba(255, 255, 255, 0.8);
    }

    .notification-info {
        border-left: 4px solid #4facfe;
    }

    .notification-success {
        border-left: 4px solid #00ff88;
    }

    .notification-warning {
        border-left: 4px solid #ffaa00;
    }

    .notification-error {
        border-left: 4px solid #ff4444;
    }
`;
document.head.appendChild(notificationStyle);

// Device orientation support (for mobile)
if (window.DeviceOrientationEvent) {
  window.addEventListener('deviceorientation', function (event) {
    const tiltX = event.beta / 90; // -1 to 1
    const tiltY = event.gamma / 90; // -1 to 1

    mouseX = tiltY * 1.5;
    mouseY = tiltX * 1.5;
  });
}

// Touch support for mobile
document.addEventListener(
  'touchmove',
  function (event) {
    event.preventDefault();
    const touch = event.touches[0];
    mouseX = (touch.clientX - windowHalfX) / 100;
    mouseY = (touch.clientY - windowHalfY) / 100;
  },
  { passive: false }
);

// Performance optimization
let frameCount = 0;
function optimizePerformance() {
  frameCount++;
  if (frameCount % 3 === 0) {
    // Reduce particle count on slower devices
    const particles = document.querySelectorAll('.particle');
    if (particles.length > 15) {
      for (let i = 15; i < particles.length; i++) {
        particles[i].style.display = 'none';
      }
    }
  }
}

// Call performance optimization
setInterval(optimizePerformance, 1000);
